<?php
/**
 * AJAX submission: spam checks, validation, local save, Famly, emails.
 */

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_famly_submit_enquiry', 'famly_ef_handle_submission');
add_action('wp_ajax_nopriv_famly_submit_enquiry', 'famly_ef_handle_submission');

function famly_ef_post($key, $max = 200) {
    $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    return mb_substr($value, 0, $max);
}

function famly_ef_valid_date($value) {
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

function famly_ef_handle_submission() {
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $data  = [
        'site_key'          => famly_ef_post('site', 60),
        'child_first_name'  => famly_ef_post('first_name', 60),
        'child_last_name'   => famly_ef_post('last_name', 60),
        'child_dob'         => famly_ef_post('dob', 10),
        'child_gender'      => famly_ef_post('gender', 20),
        'parent_first_name' => famly_ef_post('parent_first_name', 60),
        'parent_last_name'  => famly_ef_post('parent_last_name', 60),
        'parent_email'      => mb_substr($email, 0, 100),
        'phone'             => famly_ef_post('phone', 45),
        'postcode'          => strtoupper(famly_ef_post('postcode', 20)),
        'start_date'        => famly_ef_post('start_date', 10),
        'source_label'      => famly_ef_post('source', 150),
        'consent'           => famly_ef_post('agree_terms', 1) === '1' ? 1 : 0,
        'utm_source'        => famly_ef_post('utm_source'),
        'utm_medium'        => famly_ef_post('utm_medium'),
        'utm_campaign'      => famly_ef_post('utm_campaign'),
        'utm_content'       => famly_ef_post('utm_content'),
        'utm_term'          => famly_ef_post('utm_term'),
        'page_url'          => mb_substr(esc_url_raw(wp_unslash($_POST['page_url'] ?? '')), 0, 500),
    ];
    if (!in_array($data['child_gender'], ['', 'Female', 'Male', 'Other'], true)) $data['child_gender'] = '';

    $site = famly_ef_resolve_site($data['site_key']);
    if (!$site) {
        famly_ef_log('Submission for unknown nursery key: ' . $data['site_key']);
        wp_send_json_error(['message' => 'This form is not set up correctly. Please contact us directly.']);
    }
    $data['site_key']      = $site['key'];
    $data['site_label']    = $site['label'];
    $data['famly_site_id'] = $site['site_id'] ?? '';

    // 1. Validation (Famly always needs child name, DOB, start date, parent name and email).
    $values = [
        'child_first_name'  => $data['child_first_name'],
        'child_last_name'   => $data['child_last_name'],
        'child_dob'         => $data['child_dob'],
        'child_gender'      => $data['child_gender'],
        'parent_first_name' => $data['parent_first_name'],
        'parent_last_name'  => $data['parent_last_name'],
        'email'             => $data['parent_email'],
        'phone'             => $data['phone'],
        'postcode'          => $data['postcode'],
        'start_date'        => $data['start_date'],
        'source'            => $data['source_label'],
        'agree_terms'       => $data['consent'] ? '1' : '',
    ];
    foreach (famly_ef_required_fields() as $key) {
        if (isset($values[$key]) && $values[$key] === '') {
            wp_send_json_error(['message' => 'Please fill in all required fields.']);
        }
    }
    if (!is_email($data['parent_email'])) {
        wp_send_json_error(['message' => 'Please enter a valid email address.']);
    }
    foreach (['child_dob' => 'date of birth', 'start_date' => 'preferred start date'] as $key => $label) {
        if ($data[$key] !== '' && !famly_ef_valid_date($data[$key])) {
            wp_send_json_error(['message' => 'Please enter a valid ' . $label . '.']);
        }
    }
    foreach (['child_dob', 'start_date'] as $key) {
        if ($data[$key] === '') $data[$key] = null;
    }

    // Extra questions (answers must be one of the configured options).
    $posted  = (isset($_POST['extra']) && is_array($_POST['extra'])) ? wp_unslash($_POST['extra']) : [];
    $answers = [];
    foreach (famly_ef_extra_questions($site) as $key => $q) {
        $raw = $posted[$key] ?? '';
        if ($q['type'] === 'checkbox') {
            $value = array_values(array_intersect($q['options'], array_map('sanitize_text_field', (array) $raw)));
            $empty = !$value;
        } elseif ($q['type'] === 'select') {
            $v     = is_array($raw) ? '' : sanitize_text_field($raw);
            $value = in_array($v, $q['options'], true) ? $v : '';
            $empty = $value === '';
        } else {
            $value = is_array($raw) ? '' : mb_substr(sanitize_text_field($raw), 0, 200);
            $empty = $value === '';
        }
        if ($q['required'] && $empty) {
            wp_send_json_error(['message' => 'Please fill in all required fields.']);
        }
        if (!$empty) $answers[] = ['q' => $q['label'], 'a' => $value];
    }
    $data['extra_answers'] = $answers ? wp_json_encode($answers) : null;

    // 2. Turnstile. If Cloudflare can't be reached, let the enquiry through
    //    rather than lose it.
    $turnstile = famly_ef_verify_turnstile(famly_ef_post('turnstile_token', 2048));
    if ($turnstile === 'fail') {
        famly_ef_insert_held($data, 'Turnstile check failed');
        famly_ef_log('Held a submission: Turnstile failed (' . $site['key'] . ')');
        wp_send_json_error(['message' => 'We couldn\'t verify you\'re not a robot. Please try again.', 'turnstile' => true]);
    }

    // 3. Honeypot. Autofill and password managers sometimes fill hidden
    //    fields, so a filled honeypot only holds the enquiry when Turnstile
    //    hasn't verified the visitor. Held entries are kept, not sent.
    if (famly_ef_post('fe_extra') !== '') {
        if ($turnstile === 'pass') {
            famly_ef_log('Honeypot filled but Turnstile passed; treating as a person (' . $site['key'] . ')');
        } else {
            famly_ef_insert_held($data, 'Honeypot field was filled in');
            famly_ef_log('Held a submission: honeypot filled, Turnstile ' . $turnstile . ' (' . $site['key'] . ')');
            wp_send_json_success(['message' => 'Enquiry submitted successfully.', 'nursery' => $site['label']]);
        }
    }

    // 4. Nonce. Pages can be served from cache, so a stale nonce alone doesn't
    //    block a submission that passed Turnstile.
    $nonce_ok = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'famly_enquiry_nonce');
    if (!$nonce_ok && $turnstile !== 'pass') {
        wp_send_json_error(['message' => 'Security check failed. Please refresh the page and try again.']);
    }

    // 5. Save, send to Famly, notify.
    $id = famly_ef_insert_enquiry($data + ['status' => 'pending']);
    if (!$id) {
        // Couldn't even save locally: email the full details so it isn't lost.
        famly_ef_send_failure_alert($data + ['id' => 0, 'status' => 'failed'], 'The enquiry could not be saved to the WordPress database, so it was not sent to Famly either.');
        wp_send_json_success(['message' => 'Enquiry received — thank you!', 'nursery' => $site['label']]);
    }

    $result = famly_ef_send_to_famly($id);
    $row    = famly_ef_get_enquiry($id);

    if ($result['ok']) {
        if (famly_ef_get_setting('notify_every', '1') === '1') famly_ef_send_notification($row);
    } else {
        famly_ef_send_failure_alert($row, $result['error']);
    }

    // It's in Famly and/or the email has gone: don't keep the family's details here.
    famly_ef_anonymise_enquiry($id);

    wp_send_json_success(['message' => 'Enquiry submitted successfully.', 'nursery' => $site['label']]);
}

