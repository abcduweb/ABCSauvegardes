<?php
class Cloud_Backup_Restore {
    public function render_restore_interface() {
        echo '<div class="wrap"><h1>Restauration des sauvegardes</h1>';

        if (isset($_GET['restore']) && isset($_GET['source']) && isset($_GET['file'])) {
            $this->handle_restore($_GET['source'], $_GET['file']);
        } else {
            echo '<h2>Sauvegardes disponibles</h2>';
            $this->list_files_google();
        }

        echo '</div>';
    }

    private function list_files_google() {
        $token_data = get_option('cloud_backup_googledrive_token');
        if (!$token_data || empty($token_data['access_token'])) {
            echo '<p>❌ Google Drive non connecté</p>';
            return;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token_data['access_token']
        ];

        $url = 'https://www.googleapis.com/drive/v3/files?q=name+contains+%27backup%27&fields=files(id,name,modifiedTime,size)&spaces=drive';
        $response = wp_remote_get($url, ['headers' => $headers]);

        echo '<h3>Google Drive</h3>';
        if (is_wp_error($response)) {
            echo '<p>Erreur lors de l’accès à Google Drive.</p>';
            return;
        }

        $files = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($files['files'])) {
            echo '<ul>';
            foreach ($files['files'] as $file) {
                $link = admin_url('admin.php?page=cloud-backup-pro-restore&restore=1&source=google&file=' . $file['id']);
                echo "<li>{$file['name']} ({$file['size']} o) <a href='$link' class='button'>Restaurer</a></li>";
            }
            echo '</ul>';
        } else {
            echo '<p>Aucune sauvegarde trouvée sur Google Drive.</p>';
        }
    }

        if (!$token_data || empty($token_data['access_token'])) {
            return;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token_data['access_token']
        ];

        $url = 'https://graph.microsoft.com/v1.0/me/drive/root:/cloud-backups:/children';
        $response = wp_remote_get($url, ['headers' => $headers]);

        if (is_wp_error($response)) {
            return;
        }

        $files = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($files['value'])) {
            echo '<ul>';
            foreach ($files['value'] as $file) {
                if (strpos($file['name'], 'backup') !== false) {
                    echo "<li>{$file['name']} ({$file['size']} o) <a href='$link' class='button'>Restaurer</a></li>";
                }
            }
            echo '</ul>';
        } else {
        }
    }

    <?php
private function handle_restore($source, $file_id) {
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé');
    }

    echo '<h2>Restauration</h2>';

    if (!isset($_GET['confirm'])) {
        $confirm_url = add_query_arg(['confirm' => 'yes'], $_SERVER['REQUEST_URI']);
        echo '<p>⚠️ Cette opération va écraser les fichiers existants et potentiellement restaurer la base de données.</p>';
        echo '<a href="' . esc_url($confirm_url) . '" class="button button-primary">Confirmer la restauration</a>';
        return;
    }

    $token_data = get_option($token_option);
    $access_token = $token_data['access_token'] ?? '';

    $headers = ['Authorization' => 'Bearer ' . $access_token];
    $url = $source === 'google'
        ? 'https://www.googleapis.com/drive/v3/files/' . $file_id . '?alt=media'
        : 'https://graph.microsoft.com/v1.0/me/drive/items/' . $file_id . '/content';

    $response = wp_remote_get($url, ['headers' => $headers, 'timeout' => 90]);

    if (is_wp_error($response)) {
        echo '<p>Erreur lors du téléchargement de la sauvegarde.</p>';
        return;
    }

    $upload_dir = wp_upload_dir();
    $backup_path = trailingslashit($upload_dir['basedir']) . 'cloud-backups/';
    wp_mkdir_p($backup_path);
    $zip_file = $backup_path . 'restored-' . time() . '.zip';

    file_put_contents($zip_file, wp_remote_retrieve_body($response));

    $zip = new ZipArchive;
    if ($zip->open($zip_file) === TRUE) {
        $zip->extractTo(WP_CONTENT_DIR);
        $sql_found = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (str_ends_with($filename, '.sql')) {
                $sql_path = $backup_path . basename($filename);
                copy("zip://{$zip_file}#{$filename}", $sql_path);
                $this->import_sql($sql_path);
                $sql_found = true;
            }
        }

        $zip->close();
        echo '<p>✅ Fichiers extraits avec succès.</p>';
        if ($sql_found) {
            echo '<p>✅ Base de données restaurée.</p>';
        } else {
            echo '<p>ℹ️ Aucun fichier SQL trouvé, restauration de la base non effectuée.</p>';
        }
    } else {
        echo '<p>Erreur : impossible d’ouvrir l’archive ZIP.</p>';
    }
}

private function import_sql($sql_file) {
    global $wpdb;
    $command = sprintf(
        'mysql -u%s -p%s -h%s %s < %s',
        DB_USER,
        DB_PASSWORD,
        DB_HOST,
        DB_NAME,
        escapeshellarg($sql_file)
    );

    if (stripos(PHP_OS, 'WIN') !== false) {
        error_log("⚠️ Import SQL non supporté automatiquement sur Windows.");
        return false;
    }

    exec($command, $output, $return_var);
    return $return_var === 0;
}
?>