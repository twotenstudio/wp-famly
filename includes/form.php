<?php
/**
 * [famly_enquiry_form site="deptford"] shortcode.
 *
 * Markup, field IDs and behaviour mirror the EYWorks Enquiry Form 3.0.0 so
 * the design carries over (eyworks-* classes are famly-* here).
 */

if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function () {
    wp_register_style('famly-form-css', FAMLY_EF_URL . 'assets/famly-form.css', [], FAMLY_EF_VERSION);
    wp_register_script('famly-form-js', FAMLY_EF_URL . 'assets/famly-form.js', [], FAMLY_EF_VERSION, true);
    wp_register_script('cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, ['in_footer' => true, 'strategy' => 'defer']);
});

add_shortcode('famly_enquiry_form', 'famly_ef_render_form');

function famly_ef_render_form($atts) {
    $atts = shortcode_atts(['site' => '', 'nursery' => ''], $atts, 'famly_enquiry_form');
    $requested = $atts['site'] !== '' ? $atts['site'] : $atts['nursery'];
    $is_admin  = current_user_can('manage_options');

    $site = famly_ef_resolve_site($requested);
    if (!$site) {
        return $is_admin ? famly_ef_admin_notice('No nursery matches site="' . $requested . '". Check the Nurseries table on the <a href="' . esc_url(admin_url('admin.php?page=famly-enquiry-settings')) . '">Famly settings page</a>.', true) : '';
    }

    if (($site['mode'] ?? 'link') === 'link') {
        return famly_ef_render_link($site, $is_admin);
    }

    // The form always renders: if Famly isn't reachable or set up, enquiries
    // are still saved and emailed, so the page never loses a lead.
    $notice = '';
    if ($is_admin) {
        $problems = [];
        if (!famly_ef_token_for_site($site)) $problems[] = 'no Famly API token';
        if (empty($site['site_id']))         $problems[] = 'no Famly site ID for ' . $site['label'];
        if ($problems) {
            $notice = famly_ef_admin_notice('Not connected to Famly (' . esc_html(implode(', ', $problems)) . '). Enquiries from this form are saved in WordPress and emailed as "not in Famly" until this is fixed.', false);
        }
    }

    $turnstile = famly_ef_turnstile_keys();

    wp_enqueue_style('famly-form-css');
    $custom_css = famly_ef_get_setting('custom_css');
    if ($custom_css !== '') wp_add_inline_style('famly-form-css', $custom_css);

    wp_enqueue_script('famly-form-js');
    wp_localize_script('famly-form-js', 'famlyForm', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('famly_enquiry_nonce'),
        'requiredMap'  => famly_ef_required_fields_map() ?: new stdClass(),
        'contactEmail' => famly_ef_contact_email(),
        'turnstile'    => (bool) $turnstile,
        'mode'         => 'form',
    ]);
    if ($turnstile) wp_enqueue_script('cf-turnstile');

    $required = famly_ef_required_fields();
    $req      = function ($key) use ($required) { return in_array($key, $required, true); };
    $star     = function ($key) use ($req) { return $req($key) ? ' <span class="famly-req">*</span>' : ''; };
    $aria     = function ($key) use ($req) { return $req($key) ? ' aria-required="true"' : ''; };

    $source_options = '<option value="">Select...</option>';
    foreach (array_keys(famly_ef_sources()) as $label) {
        $source_options .= '<option value="' . esc_attr($label) . '">' . esc_html($label) . '</option>';
    }

    $child_name_req  = $req('child_first_name') || $req('child_last_name');
    $parent_name_req = $req('parent_first_name') || $req('parent_last_name');
    $asterisk        = '<span class="gfield_required"><span class="gfield_required gfield_required_asterisk">*</span></span>';

    ob_start();
    ?>
    <div class="famly-form-wrapper" id="famly-form-wrapper">

        <?php echo $notice; // Admin-only, built from escaped values ?>

        <div class="famly-success" id="famly-success" style="display:none;">
            <div class="famly-success-icon">&#10003;</div>
            <h3>Thank you for your enquiry!</h3>
            <p>We've received your details and will be in touch shortly to arrange your tour.</p>
        </div>

        <div class="famly-error" id="famly-error" style="display:none;" role="alert"></div>

        <div id="famly-form-container">
            <input type="hidden" id="fe-site" value="<?php echo esc_attr($site['key']); ?>">
            <input type="hidden" id="fe-site-label" value="<?php echo esc_attr($site['label']); ?>">

            <div class="famly-hp" aria-hidden="true" style="display:none !important;">
                <label for="fe-extra">Leave this field empty</label>
                <input type="text" id="fe-extra" name="fe_extra" tabindex="-1" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" value="">
            </div>

            <h3 class="famly-section-title">Child Details</h3>

            <fieldset class="gfield gfield--type-name gfield--input-type-name gfield--width-full <?php echo $child_name_req ? 'gfield_contains_required' : ''; ?> field_sublabel_below gfield--no-description field_description_below gfield_visibility_visible">
                <legend class="gfield_label gform-field-label gfield_label_before_complex">Child Name<?php if ($child_name_req) echo $asterisk; ?></legend>
                <div class="ginput_complex ginput_container ginput_container--name no_prefix has_first_name no_middle_name has_last_name no_suffix gf_name_has_2 ginput_container_name gform-grid-row">
                    <span class="name_first gform-grid-col gform-grid-col--size-auto">
                        <input type="text" id="fe-child-first-name" maxlength="45"<?php echo $aria('child_first_name'); ?>>
                        <label for="fe-child-first-name" class="gform-field-label gform-field-label--type-sub">First</label>
                    </span>
                    <span class="name_last gform-grid-col gform-grid-col--size-auto">
                        <input type="text" id="fe-child-last-name" maxlength="60"<?php echo $aria('child_last_name'); ?>>
                        <label for="fe-child-last-name" class="gform-field-label gform-field-label--type-sub">Last</label>
                    </span>
                </div>
            </fieldset>

            <div class="famly-row">
                <div class="famly-field famly-half">
                    <label for="fe-child-dob">Child Date of Birth / Expected DOB<?php echo $star('child_dob'); ?></label>
                    <input type="date" id="fe-child-dob"<?php echo $aria('child_dob'); ?>>
                </div>
                <div class="famly-field famly-half">
                    <label for="fe-child-gender">Legal Gender<?php echo $star('child_gender'); ?></label>
                    <select id="fe-child-gender"<?php echo $aria('child_gender'); ?>>
                        <option value="">Select<?php echo $req('child_gender') ? '...' : ' (optional)...'; ?></option>
                        <option value="Female">Female</option>
                        <option value="Male">Male</option>
                        <option value="Other">Unknown / Other</option>
                    </select>
                </div>
            </div>

            <h3 class="famly-section-title">Parent / Guardian Details</h3>

            <fieldset class="gfield gfield--type-name gfield--input-type-name gfield--width-full <?php echo $parent_name_req ? 'gfield_contains_required' : ''; ?> field_sublabel_below gfield--no-description field_description_below gfield_visibility_visible">
                <legend class="gfield_label gform-field-label gfield_label_before_complex">Name<?php if ($parent_name_req) echo $asterisk; ?></legend>
                <div class="ginput_complex ginput_container ginput_container--name no_prefix has_first_name no_middle_name has_last_name no_suffix gf_name_has_2 ginput_container_name gform-grid-row">
                    <span class="name_first gform-grid-col gform-grid-col--size-auto">
                        <input type="text" id="fe-parent-first-name" maxlength="45"<?php echo $aria('parent_first_name'); ?>>
                        <label for="fe-parent-first-name" class="gform-field-label gform-field-label--type-sub">First</label>
                    </span>
                    <span class="name_last gform-grid-col gform-grid-col--size-auto">
                        <input type="text" id="fe-parent-last-name" maxlength="60"<?php echo $aria('parent_last_name'); ?>>
                        <label for="fe-parent-last-name" class="gform-field-label gform-field-label--type-sub">Last</label>
                    </span>
                </div>
            </fieldset>

            <div class="famly-row">
                <div class="famly-field famly-half">
                    <label for="fe-parent-email">Email<?php echo $star('email'); ?></label>
                    <input type="email" id="fe-parent-email" maxlength="100"<?php echo $req('email') ? ' required aria-required="true"' : ''; ?>>
                </div>
                <div class="famly-field famly-half">
                    <label for="fe-phone">Phone<?php echo $star('phone'); ?></label>
                    <input type="tel" id="fe-phone" maxlength="45"<?php echo $req('phone') ? ' required aria-required="true"' : ''; ?>>
                </div>
            </div>

            <div class="famly-row">
                <div class="famly-field famly-half">
                    <label for="fe-postcode">Postal Code<?php echo $star('postcode'); ?></label>
                    <input type="text" id="fe-postcode" maxlength="10"<?php echo $aria('postcode'); ?>>
                </div>
            </div>

            <h3 class="famly-section-title">Preferences</h3>

            <div class="famly-row">
                <div class="famly-field famly-half">
                    <label for="fe-start-date">Preferred Start Date<?php echo $star('start_date'); ?></label>
                    <input type="date" id="fe-start-date" min="<?php echo esc_attr(wp_date('Y-m-d')); ?>"<?php echo $aria('start_date'); ?>>
                </div>
                <div class="famly-field famly-half">
                    <label for="fe-source">How did you hear about us?<?php echo $star('source'); ?></label>
                    <select id="fe-source"<?php echo $aria('source'); ?>>
                        <?php echo $source_options; // Escaped above ?>
                    </select>
                </div>
            </div>

            <?php echo famly_ef_render_extra_questions(famly_ef_extra_questions($site)); // Escaped inside ?>

            <div class="famly-row">
                <div class="famly-field famly-full">
                    <div class="famly-checkbox-wrap">
                        <input type="checkbox" id="fe-agree-terms" value="1" class="famly-checkbox"<?php echo $req('agree_terms') ? ' required aria-required="true"' : ''; ?>>
                        <label for="fe-agree-terms" class="famly-consent-label">You agree to receive information from us via phone or email.<?php echo $star('agree_terms'); ?></label>
                    </div>
                </div>
            </div>

            <?php if ($turnstile) : ?>
            <div class="famly-row">
                <div class="famly-field famly-full famly-turnstile-wrap">
                    <div class="cf-turnstile" id="fe-turnstile" data-sitekey="<?php echo esc_attr($turnstile['site_key']); ?>" data-action="famly_enquiry" data-appearance="interaction-only" data-theme="light"></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="famly-row">
                <div class="famly-field famly-full">
                    <button type="button" id="famly-submit-btn" class="famly-submit">
                        <span class="btn-text">Submit Enquiry</span>
                        <span class="btn-loading" style="display:none;">Submitting...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Extra questions (funding, days, etc.) for the on-page form. */
