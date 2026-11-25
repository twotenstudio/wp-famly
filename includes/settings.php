<?php
/**
 * Settings helpers. wp-config.php constants take priority over values saved
 * on the Settings screen.
 *
 *   FAMLY_API_TOKEN            Token used for every nursery (organisation token)
 *   FAMLY_API_TOKEN_<KEY>      Token for one nursery, e.g. FAMLY_API_TOKEN_SURREY_QUAYS
 *   FAMLY_API_URL              GraphQL endpoint (defaults to the general Famly environment)
 *   FAMLY_NOTIFY_EMAIL         Where enquiry notifications go
 *   FAMLY_TURNSTILE_SITE_KEY / FAMLY_TURNSTILE_SECRET
 */

if (!defined('ABSPATH')) exit;

const FAMLY_EF_DEFAULT_API_URL = 'https://famlyapi.famly.co/v1/graphql';

// Values of Famly's InquirySource enum (docs.famly.co, November 2026).
const FAMLY_EF_SOURCES = [
    'OTHER', 'NUURI_SITE', 'CHILDCARE_SITE', 'FAMILY_INFORMATION_SERVICES', 'BANNER',
    'LEAFLETS_BROCHURES', 'LOCAL_AUTHORITY_SIGNPOSTING', 'DAYNURSERIES_SITE', 'API_INTEGRATION',
    'SOCIAL_MEDIA', 'CURRENT_CHILD_ATTENDING', 'KITA_PLANER', 'CRM', 'WORD_OF_MOUTH',
    'STAFF_RECOMMENDATION', 'HUBSPOT', 'WatchMeGrow', 'PARENT_RECOMMENDATION', 'DAY_NURSERIES',
    'FOREST_SCHOOL_ASSOCIATION', 'OPEN_DAY_EVENT', 'LOCAL_PRESS', 'SEARCH_ENGINE', 'KinderConnect',
    'PRINT_ADVERT', 'OTHER_NURSERY', 'WEBSITE', 'SIBLING', 'FLOURISH_CRM',
];

// Form fields that can be made compulsory, with their labels.
const FAMLY_EF_FIELDS = [
    'child_first_name'  => 'Child First Name',
    'child_last_name'   => 'Child Last Name',
    'child_dob'         => 'Child Date of Birth',
    'child_gender'      => 'Child Gender',
    'parent_first_name' => 'Parent/Guardian First Name',
    'parent_last_name'  => 'Parent/Guardian Last Name',
    'email'             => 'Email',
    'phone'             => 'Phone',
    'postcode'          => 'Postal Code',
    'start_date'        => 'Preferred Start Date',
    'source'            => 'How did you hear about us?',
    'agree_terms'       => 'Consent Checkbox',
];

// Famly's inquiries.create needs these, so they are always compulsory.
const FAMLY_EF_ALWAYS_REQUIRED = ['child_first_name', 'child_dob', 'parent_first_name', 'email', 'start_date'];

function famly_ef_default_sources_text() {
    return implode("\n", [
        'Search engine (Google, Bing, etc.) | SEARCH_ENGINE',
        'Social media | SOCIAL_MEDIA',
        'Friend or family | WORD_OF_MOUTH',
        'Existing parent | CURRENT_CHILD_ATTENDING',
        'Staff recommendation | STAFF_RECOMMENDATION',
        'Leaflet or brochure | LEAFLETS_BROCHURES',
        'Local press | LOCAL_PRESS',
        'Open day or event | OPEN_DAY_EVENT',
        'Our website | WEBSITE',
        'Other | OTHER',
    ]);
}

/** A blank nursery row. mode: "link" (button to the Famly form) or "form" (on-page form sent through the API). */
function famly_ef_blank_site() {
    return ['key' => '', 'label' => '', 'mode' => 'link', 'form_url' => '', 'site_id' => '', 'notify_email' => '', 'api_token' => '', 'extra_questions' => ''];
}

function famly_ef_default_settings() {
    return [
        'api_token'          => '',
        'api_url'            => '',
        'sites'              => [],
        'notify_email'       => get_option('admin_email'),
        'notify_every'       => '1',
        'poll_enabled'       => '1',
        'poll_scope'         => 'online',
        'alert_emails'       => '',
        'contact_email'      => get_option('admin_email'),
        'sources_text'       => famly_ef_default_sources_text(),
        'extra_questions'    => '',
        'required_fields'    => ['child_first_name', 'child_last_name', 'parent_first_name', 'email', 'phone', 'child_dob', 'start_date', 'agree_terms'],
        'turnstile'          => 'gf',
        'turnstile_site_key' => '',
        'turnstile_secret'   => '',
        'reference_system'   => 'WP_WEBSITE',
        'link_intro'         => 'Choose a time for your visit and tell us a little about your family. It only takes a couple of minutes.',
        'link_button'        => 'Book your tour',
        'link_note'          => 'You\'ll be taken to our secure booking form on Famly, our nursery management system.',
        'link_new_tab'       => '1',
        'custom_css'         => '',
    ];
}

