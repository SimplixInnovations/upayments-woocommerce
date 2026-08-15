<?php

namespace UPayments\Subscription\Cron;

use DateTime;
use WC_Order;
use WC_DateTime;
use DateTimeInterface;

defined('ABSPATH') || exit;

// Explicit dependency load. There is no repository-owned Composer/PSR-4
// autoloader for this namespace, so the journal class must be present in
// the same request that runs Scheduler::process(). Loading it explicitly
// here guarantees UPayments\Subscription\Cron\CycleClaim is defined before
// Scheduler references it, independent of any other plugin/theme/global
// autoloader.
require_once __DIR__ . '/CycleClaim.php';

class Scheduler
{
    const CRON_HOOK = 'upay_process_subscriptions';

    /**
     * Bootstraps scheduler.
     *
     * Becomes the sole canonical owner of upay_process_subscriptions.
     * Idempotently clears any legacy upay_hourly_cron_job events that may
     * still be in the WP-Cron array from prior releases.
     */
    public static function init()
    {
        // Idempotent legacy cleanup. The legacy wrapper callback no longer
        // exists; clearing the event prevents it from being dispatched.
        if (function_exists('wp_next_scheduled')
            && wp_next_scheduled('upay_hourly_cron_job')
        ) {
            wp_clear_scheduled_hook('upay_hourly_cron_job');
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }
        add_action(self::CRON_HOOK, [__CLASS__, 'process']);
    }

    /**
     * Main cron logic.
     */
    public static function process()
    {
        $logger  = wc_get_logger();
        $context = ['source' => 'upayments-cron'];

        // Table readiness (CycleClaim::maybe_install) and ALL subsequent
        // cron work executes INSIDE the outer try so that any Throwable
        // from get_option(), table_exists(), $wpdb, upgrade.php loading,
        // dbDelta(), DateTime/wp_timezone(), or any other step is caught
        // by the global Scheduler safety boundary. No charge POST may
        // occur before table readiness succeeds.
        try {
            $now = new DateTime('now', wp_timezone());

            // Fail closed if the persistent attempt journal is unavailable.
            // We will NOT dispatch an auto-deduct POST without a per-cycle claim.
            if (!CycleClaim::maybe_install()) {
                $logger->error(
                    'Subscription auto-deduction skipped: cycle claim table is not available.',
                    $context
                );
                return;
            }

            $gateway = self::getGateway();

            if (!$gateway || $gateway->get_option('enable_subscriptions') !== 'yes') {
                $logger->info('Subscriptions disabled or gateway unavailable', $context);
                return;
            }

            $page  = 1;
            $limit = 50;
            $matched_orders = [];

            do {
                $orders = wc_get_orders([
                    'status' => 'completed',
                    'limit'  => $limit,
                    'paged'  => $page,
                ]);

                foreach ($orders as $order) {
                    foreach ($order->get_items('line_item') as $item) {
                        $product = $item->get_product();
                        if ($product && $product->get_type() === 'custom_type') {
                            $matched_orders[] = $order;
                            break; // stop checking this order
                        }
                    }
                }
                $page++;
            } while (!empty($orders));

            if (!empty($matched_orders)) {
                foreach ($matched_orders as $order) {

                    // ---- Per-parent Throwable isolation ----
                    // Each subscription is processed inside its own try/catch
                    // so that one parent's failure cannot terminate processing
                    // of later matched parents. The outer try/catch remains as
                    // a global safety net.
                    try {
                        self::process_one_order(
                            $order,
                            $now,
                            $context
                        );
                    } catch (\Throwable $e) {
                        // The per-parent method already does its own claim
                        // cleanup (release if pre-dispatch, hold if post-
                        // dispatch). This catch is a defense in depth: if
                        // something escapes process_one_order before any
                        // state was established, we log and continue.
                        $logger->error(
                            'Unhandled per-parent error; continuing to next parent.',
                            $context + [
                                'order_id' => $order->get_id(),
                            ]
                        );
                        continue;
                    }
                }
            } else {
                $logger->info('No matched orders found', $context);
            }

            $logger->info('Cron execution finished successfully', $context);

        } catch (\Throwable $e) {
            $logger->error(
                'Cron failed.',
                $context + ['order_id' => 'n/a']
            );
        }
    }