function famly_ef_render_extra_questions(array $questions) {
    $html = '';
    foreach ($questions as $q) {
        $id   = 'fe-x-' . $q['key'];
        $star = $q['required'] ? ' <span class="famly-req">*</span>' : '';
        $attr = ' data-key="' . esc_attr($q['key']) . '" data-type="' . esc_attr($q['type']) . '" data-required="' . ($q['required'] ? '1' : '0') . '"';

        if ($q['type'] === 'checkbox') {
            $html .= '<div class="famly-row"><fieldset class="famly-field famly-full famly-extra famly-extra-checkboxes" id="' . esc_attr($id) . '"' . $attr . '>';
            $html .= '<legend>' . esc_html($q['label']) . $star . '</legend><div class="famly-checkbox-grid">';
            foreach ($q['options'] as $i => $opt) {
                $oid   = $id . '-' . $i;
                $html .= '<div class="famly-checkbox-wrap famly-checkbox-option"><input type="checkbox" class="famly-checkbox" id="' . esc_attr($oid) . '" value="' . esc_attr($opt) . '">'
                       . '<label for="' . esc_attr($oid) . '" class="famly-consent-label">' . esc_html($opt) . '</label></div>';
            }
            $html .= '</div></fieldset></div>';
        } elseif ($q['type'] === 'select') {
            $html .= '<div class="famly-row"><div class="famly-field famly-full"><label for="' . esc_attr($id) . '">' . esc_html($q['label']) . $star . '</label>';
            $html .= '<select id="' . esc_attr($id) . '" class="famly-extra"' . $attr . ($q['required'] ? ' aria-required="true"' : '') . '><option value="">Select...</option>';
            foreach ($q['options'] as $opt) $html .= '<option value="' . esc_attr($opt) . '">' . esc_html($opt) . '</option>';
            $html .= '</select></div></div>';
        } else {
            $html .= '<div class="famly-row"><div class="famly-field famly-full"><label for="' . esc_attr($id) . '">' . esc_html($q['label']) . $star . '</label>';
            $html .= '<input type="text" id="' . esc_attr($id) . '" class="famly-extra" maxlength="200"' . $attr . ($q['required'] ? ' aria-required="true"' : '') . '></div></div>';
        }
    }
    return $html;
}

