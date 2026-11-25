<?php
/**
 * New-enquiry alerts: Famly has no enquiry webhooks and doesn't email the
 * nursery about new enquiries, so this checks each nursery's Famly enquiries
 * every 10 minutes and emails anything new.
 *
 * - The first check for a nursery only records what's already there (no emails).
 * - Enquiries this plugin created itself (API form mode) are skipped: they
 *   were already emailed when they were submitted.
 * - By default only website and lead-site enquiries trigger an email, not
 *   ones staff add by hand or import.
 * - After 3 failed checks in a row an alert goes out once (expired or revoked
 *   token), and a "working again" email follows when it recovers.
 *
 * Runs from WP-Cron (`famly_ef_poll`, every 10 minutes) and from
 * `wp famly-enquiry poll` for a server cron. A lock stops overlapping runs.
 */

if (!defined('ABSPATH')) exit;

const FAMLY_EF_POLL_HOOK      = 'famly_ef_poll';
const FAMLY_EF_POLL_FAIL_LIMIT = 3;
const FAMLY_EF_POLL_SEEN_MAX  = 500;
// Booking sources that mean a family enquired online (Famly form or a lead site).
const FAMLY_EF_ONLINE_SOURCES = ['WEBSITE', 'CHILDCARE_SITE', 'DAYNURSERIES_SITE', 'NUURI_SITE', 'FACEBOOK'];

const FAMLY_EF_POLL_QUERY = 'query NewEnquiries($ids: [SiteId!]!, $cursor: InquiriesCursor) {
  inquiries { listBySiteIdsPaginated(siteIds: $ids, sortOrder: ByCreatedDesc, pageSize: 20, cursor: $cursor) {
    next
    results { id createdAt status source bookingSource note
      actions { type subject note due }
      child { name { fullName } birthday
        contacts { name { fullName } email phoneNumbers { value phoneType } } } } } } }';

const FAMLY_EF_RESPONSES_QUERY = 'query FormAnswers($form: CustomRegistrationFormId!, $ss: SiteSetId!, $from: LocalDate) {
  formResponses(formId: $form, siteSetId: $ss, startDate: $from, pageSize: 50) {
    responses { responseId submissionDate sections { sectionName fields { fieldName fieldType value } } } } }';

// ─── Scheduling ─────────────────────────────────────────────────────

add_filter('cron_schedules', function ($schedules) {
    $schedules['famly_ef_10min'] = ['interval' => 10 * MINUTE_IN_SECONDS, 'display' => 'Every 10 minutes (Famly enquiry alerts)'];
    return $schedules;
});

add_action('init', function () {
    $enabled   = famly_ef_get_setting('poll_enabled', '1') === '1';
    $scheduled = wp_next_scheduled(FAMLY_EF_POLL_HOOK);
    if ($enabled && !$scheduled) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'famly_ef_10min', FAMLY_EF_POLL_HOOK);
    } elseif (!$enabled && $scheduled) {
        wp_clear_scheduled_hook(FAMLY_EF_POLL_HOOK);
    }
});

add_action(FAMLY_EF_POLL_HOOK, function () {
    famly_ef_poll_all();
});

register_deactivation_hook(FAMLY_EF_FILE, function () {
    wp_clear_scheduled_hook(FAMLY_EF_POLL_HOOK);
    delete_option('famly_ef_poll_lock');
});

// ─── State ──────────────────────────────────────────────────────────

function famly_ef_poll_state() {
    $state = get_option('famly_ef_poll_state', []);
    return is_array($state) ? $state : [];
}

/**
 * Per-nursery poll state. Holds no enquiry data: "seen" is a list of keyed
 * hashes of Famly enquiry IDs, and last_error is one of our own fixed messages.
 */
function famly_ef_poll_site_state($key) {
    $state = famly_ef_poll_state();
    $st = array_merge([
        'seeded' => false, 'seeded_at' => 0, 'seen' => [], 'last_checked' => 0, 'last_ok' => 0,
        'last_new' => 0, 'last_new_count' => 0, 'last_error' => '', 'last_error_at' => 0,
        'failures' => 0, 'alerted' => false,
    ], $state[$key] ?? []);
    // State written by 1.1.x held raw IDs: hash them.
    $st['seen'] = array_values(array_map(function ($v) { return famly_ef_is_hash($v) ? $v : famly_ef_id_hash($v); }, (array) $st['seen']));
    return $st;
}

function famly_ef_save_poll_site_state($key, array $site_state) {
    $state       = famly_ef_poll_state();
    $state[$key] = $site_state;
    update_option('famly_ef_poll_state', $state, false);
}

