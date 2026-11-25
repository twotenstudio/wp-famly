<?php
/**
 * Famly Enquiries dashboard: list, detail, retry, mark handled, delete, CSV.
 */

if (!defined('ABSPATH')) exit;

const FAMLY_EF_STATUSES = [
    'sent'    => ['In Famly', '#1e8e3e'],
    'failed'  => ['Not in Famly', '#d63638'],
    'pending' => ['Sending', '#996800'],
    'held'    => ['Held (spam check)', '#757575'],
    'manual'  => ['Added by hand', '#2271b1'],
];

/** Enquiries that need someone to act: failed, or stuck "sending" for 15+ minutes. */
function famly_ef_attention_count() {
    global $wpdb;
    $poll_failing = 0;
    if (function_exists('famly_ef_poll_site_state')) {
        foreach (famly_ef_sites() as $site) {
            if (famly_ef_poll_site_state($site['key'])['failures'] >= FAMLY_EF_POLL_FAIL_LIMIT) $poll_failing++;
        }
    }
    if (!famly_ef_table_exists()) return $poll_failing;
    $cutoff = wp_date('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS);
    return $poll_failing + (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . famly_ef_table() . " WHERE status = 'failed' OR (status = 'pending' AND created_at < %s)", $cutoff
    ));
}

add_action('admin_menu', function () {
    $count  = famly_ef_attention_count();
    $bubble = $count ? ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . $count . '</span></span>' : '';
    add_menu_page('Famly Enquiries', 'Famly Enquiries' . $bubble, 'manage_options', 'famly-enquiries', 'famly_ef_dashboard_page', 'dashicons-groups', 27);
    add_submenu_page('famly-enquiries', 'Famly Enquiries', 'Enquiries', 'manage_options', 'famly-enquiries', 'famly_ef_dashboard_page');
    add_submenu_page('famly-enquiries', 'Famly Enquiry Form Settings', 'Settings', 'manage_options', 'famly-enquiry-settings', 'famly_ef_settings_page');
});

// Row actions and CSV export run before any output.
add_action('admin_init', function () {
    if (($_GET['page'] ?? '') !== 'famly-enquiries' || empty($_GET['famly_action'])) return;
    if (!current_user_can('manage_options')) return;

    $action = sanitize_key($_GET['famly_action']);
    if ($action === 'export') {
        check_admin_referer('famly_ef_export');
        famly_ef_export_csv();
        exit;
    }

    $id = (int) ($_GET['id'] ?? 0);
    check_admin_referer('famly_ef_' . $action . '_' . $id);
    $back = remove_query_arg(['famly_action', 'id', '_wpnonce', 'famly_msg', 'famly_err']);

    if ($action === 'retry') {
        $r = famly_ef_send_to_famly($id);
        $back = $r['ok'] ? add_query_arg('famly_msg', rawurlencode('Enquiry #' . $id . ' is now in Famly.'), $back)
                         : add_query_arg('famly_err', rawurlencode('Enquiry #' . $id . ' still could not be sent: ' . $r['error']), $back);
    } elseif ($action === 'handled') {
        famly_ef_update_enquiry($id, ['status' => 'manual', 'status_reason' => 'Marked as added to Famly by hand']);
        $back = add_query_arg('famly_msg', rawurlencode('Enquiry #' . $id . ' marked as added by hand.'), $back);
    } elseif ($action === 'delete') {
        global $wpdb;
        $wpdb->delete(famly_ef_table(), ['id' => $id]);
        $back = add_query_arg('famly_msg', rawurlencode('Enquiry #' . $id . ' deleted.'), $back);
    }
    wp_safe_redirect($back);
    exit;
});

function famly_ef_action_url($action, $id) {
    return wp_nonce_url(add_query_arg(['famly_action' => $action, 'id' => (int) $id]), 'famly_ef_' . $action . '_' . (int) $id);
}

function famly_ef_status_badge($status) {
    [$label, $color] = FAMLY_EF_STATUSES[$status] ?? [$status, '#757575'];
    return '<span style="color:' . esc_attr($color) . ';font-weight:600;">' . esc_html($label) . '</span>';
}

