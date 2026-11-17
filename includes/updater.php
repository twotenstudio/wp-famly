<?php
/**
 * Updates from GitHub Releases (twotenstudio/wp-famly), like the EYWorks plugin.
 * A release needs a tag such as v1.0.1; a bare tag without a Release is ignored.
 */

if (!defined('ABSPATH')) exit;

function famly_ef_latest_release() {
    $cached = get_site_transient('famly_ef_latest_release');
    if ($cached !== false) return $cached ?: null;

    $response = wp_remote_get('https://api.github.com/repos/' . FAMLY_EF_GITHUB_REPO . '/releases/latest', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github+json'],
    ]);
    $release = null;
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['tag_name'])) $release = $body;
    }
    set_site_transient('famly_ef_latest_release', $release ?: '', 6 * HOUR_IN_SECONDS);
    return $release;
}

function famly_ef_release_package($release) {
    foreach ((array) ($release['assets'] ?? []) as $asset) {
        if (substr($asset['name'] ?? '', -4) === '.zip') return $asset['browser_download_url'];
    }
    return $release['zipball_url'] ?? '';
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) return $transient;
    $release = famly_ef_latest_release();
    if (!$release) return $transient;

    $plugin  = plugin_basename(FAMLY_EF_FILE);
    $version = ltrim($release['tag_name'], 'v');
    $item    = (object) [
        'slug'        => 'wp-famly',
        'plugin'      => $plugin,
        'new_version' => $version,
        'url'         => 'https://github.com/' . FAMLY_EF_GITHUB_REPO,
        'package'     => famly_ef_release_package($release),
    ];
    if (version_compare(FAMLY_EF_VERSION, $version, '<')) {
        $transient->response[$plugin] = $item;
    } else {
        $item->new_version = FAMLY_EF_VERSION;
        $transient->no_update[$plugin] = $item;
        unset($transient->response[$plugin]);
    }
    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'wp-famly') return $result;
    $release = famly_ef_latest_release();
    if (!$release) return $result;
    return (object) [
        'name'          => 'Famly Enquiry Form',
        'slug'          => 'wp-famly',
        'version'       => ltrim($release['tag_name'], 'v'),
        'author'        => '<a href="https://twotenstudio.co.uk">Two Ten Studio</a>',
        'homepage'      => 'https://github.com/' . FAMLY_EF_GITHUB_REPO,
        'sections'      => ['changelog' => nl2br(esc_html($release['body'] ?? 'See GitHub for release notes.'))],
        'download_link' => famly_ef_release_package($release),
    ];
}, 10, 3);

// GitHub zipballs unpack as owner-repo-hash/; move it back to this plugin's folder.
add_filter('upgrader_post_install', function ($response, $hook_extra, $result) {
    if (($hook_extra['plugin'] ?? '') !== plugin_basename(FAMLY_EF_FILE)) return $response;
    global $wp_filesystem;
    $proper = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(FAMLY_EF_FILE));
    if (untrailingslashit($result['destination']) !== $proper) {
        $wp_filesystem->move($result['destination'], $proper);
        $result['destination'] = trailingslashit($proper);
    }
    if (is_plugin_active($hook_extra['plugin'])) activate_plugin($hook_extra['plugin']);
    return $result;
}, 10, 3);
