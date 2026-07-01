<?php
/**
 * DomainRecordStore
 *
 * ResellersPanel's API has no command to list a client's existing
 * domains, fetch a domain's expiry date, or read back currently-set
 * DNS/nameservers. Every one of those facts is therefore tracked HERE,
 * at the moment we ourselves register, renew, or wire DNS for a domain —
 * this store is the single source of truth the rest of the module
 * consults for "do we already have this domain", "when does it expire",
 * and "is DNS already wired", rather than ever attempting to ask the
 * registrar API for that information after the fact.
 *
 * Storage: JSON blob in tbladdonmodules (module='rancherfleet_domains',
 * setting='domain_records'), keyed by "sld.tld" — same lightweight
 * pattern as DomainRetryStore, avoiding a schema migration.
 */

namespace RancherFleet\Domains;

class DomainRecordStore
{
    const MODULE_NAME  = 'rancherfleet_domains';
    const SETTING_NAME = 'domain_records';

    /**
     * Creates or overwrites the record for a domain immediately after a
     * successful registration.
     *
     * @param  string $sld
     * @param  string $tld
     * @param  array  $data  keys: serviceId, clientId, namespace,
     *   registeredAt (Y-m-d), periodYears, expiresAt (Y-m-d, computed by
     *   caller as registeredAt + periodYears), cloudflareZoneId,
     *   cnameHost (default 'www'), cnameTarget, dnsWiredAt (Y-m-d H:i:s
     *   or null until confirmed wired)
     */
    public static function create($sld, $tld, array $data)
    {
        $key  = self::key($sld, $tld);
        $all  = self::loadAll();

        $all[$key] = array_merge(array(
            'sld'         => $sld,
            'tld'         => $tld,
            'status'      => 'active',
            'dnsWiredAt'  => null,
            'createdAt'   => date('Y-m-d H:i:s'),
            'updatedAt'   => date('Y-m-d H:i:s'),
        ), $data);

        self::saveAll($all);
    }

    /**
     * Looks up a domain record by name. Returns null if we have no
     * record of ever registering/renewing this domain ourselves.
     *
     * @param  string $sld
     * @param  string $tld
     * @return array|null
     */
    public static function get($sld, $tld)
    {
        $all = self::loadAll();
        $key = self::key($sld, $tld);
        return isset($all[$key]) ? $all[$key] : null;
    }

    /**
     * Returns all domain records associated with a given WHMCS service ID.
     * A service can have multiple domains (e.g. a renewed/replaced one),
     * though typically just one.
     *
     * @param  int $serviceId
     * @return array  key => record
     */
    public static function getForService($serviceId)
    {
        $all = self::loadAll();
        $result = array();
        foreach ($all as $key => $record) {
            if (isset($record['serviceId']) && (int)$record['serviceId'] === (int)$serviceId) {
                $result[$key] = $record;
            }
        }
        return $result;
    }

    /**
     * Marks DNS as confirmed wired (CNAME + A record created successfully
     * on Cloudflare, nameservers pointed via ResellersPanel).
     */
    public static function markDnsWired($sld, $tld)
    {
        $all = self::loadAll();
        $key = self::key($sld, $tld);
        if (!isset($all[$key])) {
            return;
        }
        $all[$key]['dnsWiredAt'] = date('Y-m-d H:i:s');
        $all[$key]['updatedAt']  = date('Y-m-d H:i:s');
        self::saveAll($all);
    }

    /**
     * Updates the expiry date after a successful renewal, and clears any
     * 'expired' status back to 'active'.
     *
     * @param  string $sld
     * @param  string $tld
     * @param  string $newExpiresAt  Y-m-d
     */
    public static function recordRenewal($sld, $tld, $newExpiresAt)
    {
        $all = self::loadAll();
        $key = self::key($sld, $tld);
        if (!isset($all[$key])) {
            return;
        }
        $all[$key]['expiresAt'] = $newExpiresAt;
        $all[$key]['status']    = 'active';
        $all[$key]['updatedAt'] = date('Y-m-d H:i:s');
        self::saveAll($all);
    }

    /**
     * Computes a display-ready expiry status for the client area UI.
     *
     * @param  array $record  as returned by get()/getForService()
     * @return array  array('daysRemaining' => int, 'state' => 'active'|'expiring'|'expired')
     */
    public static function getExpiryStatus(array $record)
    {
        $expiresAt = isset($record['expiresAt']) ? $record['expiresAt'] : null;
        if (!$expiresAt) {
            return array('daysRemaining' => null, 'state' => 'unknown');
        }

        $now  = new \DateTime();
        $exp  = new \DateTime($expiresAt);
        $diff = (int)$now->diff($exp)->format('%r%a'); // signed day count

        if ($diff < 0) {
            return array('daysRemaining' => $diff, 'state' => 'expired');
        }
        if ($diff <= 30) {
            return array('daysRemaining' => $diff, 'state' => 'expiring');
        }
        return array('daysRemaining' => $diff, 'state' => 'active');
    }

    private static function key($sld, $tld)
    {
        return strtolower(trim($sld, '.')) . '.' . strtolower(trim($tld, '.'));
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
