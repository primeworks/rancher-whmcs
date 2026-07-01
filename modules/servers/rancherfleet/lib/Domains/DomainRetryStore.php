<?php
/**
 * DomainRetryStore
 *
 * Self-contained pending-order tracker for domain registrations that failed
 * at the ResellerClub step (most commonly: reseller wallet underfunded).
 *
 * Deliberately independent from RancherFleet\RetryQueue: that queue is
 * built around infrastructure phases (no money involved, uncapped retry)
 * keyed by service_id+namespace. Domain orders are keyed by domain name,
 * already have a captured customer payment sitting in escrow conceptually,
 * and need a hard attempt cap that triggers a refund — different enough
 * semantics that bolting onto the existing queue would risk corrupting its
 * behavior for the infrastructure phases that depend on it today.
 *
 * Storage: a single JSON blob in tbladdonmodules (module='rancherfleet_domains',
 * setting='pending_orders'), keyed by a generated order token. This avoids
 * a schema migration; WHMCS addon modules commonly use this table for
 * exactly this kind of lightweight persistent state.
 */

namespace RancherFleet\Domains;

class DomainRetryStore
{
    const MODULE_NAME  = 'rancherfleet_domains';
    const SETTING_NAME = 'pending_orders';

    /**
     * Creates a new pending order record after a successful payment capture
     * but failed (or not-yet-attempted) ResellerClub registration.
     *
     * @param  array $orderData  Arbitrary order context needed to retry:
     *   clientId, domainName, years, ns, customerId, regContactId,
     *   adminContactId, techContactId, billingContactId,
     *   chargeAmount, chargeCurrency, gatewayModule, transactionId
     *   (the captured payment's WHMCS transaction ID, needed to refund)
     * @return string  order token (used to find/update/remove this record)
     */
    public static function create(array $orderData)
    {
        $token = bin2hex(random_bytes(8));
        $orders = self::loadAll();

        $orders[$token] = array_merge($orderData, array(
            'attempts'      => 0,
            'max_attempts'  => self::getMaxAttempts(),
            'next_retry_at' => date('Y-m-d H:i:s'),
            'status'        => 'pending',
            'last_error'    => '',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ));

        self::saveAll($orders);
        return $token;
    }

    /**
     * Returns all orders whose next_retry_at has passed and are still
     * 'pending' (not yet succeeded, not yet exhausted/refunded).
     *
     * @return array  token => orderData
     */
    public static function getDueOrders()
    {
        $orders = self::loadAll();
        $due    = array();
        $now    = date('Y-m-d H:i:s');

        foreach ($orders as $token => $order) {
            if (isset($order['status']) && $order['status'] === 'pending'
                && isset($order['next_retry_at']) && $order['next_retry_at'] <= $now) {
                $due[$token] = $order;
            }
        }

        return $due;
    }

    /**
     * Records a failed retry attempt. If max_attempts is reached, marks the
     * order 'exhausted' (caller is responsible for issuing the refund and
     * should then call markRefunded()) rather than scheduling another retry.
     *
     * @param  string $token
     * @param  string $errorMessage
     * @return bool    true if order is now exhausted (caller must refund),
     *                  false if another retry was scheduled
     */
    public static function recordFailure($token, $errorMessage)
    {
        $orders = self::loadAll();
        if (!isset($orders[$token])) {
            return false;
        }

        $orders[$token]['attempts']   += 1;
        $orders[$token]['last_error']  = $errorMessage;
        $orders[$token]['updated_at']  = date('Y-m-d H:i:s');

        $maxAttempts = isset($orders[$token]['max_attempts'])
            ? (int)$orders[$token]['max_attempts']
            : self::getMaxAttempts();

        $exhausted = $orders[$token]['attempts'] >= $maxAttempts;

        if ($exhausted) {
            $orders[$token]['status'] = 'exhausted';
        } else {
            $intervalHours = self::getRetryIntervalHours();
            $orders[$token]['next_retry_at'] = date('Y-m-d H:i:s', strtotime("+{$intervalHours} hours"));
        }

        self::saveAll($orders);
        return $exhausted;
    }

