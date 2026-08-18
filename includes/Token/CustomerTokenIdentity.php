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

    const VERIFIER_DOMAIN = 'upayments_token_identity_secret_record_v1';

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

    const HISTORY_CARD_WITHOUT_CUSTOMER_IDENTITY = 'card_without_customer_identity';

    // ────────────────────────────────────────────────────────
    // SECRET RECORD
    // ────────────────────────────────────────────────────────

    const SECRET_ABSENT = 'absent';
    const SECRET_VALID = 'valid';
    const SECRET_INVALID = 'invalid';

    /**
     * Read-only secret record access. NEVER creates a secret.
     *
     * @return array with 'state' (SECRET_ABSENT/SECRET_VALID/SECRET_INVALID) and 'record' (when VALID).
     */
    public static function read_existing_secret_record() {
        $missing = new \stdClass();
        $existing = get_option(self::SECRET_OPTION, $missing);

        if ($existing === $missing) {
            return array('state' => self::SECRET_ABSENT, 'record' => null);
        }

        if (is_array($existing) && self::is_valid_secret_record($existing)) {
            return array('state' => self::SECRET_VALID, 'record' => $existing);
        }

        return array('state' => self::SECRET_INVALID, 'record' => null);
    }

    /**
     * Derive scope fingerprint from an already-validated secret record.
     * Side-effect free: never creates a secret.
     *
     * @param string $api_key
     * @param bool   $is_test_mode
     * @param array  $validated_secret_record Must be a valid secret record.
     * @return string|null Scope fingerprint or null on failure.
     */
    private static function derive_scope_fingerprint($api_key, $is_test_mode, $validated_secret_record) {
        if (empty($api_key) || !is_scalar($api_key)) {
            return null;
        }
        if (!is_array($validated_secret_record) || !isset($validated_secret_record['secret'])) {
            return null;
        }
        $blog_id = (string) get_current_blog_id();
        $mode = $is_test_mode ? 'test' : 'live';
        $fingerprint = hash_hmac(
            'sha256',
            $blog_id . '|' . $mode . '|' . (string) $api_key,
            $validated_secret_record['secret']
        );
        $hex = strtolower($fingerprint);
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
            return null;
        }
        return substr($hex, 0, self::SCOPE_HEX_LENGTH);
    }

    /**
     * Get existing scope fingerprint without creating a secret.
     * Side-effect free: returns null if secret is absent/invalid.
     *
     * @param string $api_key
     * @param bool   $is_test_mode
     * @return string|null Scope fingerprint or null.
     */
    public static function get_existing_scope_fingerprint($api_key, $is_test_mode) {
        $secret_result = self::read_existing_secret_record();
        if ($secret_result['state'] !== self::SECRET_VALID) {
            return null;
        }
        return self::derive_scope_fingerprint($api_key, $is_test_mode, $secret_result['record']);
    }

    /**
     * Get existing generation ID without creating a secret.
     * Side-effect free: returns null if secret is absent/invalid.
     *
     * @return string|null Generation ID or null.
     */
    public static function get_existing_generation_id() {
        $secret_result = self::read_existing_secret_record();
        if ($secret_result['state'] !== self::SECRET_VALID) {
            return null;
        }
        return $secret_result['record']['generation_id'];
    }

    public static function get_or_create_secret_record() {
        global $wpdb;

        // Use unique sentinel to distinguish missing from malformed.
        $missing = new \stdClass();
        $existing = get_option(self::SECRET_OPTION, $missing);

        if ($existing !== $missing) {
            if (is_array($existing) && self::is_valid_secret_record($existing)) {
                return $existing;
            }
            // existing but malformed: FAIL CLOSED
            return null;
        }

        // Only here is the option genuinely absent — proceed to check provenance.
        $blog_id = (string) get_current_blog_id();
        $meta_prefix = '_upay_customer_token_v2_b' . $blog_id . '_';
        $escaped_prefix = $wpdb->esc_like($meta_prefix);

        // Use $wpdb->query() for unambiguous row-count semantics.
        $row_count = $wpdb->query(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 1",
                $escaped_prefix . '%'
            )
        );

        if ($row_count === false) {
            return null; // DB failure
        }

        if ((int) $row_count > 0) {
            return null; // identity artifacts exist, secret is missing: FAIL CLOSED
        }

        // exactly 0 rows: safe fresh initialization candidate
        try {
            $secret = bin2hex(random_bytes(self::SECRET_BYTES));
            $generation_id = bin2hex(random_bytes(self::GENERATION_ID_BYTES));
            // Verifier binds domain + version + generation_id using secret as key.
            $verifier = hash_hmac('sha256', self::VERIFIER_DOMAIN . '|1|' . $generation_id, $secret);
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

        // Race: another worker created it. Read back and validate.
        $read_back = get_option(self::SECRET_OPTION, $missing);
        if ($read_back !== $missing && is_array($read_back) && self::is_valid_secret_record($read_back)) {
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

        // Verifier binds domain + version + generation_id.
        $expected_verifier = hash_hmac(
            'sha256',
            self::VERIFIER_DOMAIN . '|' . $record['version'] . '|' . $record['generation_id'],
            $record['secret']
        );

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
        // Section H: Strict blog-ID boundary — canonical positive decimal string.
        if (!is_string($blog_id) || !preg_match('/^[1-9][0-9]*$/', $blog_id)) {
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
    // READ PROVENANCE (authoritative, exactly-one-record)
    // ────────────────────────────────────────────────────────

    public static function read_provenance($user_id, $scope_fingerprint) {
        if ($user_id <= 0 || !self::is_valid_scope($scope_fingerprint)) {
            return array('state' => self::STATE_ABSENT, 'record' => null);
        }

        // Force-refresh user-meta cache before authoritative read.
        // Section T: Fail closed if refresh fails.
        if (!self::force_refresh_user_meta($user_id)) {
            return array('state' => self::STATE_INVALID, 'record' => null);
        }

        $blog_id = (string) get_current_blog_id();
        $meta_key = self::get_user_meta_key($blog_id, $scope_fingerprint);
        if ($meta_key === null) {
            return array('state' => self::STATE_INVALID, 'record' => null);
        }

        // Retrieve ALL values for exact user/meta key to detect duplicates.
        $all_values = get_user_meta($user_id, $meta_key, false);

        if (!is_array($all_values) || count($all_values) === 0) {
            return array('state' => self::STATE_ABSENT, 'record' => null);
        }

        if (count($all_values) > 1) {
            return array('state' => self::STATE_INVALID, 'record' => null);
        }

        $record = $all_values[0];

        if (!is_array($record)) {
            return array('state' => self::STATE_INVALID, 'record' => $record);
        }

        $validation = self::validate_provenance_record($record, $scope_fingerprint, true);
        if ($validation === 'valid') {
            return array('state' => self::STATE_VALID, 'record' => $record);
        }

        return array('state' => self::STATE_INVALID, 'record' => $record);
    }

    /**
     * Validate a provenance record structure.
     *
     * @param array  $record                     The provenance record.
     * @param string $requested_scope            The scope from the meta key.
     * @param bool   $require_current_generation Whether to require current generation match.
     * @return string 'valid', 'invalid', or 'generation_mismatch'
     */
    private static function validate_provenance_record($record, $requested_scope, $require_current_generation = true) {
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

        // Generation binding (optional for prior-provenance inspection).
        if ($require_current_generation) {
            // Use read-only generation helper — never create a secret.
            $current_generation = self::get_existing_generation_id();
            if ($current_generation === null || $record['secret_generation_id'] !== $current_generation) {
                return 'invalid';
            }
        } else {
            // Structural OK but check generation match explicitly.
            $current_generation = self::get_existing_generation_id();
            if ($current_generation !== null && $record['secret_generation_id'] !== $current_generation) {
                return 'generation_mismatch';
            }
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

        // Section T: Verify provenance persistence after creation.
        // Section U: Force refresh must succeed.
        if (!self::force_refresh_user_meta($user_id)) {
            return false;
        }
        $verify_values = get_user_meta($user_id, $meta_key, false);
        if (!is_array($verify_values) || count($verify_values) !== 1) {
            return false;
        }
        $verify_record = $verify_values[0];
        if (!is_array($verify_record)) {
            return false;
        }
        // Exact compare all fields.
        if (!isset($verify_record['version']) || $verify_record['version'] !== $record['version']) {
            return false;
        }
        if (!isset($verify_record['kind']) || $verify_record['kind'] !== $record['kind']) {
            return false;
        }
        if (!isset($verify_record['token']) || $verify_record['token'] !== $record['token']) {
            return false;
        }
        if (!isset($verify_record['source']) || $verify_record['source'] !== $record['source']) {
            return false;
        }
        if (!isset($verify_record['scope']) || $verify_record['scope'] !== $record['scope']) {
            return false;
        }
        if (!isset($verify_record['secret_generation_id']) || $verify_record['secret_generation_id'] !== $record['secret_generation_id']) {
            return false;
        }
        if (!isset($verify_record['established_at_gmt']) || $verify_record['established_at_gmt'] !== $record['established_at_gmt']) {
            return false;
        }

        // Section U: Run full structural validator with current-generation binding.
        if (self::validate_provenance_record($verify_record, $scope_fingerprint, true) !== 'valid') {
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

        if (!isset($transport['http_status']) || !is_int($transport['http_status'])) {
            $result['reason'] = 'transport_failure';
            return $result;
        }

        $http_status = $transport['http_status'];

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
    // FORCE-FRESH ORDER METADATA HELPER
    // ────────────────────────────────────────────────────────

    /**
     * Force a fresh metadata read from storage for security-sensitive decisions.
     *
     * @param object $order WC_Order instance.
     * @return bool true on success, false on failure.
     */
    public static function force_refresh_order_meta($order) {
        if (!$order || !method_exists($order, 'read_meta_data')) {
            return false;
        }
        try {
            $order->read_meta_data(true);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Force-refresh WordPress user-meta cache for a user.
     * Side-effect free: does not mutate provenance data.
     *
     * @param int $user_id Positive user ID.
     * @return bool true on success, false on failure.
     */
    public static function force_refresh_user_meta($user_id) {
        if ($user_id <= 0) {
            return false;
        }
        try {
            clean_user_cache($user_id);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ────────────────────────────────────────────────────────
    // HISTORICAL ORDER META CARDINALITY HELPER
    // ────────────────────────────────────────────────────────

    const META_ABSENT = 0;
    const META_EXACTLY_ONE = 1;
    const META_DUPLICATE_OR_INVALID = 2;

    /**
     * Check historical order metadata cardinality.
     *
     * Returns array with 'status' (META_ABSENT, META_EXACTLY_ONE, META_DUPLICATE_OR_INVALID)
     * and 'value' (the scalar value when exactly one, null otherwise).
     *
     * Uses WC_Order APIs only — no direct SQL.
     */
    public static function get_historical_meta_cardinality($order, $key) {
        if (!$order) {
            return array('status' => self::META_DUPLICATE_OR_INVALID, 'value' => null);
        }

        if (!$order->meta_exists($key)) {
            return array('status' => self::META_ABSENT, 'value' => null);
        }

        // Retrieve ALL matching metadata entries in raw/edit context.
        $all_meta = $order->get_meta($key, false, 'edit');

        // WooCommerce returns array of WC_Meta_Data objects when $single=false.
        if (!is_array($all_meta) || count($all_meta) !== 1) {
            return array('status' => self::META_DUPLICATE_OR_INVALID, 'value' => null);
        }

        // Extract value from the single WC_Meta_Data entry.
        $entry = $all_meta[0];
        if ($entry instanceof \WC_Meta_Data) {
            $value = $entry->get_value();
        } elseif (is_array($entry) && isset($entry['value'])) {
            $value = $entry['value'];
        } else {
            return array('status' => self::META_DUPLICATE_OR_INVALID, 'value' => null);
        }

        if (!is_scalar($value)) {
            return array('status' => self::META_DUPLICATE_OR_INVALID, 'value' => null);
        }

        return array('status' => self::META_EXACTLY_ONE, 'value' => $value);
    }

    // ────────────────────────────────────────────────────────
    // HISTORY INSPECTOR (paginated, trustworthy)
    // ────────────────────────────────────────────────────────

    public static function inspect_customer_history($user_id, $current_scope) {
        if ($user_id <= 0 || !self::is_valid_scope($current_scope)) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'invalid_input');
        }

        // Use read-only generation helper — never create a secret.
        $current_generation = self::get_existing_generation_id();
        if ($current_generation === null) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'no_generation');
        }

        $scanned_unique_count = 0;
        $seen_order_ids = array();
        $found_tokens = array();
        $page = 1;
        $expected_total = null;
        $expected_max_pages = null;

        $has_generation_mismatch = false;
        $has_malformed = false;
        $has_unscoped = false;
        $has_current_scope_orphan = false;
        $has_prior_scope_same_gen = false;
        $has_card_without_identity = false;

        while ($scanned_unique_count < self::HISTORY_MAX_ORDERS) {
            try {
                $orders = wc_get_orders(array(
                    'type' => 'shop_order',
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
            $current_max_pages = (int) $orders->max_num_pages;

            if ($expected_total === null) {
                $expected_total = $current_total;
                $expected_max_pages = $current_max_pages;
            } else {
                if ($current_total !== $expected_total) {
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'total_changed');
                }
                if ($current_max_pages !== $expected_max_pages) {
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'max_pages_changed');
                }
            }

            // Page size check.
            if (count($orders->orders) > self::HISTORY_PAGE_SIZE) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'oversized_page');
            }

            // Page number must not continue beyond expected_max_pages.
            if ($expected_max_pages !== null && $page > $expected_max_pages && !empty($orders->orders)) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'page_beyond_max');
            }

            // Scanned count must not exceed expected_total.
            if ($expected_total !== null && $scanned_unique_count + count($orders->orders) > $expected_total) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'scanned_exceeds_total');
            }

            // Unexpected empty page.
            if (empty($orders->orders) && $scanned_unique_count < $expected_total) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'unexpected_empty_page');
            }

            // expected_total=0 must be compatible with empty result.
            if ($expected_total === 0 && empty($orders->orders)) {
                break;
            }

            if (empty($orders->orders)) {
                break;
            }

            foreach ($orders->orders as $order_id) {
                $order_id_int = (int) $order_id;
                if ($order_id_int <= 0) {
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'invalid_order_id');
                }

                // Duplicate ID across pages.
                if (isset($seen_order_ids[$order_id_int])) {
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'duplicate_order_id');
                }
                $seen_order_ids[$order_id_int] = true;
                $scanned_unique_count++;

                $order = wc_get_order($order_id_int);
                if (!$order) {
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'unloadable_order');
                }

                if (!self::force_refresh_order_meta($order)) {
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'force_refresh_failed');
                }

                // Section K: Inspect ALL five security keys up front.
                $token_card = self::get_historical_meta_cardinality($order, '_upay_customer_unique_token');
                $kind_card = self::get_historical_meta_cardinality($order, '_upay_customer_token_kind_v1');
                $scope_card = self::get_historical_meta_cardinality($order, '_upay_customer_token_scope_v1');
                $gen_card = self::get_historical_meta_cardinality($order, '_upay_customer_token_generation_v1');
                $card_card = self::get_historical_meta_cardinality($order, '_upay_credit_card_token');

                // Section P: Check duplicate/invalid cardinality BEFORE presence logic.
                if ($token_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $kind_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $scope_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $gen_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $card_card['status'] === self::META_DUPLICATE_OR_INVALID
                ) {
                    $has_malformed = true;
                    continue;
                }

                $has_customer_token = ($token_card['status'] === self::META_EXACTLY_ONE);
                $token_str = $has_customer_token ? (string) $token_card['value'] : '';
                $token_is_empty = ($token_str === '');
                $token_is_valid_grammar = (!$token_is_empty && preg_match('/^[0-9]{8,18}$/', $token_str));

                $has_kind = ($kind_card['status'] === self::META_EXACTLY_ONE);
                $has_scope = ($scope_card['status'] === self::META_EXACTLY_ONE);
                $has_generation = ($gen_card['status'] === self::META_EXACTLY_ONE);

                // Section L: Orphan snapshot fields without customer token.
                if ((!$has_customer_token || $token_is_empty) && ($has_kind || $has_scope || $has_generation)) {
                    $has_malformed = true;
                    continue;
                }

                // No customer token and no snapshot fields — check card-only.
                if (!$has_customer_token || $token_is_empty) {
                    if ($card_card['status'] === self::META_EXACTLY_ONE && (string) $card_card['value'] !== '') {
                        $has_card_without_identity = true;
                    }
                    continue;
                }

                // Nonempty token — validate basic grammar.
                if (!$token_is_valid_grammar) {
                    $has_malformed = true;
                    continue;
                }

                // Snapshot presence matrix.
                $all_three_present = $has_kind && $has_scope && $has_generation;
                $all_three_absent = !$has_kind && !$has_scope && !$has_generation;

                if (!$all_three_present && !$all_three_absent) {
                    $has_malformed = true;
                    continue;
                }

                if ($all_three_absent) {
                    $has_unscoped = true;
                    continue;
                }

                // All three present — validate.
                $kind_str = (string) $kind_card['value'];
                $scope_str = (string) $scope_card['value'];
                $gen_str = (string) $gen_card['value'];

                if ($kind_str === '' || $scope_str === '' || $gen_str === '') {
                    $has_malformed = true;
                    continue;
                }

                // Check for duplicate snapshot metadata.
                if ($kind_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $scope_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $gen_card['status'] === self::META_DUPLICATE_OR_INVALID
                ) {
                    $has_malformed = true;
                    continue;
                }

                $valid_kinds = array(self::KIND_CANONICAL, self::KIND_LEGACY_COMPAT);
                if (!in_array($kind_str, $valid_kinds, true)) {
                    $has_malformed = true;
                    continue;
                }

                if (!self::is_valid_token_for_kind($token_str, $kind_str)) {
                    $has_malformed = true;
                    continue;
                }

                if (!self::is_valid_scope($scope_str)) {
                    $has_malformed = true;
                    continue;
                }

                if (!self::is_valid_hex($gen_str, self::GENERATION_ID_HEX_LENGTH)) {
                    $has_malformed = true;
                    continue;
                }

                if ($gen_str !== $current_generation) {
                    $has_generation_mismatch = true;
                    continue;
                }

                if ($scope_str === $current_scope) {
                    $has_current_scope_orphan = true;
                } else {
                    $has_prior_scope_same_gen = true;
                }
            }

            $page++;

            if ($scanned_unique_count >= $expected_total) {
                break;
            }
        }

        // Completeness: genuinely scanned all expected orders.
        // Safety cap does NOT mean scan was complete — only expected_total <= cap AND scanned >= expected_total.
        $is_complete = ($expected_total !== null)
            && ($expected_total <= self::HISTORY_MAX_ORDERS)
            && ($scanned_unique_count >= $expected_total);

        // If scan is incomplete and no definitive blocker found yet: INDETERMINATE.
        if (!$is_complete
            && !$has_generation_mismatch
            && !$has_malformed
            && !$has_card_without_identity
            && !$has_unscoped
            && !$has_current_scope_orphan
        ) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'incomplete_scan');
        }

        // Blocker precedence (Section Q).
        if ($has_generation_mismatch) {
            return array('classification' => self::HISTORY_SECRET_GENERATION_MISMATCH, 'reason' => 'generation_mismatch');
        }

        if ($has_malformed) {
            return array('classification' => self::HISTORY_MALFORMED_SCOPED, 'reason' => 'malformed_snapshot');
        }

        if ($has_card_without_identity) {
            return array('classification' => self::HISTORY_CARD_WITHOUT_CUSTOMER_IDENTITY, 'reason' => 'card_without_customer_identity');
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

        if ($scanned_unique_count === 0) {
            return array('classification' => self::HISTORY_NONE, 'reason' => 'no_tokens_found');
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

        // Use read-only generation helper — never create a secret.
        $current_generation = self::get_existing_generation_id();
        if ($current_generation === null) {
            return array('state' => 'read_failure', 'reason' => 'no_generation');
        }

        // Force-refresh user-meta cache before authoritative read.
        // Section T: Fail closed if refresh fails.
        if (!self::force_refresh_user_meta($user_id)) {
            return array('state' => 'read_failure', 'reason' => 'usermeta_refresh_failed');
        }

        global $wpdb;
        $blog_id = (string) get_current_blog_id();
        $meta_prefix = '_upay_customer_token_v2_b' . $blog_id . '_';
        $escaped_prefix = $wpdb->esc_like($meta_prefix);

        // Use $wpdb->query() for unambiguous DB-error semantics (Section G).
        $query_result = $wpdb->query(
            $wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
                $user_id,
                $escaped_prefix . '%'
            )
        );

        if ($query_result === false) {
            return array('state' => 'read_failure', 'reason' => 'db_query_failed');
        }

        if ((int) $query_result === 0) {
            return array('state' => 'none', 'reason' => 'no_provenance_records');
        }

        $meta_keys = $wpdb->get_col(null);

        if (!is_array($meta_keys) || count($meta_keys) !== (int) $query_result) {
            return array('state' => 'read_failure', 'reason' => 'db_result_inconsistent');
        }

        $has_generation_mismatch = false;
        $has_invalid = false;

        foreach ($meta_keys as $meta_key) {
            $scope_from_key = substr($meta_key, strlen($meta_prefix));
            if (!self::is_valid_scope($scope_from_key)) {
                $has_invalid = true;
                continue;
            }

            // Retrieve ALL values for exact meta key to detect duplicates (Section H).
            $all_values = get_user_meta($user_id, $meta_key, false);

            if (!is_array($all_values) || count($all_values) === 0) {
                $has_invalid = true;
                continue;
            }

            if (count($all_values) > 1) {
                $has_invalid = true;
                continue;
            }

            $record = $all_values[0];

            if (!is_array($record)) {
                $has_invalid = true;
                continue;
            }

            // Structural validation without requiring current generation (Section F).
            $validation = self::validate_provenance_record($record, $scope_from_key, false);
            if ($validation === 'invalid') {
                $has_invalid = true;
                continue;
            }

            if ($validation === 'generation_mismatch') {
                $has_generation_mismatch = true;
                continue;
            }

            // validation === 'valid': structurally OK, generation matches.
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

    /**
     * Validate the runtime token context tuple.
     *
     * For ordinary (null-token) payments, all four token fields must be null.
     * For tokenized payments, all fields must be valid and match expected values.
     *
     * @param string|null $token              The customer unique token.
     * @param string|null $kind               The token kind.
     * @param string|null $scope              The scope fingerprint.
     * @param string|null $generation_id      The secret generation ID.
     * @param string      $expected_scope     The authoritative expected scope.
     * @param string      $expected_generation The authoritative expected generation.
     * @return bool
     */
    public static function validate_token_runtime_context($token, $kind, $scope, $generation_id, $expected_scope, $expected_generation) {
        // Null token = ordinary payment, all four must be null.
        if ($token === null) {
            return ($kind === null && $scope === null && $generation_id === null);
        }

        // Non-null token requires all fields.
        if ($kind === null || $scope === null || $generation_id === null) {
            return false;
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

        // Validate expected values.
        if (!self::is_valid_scope($expected_scope)) {
            return false;
        }

        if (!self::is_valid_hex($expected_generation, self::GENERATION_ID_HEX_LENGTH)) {
            return false;
        }

        if ($scope !== $expected_scope) {
            return false;
        }

        if ($generation_id !== $expected_generation) {
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

        // === Read-only preflight: do NOT mutate identity state before confirming
        // the historical order evidence will not block canonical establishment. ===
        $preflight_history = self::inspect_customer_history($user_id, '__UPAY_PREFLIGHT_SCOPE__');
        $preflight_prior = self::inspect_current_user_prior_provenance($user_id);
        $preflight_blocking_states = array(
            self::HISTORY_INDETERMINATE,
            self::HISTORY_UNSCOPED_LEGACY,
            self::HISTORY_MALFORMED_SCOPED,
            self::HISTORY_CURRENT_SCOPE_ORPHAN,
            self::HISTORY_SECRET_GENERATION_MISMATCH,
            self::HISTORY_CARD_WITHOUT_CUSTOMER_IDENTITY,
            self::HISTORY_PRIOR_SCOPE_ONLY,
        );
        if (in_array($preflight_history['classification'], $preflight_blocking_states, true)
            || $preflight_history['classification'] !== self::HISTORY_NONE
        ) {
            $result['reason'] = 'legacy_migration_required';
            return $result;
        }
        if ($preflight_prior['state'] === 'read_failure') {
            $result['reason'] = 'read_failure';
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
            self::HISTORY_CARD_WITHOUT_CUSTOMER_IDENTITY,
            self::HISTORY_PRIOR_SCOPE_ONLY,
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

            // HISTORY_PRIOR_SCOPE_ONLY is in $blocking_states above. After the lock
            // we simply check $blocking_states again. If somehow a new classification
            // were added in the future that is not in $blocking_states, this guard
            // rejects any non-HISTORY_NONE outcome as legacy-migration-required.
            if ($history['classification'] !== self::HISTORY_NONE) {
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

        // Use read-only scope helper — never create a secret.
        $scope = self::get_existing_scope_fingerprint($api_key, $is_test_mode);
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

        // Use read-only generation helper — never create a secret.
        $generation_id = self::get_existing_generation_id();
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

        // Section I: Force-fresh before making any decision.
        if (!self::force_refresh_order_meta($order)) {
            return false;
        }

        $keys = array(
            '_upay_customer_unique_token',
            '_upay_customer_token_kind_v1',
            '_upay_customer_token_scope_v1',
            '_upay_customer_token_generation_v1',
        );

        // All 4 keys must exist.
        foreach ($keys as $key) {
            if (!$order->meta_exists($key)) {
                return true; // partial — preserve
            }
        }

        // Each key must have exactly one value (edit context).
        foreach ($keys as $key) {
            $vals = $order->get_meta($key, false, 'edit');
            if (!is_array($vals) || count($vals) !== 1) {
                return true; // duplicate — preserve
            }
        }

        // Card-token cardinality check (Section G).
        $card_card = self::get_historical_meta_cardinality($order, '_upay_credit_card_token');
        if ($card_card['status'] === self::META_DUPLICATE_OR_INVALID) {
            return true; // malformed card token — preserve all
        }

        $kind = $order->get_meta('_upay_customer_token_kind_v1', true, 'edit');
        $scope = $order->get_meta('_upay_customer_token_scope_v1', true, 'edit');
        $generation = $order->get_meta('_upay_customer_token_generation_v1', true, 'edit');
        $token = $order->get_meta('_upay_customer_unique_token', true, 'edit');

        if (!is_scalar($kind) || !is_scalar($scope) || !is_scalar($generation) || !is_scalar($token)) {
            return true; // non-scalar — preserve
        }

        $valid_kinds = array(self::KIND_CANONICAL, self::KIND_LEGACY_COMPAT);
        if (!in_array((string) $kind, $valid_kinds, true)) {
            return true; // unknown kind — preserve
        }

        if (!self::is_valid_token_for_kind($token, (string) $kind)) {
            return true; // malformed token — preserve
        }

        if (!self::is_valid_scope((string) $scope)) {
            return true; // malformed scope — preserve
        }

        if (!self::is_valid_hex((string) $generation, self::GENERATION_ID_HEX_LENGTH)) {
            return true; // malformed generation — preserve
        }

        // Use read-only secret access (Section H) — never create a secret.
        $secret_result = self::read_existing_secret_record();
        if ($secret_result['state'] !== self::SECRET_VALID) {
            return true; // secret absent/invalid — preserve evidence
        }

        if ((string) $generation !== $secret_result['record']['generation_id']) {
            return true; // different generation — preserve
        }

        // Fully valid current-generation PR16 snapshot — safe to clear.
        try {
            foreach ($keys as $key) {
                $order->delete_meta_data($key);
            }
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
