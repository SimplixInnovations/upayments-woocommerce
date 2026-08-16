<?php
namespace UPayments\Token;

defined('ABSPATH') || exit;

class CustomerTokenIdentity {

    const SCHEMA_VERSION = 3;

    const TOKEN_PATTERN = '/^[1-9][0-9]{7}$/';

    const LEGACY_TOKEN_PATTERN = '/^[0-9]{8,18}$/';

    const SECRET_OPTION = 'upayments_token_identity_secret_v2';

    const SECRET_BYTES = 32;

    const SECRET_HEX_LENGTH = 64;

    const GENERATION_ID_BYTES = 16;

    const GENERATION_ID_HEX_LENGTH = 32;

    const VERIFIER_MESSAGE = 'upayments_token_identity_verifier_v1';

    const SCOPE_HEX_LENGTH = 32;

    const SCOPE_PATTERN = '/^[0-9a-f]{32}$/';

    const LOCK_PREFIX = 'upay_ctk_';

    const LOCK_MAX_LENGTH = 64;

    const KIND_CANONICAL = 'canonical';

    const KIND_LEGACY_COMPAT = 'legacy_compat';

    const SOURCE_CREATE_201 = 'create_201';

    const SOURCE_LEGACY_VERIFIED_CAPTURE = 'legacy_verified_capture';

    const STATE_ABSENT = 'absent';

    const STATE_VALID = 'valid';

    const STATE_INVALID = 'invalid';

    const STATE_LEGACY_MIGRATION_REQUIRED = 'legacy_migration_required';

    const HISTORY_PAGE_SIZE = 20;

    const HISTORY_MAX_ORDERS = 200;

    const HISTORY_INDETERMINATE = 'indeterminate';

    const HISTORY_NONE = 'none';

    const HISTORY_UNSCOPED_LEGACY = 'unscoped_legacy';

    const HISTORY_MALFORMED_SCOPED = 'malformed_scoped';

    const HISTORY_CURRENT_SCOPE_ORPHAN = 'current_scope_orphan';

    const HISTORY_PRIOR_SCOPE_ONLY = 'prior_scope_only';

    const HISTORY_SECRET_GENERATION_MISMATCH = 'secret_generation_mismatch';

    // ────────────────────────────────────────────────────────
    // SECRET RECORD
    // ────────────────────────────────────────────────────────