    /**
     * Marks an order as successfully completed (ResellerClub registration
     * and DNS setup both succeeded). The record is kept (status changed,
     * not deleted) for a short audit trail; callers may prune old
     * completed/refunded records separately if the blob grows large.
     */
    public static function markSucceeded($token, array $resultData = array())
    {
        $orders = self::loadAll();
        if (!isset($orders[$token])) {
            return;
        }
        $orders[$token]['status']     = 'succeeded';
        $orders[$token]['updated_at'] = date('Y-m-d H:i:s');
        $orders[$token]['result']     = $resultData;
        self::saveAll($orders);
    }

    /**
     * Marks an order as refunded after exhausting all retry attempts.
     * Called by the cron handler once the refund API call has succeeded.
     */
    public static function markRefunded($token, $refundTransactionId = '')
    {
        $orders = self::loadAll();
        if (!isset($orders[$token])) {
            return;
        }
        $orders[$token]['status']               = 'refunded';
        $orders[$token]['refund_transaction_id'] = $refundTransactionId;
        $orders[$token]['updated_at']            = date('Y-m-d H:i:s');
        self::saveAll($orders);
    }

    /**
     * Marks a refund attempt itself as failed (e.g. gateway refund API
     * errored). Left in 'exhausted' status with the error recorded so an
     * admin can see it needs manual handling — this case should be rare
     * enough that automatic re-attempting the refund isn't worth the
     * complexity, but the failure is never silently dropped.
     */
    public static function markRefundFailed($token, $errorMessage)
    {
        $orders = self::loadAll();
        if (!isset($orders[$token])) {
            return;
        }
        $orders[$token]['status']            = 'refund_failed';
        $orders[$token]['refund_error']      = $errorMessage;
        $orders[$token]['updated_at']        = date('Y-m-d H:i:s');
        self::saveAll($orders);
    }

    public static function get($token)
    {
        $orders = self::loadAll();
        return isset($orders[$token]) ? $orders[$token] : null;
    }

    public static function getAllForClient($clientId)
    {
        $orders = self::loadAll();
        $result = array();
        foreach ($orders as $token => $order) {
            if (isset($order['clientId']) && (int)$order['clientId'] === (int)$clientId) {
                $result[$token] = $order;
            }
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    /**
     * Reads the configurable retry interval (hours) from this addon's
     * module settings, falling back to 2 hours if not yet configured.
     */
    private static function getRetryIntervalHours()
    {
        $value = self::readModuleSetting('retry_interval_hours');
        $hours = is_numeric($value) ? (int)$value : 2;
        return $hours > 0 ? $hours : 2;
    }

    /**
     * Reads the configurable max attempts before refund, falling back to 10.
     */
    private static function getMaxAttempts()
    {
        $value = self::readModuleSetting('max_retry_attempts');
        $max   = is_numeric($value) ? (int)$value : 10;
        return $max > 0 ? $max : 10;
    }

    private static function readModuleSetting($settingName)
    {
        try {
            $row = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE_NAME)
                ->where('setting', $settingName)
                ->first();
            return $row ? $row->value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------------

    private static function loadAll()
    {
        try {
            $row = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE_NAME)
                ->where('setting', self::SETTING_NAME)
                ->first();

            if (!$row || empty($row->value)) {
                return array();
            }

            $decoded = json_decode($row->value, true);
            return is_array($decoded) ? $decoded : array();
        } catch (\Exception $e) {
            return array();
        }
    }

    private static function saveAll(array $orders)
    {
        $json = json_encode($orders);

        $exists = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE_NAME)
            ->where('setting', self::SETTING_NAME)
            ->exists();

        if ($exists) {
            \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE_NAME)
                ->where('setting', self::SETTING_NAME)
                ->update(array('value' => $json));
        } else {
            \WHMCS\Database\Capsule::table('tbladdonmodules')->insert(array(
                'module'  => self::MODULE_NAME,
                'setting' => self::SETTING_NAME,
                'value'   => $json,
            ));
        }
    }
}
