<?php
/**
 * Famly Enquiries → Settings screen.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_init', function () {
    register_setting('famly_ef_settings_group', 'famly_ef_settings', ['sanitize_callback' => 'famly_ef_sanitize_settings']);
});

/** Secrets left blank on the form keep their saved value; tick "clear" to remove. */
function famly_ef_keep_secret($new, $old, $clear) {
    if ($clear) return '';
    $new = trim((string) $new);
    return $new !== '' ? $new : (string) $old;
}

function famly_ef_sanitize_settings($input) {
    $input = is_array($input) ? $input : [];
    $old   = famly_ef_settings();
    $out   = [];

    $out['api_token'] = famly_ef_keep_secret(sanitize_text_field($input['api_token'] ?? ''), $old['api_token'], !empty($input['api_token_clear']));
    $url = esc_url_raw(trim($input['api_url'] ?? ''), ['https']);
    $out['api_url'] = ($url === FAMLY_EF_DEFAULT_API_URL) ? '' : $url;

    // Nurseries
    $old_sites = [];
    foreach ((array) $old['sites'] as $s) if (!empty($s['key'])) $old_sites[$s['key']] = $s;
    $out['sites'] = [];
    $seen = [];
    foreach ((array) ($input['sites'] ?? []) as $row) {
        $key = sanitize_title($row['key'] ?? '');
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $site_id = strtolower(trim(sanitize_text_field($row['site_id'] ?? '')));
        if ($site_id !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $site_id)) {
            add_settings_error('famly_ef_settings', 'site_id_' . $key, 'The Famly site ID for "' . $key . '" is not a valid UUID, so it was not saved.');
            $site_id = $old_sites[$key]['site_id'] ?? '';
        }
        $notify   = sanitize_email($row['notify_email'] ?? '');
        $form_url = esc_url_raw(trim($row['form_url'] ?? ''), ['https']);
        if ($form_url !== '' && !preg_match('#^https://app\.famly\.(co|de)/#', $form_url)) {
            add_settings_error('famly_ef_settings', 'form_url_' . $key, 'The Famly form link for "' . $key . '" doesn\'t look like an app.famly.co link. It was saved, but check it.', 'warning');
        }
        $mode = ($row['mode'] ?? 'link') === 'form' ? 'form' : 'link';
        if ($mode === 'link' && $form_url === '') {
            add_settings_error('famly_ef_settings', 'mode_' . $key, '"' . $key . '" is set to link mode but has no Famly form link, so its button won\'t show.', 'warning');
        }
        $out['sites'][] = [
            'key'             => $key,
            'label'           => sanitize_text_field($row['label'] ?? '') ?: $key,
            'mode'            => $mode,
            'form_url'        => $form_url,
            'site_id'         => $site_id,
            'notify_email'    => $notify,
            'api_token'       => famly_ef_keep_secret(sanitize_text_field($row['api_token'] ?? ''), $old_sites[$key]['api_token'] ?? '', !empty($row['api_token_clear'])),
            'extra_questions' => sanitize_textarea_field($row['extra_questions'] ?? ''),
            'poll'            => !empty($row['poll']) ? '1' : '0',
        ];
    }

    $out['notify_email']  = sanitize_email($input['notify_email'] ?? '') ?: get_option('admin_email');
    $out['notify_every']  = !empty($input['notify_every']) ? '1' : '0';
    $out['poll_enabled']  = !empty($input['poll_enabled']) ? '1' : '0';
    $out['poll_scope']    = ($input['poll_scope'] ?? 'online') === 'all' ? 'all' : 'online';
    $alerts = array_filter(array_map('sanitize_email', array_map('trim', explode(',', (string) ($input['alert_emails'] ?? '')))));
    $out['alert_emails']  = implode(', ', $alerts);
    $out['contact_email'] = sanitize_email($input['contact_email'] ?? '') ?: get_option('admin_email');

    // "How did you hear about us?" options
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) ($input['sources_text'] ?? '')) as $line) {
        $parts = array_map('trim', explode('|', sanitize_text_field($line), 2));
        if ($parts[0] === '') continue;
        $enum = $parts[1] ?? '';
        if ($enum !== '' && !in_array($enum, FAMLY_EF_SOURCES, true)) {
            add_settings_error('famly_ef_settings', 'source_' . md5($line), 'Unknown Famly source "' . $enum . '" for "' . $parts[0] . '". That option is kept, but no source will be set in Famly for it.');
        }
        $lines[] = $parts[0] . ($enum !== '' ? ' | ' . $enum : '');
    }
    $out['sources_text'] = implode("\n", $lines);

    $out['extra_questions'] = sanitize_textarea_field($input['extra_questions'] ?? '');
    $out['link_intro']      = sanitize_textarea_field($input['link_intro'] ?? '');
    $out['link_button']     = sanitize_text_field($input['link_button'] ?? '') ?: 'Book your tour';
    $out['link_note']       = sanitize_textarea_field($input['link_note'] ?? '');
    $out['link_new_tab']    = !empty($input['link_new_tab']) ? '1' : '0';

    $out['required_fields'] = array_values(array_intersect((array) ($input['required_fields'] ?? []), array_keys(FAMLY_EF_FIELDS)));

    $mode = $input['turnstile'] ?? 'off';
    $out['turnstile']          = in_array($mode, ['gf', 'custom', 'off'], true) ? $mode : 'off';
    $out['turnstile_site_key'] = sanitize_text_field($input['turnstile_site_key'] ?? '');
    $out['turnstile_secret']   = famly_ef_keep_secret(sanitize_text_field($input['turnstile_secret'] ?? ''), $old['turnstile_secret'], !empty($input['turnstile_secret_clear']));

    $out['reference_system'] = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($input['reference_system'] ?? '')));
    $out['custom_css']       = wp_strip_all_tags($input['custom_css'] ?? '');

    return $out;
}

