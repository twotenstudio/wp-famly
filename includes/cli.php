<?php
/**
 * WP-CLI: wp famly-enquiry <sites|dry-run|retry|status>
 */

if (!defined('ABSPATH')) exit;

class Famly_EF_CLI {

    /**
     * List the Famly sites the configured token(s) can see. Read-only.
     */
    public function sites() {
        $token = famly_ef_global_token();
        if (!$token) WP_CLI::error('No shared FAMLY_API_TOKEN / API token is set.');
        $r = famly_ef_list_sites($token);
        if (!$r['ok']) WP_CLI::error($r['error']);
        $items = [];
        foreach ($r['sites'] as $id => $title) $items[] = ['siteId' => $id, 'title' => $title];
        WP_CLI\Utils\format_items('table', $items, ['siteId', 'title']);
    }

    /**
     * Validate the InquiryInput this plugin builds, using made-up test data and
     * NO token, so Famly checks the schema and nothing can be created.
     *
     * [--site=<key>]
     * : Nursery key (default: first nursery).
     *
     * @subcommand dry-run
     */
    public function dry_run($args, $assoc) {
        $site = famly_ef_resolve_site($assoc['site'] ?? '');
        if (!$site) WP_CLI::error('Unknown nursery.');
        $row = [
            'id' => 0, 'site_key' => $site['key'], 'site_label' => $site['label'],
            'famly_site_id' => $site['site_id'] ?: '00000000-0000-4000-8000-000000000000',
            'child_first_name' => 'Dryrun', 'child_last_name' => 'Child', 'child_dob' => '2025-01-01', 'child_gender' => 'Other',
            'parent_first_name' => 'Dryrun', 'parent_last_name' => 'Parent', 'parent_email' => 'dryrun@example.com',
            'phone' => '07700 900000', 'postcode' => 'SE8 0AA', 'start_date' => wp_date('Y-m-d', strtotime('+3 months')),
            'source_label' => array_key_first(famly_ef_sources()) ?: '', 'consent' => 1,
            'extra_answers' => '', 'utm_source' => 'cli', 'utm_medium' => 'dry-run', 'utm_campaign' => '', 'utm_content' => '', 'utm_term' => '',
        ];
        $input = famly_ef_build_inquiry_input($row);
        WP_CLI::log(wp_json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $r = famly_ef_dry_run($input);
        if ($r['valid'] === true) WP_CLI::success($r['detail']);
        else WP_CLI::error($r['detail']);
    }

    /**
     * Send a stored enquiry (failed, held or pending) to Famly.
     *
     * <id>
     * : Enquiry ID.
     */
    public function retry($args) {
        $r = famly_ef_send_to_famly((int) $args[0]);
        if ($r['ok']) WP_CLI::success('Created Famly inquiry ' . $r['inquiry_id']);
        else WP_CLI::error($r['error']);
    }

    /**
     * Show configuration and enquiry counts (no personal data).
     */
    public function status() {
        WP_CLI::log('Endpoint: ' . famly_ef_api_url());
        WP_CLI::log('Shared token: ' . (famly_ef_global_token() ? 'set' : 'not set'));
        WP_CLI::log('Turnstile: ' . (famly_ef_turnstile_keys() ? 'on' : 'off'));
        $items = [];
        foreach (famly_ef_sites() as $s) {
            $items[] = ['key' => $s['key'], 'label' => $s['label'], 'mode' => $s['mode'], 'site_id' => $s['site_id'] ?: '-',
                        'form_url' => $s['form_url'] ? 'set' : '-', 'token' => famly_ef_token_for_site($s) ? 'yes' : 'no',
                        'extra_questions' => count(famly_ef_extra_questions($s))];
        }
        WP_CLI\Utils\format_items('table', $items, ['key', 'label', 'mode', 'site_id', 'form_url', 'token', 'extra_questions']);
        global $wpdb;
        if (famly_ef_table_exists()) {
            $counts = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . famly_ef_table() . ' GROUP BY status', ARRAY_A);
            WP_CLI\Utils\format_items('table', $counts ?: [['status' => '(none)', 'n' => 0]], ['status', 'n']);
        }
    }
}

WP_CLI::add_command('famly-enquiry', 'Famly_EF_CLI');