// ─── Polling ────────────────────────────────────────────────────────

/**
 * Check every nursery. Options: dry_run (fetch and report only: no emails,
 * no saved state) and seed (record what's there without emailing).
 *
 * @return array site key => result summary
 */
function famly_ef_poll_all(array $opts = []) {
    $opts += ['dry_run' => false, 'seed' => false];
    if (famly_ef_get_setting('poll_enabled', '1') !== '1' && !$opts['dry_run']) return [];

    // Lock: add_option() is atomic, so only one run gets it. Stale after 5 min.
    if (!$opts['dry_run']) {
        if (!add_option('famly_ef_poll_lock', time(), '', false)) {
            if (time() - (int) get_option('famly_ef_poll_lock') < 5 * MINUTE_IN_SECONDS) return ['locked' => true];
            update_option('famly_ef_poll_lock', time(), false);
        }
    }

    $results = [];
    try {
        foreach (famly_ef_sites() as $site) {
            $results[$site['key']] = famly_ef_poll_site($site, $opts);
        }
    } finally {
        if (!$opts['dry_run']) delete_option('famly_ef_poll_lock');
    }
    return $results;
}

function famly_ef_poll_site(array $site, array $opts) {
    $key = $site['key'];

    // Switched off, or not set up yet: skip quietly (not a failure, no alert).
    $waiting = famly_ef_poll_waiting_reason($site);
    if ($waiting !== '') return ['ok' => true, 'waiting' => $waiting, 'new' => 0];

    $st    = famly_ef_poll_site_state($key);
    $now   = time();
    $token = famly_ef_token_for_site($site);

    $st['last_checked'] = $now;
    $error = '';

    $inquiries = [];
    if ($error === '') {
        $fetched = famly_ef_fetch_recent_inquiries($site, $token, $st);
        if ($fetched['error'] !== '') $error = $fetched['error'];  // our own category text
        $inquiries = $fetched['inquiries'];
    }

    if ($error !== '') {
        famly_ef_log("Poll {$key}: FAILED ({$error})");
        if ($opts['dry_run']) return ['ok' => false, 'error' => $error];
        $st['failures']++;
        $st['last_error']    = $error;
        $st['last_error_at'] = $now;
        if ($st['failures'] >= FAMLY_EF_POLL_FAIL_LIMIT && !$st['alerted']) {
            famly_ef_send_poll_failure_alert($site, $st);
            $st['alerted'] = true;
        }
        famly_ef_save_poll_site_state($key, $st);
        return ['ok' => false, 'error' => $error, 'failures' => $st['failures']];
    }

    // Success
    if ($st['alerted'] && !$opts['dry_run']) famly_ef_send_poll_recovered_email($site, $st);
    $st['failures'] = 0;
    $st['alerted']  = false;
    $st['last_ok']  = $now;

    $ids    = wp_list_pluck($inquiries, 'id');
    $hashes = array_map('famly_ef_id_hash', $ids);

    // First run (or explicit seed): remember what's there, email nothing.
    if (!$st['seeded'] || $opts['seed']) {
        if ($opts['dry_run']) return ['ok' => true, 'seeded' => false, 'would_seed' => count($ids), 'new' => []];
        $st['seen']      = array_slice(array_values(array_unique(array_merge($hashes, $st['seen']))), 0, FAMLY_EF_POLL_SEEN_MAX);
        $st['seeded']    = true;
        $st['seeded_at'] = $now;
        famly_ef_save_poll_site_state($key, $st);
        famly_ef_log("Poll {$key}: seeded with " . count($ids) . ' existing enquiries (no emails)');
        return ['ok' => true, 'seeded_now' => count($ids), 'new' => []];
    }

    $own = famly_ef_inquiry_ids_created_here($ids);
    $new = [];
    $skipped = 0;
    foreach ($inquiries as $inq) {
        if (in_array(famly_ef_id_hash($inq['id']), $st['seen'], true)) continue;
        if (in_array($inq['id'], $own, true)) { $skipped++; continue; }                  // already emailed at submission
        if (strtotime($inq['createdAt']) < $st['seeded_at'] - HOUR_IN_SECONDS) { $skipped++; continue; } // older than our first look
        if (!famly_ef_poll_wants($inq)) { $skipped++; continue; }                           // staff-added / imported
        $new[] = $inq;
    }
    $new = array_reverse($new); // oldest first

    if ($new) {
        $answers = famly_ef_match_form_answers($site, $token, $new);
        foreach ($new as $inq) {
            if (!$opts['dry_run']) famly_ef_send_new_inquiry_email($site, $inq, $answers[$inq['id']] ?? null);
        }
    }

    if ($opts['dry_run']) {
        return ['ok' => true, 'new' => $new, 'skipped' => $skipped, 'answers' => $new ? ($answers ?? []) : []];
    }

    $st['seen'] = array_slice(array_values(array_unique(array_merge($hashes, $st['seen']))), 0, FAMLY_EF_POLL_SEEN_MAX);
    if ($new) {
        $st['last_new']       = $now;
        $st['last_new_count'] = count($new);
    }
    famly_ef_save_poll_site_state($key, $st);
    famly_ef_log("Poll {$key}: " . count($inquiries) . ' checked, ' . count($new) . ' new emailed, ' . $skipped . ' skipped');
    return ['ok' => true, 'new' => count($new), 'skipped' => $skipped];
}