// "Test connection" button: list the Famly sites each token can see.
add_action('admin_post_famly_ef_test_connection', function () {
    if (!current_user_can('manage_options')) wp_die('Not allowed');
    check_admin_referer('famly_ef_test_connection');

    $tokens = [];
    if (famly_ef_global_token()) $tokens['Shared token'] = famly_ef_global_token();
    foreach (famly_ef_sites() as $site) {
        $t = famly_ef_token_for_site($site);
        if ($t && !in_array($t, $tokens, true)) $tokens[$site['label'] . ' token'] = $t;
    }

    $report = [];
    foreach ($tokens as $label => $token) {
        $r = famly_ef_list_sites($token);
        $report[] = ['label' => $label, 'ok' => $r['ok'], 'error' => $r['error'], 'sites' => $r['sites']];
        famly_ef_log('Test connection (' . $label . '): ' . ($r['ok'] ? count($r['sites']) . ' site(s)' : $r['error']));
    }
    // Per nursery: can its token see its site, with the enquiries permission?
    $nurseries = [];
    foreach (famly_ef_sites() as $site) {
        $token = famly_ef_token_for_site($site);
        $row   = ['label' => $site['label'], 'mode' => $site['mode'], 'source' => famly_ef_token_source($site), 'site_id' => $site['site_id']];
        if (!$token || !$site['site_id']) {
            $row += ['sees_site' => null, 'enquiries' => false, 'site_title' => '', 'token_sites' => [],
                     'error' => !$token ? 'No token' : 'No Famly site ID'];
        } else {
            $row += famly_ef_check_site_access($token, $site['site_id']);
        }
        $nurseries[] = $row;
        famly_ef_log('Test connection (' . $site['key'] . '): sees site=' . var_export($row['sees_site'], true) . ', enquiries=' . ($row['enquiries'] ? 'yes' : 'no'));
    }
    set_transient('famly_ef_connection_report_' . get_current_user_id(), ['time' => time(), 'report' => $report, 'nurseries' => $nurseries], 10 * MINUTE_IN_SECONDS);

    wp_safe_redirect(admin_url('admin.php?page=famly-enquiry-settings&tested=1'));
    exit;
});

