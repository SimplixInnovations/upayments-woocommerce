<?php

namespace UPayments\Subscription\Cron;

defined('ABSPATH') || exit;

/**
 * Per-billing-cycle atomic attempt journal.
 *
 * This is a durable LOCAL record of dispatch attempts for a single
 * (parent_order_id, billing_cycle) pair. It is NOT a payment lease, NOT a
 * provider idempotency key, and NOT an expiring lock.
 *
 * The journal enforces the rule:
 *
 *   AT MOST ONE automatic POST /auto-deduct may BEGIN until that billing
 *   attempt has been explicitly resolved/reconciled.
 *
 * States (high-level):
 *   claimed       — INSERT-only winner; no network dispatch has begun.
 *   dispatching   — This worker has crossed the point where an auto-deduct
 *                   POST may have reached UPayments. Never auto-released.
 *   held          — Post-dispatch outcome is ambiguous or unsafe for
 *                   automatic retry. Never auto-released.
 *   resolved      — Local renewal persisted and parent last_billed_at
 *                   advanced. Never auto-released.
 *
 * Only `claimed` may be stale-reclaimed after a conservative threshold.
 * `dispatching`, `held`, and `resolved` never auto-expire.
 */
class CycleClaim
{
    const SCHEMA_VERSION = '1';
    const OPTION_KEY     = 'upay_billing_cycle_schema_version';

    const STALE_CLAIMED_THRESHOLD_SECONDS = 600; // 10 minutes — conservative

    const STATE_CLAIMED     = 'claimed';
    const STATE_DISPATCHING = 'dispatching';
    const STATE_HELD        = 'held';
    const STATE_RESOLVED    = 'resolved';

    /**
     * Idempotent schema installer / readiness verifier.
     *
     * Behavior:
     *   - If the schema-version option matches AND the table actually exists,
     *     return true immediately. No dbDelta, no upgrade.php load.
     *   - Otherwise, (re)load upgrade.php, run dbDelta(), verify the table
     *     exists, and ONLY THEN bump the schema-version option.
     *   - If the table cannot be verified after dbDelta(), return false
     *     WITHOUT bumping the version flag. The caller must fail closed.
     *
     * Self-heals against:
     *   - accidental table deletion;
     *   - partial DB restore;
     *   - manual DB cleanup;
     *   - failed migration state where the version flag is set but the
     *     table is missing.
     */
    public static function maybe_install(): bool
    {
        $current = (string) get_option(self::OPTION_KEY, '');
        if ($current === self::SCHEMA_VERSION && self::table_exists()) {
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            cycle_key        char(64)        NOT NULL,
            parent_order_id  bigint unsigned NOT NULL,
            owner_token      varchar(64)     NOT NULL,
            state            varchar(20)     NOT NULL,
            cycle_due_gmt    datetime        NOT NULL,
            created_gmt      datetime        NOT NULL,
            updated_gmt      datetime        NOT NULL,
            dispatched_gmt   datetime        NULL,
            resolved_gmt     datetime        NULL,
            renewal_order_id bigint unsigned NULL,
            payment_id       varchar(255)    NULL,
            curl_errno       int             NULL,
            http_status      int             NULL,
            PRIMARY KEY  (cycle_key),
            KEY idx_parent (parent_order_id),
            KEY idx_state  (state)
        ) {$charset};";

        dbDelta($sql);

        // Confirm the table is now usable before bumping the version flag.
        if (!self::table_exists()) {
            return false;
        }

        update_option(self::OPTION_KEY, self::SCHEMA_VERSION, false);
        return true;
    }