/**
 * Newest enquiries first. Reads more pages (up to 3) while a whole page is
 * unseen, so a burst of enquiries between checks isn't missed.
 */
function famly_ef_fetch_recent_inquiries(array $site, $token, array $st) {
    $all    = [];
    $cursor = null;
    for ($page = 0; $page < 3; $page++) {
        $vars = ['ids' => [$site['site_id']]];
        if ($cursor) $vars['cursor'] = $cursor;
        $r    = famly_ef_graphql(FAMLY_EF_POLL_QUERY, $vars, $token, 20);
        $list = $r['data']['inquiries']['listBySiteIdsPaginated'] ?? null;
        // Child/contact fields can be refused (403) while the list itself works:
        // that comes back as partial data, which is fine.
        if (!$r['ok'] || !is_array($list) || !isset($list['results'])) {
            // The log (PHP error log, not the database) gets Famly's message; state and emails get our category.
            famly_ef_log('Poll ' . $site['key'] . ': Famly said: ' . mb_substr((string) $r['error'], 0, 200));
            return ['inquiries' => $all, 'error' => famly_ef_error_category($r)];
        }
        $all = array_merge($all, $list['results']);
        $unseen = array_diff(array_map('famly_ef_id_hash', wp_list_pluck($list['results'], 'id')), $st['seen']);
        if (!$st['seeded'] || count($unseen) < count($list['results']) || empty($list['next'])) break;
        $cursor = $list['next'];
    }
    return ['inquiries' => $all, 'error' => ''];
}

/** Should this enquiry trigger an email? */
function famly_ef_poll_wants(array $inq) {
    if (famly_ef_get_setting('poll_scope', 'online') === 'all') return true;
    if (in_array($inq['bookingSource'] ?? '', FAMLY_EF_ONLINE_SOURCES, true)) return true;
    foreach ((array) ($inq['actions'] ?? []) as $a) {
        if (stripos((string) ($a['note'] ?? ''), 'registration form') !== false) return true;
    }
    return false;
}

/** Famly inquiry IDs this plugin created (API form mode). */
function famly_ef_inquiry_ids_created_here(array $ids) {
    global $wpdb;
    if (!$ids || !famly_ef_table_exists()) return [];
    // Rows keep the raw ID until their details are removed, then a hash of it.
    $lookup = [];
    foreach ($ids as $id) { $lookup[$id] = $id; $lookup[famly_ef_id_hash($id)] = $id; }
    $keys  = array_keys($lookup);
    $in    = implode(',', array_fill(0, count($keys), '%s'));
    $found = $wpdb->get_col($wpdb->prepare('SELECT famly_inquiry_id FROM ' . famly_ef_table() . " WHERE famly_inquiry_id IN ($in)", $keys));
    return array_values(array_unique(array_map(function ($v) use ($lookup) { return $lookup[$v]; }, $found)));
}

/**
 * Best effort: find each new enquiry's Famly form submission (by time, within
 * 3 minutes) so the email can include the answers. Needs the token to have
 * "View forms and responses"; without it the email just has less detail.
 *
 * @return array inquiry id => [label => value]
 */