    public static function get_or_create_secret_record() {
        global $wpdb;

        $existing = get_option(self::SECRET_OPTION, '');

        if (is_array($existing) && self::is_valid_secret_record($existing)) {
            return $existing;
        }

        if ($existing !== '' && $existing !== false) {
            return null;
        }

        $blog_id = (string) get_current_blog_id();
        $meta_prefix = '_upay_customer_token_v2_b' . $blog_id . '_';
        $escaped_prefix = $wpdb->esc_like($meta_prefix);

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 1",
                $escaped_prefix . '%'
            )
        );

        if ($exists === null) {
            return null;
        }

        try {
            $secret = bin2hex(random_bytes(self::SECRET_BYTES));
            $generation_id = bin2hex(random_bytes(self::GENERATION_ID_BYTES));
            $verifier = hash_hmac('sha256', self::VERIFIER_MESSAGE, $secret);
        } catch (\Throwable $e) {
            return null;
        }

        if (!self::is_valid_hex($secret, self::SECRET_HEX_LENGTH)) {
            return null;
        }

        if (!self::is_valid_hex($generation_id, self::GENERATION_ID_HEX_LENGTH)) {
            return null;
        }

        if (!self::is_valid_hex($verifier, self::SECRET_HEX_LENGTH)) {
            return null;
        }

        $record = array(
            'version' => 1,
            'secret' => $secret,
            'generation_id' => $generation_id,
            'verifier' => $verifier,
        );

        if (add_option(self::SECRET_OPTION, $record, '', 'no')) {
            return $record;
        }

        $read_back = get_option(self::SECRET_OPTION, '');
        if (is_array($read_back) && self::is_valid_secret_record($read_back)) {
            return $read_back;
        }

        return null;
    }

    public static function is_valid_secret_record($record) {
        if (!is_array($record)) {
            return false;
        }

        if (!isset($record['version']) || $record['version'] !== 1) {
            return false;
        }

        if (!isset($record['secret']) || !self::is_valid_hex($record['secret'], self::SECRET_HEX_LENGTH)) {
            return false;
        }

        if (!isset($record['generation_id']) || !self::is_valid_hex($record['generation_id'], self::GENERATION_ID_HEX_LENGTH)) {
            return false;
        }

        if (!isset($record['verifier']) || !self::is_valid_hex($record['verifier'], self::SECRET_HEX_LENGTH)) {
            return false;
        }

        $expected_verifier = hash_hmac('sha256', self::VERIFIER_MESSAGE, $record['secret']);

        return hash_equals($expected_verifier, $record['verifier']);
    }

    private static function is_valid_hex($value, $expected_length) {
        if (!is_string($value)) {
            return false;
        }
        if (strlen($value) !== $expected_length) {
            return false;
        }
        return preg_match('/^[0-9a-f]+$/', $value) === 1;
    }

    // ────────────────────────────────────────────────────────
    // SCOPE FINGERPRINT
    // ────────────────────────────────────────────────────────

    public static function is_valid_scope($scope) {
        if (!is_string($scope)) {
            return false;
        }
        return preg_match(self::SCOPE_PATTERN, $scope) === 1;
    }

    public static function get_scope_fingerprint($api_key, $is_test_mode) {
        if (empty($api_key) || !is_scalar($api_key)) {
            return null;
        }

        $secret_record = self::get_or_create_secret_record();
        if ($secret_record === null) {
            return null;
        }

        $blog_id = (string) get_current_blog_id();
        $mode = $is_test_mode ? 'test' : 'live';

        $fingerprint = hash_hmac(
            'sha256',
            $blog_id . '|' . $mode . '|' . (string) $api_key,
            $secret_record['secret']
        );

        $hex = strtolower($fingerprint);
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
            return null;
        }

        return substr($hex, 0, self::SCOPE_HEX_LENGTH);
    }

    public static function get_generation_id() {
        $secret_record = self::get_or_create_secret_record();
        if ($secret_record === null) {
            return null;
        }
        return $secret_record['generation_id'];
    }

    // ────────────────────────────────────────────────────────
    // META KEY (with boundary validation)
    // ────────────────────────────────────────────────────────

    public static function get_user_meta_key($blog_id, $scope_fingerprint) {
        if (!is_string($blog_id) || $blog_id === '' || (int) $blog_id <= 0) {
            return null;
        }
        if (!self::is_valid_scope($scope_fingerprint)) {
            return null;
        }
        return '_upay_customer_token_v2_b' . $blog_id . '_' . $scope_fingerprint;
    }

    // ────────────────────────────────────────────────────────
    // LOCK NAME
    // ────────────────────────────────────────────────────────

    public static function get_lock_name($scope_fingerprint, $user_id) {
        if (!self::is_valid_scope($scope_fingerprint)) {
            return null;
        }
        if ($user_id <= 0) {
            return null;
        }
        $lock = self::LOCK_PREFIX . $scope_fingerprint . '_' . (string) $user_id;
        if (strlen($lock) > self::LOCK_MAX_LENGTH) {
            return null;
        }
        return $lock;
    }

    // ────────────────────────────────────────────────────────
    // TOKEN GENERATORS / VALIDATORS
    // ────────────────────────────────────────────────────────

    public static function generate_canonical_token() {
        try {
            return (string) random_int(10000000, 99999999);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function is_valid_canonical_token($token) {
        if (!is_scalar($token)) {
            return false;
        }
        return preg_match(self::TOKEN_PATTERN, (string) $token) === 1;
    }

    public static function is_valid_legacy_token($token) {
        if (!is_scalar($token)) {
            return false;
        }
        return preg_match(self::LEGACY_TOKEN_PATTERN, (string) $token) === 1;
    }

    public static function is_valid_token_for_kind($token, $kind) {
        if ($kind === self::KIND_CANONICAL) {
            return self::is_valid_canonical_token($token);
        }
        if ($kind === self::KIND_LEGACY_COMPAT) {
            return self::is_valid_legacy_token($token);
        }
        return false;
    }

    // ────────────────────────────────────────────────────────
    // READ PROVENANCE (authoritative)
    // ────────────────────────────────────────────────────────

    public static function read_provenance($user_id, $scope_fingerprint) {
        if ($user_id <= 0 || !self::is_valid_scope($scope_fingerprint)) {
            return array('state' => self::STATE_ABSENT, 'record' => null);
        }

        $blog_id = (string) get_current_blog_id();
        $meta_key = self::get_user_meta_key($blog_id, $scope_fingerprint);
        if ($meta_key === null) {
            return array('state' => self::STATE_INVALID, 'record' => null);
        }

        $exists = metadata_exists('user', $user_id, $meta_key);
        if (!$exists) {
            return array('state' => self::STATE_ABSENT, 'record' => null);
        }

        $record = get_user_meta($user_id, $meta_key, true);

        if (!is_array($record)) {
            return array('state' => self::STATE_INVALID, 'record' => $record);
        }

        $validation = self::validate_provenance_record($record, $scope_fingerprint);
        if ($validation === 'valid') {
            return array('state' => self::STATE_VALID, 'record' => $record);
        }

        return array('state' => self::STATE_INVALID, 'record' => $record);
    }

    private static function validate_provenance_record($record, $requested_scope) {
        if (!isset($record['version']) || !is_int($record['version']) || $record['version'] !== self::SCHEMA_VERSION) {
            return 'invalid';
        }

        if (!isset($record['kind']) || !is_string($record['kind'])) {
            return 'invalid';
        }

        $valid_kinds = array(self::KIND_CANONICAL, self::KIND_LEGACY_COMPAT);
        if (!in_array($record['kind'], $valid_kinds, true)) {
            return 'invalid';
        }

        if (!isset($record['token']) || !is_scalar($record['token'])) {
            return 'invalid';
        }

        $token = (string) $record['token'];

        if ($record['kind'] === self::KIND_CANONICAL && !self::is_valid_canonical_token($token)) {
            return 'invalid';
        }

        if ($record['kind'] === self::KIND_LEGACY_COMPAT && !self::is_valid_legacy_token($token)) {
            return 'invalid';
        }

        $valid_sources = array(self::SOURCE_CREATE_201, self::SOURCE_LEGACY_VERIFIED_CAPTURE);
        if (!isset($record['source']) || !in_array($record['source'], $valid_sources, true)) {
            return 'invalid';
        }

        if ($record['kind'] === self::KIND_CANONICAL && $record['source'] !== self::SOURCE_CREATE_201) {
            return 'invalid';
        }

        if ($record['kind'] === self::KIND_LEGACY_COMPAT && $record['source'] !== self::SOURCE_LEGACY_VERIFIED_CAPTURE) {
            return 'invalid';
        }

        if (!isset($record['scope']) || !self::is_valid_scope($record['scope'])) {
            return 'invalid';
        }

        if ($record['scope'] !== $requested_scope) {
            return 'invalid';
        }

        if (!isset($record['secret_generation_id']) || !self::is_valid_hex($record['secret_generation_id'], self::GENERATION_ID_HEX_LENGTH)) {
            return 'invalid';
        }

        $current_generation = self::get_generation_id();
        if ($current_generation === null || $record['secret_generation_id'] !== $current_generation) {
            return 'invalid';
        }

        if (!isset($record['established_at_gmt']) || !is_int($record['established_at_gmt']) || $record['established_at_gmt'] <= 0) {
            return 'invalid';
        }

        return 'valid';
    }

    // ────────────────────────────────────────────────────────
    // CREATE PROVENANCE (immutable)
    // ────────────────────────────────────────────────────────

    public static function create_provenance($user_id, $scope_fingerprint, $kind, $token, $source) {
        if ($user_id <= 0 || !self::is_valid_scope($scope_fingerprint)) {
            return false;
        }

        $valid_pairings = array(
            self::KIND_CANONICAL => self::SOURCE_CREATE_201,
            self::KIND_LEGACY_COMPAT => self::SOURCE_LEGACY_VERIFIED_CAPTURE,
        );

        if (!isset($valid_pairings[$kind]) || $valid_pairings[$kind] !== $source) {
            return false;
        }

        if ($kind === self::KIND_CANONICAL && !self::is_valid_canonical_token($token)) {
            return false;
        }

        if ($kind === self::KIND_LEGACY_COMPAT && !self::is_valid_legacy_token($token)) {
            return false;
        }

        $generation_id = self::get_generation_id();
        if ($generation_id === null) {
            return false;
        }

        $blog_id = (string) get_current_blog_id();
        $meta_key = self::get_user_meta_key($blog_id, $scope_fingerprint);
        if ($meta_key === null) {
            return false;
        }

        if (metadata_exists('user', $user_id, $meta_key)) {
            return false;
        }

        $record = array(
            'version' => self::SCHEMA_VERSION,
            'kind' => $kind,
            'token' => (string) $token,
            'source' => $source,
            'scope' => $scope_fingerprint,
            'secret_generation_id' => $generation_id,
            'established_at_gmt' => time(),
        );

        $result = add_user_meta($user_id, $meta_key, $record, true);
        if ($result === false) {
            return false;
        }

        return true;
    }

    // ────────────────────────────────────────────────────────
    // CLASSIFY CREATE TOKEN RESPONSE
    // ────────────────────────────────────────────────────────

    public static function classify_create_token_response($transport, $submitted_token) {
        $result = array(
            'success' => false,
            'token' => null,
            'reason' => 'unknown',
        );

        if (!is_array($transport)) {
            $result['reason'] = 'transport_failure';
            return $result;
        }

        if (!isset($transport['http_status']) || !is_scalar($transport['http_status'])) {
            $result['reason'] = 'transport_failure';
            return $result;
        }

        $http_status = (int) $transport['http_status'];

        if ($http_status <= 0) {
            $result['reason'] = 'transport_failure';
            return $result;
        }

        if ($http_status !== 201) {
            $result['reason'] = 'http_' . $http_status;
            return $result;
        }

        if (!isset($transport['transport_ok']) || !$transport['transport_ok']) {
            $result['reason'] = 'http_201_transport_not_ok';
            return $result;
        }

        if (!isset($transport['curl_errno']) || (int) $transport['curl_errno'] !== 0) {
            $result['reason'] = 'curl_error';
            return $result;
        }

        if (!isset($transport['body']) || !is_scalar($transport['body'])) {
            $result['reason'] = 'malformed_body';
            return $result;
        }

        $body = json_decode((string) $transport['body'], true);
        if (!is_array($body)) {
            $result['reason'] = 'malformed_json';
            return $result;
        }

        if (!array_key_exists('status', $body) || $body['status'] !== true) {
            $result['reason'] = 'status_not_true';
            return $result;
        }

        if (!isset($body['data']) || !is_array($body['data'])) {
            $result['reason'] = 'missing_data';
            return $result;
        }

        if (!isset($body['data']['customerUniqueToken']) || !is_scalar($body['data']['customerUniqueToken'])) {
            $result['reason'] = 'missing_token';
            return $result;
        }

        if (!self::is_valid_canonical_token((string) $submitted_token)) {
            $result['reason'] = 'invalid_candidate';
            return $result;
        }

        if ((string) $body['data']['customerUniqueToken'] !== (string) $submitted_token) {
            $result['reason'] = 'token_mismatch';
            return $result;
        }

        $result['success'] = true;
        $result['token'] = (string) $submitted_token;
        $result['reason'] = 'success';
        return $result;
    }

    // ────────────────────────────────────────────────────────
    // HISTORY INSPECTOR (paginated, trustworthy)
    // ────────────────────────────────────────────────────────

    public static function inspect_customer_history($user_id, $current_scope) {
        if ($user_id <= 0 || !self::is_valid_scope($current_scope)) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'invalid_input');
        }

        $current_generation = self::get_generation_id();
        if ($current_generation === null) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'no_generation');
        }

        $total_orders = 0;
        $found_tokens = array();
        $page = 1;
        $expected_total = null;

        while ($total_orders < self::HISTORY_MAX_ORDERS) {
            try {
                $orders = wc_get_orders(array(
                    'customer_id' => $user_id,
                    'payment_method' => 'upayments',
                    'limit' => self::HISTORY_PAGE_SIZE,
                    'paged' => $page,
                    'orderby' => 'ID',
                    'order' => 'DESC',
                    'return' => 'ids',
                    'paginate' => true,
                ));
            } catch (\Throwable $e) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'query_exception');
            }

            if (!is_object($orders)) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'malformed_query_result');
            }

            if (!isset($orders->orders) || !is_array($orders->orders)) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'missing_orders_array');
            }

            if (!isset($orders->total) || !is_numeric($orders->total) || (int) $orders->total < 0) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'missing_total');
            }

            if (!isset($orders->max_num_pages) || !is_numeric($orders->max_num_pages) || (int) $orders->max_num_pages < 0) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'missing_max_pages');
            }

            $current_total = (int) $orders->total;

            if ($expected_total === null) {
                $expected_total = $current_total;
            } elseif ($current_total !== $expected_total) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'total_changed');
            }

            if (empty($orders->orders)) {
                break;
            }

            foreach ($orders->orders as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'unloadable_order');
                }

                $has_token = $order->meta_exists('_upay_customer_unique_token');
                if (!$has_token) {
                    continue;
                }

                $token = $order->get_meta('_upay_customer_unique_token', true);
                if (!is_scalar($token) || (string) $token === '') {
                    $found_tokens[] = array(
                        'token' => '',
                        'kind' => '',
                        'scope' => '',
                        'generation' => '',
                        'has_token_meta' => true,
                        'token_empty' => true,
                    );
                    continue;
                }

                $kind = $order->get_meta('_upay_customer_token_kind_v1', true);
                $scope = $order->get_meta('_upay_customer_token_scope_v1', true);
                $generation = $order->get_meta('_upay_customer_token_generation_v1', true);

                $has_kind = $order->meta_exists('_upay_customer_token_kind_v1');
                $has_scope = $order->meta_exists('_upay_customer_token_scope_v1');
                $has_generation = $order->meta_exists('_upay_customer_token_generation_v1');

                $found_tokens[] = array(
                    'token' => (string) $token,
                    'kind' => is_scalar($kind) ? (string) $kind : '',
                    'scope' => is_scalar($scope) ? (string) $scope : '',
                    'generation' => is_scalar($generation) ? (string) $generation : '',
                    'has_kind' => $has_kind,
                    'has_scope' => $has_scope,
                    'has_generation' => $has_generation,
                    'token_empty' => false,
                );
            }

            $total_orders += count($orders->orders);
            $page++;

            if ($total_orders >= $expected_total) {
                break;
            }
        }

        $is_complete = ($total_orders < self::HISTORY_MAX_ORDERS) || ($total_orders >= $expected_total);

        if (!$is_complete && empty($found_tokens)) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'safety_cap_reached_no_tokens');
        }

        if (empty($found_tokens)) {
            return array('classification' => self::HISTORY_NONE, 'reason' => 'no_tokens_found');
        }

        $has_generation_mismatch = false;
        $has_malformed = false;
        $has_unscoped = false;
        $has_current_scope_orphan = false;
        $has_prior_scope_same_gen = false;

        foreach ($found_tokens as $entry) {
            if ($entry['token_empty']) {
                continue;
            }

            $kind = $entry['kind'];
            $scope = $entry['scope'];
            $generation = $entry['generation'];
            $has_kind = $entry['has_kind'];
            $has_scope = $entry['has_scope'];
            $has_generation = $entry['has_generation'];

            if (!$has_kind || !$has_scope || !$has_generation) {
                $has_unscoped = true;
                continue;
            }

            if ($kind === '' || $scope === '' || $generation === '') {
                $has_unscoped = true;
                continue;
            }

            if (!self::is_valid_scope($scope)) {
                $has_malformed = true;
                continue;
            }

            $valid_kinds = array(self::KIND_CANONICAL, self::KIND_LEGACY_COMPAT);
            if (!in_array($kind, $valid_kinds, true)) {
                $has_malformed = true;
                continue;
            }

            if (!self::is_valid_token_for_kind($entry['token'], $kind)) {
                $has_malformed = true;
                continue;
            }

            if (!self::is_valid_hex($generation, self::GENERATION_ID_HEX_LENGTH)) {
                $has_malformed = true;
                continue;
            }

            if ($generation !== $current_generation) {
                $has_generation_mismatch = true;
                continue;
            }

            if ($scope === $current_scope) {
                $has_current_scope_orphan = true;
            } else {
                $has_prior_scope_same_gen = true;
            }
        }

        if ($has_generation_mismatch) {
            return array('classification' => self::HISTORY_SECRET_GENERATION_MISMATCH, 'reason' => 'generation_mismatch');
        }

        if ($has_malformed) {
            return array('classification' => self::HISTORY_MALFORMED_SCOPED, 'reason' => 'malformed_snapshot');
        }

        if ($has_unscoped) {
            return array('classification' => self::HISTORY_UNSCOPED_LEGACY, 'reason' => 'unscoped_tokens_exist');
        }

        if ($has_current_scope_orphan) {
            return array('classification' => self::HISTORY_CURRENT_SCOPE_ORPHAN, 'reason' => 'current_scope_history_without_provenance');
        }

        if (!$is_complete) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'incomplete_scan');
        }

        if ($has_prior_scope_same_gen) {
            return array('classification' => self::HISTORY_PRIOR_SCOPE_ONLY, 'reason' => 'prior_scope_same_generation');
        }

        return array('classification' => self::HISTORY_NONE, 'reason' => 'no_blocking_history');
    }

    // ────────────────────────────────────────────────────────
    // PRIOR PROVENANCE INSPECTION (full validation)
    // ────────────────────────────────────────────────────────

    public static function inspect_current_user_prior_provenance($user_id) {
        if ($user_id <= 0) {
            return array('state' => 'none', 'reason' => 'not_logged_in');
        }

        $current_generation = self::get_generation_id();
        if ($current_generation === null) {
            return array('state' => 'read_failure', 'reason' => 'no_generation');
        }

        global $wpdb;
        $blog_id = (string) get_current_blog_id();
        $meta_prefix = '_upay_customer_token_v2_b' . $blog_id . '_';
        $escaped_prefix = $wpdb->esc_like($meta_prefix);

        $meta_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
                $user_id,
                $escaped_prefix . '%'
            )
        );

        if ($meta_keys === null) {
            return array('state' => 'read_failure', 'reason' => 'db_query_failed');
        }

        if (empty($meta_keys)) {
            return array('state' => 'none', 'reason' => 'no_provenance_records');
        }

        $has_generation_mismatch = false;
        $has_invalid = false;

        foreach ($meta_keys as $meta_key) {
            $scope_from_key = substr($meta_key, strlen($meta_prefix));
            if (!self::is_valid_scope($scope_from_key)) {
                $has_invalid = true;
                continue;
            }

            $record = get_user_meta($user_id, $meta_key, true);

            if (!is_array($record)) {
                $has_invalid = true;
                continue;
            }

            $validation = self::validate_provenance_record($record, $scope_from_key);
            if ($validation !== 'valid') {
                $has_invalid = true;
                continue;
            }

            if (!isset($record['secret_generation_id']) || !is_string($record['secret_generation_id'])) {
                $has_invalid = true;
                continue;
            }

            if ($record['secret_generation_id'] !== $current_generation) {
                $has_generation_mismatch = true;
            }
        }

        if ($has_generation_mismatch) {
            return array('state' => 'secret_generation_mismatch', 'reason' => 'different_generation_found');
        }

        if ($has_invalid) {
            return array('state' => 'invalid', 'reason' => 'malformed_provenance');
        }

        return array('state' => 'same_generation_only', 'reason' => 'all_records_same_generation');
    }

    // ────────────────────────────────────────────────────────
    // VALIDATE TOKEN RUNTIME CONTEXT
    // ────────────────────────────────────────────────────────

    public static function validate_token_runtime_context($token, $kind, $scope, $generation_id) {
        if ($token === null) {
            return true;
        }

        if (!is_string($kind) || !in_array($kind, array(self::KIND_CANONICAL, self::KIND_LEGACY_COMPAT), true)) {
            return false;
        }

        if (!self::is_valid_token_for_kind($token, $kind)) {
            return false;
        }

        if (!self::is_valid_scope($scope)) {
            return false;
        }

        if (!self::is_valid_hex($generation_id, self::GENERATION_ID_HEX_LENGTH)) {
            return false;
        }

        $current_generation = self::get_generation_id();
        if ($current_generation === null || $generation_id !== $current_generation) {
            return false;
        }

        $current_scope = self::get_scope_fingerprint(
            get_option('upayments_api_key', ''),
            get_option('upayments_test_mode', 'no') === 'yes'
        );
        if ($current_scope === null || $scope !== $current_scope) {
            return false;
        }

        return true;
    }

    // ────────────────────────────────────────────────────────
    // GET OR ESTABLISH TOKEN
    // ────────────────────────────────────────────────────────

    public static function get_or_establish_token($user_id, $api_key, $is_test_mode, callable $create_token_caller) {
        $result = array(
            'success' => false,
            'token' => null,
            'reason' => 'unknown',
            'established' => false,
            'kind' => null,
            'scope' => null,
            'secret_generation_id' => null,
        );

        if ($user_id <= 0) {
            $result['reason'] = 'not_logged_in';
            return $result;
        }

        $scope = self::get_scope_fingerprint($api_key, $is_test_mode);
        if ($scope === null) {
            $result['reason'] = 'scope_failure';
            return $result;
        }

        $generation_id = self::get_generation_id();
        if ($generation_id === null) {
            $result['reason'] = 'generation_failure';
            return $result;
        }

        $result['scope'] = $scope;
        $result['secret_generation_id'] = $generation_id;

        $provenance = self::read_provenance($user_id, $scope);

        if ($provenance['state'] === self::STATE_VALID) {
            $result['success'] = true;
            $result['token'] = $provenance['record']['token'];
            $result['reason'] = 'existing';
            $result['kind'] = $provenance['record']['kind'];
            return $result;
        }

        if ($provenance['state'] === self::STATE_INVALID) {
            $result['reason'] = 'invalid_provenance';
            return $result;
        }

        $prior_check = self::inspect_current_user_prior_provenance($user_id);
        if ($prior_check['state'] === 'secret_generation_mismatch') {
            $result['reason'] = 'secret_generation_mismatch';
            return $result;
        }
        if ($prior_check['state'] === 'invalid') {
            $result['reason'] = 'invalid_provenance';
            return $result;
        }
        if ($prior_check['state'] === 'read_failure') {
            $result['reason'] = 'read_failure';
            return $result;
        }

        $history = self::inspect_customer_history($user_id, $scope);

        $blocking_states = array(
            self::HISTORY_INDETERMINATE,
            self::HISTORY_UNSCOPED_LEGACY,
            self::HISTORY_MALFORMED_SCOPED,
            self::HISTORY_CURRENT_SCOPE_ORPHAN,
            self::HISTORY_SECRET_GENERATION_MISMATCH,
        );

        if (in_array($history['classification'], $blocking_states, true)) {
            $result['reason'] = 'legacy_migration_required';
            return $result;
        }

        $lock_name = self::get_lock_name($scope, $user_id);
        if ($lock_name === null) {
            $result['reason'] = 'lock_name_invalid';
            return $result;
        }

        $lock_acquired = self::acquire_lock($lock_name);

        if (!$lock_acquired) {
            $result['reason'] = 'lock_contention';
            return $result;
        }

        try {
            $provenance = self::read_provenance($user_id, $scope);

            if ($provenance['state'] === self::STATE_VALID) {
                $result['success'] = true;
                $result['token'] = $provenance['record']['token'];
                $result['reason'] = 'existing_after_lock';
                $result['kind'] = $provenance['record']['kind'];
                return $result;
            }

            if ($provenance['state'] === self::STATE_INVALID) {
                $result['reason'] = 'invalid_provenance';
                return $result;
            }

            $prior_check = self::inspect_current_user_prior_provenance($user_id);
            if ($prior_check['state'] === 'secret_generation_mismatch') {
                $result['reason'] = 'secret_generation_mismatch';
                return $result;
            }
            if ($prior_check['state'] === 'invalid') {
                $result['reason'] = 'invalid_provenance';
                return $result;
            }
            if ($prior_check['state'] === 'read_failure') {
                $result['reason'] = 'read_failure';
                return $result;
            }

            $history = self::inspect_customer_history($user_id, $scope);

            if (in_array($history['classification'], $blocking_states, true)) {
                $result['reason'] = 'legacy_migration_required';
                return $result;
            }

            if ($history['classification'] !== self::HISTORY_NONE
                && $history['classification'] !== self::HISTORY_PRIOR_SCOPE_ONLY
            ) {
                $result['reason'] = 'legacy_migration_required';
                return $result;
            }

            $canonical_attempt = self::establish_canonical_token($create_token_caller);

            if ($canonical_attempt['success']) {
                $persisted = self::create_provenance(
                    $user_id,
                    $scope,
                    self::KIND_CANONICAL,
                    $canonical_attempt['token'],
                    self::SOURCE_CREATE_201
                );

                if ($persisted) {
                    $result['success'] = true;
                    $result['token'] = $canonical_attempt['token'];
                    $result['reason'] = 'created';
                    $result['established'] = true;
                    $result['kind'] = self::KIND_CANONICAL;
                    return $result;
                }

                $result['reason'] = 'persist_failure';
                return $result;
            }

            $result['reason'] = $canonical_attempt['reason'];
            return $result;
        } finally {
            self::release_lock($lock_name);
        }
    }

    private static function establish_canonical_token(callable $create_token_caller) {
        $result = array(
            'success' => false,
            'token' => null,
            'reason' => 'unknown',
        );

        $candidate = self::generate_canonical_token();
        if ($candidate === null) {
            $result['reason'] = 'random_failure';
            return $result;
        }

        try {
            $transport = $create_token_caller($candidate);
        } catch (\Throwable $e) {
            $result['reason'] = 'transport_failure';
            return $result;
        }

        $api_result = self::classify_create_token_response($transport, $candidate);

        if ($api_result['success']) {
            $result['success'] = true;
            $result['token'] = $candidate;
            $result['reason'] = 'created';
            return $result;
        }

        $result['reason'] = $api_result['reason'];
        return $result;
    }

    // ────────────────────────────────────────────────────────
    // SAVED CARDS FOR CURRENT USER
    // ────────────────────────────────────────────────────────

    public static function get_saved_cards_for_current_user($user_id, $api_key, $is_test_mode, callable $get_saved_cards_caller) {
        if ($user_id <= 0) {
            return null;
        }

        $scope = self::get_scope_fingerprint($api_key, $is_test_mode);
        if ($scope === null) {
            return null;
        }

        $provenance = self::read_provenance($user_id, $scope);

        if ($provenance['state'] !== self::STATE_VALID) {
            return null;
        }

        $token = $provenance['record']['token'];
        if (empty($token) || !is_scalar($token)) {
            return null;
        }

        $generation_id = self::get_generation_id();
        if ($generation_id === null) {
            return null;
        }

        if (isset($provenance['record']['secret_generation_id'])
            && $provenance['record']['secret_generation_id'] !== $generation_id
        ) {
            return null;
        }

        if (isset($provenance['record']['scope'])
            && $provenance['record']['scope'] !== $scope
        ) {
            return null;
        }

        if (!preg_match('/^[0-9]{8,18}$/', (string) $token)) {
            return null;
        }

        try {
            $result = $get_saved_cards_caller($token);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($result)) {
            return null;
        }

        if (!isset($result['result']) || $result['result'] !== 'success') {
            return null;
        }

        if (!isset($result['data']) || !is_array($result['data'])) {
            return null;
        }

        return $result;
    }

    // ────────────────────────────────────────────────────────
    // VERIFY CARD MEMBERSHIP
    // ────────────────────────────────────────────────────────

    public static function verify_card_membership($card_token, $customer_token, callable $get_saved_cards_caller) {
        if (empty($card_token) || !is_scalar($card_token)) {
            return false;
        }

        if (empty($customer_token) || !is_scalar($customer_token)) {
            return false;
        }

        if (!preg_match('/^[0-9]{8,18}$/', (string) $customer_token)) {
            return false;
        }

        try {
            $result = $get_saved_cards_caller($customer_token);
        } catch (\Throwable $e) {
            return false;
        }

        if (!is_array($result)) {
            return false;
        }

        if (!isset($result['result']) || $result['result'] !== 'success') {
            return false;
        }

        if (!isset($result['data']) || !is_array($result['data'])) {
            return false;
        }

        $submitted = (string) $card_token;

        foreach ($result['data'] as $card_entry) {
            if (!is_array($card_entry)) {
                continue;
            }

            if (!isset($card_entry['token']) || !is_scalar($card_entry['token'])) {
                continue;
            }

            if ((string) $card_entry['token'] === $submitted) {
                return true;
            }
        }

        return false;
    }

    // ────────────────────────────────────────────────────────
    // STALE PR16 CLEANUP (safe version)
    // ────────────────────────────────────────────────────────

    public static function clear_stale_pr16_attempt_metadata($order) {
        if (!$order) {
            return false;
        }

        $has_token = $order->meta_exists('_upay_customer_unique_token');
        $has_kind = $order->meta_exists('_upay_customer_token_kind_v1');
        $has_scope = $order->meta_exists('_upay_customer_token_scope_v1');
        $has_generation = $order->meta_exists('_upay_customer_token_generation_v1');

        if (!$has_token || !$has_kind || !$has_scope || !$has_generation) {
            return true;
        }

        $kind = $order->get_meta('_upay_customer_token_kind_v1', true);
        $scope = $order->get_meta('_upay_customer_token_scope_v1', true);
        $generation = $order->get_meta('_upay_customer_token_generation_v1', true);
        $token = $order->get_meta('_upay_customer_unique_token', true);

        if (!is_scalar($kind) || !is_scalar($scope) || !is_scalar($generation) || !is_scalar($token)) {
            return true;
        }

        $valid_kinds = array(self::KIND_CANONICAL, self::KIND_LEGACY_COMPAT);
        if (!in_array((string) $kind, $valid_kinds, true)) {
            return true;
        }

        if (!self::is_valid_token_for_kind($token, (string) $kind)) {
            return true;
        }

        if (!self::is_valid_scope((string) $scope)) {
            return true;
        }

        if (!self::is_valid_hex((string) $generation, self::GENERATION_ID_HEX_LENGTH)) {
            return true;
        }

        $current_generation = self::get_generation_id();
        if ($current_generation === null || (string) $generation !== $current_generation) {
            return true;
        }

        try {
            $order->delete_meta_data('_upay_customer_unique_token');
            $order->delete_meta_data('_upay_customer_token_kind_v1');
            $order->delete_meta_data('_upay_customer_token_scope_v1');
            $order->delete_meta_data('_upay_customer_token_generation_v1');
            $order->delete_meta_data('_upay_credit_card_token');
            $order->save_meta_data();
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    // ────────────────────────────────────────────────────────
    // LOCK HELPERS
    // ────────────────────────────────────────────────────────

    private static function acquire_lock($lock_name) {
        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock_name)
        );

        return $result === '1' || $result === 1;
    }

    private static function release_lock($lock_name) {
        global $wpdb;

        $wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)
        );
    }
}