function famly_ef_dashboard_page() {
    global $wpdb;
    if (!current_user_can('manage_options')) return;
    $table = famly_ef_table();
    if (!famly_ef_table_exists()) famly_ef_create_table();

    $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
    $status = sanitize_key($_GET['status'] ?? '');
    $where  = ['1=1'];
    $args   = [];
    if ($status !== '' && isset(FAMLY_EF_STATUSES[$status])) {
        $where[] = 'status = %s';
        $args[]  = $status;
    }
    if ($search !== '') {
        $like    = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(child_first_name LIKE %s OR child_last_name LIKE %s OR parent_first_name LIKE %s OR parent_last_name LIKE %s OR parent_email LIKE %s OR phone LIKE %s)';
        array_push($args, $like, $like, $like, $like, $like, $like);
    }
    $where_sql = implode(' AND ', $where);
    $prepare   = function ($sql) use ($wpdb, $args) { return $args ? $wpdb->prepare($sql, $args) : $sql; };

    $per_page = 25;
    $current  = max(1, (int) ($_GET['paged'] ?? 1));
    $total    = (int) $wpdb->get_var($prepare("SELECT COUNT(*) FROM $table WHERE $where_sql"));
    $pages    = (int) ceil($total / $per_page);
    $offset   = ($current - 1) * $per_page;
    $entries  = $wpdb->get_results($prepare("SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC, id DESC LIMIT $per_page OFFSET $offset"), ARRAY_A);
    $counts   = $wpdb->get_results("SELECT status, COUNT(*) n FROM $table GROUP BY status", OBJECT_K);

    $base = admin_url('admin.php?page=famly-enquiries');
    ?>
    <style>
        .famly-ef-detail td { padding: 0 !important; background: #f9f9f9; }
        .famly-ef-detail-inner { display: none; padding: 12px 20px 16px; }
        .famly-ef-detail-inner.visible { display: block; }
        .famly-ef-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px 24px; margin: 0; }
        .famly-ef-grid dt { font-weight: 600; font-size: 11px; text-transform: uppercase; color: #888; }
        .famly-ef-grid dd { margin: 2px 0 10px; font-size: 13px; color: #333; white-space: pre-line; }
    </style>
    <div class="wrap">
        <h1 class="wp-heading-inline">Famly Enquiries</h1>
        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('famly_action', 'export', $base), 'famly_ef_export')); ?>" class="page-title-action">Export CSV</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=famly-enquiry-settings')); ?>" class="page-title-action">Settings</a>
        <hr class="wp-header-end">

        <?php if (!empty($_GET['famly_msg'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['famly_msg'])); ?></p></div><?php endif; ?>
        <?php if (!empty($_GET['famly_err'])) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['famly_err'])); ?></p></div><?php endif; ?>

        <?php famly_ef_render_poll_status(); ?>

        <h2>Enquiries submitted on this website</h2>
        <p>Enquiries from on-page forms (API form mode). Enquiries made on Famly's own form (link mode) are only in Famly; the alerts above email those.</p>

        <ul class="subsubsub">
            <li><a href="<?php echo esc_url($base); ?>"<?php echo $status === '' ? ' class="current"' : ''; ?>>All</a> |</li>
            <?php $i = 0; foreach (FAMLY_EF_STATUSES as $key => $info) : $i++; ?>
                <li><a href="<?php echo esc_url(add_query_arg('status', $key, $base)); ?>"<?php echo $status === $key ? ' class="current"' : ''; ?>><?php echo esc_html($info[0]); ?> <span class="count">(<?php echo (int) ($counts[$key]->n ?? 0); ?>)</span></a><?php echo $i < count(FAMLY_EF_STATUSES) ? ' |' : ''; ?></li>
            <?php endforeach; ?>
        </ul>

        <form method="get" style="float:right;margin:8px 0;">
            <input type="hidden" name="page" value="famly-enquiries">
            <?php if ($status) : ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>"><?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search enquiries...">
            <input type="submit" class="button" value="Search">
        </form>

        <table class="wp-list-table widefat fixed striped" style="clear:both;">
            <thead><tr>
                <th style="width:45px;">#</th><th>Nursery</th><th>Child</th><th>Parent</th><th>Email</th><th>Phone</th><th>Start</th><th>Famly</th><th>Submitted</th><th style="width:190px;"></th>
            </tr></thead>
            <tbody>
            <?php if (!$entries) : ?>
                <tr><td colspan="10">No enquiries found.</td></tr>
            <?php else : foreach ($entries as $e) : $id = (int) $e['id']; $date = function ($v, $f = 'd/m/Y') { return $v ? wp_date($f, strtotime($v)) : '—'; }; ?>
                <tr>
                    <td><?php echo $id; ?></td>
                    <td><?php echo esc_html($e['site_label']); ?></td>
                    <td><strong><?php echo esc_html(trim($e['child_first_name'] . ' ' . $e['child_last_name'])); ?></strong><br><small>DOB: <?php echo esc_html($date($e['child_dob'])); ?></small></td>
                    <td><?php echo esc_html(trim($e['parent_first_name'] . ' ' . $e['parent_last_name'])); ?></td>
                    <td><a href="mailto:<?php echo esc_attr($e['parent_email']); ?>"><?php echo esc_html($e['parent_email']); ?></a></td>
                    <td><?php echo esc_html($e['phone']); ?></td>
                    <td><?php echo esc_html($date($e['start_date'])); ?></td>
                    <td><?php echo famly_ef_status_badge($e['status']); ?><?php if ($e['status_reason'] !== '' && $e['status'] !== 'sent') echo '<br><small>' . esc_html(mb_strimwidth($e['status_reason'], 0, 90, '…')) . '</small>'; ?></td>
                    <td><?php echo esc_html($date($e['created_at'], 'd/m/Y H:i')); ?></td>
                    <td>
                        <a href="#" onclick="var d=document.getElementById('famly-ef-d-<?php echo $id; ?>');d.classList.toggle('visible');this.textContent=d.classList.contains('visible')?'Hide':'View';return false;">View</a>
                        <?php if (in_array($e['status'], ['failed', 'held', 'pending'], true)) : ?>
                            | <a href="<?php echo esc_url(famly_ef_action_url('retry', $id)); ?>" onclick="return confirm('Send enquiry #<?php echo $id; ?> to Famly now?');">Send to Famly</a>
                            | <a href="<?php echo esc_url(famly_ef_action_url('handled', $id)); ?>">Added by hand</a>
                        <?php endif; ?>
                        | <a href="<?php echo esc_url(famly_ef_action_url('delete', $id)); ?>" onclick="return confirm('Delete enquiry #<?php echo $id; ?>? This cannot be undone.');" style="color:#a00;">Delete</a>
                    </td>
                </tr>
                <tr class="famly-ef-detail"><td colspan="10"><div id="famly-ef-d-<?php echo $id; ?>" class="famly-ef-detail-inner"><dl class="famly-ef-grid">
                    <?php
                    $details = [
                        'Gender'          => $e['child_gender'] === 'Other' ? 'Unknown / Other' : $e['child_gender'],
                        'Postcode'        => $e['postcode'],
                        'Source'          => $e['source_label'],
                        'Consent'         => $e['consent'] ? 'Yes' : 'No',
                        'UTM'             => implode(' / ', array_filter([$e['utm_source'], $e['utm_medium'], $e['utm_campaign'], $e['utm_content'], $e['utm_term']])),
                        'Page'            => $e['page_url'],
                        'Famly inquiry ID'=> $e['famly_inquiry_id'],
                        'Famly site ID'   => $e['famly_site_id'],
                        'Attempts'        => $e['attempts'] . ($e['last_attempt_at'] ? ' (last ' . $date($e['last_attempt_at'], 'd/m/Y H:i') . ')' : ''),
                        'Last error'      => $e['status'] === 'sent' ? '' : (string) $e['famly_error'],
                    ] + famly_ef_extra_answers($e);
                    foreach ($details as $label => $value) {
                        if ($value === '' || $value === null) continue;
                        echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
                    }
                    ?>
                </dl></div></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($pages > 1) : ?>
            <div class="tablenav bottom"><div class="tablenav-pages"><?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $current, 'total' => $pages]); ?></div></div>
        <?php endif; ?>
    </div>
    <?php
}