/** Link mode: a button that opens the nursery's own Famly enquiry form. */
function famly_ef_render_link(array $site, $is_admin) {
    $url = $site['form_url'] ?? '';
    if ($url === '') {
        return $is_admin ? famly_ef_admin_notice('No Famly form link is set for ' . esc_html($site['label']) . '.', true) : '';
    }

    wp_enqueue_style('famly-form-css');
    $custom_css = famly_ef_get_setting('custom_css');
    if ($custom_css !== '') wp_add_inline_style('famly-form-css', $custom_css);
    wp_enqueue_script('famly-form-js');
    wp_localize_script('famly-form-js', 'famlyForm', ['mode' => 'link']);

    $new_tab = famly_ef_get_setting('link_new_tab', '0') === '1';
    $intro   = famly_ef_get_setting('link_intro');
    $note    = famly_ef_get_setting('link_note');

    ob_start();
    ?>
    <div class="famly-form-wrapper famly-link-mode" id="famly-form-wrapper">
        <?php if ($intro !== '') : ?><p class="famly-link-intro"><?php echo esc_html($intro); ?></p><?php endif; ?>
        <a class="famly-submit famly-link-button" id="famly-link-button" href="<?php echo esc_url($url); ?>" data-nursery="<?php echo esc_attr($site['label']); ?>"<?php echo $new_tab ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html(famly_ef_get_setting('link_button', 'Book your tour')); ?></a>
        <?php if ($note !== '') : ?><p class="famly-link-note"><?php echo esc_html($note); ?></p><?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/** Notice shown above the form to logged-in administrators only. */
function famly_ef_admin_notice($html, $is_error) {
    $style = $is_error
        ? 'border:1px solid #fecaca;background:#fef2f2;color:#991b1b;'
        : 'border:1px solid #fde68a;background:#fffbeb;color:#92400e;';
    return '<div class="famly-admin-notice" style="padding:1rem;border-radius:6px;margin-bottom:1rem;' . $style . '"><p><strong>Famly Enquiry Form:</strong> ' . $html . ' Only administrators see this notice.</p></div>';
}
