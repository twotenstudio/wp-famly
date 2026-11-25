<?php
/**
 * Famly Public API (GraphQL) client.
 * Docs: https://docs.famly.co/  Auth header: X-Famly-Accesstoken.
 */

if (!defined('ABSPATH')) exit;

const FAMLY_EF_CREATE_MUTATION = 'mutation CreateWebsiteInquiry($inquiries: [InquiryInput!]!) {
  inquiries {
    create(inquiries: $inquiries) { id status siteId siteName childId createdAt }
  }
}';

const FAMLY_EF_SITES_QUERY = 'query ListSites { sites { list { result { siteId title } } } }';

/**
 * POST a GraphQL request to Famly.
 *
 * @param string|null $token Null sends no token (used by the dry-run check).
 * @return array {
 *   ok: bool, http: int, data: mixed, error: string (safe to log), retryable: bool
 * }
 */
function famly_ef_graphql($query, $variables, $token, $timeout = 20) {
    $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    if ($token !== null) $headers['X-Famly-Accesstoken'] = $token;

    $response = wp_remote_post(famly_ef_api_url(), [
        'timeout' => $timeout,
        'headers' => $headers,
        'body'    => wp_json_encode(['query' => $query, 'variables' => (object) $variables]),
    ]);

    if (is_wp_error($response)) {
        return ['ok' => false, 'http' => 0, 'data' => null, 'retryable' => true,
                'error' => 'Connection error: ' . $response->get_error_message()];
    }

    $http = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($body)) {
        return ['ok' => false, 'http' => $http, 'data' => null, 'retryable' => $http >= 500 || $http === 429,
                'error' => 'HTTP ' . $http . ': response was not JSON'];
    }

    $errors = [];
    if (!empty($body['errors']) && is_array($body['errors'])) {
        foreach ($body['errors'] as $e) {
            $errors[] = is_array($e) ? ($e['message'] ?? 'Unknown error') : (string) $e;
        }
    } elseif ($http >= 400) {
        // Famly returns variable-coercion failures as a top-level object with a
        // "debug" string. That string echoes the submitted values, so keep only
        // the "Reason:" part, which names the field without its value.
        $msg = $body['message'] ?? ('HTTP ' . $http);
        if (!empty($body['debug']) && preg_match('/Reason:\s*(.+?)(?:\s*\(line \d+|$)/s', (string) $body['debug'], $m)) {
            $msg .= ' — ' . trim($m[1]);
        }
        $errors[] = $msg;
    }

    $data = $body['data'] ?? null;
    if ($errors && empty($data)) {
        return ['ok' => false, 'http' => $http, 'data' => $data, 'retryable' => $http >= 500 || $http === 429,
                'error' => 'HTTP ' . $http . ': ' . mb_substr(implode('; ', $errors), 0, 500)];
    }

    return ['ok' => true, 'http' => $http, 'data' => $data, 'retryable' => false,
            'error' => $errors ? mb_substr(implode('; ', $errors), 0, 500) : ''];
}

/**
 * Create one inquiry in Famly.
 * @return array famly_ef_graphql() result plus 'inquiry_id' on success.
 */
function famly_ef_create_inquiry(array $input, $token) {
    $result = famly_ef_graphql(FAMLY_EF_CREATE_MUTATION, ['inquiries' => [$input]], $token);
    $result['inquiry_id'] = '';
    if ($result['ok']) {
        $created = $result['data']['inquiries']['create'][0] ?? null;
        if (!empty($created['id'])) {
            $result['inquiry_id'] = (string) $created['id'];
        } else {
            $result['ok']    = false;
            $result['error'] = 'Famly did not return an inquiry ID' . ($result['error'] ? ' (' . $result['error'] . ')' : '');
        }
    }
    return $result;
}

/**
 * A short, fixed description of a failed Famly call, for storing and emailing.
 * Deliberately never includes Famly's response text.
 */
