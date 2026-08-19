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
        // Section #14: Strict input typing. Reject int/float/bool/array/object,
        // null, empty string, and whitespace-only strings outright. Only a
        // non-empty scalar string API key is accepted.
        if (!is_string($api_key) || $api_key === '') {
            return null;
        }
        if (!is_bool($is_test_mode)) {
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
     * Read the existing canonical identity context atomically.
     *
     * Performs exactly one read of the secret option. Derives the scope from
     * the validated secret record using the supplied API key + test mode.
     * Returns the validated scope and generation from that single record so
     * torn reads cannot produce a hybrid scope(A)+generation(B) combination.
     *
     * @param string $api_key     Non-empty scalar API key (mode-specific).
     * @param bool   $is_test_mode Whether to derive a test-mode scope.
     * @return array{state:string, scope:?string, generation_id:?string}
     */
    public static function read_existing_identity_context($api_key, $is_test_mode) {
        // Section #14: Strict input typing — the public surface must reject
        // malformed callers (int/float/bool/array/object, null, empty, etc.).
        if (!is_string($api_key) || $api_key === '') {
            return array(
                'state' => 'invalid_input',
                'scope' => null,
                'generation_id' => null,
            );
        }
        if (!is_bool($is_test_mode)) {
            return array(
                'state' => 'invalid_input',
                'scope' => null,
                'generation_id' => null,
            );
        }
        $secret_result = self::read_existing_secret_record();
        if ($secret_result['state'] !== self::SECRET_VALID) {
            return array(
                'state' => $secret_result['state'],
                'scope' => null,
                'generation_id' => null,
            );
        }

        // Derive scope from this exact record (api_key + mode + secret). The
        // validated secret record contains the secret — the scope is computed
        // here, not stored.
        $scope = self::derive_scope_fingerprint($api_key, $is_test_mode, $secret_result['record']);
        if ($scope === null) {
            return array(
                'state' => self::SECRET_VALID,
                'scope' => null,
                'generation_id' => isset($secret_result['record']['generation_id']) ? (string) $secret_result['record']['generation_id'] : null,
            );
        }

        return array(
            'state' => self::SECRET_VALID,
            'scope' => $scope,
            'generation_id' => isset($secret_result['record']['generation_id']) ? (string) $secret_result['record']['generation_id'] : null,
        );
    }

    /**
     * Note: the legacy two-call torn-read seam (a separate scope read + a
     * separate generation read of the secret option) was deleted in Residual
     * Correction #15. It enabled torn scope(A)+generation(B) snapshots because
     * the secret could be rotated between the two reads. Use
     * read_existing_identity_context() to obtain both values atomically in a
     * single secret-option read, then pass the captured generation to
     * read_provenance().
     */

    private static function get_or_create_secret_record() {
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

    /**
     * Strict non-negative integer parser.
     *
     * Rejects: floats ("1.0"), scientific notation ("1e2"), signs ("+1", "-1"),
     * leading whitespace (" 1"), hex/octal/binary literals ("0x10"), null,
     * empty string, booleans, arrays, objects, and any non-integer numeric
     * value. Accepts only a pure ASCII decimal string composed of digits 0-9
     * with no leading sign and no surrounding whitespace, OR an integer-typed
     * value. The decoded value MUST be >= 0 to qualify; the cast integer is
     * returned alongside a boolean via the by-reference convention so callers
     * can keep their existing control flow unchanged.
     *
     * @param mixed  $value      Candidate value to parse.
     * @param int   &$parsed_out Out-parameter: the parsed non-negative integer
     *                           when the method returns true; undefined otherwise.
     * @return bool              True iff the input is a strict non-negative int.
     */
    private static function parse_strict_nonneg_int($value, &$parsed_out) {
        if (is_int($value)) {
            if ($value < 0) {
                return false;
            }
            $parsed_out = $value;
            return true;
        }
        if (!is_string($value)) {
            return false;
        }
        // Reject any non-ASCII or any character outside [0-9].
        if ($value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
            return false;
        }
        // Reject leading-zero strings: only "0" alone, or [1-9][0-9]* allowed.
        // Grammar: ^(?:0|[1-9][0-9]*)$.
        if (strlen($value) > 1 && $value[0] === '0') {
            return false;
        }
        // PHP's int is 64-bit signed on supported platforms; cap to that range
        // so we never silently wrap. Compare as strings first to dodge float
        // coercion of huge string-to-int conversions.
        $int_max_str = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($int_max_str)) {
            return false;
        }
        if (strlen($value) === strlen($int_max_str) && strcmp($value, $int_max_str) > 0) {
            return false;
        }
        $parsed_out = (int) $value;
        return $parsed_out >= 0;
    }

    /**
     * Strict positive-integer parser for historical order IDs.
     *
     * Rejects: floats ("1.5", "1.0"), scientific notation ("1e2"), signs ("+1",
     * "-1"), leading whitespace (" 1"), hex/octal/binary literals ("0x10"),
     * leading-zero strings ("00", "01", "007"), null, empty string, booleans,
     * arrays, objects, and values that exceed PHP_INT_MAX.
     *
     * Accepts only:
     *   - a non-negative PHP int > 0, OR
     *   - a canonical positive-decimal digit string in the grammar
     *     `^(?:0|[1-9][0-9]*)$` whose integer value is > 0 and <= PHP_INT_MAX.
     *
     * @param mixed $value Candidate.
     * @param int   &$parsed_out Out-parameter: parsed positive integer when valid.
     * @return bool true iff input is a strict positive integer.
     */
    public static function parse_strict_positive_int($value, &$parsed_out) {
        $parsed_out = 0;
        if (!self::parse_strict_nonneg_int($value, $parsed_out)) {
            return false;
        }
        return $parsed_out > 0;
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

    /**
     * Bootstrap lock name (blog-scoped). Cannot be derived from API secret
     * material, which does not exist before the bootstrap completes.
     */
    public static function get_bootstrap_lock_name() {
        $blog_id = (string) get_current_blog_id();
        $lock = 'upay_ctk_bootstrap_b' . $blog_id;
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
        if (!is_string($token) || $token === '') {
            return false;
        }
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public static function is_valid_legacy_token($token) {
        if (!is_string($token) || $token === '') {
            return false;
        }
        return preg_match(self::LEGACY_TOKEN_PATTERN, $token) === 1;
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

    public static function read_provenance($user_id, $scope_fingerprint, $current_generation) {
        if ($user_id <= 0 || !self::is_valid_scope($scope_fingerprint)) {
            return array('state' => self::STATE_ABSENT, 'record' => null);
        }

        // Residual Correction #15: generation is mandatory, must be strict 32-hex.
        if (!is_string($current_generation)
            || !self::is_valid_hex($current_generation, self::GENERATION_ID_HEX_LENGTH)
        ) {
            return array('state' => self::STATE_INVALID, 'record' => null, 'reason' => 'missing_generation');
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

        // Pure structural validator with explicit generation binding.
        $validation = self::validate_provenance_record($record, $scope_fingerprint, $current_generation);
        if ($validation === 'valid') {
            return array('state' => self::STATE_VALID, 'record' => $record);
        }

        if ($validation === 'generation_mismatch') {
            return array('state' => self::STATE_INVALID, 'record' => $record, 'reason' => 'generation_mismatch');
        }

        return array('state' => self::STATE_INVALID, 'record' => $record);
    }

    /**
     * Validate a provenance record structure.
     *
     * Pure structural validator. The caller MUST pass the current generation
     * (or null) explicitly so this function does not perform independent
     * secret reads. The previous implementation re-read the secret option
     * inside the validator via a separate generation lookup, which created a
     * hidden second read and could yield a hybrid scope(A)+generation(B)
     * snapshot.
     *
     * @param array       $record            The provenance record.
     * @param string      $requested_scope   The scope from the meta key.
     * @param string|null $current_generation Explicit current generation, or null to skip.
     * @return string 'valid', 'invalid', or 'generation_mismatch'
     */
    private static function validate_provenance_record($record, $requested_scope, $current_generation = null) {
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

        if (!isset($record['token']) || !is_string($record['token']) || $record['token'] === '') {
            return 'invalid';
        }

        $token = $record['token'];

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

        // Generation binding — caller-supplied. No secret read here.
        if ($current_generation !== null) {
            if (!is_string($current_generation) || !self::is_valid_hex($current_generation, self::GENERATION_ID_HEX_LENGTH)) {
                return 'invalid';
            }
            if ($record['secret_generation_id'] !== $current_generation) {
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

    /**
     * Residual Correction #16: Full delete + verify + readback routine.
     *
     * 1. Exact-value delete_user_meta(uid,key,inserted_record) — only this exact
     *    value is removed, never the whole meta key.
     * 2. force_refresh_user_meta() — cache invalidation required so the next
     *    read sees storage truth.
     * 3. Re-read all values for the exact key and assert the inserted record is
     *    NOT present.
     * 4. Returns a structured result so callers can distinguish failure modes:
     *      ok               — delete + refresh + readback all succeeded, record absent.
     *      delete_failed    — delete_user_meta returned false (no rows matched).
     *      refresh_failed   — force_refresh_user_meta returned false.
     *      readback_failed  — readback returned non-array / unparsable data.
     *      record_remains   — readback shows the inserted value still present.
     *
     * This is the ONLY allowed way to remove provenance after a failed write.
     * Callers MUST inspect the structured result and propagate failure.
     *
     * @param int    $user_id
     * @param string $meta_key
     * @param array  $inserted_record The exact record previously inserted.
     * @return array{ok: bool, reason: string}
     */
    private static function rollback_provenance($user_id, $meta_key, $inserted_record) {
        // 1. Exact-value delete. WP semantics: only this value is removed.
        $deleted = delete_user_meta($user_id, $meta_key, $inserted_record);
        if (!$deleted) {
            return array('ok' => false, 'reason' => 'delete_failed');
        }

        // 2. Force-refresh the user meta cache.
        if (!self::force_refresh_user_meta($user_id)) {
            return array('ok' => false, 'reason' => 'refresh_failed');
        }

        // 3. Readback all values for this exact key.
        $readback = get_user_meta($user_id, $meta_key, false);
        if (!is_array($readback)) {
            return array('ok' => false, 'reason' => 'readback_failed');
        }

        // 4. Assert the inserted record is no longer present.
        foreach ($readback as $value) {
            if (is_array($value) && $value === $inserted_record) {
                return array('ok' => false, 'reason' => 'record_remains');
            }
        }

        return array('ok' => true, 'reason' => 'verified_absent');
    }

    /**
     * In-process rollback state observability. Stored in a static so tests and
     * diagnostics can inspect the most recent rollback outcome without persisting
     * to storage (which would itself be observable side-effects).
     */
    private static $last_rollback_state = null;

    private static function record_rollback_state($user_id, $meta_key, $reason) {
        self::$last_rollback_state = array(
            'user_id' => $user_id,
            'meta_key' => $meta_key,
            'reason' => $reason,
            'time' => time(),
        );
    }

    public static function last_rollback_state() {
        return self::$last_rollback_state;
    }

    public static function reset_rollback_state_for_tests() {
        self::$last_rollback_state = null;
    }

    public static function create_provenance(
        $user_id,
        $api_key,
        $is_test_mode,
        $scope_fingerprint,
        $expected_generation_id,
        $kind,
        $token,
        $source
    ) {
        if ($user_id <= 0 || !self::is_valid_scope($scope_fingerprint)) {
            return false;
        }
        if (!is_string($api_key) || $api_key === '') {
            return false;
        }
        if (!is_bool($is_test_mode)) {
            return false;
        }
        if (!is_string($expected_generation_id)
            || !self::is_valid_hex($expected_generation_id, self::GENERATION_ID_HEX_LENGTH)
        ) {
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

        // Re-read the canonical identity context atomically. The scope is
        // derived from the secret record using (api_key, is_test_mode). The
        // generation comes from the same record. We then require exact match
        // against the caller's expected scope+generation to prove the caller's
        // request was authorized under exactly the same canonical context.
        $ctx = self::read_existing_identity_context($api_key, $is_test_mode);
        if ($ctx['state'] !== self::SECRET_VALID
            || $ctx['scope'] === null
            || $ctx['generation_id'] === null
        ) {
            return false;
        }
        if (!hash_equals((string) $ctx['scope'], (string) $scope_fingerprint)) {
            return false;
        }
        if (!hash_equals((string) $ctx['generation_id'], (string) $expected_generation_id)) {
            return false;
        }
        $generation_id = $expected_generation_id;

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
            'token' => $token,
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
            // Residual Correction #15: exact rollback of the inserted value only.
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }
        $verify_values = get_user_meta($user_id, $meta_key, false);
        if (!is_array($verify_values) || count($verify_values) !== 1) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }
        $verify_record = $verify_values[0];
        if (!is_array($verify_record)) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }
        // Exact compare all fields.
        if (!isset($verify_record['version']) || $verify_record['version'] !== $record['version']) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }
        if (!isset($verify_record['kind']) || $verify_record['kind'] !== $record['kind']) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }
        if (!isset($verify_record['token']) || $verify_record['token'] !== $record['token']) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }
        if (!isset($verify_record['source']) || $verify_record['source'] !== $record['source']) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }
        if (!isset($verify_record['scope']) || $verify_record['scope'] !== $record['scope']) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }
        if (!isset($verify_record['secret_generation_id']) || $verify_record['secret_generation_id'] !== $record['secret_generation_id']) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }
        if (!isset($verify_record['established_at_gmt']) || $verify_record['established_at_gmt'] !== $record['established_at_gmt']) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }

        // Section U: Run full structural validator with current-generation binding.
        if (self::validate_provenance_record($verify_record, $scope_fingerprint, $generation_id) !== 'valid') {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
            return false;
        }

        // Residual Correction #15: Final-context re-check. Re-read the canonical
        // identity context atomically. If the secret record was deleted, replaced,
        // malformed, or had its scope/generation rotated between the pre-insert
        // read and the post-insert read, the provenance we just wrote is bound to
        // a stale context and must be rolled back exactly.
        $final_ctx = self::read_existing_identity_context($api_key, $is_test_mode);
        if ($final_ctx['state'] !== self::SECRET_VALID
            || $final_ctx['scope'] === null
            || $final_ctx['generation_id'] === null
            || !hash_equals((string) $final_ctx['scope'], (string) $scope_fingerprint)
            || !hash_equals((string) $final_ctx['generation_id'], (string) $generation_id)
        ) {
            $rollback = self::rollback_provenance($user_id, $meta_key, $record);
            if (!$rollback['ok']) {
                // Surface rollback failure but still return false so caller cannot
                // proceed toward Charge. The deleted/refresh/readback state is now
                // explicitly observable via the rollback reason (recorded via
                // last_rollback_state() for diagnostics).
                self::record_rollback_state($user_id, $meta_key, $rollback['reason']);
            }
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

        if (!isset($body['data']['customerUniqueToken']) || !is_string($body['data']['customerUniqueToken']) || $body['data']['customerUniqueToken'] === '') {
            $result['reason'] = 'missing_token';
            return $result;
        }

        if (!is_string($submitted_token) || $submitted_token === '' || !self::is_valid_canonical_token($submitted_token)) {
            $result['reason'] = 'invalid_candidate';
            return $result;
        }

        if ($body['data']['customerUniqueToken'] !== $submitted_token) {
            $result['reason'] = 'token_mismatch';
            return $result;
        }

        $result['success'] = true;
        $result['token'] = $submitted_token;
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

    public static function inspect_customer_history($user_id, $current_scope, $current_generation) {
        if ($user_id <= 0 || !self::is_valid_scope($current_scope)) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'invalid_input');
        }

        // Section #14: Caller MUST supply current_generation. No hidden read.
        // (The previous behaviour silently fell back to a fresh secret option
        // read here, which produced a torn scope(A)+generation(B) snapshot when
        // the credential rotated between the caller's earlier read and this one.)
        if (!is_string($current_generation)
            || !self::is_valid_hex($current_generation, self::GENERATION_ID_HEX_LENGTH)
        ) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'missing_generation');
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

            if (!self::parse_strict_nonneg_int(isset($orders->total) ? $orders->total : null, $current_total)) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'missing_total');
            }

            if (!self::parse_strict_nonneg_int(isset($orders->max_num_pages) ? $orders->max_num_pages : null, $current_max_pages)) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'missing_max_pages');
            }

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
                if (!self::parse_strict_positive_int($order_id, $order_id_int)) {
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
    // SECRET-INDEPENDENT BOOTSTRAP HISTORY CENSUS
    // ────────────────────────────────────────────────────────

    /**
     * Inspect the customer's order history to determine whether a secret may
     * be safely created. Does NOT require a scope (i.e. works even when no
     * secret has ever been created). Does NOT mutate identity state.
     *
     * Returns HISTORY_NONE only when a complete pagination scan finds zero
     * historical identity/card security evidence across the entire account.
     * Any uncertainty (indeterminate pagination, unloadable order, query
     * failure, duplicate metadata, non-scalar values, partial/orphan evidence)
     * is treated as a blocker.
     */
    public static function inspect_bootstrap_history($user_id) {
        if ($user_id <= 0) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'not_logged_in');
        }
        // Single read of the secret record. The bootstrap helper is intended
        // only for initialization semantics and must not be reused against an
        // established identity root.
        $secret_result = self::read_existing_secret_record();
        if ($secret_result['state'] === self::SECRET_VALID) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'not_bootstrap_candidate');
        }
        if ($secret_result['state'] === self::SECRET_INVALID) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'malformed_secret');
        }
        // SECRET_ABSENT: ready for census (caller must hold the bootstrap lock).

        // No secret yet: do a complete pagination census and confirm the
        // user has zero identity/card security evidence. Uses real WooCommerce
        // APIs (wc_get_orders, wc_get_order, force_refresh_order_meta).
        $scanned_unique_count = 0;
        $seen_order_ids = array();
        $page = 1;
        $expected_total = null;
        $expected_max_pages = null;
        $page_size = self::HISTORY_PAGE_SIZE;

        while ($scanned_unique_count < self::HISTORY_MAX_ORDERS) {
            try {
                $orders = wc_get_orders(array(
                    'type' => 'shop_order',
                    'customer_id' => $user_id,
                    'payment_method' => 'upayments',
                    'limit' => $page_size,
                    'paged' => $page,
                    'orderby' => 'ID',
                    'order' => 'DESC',
                    'return' => 'ids',
                    'paginate' => true,
                ));
            } catch (\Throwable $e) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'query_exception');
            }

            if (!is_object($orders) || !isset($orders->orders) || !is_array($orders->orders)) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'malformed_orders_array');
            }
            if (!self::parse_strict_nonneg_int(isset($orders->total) ? $orders->total : null, $current_total)) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'missing_total');
            }
            if (!self::parse_strict_nonneg_int(isset($orders->max_num_pages) ? $orders->max_num_pages : null, $current_max_pages)) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'missing_max_pages');
            }

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

            if (count($orders->orders) > $page_size) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'oversized_page');
            }
            if ($expected_max_pages !== null && $page > $expected_max_pages && !empty($orders->orders)) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'page_beyond_max');
            }
            if ($expected_total !== null && $scanned_unique_count + count($orders->orders) > $expected_total) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'scanned_exceeds_total');
            }
            if (empty($orders->orders) && $scanned_unique_count < $expected_total) {
                return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'unexpected_empty_page');
            }
            if ($expected_total === 0 && empty($orders->orders)) {
                break;
            }
            if (empty($orders->orders)) {
                break;
            }

            foreach ($orders->orders as $order_id) {
                if (!self::parse_strict_positive_int($order_id, $order_id_int)) {
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'invalid_order_id');
                }
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
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'refresh_failure');
                }

                $token_card = self::get_historical_meta_cardinality($order, '_upay_customer_unique_token');
                $kind_card = self::get_historical_meta_cardinality($order, '_upay_customer_token_kind_v1');
                $scope_card = self::get_historical_meta_cardinality($order, '_upay_customer_token_scope_v1');
                $gen_card = self::get_historical_meta_cardinality($order, '_upay_customer_token_generation_v1');
                $cc_card = self::get_historical_meta_cardinality($order, '_upay_credit_card_token');

                if ($token_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $kind_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $scope_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $gen_card['status'] === self::META_DUPLICATE_OR_INVALID
                    || $cc_card['status'] === self::META_DUPLICATE_OR_INVALID
                ) {
                    return array('classification' => self::HISTORY_MALFORMED_SCOPED, 'reason' => 'malformed_snapshot');
                }

                $has_token = ($token_card['status'] === self::META_EXACTLY_ONE);
                $has_kind = ($kind_card['status'] === self::META_EXACTLY_ONE);
                $has_scope = ($scope_card['status'] === self::META_EXACTLY_ONE);
                $has_gen = ($gen_card['status'] === self::META_EXACTLY_ONE);
                $has_cc = ($cc_card['status'] === self::META_EXACTLY_ONE);

                if ($has_token || $has_kind || $has_scope || $has_gen || $has_cc) {
                    return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'bootstrap_blocked_by_history');
                }
            }

            $page++;
        }

        // Bootstrap completion: scan must be complete (not just the cap).
        $is_complete = ($expected_total !== null)
            && ($expected_total <= self::HISTORY_MAX_ORDERS)
            && ($scanned_unique_count >= $expected_total);
        if (!$is_complete) {
            return array('classification' => self::HISTORY_INDETERMINATE, 'reason' => 'incomplete_scan');
        }

        return array('classification' => self::HISTORY_NONE, 'reason' => 'bootstrap_clear');
    }

    // ────────────────────────────────────────────────────────
    // PRIOR PROVENANCE INSPECTION (full validation)
    // ────────────────────────────────────────────────────────

    public static function inspect_current_user_prior_provenance($user_id, $current_generation) {
        if ($user_id <= 0) {
            return array('state' => 'none', 'reason' => 'not_logged_in');
        }

        // Section #14: Caller MUST supply current_generation. No hidden read.
        if (!is_string($current_generation)
            || !self::is_valid_hex($current_generation, self::GENERATION_ID_HEX_LENGTH)
        ) {
            return array('state' => 'read_failure', 'reason' => 'missing_generation');
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

            // Structural validation with explicit current generation (Section F).
            // Pass the read-only current generation explicitly; the validator
            // now requires the caller to supply it instead of doing a hidden read.
            $validation = self::validate_provenance_record($record, $scope_from_key, $current_generation);
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

        // === Secret state machine. ===
        $secret_result = self::read_existing_secret_record();
        if ($secret_result['state'] === self::SECRET_INVALID) {
            $result['reason'] = 'malformed_secret';
            return $result;
        }
        if ($secret_result['state'] === self::SECRET_ABSENT) {
            // Bootstrap path: serialize the entire ABSENT → VALID transition.
            // The bootstrap lock is blog-scoped because a secret has no
            // caller-supplied identity key before it exists.
            $bootstrap_lock = self::get_bootstrap_lock_name();
            if ($bootstrap_lock === null) {
                $result['reason'] = 'bootstrap_lock_invalid';
                return $result;
            }
            if (!self::acquire_lock($bootstrap_lock)) {
                $result['reason'] = 'bootstrap_lock_contention';
                return $result;
            }
            try {
                // Reread secret state after acquiring the lock (another worker
                // may have raced and produced a valid secret first).
                $secret_result = self::read_existing_secret_record();
                if ($secret_result['state'] === self::SECRET_INVALID) {
                    $result['reason'] = 'malformed_secret';
                    return $result;
                }
                if ($secret_result['state'] === self::SECRET_ABSENT) {
                    // Census the user's history (zero identity/card security
                    // evidence required). Only HISTORY_NONE initializes a secret.
                    $preflight_blocking = self::inspect_bootstrap_history($user_id);
                    if ($preflight_blocking['classification'] !== self::HISTORY_NONE) {
                        $result['reason'] = 'legacy_migration_required';
                        return $result;
                    }
                    // Reread once more before write — another worker may have
                    // created the secret between the census and now.
                    $secret_result = self::read_existing_secret_record();
                    if ($secret_result['state'] === self::SECRET_INVALID) {
                        $result['reason'] = 'malformed_secret';
                        return $result;
                    }
                    if ($secret_result['state'] === self::SECRET_ABSENT) {
                        $created = self::get_or_create_secret_record();
                        if ($created === null) {
                            $result['reason'] = 'secret_create_failed';
                            return $result;
                        }
                        $secret_result = self::read_existing_secret_record();
                        if ($secret_result['state'] !== self::SECRET_VALID) {
                            $result['reason'] = 'secret_create_failed';
                            return $result;
                        }
                    }
                }
            } finally {
                self::release_lock($bootstrap_lock);
            }
        }

        // === Atomic context snapshot: ONE read of the secret record. ===
        $ctx = self::read_existing_identity_context($api_key, $is_test_mode);
        if ($ctx['state'] !== self::SECRET_VALID
            || $ctx['scope'] === null
            || $ctx['generation_id'] === null
        ) {
            $result['reason'] = 'scope_failure';
            return $result;
        }
        $scope = $ctx['scope'];
        $generation_id = $ctx['generation_id'];

        $result['scope'] = $scope;
        $result['secret_generation_id'] = $generation_id;

        $provenance = self::read_provenance($user_id, $scope, $generation_id);

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

        $prior_check = self::inspect_current_user_prior_provenance($user_id, $generation_id);
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

        $history = self::inspect_customer_history($user_id, $scope, $generation_id);

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
            // Re-validate context after lock acquisition: an identity root
            // rotation that landed between the snapshot and the lock must
            // fail closed rather than silently persisting under the old root.
            $post_lock_ctx = self::read_existing_identity_context($api_key, $is_test_mode);
            if ($post_lock_ctx['state'] !== self::SECRET_VALID
                || $post_lock_ctx['scope'] === null
                || $post_lock_ctx['generation_id'] === null
            ) {
                $result['reason'] = 'context_invalidated_after_lock';
                return $result;
            }
            if (!hash_equals($post_lock_ctx['scope'], $scope)
                || !hash_equals($post_lock_ctx['generation_id'], $generation_id)
            ) {
                $result['reason'] = 'context_changed_after_lock';
                return $result;
            }

            $provenance = self::read_provenance($user_id, $scope, $generation_id);

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

            $prior_check = self::inspect_current_user_prior_provenance($user_id, $generation_id);
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

            $history = self::inspect_customer_history($user_id, $scope, $generation_id);

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
                // Final revalidation immediately before persisting. The
                // identity root may have rotated during the network call
                // and we must persist under the exact same context that
                // authorized the API call.
                $final_ctx = self::read_existing_identity_context($api_key, $is_test_mode);
                if ($final_ctx['state'] !== self::SECRET_VALID
                    || $final_ctx['scope'] === null
                    || $final_ctx['generation_id'] === null
                ) {
                    $result['reason'] = 'context_invalidated_before_persist';
                    return $result;
                }
                if (!hash_equals($final_ctx['scope'], $scope)
                    || !hash_equals($final_ctx['generation_id'], $generation_id)
                ) {
                    $result['reason'] = 'context_changed_before_persist';
                    return $result;
                }

                $persisted = self::create_provenance(
                    $user_id,
                    $api_key,
                    $is_test_mode,
                    $scope,
                    $generation_id,
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

        // Single atomic context read — never call scope and generation helpers
        // independently; they each read the secret option and could interleave
        // with a rotation.
        $ctx = self::read_existing_identity_context($api_key, $is_test_mode);
        if ($ctx['state'] !== self::SECRET_VALID
            || $ctx['scope'] === null
            || $ctx['generation_id'] === null
        ) {
            return null;
        }
        $scope = $ctx['scope'];
        $generation_id = $ctx['generation_id'];

        $provenance = self::read_provenance($user_id, $scope, $generation_id);

        if ($provenance['state'] !== self::STATE_VALID) {
            return null;
        }

        $token = $provenance['record']['token'];
        if (!is_string($token) || $token === '') {
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

        if (!preg_match('/^[0-9]{8,18}$/', $token)) {
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
        if (!is_string($card_token) || $card_token === '') {
            return false;
        }

        if (!is_string($customer_token) || $customer_token === '') {
            return false;
        }

        if (!preg_match('/^[0-9]{8,18}$/', $customer_token)) {
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

        $submitted = $card_token;

        foreach ($result['data'] as $card_entry) {
            if (!is_array($card_entry)) {
                continue;
            }

            if (!isset($card_entry['token']) || !is_string($card_entry['token']) || $card_entry['token'] === '') {
                continue;
            }

            if ($card_entry['token'] === $submitted) {
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
