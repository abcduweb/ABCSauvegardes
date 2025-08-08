
<?php

class Cloud_Backup_UI {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_admin_menu() {
        add_options_page(
            'Cloud Backup Pro',
            'Sauvegarde Cloud',
            'manage_options',
            'cloud-backup-pro',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('cloud_backup_pro_settings', 'cloud_backup_filename');
        register_setting('cloud_backup_pro_settings', 'cloud_backup_gdrive_path');
        register_setting('cloud_backup_pro_settings', 'cloud_backup_schedule');
        register_setting('cloud_backup_pro_settings', 'cloud_backup_client_id');
        register_setting('cloud_backup_pro_settings', 'cloud_backup_client_secret');
    }

    public function render_settings_page() {
        // Gestion du retour OAuth
        if (class_exists('Cloud_Backup_Auth')) { Cloud_Backup_Auth::handle_oauth_return(); }
        if (!empty($_POST['cloud_backup_manual_trigger'])) {
            if (!isset($_POST['cloud_backup_nonce']) || !wp_verify_nonce($_POST['cloud_backup_nonce'], 'cloud_backup_manual')) {
                add_settings_error('cloud_backup_pro', 'nonce', 'Nonce invalide pour la sauvegarde manuelle.', 'error');
            } else {
                if (function_exists('cloud_backup_pro_run_backup')) {
                    cloud_backup_pro_run_backup();
                    add_settings_error('cloud_backup_pro', 'manual_ok', 'Sauvegarde manuelle lancée.', 'updated');
                }
            }
        }
    
        ?>
        <div class="wrap">

<?php if (get_option('cloud_backup_gdrive_token')): ?>
    <div class="notice notice-success is-dismissible">
        <p><strong>✅ Connexion à Google Drive réussie !</strong> Le plugin est maintenant prêt à sauvegarder vos fichiers.</p>
    </div>
<?php endif; ?>

            <h1>Réglages Cloud Backup Pro</h1>
            <?php
            if (isset($_POST['cloud_backup_manual_trigger']) && current_user_can('manage_options')) {
                if (!function_exists('cloud_backup_pro_run_backup')) {
                    include_once plugin_dir_path(__FILE__) . 'class-cloud-backup-pro.php';
                }
                echo '<div class="notice notice-success"><p>Sauvegarde lancée...</p></div>';
                cloud_backup_pro_run_backup();
            }
            ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('cloud_backup_pro_settings');
                do_settings_sections('cloud_backup_pro_settings');
                ?>
                <h2>Paramètres de Google Drive</h2>
<?php
if (class_exists('Cloud_Backup_Auth')) {
    echo '<div class="notice notice-info"><p><strong>Redirect URI (à copier dans Google Cloud) :</strong><br><code>https://www.test.wordpress.abcduweb.fr/wp-admin/admin-post.php?action=cloud_backup_oauth</code></p></div>';
}
?>
<?php $___url = Cloud_Backup_Auth::build_auth_url(); ?><p><strong>URL d’autorisation générée :</strong><br><code><?php echo esc_html($___url); ?></code></p>
<?php
$__client_id = get_option('cloud_backup_client_id', '');
$__auth_url  = class_exists('Cloud_Backup_Auth') ? Cloud_Backup_Auth::build_auth_url() : '';
if (empty($__client_id)) {
    echo '<div class="notice notice-error"><p><strong>Client ID manquant :</strong> enregistrez d\'abord votre Client ID Google, puis réessayez.</p></div>';
}
echo '<p><em>URL d\'autorisation générée :</em><br><code style="user-select:all;">' . esc_html($__auth_url) . '</code></p>';
?>
<?php
$token = get_option('cloud_backup_gdrive_token', array());
if (!empty($token['access_token'])) {
    echo '<p><strong>Statut Google Drive :</strong> Connecté ✅</p>';
} else {
    echo '<p><strong>Statut Google Drive :</strong> Non connecté ❌</p>';
}
?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Nom du fichier de sauvegarde</th>
                        <td>
                            <input type="text" name="cloud_backup_filename"
                                   value="<?php echo esc_attr(get_option('cloud_backup_filename', 'backup.zip')); ?>"
                                   class="regular-text"/>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Chemin sur Google Drive</th>
                        <td>
                            <input type="text" name="cloud_backup_gdrive_path"
                                   value="<?php echo esc_attr(get_option('cloud_backup_gdrive_path', 'CloudBackups')); ?>"
                                   class="regular-text"/>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Fréquence de sauvegarde</th>
                        <td>
                            <select name="cloud_backup_schedule">
                                <option value="none" <?php selected(get_option('cloud_backup_schedule'), 'none'); ?>>Manuelle</option>
                                <option value="daily" <?php selected(get_option('cloud_backup_schedule'), 'daily'); ?>>Quotidienne</option>
                                <option value="weekly" <?php selected(get_option('cloud_backup_schedule'), 'weekly'); ?>>Hebdomadaire</option>
                                <option value="monthly" <?php selected(get_option('cloud_backup_schedule'), 'monthly'); ?>>Mensuelle</option>
                            </select>
                        </td>
                    </tr>
                
<h2>Identifiants API Google Drive</h2>
<table class="form-table">
    <tr valign="top">
        <th scope="row">Client ID</th>
        <td>
            <input type="text" name="cloud_backup_client_id"
                   value="<?php echo esc_attr(get_option('cloud_backup_client_id', '')); ?>"
                   class="regular-text"/>
        </td>
    </tr>
    <tr valign="top">
        <th scope="row">Client Secret</th>
        <td>
            <input type="password" name="cloud_backup_client_secret"
                   value="<?php echo esc_attr(get_option('cloud_backup_client_secret', '')); ?>"
                   class="regular-text"/>
        </td>
    </tr>
</table>
</table>
                <p class="submit">
                    <input type="submit" class="button-primary" value="Sauvegarder les réglages"/>
                </p>
            </form>

            <h2>Sauvegarde manuelle</h2>
            <form method="post" action="">
                <?php wp_nonce_field('cloud_backup_manual', 'cloud_backup_nonce'); ?>
                <input type="hidden" name="cloud_backup_manual_trigger" value="1" />
                <p><input type="submit" class="button button-secondary" value="Lancer une sauvegarde maintenant" /></p>
            
<h2>Connexion Google Drive</h2>
<p>
    <a href="<?php echo admin_url('admin.php?page=cloud-backup-pro&connect_gdrive=1'); ?>" class="button button-primary" target="_blank" rel="noopener" target="_blank" rel="noopener" onclick="if(!this.href||this.href==''){alert('Client ID manquant ou URL OAuth vide. Enregistrez vos identifiants puis réessayez.'); return false;}">Se connecter à Google Drive</a>
</p>

<h2>Historique des sauvegardes</h2>
<pre style="background:#f1f1f1; padding:10px; border:1px solid #ccc; max-height:200px; overflow:auto;">
<?php
$upload_dir = wp_upload_dir();
$log_path = trailingslashit($upload_dir['basedir']) . 'cloud-backup-pro/logs/backup-log.txt';
if (file_exists($log_path)) {
    echo esc_html(file_get_contents($log_path));
} else {
    echo 'Aucune sauvegarde enregistrée.';
}
?>
</pre>
</form>
        </div>
        <?php
    }
}