/** Held by a spam check: record only that it happened (no details), for the counts. */
function famly_ef_insert_held(array $data, $reason) {
    return famly_ef_insert_enquiry([
        'site_key'      => $data['site_key'],
        'site_label'    => $data['site_label'],
        'famly_site_id' => $data['famly_site_id'],
        'status'        => 'held',
        'status_reason' => $reason,
        'anonymised_at' => current_time('mysql'),
    ]);
}

/** @return string 'pass' | 'fail' | 'error' (Cloudflare unreachable) | 'off' */
function famly_ef_verify_turnstile($token) {
    $keys = famly_ef_turnstile_keys();
    if (!$keys) return 'off';
    if ($token === '') return 'fail';

    $ip   = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    $body = ['secret' => $keys['secret'], 'response' => $token];
    if (filter_var($ip, FILTER_VALIDATE_IP)) $body['remoteip'] = $ip;

    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['timeout' => 10, 'body' => $body]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 500) {
        famly_ef_log('Turnstile verify unavailable, allowing submission: ' . (is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response)));
        return 'error';
    }
    $json = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($json['success'])) return 'pass';
    famly_ef_log('Turnstile rejected token: ' . implode(',', (array) ($json['error-codes'] ?? [])));
    return 'fail';
}


// ─── EMAILS ─────────────────────────────────────────────────────────

