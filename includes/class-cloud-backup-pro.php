<?php

class Cloud_Backup_Pro {

    /**
     * Lance une sauvegarde locale (ZIP) puis l'envoie vers Google Drive via l'API REST.
     * - PHP 8.1 compatible
     * - Écrit dans wp-content/uploads/cloud-backup-pro/{backups,logs}
     */
    public static function run_backup() {
        // Récupère un access_token valide (avec refresh auto)
        if (class_exists('Cloud_Backup_Auth')) {
            $access_token = Cloud_Backup_Auth::get_valid_token();
        } else {
            $access_token = get_option('cloud_backup_gdrive_token', '');
        }
        // Récup paramètres
        $backup_filename = get_option('cloud_backup_filename', 'backup.zip');
        $gdrive_folder   = get_option('cloud_backup_gdrive_path', 'CloudBackups'); // non utilisé dans cette version simple
        $access_token    = get_option('cloud_backup_gdrive_token', '');

        if (empty($access_token)) {
            error_log('⚠️ Token Google Drive manquant (cloud_backup_gdrive_token).');
            return;
        }

        // Prépare chemins
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit($upload_dir['basedir']) . 'cloud-backup-pro/';
        $backup_dir = $base_dir . 'backups/';
        $logs_dir   = $base_dir . 'logs/';

        if (!file_exists($backup_dir)) { wp_mkdir_p($backup_dir); }
        if (!file_exists($logs_dir))   { wp_mkdir_p($logs_dir); }

        $backup_file = $backup_dir . $backup_filename;

        // Crée l'archive ZIP (ex: wp-content)
        $zip = new ZipArchive();
        if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            error_log('⚠️ Impossible de créer le ZIP : ' . $backup_file);
            return;
        }

        $content_path = WP_CONTENT_DIR;
        $content_path_len = strlen($content_path);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($content_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $file_path = $file->getRealPath();
            $local_path = substr($file_path, $content_path_len + 1); // relatif à wp-content

            if ($file->isDir()) {
                $zip->addEmptyDir($local_path);
            } else {
                $zip->addFile($file_path, $local_path);
            }
        }
        $zip->close();

        // === Ensure we have a valid access_token (string) ===
        $token_opt = get_option('cloud_backup_gdrive_token', array());
        if (is_array($token_opt)) {
            $access_token = isset($token_opt['access_token']) ? $token_opt['access_token'] : '';
            $expires_at   = isset($token_opt['expires_at']) ? intval($token_opt['expires_at']) : 0;
        } else {
            $access_token = '';
            $expires_at = 0;
        }
        if (empty($access_token) || ($expires_at && $expires_at < time())) {
            if (class_exists('Cloud_Backup_Auth') && is_array($token_opt) && !empty($token_opt['refresh_token'])) {
                $new = Cloud_Backup_Auth::refresh_token($token_opt);
                if ($new && !empty($new['access_token'])) {
                    $access_token = $new['access_token'];
                }
            }
        }
        if (empty($access_token)) {
            if (function_exists('error_log')) error_log('[CloudBackup] Abort: no valid access_token.');
            if (function_exists('add_settings_error')) add_settings_error('cloud_backup_pro', 'no_token', 'Aucun jeton Google Drive valide. Veuillez reconnecter votre compte.', 'error');
            return;
        }
        // === End token guard ===

        // Upload vers Google Drive (multipart)
        $boundary = wp_generate_uuid4();
        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';
        $metadata = array('name' => basename($backup_file));
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/zip\r\n\r\n";
        $body .= file_get_contents($backup_file) . "\r\n";
        $body .= "--{$boundary}--";

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => "multipart/related; boundary={$boundary}",
            ),
            'body'    => $body,
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            error_log('⚠️ Erreur HTTP upload Drive: ' . $response->get_error_message());
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body_resp = wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            error_log('✅ Sauvegarde envoyée sur Google Drive.');
        } else {
            error_log('⚠️ Échec de l\'upload sur Google Drive (HTTP ' . $code . ')');
        }
    }
}

/**
 * Wrapper fonctionnel (utilisé par le cron / UI)
 */
function cloud_backup_pro_run_backup() {
    Cloud_Backup_Pro::run_backup();
}
