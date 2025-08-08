<?php
/**
 * Plugin Name: ABC-maintenance
 * Version: 1.0
 * Author: Adrien Dubois (SAS ABCduWeb)
 * Description: Plugin permettant la sauvegarde de son site internet sur Google Drive.
 */

defined('ABSPATH') || exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-cloud-backup-ui.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cloud-backup-auth.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cloud-backup-pro.php'; // REST-only core
require_once plugin_dir_path(__FILE__) . 'includes/class-cloud-backup-googledrive.php'; // UI/options helper (REST)

function cloud_backup_pro_init() {
    new Cloud_Backup_UI();
}
add_action('plugins_loaded', 'cloud_backup_pro_init');

// Planifier la tâche si elle ne l'est pas déjà
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('cloud_backup_cron_hook')) {
        // Fréquence par défaut: daily
        wp_schedule_event(time(), 'daily', 'cloud_backup_cron_hook');
    }
});

// Nettoyage à la désactivation
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('cloud_backup_cron_hook');
});

// Exécuter la sauvegarde quand le cron se déclenche
add_action('cloud_backup_cron_hook', 'cloud_backup_pro_run_backup');
add_action('admin_post_cloud_backup_revoke', array('Cloud_Backup_Auth', 'handle_revoke'));

// Toujours intercepter le retour OAuth2 en admin
function cloud_backup_auth_admin_init() {
    if (class_exists('Cloud_Backup_Auth')) {
        Cloud_Backup_Auth::handle_oauth_return();
    }
}
add_action('admin_init', 'cloud_backup_auth_admin_init');


function cloud_backup_connect_redirect() {
    if (!is_admin()) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'cloud-backup-pro') return;
    if (!isset($_GET['connect_gdrive'])) return;
    if (!class_exists('Cloud_Backup_Auth')) return;

    $url = Cloud_Backup_Auth::build_auth_url();
    if (!empty($url)) {
        wp_redirect($url);
        exit;
    } else {
        add_settings_error('cloud_backup_pro', 'oauth_missing', 'Client ID manquant : enregistrez vos identifiants puis réessayez.', 'error');
    }
}
add_action('admin_init', 'cloud_backup_connect_redirect');


/**
 * OAuth2 callback endpoint (works for logged-in and non-logged-in users)
 */
function cloud_backup_oauth_admin_post() {
    if (class_exists('Cloud_Backup_Auth')) {
        Cloud_Backup_Auth::handle_oauth_return();
        return;
    }
    wp_safe_redirect( add_query_arg(array('page' => 'cloud-backup-pro', 'oauth' => 'missing'), admin_url('options-general.php')) );
    exit;
}
add_action('admin_post_cloud_backup_oauth', 'cloud_backup_oauth_admin_post');
add_action('admin_post_nopriv_cloud_backup_oauth', 'cloud_backup_oauth_admin_post');
