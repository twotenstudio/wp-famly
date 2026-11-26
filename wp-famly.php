<?php
/**
 * Plugin Name: Famly Enquiry Form
 * Plugin URI: https://github.com/twotenstudio/wp-famly
 * Description: Nursery tour enquiry form for Famly (Famly-form link or on-page form via the Famly Public API), plus email alerts for new Famly enquiries, local storage, email fallback, spam protection, GTM tracking and an admin dashboard.
 * Version: 1.4.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Two Ten Studio
 * Author URI: https://twotenstudio.co.uk
 * License: GPL-2.0+
 * Text Domain: wp-famly
 */

if (!defined('ABSPATH')) exit;

define('FAMLY_EF_VERSION', '1.4.0');
define('FAMLY_EF_DB_VERSION', '2');
define('FAMLY_EF_FILE', __FILE__);
define('FAMLY_EF_DIR', plugin_dir_path(__FILE__));
define('FAMLY_EF_URL', plugin_dir_url(__FILE__));
define('FAMLY_EF_GITHUB_REPO', 'twotenstudio/wp-famly');

require_once FAMLY_EF_DIR . 'includes/settings.php';
require_once FAMLY_EF_DIR . 'includes/famly-api.php';
require_once FAMLY_EF_DIR . 'includes/storage.php';
require_once FAMLY_EF_DIR . 'includes/form.php';
require_once FAMLY_EF_DIR . 'includes/submission.php';
require_once FAMLY_EF_DIR . 'includes/poller.php';
require_once FAMLY_EF_DIR . 'includes/admin-settings.php';
require_once FAMLY_EF_DIR . 'includes/admin-dashboard.php';
require_once FAMLY_EF_DIR . 'includes/updater.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once FAMLY_EF_DIR . 'includes/cli.php';
}

register_activation_hook(__FILE__, 'famly_ef_activate');

/**
 * Create the enquiries table and seed settings (from the EYWorks plugin's
 * settings where they exist, so the form keeps its look and behaviour).
 */
function famly_ef_activate() {
    famly_ef_create_table();
    update_option('famly_ef_db_version', FAMLY_EF_DB_VERSION);

    if (get_option('famly_ef_settings', null) === null) {
        add_option('famly_ef_settings', famly_ef_initial_settings(), '', false);
    }
}

// Keep the table schema current without needing a re-activation (on every
// request type: the server cron runs through WP-CLI, which skips admin_init).
add_action('init', function () {
    if (get_option('famly_ef_db_version') !== FAMLY_EF_DB_VERSION) {
        famly_ef_create_table();
        update_option('famly_ef_db_version', FAMLY_EF_DB_VERSION);
    }
});

// Settings link on the Plugins screen.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=famly-enquiry-settings')) . '">Settings</a>');
    return $links;
});
