<?php
if (!is_admin() || !current_user_can('manage_options')) {
    wp_die(__('Non autorisé', 'cloud-backup-pro'));
}


if (!isset($_GET['code'])) {
    $auth_url = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query([
        'client_id' => $client_id,
        'scope' => 'offline_access files.readwrite user.read',
        'response_type' => 'code',
        'redirect_uri' => $redirect_uri,
        'response_mode' => 'query'
    ]);
    wp_redirect($auth_url);
    exit;
}

$response = wp_remote_post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
    'body' => [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'code' => $_GET['code'],
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code'
    ]
]);

$body = json_decode(wp_remote_retrieve_body($response), true);

if (isset($body['access_token'])) {
    exit;
} else {
}