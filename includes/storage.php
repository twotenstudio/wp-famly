<?php
/**
 * Local enquiry storage and sending a stored enquiry to Famly.
 *
 * Every enquiry is saved here before Famly is called, so nothing is lost
 * if Famly is down, the token has expired or a site ID is missing. As soon as
 * it has been sent to Famly or emailed, its personal details are removed
 * (famly_ef_anonymise_enquiry); a 5-minute clean-up catches anything left.
 * Only the date, nursery, outcome and a hash of the Famly ID stay.
 *
 * Statuses: pending (being sent), sent, failed (alert emailed, can be retried),
 * held (spam check failed, not sent), manual (staff added it to Famly by hand).
 */

if (!defined('ABSPATH')) exit;

function famly_ef_table() {
    global $wpdb;
    return $wpdb->prefix . 'famly_enquiries';
}

function famly_ef_create_table() {
    global $wpdb;
    $table   = famly_ef_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime NOT NULL,
        updated_at datetime DEFAULT NULL,
        site_key varchar(60) NOT NULL DEFAULT '',
        site_label varchar(200) NOT NULL DEFAULT '',
        famly_site_id varchar(36) NOT NULL DEFAULT '',
        child_first_name varchar(60) NOT NULL DEFAULT '',
        child_last_name varchar(60) NOT NULL DEFAULT '',
        child_dob date DEFAULT NULL,
        child_gender varchar(20) NOT NULL DEFAULT '',
        parent_first_name varchar(60) NOT NULL DEFAULT '',
        parent_last_name varchar(60) NOT NULL DEFAULT '',
        parent_email varchar(100) NOT NULL DEFAULT '',
        phone varchar(45) NOT NULL DEFAULT '',
        postcode varchar(20) NOT NULL DEFAULT '',
        start_date date DEFAULT NULL,
        source_label varchar(150) NOT NULL DEFAULT '',
        consent tinyint(1) NOT NULL DEFAULT 0,
        extra_answers text,
        utm_source varchar(200) NOT NULL DEFAULT '',
        utm_medium varchar(200) NOT NULL DEFAULT '',
        utm_campaign varchar(200) NOT NULL DEFAULT '',
        utm_content varchar(200) NOT NULL DEFAULT '',
        utm_term varchar(200) NOT NULL DEFAULT '',
        page_url varchar(500) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'pending',
        status_reason varchar(255) NOT NULL DEFAULT '',
        famly_inquiry_id varchar(64) NOT NULL DEFAULT '',
        famly_error text,
        attempts smallint(5) unsigned NOT NULL DEFAULT 0,
        last_attempt_at datetime DEFAULT NULL,
        anonymised_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY created_at (created_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function famly_ef_table_exists() {
    global $wpdb;
    $table = famly_ef_table();
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

/** @return int|false New row ID. */
function famly_ef_insert_enquiry(array $data) {
    global $wpdb;
    if (!famly_ef_table_exists()) {
        famly_ef_log('Table missing, creating it now');
        famly_ef_create_table();
    }
    $now = current_time('mysql');
    $ok  = $wpdb->insert(famly_ef_table(), array_merge($data, ['created_at' => $now, 'updated_at' => $now]));
    if ($ok === false) {
        famly_ef_log('DB insert failed: ' . $wpdb->last_error);
        return false;
    }
    return (int) $wpdb->insert_id;
}

function famly_ef_update_enquiry($id, array $data) {
    global $wpdb;
    $data['updated_at'] = current_time('mysql');
    return $wpdb->update(famly_ef_table(), $data, ['id' => (int) $id]);
}

function famly_ef_get_enquiry($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . famly_ef_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
}

// Columns holding the family's details or Famly's response text.
const FAMLY_EF_PERSONAL_TEXT = ['child_first_name', 'child_last_name', 'child_gender', 'parent_first_name', 'parent_last_name',
    'parent_email', 'phone', 'postcode', 'source_label', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'page_url'];
const FAMLY_EF_PERSONAL_NULL = ['child_dob', 'start_date', 'extra_answers', 'famly_error'];
const FAMLY_EF_PURGE_HOOK    = 'famly_ef_purge';

function famly_ef_is_anonymised(array $row) {
    return !empty($row['anonymised_at']);
}

/** Remove an enquiry's personal details, keeping date, nursery, outcome and a hash of the Famly ID. */
function famly_ef_anonymise_enquiry($id) {
    $row = famly_ef_get_enquiry($id);
    if (!$row || famly_ef_is_anonymised($row)) return false;
    $data = array_fill_keys(FAMLY_EF_PERSONAL_TEXT, '') + array_fill_keys(FAMLY_EF_PERSONAL_NULL, null);
    $data['consent'] = 0;
    // Our own wording only (the stored reason could include Famly's text).
    $data['status_reason'] = $row['status'] === 'failed' ? 'Not sent to Famly; details were in the ACTION NEEDED email'
        : ($row['status'] === 'held' ? $row['status_reason'] : '');
    if ($row['famly_inquiry_id'] !== '' && !famly_ef_is_hash($row['famly_inquiry_id'])) {
        $data['famly_inquiry_id'] = famly_ef_id_hash($row['famly_inquiry_id']); // still lets the poller skip it
    }
    $data['anonymised_at'] = current_time('mysql');
    return famly_ef_update_enquiry($id, $data) !== false;
}

// ─── Clean-up: nothing personal stays more than a few minutes ─────────

add_filter('cron_schedules', function ($schedules) {
    $schedules['famly_ef_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (Famly enquiry clean-up)'];
    return $schedules;
});
add_action('init', function () {
    if (!wp_next_scheduled(FAMLY_EF_PURGE_HOOK)) wp_schedule_event(time() + MINUTE_IN_SECONDS, 'famly_ef_5min', FAMLY_EF_PURGE_HOOK);
});
add_action(FAMLY_EF_PURGE_HOOK, function () { famly_ef_purge_stale(); });
register_deactivation_hook(FAMLY_EF_FILE, function () { wp_clear_scheduled_hook(FAMLY_EF_PURGE_HOOK); });

/**
 * Remove personal details from any enquiry that still has them. A submission
 * stuck "sending" for 10+ minutes (the request died) gets its ACTION NEEDED
 * email first, so it isn't lost. Rows that existed before this clean-up was
 * added (id <= famly_ef_purge_after_id) are left alone unless $include_existing.
 *
 * @return array { anonymised: int, alerted: int }
 */
function famly_ef_purge_stale($include_existing = false) {
    global $wpdb;
    $out = ['anonymised' => 0, 'alerted' => 0];
    if (!famly_ef_table_exists()) return $out;
    $table = famly_ef_table();

    $after = get_option('famly_ef_purge_after_id', null);
    if ($after === null) {
        $after = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM $table");
        add_option('famly_ef_purge_after_id', $after, '', false);
    }
    $min_id = $include_existing ? 0 : (int) $after;

    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE anonymised_at IS NULL AND id > %d", $min_id), ARRAY_A);
    foreach ($rows as $row) {
        if ($row['status'] === 'pending') {
            if (strtotime($row['created_at']) > current_time('timestamp') - 10 * MINUTE_IN_SECONDS) continue; // still being sent
            famly_ef_update_enquiry($row['id'], ['status' => 'failed']);
            $row['status'] = 'failed';
            famly_ef_send_failure_alert($row, 'The submission did not finish (the website stopped before it could send it to Famly).');
            $out['alerted']++;
        }
        if (famly_ef_anonymise_enquiry($row['id'])) $out['anonymised']++;
    }
    if ($out['anonymised'] || $out['alerted']) famly_ef_log('Clean-up: removed details from ' . $out['anonymised'] . ' enquiry(ies), ' . $out['alerted'] . ' unfinished alerted');
    return $out;
}

/** Replace this enquiry's personal details in a message before it is logged or stored. */
function famly_ef_scrub($text, array $row) {
    $values = [];
    foreach (['child_first_name', 'child_last_name', 'parent_first_name', 'parent_last_name', 'parent_email', 'phone', 'postcode'] as $k) {
        if (!empty($row[$k]) && mb_strlen($row[$k]) > 2) $values[] = $row[$k];
    }
    $phone = famly_ef_normalise_phone($row['phone'] ?? '');
    if ($phone) $values[] = $phone;
    return $values ? str_ireplace($values, '[redacted]', (string) $text) : (string) $text;
}

/** @return array [question label => answer string] from the stored JSON. */
function famly_ef_extra_answers(array $row) {
    $answers = json_decode((string) ($row['extra_answers'] ?? ''), true);
    $out = [];
    foreach (is_array($answers) ? $answers : [] as $a) {
        if (!empty($a['q'])) $out[$a['q']] = is_array($a['a'] ?? null) ? implode(', ', $a['a']) : (string) ($a['a'] ?? '');
    }
    return $out;
}

/** The free-text note added to the Famly inquiry (fields Famly has no slot for). */
function famly_ef_build_note(array $row) {
    $lines = ['Website enquiry from the Book a Tour form (#' . $row['id'] . ').'];
    if ($row['source_label'] !== '') $lines[] = 'How did you hear about us: ' . $row['source_label'];
    if ($row['postcode'] !== '')     $lines[] = 'Postcode: ' . $row['postcode'];
    if ($row['child_gender'] === 'Other') $lines[] = 'Legal gender: Unknown / Other';
    if ($row['phone'] !== '' && famly_ef_normalise_phone($row['phone']) === '') {
        $lines[] = 'Phone (as entered): ' . $row['phone'];
    }
    foreach (famly_ef_extra_answers($row) as $q => $a) {
        if ($a !== '') $lines[] = rtrim($q, ' ?:') . ': ' . $a;
    }
    $lines[] = 'Agreed to receive information by phone or email: ' . ($row['consent'] ? 'Yes' : 'No');

    $utm = [];
    foreach (['source', 'medium', 'campaign', 'content', 'term'] as $k) {
        if (!empty($row['utm_' . $k])) $utm[] = $k . '=' . $row['utm_' . $k];
    }
    if ($utm) $lines[] = 'UTM: ' . implode(', ', $utm);

    return implode("\n", $lines);
}

/** Build Famly's InquiryInput from a stored enquiry. */
function famly_ef_build_inquiry_input(array $row) {
    $contact = [
        'name'  => trim($row['parent_first_name'] . ' ' . $row['parent_last_name']),
        'email' => $row['parent_email'],
    ];
    $phone = famly_ef_normalise_phone($row['phone']);
    if ($phone !== '') $contact['mobilePhone'] = $phone;

    $input = [
        'name'          => trim($row['child_first_name'] . ' ' . $row['child_last_name']),
        'birthday'      => $row['child_dob'],
        'startDate'     => $row['start_date'],
        'siteId'        => $row['famly_site_id'],
        'contacts'      => [$contact],
        'note'          => famly_ef_build_note($row),
        'bookingSource' => 'WEBSITE',
    ];

    $genders = ['Female' => 'FEMALE', 'Male' => 'MALE'];
    if (isset($genders[$row['child_gender']])) $input['gender'] = $genders[$row['child_gender']];

    $sources = famly_ef_sources();
    if (!empty($sources[$row['source_label']])) $input['source'] = $sources[$row['source_label']];

    $system = trim((string) famly_ef_get_setting('reference_system'));
    if ($system !== '') {
        $input['referenceNumber'] = ['foreignId' => 'wp-' . $row['id'], 'system' => $system];
    }

    /** Filter the InquiryInput sent to Famly. */
    return apply_filters('famly_ef_inquiry_input', $input, $row);
}

/**
 * Send a stored enquiry to Famly and record the outcome on the row.
 * Refuses to resend an enquiry that already has a Famly ID.
 *
 * @return array { ok: bool, error: string, inquiry_id: string }
 */
function famly_ef_send_to_famly($id) {
    $row = famly_ef_get_enquiry($id);
    if (!$row) return ['ok' => false, 'error' => 'Enquiry not found', 'inquiry_id' => ''];
    if ($row['famly_inquiry_id'] !== '' || $row['status'] === 'sent') {
        $shown = famly_ef_is_hash($row['famly_inquiry_id']) ? '' : ' (ID ' . $row['famly_inquiry_id'] . ')';
        return ['ok' => false, 'error' => 'Already in Famly' . $shown, 'inquiry_id' => $row['famly_inquiry_id']];
    }
    if (famly_ef_is_anonymised($row)) {
        return ['ok' => false, 'error' => "This enquiry's details were removed from WordPress after the email went out. Add it to Famly from that email.", 'inquiry_id' => ''];
    }

    // Use the nursery's current settings, so a retry picks up a newly added site ID or token.
    $site = famly_ef_sites()[$row['site_key']] ?? null;
    if ($site && !empty($site['site_id'])) {
        $row['famly_site_id'] = $site['site_id'];
    }
    $token = $site ? famly_ef_token_for_site($site) : famly_ef_global_token();

    $error = '';
    if (!$site)                    $error = 'Nursery "' . $row['site_key'] . '" is no longer configured';
    elseif (!$token)               $error = 'Famly API token is not configured';
    elseif (!$row['famly_site_id']) $error = 'Famly site ID is not configured for ' . $row['site_label'];

    $result = ['ok' => false, 'error' => $error, 'inquiry_id' => '', 'http' => 0];
    if ($error === '') {
        $result = famly_ef_create_inquiry(famly_ef_build_inquiry_input($row), $token);
    }

    $update = [
        'famly_site_id'   => $row['famly_site_id'],
        'attempts'        => (int) $row['attempts'] + ($error === '' ? 1 : 0),
        'last_attempt_at' => current_time('mysql'),
    ];
    if ($result['ok']) {
        $update['status']           = 'sent';
        $update['status_reason']    = '';
        $update['famly_inquiry_id'] = $result['inquiry_id'];
        $update['famly_error']      = null;
        famly_ef_log("Enquiry #{$id} ({$row['site_key']}) created in Famly as {$result['inquiry_id']}");
    } else {
        $result['error']         = famly_ef_scrub($result['error'], $row);
        $update['status']        = 'failed';
        $update['status_reason'] = mb_substr($result['error'], 0, 255);
        $update['famly_error']   = $result['error'];
        famly_ef_log("Enquiry #{$id} ({$row['site_key']}) NOT sent to Famly: {$result['error']}");
    }
    famly_ef_update_enquiry($id, $update);

    return $result;
}
