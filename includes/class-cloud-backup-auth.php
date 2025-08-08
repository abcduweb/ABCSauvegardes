<?php

class Cloud_Backup_Auth {

    public static function handle_revoke() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.'));
        }
        check_admin_referer('cloud_backup_revoke');
        self::revoke();
        $url = add_query_arg(array('page' => 'cloud-backup-pro', 'cloud_revoked' => '1'), admin_url('options-general.php'));
        wp_safe_redirect($url);
        exit;
    }


    /**
     * Révoque la connexion Google Drive :
     * - tente une révocation côté Google (refresh_token prioritaire, sinon access_token)
     * - supprime l'option locale
     * - affiche un message de confirmation
     */
    public static function revoke() {
        $opt_name = self::OPTION_TOKEN;
        $token = get_option($opt_name, array());
        $token_to_revoke = '';
        if (is_array($token)) {
            if (!empty($token['refresh_token'])) { $token_to_revoke = $token['refresh_token']; }
            elseif (!empty($token['access_token'])) { $token_to_revoke = $token['access_token']; }
        }
        if (!empty($token_to_revoke)) {
            $resp = wp_remote_post('https://oauth2.googleapis.com/revoke', array(
                'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
                'body'    => array('token' => $token_to_revoke),
                'timeout' => 15,
            ));
            // Pas bloquant si échec de l'API revoke
        }
        delete_option($opt_name);
        if (function_exists('add_settings_error')) {
            add_settings_error('cloud_backup_pro', 'oauth_revoked', 'Connexion Google Drive révoquée.', 'updated');
        }
        return true;
    }

    private static function log($msg){ if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[CloudBackupAuth] ' . $msg); } }

    const OPTION_TOKEN = 'cloud_backup_gdrive_token'; // stores array: access_token, refresh_token, expires_at
    const OPTION_CLIENT_ID = 'cloud_backup_client_id';
    const OPTION_CLIENT_SECRET = 'cloud_backup_client_secret';
    const OPTION_REDIRECT_URI = 'cloud_backup_redirect_uri'; // computed, but stored for debug if needed

    public static function get_redirect_uri() {
        return set_url_scheme(admin_url('options-general.php?page=cloud-backup-pro&cloud_backup_oauth=1'), 'https');
    }

    public static function build_auth_url() {
        $client_id = get_option(self::OPTION_CLIENT_ID, '');
        if (empty($client_id)) return '';

        $redirect_uri = self::get_redirect_uri();
        $scope = 'https://www.googleapis.com/auth/drive.file';
        $params = array(
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => $scope,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => wp_create_nonce('cloud_backup_oauth_state')
        );

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&');
    }

    public static function handle_oauth_return() {
        if (empty($_GET['cloud_backup_oauth'])) return;

        if (!empty($_GET['error'])) {
            add_settings_error('cloud_backup_pro', 'oauth_error', 'Erreur OAuth: ' . esc_html($_GET['error']), 'error');
            return;
        }

        if (empty($_GET['code'])) {
            add_settings_error('cloud_backup_pro', 'oauth_no_code', 'Code OAuth absent.', 'error');
            return;
        }

        // Optional state check (Google returns it if provided)
        if (isset($_GET['state']) && !wp_verify_nonce(sanitize_text_field($_GET['state']), 'cloud_backup_oauth_state')) {
            add_settings_error('cloud_backup_pro', 'oauth_bad_state', 'Échec de la vérification du state/nonce.', 'error');
            return;
        }

        $code = sanitize_text_field($_GET['code']);
        $ok = self::exchange_code_for_token($code);
        if ($ok) {
            add_settings_error('cloud_backup_pro', 'oauth_ok', 'Connexion à Google Drive réussie ✔', 'updated');
        } else {
            add_settings_error('cloud_backup_pro', 'oauth_fail', 'Échec de l’échange du code OAuth.', 'error');
        }
    }

    public static function exchange_code_for_token($code) {
        $client_id = get_option(self::OPTION_CLIENT_ID, '');
        $client_secret = get_option(self::OPTION_CLIENT_SECRET, '');
        $redirect_uri = self::get_redirect_uri();

        if (empty($client_id) || empty($client_secret)) return false;

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array(
                'code' => $code,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            error_log('OAuth token exchange error: ' . $response->get_error_message());
            return false;
        }

        $code_http = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code_http >= 200 && $code_http < 300 && is_array($body) && !empty($body['access_token'])) {
            $expires_in = isset($body['expires_in']) ? intval($body['expires_in']) : 3600;
            $token = array(
                'access_token' => $body['access_token'],
                'refresh_token' => isset($body['refresh_token']) ? $body['refresh_token'] : '',
                'expires_at' => time() + max(60, $expires_in - 60),
            );
            update_option(self::OPTION_TOKEN, $token, false);
            // Redirect to clean URL to avoid code reuse on refresh
            $clean = add_query_arg('oauth', 'ok', admin_url('options-general.php?page=cloud-backup-pro'));
            wp_safe_redirect($clean);
            exit;
            return true;
        }

        error_log('OAuth token exchange failed: HTTP ' . $code_http . ' body=' . print_r($body, true));
        return false;
    }

    public static function get_valid_token() {
        $token = get_option(self::OPTION_TOKEN, array());
        if (empty($token) || empty($token['access_token'])) {
            return '';
        }
        // Refresh if expiring in <= 60s
        if (empty($token['expires_at']) || time() >= intval($token['expires_at'])) {
            $token = self::refresh_token($token);
            if (!$token) return '';
        }
        return $token['access_token'];
    }

    public static function refresh_token($token) {
        if (empty($token['refresh_token'])) {
            // no refresh token -> must re-auth
            return false;
        }

        $client_id = get_option(self::OPTION_CLIENT_ID, '');
        $client_secret = get_option(self::OPTION_CLIENT_SECRET, '');
        if (empty($client_id) || empty($client_secret)) return false;

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array(
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $token['refresh_token'],
                'grant_type' => 'refresh_token',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            error_log('OAuth refresh error: ' . $response->get_error_message());
            return false;
        }

        $code_http = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code_http >= 200 && $code_http < 300 && !empty($body['access_token'])) {
            $expires_in = isset($body['expires_in']) ? intval($body['expires_in']) : 3600;
            $token['access_token'] = $body['access_token'];
            $token['expires_at'] = time() + max(60, $expires_in - 60);
            update_option(self::OPTION_TOKEN, $token, false);
            return $token;
        }

        error_log('OAuth refresh failed: HTTP ' . $code_http . ' body=' . print_r($body, true));
        return false;
    }

// Helper: log redirect URI for debugging
private static function cloud_backup_log_redirect_uri($uri) {
    if (function_exists('error_log')) {
        error_log('[CloudBackup] redirect_uri=' . $uri);
    }
}
}
