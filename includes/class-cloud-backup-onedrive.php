<?php
class Cloud_Backup_Onedrive {
    public function upload_backup($file_path) {
        if (!$token_data || empty($token_data['access_token'])) {
            return false;
        }

        $access_token = $token_data['access_token'];
        $filename = basename($file_path);
        $url = "https://graph.microsoft.com/v1.0/me/drive/root:/cloud-backups/$filename:/content";

        $file_data = file_get_contents($file_path);

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => "Bearer $access_token",
                'Content-Type' => 'application/zip'
            ],
            'body' => $file_data,
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $logs = get_option('cloud_backup_log', []);
        update_option('cloud_backup_log', $logs);
        return true;
    }
}