/**
 * First-run settings: defaults, plus the notification address, required
 * fields and custom CSS from the EYWorks plugin (class names renamed).
 */
function famly_ef_initial_settings() {
    $settings = famly_ef_default_settings();
    $eyworks  = get_option('eyworks_settings', []);
    if (is_array($eyworks)) {
        if (!empty($eyworks['notify_email'])) {
            $settings['notify_email'] = $eyworks['notify_email'];
        }
        if (!empty($eyworks['required_fields']) && is_array($eyworks['required_fields'])) {
            $settings['required_fields'] = array_values(array_intersect($eyworks['required_fields'], array_keys(FAMLY_EF_FIELDS)));
        }
        if (!empty($eyworks['custom_css'])) {
            $settings['custom_css'] = str_replace('eyworks-', 'famly-', $eyworks['custom_css']);
        }
    }
    if (!famly_ef_gf_turnstile_keys()) {
        $settings['turnstile'] = 'off';
    }
    return $settings;
}

function famly_ef_settings() {
    $saved = get_option('famly_ef_settings', []);
    return array_merge(famly_ef_default_settings(), is_array($saved) ? $saved : []);
}

function famly_ef_get_setting($key, $default = '') {
    $settings = famly_ef_settings();
    return (isset($settings[$key]) && $settings[$key] !== '') ? $settings[$key] : $default;
}

function famly_ef_api_url() {
    if (defined('FAMLY_API_URL') && FAMLY_API_URL) return FAMLY_API_URL;
    return famly_ef_get_setting('api_url', FAMLY_EF_DEFAULT_API_URL);
}

function famly_ef_site_token_constant($site_key) {
    return 'FAMLY_API_TOKEN_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $site_key));
}

/** API token for a nursery: its own token if set, otherwise the shared one. */
function famly_ef_token_for_site($site) {
    $const = famly_ef_site_token_constant($site['key'] ?? '');
    if (defined($const) && constant($const)) return constant($const);
    if (!empty($site['api_token'])) return $site['api_token'];
    return famly_ef_global_token();
}

/** Describe where a nursery's token comes from (for admin screens; never the token itself). */
function famly_ef_token_source($site) {
    $const = famly_ef_site_token_constant($site['key'] ?? '');
    if (defined($const) && constant($const)) return $const . ' (wp-config.php)';
    if (!empty($site['api_token'])) return 'its own token';
    if (defined('FAMLY_API_TOKEN') && FAMLY_API_TOKEN) return 'FAMLY_API_TOKEN (wp-config.php)';
    return famly_ef_get_setting('api_token') ? 'the shared token' : 'none';
}

function famly_ef_global_token() {
    if (defined('FAMLY_API_TOKEN') && FAMLY_API_TOKEN) return FAMLY_API_TOKEN;
    return famly_ef_get_setting('api_token');
}

function famly_ef_notify_email() {
    if (defined('FAMLY_NOTIFY_EMAIL') && FAMLY_NOTIFY_EMAIL) return FAMLY_NOTIFY_EMAIL;
    return famly_ef_get_setting('notify_email', get_option('admin_email'));
}

function famly_ef_contact_email() {
    return famly_ef_get_setting('contact_email', get_option('admin_email'));
}

/** @return array[] Configured nurseries, keyed by their shortcode key. */
function famly_ef_sites() {
    $sites = [];
    foreach ((array) famly_ef_get_setting('sites', []) as $site) {
        if (!empty($site['key'])) $sites[$site['key']] = array_merge(famly_ef_blank_site(), $site);
    }
    return $sites;
}

/** Find a nursery by shortcode key or label. Empty request = first nursery. */
function famly_ef_resolve_site($requested) {
    $sites     = famly_ef_sites();
    $requested = trim((string) $requested);
    if ($requested === '') return $sites ? reset($sites) : null;
    if (isset($sites[$requested])) return $sites[$requested];
    foreach ($sites as $site) {
        if (strcasecmp(trim($site['label'] ?? ''), $requested) === 0) return $site;
    }
    return null;
}