function famly_ef_recipients(array $row, $include_alert_list) {
    $site = famly_ef_sites()[$row['site_key']] ?? [];
    $list = [famly_ef_notify_email()];
    if (!empty($site['notify_email'])) $list[] = $site['notify_email'];
    if ($include_alert_list) {
        $list = array_merge($list, array_map('trim', explode(',', (string) famly_ef_get_setting('alert_emails'))));
    }
    return array_values(array_unique(array_filter($list, 'is_email')));
}

function famly_ef_email_details(array $row) {
    $date = function ($v) { return $v ? wp_date('d/m/Y', strtotime($v)) : ''; };
    $lines = [
        'Nursery'         => $row['site_label'],
        'Child'           => trim($row['child_first_name'] . ' ' . $row['child_last_name']),
        'DOB'             => $date($row['child_dob']),
        'Gender'          => $row['child_gender'] === 'Other' ? 'Unknown / Other' : $row['child_gender'],
        'Parent'          => trim($row['parent_first_name'] . ' ' . $row['parent_last_name']),
        'Email'           => $row['parent_email'],
        'Phone'           => $row['phone'],
        'Postcode'        => $row['postcode'],
        'Preferred Start' => $date($row['start_date']),
        'Source'          => $row['source_label'],
    ];
    foreach (famly_ef_extra_answers($row) as $q => $a) $lines[rtrim($q, ' ?:')] = $a;
    $lines['Consent'] = $row['consent'] ? 'Yes' : 'No';
    $utm = array_filter([$row['utm_source'] ?? '', $row['utm_medium'] ?? '', $row['utm_campaign'] ?? '']);
    if ($utm) $lines['UTM'] = implode(' / ', $utm);

    $out = '';
    foreach ($lines as $label => $value) {
        if ($value !== '' && $value !== null) $out .= $label . ': ' . $value . "\n";
    }
    return $out;
}

function famly_ef_mail($to, $subject, $body, array $row) {
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if (!empty($row['parent_email']) && is_email($row['parent_email'])) {
        $name      = trim(preg_replace('/[\r\n<>"]+/', '', $row['parent_first_name'] . ' ' . $row['parent_last_name']));
        $headers[] = 'Reply-To: ' . ($name !== '' ? $name . ' ' : '') . '<' . $row['parent_email'] . '>';
    }
    $sent = wp_mail($to, $subject, $body, $headers);
    if (!$sent) famly_ef_log('Email FAILED for enquiry #' . ($row['id'] ?? 0) . ': ' . $subject);
    return $sent;
}

/** Normal "new enquiry" email, sent when the enquiry reached Famly. */
function famly_ef_send_notification(array $row) {
    $child   = trim($row['child_first_name'] . ' ' . $row['child_last_name']);
    $subject = 'New Tour Enquiry: ' . $child . ' (' . $row['site_label'] . ')';
    $body    = "A new tour enquiry has been submitted. It has been added to Famly.\n\n"
             . famly_ef_email_details($row)
             . "\n---\nFamly inquiry ID: " . $row['famly_inquiry_id']
             . "\nView all enquiries in WordPress: " . admin_url('admin.php?page=famly-enquiries');
    return famly_ef_mail(famly_ef_recipients($row, false), $subject, $body, $row);
}

/** "ACTION NEEDED" email when an enquiry could not be added to Famly. */
function famly_ef_send_failure_alert(array $row, $error) {
    $child   = trim($row['child_first_name'] . ' ' . $row['child_last_name']);
    $subject = 'ACTION NEEDED – enquiry not added to Famly: ' . $child . ' (' . $row['site_label'] . ')';
    $body    = "A tour enquiry came in through the website but could NOT be added to Famly automatically.\n"
             . "Please add it to Famly by hand (Attendance → Registration → Enquiries → New → + Enquiry).\n"
             . "The website doesn't keep a copy of these details, so this email is the only record.\n\n"
             . famly_ef_email_details($row)
             . "\n---\nReason: " . $error
             . "\nWebsite enquiry #" . ($row['id'] ?? 0)
             . "\nFamly Enquiries in WordPress: " . admin_url('admin.php?page=famly-enquiries&status=failed');
    return famly_ef_mail(famly_ef_recipients($row, true), $subject, $body, $row);
}