    /**
     * True iff the journal table actually exists in the live database.
     * Uses $wpdb->esc_like() so the table prefix (which may contain
     * underscores or other LIKE wildcards) is treated literally.
     */
    public static function table_exists(): bool
    {
        global $wpdb;
        $table = self::table_name();

        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)
            )
        );
        return $found === $table;
    }

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'upayments_billing_attempts';
    }

    /**
     * Deterministic LOCAL cycle identity. Computed only from values that
     * exist before any POST is sent. NEVER sent to UPayments.
     */
    public static function make_cycle_key(
        int $parent_order_id,
        int $next_billing_utc_timestamp,
        string $subscription_plan,
        int $subscription_interval
    ): string {
        $material = implode('|', [
            'upay-cycle-v1',
            (string) $parent_order_id,
            (string) $next_billing_utc_timestamp,
            (string) $subscription_plan,
            (string) $subscription_interval,
        ]);
        return hash('sha256', $material);
    }

    /**
     * Atomic INSERT-ONLY acquisition. Uses INSERT IGNORE so the UNIQUE
     * (primary) cycle_key rejects a duplicate.
     *
     * IMPORTANT: this method does NOT use INSERT ... ON DUPLICATE KEY UPDATE.
     * Re-acquisition after a stale `claimed` is performed by reclaim_stale_claimed()
     * under a CAS pattern.
     *
     * Return contract (strict):
     *   true  iff $wpdb->query() returned exactly integer 1.
     *   false for any other return: 0 (duplicate ignored), false (SQL error),
     *         or any non-1 value.
     *
     * The follow-up owner-token re-read is defense in depth, NOT the primary
     * acceptance signal. WordPress wpdb::query() returns the affected-rows
     * count for INSERT/UPDATE/DELETE and false on error.
     */
    public static function acquire(
        string $cycle_key,
        int $parent_order_id,
        string $owner_token,
        string $cycle_due_gmt
    ): bool {
        global $wpdb;
        $table = self::table_name();

        $now_gmt = current_time('mysql', true);

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (
                    cycle_key, parent_order_id, owner_token, state,
                    cycle_due_gmt, created_gmt, updated_gmt
                ) VALUES (%s, %d, %s, %s, %s, %s, %s)",
                $cycle_key,
                $parent_order_id,
                $owner_token,
                self::STATE_CLAIMED,
                $cycle_due_gmt,
                $now_gmt,
                $now_gmt
            )
        );

        // Strict acceptance: exactly one row inserted. Anything else
        // (0 = duplicate, false = SQL error, anything non-1) loses.
        if ($inserted !== 1) {
            return false;
        }

        // Defense in depth: confirm we actually own the row we just wrote.
        $row = self::get($cycle_key);
        return is_array($row)
            && isset($row['owner_token'])
            && hash_equals((string) $row['owner_token'], $owner_token)
            && isset($row['state'])
            && (string) $row['state'] === self::STATE_CLAIMED;
    }

    /**
     * Stale-CLAIMED recovery. CAS-protected: only reclaims if the row is
     * still in `claimed` state, owned by a different token, belongs to
     * the same parent_order_id, AND is older than the stale threshold.
     *
     * The old owner, when it later attempts `claimed → dispatching`,
     * will see zero rows affected and MUST abort without POSTing.
     *
     * The `parent_order_id` predicate is critical: it prevents a stale
     * reclaim that would silently re-bind a cycle_key to a different
     * subscription's renewal pipeline.
     */
    public static function reclaim_stale_claimed(
        string $cycle_key,
        int $parent_order_id,
        string $new_owner_token,
        string $cycle_due_gmt
    ): bool {
        global $wpdb;
        $table   = self::table_name();
        $now_gmt = current_time('mysql', true);

        $threshold_gmt = gmdate(
            'Y-m-d H:i:s',
            time() - self::STALE_CLAIMED_THRESHOLD_SECONDS
        );

        $updated = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                    SET owner_token = %s,
                        updated_gmt = %s,
                        cycle_due_gmt = %s
                    WHERE cycle_key = %s
                      AND parent_order_id = %d
                      AND state = %s
                      AND updated_gmt < %s",
                $new_owner_token,
                $now_gmt,
                $cycle_due_gmt,
                $cycle_key,
                $parent_order_id,
                self::STATE_CLAIMED,
                $threshold_gmt
            )
        );

        if ($updated !== 1) {
            return false;
        }

        // Verify ownership after the CAS update.
        $row = self::get($cycle_key);
        return is_array($row)
            && isset($row['owner_token'])
            && hash_equals((string) $row['owner_token'], $new_owner_token)
            && isset($row['parent_order_id'])
            && (int) $row['parent_order_id'] === $parent_order_id;
    }

    /**
     * CAS transition: claimed → dispatching. Requires owner_token.
     * MUST be invoked BEFORE curl_exec().
     *
     * Returns true if THIS worker now owns the dispatching state.
     */
    public static function mark_dispatching(string $cycle_key, string $owner_token): bool
    {
        global $wpdb;
        $table   = self::table_name();
        $now_gmt = current_time('mysql', true);

        $updated = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                    SET state = %s,
                        dispatched_gmt = %s,
                        updated_gmt = %s
                    WHERE cycle_key = %s
                      AND owner_token = %s
                      AND state = %s",
                self::STATE_DISPATCHING,
                $now_gmt,
                $now_gmt,
                $cycle_key,
                $owner_token,
                self::STATE_CLAIMED
            )
        );

        return $updated === 1;
    }

    /**
     * Mark post-dispatch outcome as held. CAS-protected by owner_token.
     *
     * Acceptable from:
     *   - `dispatching` — NORMAL new-flow post-dispatch hold.
     *   - `claimed`     — ONLY for the LEGACY AMBIGUOUS ATTEMPT MIGRATION
     *                     guard: a due parent that already has a non-empty
     *                     historical `_upay_last_attempt_at` but no cycle
     *                     row is HELD before any POST is attempted. The
     *                     current Scheduler never performs this transition
     *                     from `claimed` for any other reason.
     *
     * Never auto-released.
     */
    public static function mark_held(
        string $cycle_key,
        string $owner_token,
        ?int $curl_errno = null,
        ?int $http_status = null
    ): bool {
        global $wpdb;
        $table   = self::table_name();
        $now_gmt = current_time('mysql', true);

        $updated = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                    SET state = %s,
                        updated_gmt = %s,
                        curl_errno = %d,
                        http_status = %d
                    WHERE cycle_key = %s
                      AND owner_token = %s
                      AND state IN (%s, %s)",
                self::STATE_HELD,
                $now_gmt,
                null === $curl_errno ? 0 : $curl_errno,
                null === $http_status ? 0 : $http_status,
                $cycle_key,
                $owner_token,
                self::STATE_CLAIMED,
                self::STATE_DISPATCHING
            )
        );

        return $updated === 1;
    }

    /**
     * Mark cycle as resolved. Records the renewal order id and payment id.
     * Owner-token protected.
     *
     * Acceptable from:
     *   - `dispatching` — NORMAL automatic resolution after the renewal is
     *                     saved AND parent `_upay_last_billed_at` is saved.
     *                     This is the ONLY transition the current Scheduler
     *                     performs into `resolved`.
     *   - `held`        — Reserved for FUTURE manual reconciliation tooling
     *                     (Phase 8C or later). The current Scheduler never
     *                     performs this transition.
     *
     * `claimed` is NOT in the source-state set. A normal automatic resolution
     * MUST originate from `dispatching`; resolution from `claimed` would
     * imply a payment event without an attempted POST, which is impossible
     * in the new flow.
     */
    public static function mark_resolved(
        string $cycle_key,
        string $owner_token,
        int $renewal_order_id,
        string $payment_id
    ): bool {
        global $wpdb;
        $table   = self::table_name();
        $now_gmt = current_time('mysql', true);

        $updated = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                    SET state = %s,
                        resolved_gmt = %s,
                        updated_gmt = %s,
                        renewal_order_id = %d,
                        payment_id = %s
                    WHERE cycle_key = %s
                      AND owner_token = %s
                      AND state IN (%s, %s)",
                self::STATE_RESOLVED,
                $now_gmt,
                $now_gmt,
                $renewal_order_id,
                $payment_id,
                $cycle_key,
                $owner_token,
                self::STATE_DISPATCHING,
                self::STATE_HELD
            )
        );

        return $updated === 1;
    }

    /**
     * Release a still-claimed row. Only allowed when state is `claimed`
     * AND owner_token matches. Never releases dispatching/held/resolved.
     */
    public static function release_claimed(string $cycle_key, string $owner_token): bool
    {
        global $wpdb;
        $table = self::table_name();

        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table}
                    WHERE cycle_key = %s
                      AND owner_token = %s
                      AND state = %s",
                $cycle_key,
                $owner_token,
                self::STATE_CLAIMED
            )
        );

        return $deleted === 1;
    }

    /**
     * Read the current row for a cycle key. Returns null if absent.
     */
    public static function get(string $cycle_key): ?array
    {
        global $wpdb;
        $table = self::table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE cycle_key = %s",
                $cycle_key
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Generate a fresh owner token for this worker.
     */
    public static function new_owner_token(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        // Fallback: 32 hex chars from random_bytes.
        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            return sha1(uniqid('upay_', true) . microtime(true));
        }
    }

    /**
     * Format a PHP DateTime as a MySQL DATETIME string in UTC.
     */
    public static function format_gmt_datetime(\DateTimeInterface $dt): string
    {
        $utc = new \DateTime('@' . $dt->getTimestamp());
        return $utc->format('Y-m-d H:i:s');
    }
}