/** "How did you hear about us?" options as [label => Famly source or '']. */
function famly_ef_sources() {
    $sources = [];
    $lines   = preg_split('/\r\n|\r|\n/', (string) famly_ef_get_setting('sources_text', famly_ef_default_sources_text()));
    foreach ($lines as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        if ($parts[0] === '') continue;
        $enum = $parts[1] ?? '';
        $sources[$parts[0]] = in_array($enum, FAMLY_EF_SOURCES, true) ? $enum : '';
    }
    return $sources;
}

/**
 * Extra questions for the on-page form, one per line:
 *   Question | select | Option A; Option B
 *   Question | checkbox* | Monday; Tuesday        (* = required)
 *   Question | text
 * A nursery's own list replaces the default list.
 *
 * @return array[] key => [key, label, type, required, options[]]
 */
function famly_ef_extra_questions(array $site = []) {
    $text = trim((string) ($site['extra_questions'] ?? ''));
    if ($text === '') $text = (string) famly_ef_get_setting('extra_questions');
    $questions = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $parts = array_map('trim', explode('|', $line, 3));
        if ($parts[0] === '') continue;
        $type     = strtolower($parts[1] ?? 'text');
        $required = substr($type, -1) === '*';
        $type     = rtrim($type, '*');
        if (!in_array($type, ['select', 'checkbox', 'text'], true)) $type = 'text';
        $options  = array_values(array_filter(array_map('trim', explode(';', $parts[2] ?? '')), 'strlen'));
        if ($type !== 'text' && !$options) $type = 'text';
        $key = substr(md5($parts[0]), 0, 8);
        $questions[$key] = ['key' => $key, 'label' => $parts[0], 'type' => $type, 'required' => $required, 'options' => $options];
    }
    return $questions;
}

function famly_ef_required_fields() {
    $required = famly_ef_get_setting('required_fields', []);
    $required = is_array($required) ? $required : [];
    return array_values(array_unique(array_merge($required, FAMLY_EF_ALWAYS_REQUIRED)));
}

/** Map of JS field key => element ID for the required fields. */
function famly_ef_required_fields_map() {
    $map = [
        'child_first_name'  => ['first_name',        'fe-child-first-name'],
        'child_last_name'   => ['last_name',         'fe-child-last-name'],
        'child_dob'         => ['dob',               'fe-child-dob'],
        'child_gender'      => ['gender',            'fe-child-gender'],
        'parent_first_name' => ['parent_first_name', 'fe-parent-first-name'],
        'parent_last_name'  => ['parent_last_name',  'fe-parent-last-name'],
        'email'             => ['email',             'fe-parent-email'],
        'phone'             => ['phone',             'fe-phone'],
        'postcode'          => ['postcode',          'fe-postcode'],
        'start_date'        => ['start_date',        'fe-start-date'],
        'source'            => ['source',            'fe-source'],
        'agree_terms'       => ['agree_terms',       'fe-agree-terms'],
    ];
    $js = [];
    foreach (famly_ef_required_fields() as $key) {
        if (isset($map[$key])) $js[$map[$key][0]] = $map[$key][1];
    }
    return $js;
}

/** Turnstile keys already saved in the Gravity Forms Turnstile add-on, if any. */
function famly_ef_gf_turnstile_keys() {
    $gf = get_option('gravityformsaddon_gravityformsturnstile_settings', []);
    if (is_array($gf) && !empty($gf['site_key']) && !empty($gf['site_secret'])) {
        return ['site_key' => $gf['site_key'], 'secret' => $gf['site_secret']];
    }
    return null;
}

/** Active Turnstile keys, or null when Turnstile is off or not set up. */
function famly_ef_turnstile_keys() {
    if (defined('FAMLY_TURNSTILE_SITE_KEY') && defined('FAMLY_TURNSTILE_SECRET') && FAMLY_TURNSTILE_SITE_KEY && FAMLY_TURNSTILE_SECRET) {
        return ['site_key' => FAMLY_TURNSTILE_SITE_KEY, 'secret' => FAMLY_TURNSTILE_SECRET];
    }
    $mode = famly_ef_get_setting('turnstile', 'off');
    if ($mode === 'gf') return famly_ef_gf_turnstile_keys();
    if ($mode === 'custom') {
        $site_key = famly_ef_get_setting('turnstile_site_key');
        $secret   = famly_ef_get_setting('turnstile_secret');
        if ($site_key && $secret) return ['site_key' => $site_key, 'secret' => $secret];
    }
    return null;
}

/** Write to the PHP error log. Never pass personal data in $message. */
function famly_ef_log($message) {
    error_log('[Famly] ' . $message);
}