function famly_ef_match_form_answers(array $site, $token, array $inquiries) {
    if (!preg_match('#customInquiryForm/([0-9a-f-]{36})/to/([0-9a-f-]{36})#i', (string) ($site['form_url'] ?? ''), $m)) return [];
    $oldest = min(array_map(function ($i) { return strtotime($i['createdAt']); }, $inquiries));
    $r = famly_ef_graphql(FAMLY_EF_RESPONSES_QUERY, ['form' => $m[1], 'ss' => $m[2], 'from' => gmdate('Y-m-d', $oldest - DAY_IN_SECONDS)], $token, 20);
    $responses = $r['data']['formResponses']['responses'] ?? null;
    if (!$r['ok'] || !is_array($responses)) {
        famly_ef_log('Poll ' . $site['key'] . ': form answers unavailable (' . ($r['error'] ?: 'no data') . ')');
        return [];
    }

    $matched = [];
    $used    = [];
    foreach ($inquiries as $inq) {
        $t = strtotime($inq['createdAt']);
        $best = null; $best_gap = 181;
        foreach ($responses as $i => $resp) {
            if (isset($used[$i])) continue;
            $gap = abs(strtotime($resp['submissionDate']) - $t);
            if ($gap < $best_gap) { $best = $i; $best_gap = $gap; }
        }
        if ($best !== null) {
            $used[$best] = true;
            $matched[$inq['id']] = famly_ef_flatten_form_response($responses[$best]);
        }
    }
    return $matched;
}

/** Turn a Famly form response into [label => readable value]. */
function famly_ef_flatten_form_response(array $resp) {
    $out = [];
    foreach ((array) $resp['sections'] as $section) {
        $fields = (array) $section['fields'];
        foreach ($fields as $f) {
            if (in_array($f['fieldType'], ['PRIVACY_POLICY_CONFIRMATION', 'UPLOADER', 'CUSTOM_UPLOADER', 'SIGNATURE'], true)) continue;
            $value = famly_ef_readable_value($f['value']);
            if ($value === '') continue;
            // Famly's builder gives custom fields generic names ("Dropdown - single",
            // "Custom multi-select (checkbox)"); the question is the section name.
            $generic = preg_match('/^(dropdown\b|custom\b|((short|long|free) )?text( field)?$|number( field)?$|date( field| picker)?$)/i', trim($f['fieldName']));
            $label   = (count($fields) === 1 || $generic) && $section['sectionName'] !== ''
                ? $section['sectionName']
                : trim($section['sectionName'] . ': ' . $f['fieldName'], ': ');
            $out[trim($label)] = $value;
        }
    }
    return $out;
}

function famly_ef_readable_value($raw) {
    if ($raw === null) return '';
    $raw  = trim((string) $raw);
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $parts = [];
        array_walk_recursive($json, function ($v, $k) use (&$parts) {
            if ($v === null || $v === '' || is_bool($v)) return;
            $parts[] = (is_string($k) && !preg_match('/id$/i', $k) ? $k . ': ' : '') . famly_ef_readable_value((string) $v);
        });
        return implode(', ', $parts);
    }
    $raw = trim(wp_strip_all_tags($raw));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return wp_date('d/m/Y', strtotime($raw . ' 12:00'));
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $raw)) return wp_date('D d/m/Y H:i', strtotime($raw));
    return $raw;
}

// ─── Emails ─────────────────────────────────────────────────────────

function famly_ef_famly_inquiries_url(array $site) {
    return 'https://app.famly.co/#/institution/' . rawurlencode($site['site_id']) . '/inquiries';
}

function famly_ef_poll_recipients(array $site, $include_alert_list) {
    $list = [famly_ef_notify_email()];
    if (!empty($site['notify_email'])) $list[] = $site['notify_email'];
    if ($include_alert_list) {
        $list = array_merge($list, array_map('trim', explode(',', (string) famly_ef_get_setting('alert_emails'))));
    }
    return array_values(array_unique(array_filter($list, 'is_email')));
}

/** Short nursery name for subjects: the key, humanised ("surrey-quays" -> "Surrey Quays"). */
function famly_ef_site_short_name(array $site) {
    return ucwords(str_replace(['-', '_'], ' ', (string) $site['key']));
}

/**
 * The enquirer's name and email: from the enquiry's contact when the token can
 * read it, otherwise from the matched Famly form answers.
 *
 * @return array { name: string, email: string }
 */
function famly_ef_enquirer(array $inq, $answers) {
    $contact = $inq['child']['contacts'][0] ?? null;
    $name    = trim((string) ($contact['name']['fullName'] ?? ''));
    $email   = trim((string) ($contact['email'] ?? ''));

    if (is_array($answers)) {
        if ($name === '') {
            $parts = [];
            foreach ($answers as $label => $value) {
                // "New contact: First name", "Parent: Last name"... but not the child's
                if (preg_match('/^(?!child)[^:]+:\s*(first |last |full )?name$/i', $label) && !is_email($value)) $parts[] = $value;
            }
            $name = trim(implode(' ', $parts));
        }
        if ($email === '') {
            foreach ($answers as $value) {
                if (is_email($value)) { $email = $value; break; }
            }
        }
    }
    return ['name' => $name, 'email' => is_email($email) ? $email : ''];
}

