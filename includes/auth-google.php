<?php
if (!is_admin() || !current_user_can('manage_options')) {
    wp_die(__('Non autorisé', 'cloud-backup-pro'));
}

$client_id = get_option('cloud_backup_googledrive_client_id');
$client_secret = get_option('cloud_backup_googledrive_client_secret');
$redirect_uri = admin_url('admin.php?page=cloud-backup-pro-auth-google');

if (!isset($_GET['code'])) {
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'access_type' => 'offline',
        'prompt' => 'consent'
    ]);
    wp_redirect($auth_url);
    exit;
}

$response = wp_remote_post('https://oauth2.googleapis.com/token', [
    'body' => [
        'code' => $_GET['code'],
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code'
    ]
]);

$body = json_decode(wp_remote_retrieve_body($response), true);

if (isset($body['access_token'])) {
    update_option('cloud_backup_googledrive_token', $body);
    wp_redirect(admin_url('options-general.php?page=cloud-backup-pro&auth=google_success'));
    exit;
} else {
    error_log('❌ Auth Google Drive échouée : ' . print_r($body, true));
    wp_die('Erreur d'authentification Google.');
}