    /**
     * Process a single matched parent order end-to-end inside the per-parent
     * Throwable isolation boundary.
     *
     * Local state tracked for safe claim cleanup across exceptions:
     *   $cycle_key        — current cycle identity, or null until computed
     *   $owner_token      — current owner token, or null until computed
     *   $claim_owned      — true once acquire()/reclaim_stale_claimed() has succeeded
     *   $dispatch_started — true once mark_dispatching() has succeeded
     *   $ch               — open cURL handle, or null
     *
     * Claim cleanup policy:
     *   - Pre-dispatch failure  : release the CLAIMED row owned by THIS token.
     *   - Post-dispatch failure : NEVER release; mark_held() is the safest
     *                             fallback. If mark_held() itself fails the
     *                             row remains in `dispatching`, which is
     *                             NEVER auto-expired.
     *   - Unhandled throwable   : best-effort safe release if pre-dispatch,
     *                             best-effort mark_held if post-dispatch.
     */
    protected static function process_one_order(
        WC_Order $order,
        DateTime $now,
        array $context
    ): void {
        $logger  = wc_get_logger();
        $gateway = self::getGateway();
        if (!$gateway) {
            return;
        }

        $cycle_key        = null;
        $owner_token      = null;
        $claim_owned      = false;
        $dispatch_started = false;
        $ch               = null;

        // Helper: pre-dispatch safe cleanup. Closes cURL if open and
        // releases the CLAIMED row IF we still own it. Use only before
        // any POST may have been sent.
        $release_pre_dispatch = function () use (&$ch, &$cycle_key, &$owner_token, &$claim_owned) {
            if (is_resource($ch) || $ch instanceof \CurlHandle) {
                @curl_close($ch);
                $ch = null;
            }
            if ($claim_owned && null !== $cycle_key && null !== $owner_token) {
                CycleClaim::release_claimed($cycle_key, $owner_token);
            }
            $claim_owned = false;
        };

        // Helper: post-dispatch safe cleanup. Closes cURL if open and
        // marks the cycle HELD. Never releases — leaving the row in
        // `dispatching` is intentionally safe.
        $hold_post_dispatch = function (
            ?int $curl_errno,
            ?int $http_status
        ) use (&$ch, &$cycle_key, &$owner_token, &$claim_owned) {
            if (is_resource($ch) || $ch instanceof \CurlHandle) {
                @curl_close($ch);
                $ch = null;
            }
            if ($claim_owned && null !== $cycle_key && null !== $owner_token) {
                CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_status);
            }
            $claim_owned = false;
        };