/** Status of the new-enquiry alerts (last check, last new enquiry, last error). */
function famly_ef_render_poll_status() {
    $enabled = famly_ef_get_setting('poll_enabled', '1') === '1';
    $next    = wp_next_scheduled(FAMLY_EF_POLL_HOOK);
    $ago     = function ($ts) { return $ts ? sprintf('%s ago (%s)', human_time_diff((int) $ts), wp_date('d/m H:i', (int) $ts)) : 'never'; };
    ?>
    <div class="postbox" style="padding:12px 16px;margin-top:16px;max-width:1100px;">
        <h2 style="margin:0 0 8px;padding:0;">New-enquiry alerts from Famly</h2>
        <?php if (!$enabled) : ?>
            <p>Off. Turn on "Email new Famly enquiries" in <a href="<?php echo esc_url(admin_url('admin.php?page=famly-enquiry-settings')); ?>">Settings</a>.</p>
        <?php else : ?>
            <p style="margin-top:0;">The website checks Famly every 10 minutes and emails new enquiries to <?php echo esc_html(famly_ef_notify_email()); ?> (plus each nursery's extra address).
            Next scheduled check: <?php echo $next ? esc_html(human_time_diff($next) . ($next < time() ? ' overdue' : '')) : 'not scheduled'; ?>.</p>
            <table class="widefat striped">
                <thead><tr><th>Nursery</th><th>Last checked</th><th>Last new enquiry emailed</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach (famly_ef_sites() as $site) : $st = famly_ef_poll_site_state($site['key']); ?>
                    <tr>
                        <td><?php echo esc_html($site['label']); ?></td>
                        <td><?php echo esc_html($ago($st['last_checked'])); ?></td>
                        <td><?php echo esc_html($st['last_new'] ? $ago($st['last_new']) . ' — ' . (int) $st['last_new_count'] . ' new' : 'none yet'); ?></td>
                        <td><?php
                            if ($st['failures'] > 0) {
                                echo '<span style="color:#d63638;font-weight:600;">&#10007; ' . (int) $st['failures'] . ' failed check(s) in a row</span><br><small>' . esc_html($st['last_error']) . '</small>';
                            } elseif ($st['seeded']) {
                                echo '<span style="color:#1e8e3e;font-weight:600;">&#10003; Working</span>';
                                if ($st['last_error']) echo '<br><small>Last error ' . esc_html($ago($st['last_error_at'])) . ': ' . esc_html($st['last_error']) . '</small>';
                            } else {
                                echo 'Waiting for first check';
                            }
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <input type="hidden" name="action" value="famly_ef_poll_now">
                <?php wp_nonce_field('famly_ef_poll_now'); ?>
                <?php submit_button('Check Famly now', 'secondary', 'submit', false); ?>
                <span class="description" style="margin-left:8px;">Emails anything new, the same as the scheduled check.</span>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function famly_ef_export_csv() {
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . famly_ef_table() . ' ORDER BY created_at DESC, id DESC', ARRAY_A);

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=famly-enquiries-' . wp_date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Submitted', 'Nursery', 'Child First Name', 'Child Last Name', 'Child DOB', 'Gender',
        'Parent First Name', 'Parent Last Name', 'Email', 'Phone', 'Postcode', 'Preferred Start', 'Source', 'Extra answers',
        'Consent', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Content', 'UTM Term', 'Page',
        'Famly Status', 'Famly Inquiry ID', 'Famly Error']);
    foreach ($rows as $r) {
        $extra = [];
        foreach (famly_ef_extra_answers($r) as $q => $a) $extra[] = $q . ': ' . $a;
        $line = [$r['id'], $r['created_at'], $r['site_label'], $r['child_first_name'], $r['child_last_name'], $r['child_dob'], $r['child_gender'],
            $r['parent_first_name'], $r['parent_last_name'], $r['parent_email'], $r['phone'], $r['postcode'], $r['start_date'], $r['source_label'], implode(' | ', $extra),
            $r['consent'] ? 'Yes' : 'No', $r['utm_source'], $r['utm_medium'], $r['utm_campaign'], $r['utm_content'], $r['utm_term'], $r['page_url'],
            FAMLY_EF_STATUSES[$r['status']][0] ?? $r['status'], $r['famly_inquiry_id'], $r['famly_error']];
        // Stop spreadsheet apps treating a value as a formula.
        $line = array_map(function ($v) { $v = (string) $v; return preg_match('/^(?:[=@\t\r]|[+\-](?!\d))/', $v) ? "'" . $v : $v; }, $line);
        fputcsv($out, $line);
    }
    fclose($out);
}
