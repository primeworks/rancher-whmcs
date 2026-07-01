<?php
/**
 * StorageUpgradeStore
 *
 * Tracks each service's current provisioned Odoo filestore size and
 * upgrade history, using the same tbladdonmodules JSON-blob pattern
 * as DomainRecordStore and DomainRetryStore.
 *
 * Keyed by serviceId. Records the current provisioned GB so the UI
 * can display it without re-querying Kubernetes every time, and so
 * the upgrade handler knows the current size before patching.
 *
 * Storage: tbladdonmodules (module='rancherfleet_storage',
 * setting='storage_records'), value is a JSON object keyed by serviceId.
 */

namespace RancherFleet;

class StorageUpgradeStore
{
    const MODULE_NAME  = 'rancherfleet_storage';
    const SETTING_NAME = 'storage_records';

    /**
     * Returns the storage record for a service, or a default if none exists.
     *
     * @param  int $serviceId
     * @return array  keys: provisionedGb, pvcName, lastUpgradedAt, history[]
     */
    public static function get($serviceId)
    {
        $all = self::loadAll();
        $key = (string)(int)$serviceId;

        return isset($all[$key]) ? $all[$key] : array(
            'provisionedGb'  => null,   // null = not yet recorded, read from PVC
            'pvcName'        => null,
            'lastUpgradedAt' => null,
            'history'        => array(),
        );
    }

    /**
     * Records a completed storage upgrade.
     *
     * @param  int    $serviceId
     * @param  string $pvcName       e.g. "odoo-filestore"
     * @param  int    $newSizeGb     New total provisioned size after upgrade
     * @param  int    $addedGb       How many GB were added this upgrade
     * @param  float  $chargeAmount
     * @param  int    $invoiceId
     */
    public static function recordUpgrade($serviceId, $pvcName, $newSizeGb, $addedGb, $chargeAmount, $invoiceId)
    {
        $all = self::loadAll();
        $key = (string)(int)$serviceId;

        $existing = isset($all[$key]) ? $all[$key] : array(
            'provisionedGb'  => null,
            'pvcName'        => null,
            'lastUpgradedAt' => null,
            'history'        => array(),
        );

        $existing['provisionedGb']  = $newSizeGb;
        $existing['pvcName']        = $pvcName;
        $existing['lastUpgradedAt'] = date('Y-m-d H:i:s');

        $existing['history'][] = array(
            'at'           => date('Y-m-d H:i:s'),
            'addedGb'      => $addedGb,
            'newTotalGb'   => $newSizeGb,
            'chargeAmount' => $chargeAmount,
            'invoiceId'    => $invoiceId,
        );

        // Keep last 20 upgrade events
        if (count($existing['history']) > 20) {
            $existing['history'] = array_slice($existing['history'], -20);
        }

        $all[$key] = $existing;
        self::saveAll($all);
    }

    /**
     * Updates the cached provisioned size (called after reading from the
     * Kubernetes API, so we have a fresh baseline even if no upgrade has
     * been recorded yet).
     *
     * @param  int    $serviceId
     * @param  string $pvcName
     * @param  int    $provisionedGb
     */
    public static function updateProvisionedSize($serviceId, $pvcName, $provisionedGb)
    {
        $all = self::loadAll();
        $key = (string)(int)$serviceId;

        if (!isset($all[$key])) {
            $all[$key] = array(
                'provisionedGb'  => $provisionedGb,
                'pvcName'        => $pvcName,
                'lastUpgradedAt' => null,
                'history'        => array(),
            );
        } else {
            $all[$key]['provisionedGb'] = $provisionedGb;
            $all[$key]['pvcName']       = $pvcName;
        }

        self::saveAll($all);
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

    private static function saveAll(array $records)
    {
        $json = json_encode($records);

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