        try {
            $isAutoDeductionOrder = $order->get_meta('UPayments_AutoDeduction') === 'yes' ? true : false;
            // Preserve historical empty status as 'active' for compatibility.
            $subscriptionStatus = $order->get_meta('_upay_subscription_status') ?: 'active';
            $customerUnqToken = $order->get_meta('_upay_customer_unique_token');

            if ($customerUnqToken == null || empty($customerUnqToken)) {
                $logger->info(
                    'Customer Unique token not found.',
                    $context + ['order_id' => $order->get_id()]
                );
                return; // per-order failure; do not abort the loop
            }

            $subscriptionPlan = $order->get_meta('_upay_subscription_plan');
            $subscriptionInterval = (int) $order->get_meta('_upay_subscription_interval');
            if ($subscriptionPlan === 'daily') {
                $subscriptionInterval = 1;
            }

            if ((!$subscriptionPlan || $subscriptionInterval < 1) || $subscriptionPlan === 'one_time') {
                $logger->info(
                    'Invalid subscription configuration.',
                    $context + ['order_id' => $order->get_id()]
                );
                return;
            }

            $order_date = $order->get_date_created();
            $order_paid_date = $order->get_date_paid();
            $order_completed_date = $order->get_date_completed();
            $order_last_billed_date = $order->get_meta('_upay_last_billed_at');

            $start_date = $order_last_billed_date
                ?: $order_paid_date
                ?: $order_completed_date
                ?: $order_date;

            if (!$start_date) {
                $logger->info(
                    'No start date available.',
                    $context + ['order_id' => $order->get_id()]
                );
                return;
            }

            if (is_string($start_date)) {
                $start_date = new DateTime($start_date);
            } elseif ($start_date instanceof DateTimeInterface) {
                $start_date = new DateTime($start_date->format('Y-m-d H:i:s'), $start_date->getTimezone());
            }

            $next_billing_date = self::getNextBillingDate(
                $start_date,
                $subscriptionPlan,
                $subscriptionInterval
            );
            if (!$next_billing_date) {
                $logger->info(
                    'Could not compute next billing date.',
                    $context + ['order_id' => $order->get_id()]
                );
                return;
            }

            // Billing gate:
            // - parent is initial subscription ('no'), not a renewal child ('yes');
            // - status is explicitly 'active' (empty defaults to 'active' for compatibility);
            // - billing date reached.
            if ($isAutoDeductionOrder
                || $subscriptionStatus !== 'active'
                || $now < $next_billing_date
            ) {
                return;
            }

            // ---- BLOCKER 7: Legacy ambiguous attempt migration guard ----
            // Before PR 8B, Scheduler wrote _upay_last_attempt_at immediately
            // before attempting auto-deduct and deleted it only after the
            // renewal+parent success path completed. A due parent that still
            // has a non-empty _upay_last_attempt_at therefore represents an
            // UNRESOLVED historical attempt. We cannot prove that request did
            // not reach UPayments. Hold the cycle, send ZERO POSTs, and do
            // NOT clear _upay_last_attempt_at automatically.
            $legacy_last_attempt = (string) $order->get_meta('_upay_last_attempt_at');
            if ($legacy_last_attempt !== '') {
                $cycle_due_gmt = CycleClaim::format_gmt_datetime($next_billing_date);
                $cycle_key = CycleClaim::make_cycle_key(
                    (int) $order->get_id(),
                    $next_billing_date->getTimestamp(),
                    (string) $subscriptionPlan,
                    (int) $subscriptionInterval
                );
                $owner_token = CycleClaim::new_owner_token();

                $legacy_acquired = CycleClaim::acquire(
                    $cycle_key,
                    (int) $order->get_id(),
                    $owner_token,
                    $cycle_due_gmt
                );
                if (!$legacy_acquired) {
                    // Stale reclaim is the only allowed second chance.
                    $legacy_acquired = CycleClaim::reclaim_stale_claimed(
                        $cycle_key,
                        (int) $order->get_id(),
                        $owner_token,
                        $cycle_due_gmt
                    );
                }

                if ($legacy_acquired) {
                    $claim_owned = true;
                    // Transition the newly-owned CLAIMED row to HELD via the
                    // documented claimed→held migration path.
                    CycleClaim::mark_held($cycle_key, $owner_token, null, null);
                    $claim_owned = false;
                    $logger->warning(
                        'Legacy unresolved subscription attempt detected; cycle held for reconciliation.',
                        $context + [
                            'order_id' => $order->get_id(),
                            'cycle'    => substr($cycle_key, 0, 12),
                        ]
                    );
                } else {
                    // A claim row already exists in some state (held /
                    // dispatching / resolved). Send ZERO POSTs and rely on
                    // the existing row.
                    $logger->warning(
                        'Legacy unresolved attempt with existing cycle row; cycle held for reconciliation.',
                        $context + [
                            'order_id' => $order->get_id(),
                            'cycle'    => substr($cycle_key, 0, 12),
                        ]
                    );
                }
                return; // Zero POSTs. The legacy guard ends processing.
            }

            $credit_card_token = $order->get_meta('_upay_credit_card_token');
            if (empty($credit_card_token)) {
                $savedCards = $gateway->getSavedCards($customerUnqToken);
                if ($savedCards && isset($savedCards['result']) && $savedCards['result'] === 'success') {
                    $cards = $savedCards['data'];
                    if (empty($cards) || !is_array($cards)) {
                        $logger->info(
                            'No saved cards for customer.',
                            $context + ['order_id' => $order->get_id()]
                        );
                        return;
                    }
                    $credit_card_token = isset($cards[0]['token']) ? (string) $cards[0]['token'] : '';
                } else {
                    $logger->info(
                        'Unable to retrieve saved cards.',
                        $context + ['order_id' => $order->get_id()]
                    );
                    return;
                }
            }

            if ($credit_card_token === '') {
                $logger->info(
                    'Credit card token missing.',
                    $context + ['order_id' => $order->get_id()]
                );
                return;
            }

            $unique_order_id = $order->get_id();
            $ref_id = $order->get_meta('UPayments_Ref');
            $order_total = $order->get_total();
            $currency = $order->get_currency();
            $phone = preg_replace('/\D+/', '', $order->get_billing_phone());
            $firstName = $order->get_billing_first_name();
            $lastName = $order->get_billing_last_name();
            $fullName = $firstName . ' ' . $lastName;
            $email = $order->get_billing_email();

            $params = wp_json_encode([
                'order' => [
                    'id'          => (string) $unique_order_id,
                    'amount'      => $order_total,
                    'currency'    => $gateway->getCurrencyCode($currency),
                    'description' => 'Woocommerce Auto Deduction Order: ' . $unique_order_id,
                    'reference'   => 'Uniq Order ID: ' . $unique_order_id,
                ],
                'reference' => [
                    'id' => (string) $ref_id,
                ],
                'customer' => [
                    'name'        => $fullName,
                    'email'       => $email,
                    'mobile'      => $phone,
                    'uniqueToken' => $customerUnqToken,
                ],
                'language' => 'en',
                'card' => [
                    'token' => $credit_card_token,
                ],
            ]);

            if (!is_string($params) || $params === '') {
                $logger->info(
                    'Request payload encoding failed.',
                    $context + ['order_id' => $order->get_id()]
                );
                return;
            }

            $gateway->log(__('Auto-deduction request prepared.', $gateway->domain));

            // ---- Compute per-cycle identity and acquire claim ----
            $cycle_due_gmt = CycleClaim::format_gmt_datetime($next_billing_date);
            $cycle_key = CycleClaim::make_cycle_key(
                (int) $order->get_id(),
                $next_billing_date->getTimestamp(),
                (string) $subscriptionPlan,
                (int) $subscriptionInterval
            );

            $owner_token = CycleClaim::new_owner_token();

            $acquired = CycleClaim::acquire(
                $cycle_key,
                (int) $order->get_id(),
                $owner_token,
                $cycle_due_gmt
            );

            if (!$acquired) {
                // Stale-claimed reclamation is the only allowed second chance.
                $acquired = CycleClaim::reclaim_stale_claimed(
                    $cycle_key,
                    (int) $order->get_id(),
                    $owner_token,
                    $cycle_due_gmt
                );
            }

            if (!$acquired) {
                $logger->info(
                    'Cycle claim already in flight or resolved; skipping.',
                    $context + [
                        'order_id' => $order->get_id(),
                        'cycle'    => substr($cycle_key, 0, 12),
                    ]
                );
                return;
            }
            $claim_owned = true;

            // ---- Pre-dispatch: prepare cURL but DO NOT call curl_exec() yet ----
            $ch = curl_init();
            if ($ch === false) {
                $release_pre_dispatch();
                $logger->info(
                    'curl_init failed.',
                    $context + ['order_id' => $order->get_id()]
                );
                return;
            }

            // ---- BLOCKER 5: cURL configuration in a single batch + verify ----
            $curl_options = [
                CURLOPT_URL            => $gateway->getAPIUrl('auto-deduct'),
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $params,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERAGENT      => $gateway->getUserAgent(),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $gateway->apiKey,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
            ];

            $curl_config_ok = true;
            foreach ($curl_options as $option => $value) {
                if (!@curl_setopt($ch, $option, $value)) {
                    $curl_config_ok = false;
                    break;
                }
            }
            if (!$curl_config_ok) {
                $release_pre_dispatch();
                $logger->info(
                    'curl_setopt configuration failed.',
                    $context + ['order_id' => $order->get_id()]
                );
                return;
            }

            // ---- Atomic claimed → dispatching transition BEFORE curl_exec() ----
            // If this fails, no POST was sent. Release and continue.
            $dispatching = CycleClaim::mark_dispatching($cycle_key, $owner_token);
            if (!$dispatching) {
                $release_pre_dispatch();
                $logger->info(
                    'Cycle claimed→dispatching transition failed; skipping.',
                    $context + [
                        'order_id' => $order->get_id(),
                        'cycle'    => substr($cycle_key, 0, 12),
                    ]
                );
                return;
            }
            $dispatch_started = true;

            // ---- BLOCKER 3: NO write between mark_dispatching() and curl_exec() ----
            // The journal is the new authoritative attempt record. Legacy
            // metadata writes (_upay_last_attempt_at, _upay_retry_count,
            // _upay_last_failed_reason) are diagnostic/backward-compatibility
            // only and must NOT be written immediately before every new
            // dispatch — a clean pre-dispatch crash must not be mistaken for
            // a historical ambiguous charge.
            //
            // There must be NO:
            //   - WC_Order save;
            //   - metadata mutation;
            //   - claim-journal mutation other than the
            //     claimed→dispatching transition above;
            //   - logging call that can reasonably throw;
            //   - external API call
            // between successful mark_dispatching() and curl_exec().

            // ---- Dispatch (POST may have reached UPayments) ----
            $response   = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            // Raw curl_error is intentionally not read, persisted, or
            // logged. Structured curl_errno and HTTP status are used for
            // local diagnostics only.
            $http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);
            $ch = null;