/** Plain-text email for a new Famly enquiry: nursery, enquirer name and email, Famly link (no sending). */
function famly_ef_build_new_inquiry_email(array $site, array $inq, $answers) {
    $who = famly_ef_enquirer($inq, $answers);

    $subject = 'New enquiry – ' . famly_ef_site_short_name($site);
    $body    = "A new enquiry has come in through Famly.\n\n"
             . 'Nursery: ' . $site['label'] . "\n"
             . 'Name: ' . ($who['name'] !== '' ? $who['name'] : 'not available') . "\n"
             . 'Email: ' . ($who['email'] !== '' ? $who['email'] : 'not available') . "\n";
    if ($who['name'] === '' && $who['email'] === '') {
        $body .= "\nThe enquirer's details couldn't be read from Famly; please check the enquiry in Famly.\n";
    }
    $body .= "\nView it in Famly: " . famly_ef_famly_inquiries_url($site) . "\n";

    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if ($who['email'] !== '') {
        $reply_name = trim(preg_replace('/[\r\n<>"]+/', '', $who['name']));
        $headers[]  = 'Reply-To: ' . ($reply_name !== '' ? $reply_name . ' ' : '') . '<' . $who['email'] . '>';
    }

    return ['subject' => $subject, 'body' => $body, 'headers' => $headers];
}

function famly_ef_send_new_inquiry_email(array $site, array $inq, $answers) {
    $mail = famly_ef_build_new_inquiry_email($site, $inq, $answers);
    $sent = wp_mail(famly_ef_poll_recipients($site, false), $mail['subject'], $mail['body'], $mail['headers']);
    if (!$sent) famly_ef_log('Poll ' . $site['key'] . ': new-enquiry email FAILED to send');
    return $sent;
}

function famly_ef_send_poll_failure_alert(array $site, array $st) {
    $subject = 'ACTION NEEDED – Famly enquiry alerts stopped (' . $site['label'] . ')';
    $body = "The website can't check Famly for new enquiries for {$site['label']}, so new-enquiry emails have stopped.\n\n"
          . "Last error: {$st['last_error']}\n"
          . "Failed checks in a row: {$st['failures']}\n\n"
          . "This usually means the Famly API token has expired or been revoked. Create a new token in Famly\n"
          . "(Settings → Integrations → Manage API Tokens, with Enrolment → Manage enquiries) and paste it into\n"
          . admin_url('admin.php?page=famly-enquiry-settings') . "\n\n"
          . "Until then, please check Famly for new enquiries directly: " . famly_ef_famly_inquiries_url($site) . "\n";
    wp_mail(famly_ef_poll_recipients($site, true), $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
    famly_ef_log('Poll ' . $site['key'] . ': failure alert sent');
}

function famly_ef_send_poll_recovered_email(array $site, array $st) {
    $subject = 'Famly enquiry alerts working again (' . $site['label'] . ')';
    $body = "The website can check Famly for new enquiries for {$site['label']} again, so new-enquiry emails have resumed.\n\n"
          . "Enquiries that arrived while it was down are not emailed retrospectively; please check Famly for anything since "
          . wp_date('D d/m/Y H:i', (int) $st['last_ok'] ?: time()) . ": " . famly_ef_famly_inquiries_url($site) . "\n";
    wp_mail(famly_ef_poll_recipients($site, true), $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

// ─── Admin "Check now" ──────────────────────────────────────────────

add_action('admin_post_famly_ef_poll_now', function () {
    if (!current_user_can('manage_options')) wp_die('Not allowed');
    check_admin_referer('famly_ef_poll_now');
    $r   = famly_ef_poll_all();
    $msg = [];
    foreach ($r as $key => $res) {
        if ($key === 'locked') { $msg[] = 'A check is already running.'; continue; }
        $msg[] = $key . ': ' . (!$res['ok'] ? 'failed (' . $res['error'] . ')' : (isset($res['waiting']) ? strtolower($res['waiting']) : (isset($res['seeded_now']) ? 'first check, recorded ' . $res['seeded_now'] . ' existing enquiries' : $res['new'] . ' new')));
    }
    wp_safe_redirect(add_query_arg('famly_msg', rawurlencode('Checked Famly: ' . implode('; ', $msg)), admin_url('admin.php?page=famly-enquiries')));
    exit;
});
