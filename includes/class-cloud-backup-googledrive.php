<?php
class Cloud_Backup_Googledrive {
    private $client_id;
    private $client_secret;
    private $redirect_uri;

    public function __construct() {
        $this->client_id = get_option('cloud_backup_googledrive_client_id');
        $this->client_secret = get_option('cloud_backup_googledrive_client_secret');
        $this->redirect_uri = admin_url('admin.php?page=cloud-backup-pro-auth-google');
    }

    public function get_auth_url() {
        $url = 'https://accounts.google.com/o/oauth2/v2/auth';
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];
        return $url . '?' . http_build_query($params);
    }

    public function handle_auth_callback() {
        if (!isset($_GET['code'])) {
            echo '<div class="error"><p>Erreur : code d’autorisation manquant.</p></div>';
            return;
        }

        $code = sanitize_text_field($_GET['code']);
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code' => $code,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri' => $this->redirect_uri,
                'grant_type' => 'authorization_code'
            ]
        ]);

        if (is_wp_error($response)) {
            echo '<div class="error"><p>Erreur d’échange du token : ' . $response->get_error_message() . '</p></div>';
            return;
        }

        $token_data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($token_data['access_token'])) {
            echo '<div class="error"><p>Erreur : token d’accès manquant ou invalide.</p></div>';
            return;
        }

        update_option('cloud_backup_googledrive_token', $token_data);
        echo '<div class="updated"><p>Connexion à Google Drive réussie ✅</p></div>';
    }

    private function get_access_token() {
        $token_data = get_option('cloud_backup_gdrive_token');
        if (!$token_data) return null;

        // Rafraîchissement si expiré et refresh_token disponible
        if (isset($token_data['expires_in']) && isset($token_data['refresh_token'])) {
            $new_response = wp_remote_post('https://oauth2.googleapis.com/token', [
                'body' => [
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'refresh_token' => $token_data['refresh_token'],
                    'grant_type' => 'refresh_token'
                ]
            ]);

            if (!is_wp_error($new_response)) {
                $new_data = json_decode(wp_remote_retrieve_body($new_response), true);
                if (isset($new_data['access_token'])) {
                    $token_data['access_token'] = $new_data['access_token'];
                    update_option('cloud_backup_googledrive_token', $token_data);
                }
            }
        }

        return $token_data['access_token'] ?? null;
    }

    public function upload_backup($file_path) {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            error_log("⚠️ Token Google Drive manquant ou invalide");
            return false;
        }

        $filename = basename($file_path);
        $file_data = file_get_contents($file_path);
        $boundary = uniqid();
        $delimiter = '------' . $boundary;

        $body = "--$delimiter
"
              . "Content-Type: application/json; charset=UTF-8

"
              . json_encode(["name" => $filename]) . "
"
              . "--$delimiter
"
              . "Content-Type: application/zip

"
              . $file_data . "
"
              . "--$delimiter--";

        $response = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => "multipart/related; boundary=$boundary"
            ],
            'body' => $body,
            'timeout' => 60
        ]);

        return !is_wp_error($response);
    }
}
?>