function famly_ef_secret_field($name, $saved, $constant = '') {
    if ($constant && defined($constant) && constant($constant)) {
        echo '<input type="text" class="regular-text" value="•••••••••••• (set in wp-config.php)" disabled>';
        echo '<p class="description">Set by <code>' . esc_html($constant) . '</code> in wp-config.php.</p>';
        return;
    }
    echo '<input type="password" name="' . esc_attr($name) . '" value="" class="regular-text" autocomplete="new-password" placeholder="' . ($saved ? 'Saved — leave blank to keep' : '') . '">';
    if ($saved) {
        echo ' <label><input type="checkbox" name="' . esc_attr(preg_replace('/\]$/', '_clear]', $name)) . '" value="1"> Clear</label>';
    }
}

function famly_ef_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s        = famly_ef_settings();
    $sites    = array_values(famly_ef_sites());
    $gf_keys  = famly_ef_gf_turnstile_keys();
    $report   = !empty($_GET['tested']) ? get_transient('famly_ef_connection_report_' . get_current_user_id()) : false;
    $configured_ids = wp_list_pluck($sites, 'site_id');
    ?>
    <div class="wrap">
        <h1>Famly Enquiry Form Settings</h1>
        <?php settings_errors('famly_ef_settings'); ?>

        <?php if ($report && !empty($report['nurseries'])) : $all_ok = true; ?>
            <?php foreach ($report['nurseries'] as $n) if (!$n['sees_site'] || !$n['enquiries']) $all_ok = false; ?>
            <div class="notice <?php echo $all_ok ? 'notice-success' : 'notice-error'; ?>">
                <p><strong><?php echo $all_ok ? 'Every nursery can create enquiries in Famly.' : 'At least one nursery cannot create enquiries in Famly.'; ?></strong> Checked without reading or creating any Famly records.</p>
                <table class="widefat striped" style="max-width:1000px;margin-bottom:10px;">
                    <thead><tr><th>Nursery</th><th>Mode</th><th>Token used</th><th>Token can see this site</th><th>Enquiries permission</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['nurseries'] as $n) : ?>
                        <tr>
                            <td><?php echo esc_html($n['label']); ?></td>
                            <td><?php echo $n['mode'] === 'form' ? 'API form' : 'Link'; ?></td>
                            <td><?php echo esc_html($n['source']); ?></td>
                            <td><?php
                                if ($n['sees_site']) {
                                    echo '<span style="color:#1e8e3e;">&#10003;</span> ' . esc_html($n['site_title']);
                                } elseif ($n['sees_site'] === false) {
                                    echo '<span style="color:#d63638;">&#10007; No.</span> This token only sees: ' . esc_html(implode(', ', $n['token_sites']) ?: 'nothing');
                                } else {
                                    echo '—';
                                }
                            ?></td>
                            <td><?php echo $n['enquiries'] ? '<span style="color:#1e8e3e;">&#10003; Yes</span>' : '<span style="color:#d63638;">&#10007; ' . esc_html($n['error'] ?: 'No') . '</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$all_ok) : ?><p>A token only covers the Famly site it was created in. To make one for another nursery, switch Famly's site picker to that nursery first, then go to Settings → Integrations → Manage API Tokens, and tick <strong>Enrolment → Manage enquiries</strong>.</p><?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($report) : ?>
            <?php foreach ($report['report'] as $r) : ?>
                <div class="notice <?php echo $r['ok'] ? 'notice-success' : 'notice-error'; ?>">
                    <p><strong><?php echo esc_html($r['label']); ?>:</strong>
                    <?php if ($r['ok']) : ?>
                        connected. Famly sites this token can see:</p>
                        <table class="widefat striped" style="max-width:760px;margin-bottom:10px;"><thead><tr><th>Famly site</th><th>Site ID</th><th>Used by</th></tr></thead><tbody>
                        <?php foreach ($r['sites'] as $id => $title) : $used = array_search($id, $configured_ids, true); ?>
                            <tr><td><?php echo esc_html($title); ?></td><td><code><?php echo esc_html($id); ?></code></td><td><?php echo $used !== false ? esc_html($sites[$used]['label']) : '—'; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$r['sites']) echo '<tr><td colspan="3">No sites returned. The token may lack permission to read sites; you can still paste site IDs below.</td></tr>'; ?>
                        </tbody></table>
                    <?php else : ?>
                        <?php echo esc_html($r['error']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$report['report']) echo '<div class="notice notice-error"><p>No API token is set yet.</p></div>'; ?>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('famly_ef_settings_group'); ?>

            <h2>Famly connection</h2>
            <p>Create an API token in Famly: organisation token (Home → Overview → choose the organisation → Settings → Manage API Tokens) or site token (Settings → Integrations → Manage API Tokens). Give it the <strong>Enrolment → Manage enquiries</strong> permission only. A site token only covers the site it was created in. API access is a paid Famly add-on.</p>
            <table class="form-table" role="presentation">
                <tr><th scope="row">API token</th><td><?php famly_ef_secret_field('famly_ef_settings[api_token]', $s['api_token'], 'FAMLY_API_TOKEN'); ?>
                    <p class="description">Used for every nursery unless that nursery has its own token below. Watch the token's expiry date in Famly: when it expires, enquiries are emailed as "not in Famly" until a new one is entered.</p></td></tr>
                <tr><th scope="row">API endpoint</th><td>
                    <?php if (defined('FAMLY_API_URL') && FAMLY_API_URL) : ?>
                        <code><?php echo esc_html(FAMLY_API_URL); ?></code> <span class="description">(set in wp-config.php)</span>
                    <?php else : ?>
                        <input type="url" name="famly_ef_settings[api_url]" value="<?php echo esc_attr($s['api_url']); ?>" class="regular-text" placeholder="<?php echo esc_attr(FAMLY_EF_DEFAULT_API_URL); ?>">
                        <p class="description">Leave blank for the general environment (app.famly.co). For app.famly.de use https://famlyapi.famly.de/v1/graphql.</p>
                    <?php endif; ?>
                </td></tr>
            </table>

            <h2>Nurseries</h2>
            <p>One row per nursery; the key goes in the shortcode. Leave a key blank to remove a row.
            <strong>Link</strong> mode shows a button that opens the nursery's Famly enquiry form (parents can book a tour there; no API needed).
            <strong>API form</strong> mode shows the on-page form and creates the enquiry through the Famly API (needs a token and site ID).</p>
            <table class="widefat striped" style="max-width:1300px;">
                <thead><tr><th>Key</th><th>Name (emails and dashboard)</th><th>Mode</th><th>Famly form link (link mode)</th><th>Famly site ID (API mode)</th><th>Extra notification email</th><th>Own API token (optional)</th><th>New-enquiry alerts</th><th>Shortcode</th></tr></thead>
                <tbody>
                <?php foreach (array_merge($sites, [famly_ef_blank_site()]) as $i => $site) : $n = 'famly_ef_settings[sites][' . $i . ']'; ?>
                    <tr>
                        <td><input type="text" name="<?php echo esc_attr($n); ?>[key]" value="<?php echo esc_attr($site['key']); ?>" style="width:110px;" placeholder="<?php echo $site['key'] === '' ? 'new-nursery' : ''; ?>"></td>
                        <td><input type="text" name="<?php echo esc_attr($n); ?>[label]" value="<?php echo esc_attr($site['label']); ?>" style="width:100%;"></td>
                        <td><select name="<?php echo esc_attr($n); ?>[mode]"><option value="link"<?php selected($site['mode'], 'link'); ?>>Link</option><option value="form"<?php selected($site['mode'], 'form'); ?>>API form</option></select></td>
                        <td><input type="url" name="<?php echo esc_attr($n); ?>[form_url]" value="<?php echo esc_attr($site['form_url']); ?>" style="width:100%;" placeholder="https://app.famly.co/#/customInquiryForm/…/submit"></td>
                        <td><input type="text" name="<?php echo esc_attr($n); ?>[site_id]" value="<?php echo esc_attr($site['site_id']); ?>" style="width:290px;font-family:monospace;" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></td>
                        <td><input type="email" name="<?php echo esc_attr($n); ?>[notify_email]" value="<?php echo esc_attr($site['notify_email']); ?>" style="width:100%;"></td>
                        <td><?php $site['key'] !== '' ? famly_ef_secret_field($n . '[api_token]', $site['api_token'] ?? '', famly_ef_site_token_constant($site['key'])) : famly_ef_secret_field($n . '[api_token]', ''); ?></td>
                        <td><label><input type="checkbox" name="<?php echo esc_attr($n); ?>[poll]" value="1"<?php checked($site['poll'] ?? '1', '1'); ?>> Check this nursery</label></td>
                        <td><?php if ($site['key'] !== '') echo '<code>[famly_enquiry_form site="' . esc_html($site['key']) . '"]</code>'; ?>
                            <input type="hidden" name="<?php echo esc_attr($n); ?>[extra_questions]" value="<?php echo esc_attr($site['extra_questions']); ?>" class="famly-ef-site-extra" data-row="<?php echo (int) $i; ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Link mode</h2>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Intro text</th><td><textarea name="famly_ef_settings[link_intro]" rows="2" class="large-text"><?php echo esc_textarea($s['link_intro']); ?></textarea></td></tr>
                <tr><th scope="row">Button label</th><td><input type="text" name="famly_ef_settings[link_button]" value="<?php echo esc_attr($s['link_button']); ?>" class="regular-text"></td></tr>
                <tr><th scope="row">Small print under the button</th><td><textarea name="famly_ef_settings[link_note]" rows="2" class="large-text"><?php echo esc_textarea($s['link_note']); ?></textarea></td></tr>
                <tr><th scope="row">Open in a new tab</th><td><label><input type="checkbox" name="famly_ef_settings[link_new_tab]" value="1"<?php checked($s['link_new_tab'], '1'); ?>> Open the Famly form in a new tab</label>
                    <p class="description">Clicks push <code>tour_booking_link_clicked</code> (with <code>nursery</code>) to the GTM dataLayer. The enquiry itself is completed on Famly, so this site can't see whether the parent finished it.</p></td></tr>
            </table>

            <h2>Extra questions (API form mode)</h2>
            <p>Asked on the on-page form after the start date; the answers go into the Famly note and the emails (the Famly API has no custom fields). One per line:
            <code>Question | select | Option A; Option B</code>, <code>Question | checkbox | Option A; Option B</code> or <code>Question | text</code>. Add <code>*</code> to the type to make it required, e.g. <code>checkbox*</code>.</p>
            <textarea name="famly_ef_settings[extra_questions]" rows="5" class="large-text code" style="max-width:1000px;"><?php echo esc_textarea($s['extra_questions']); ?></textarea>
            <?php foreach ($sites as $i => $site) : ?>
                <p style="margin-bottom:2px;"><strong><?php echo esc_html($site['label']); ?></strong>: own list (leave blank to use the list above)</p>
                <textarea rows="3" class="large-text code famly-ef-site-extra-edit" data-row="<?php echo (int) $i; ?>" style="max-width:1000px;"><?php echo esc_textarea($site['extra_questions']); ?></textarea>
            <?php endforeach; ?>
            <script>
            // Per-nursery textareas live outside the table; copy them into the row's hidden field on save.
            document.addEventListener('submit', function (e) {
                document.querySelectorAll('.famly-ef-site-extra-edit').forEach(function (ta) {
                    var hidden = document.querySelector('.famly-ef-site-extra[data-row="' + ta.dataset.row + '"]');
                    if (hidden) hidden.value = ta.value;
                });
            }, true);
            </script>

            <h2>Notifications</h2>
            <p><strong>Enquiry details aren't kept in WordPress.</strong> An on-page enquiry is saved only while it's being sent to Famly and emailed; its personal details are then removed (a 5-minute clean-up catches anything left), keeping just the date, nursery and outcome. The new-enquiry alerts store only scrambled (hashed) Famly enquiry IDs, so they know what they've already emailed.</p>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Notification email</th><td>
                    <?php if (defined('FAMLY_NOTIFY_EMAIL') && FAMLY_NOTIFY_EMAIL) : ?>
                        <code><?php echo esc_html(FAMLY_NOTIFY_EMAIL); ?></code> <span class="description">(set in wp-config.php)</span>
                    <?php else : ?>
                        <input type="email" name="famly_ef_settings[notify_email]" value="<?php echo esc_attr($s['notify_email']); ?>" class="regular-text">
                    <?php endif; ?>
                    <p class="description">Gets every enquiry email, along with each nursery's extra address.</p></td></tr>
                <tr><th scope="row">Email every enquiry</th><td><label><input type="checkbox" name="famly_ef_settings[notify_every]" value="1"<?php checked($s['notify_every'], '1'); ?>> Send a "New Tour Enquiry" email even when the enquiry reached Famly</label>
                    <p class="description">"ACTION NEEDED" emails for enquiries that did not reach Famly are always sent.</p></td></tr>
                <tr><th scope="row">Also alert</th><td><input type="text" name="famly_ef_settings[alert_emails]" value="<?php echo esc_attr($s['alert_emails']); ?>" class="regular-text" placeholder="someone@example.com, other@example.com">
                    <p class="description">Extra addresses (comma-separated) for "ACTION NEEDED" emails only.</p></td></tr>
                <tr><th scope="row">Contact email on errors</th><td><input type="email" name="famly_ef_settings[contact_email]" value="<?php echo esc_attr($s['contact_email']); ?>" class="regular-text">
                    <p class="description">Shown to parents if the form shows an error.</p></td></tr>
            </table>

            <h2>New-enquiry alerts from Famly</h2>
            <p>Famly doesn't email you when a family enquires. With this on, the website checks each nursery's Famly enquiries every 10 minutes and emails anything new to the notification email above (plus the nursery's extra address). The first check only records what's already there. Enquiries made with this site's own form are emailed when they're submitted, so they aren't emailed twice.</p>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Email new Famly enquiries</th><td><label><input type="checkbox" name="famly_ef_settings[poll_enabled]" value="1"<?php checked($s['poll_enabled'], '1'); ?>> Check Famly every 10 minutes and email new enquiries</label>
                    <p class="description">Needs each nursery's token to have Enrolment → Manage enquiries. The email gives the enquirer's name, email and phone, the showaround time and their form answers, but never the child's details. For those details, also give it Documents → Forms → <strong>View forms and responses</strong> (or Children → View child information and View contact details); without them the email says "not available" and links to Famly.</p></td></tr>
                <tr><th scope="row">Which enquiries</th><td>
                    <label style="display:block;"><input type="radio" name="famly_ef_settings[poll_scope]" value="online"<?php checked($s['poll_scope'], 'online'); ?>> Online enquiries only: Famly forms and lead sites (childcare.co.uk, daynurseries.co.uk, Nuuri, Facebook)</label>
                    <label style="display:block;"><input type="radio" name="famly_ef_settings[poll_scope]" value="all"<?php checked($s['poll_scope'], 'all'); ?>> Every new enquiry, including ones staff add by hand or import</label>
                </td></tr>
            </table>

            <h2>"How did you hear about us?" options</h2>
            <p>One option per line: <code>Label shown on the form | FAMLY_SOURCE</code>. The label is always written into the enquiry's note in Famly; the Famly source sets Famly's own Source field (leave it off to set none).</p>
            <textarea name="famly_ef_settings[sources_text]" rows="14" class="large-text code" style="max-width:760px;"><?php echo esc_textarea($s['sources_text']); ?></textarea>
            <p class="description" style="max-width:760px;">Famly sources: <code><?php echo esc_html(implode(', ', FAMLY_EF_SOURCES)); ?></code></p>

            <h2>Spam protection</h2>
            <p>A hidden honeypot field is always on. Cloudflare Turnstile adds a check that most visitors never see.</p>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Cloudflare Turnstile</th><td>
                    <?php if (defined('FAMLY_TURNSTILE_SITE_KEY') && FAMLY_TURNSTILE_SITE_KEY) : ?>
                        <p>Keys set in wp-config.php.</p>
                    <?php endif; ?>
                    <label style="display:block;"><input type="radio" name="famly_ef_settings[turnstile]" value="gf"<?php checked($s['turnstile'], 'gf'); ?>> Use the keys from the Gravity Forms Turnstile add-on <?php echo $gf_keys ? '(found)' : '<strong>(not found)</strong>'; ?></label>
                    <label style="display:block;"><input type="radio" name="famly_ef_settings[turnstile]" value="custom"<?php checked($s['turnstile'], 'custom'); ?>> Use these keys:</label>
                    <p style="margin-left:24px;">Site key <input type="text" name="famly_ef_settings[turnstile_site_key]" value="<?php echo esc_attr($s['turnstile_site_key']); ?>" class="regular-text"><br>
                    Secret <?php famly_ef_secret_field('famly_ef_settings[turnstile_secret]', $s['turnstile_secret']); ?></p>
                    <label style="display:block;"><input type="radio" name="famly_ef_settings[turnstile]" value="off"<?php checked($s['turnstile'], 'off'); ?>> Off</label>
                </td></tr>
            </table>

            <h2>Required fields</h2>
            <p>Fields marked "required by Famly" are always compulsory.</p>
            <?php $req = famly_ef_required_fields(); foreach (FAMLY_EF_FIELDS as $key => $label) : $forced = in_array($key, FAMLY_EF_ALWAYS_REQUIRED, true); ?>
                <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="famly_ef_settings[required_fields][]" value="<?php echo esc_attr($key); ?>"<?php checked(in_array($key, $req, true)); disabled($forced); ?>> <?php echo esc_html($label); ?><?php echo $forced ? ' <em>(required by Famly)</em>' : ''; ?></label>
            <?php endforeach; ?>

            <h2>Advanced</h2>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Reference system name</th><td><input type="text" name="famly_ef_settings[reference_system]" value="<?php echo esc_attr($s['reference_system']); ?>" class="regular-text">
                    <p class="description">Each Famly enquiry is tagged with the website enquiry number (<code>wp-123</code>) under this system name. Leave blank to send no reference.</p></td></tr>
                <tr><th scope="row">Custom CSS</th><td><textarea name="famly_ef_settings[custom_css]" rows="10" class="large-text code"><?php echo esc_textarea($s['custom_css']); ?></textarea>
                    <p class="description">Scoped by you: the form's wrapper is <code>.famly-form-wrapper</code>.</p></td></tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <hr>
        <h2>Test the connection</h2>
        <p>Asks Famly which sites the saved token(s) can see. Nothing is created in Famly.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="famly_ef_test_connection">
            <?php wp_nonce_field('famly_ef_test_connection'); ?>
            <?php submit_button('Test connection', 'secondary', 'submit', false); ?>
        </form>

        <hr>
        <h2>Tracking</h2>
        <p>The form pushes <code>tour_booking_attempted</code>, <code>tour_booking_submitted</code> and <code>tour_booking_error</code> to the GTM <code>dataLayer</code> (with <code>nursery</code>), and fires a <code>famly:enquiry-submitted</code> DOM event on success. UTM parameters from the page URL are saved and added to the Famly note.</p>
    </div>
    <?php
}
