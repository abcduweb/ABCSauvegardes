<?php

class Cloud_Backup_Pro {

    /**
     * Lance une sauvegarde locale (ZIP) puis l'envoie vers Google Drive via l'API REST.
     * - PHP 8.1 compatible
     * - Écrit dans wp-content/uploads/cloud-backup-pro/{backups,logs}
     */
    public static function run_backup() {
        // Prépare chemins et nom de fichier
        $backup_filename = get_option('cloud_backup_filename', 'backup.zip');
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit($upload_dir['basedir']) . 'cloud-backup-pro/';
        $backup_dir = $base_dir . 'backups/';
        $logs_dir   = $base_dir . 'logs/';
        if (!file_exists($backup_dir)) { wp_mkdir_p($backup_dir); }
        if (!file_exists($logs_dir))   { wp_mkdir_p($logs_dir); }
        $backup_file = $backup_dir . $backup_filename;

        // Crée l'archive ZIP (ex: wp-content entier)
        $zip = new \ZipArchive();
        if ($zip->open($backup_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
            error_log('⚠️ Impossible de créer le ZIP : ' . $backup_file);
            return;
        }
        $content_path = WP_CONTENT_DIR;
        $content_path_len = strlen($content_path);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($content_path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
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

        // === Token guard & refresh (canonical) ===
        $token_opt = get_option(Cloud_Backup_Auth::OPTION_TOKEN, array());
        $access_token = '';
        $expires_at = 0;
        if (is_array($token_opt)) {
            $access_token = isset($token_opt['access_token']) ? (string)$token_opt['access_token'] : '';
            $expires_at   = isset($token_opt['expires_at']) ? intval($token_opt['expires_at']) : 0;
        }
        if (empty($access_token) || ($expires_at && $expires_at < time())) {
            if (class_exists('Cloud_Backup_Auth') && !empty($token_opt['refresh_token'])) {
                $new = Cloud_Backup_Auth::refresh_token($token_opt);
                if ($new && !empty($new['access_token'])) { $access_token = $new['access_token']; }
            }
        }
        if (empty($access_token)) {
            if (function_exists('error_log')) error_log('[CloudBackup] Abort upload: no valid access_token.');
            if (function_exists('add_settings_error')) add_settings_error('cloud_backup_pro', 'no_token', 'Aucun jeton Google Drive valide. Veuillez reconnecter votre compte.', 'error');
            return;
        }
        // === End guard ===

        // Upload vers Google Drive (résumable, streaming par chunks)
        if (!self::upload_resumable_to_drive($backup_file, $access_token, basename($backup_file))) {
            error_log('⚠️ Échec upload résumable Google Drive.');
            return;
        } else {
            error_log('✅ Sauvegarde envoyée sur Google Drive.');
        }
    }

    /**
     * Upload Google Drive via Resumable Upload (chunked) with progress + retries.
     */
    private static function upload_resumable_to_drive($backup_file, $access_token, $filename = null) {
        if (!file_exists($backup_file)) { error_log('[CloudBackup] Fichier introuvable: ' . $backup_file); return false; }
        if ($filename === null) { $filename = basename($backup_file); }

        $chunk_mb = intval(get_option('cloud_backup_chunk_mb', 5));
        if ($chunk_mb < 1) { $chunk_mb = 1; }
        if ($chunk_mb > 64) { $chunk_mb = 64; }
        $chunk_size = $chunk_mb * 1024 * 1024;

        // Étape 1: initier la session résumable
        $init = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json; charset=UTF-8',
            ),
            'body' => wp_json_encode(array('name' => $filename)),
            'timeout' => 30,
        ));
        if (is_wp_error($init)) { error_log('[CloudBackup] Init résumable: ' . $init->get_error_message()); return false; }
        $code = wp_remote_retrieve_response_code($init);
        $location = wp_remote_retrieve_header($init, 'location');
        if (($code < 200 || $code >= 300) || empty($location)) { error_log('[CloudBackup] Init résumable HTTP ' . $code . ' (no Location)'); return false; }

        // Étape 2: envoi par morceaux
        $fp = fopen($backup_file, 'rb'); if (!$fp) { error_log('[CloudBackup] fopen échoué'); return false; }
        $total = filesize($backup_file);
        $offset = 0; $chunk_index = 0; $ok = false;
        $total_chunks = max(1, ceil($total / $chunk_size));

        while (!feof($fp)) {
            $data = fread($fp, $chunk_size);
            if ($data === false) { fclose($fp); error_log('[CloudBackup] fread échoué'); return false; }
            $len = strlen($data); $end = $offset + $len - 1;

            $attempts = 0;
            while (true) {
                $attempts++;
                $put = wp_remote_request($location, array(
                    'method'  => 'PUT',
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Length'=> $len,
                        'Content-Range' => 'bytes ' . $offset . '-' . $end . '/' . $total,
                        'Content-Type'  => 'application/zip',
                    ),
                    'body'    => $data,
                    'timeout' => 180,
                ));
                if (is_wp_error($put)) {
                    error_log('[CloudBackup] Chunk PUT error (try ' . $attempts . '): ' . $put->get_error_message());
                    if ($attempts < 3) { sleep(2 * $attempts); continue; }
                    fclose($fp); return false;
                }
                $status = wp_remote_retrieve_response_code($put);
                if ($status == 308) { break; }
                elseif ($status >= 200 && $status < 300) { $ok = true; break 2; }
                elseif ($status == 429 || ($status >= 500 && $status < 600)) {
                    error_log('[CloudBackup] Chunk PUT HTTP ' . $status . ' (try ' . $attempts . '), retry…');
                    if ($attempts < 3) { sleep(2 * $attempts); continue; }
                    fclose($fp); return false;
                } else {
                    error_log('[CloudBackup] Chunk PUT HTTP ' . $status . ' body=' . wp_remote_retrieve_body($put));
                    fclose($fp); return false;
                }
            }
            $offset = $end + 1; $chunk_index++;
            $percent = $total > 0 ? round(($offset / $total) * 100, 1) : 100;
            error_log('[CloudBackup] Upload ' . $percent . '% (' . ($chunk_index) . '/' . $total_chunks . ' chunks)');
        }
        fclose($fp);
        return $ok;
    }

}

function cloud_backup_pro_run_backup() {
    Cloud_Backup_Pro::run_backup();
}