function famly_ef_error_category(array $result) {
    $http = (int) ($result['http'] ?? 0);
    if ($http === 0)                    return 'Could not reach Famly (connection error or timeout)';
    if ($http === 401 || $http === 403) return 'HTTP ' . $http . ': Famly refused the token (expired, revoked or missing the Manage enquiries permission)';
    if ($http === 429)                  return 'HTTP 429: Famly is rate-limiting requests';
    if ($http >= 500)                   return 'HTTP ' . $http . ': Famly server error';
    if ($http >= 400)                   return 'HTTP ' . $http . ': Famly rejected the request';
    return 'Unexpected response from Famly';
}

/** @return array famly_ef_graphql() result plus 'sites' => [siteId => title]. */
function famly_ef_list_sites($token) {
    $result = famly_ef_graphql(FAMLY_EF_SITES_QUERY, [], $token, 15);
    $result['sites'] = [];
    foreach ((array) ($result['data']['sites']['list']['result'] ?? []) as $s) {
        if (!empty($s['siteId'])) $result['sites'][$s['siteId']] = $s['title'] ?? '';
    }
    return $result;
}

/**
 * Read-only check that a token can see a site and has the enquiries
 * permission there. Selects only __typename, so no enquiry data comes back.
 *
 * @return array { sees_site: bool|null, site_title: string, token_sites: array, enquiries: bool, error: string }
 */
function famly_ef_check_site_access($token, $site_id) {
    $sites = famly_ef_list_sites($token);
    $r     = famly_ef_graphql(
        'query CheckEnquiryAccess($ids: [SiteId!]!) { inquiries { listBySiteIdsPaginated(siteIds: $ids, pageSize: 1) { __typename } } }',
        ['ids' => [$site_id]], $token, 15
    );
    return [
        'sees_site'   => $sites['ok'] ? isset($sites['sites'][$site_id]) : null,
        'site_title'  => $sites['sites'][$site_id] ?? '',
        'token_sites' => $sites['sites'],
        'enquiries'   => $r['ok'],
        'error'       => $r['ok'] ? '' : $r['error'],
    ];
}

/**
 * Check an InquiryInput's shape against Famly's schema WITHOUT creating
 * anything: the request is sent with no token, so Famly coerces the variables
 * (400 if a field is wrong) and then refuses with "Not authorized" (input OK).
 * Only use it with test data, never a real family's details.
 *
 * @return array { valid: bool|null, detail: string }
 */
function famly_ef_dry_run(array $input) {
    $result = famly_ef_graphql(FAMLY_EF_CREATE_MUTATION, ['inquiries' => [$input]], null, 15);
    if ($result['ok']) {
        return ['valid' => null, 'detail' => 'Unexpected: Famly accepted an unauthenticated request'];
    }
    if (stripos($result['error'], 'Not authorized') !== false) {
        return ['valid' => true, 'detail' => 'Input accepted by the schema (stopped at "Not authorized", nothing created)'];
    }
    return ['valid' => false, 'detail' => $result['error']];
}

/** UK-friendly phone normalisation to E.164 (+447…). Returns '' if unsure. */
function famly_ef_normalise_phone($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') return '';
    $plus   = strpos($raw, '+') === 0;
    $digits = preg_replace('/\D+/', '', $raw);
    if ($plus) {
        $e164 = '+' . $digits;
    } elseif (strpos($digits, '00') === 0) {
        $e164 = '+' . substr($digits, 2);
    } elseif (strpos($digits, '44') === 0 && strlen($digits) >= 12) {
        $e164 = '+' . $digits;
    } elseif (strpos($digits, '0') === 0) {
        $e164 = '+44' . substr($digits, 1);
    } else {
        return '';
    }
    // +44 numbers drop a "(0)" trunk prefix: +44 (0)7700… → +447700…
    $e164 = preg_replace('/^\+440/', '+44', $e164);
    return preg_match('/^\+[1-9]\d{7,14}$/', $e164) ? $e164 : '';
}