            $logger->info(
                'Auto-deduction HTTP response received.',
                $context + [
                    'order_id'    => $order->get_id(),
                    'http_status' => $http_code,
                    'curl_errno'  => $curl_errno,
                ]
            );

            // ---- Post-dispatch outcome handling ----
            self::handle_post_dispatch(
                $order,
                $response,
                $http_code,
                $curl_errno,
                $cycle_key,
                $owner_token,
                $gateway,
                $credit_card_token,
                $customerUnqToken,
                $subscriptionPlan,
                $subscriptionInterval,
                $context
            );

            // After handle_post_dispatch returns the cycle is either
            // resolved (mark_resolved succeeded) or held. Either is safe;
            // we drop our ownership marker.
            $claim_owned = false;

        } catch (\Throwable $e) {
            // Per-parent Throwable isolation. Decide cleanup policy based
            // on whether we have crossed the dispatching boundary.
            if ($dispatch_started) {
                // Post-dispatch failure. NEVER release the claim.
                $hold_post_dispatch(null, null);
            } else {
                // Pre-dispatch failure (or never reached dispatching).
                // Release the claim if we owned it.
                $release_pre_dispatch();
            }
            $logger->error(
                'Unhandled per-parent error; continuing to next parent.',
                $context + [
                    'order_id' => $order->get_id(),
                    'cycle'    => null !== $cycle_key ? substr($cycle_key, 0, 12) : 'n/a',
                ]
            );
        }
    }

    /**
     * Handle the post-dispatch response, structural validation, dedupe,
     * renewal creation, and final cycle state transition.
     *
     * Any failure path that has crossed the dispatching boundary must
     * result in `held` (never a release). Pre-dispatch failures are not
     * possible here; this runs only after curl_exec() has returned.
     */
    protected static function handle_post_dispatch(
        WC_Order $order,
        $response,
        int $http_code,
        int $curl_errno,
        string $cycle_key,
        string $owner_token,
        $gateway,
        string $credit_card_token,
        string $customerUnqToken,
        string $subscriptionPlan,
        int $subscriptionInterval,
        array $context
    ): void {
        $logger = wc_get_logger();

        // ---- 1. Transport-level outcome ----
        if ($response === false || $response === '' || $curl_errno !== 0) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            $logger->info(
                'cURL transport failure; cycle held.',
                $context + [
                    'order_id' => $order->get_id(),
                    'cycle'    => substr($cycle_key, 0, 12),
                ]
            );
            return;
        }

        // ---- 2. HTTP status gate (BLOCKER 2) ----
        // The auto-deduct success-code contract is undocumented. The maximum
        // safe assumption for this LOCAL transport PR is generic 2xx. Every
        // non-2xx code holds the cycle, regardless of body shape:
        //   0, 3xx, 400, 401, 403, 404, 409, 422, 429, 500, 502, 503, 504, ...
        // Non-2xx may NOT reach wc_create_order(), payment_complete(), or
        // the parent last_billed update.
        if ($http_code < 200 || $http_code >= 300) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            $logger->info(
                'Non-2xx HTTP response; cycle held.',
                $context + [
                    'order_id'    => $order->get_id(),
                    'cycle'       => substr($cycle_key, 0, 12),
                    'http_status' => $http_code,
                ]
            );
            return;
        }

        $result = json_decode((string) $response, true);
        if (!is_array($result)) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            $logger->info(
                'Malformed JSON response; cycle held.',
                $context + [
                    'order_id' => $order->get_id(),
                    'cycle'    => substr($cycle_key, 0, 12),
                ]
            );
            return;
        }

        // ---- 3. Top-level status (historical truthy compatibility gate) ----
        // Preserves the historical truthy top-level `status` compatibility
        // gate. This is NOT authoritative capture verification. HTTP 2xx
        // is transport acceptance. Truthy status is historical compatibility.
        // Phase 8C will replace this only after first-party protocol
        // evidence exists.
        $status_true = isset($result['status']) && $result['status'];
        if (!$status_true) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            $logger->info(
                'Provider returned non-success status; cycle held.',
                $context + [
                    'order_id' => $order->get_id(),
                    'cycle'    => substr($cycle_key, 0, 12),
                ]
            );
            return;
        }

        // ---- 4. Structural validation of the success response ----
        if (!isset($result['data']) || !is_array($result['data'])
            || !isset($result['data']['transaction']) || !is_array($result['data']['transaction'])
        ) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            $logger->info(
                'Response missing data.transaction; cycle held.',
                $context + [
                    'order_id' => $order->get_id(),
                    'cycle'    => substr($cycle_key, 0, 12),
                ]
            );
            return;
        }

        $transaction = $result['data']['transaction'];

        // BLOCKER 12 scalar normalization.
        if (!array_key_exists('paymentId', $transaction)
            || !is_scalar($transaction['paymentId'])
        ) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            return;
        }
        $payment_id = trim((string) $transaction['paymentId']);
        if ($payment_id === '') {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            return;
        }

        if (!isset($transaction['paid_amount']) || !is_numeric($transaction['paid_amount'])) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            return;
        }

        if (!isset($transaction['paid_currency']) || !is_string($transaction['paid_currency'])) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            return;
        }
        $paid_currency = trim($transaction['paid_currency']);
        if ($paid_currency === '') {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            return;
        }

        if (!isset($transaction['orderId']) || !is_numeric($transaction['orderId'])) {
            // Prevents the legacy Fabricated `orderId + 1` from silently
            // producing identifier "1" when orderId is missing/null.
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            return;
        }

        // Optional fields — guarded access only.
        $track_id        = isset($transaction['trackId'])        && is_scalar($transaction['trackId'])        ? (string) $transaction['trackId']        : '';
        $invoice_id      = isset($transaction['invoiceId'])      && is_scalar($transaction['invoiceId'])      ? (string) $transaction['invoiceId']      : '';
        $reference_value = isset($transaction['reference'])      && is_scalar($transaction['reference'])      ? (string) $transaction['reference']      : '';
        $transaction_dt  = isset($transaction['transactionDate']) && is_scalar($transaction['transactionDate']) ? (string) $transaction['transactionDate'] : '';
        $payment_type    = isset($transaction['paymentType'])    && is_scalar($transaction['paymentType'])    ? (string) $transaction['paymentType']    : '';

        // ---- 5. Local transaction_id dedupe via WooCommerce order query ----
        // Invariant: a NON-EMPTY transaction_id match is already duplicate /
        // reconciliation evidence. EVERY possible branch of
        // if (!empty($existing_order_ids)) MUST return BEFORE
        // wc_create_order(). Unknown / unloadable classification = HELD.
        $existing_order_ids = wc_get_orders([
            'limit'          => 1,
            'return'         => 'ids',
            'payment_method' => 'upayments',
            'transaction_id' => $payment_id,
        ]);

        if (!empty($existing_order_ids)) {
            $existing_order_id = absint($existing_order_ids[0]);
            $existing_order = $existing_order_id > 0
                ? wc_get_order($existing_order_id)
                : false;

            if (!$existing_order instanceof WC_Order) {
                // The matching id could not be loaded as a WC_Order (was
                // deleted between query and load, is corrupt, is unexpected
                // order type, has incomplete metadata, or wc_get_order
                // returned a non-object). Unknown classification = HELD /
                // reconciliation. Do NOT create a replacement renewal, do
                // NOT update parent last_billed, do NOT automatically retry.
                CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
                $logger->warning(
                    'Existing transaction match could not be loaded; cycle held.',
                    $context + [
                        'order_id'    => $order->get_id(),
                        'existing_id' => $existing_order_id,
                        'cycle'       => substr($cycle_key, 0, 12),
                    ]
                );
                return;
            }

            $existing_parent = (int) $existing_order->get_meta('UPayments_ParentOrderID');
            $existing_is_auto = $existing_order->get_meta('UPayments_AutoDeduction') === 'yes';

            if ($existing_is_auto && $existing_parent === (int) $order->get_id()) {
                // Same paymentId seen for the same parent+cycle. The
                // existing local renewal belongs to a previous attempt;
                // we cannot prove which billing cycle it belongs to
                // (Phase 8B explicitly does not implement reconciliation).
                // Hold the cycle for manual reconciliation, do NOT
                // mark_resolved, do NOT touch parent last_billed, do
                // NOT send another POST, do NOT create another renewal.
                CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
                $logger->warning(
                    'Existing renewal transaction requires reconciliation; cycle held.',
                    $context + [
                        'order_id'      => $order->get_id(),
                        'renewal_order' => $existing_order_id,
                        'cycle'         => substr($cycle_key, 0, 12),
                    ]
                );
                return;
            }

            // Transaction_id collision with a different parent/order.
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            $logger->warning(
                'Transaction collision with a different order; cycle held.',
                $context + [
                    'order_id'      => $order->get_id(),
                    'existing_id'   => $existing_order_id,
                    'cycle'         => substr($cycle_key, 0, 12),
                ]
            );
            return;
        }

        // ---- 5. Create renewal order ----
        $renewal_order = wc_create_order([
            'customer_id' => $order->get_user_id(),
        ]);

        if (!$renewal_order instanceof WC_Order) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            return;
        }

        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $renewal_order->add_product(
                $product,
                $item->get_quantity(),
                [
                    'subtotal' => $item->get_total(),
                    'total'    => $item->get_total(),
                ]
            );
        }

        $renewal_order->set_address($order->get_address('billing'), 'billing');
        $renewal_order->set_address($order->get_address('shipping'), 'shipping');
        $renewal_order->set_currency($paid_currency);
        $renewal_order->set_total((float) $transaction['paid_amount']);
        $renewal_order->set_payment_method('upayments');
        $renewal_order->set_payment_method_title('UPayments Auto Deduction');

        // Preserve the existing ordering semantics for gateway meta.
        $renewal_order->update_meta_data('UPayments_order_id', $transaction['orderId'] + 1);
        $renewal_order->update_meta_data('UPayments_ParentOrderID', $order->get_id());
        $renewal_order->update_meta_data('UPayments_AutoDeduction', 'yes');
        $renewal_order->update_meta_data('UPayments_PaymentID', $payment_id);

        if ($track_id !== '') {
            $renewal_order->update_meta_data('UPayments_TrackID', $track_id);
        }
        if ($invoice_id !== '') {
            $renewal_order->update_meta_data('UPayments_InvoiceID', $invoice_id);
        }
        if ($reference_value !== '') {
            $renewal_order->update_meta_data('UPayments_Ref', $reference_value);
        }
        if ($transaction_dt !== '') {
            $renewal_order->update_meta_data('UPayments_TransactionDate', $transaction_dt);
        }
        if ($payment_type !== '') {
            $renewal_order->update_meta_data('UPayments_payment_type', $payment_type);
        }

        $renewal_order->update_meta_data('UPayments_Result', 'CAPTURED');
        $renewal_order->update_meta_data('UPayments_PostDate', '');
        // BLOCKER 13: Preserve UPayments_GatewayStatus compatibility.
        // The legacy code stored $result['status'] directly. Do NOT
        // intentionally normalize this metadata type in PR 8B. Phase 8C
        // will replace this only after first-party protocol evidence
        // exists.
        $renewal_order->update_meta_data('UPayments_GatewayStatus', $result['status']);
        $renewal_order->update_meta_data('_upay_credit_card_token', $credit_card_token);
        $renewal_order->update_meta_data('_upay_customer_unique_token', $customerUnqToken);
        $renewal_order->update_meta_data('_upay_subscription_plan', $subscriptionPlan);
        $renewal_order->update_meta_data('_upay_subscription_interval', $subscriptionInterval);

        $renewal_order->payment_complete($payment_id);
        $renewal_order->update_status(
            'completed',
            __('Subscription renewal payment completed via UPayments Auto Deduction. PaymentID: ' . $payment_id, $gateway->domain)
        );
        $renewal_order->save();

        // ---- 6. Renewal persistence verification (fresh WooCommerce read) ----
        // WC_Order::save() catches Exception internally, logs via
        // handle_exception(), and returns the order id. A normal return
        // therefore does NOT independently prove that the requested data
        // was persisted. The CycleClaim definition of RESOLVED requires:
        //   - renewal locally finalized; AND
        //   - parent _upay_last_billed_at durably advanced.
        // We must fresh-read BOTH via WooCommerce APIs and verify the
        // persisted values BEFORE mark_resolved() is permitted.
        $renewal_order_id = absint($renewal_order->get_id());
        if ($renewal_order_id <= 0) {
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            $logger->warning(
                'Renewal persistence verification failed; cycle held for reconciliation.',
                $context + [
                    'order_id'    => $order->get_id(),
                    'renewal_id'  => 0,
                    'cycle'       => substr($cycle_key, 0, 12),
                    'http_status' => $http_code,
                ]
            );
            return;
        }

        $persisted_renewal              = wc_get_order($renewal_order_id);
        $persisted_renewal_txn_id       = $persisted_renewal instanceof WC_Order
            ? (string) $persisted_renewal->get_transaction_id()
            : '';
        $persisted_renewal_payment_mthd = $persisted_renewal instanceof WC_Order
            ? (string) $persisted_renewal->get_payment_method()
            : '';
        $persisted_renewal_auto         = $persisted_renewal instanceof WC_Order
            ? (string) $persisted_renewal->get_meta('UPayments_AutoDeduction')
            : '';
        $persisted_renewal_parent       = $persisted_renewal instanceof WC_Order
            ? (int) $persisted_renewal->get_meta('UPayments_ParentOrderID')
            : 0;
        $persisted_renewal_payment_id   = $persisted_renewal instanceof WC_Order
            ? (string) $persisted_renewal->get_meta('UPayments_PaymentID')
            : '';
        $persisted_renewal_completed    = $persisted_renewal instanceof WC_Order
            ? $persisted_renewal->has_status('completed')
            : false;

        $renewal_verified = $persisted_renewal instanceof WC_Order
            && $persisted_renewal_txn_id === $payment_id
            && $persisted_renewal_payment_mthd === 'upayments'
            && $persisted_renewal_auto === 'yes'
            && $persisted_renewal_parent === (int) $order->get_id()
            && $persisted_renewal_payment_id === $payment_id
            && $persisted_renewal_completed;

        if (!$renewal_verified) {
            // Do NOT update parent _upay_last_billed_at.
            // Do NOT delete the claim.
            // Do NOT retry the gateway.
            // Do NOT create another renewal.
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            $logger->warning(
                'Renewal persistence verification failed; cycle held for reconciliation.',
                $context + [
                    'order_id'      => $order->get_id(),
                    'renewal_order' => $renewal_order_id,
                    'cycle'         => substr($cycle_key, 0, 12),
                    'http_status'   => $http_code,
                ]
            );
            return;
        }

        // ---- 7. Update parent subscription state ----
        // Capture the billed_at timestamp ONCE so the subsequent parent
        // persistence verification can compare against the exact value we
        // just wrote. The CycleClaim journal is the new authoritative
        // attempt record; the legacy metadata writes below are diagnostic/
        // backward-compatibility only and do NOT carry charge authority.
        $billed_at = current_time('mysql');
        $order->update_meta_data('_upay_last_billed_at', $billed_at);
        $order->delete_meta_data('_upay_retry_count');
        $order->delete_meta_data('_upay_last_attempt_at');
        $order->delete_meta_data('_upay_last_failed_reason');
        $order->update_meta_data('_upay_subscription_status', 'active');
        $order->save();

        // ---- 8. Parent persistence verification (fresh WooCommerce read) ----
        // Re-read the parent and verify the exact _upay_last_billed_at
        // value we just wrote. Any mismatch, missing meta, or inability
        // to reload the parent order ⇒ HELD. The remote payment / renewal
        // may already exist, so we MUST NOT automatically retry.
        $persisted_parent          = wc_get_order($order->get_id());
        $persisted_parent_billed   = $persisted_parent instanceof WC_Order
            ? (string) $persisted_parent->get_meta('_upay_last_billed_at')
            : '';
        $persisted_parent_status   = $persisted_parent instanceof WC_Order
            ? (string) ($persisted_parent->get_meta('_upay_subscription_status') ?: 'active')
            : '';

        $parent_verified = $persisted_parent instanceof WC_Order
            && $persisted_parent_billed !== ''
            && $persisted_parent_billed === (string) $billed_at
            && $persisted_parent_status === 'active';

        if (!$parent_verified) {
            // Do NOT call mark_resolved() — persistence state is unknown.
            CycleClaim::mark_held($cycle_key, $owner_token, $curl_errno, $http_code);
            $logger->error(
                'Parent billing-state persistence verification failed; cycle held for reconciliation.',
                $context + [
                    'order_id'      => $order->get_id(),
                    'renewal_order' => $renewal_order_id,
                    'cycle'         => substr($cycle_key, 0, 12),
                    'http_status'   => $http_code,
                ]
            );
            return;
        }

        // ---- 9. Finalize cycle ----
        // BLOCKER 11: Check the mark_resolved return value. A failed
        // journal update AFTER both fresh-read verifications succeeded is
        // a journal-consistency / observability problem, NOT authority to
        // recharge. We MUST NOT roll back the paid renewal, MUST NOT roll
        // back the parent last_billed, and MUST NOT issue another charge.
        $resolved_ok = CycleClaim::mark_resolved(
            $cycle_key,
            $owner_token,
            $renewal_order_id,
            $payment_id
        );
        if (!$resolved_ok) {
            $logger->error(
                'Cycle journal mark_resolved returned false after successful finalization; reconciliation gap.',
                $context + [
                    'order_id'      => $order->get_id(),
                    'renewal_order' => $renewal_order_id,
                    'cycle'         => substr($cycle_key, 0, 12),
                    'http_status'   => $http_code,
                ]
            );
        }
    }

    /**
     * Fetch UPayments gateway safely.
     */
    protected static function getGateway()
    {
        if (!function_exists('WC')) {
            return null;
        }

        WC()->payment_gateways();
        $gateways = WC()->payment_gateways->payment_gateways();

        return $gateways['upayments'] ?? null;
    }

    public static function getNextBillingDate(?DateTime $start, string $plan, int $interval): ?DateTime
    {
        if (!$start) {
            return null;
        }
        if (is_string($start)) {
            $date = new DateTime($start);
        } elseif ($start instanceof DateTimeInterface) {
            $date = clone $start;
        } else {
            return null;
        }

        switch ($plan) {
            case 'daily':
                $date->modify("+{$interval} day");
                break;
            case 'weekly':
                $date->modify("+{$interval} week");
                break;
            case 'monthly':
                $date->modify("+{$interval} month");
                break;
            case 'quarterly':
                $date->modify("+" . ($interval * 3) . " month");
                break;
            case 'yearly':
                $date->modify("+{$interval} year");
                break;
        }

        return $date;
    }

    /**
     * Backward-compatibility retained. NOT used by process(). The retry
     * counter is a diagnostic field only; it does not authorize another
     * POST for a held cycle.
     */
    public static function upayShouldAttemptRetry(WC_Order $order): bool
    {
        $status = $order->get_meta('_upay_subscription_status') ?: 'active';
        if (in_array($status, ['paused', 'cancelled'], true)) {
            return false;
        }

        $retry_count = (int) $order->get_meta('_upay_retry_count');
        $last_attempt = $order->get_meta('_upay_last_attempt_at');

        if ($retry_count >= 3) {
            return false;
        }

        if (!$last_attempt) {
            return true;
        }

        $last_attempt_dt = new DateTime($last_attempt, wp_timezone());
        $now = new DateTime('now', wp_timezone());

        $delays = [1, 6, 24];
        $hours  = $delays[min($retry_count, count($delays) - 1)];

        $next_allowed = clone $last_attempt_dt;
        $next_allowed->modify("+{$hours} hour");

        return $now >= $next_allowed;
    }
}
