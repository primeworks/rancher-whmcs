<?php
/**
 * RancherFleet WHMCS Provisioning Module
 *
 * Phased, testable provisioning module for Rancher 2.8 Fleet GitOps.
 *
 * Phases:
 *   1. Test Connection    - validate Rancher API credentials
 *   2. Create Namespace   - create whmcs-client-{id} namespace
 *   3. Bootstrap GitHub   - push starter manifests to repo
 *   4. Create GitRepo     - create Fleet GitRepo CRD object
 *   5. Suspend/Unsuspend  - scale deployments to 0 / back to 1
 *
 * @version 3.4.0 - Phase E: Bulk Admin, Retry Queue, Webhook, Dry Run, Custom Image
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/RancherClient.php';
require_once __DIR__ . '/lib/GitHubClient.php';
require_once __DIR__ . '/lib/FleetHelper.php';
require_once __DIR__ . '/lib/RetryQueue.php';
require_once __DIR__ . '/lib/LonghornClient.php';
require_once __DIR__ . '/lib/StorageUpgradeStore.php';
require_once __DIR__ . '/lib/Domains/ResellersPanelClient.php';
require_once __DIR__ . '/lib/Domains/CloudflareClient.php';
require_once __DIR__ . '/lib/Domains/DomainRetryStore.php';
require_once __DIR__ . '/lib/Domains/DomainRecordStore.php';
require_once __DIR__ . '/lib/Domains/IngressHelper.php';
require_once __DIR__ . '/lib/Domains/DomainOrderManager.php';

// ---------------------------------------------------------------------------
// Module Metadata
// ---------------------------------------------------------------------------

function rancherfleet_MetaData()
{
    return array(
        'DisplayName'    => 'Rancher Fleet GitOps',
        'APIVersion'     => '1.1',
        'RequiresServer' => true,
    );
}

// ---------------------------------------------------------------------------
// Config Options (required by WHMCS even if empty)
// ---------------------------------------------------------------------------

function rancherfleet_ConfigOptions()
{
    return array(
        'Automatic Provisioning' => array(
            'FriendlyName' => 'Automatic Provisioning',
            'Type'         => 'yesno',
            'Description'  => 'Run all provisioning phases automatically when an order is paid. Uncheck to use the manual phase buttons instead.',
            'Default'      => 'on',
        ),
        'Suspend Grace Hours' => array(
            'FriendlyName' => 'Suspend Grace Period (hours)',
            'Type'         => 'text',
            'Size'         => '5',
            'Default'      => '0',
            'Description'  => 'Hours to wait before scaling replicas to 0 on suspension. Set to 0 for immediate suspension.',
        ),
        'Container CPU Request' => array(
            'FriendlyName' => 'Container CPU Request Override',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '',
            'Description'  => 'Override pod CPU request/limit e.g. 500m or 2. Leave blank to use User Count defaults.',
        ),
        'Container Memory Request' => array(
            'FriendlyName' => 'Container Memory Request Override',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '',
            'Description'  => 'Override pod memory request/limit e.g. 512Mi or 2Gi. Leave blank to use User Count defaults.',
        ),
        'Custom Image' => array(
            'FriendlyName' => 'Custom Container Image',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Override the container image in manifests e.g. myregistry.com/myapp:1.2.3. Leave blank to use template image.',
        ),
        'Dry Run Mode' => array(
            'FriendlyName' => 'Dry Run Mode',
            'Type'         => 'yesno',
            'Description'  => 'Simulate all phases without making any changes. Results logged to activity log. Useful for testing new configurations.',
            'Default'      => '',
        ),
        'User Count' => array(
            'FriendlyName' => 'User Count',
            'Type'         => 'dropdown',
            'Options'      => '2,5,15,20,35,45,55,65,75,85,95',
            'Default'      => '5',
            'Description'  => 'Number of users this instance will support. Determines pod CPU and memory resource requests/limits.',
        ),
        'Odoo Version' => array(
            'FriendlyName' => 'Odoo Version',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '19',
            'Description'  => 'Odoo image version to deploy e.g. 19 or 17.0. Replaces the version tag in all YAML manifests on the client branch.',
        ),
        'Database Server' => array(
            'FriendlyName' => 'Database Server',
            'Type'         => 'text',
            'Size'         => '20',
            'Default'      => 'db16',
            'Description'  => 'Database server identifier e.g. db16 or db19. Replaces all db{N} references in YAML manifests including FQDNs.',
        ),
        'ResellersPanel Store Username' => array(
            'FriendlyName' => 'ResellersPanel Store Username',
            'Type'         => 'text',
            'Size'         => '20',
            'Default'      => '',
            'Description'  => 'Your ResellersPanel store name (auth_username), from your reseller account.',
        ),
        'ResellersPanel API Password' => array(
            'FriendlyName' => 'ResellersPanel API Password',
            'Type'         => 'password',
            'Size'         => '40',
            'Default'      => '',
            'Description'  => 'Your ResellersPanel API password (auth_password).',
        ),
        'ResellersPanel API URL' => array(
            'FriendlyName' => 'ResellersPanel API URL',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => '',
            'Description'  => 'Base URL for the ResellersPanel API endpoint, e.g. https://api.resellerspanel.com. Confirm the exact host with ResellersPanel support or your control panel.',
        ),
        'Cloudflare API Token' => array(
            'FriendlyName' => 'Cloudflare API Token',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Cloudflare API Token (My Profile > API Tokens) with Zone:Edit, Zone:Read, and DNS:Edit permissions. Used to host DNS for purchased domains, since ResellersPanel only sets nameservers.',
        ),
        'Domain CNAME Target' => array(
            'FriendlyName' => 'Domain CNAME Target',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => 'cowboy.webdiscode.com',
            'Description'  => 'Target host for the www CNAME record created on customer-purchased domains.',
        ),
        'Domain Retry Interval Hours' => array(
            'FriendlyName' => 'Domain Retry Interval (Hours)',
            'Type'         => 'text',
            'Size'         => '5',
            'Default'      => '2',
            'Description'  => 'How often to retry ResellersPanel registration after a failure (e.g. underfunded reseller wallet) before giving up and refunding.',
        ),
        'Domain Max Retry Attempts' => array(
            'FriendlyName' => 'Domain Max Retry Attempts',
            'Type'         => 'text',
            'Size'         => '5',
            'Default'      => '10',
            'Description'  => 'Number of registration retry attempts before the customer is automatically refunded.',
        ),
        'Storage Price Per GB' => array(
            'FriendlyName' => 'Storage Price Per GB',
            'Type'         => 'text',
            'Size'         => '8',
            'Default'      => '2.00',
            'Description'  => 'Price per GB for Odoo filestore storage upgrades (e.g. 2.00 for $2/GB). Customer is charged at this rate times the number of GB they select.',
        ),
        'Storage Upgrade Increments' => array(
            'FriendlyName' => 'Storage Upgrade Increments (GB)',
            'Type'         => 'text',
            'Size'         => '30',
            'Default'      => '5,10,20,50',
            'Description'  => 'Comma-separated list of GB increment options shown to customers e.g. 5,10,20,50.',
        ),
        'Storage Alert Threshold' => array(
            'FriendlyName' => 'Storage Alert Threshold (%)',
            'Type'         => 'text',
            'Size'         => '5',
            'Default'      => '80',
            'Description'  => 'Usage percentage at which the storage upgrade prompt appears (default 80).',
        ),
        'DB Admin Username' => array(
            'FriendlyName' => 'DB Admin Username',
            'Type'         => 'text',
            'Size'         => '30',
            'Default'      => 'postgres',
            'Description'  => 'Postgres superuser/admin username used to drop client databases on termination.',
        ),
        'DB Admin Password' => array(
            'FriendlyName' => 'DB Admin Password',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Postgres admin password for the DB Admin Username above.',
        ),
        'Backup Auth Secret' => array(
            'FriendlyName' => 'Backup Auth Secret',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'A long random string used to sign backup download tokens. '
                            . 'Must match the secret in backup-auth.php on your web server. '
                            . 'Generate one with: openssl rand -hex 32',
        ),
        'Odoo Upgrade Versions' => array(
            'FriendlyName' => 'Available Upgrade Versions',
            'Type'         => 'text',
            'Size'         => '100',
            'Default'      => '17,18',
            'Description'  => 'Comma-separated list of Odoo major versions available for upgrade e.g. 17,18. Only versions higher than the current instance version will be offered to customers. Only versions with available OpenUpgrade Docker images can be used. Currently supported: 17, 18',
        ),
        'Odoo Upgrade Fees' => array(
            'FriendlyName' => 'Upgrade Fees per Version',
            'Type'         => 'text',
            'Size'         => '100',
            'Default'      => '20.0:49.00,21.0:49.00',
            'Description'  => 'Comma-separated version:fee pairs e.g. 20.0:49.00,21.0:79.00. Fee charged from customer credit balance.',
        ),
    );
}

// ---------------------------------------------------------------------------
// Custom Fields — shown to client at order/service level
// ---------------------------------------------------------------------------

/**
 * Defines custom fields shown on the order form and service detail page.
 * These are separate from module ConfigOptions (which are admin/product level).
 */

// ---------------------------------------------------------------------------
// Internal: check if automatic provisioning is enabled
// ---------------------------------------------------------------------------

function rancherfleet_isAutomatic(array $params)
{
    // WHMCS passes module config options under $params['configoptions'][FriendlyName]
    // for provisioning modules with ConfigOptions().
    if (isset($params['configoptions']['Automatic Provisioning'])) {
        return $params['configoptions']['Automatic Provisioning'] === 'on';
    }
    // Default to automatic if not set
    return true;
}

// ---------------------------------------------------------------------------
// Phase C + E: Resource Quota config helper
// ---------------------------------------------------------------------------

/**
 * Resource quota table keyed by user count.
 * Returns quota array for a given user count.
 */
function rancherfleet_getUserQuota($userCount)
{
    // cpu_request/limit and memory_request/limit are injected directly into pod specs.
    // Limits are set to 2x the request to allow bursting.
    $table = array(
        2  => array('storage' => '5Gi',  'cpu_request' => '500m', 'cpu_limit' => '1',    'memory_request' => '512Mi', 'memory_limit' => '1Gi'),
        5  => array('storage' => '5Gi',  'cpu_request' => '500m', 'cpu_limit' => '1',    'memory_request' => '512Mi', 'memory_limit' => '1Gi'),
        15 => array('storage' => '10Gi', 'cpu_request' => '1',    'cpu_limit' => '2',    'memory_request' => '1Gi',   'memory_limit' => '2Gi'),
        20 => array('storage' => '10Gi', 'cpu_request' => '1',    'cpu_limit' => '2',    'memory_request' => '1Gi',   'memory_limit' => '2Gi'),
        35 => array('storage' => '20Gi', 'cpu_request' => '2',    'cpu_limit' => '4',    'memory_request' => '2Gi',   'memory_limit' => '4Gi'),
        45 => array('storage' => '20Gi', 'cpu_request' => '2',    'cpu_limit' => '4',    'memory_request' => '2Gi',   'memory_limit' => '4Gi'),
        55 => array('storage' => '30Gi', 'cpu_request' => '3',    'cpu_limit' => '6',    'memory_request' => '3Gi',   'memory_limit' => '6Gi'),
        65 => array('storage' => '40Gi', 'cpu_request' => '4',    'cpu_limit' => '8',    'memory_request' => '4Gi',   'memory_limit' => '8Gi'),
        75 => array('storage' => '40Gi', 'cpu_request' => '4',    'cpu_limit' => '8',    'memory_request' => '4Gi',   'memory_limit' => '8Gi'),
        85 => array('storage' => '50Gi', 'cpu_request' => '6',    'cpu_limit' => '10',   'memory_request' => '6Gi',   'memory_limit' => '10Gi'),
        95 => array('storage' => '60Gi', 'cpu_request' => '8',    'cpu_limit' => '12',   'memory_request' => '8Gi',   'memory_limit' => '12Gi'),
    );

    // Exact match first
    if (isset($table[$userCount])) {
        return $table[$userCount];
    }

    // Find the nearest tier below, fall back to highest
    $keys = array_keys($table);
    sort($keys);
    $selected = $keys[0];
    foreach ($keys as $k) {
        if ($k <= $userCount) $selected = $k;
    }
    return $table[$selected];
}

/**
 * Returns the configured user count from product config options.
 */
function rancherfleet_getUserCount(array $params)
{
    $co = isset($params['configoptions']) ? $params['configoptions'] : array();
    $val = !empty($co['User Count']) ? (int)trim($co['User Count']) : 5;
    return max(2, $val);
}

/**
 * Namespace ResourceQuota has been removed.
 * Resource limits are now set directly on pod specs via getContainerLimits().
 * This stub is retained to avoid fatal errors if called from old code paths.
 */
function rancherfleet_getQuotaLimits(array $params)
{
    return array();
}

/**
 * Returns the Odoo image version.
 * Priority: configoptions (product Module Settings) > customfields > default
 */
function rancherfleet_getOdooImageVersion(array $params)
{
    // 1. Friendly name in configoptions array
    $co = isset($params['configoptions']) ? $params['configoptions'] : array();
    if (!empty($co['Odoo Version'])) return trim($co['Odoo Version']);

    // 2. Service custom fields
    $customfields = isset($params['customfields']) ? $params['customfields'] : array();
    if (!empty($customfields['Odoo Version'])) return trim($customfields['Odoo Version']);

    // 3. DB lookup
    $serviceId = (int)$params['serviceid'];
    try {
        $val = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfieldsvalues.relid', $serviceId)
            ->whereIn('tblcustomfields.fieldname', array('Odoo Version', 'odoo_version'))
            ->value('tblcustomfieldsvalues.value');
        if (!empty($val)) return trim($val);
    } catch (\Exception $e) {}

    return '19';
}


/**
 * Returns the database server identifier.
 * Priority: configoptions (product Module Settings) > customfields > default
 */
function rancherfleet_getDbServer(array $params)
{
    // 1. Product Module Settings (ConfigOptions)
    $co = isset($params['configoptions']) ? $params['configoptions'] : array();
    if (!empty($co['Database Server'])) {
        return trim($co['Database Server']);
    }

    // 2. Service-level custom fields
    foreach (array('customfields') as $key) {
        if (isset($params[$key]['Database Server']) && !empty($params[$key]['Database Server'])) {
            return trim($params[$key]['Database Server']);
        }
    }

    // 3. DB lookup
    $serviceId = (int)$params['serviceid'];
    try {
        $field = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfieldsvalues.relid', $serviceId)
            ->whereIn('tblcustomfields.fieldname', array('Database Server', 'db_server'))
            ->value('tblcustomfieldsvalues.value');
        if (!empty($field)) return trim($field);
    } catch (\Exception $e) { /* fallback */ }

    return 'db16';
}

// ---------------------------------------------------------------------------
// Phase D: Grace period helper
// ---------------------------------------------------------------------------

/**
 * Returns the configured suspend grace period in hours.
 * 0 means suspend immediately.
 */
function rancherfleet_getGraceHours(array $params)
{
    $co    = isset($params['configoptions']) ? $params['configoptions'] : array();
    $hours = !empty($co['Suspend Grace Hours']) ? (int)trim($co['Suspend Grace Hours']) : 0;
    return max(0, $hours);
}

/**
 * Returns pod resource requests and limits.
 *
 * Priority:
 *   1. Manual override from Module Settings (Container CPU/Memory Request Override)
 *   2. User Count tier table (cpu_request, cpu_limit, memory_request, memory_limit)
 *
 * Returns array with keys: cpu_request, cpu_limit, memory_request, memory_limit
 * (All keys always present so substituteResources() can inject both requests and limits.)
 */
function rancherfleet_getContainerLimits(array $params)
{
    $co          = isset($params['configoptions']) ? $params['configoptions'] : array();
    $userCount   = rancherfleet_getUserCount($params);
    $tier        = rancherfleet_getUserQuota($userCount);

    // Manual overrides — if set they apply to both request and limit
    $manualCpu    = !empty($co['Container CPU Request'])    ? trim($co['Container CPU Request'])    : '';
    $manualMemory = !empty($co['Container Memory Request']) ? trim($co['Container Memory Request']) : '';

    $cpuRequest    = $manualCpu    ?: (isset($tier['cpu_request'])    ? $tier['cpu_request']    : '');
    $cpuLimit      = $manualCpu    ?: (isset($tier['cpu_limit'])      ? $tier['cpu_limit']      : '');
    $memRequest    = $manualMemory ?: (isset($tier['memory_request']) ? $tier['memory_request'] : '');
    $memLimit      = $manualMemory ?: (isset($tier['memory_limit'])   ? $tier['memory_limit']   : '');

    RancherFleet\Logger::info("getContainerLimits: users={$userCount} cpu={$cpuRequest}/{$cpuLimit} mem={$memRequest}/{$memLimit}"
        . ($manualCpu || $manualMemory ? ' (manual override)' : ' (tier defaults)'));

    return array_filter(array(
        'cpu_request'    => $cpuRequest,
        'cpu_limit'      => $cpuLimit,
        'memory_request' => $memRequest,
        'memory_limit'   => $memLimit,
    ));
}

/**
 * Returns the custom container image override, or empty string if not set.
 */
function rancherfleet_getCustomImage(array $params)
{
    $co = isset($params['configoptions']) ? $params['configoptions'] : array();
    return !empty($co['Custom Image']) ? trim($co['Custom Image']) : '';
}

/**
 * Returns true if dry run mode is enabled.
 * In dry run mode all phases are simulated without making any changes.
 */
function rancherfleet_isDryRun(array $params)
{
    if (isset($params['configoptions']['Dry Run Mode'])) {
        return $params['configoptions']['Dry Run Mode'] === 'on';
    }
    return false;
}

/**
 * Stores a scheduled suspend timestamp for a service.
 * Used by the grace period mechanism.
 */
function rancherfleet_scheduleGraceSuspend(array $params, $executeAt)
{
    $serviceId = (int)$params['serviceid'];
    $key       = 'rf_grace_suspend_' . $serviceId;
    $value     = $key . '|' . $executeAt;

    try {
        $exists = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceId)
            ->where('fieldid', 0)
            ->where('value', 'like', $key . '|%')
            ->exists();

        if ($exists) {
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)
                ->where('fieldid', 0)
                ->where('value', 'like', $key . '|%')
                ->update(array('value' => $value));
        } else {
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')->insert(array(
                'fieldid' => 0,
                'relid'   => $serviceId,
                'value'   => $value,
            ));
        }
    } catch (\Exception $e) {
        RancherFleet\Logger::error("scheduleGraceSuspend: DB error: " . $e->getMessage());
    }
}

/**
 * Returns the scheduled grace suspend timestamp for a service, or null if none.
 */
function rancherfleet_getScheduledGraceSuspend(array $params)
{
    $serviceId = (int)$params['serviceid'];
    $key       = 'rf_grace_suspend_' . $serviceId;

    try {
        $row = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceId)
            ->where('fieldid', 0)
            ->where('value', 'like', $key . '|%')
            ->value('value');

        if ($row) {
            return (int)substr($row, strlen($key) + 1);
        }
    } catch (\Exception $e) {
        // ignore
    }
    return null;
}

/**
 * Clears the scheduled grace suspend for a service.
 */
function rancherfleet_clearGraceSuspend(array $params)
{
    $serviceId = (int)$params['serviceid'];
    $key       = 'rf_grace_suspend_' . $serviceId;

    try {
        \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceId)
            ->where('fieldid', 0)
            ->where('value', 'like', $key . '|%')
            ->delete();
    } catch (\Exception $e) {
        // ignore
    }
}

// ---------------------------------------------------------------------------
// Backup system helpers
// ---------------------------------------------------------------------------

/**
 * Creates (or updates) the rfm-db-admin-{orderNum} and rfm-webhook-{orderNum}
 * Kubernetes Secrets in the client namespace.
 * rfm-db-admin holds Postgres credentials for the backup CronJob.
 * rfm-webhook holds the WHMCS webhook URL, shared secret, and service ID
 * so the CronJob can notify WHMCS when a backup completes.
 */
function rancherfleet_createDbAdminSecret(array $params, $rancher, $namespace, $orderNum)
{
    $dbUser    = isset($params['configoption20']) ? trim($params['configoption20']) : 'postgres';
    $dbPass    = isset($params['configoption21']) ? trim($params['configoption21']) : '';
    $authSecret = isset($params['configoption22']) ? trim($params['configoption22']) : '';
    $serviceId = (int)$params['serviceid'];

    if (empty($dbUser)) {
        RancherFleet\Logger::info("createDbAdminSecret: DB Admin Username not set, skipping");
        return;
    }

    // Determine WHMCS base URL for the webhook (with fallback if empty)
    $whmcsUrl = function_exists('App') ? \App::getSystemUrl() : '';
    if (empty($whmcsUrl)) {
        $whmcsUrl = 'https://host.webdiscode.com';
    }
    $webhookUrl = rtrim($whmcsUrl, '/') . '/modules/servers/rancherfleet/backup_webhook.php';

    $secrets = array(
        'rfm-db-admin-' . $orderNum => array(
            'username' => $dbUser,
            'password' => $dbPass,
        ),
        'rfm-webhook-' . $orderNum => array(
            'url'        => $webhookUrl,
            'secret'     => $authSecret,
            'service_id' => (string)$serviceId,
        ),
    );

    $secretsCreated = array();
    foreach ($secrets as $secretName => $data) {
        try {
            $rancher->applySecret($namespace, $secretName, $data);
            RancherFleet\Logger::info("createDbAdminSecret: applied {$secretName} in {$namespace}");
            $secretsCreated[$secretName] = true;
        } catch (\Exception $e) {
            RancherFleet\Logger::error("createDbAdminSecret: FAILED {$secretName} in {$namespace}: " . $e->getMessage());
            $secretsCreated[$secretName] = false;
        }
    }

    // Verify both required secrets were created
    if (empty($secretsCreated['rfm-db-admin-' . $orderNum])) {
        RancherFleet\Logger::error("createDbAdminSecret: rfm-db-admin-{$orderNum} not created in {$namespace}");
    }
    if (empty($secretsCreated['rfm-webhook-' . $orderNum])) {
        RancherFleet\Logger::error("createDbAdminSecret: rfm-webhook-{$orderNum} not created in {$namespace}");
    }
}

/**
 * Reads the backup manifest.json from the pod's /backups/ directory
 * via the Kubernetes API (no exec — reads the file as a ConfigMap-style
 * GET against the pod's filesystem via the Rancher proxy).
 *
 * Strategy: find a running Odoo pod in the namespace, then use the
 * Kubernetes exec-free file-read approach — actually we read via a
 * one-shot Job that writes manifest.json to a known location, then
 * reads it back. Since manifest.json is written by the CronJob, we
 * simply read it via a pod ephemeral container... 
 *
 * Simpler: mount the same PVC in a short-lived read Job and cat the
 * manifest — but that takes 10-30s. Instead, we read the manifest
 * indirectly: the Odoo pod already mounts the PVC at /var/lib/odoo,
 * and /backups is a subPath on the same PVC. We use the Rancher API
 * to exec `cat /backups/manifest.json` in the Odoo pod.
 *
 * Since exec uses wss:// which doesn't work from cPanel, we fall back
 * to storing the manifest content in the WHMCS database (tbladdonmodules)
 * each time the CronJob completes — via a completion webhook POST to
 * a WHMCS hook. Until then, we return a cached copy if available.
 *
 * @param  int    $serviceId
 * @return array  array of backup entries, each with name/size/mtime/type
 */
function rancherfleet_getBackupFiles($serviceId)
{
    try {
        $row = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_backups')
            ->where('setting', 'manifest_' . (int)$serviceId)
            ->value('value');

        if ($row) {
            $data = json_decode($row, true);
            return is_array($data) ? $data : array();
        }
    } catch (\Exception $e) {
        // ignore
    }
    return array();
}

/**
 * Updates the cached backup manifest for a service.
 * Called from the backup completion webhook hook.
 */
function rancherfleet_storeBackupManifest($serviceId, array $files)
{
    $json   = json_encode($files);
    $exists = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', 'rancherfleet_backups')
        ->where('setting', 'manifest_' . (int)$serviceId)
        ->exists();

    if ($exists) {
        \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_backups')
            ->where('setting', 'manifest_' . (int)$serviceId)
            ->update(array('value' => $json));
    } else {
        \WHMCS\Database\Capsule::table('tbladdonmodules')->insert(array(
            'module'  => 'rancherfleet_backups',
            'setting' => 'manifest_' . (int)$serviceId,
            'value'   => $json,
        ));
    }
}

/**
 * Generates a signed download token for a backup file.
 * Token format: HMAC-SHA256(secret, "{orderNum}|{filename}|{expires}")
 * encoded as hex, valid for 60 seconds.
 *
 * @param  string $secret    From Module Settings
 * @param  string $orderNum
 * @param  string $filename
 * @return array  array('url' => string, 'token' => string, 'expires' => int)
 */
function rancherfleet_generateBackupToken($secret, $orderNum, $filename)
{
    $expires = time() + 60;
    $data    = $orderNum . '|' . $filename . '|' . $expires;
    $token   = hash_hmac('sha256', $data, $secret);
    $url     = 'https://backups.webdiscode.com/' . rawurlencode($orderNum)
             . '/' . rawurlencode($filename)
             . '?token=' . $token . '&expires=' . $expires . '&order=' . rawurlencode($orderNum);
    return array('url' => $url, 'token' => $token, 'expires' => $expires);
}

/**
 * Renders the Backups card in the client area.
 */
function rancherfleet_backupPanelHtml(array $params, $namespace, $orderNum, $serviceUrl)
{
    $serviceId = (int)$params['serviceid'];
    $files     = rancherfleet_getBackupFiles($serviceId);

    $html  = '<div class="rfm-ca-card">';
    $html .= '<h4>Backups</h4>';

    if (empty($files)) {
        $html .= '<div style="font-size:12px;color:#888;">No backups available yet. Backups run automatically at 3am UTC daily and are retained for 3 days. Check back after the first scheduled run.</div>';
        $html .= '</div>';
        return $html;
    }

    // Group by date
    $byDate = array();
    foreach ($files as $f) {
        $date = isset($f['mtime']) ? date('Y-m-d', (int)$f['mtime']) : 'Unknown';
        $byDate[$date][] = $f;
    }
    krsort($byDate); // newest first

    // Get signing secret from Module Settings
    // configoption22 = Backup Auth Secret (to be added after SQL verification)
    $secret = isset($params['configoption22']) ? trim($params['configoption22']) : '';

    foreach ($byDate as $date => $entries) {
        $html .= '<div style="margin-bottom:14px;">';
        $html .= '<div style="font-size:12px;font-weight:bold;color:#555;margin-bottom:6px;border-bottom:1px solid #f0f0f0;padding-bottom:4px;">'
               . htmlspecialchars($date) . '</div>';

        foreach ($entries as $f) {
            $fname    = isset($f['name']) ? $f['name'] : '';
            $fsize    = isset($f['size']) ? (int)$f['size'] : 0;
            $ftype    = isset($f['type']) ? $f['type'] : 'unknown';
            $sizeStr  = $fsize > 1048576
                ? number_format($fsize / 1048576, 1) . ' MB'
                : number_format($fsize / 1024, 1) . ' KB';
            $typeLabel = $ftype === 'db' ? '&#128200; Database' : '&#128196; Filestore';
            $typeColor = $ftype === 'db' ? '#2980b9' : '#27ae60';

            $html .= '<div style="display:flex;align-items:center;justify-content:space-between;'
                   . 'padding:7px 0;border-bottom:1px solid #f8f8f8;font-size:12px;">';
            $html .= '<span><span style="color:' . $typeColor . ';font-weight:bold;">' . $typeLabel . '</span>'
                   . ' &mdash; <span style="color:#888;">' . htmlspecialchars($sizeStr) . '</span></span>';

            if ($secret) {
                $token = rancherfleet_generateBackupToken($secret, $orderNum, $fname);
                $html .= '<div style="display:flex;gap:6px;align-items:center;">';

                // Download button
                $html .= '<form method="post" action="' . $serviceUrl . '" style="margin:0;">';
                $html .= '<input type="hidden" name="clientaction" value="backup_download">';
                $html .= '<input type="hidden" name="backup_file" value="' . htmlspecialchars($fname) . '">';
                $html .= '<button type="submit" style="background:#2980b9;color:#fff;border:none;'
                       . 'border-radius:4px;padding:5px 12px;font-size:11px;font-weight:bold;cursor:pointer;">'
                       . '&#11015; Download</button>';
                $html .= '</form>';

                // Restore button
                $html .= '<form method="post" action="' . $serviceUrl . '" style="margin:0;" '
                       . 'onsubmit="return confirm(\'WARNING: This will restore from this backup. Your current ' . htmlspecialchars($ftype) . ' data will be overwritten and the instance will be briefly offline. Continue?\');">';
                $html .= '<input type="hidden" name="clientaction" value="backup_restore">';
                $html .= '<input type="hidden" name="backup_file" value="' . htmlspecialchars($fname) . '">';
                $html .= '<input type="hidden" name="backup_type" value="' . htmlspecialchars($ftype) . '">';
                $html .= '<button type="submit" style="background:#e67e22;color:#fff;border:none;'
                       . 'border-radius:4px;padding:5px 12px;font-size:11px;font-weight:bold;cursor:pointer;">'
                       . '&#8635; Restore</button>';
                $html .= '</form>';

                $html .= '</div>';
            } else {
                $html .= '<span style="font-size:11px;color:#aaa;">Actions unavailable — Backup Auth Secret not configured</span>';
            }

            $html .= '</div>';
        }
        $html .= '</div>';
    }

    $html .= '<p style="font-size:11px;color:#aaa;margin-top:8px;">'
           . 'Backups are retained for 3 days. Download links expire after 60 seconds.</p>';
    $html .= '</div>';
    return $html;
}



/**
 * Reads per-service custom fields prefixed with "secret_" and injects them
 * as a Kubernetes Secret named "whmcs-client-secrets" in the namespace.
 *
 * To define a secret, create a WHMCS product custom field named e.g.:
 *   secret_db_password
 *   secret_api_key
 *
 * The field value will be stored as a base64-encoded key in the Secret.
 * Field name prefix "secret_" is stripped to form the Secret key.
 *
 * @return bool  true if any secrets were injected, false if none found
 */
function rancherfleet_doInjectSecrets(array $params, $namespace)
{
    $serviceId = (int)$params['serviceid'];
    $secretData = array();

    try {
        // Get all custom field values for this service
        $fields = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfieldsvalues.relid', $serviceId)
            ->where('tblcustomfields.type', 'product')
            ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')
            ->get();

        foreach ($fields as $field) {
            $name  = is_object($field) ? $field->fieldname : $field['fieldname'];
            $value = is_object($field) ? $field->value     : $field['value'];

            // Only inject fields prefixed with "secret_"
            if (strpos($name, 'secret_') === 0 && !empty($value)) {
                $key              = substr($name, 7); // strip "secret_" prefix
                $secretData[$key] = $value;
            }
        }
    } catch (\Exception $e) {
        RancherFleet\Logger::error("injectSecrets: DB error: " . $e->getMessage());
        return false;
    }

    if (empty($secretData)) {
        RancherFleet\Logger::info("injectSecrets: no secret_ fields found for service {$serviceId}");
        return false;
    }

    list($rancher) = rancherfleet_buildClients($params);
    $rancher->applySecret($namespace, 'whmcs-client-secrets', $secretData);
    RancherFleet\Logger::info("injectSecrets: injected " . count($secretData) . " secrets into {$namespace}");
    rancherfleet_logHistory($params, 'Secrets Injected', count($secretData) . ' keys');
    return true;
}

// ---------------------------------------------------------------------------
// Internal: parse server config from WHMCS params
// ---------------------------------------------------------------------------

function rancherfleet_getConfig(array $params)
{
    $hash   = isset($params['serveraccesshash']) ? $params['serveraccesshash'] : '{}';
    $config = json_decode($hash, true);
    if (!is_array($config)) {
        $config = array();
    }
    $hostname = rtrim($params['serverhostname'], '/');
    if (strpos($hostname, 'http') !== 0) {
        $hostname = 'https://' . $hostname;
    }
    $config['rancher_url'] = $hostname;
    $config['rancher_token'] = $params['serverpassword'];
    return $config;
}

// ---------------------------------------------------------------------------
// Internal: get client-facing order number
// ---------------------------------------------------------------------------

/**
 * Fetches the client-facing order number from the WHMCS database.
 * Falls back to service ID if the order number cannot be found.
 *
 * WHMCS stores the human-readable order number in tblorders.ordernum,
 * linked to a service via tblhosting.orderid.
 *
 * @param  array $params  WHMCS module params
 * @return string         Order number e.g. "AF4E52" or service ID as fallback
 */
function rancherfleet_getOrderNumber(array $params)
{
    $serviceId = (int)$params['serviceid'];

    try {
        // WHMCS 8.x uses Capsule (Laravel/Eloquent) for DB access
        $row = \WHMCS\Database\Capsule::table('tblorders')
            ->join('tblhosting', 'tblhosting.orderid', '=', 'tblorders.id')
            ->where('tblhosting.id', $serviceId)
            ->value('tblorders.ordernum');

        if (!empty($row)) {
            return (string)$row;
        }
    } catch (\Exception $e) {
        RancherFleet\Logger::info("getOrderNumber fallback: " . $e->getMessage());
    }

    // Fallback to service ID if order number not found
    return (string)$serviceId;
}

// ---------------------------------------------------------------------------
// Internal: build client objects
// ---------------------------------------------------------------------------

function rancherfleet_buildClients(array $params)
{
    $cfg = rancherfleet_getConfig($params);

    // cluster_id        = local (Fleet management cluster, where GitRepo objects live)
    // target_cluster_id = downstream cluster ID (where namespaces and deployments run,
    //                      used for /k8s/clusters/{id}/... proxy API paths)
    $fleetClusterId  = isset($cfg['cluster_id'])        ? $cfg['cluster_id']        : 'local';
    $targetClusterId = isset($cfg['target_cluster_id']) ? $cfg['target_cluster_id'] : $fleetClusterId;

    $rancher = new RancherFleet\RancherClient(
        $cfg['rancher_url'],
        $cfg['rancher_token'],
        $targetClusterId
    );

    // Fleet's GitRepo spec.targets[].clusterName matches against the Fleet
    // Cluster CRD's metadata.name — the cluster's DISPLAY NAME, not its
    // c-xxxxx ID. Resolve and cache it via the Rancher v3 API.
    $targetClusterName = $rancher->testConnection();

    $github = new RancherFleet\GitHubClient(
        isset($cfg['github_pat'])         ? $cfg['github_pat']         : '',
        isset($cfg['github_owner'])       ? $cfg['github_owner']       : '',
        isset($cfg['github_repo'])        ? $cfg['github_repo']        : '',
        isset($cfg['github_branch'])      ? $cfg['github_branch']      : 'main',
        isset($cfg['repo_private'])       ? (bool)$cfg['repo_private'] : false,
        isset($cfg['git_ssh_key'])        ? $cfg['git_ssh_key']        : '',
        isset($cfg['git_basic_user'])     ? $cfg['git_basic_user']     : '',
        isset($cfg['git_basic_password']) ? $cfg['git_basic_password'] : ''
    );

    $fleet = new RancherFleet\FleetHelper(
        $rancher,
        $targetClusterName,
        isset($cfg['fleet_namespace'])     ? $cfg['fleet_namespace']    : 'fleet-default',
        isset($cfg['github_owner'])        ? $cfg['github_owner']       : '',
        isset($cfg['github_repo'])         ? $cfg['github_repo']        : '',
        isset($cfg['github_branch'])       ? $cfg['github_branch']      : 'main',
        isset($cfg['repo_private'])        ? (bool)$cfg['repo_private'] : false,
        isset($cfg['git_ssh_key'])         ? $cfg['git_ssh_key']        : '',
        isset($cfg['git_basic_user'])      ? $cfg['git_basic_user']     : '',
        isset($cfg['git_basic_password'])  ? $cfg['git_basic_password'] : ''
    );

    return array($rancher, $github, $fleet, $cfg);
}

/**
 * Builds the ResellersPanel client, Cloudflare client, and
 * DomainOrderManager from this product's ConfigOptions.
 *
 * @param  array $params
 * @return array  array($resellersPanelClient, $orderManager, $rancherClient)
 * @throws \Exception  if required credentials are not configured
 */
function rancherfleet_buildDomainClients(array $params)
{
    // IMPORTANT: $params['configoptions'] (friendly-name keyed) is ONLY
    // ever populated for Configurable Options (the customer-facing system
    // under the product's "Configurable Options" tab) — confirmed via
    // WHMCS's own developer docs: "a configurable option named 'Disk
    // Space' would become $params['configoptions']['Disk Space']".
    // Module Settings (this module's own _ConfigOptions() admin fields)
    // are ONLY ever available as the numbered $params['configoptionX']
    // keys (X = 1-24), positioned according to definition order in
    // _ConfigOptions() — there is no friendly-name array for these, ever.
    //
    // For product 126 specifically, the numbered slots are OFFSET from
    // what fresh definition order would predict (confirmed via direct
    // tblproducts query, unchanged even after a full Module Settings
    // re-save — this is a sticky, product-specific drift, not a caching
    // issue). The actual confirmed positions for product 126 are:
    //   configoption11 = ResellersPanel Store Username
    //   configoption12 = ResellersPanel API Password
    //   configoption13 = ResellersPanel API URL
    //   configoption14 = Cloudflare API Token
    // If this module is used on a DIFFERENT product, or if product 126's
    // fields are changed again, re-verify via:
    //   SELECT configoption1..configoption20 FROM tblproducts WHERE id = X;
    // and update the indices below accordingly.
    // RE-CONFIRMED 2026-06-16 after a module-dropdown reset shifted every
    // slot left by one position from the original SQL-confirmed mapping.
    // This numbering is NOT stable across module resets / field edits for
    // this product — if domain features break again, re-verify via:
    //   SELECT configoption1..configoption20 FROM tblproducts WHERE id = 126;
    // and update these indices to match.
    $rpUsername = isset($params['configoption10']) ? trim($params['configoption10']) : '';
    $rpPassword = isset($params['configoption11']) ? trim($params['configoption11']) : '';
    $rpApiUrl   = isset($params['configoption12']) ? trim($params['configoption12']) : '';
    $cfToken    = isset($params['configoption13']) ? trim($params['configoption13']) : '';

    if (empty($rpUsername) || empty($rpPassword) || empty($rpApiUrl)) {
        throw new \Exception('ResellersPanel Store Username, API Password, and API URL must be configured in Module Settings before domains can be purchased.');
    }
    if (empty($cfToken)) {
        throw new \Exception('Cloudflare API Token must be configured in Module Settings before domains can be purchased.');
    }

    $rp = new RancherFleet\Domains\ResellersPanelClient($rpApiUrl, $rpUsername, $rpPassword);
    $cf = new RancherFleet\Domains\CloudflareClient($cfToken);

    // Reuse the same downstream-cluster RancherClient used for namespace/
    // deployment operations — IngressHelper (used internally by
    // DomainOrderManager) needs to query Services/Ingresses on that
    // same cluster.
    list($rancher) = rancherfleet_buildClients($params);

    $orderManager = new RancherFleet\Domains\DomainOrderManager($rp, $cf, $rancher);

    return array($rp, $orderManager, $rancher);
}

// ---------------------------------------------------------------------------
// Internal: exception detail formatter
// ---------------------------------------------------------------------------
function rancherfleet_exceptionDetail($e)
{
    $lines = array();
    $lines[] = 'Message : ' . $e->getMessage();
    $lines[] = 'File    : ' . $e->getFile() . ' (line ' . $e->getLine() . ')';

    // RancherApiException and GitHubApiException carry extra context
    if ($e instanceof RancherFleet\RancherApiException || $e instanceof RancherFleet\GitHubApiException) {
        $lines[] = 'HTTP    : ' . $e->getHttpCode();
        $raw = $e->getRawBody();
        if ($raw) {
            // Pretty-print JSON if possible
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $lines[] = 'Body    : ' . json_encode($decoded, JSON_PRETTY_PRINT);
            } else {
                $lines[] = 'Body    : ' . $raw;
            }
        }
    }

    $lines[] = 'Trace   : ' . $e->getTraceAsString();
    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// WHMCS Lifecycle Hooks
// ---------------------------------------------------------------------------

function rancherfleet_CreateAccount(array $params)
{
    RancherFleet\Logger::info("CreateAccount: starting");

    // If automatic provisioning is disabled, skip all phases.
    if (!rancherfleet_isAutomatic($params)) {
        RancherFleet\Logger::info("CreateAccount: automatic provisioning disabled, skipping.");
        return 'success';
    }

    $orderNum  = rancherfleet_getOrderNumber($params);
    $namespace = 'whmcs-client-' . $orderNum;
    $repoPath  = 'whmcs-client-' . $orderNum;
    $serviceId = (int)$params['serviceid'];

    // Phase E: Dry run mode — simulate without making changes
    if (rancherfleet_isDryRun($params)) {
        $customImage = rancherfleet_getCustomImage($params);
        $containerLimits = rancherfleet_getContainerLimits($params);
        RancherFleet\Logger::info("DryRun CreateAccount: would provision namespace={$namespace}");
        RancherFleet\Logger::info("DryRun: containerLimits=" . json_encode($containerLimits));
        RancherFleet\Logger::info("DryRun: customImage=" . ($customImage ?: 'none'));
        rancherfleet_logHistory($params, 'Dry Run: CreateAccount', "would provision {$namespace}");
        return 'success';
    }

    // Track completed phases for rollback on failure
    $completed = array(
        'namespace_created' => false,
        'branch_created'    => false,
        'gitrepo_created'   => false,
    );

    // Phase 1: Connection test
    try {
        list($rancher) = rancherfleet_buildClients($params);
        RancherFleet\Logger::info("CreateAccount: rancherfleet_buildClients() completed");
        $rancher->testConnection();
        RancherFleet\Logger::info("CreateAccount: testConnection() completed");
    } catch (Exception $e) {
        RancherFleet\RetryQueue::enqueue($serviceId, 'TestConnection', $namespace, $e->getMessage());
        return 'Error (Phase 1 - Connection): ' . $e->getMessage();
    }

    // Phase 2: Create namespace
    try {
        list($rancher) = rancherfleet_buildClients($params);
        RancherFleet\Logger::info("CreateAccount: rancherfleet_buildClients() completed for Phase 2");

        $rancher->createNamespace($namespace);
        $completed['namespace_created'] = true;
        RancherFleet\Logger::info("CreateAccount: createNamespace() completed for {$namespace}");

        // Phase C: Create client ServiceAccount for kubeconfig
        $rancher->createClientServiceAccount($namespace);
        RancherFleet\Logger::info("CreateAccount: createClientServiceAccount() completed for {$namespace}");

        // Phase C: Inject secrets from product custom fields
        rancherfleet_doInjectSecrets($params, $namespace);
        RancherFleet\Logger::info("CreateAccount: doInjectSecrets() completed for {$namespace}");

        // Phase C: Create DB admin Secret for backup CronJob
        rancherfleet_createDbAdminSecret($params, $rancher, $namespace, $orderNum);
        RancherFleet\Logger::info("CreateAccount: createDbAdminSecret() completed for {$namespace}");

    } catch (Exception $e) {
        rancherfleet_doRollback($params, $completed);
        RancherFleet\RetryQueue::enqueue($serviceId, 'CreateNamespace', $namespace, $e->getMessage());
        return 'Error (Phase 2 - Namespace): ' . $e->getMessage();
    }

    // Phase 3: Bootstrap GitHub branch
    try {
        list($rancher, $github) = rancherfleet_buildClients($params);
        // Phase D: container limits
        $containerLimits = rancherfleet_getContainerLimits($params);

        // Phase E: custom image override (from product config)
        $customImage = rancherfleet_getCustomImage($params);

        // Custom fields: Odoo version and DB server (from order/service fields)
        $odooVersion = rancherfleet_getOdooImageVersion($params);
        $dbServer    = rancherfleet_getDbServer($params);

        RancherFleet\Logger::info("CreateAccount: odooVersion={$odooVersion} dbServer={$dbServer} namespace={$namespace}");

        $github->bootstrapClientFolder($repoPath, $namespace, $orderNum, $containerLimits, $customImage, $odooVersion, $dbServer);
        $completed['branch_created'] = true;
    } catch (Exception $e) {
        rancherfleet_doRollback($params, $completed);
        RancherFleet\RetryQueue::enqueue($serviceId, 'BootstrapGithub', $namespace, $e->getMessage());
        return 'Error (Phase 3 - GitHub): ' . $e->getMessage();
    }

    // Phase 4: Create Fleet GitRepo
    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);
        $fleet->createGitRepo($namespace, $repoPath);
        $completed['gitrepo_created'] = true;
    } catch (Exception $e) {
        rancherfleet_doRollback($params, $completed);
        RancherFleet\RetryQueue::enqueue($serviceId, 'CreateGitRepo', $namespace, $e->getMessage());
        return 'Error (Phase 4 - GitRepo): ' . $e->getMessage();
    }

    RancherFleet\RetryQueue::clearAll($serviceId);
    RancherFleet\Logger::info("CreateAccount: ALL PHASES COMPLETE for order {$orderNum}");
    rancherfleet_logHistory($params, 'Provisioned', "order {$orderNum}");
    return 'success';
}

function rancherfleet_SuspendAccount(array $params)
{
    try {
        $graceHours = rancherfleet_getGraceHours($params);
        $orderNum   = rancherfleet_getOrderNumber($params);
        $namespace  = 'whmcs-client-' . $orderNum;

        if ($graceHours > 0) {
            // Grace period: schedule the actual suspension for later
            $executeAt = time() + ($graceHours * 3600);
            rancherfleet_scheduleGraceSuspend($params, $executeAt);
            $executeAtStr = date('Y-m-d H:i:s', $executeAt);
            RancherFleet\Logger::info("SuspendAccount: grace period {$graceHours}h - scheduled for {$executeAtStr}");
            rancherfleet_logHistory($params, 'Suspension Scheduled', "grace period {$graceHours}h - execute at {$executeAtStr}");
            return 'success';
        }

        // No grace period - suspend immediately
        list($rancher, $github) = rancherfleet_buildClients($params);
        $github->setReplicas($namespace, 0);
        rancherfleet_clearGraceSuspend($params);
        RancherFleet\Logger::info("SuspendAccount: pushed replicas=0 for {$namespace}");
        rancherfleet_logHistory($params, 'Suspended', 'replicas set to 0 immediately');
        return 'success';

    } catch (Exception $e) {
        RancherFleet\Logger::error("SuspendAccount FAILED: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_UnsuspendAccount(array $params)
{
    try {
        list($rancher, $github) = rancherfleet_buildClients($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        // Clear any pending grace period suspension
        $scheduled = rancherfleet_getScheduledGraceSuspend($params);
        if ($scheduled !== null) {
            rancherfleet_clearGraceSuspend($params);
            RancherFleet\Logger::info("UnsuspendAccount: cleared pending grace suspension for {$namespace}");
        }

        $github->setReplicas($namespace, 1);
        RancherFleet\Logger::info("UnsuspendAccount: pushed replicas=1 for {$namespace}");
        rancherfleet_logHistory($params, 'Unsuspended', 'replicas set to 1');
        return 'success';

    } catch (Exception $e) {
        RancherFleet\Logger::error("UnsuspendAccount FAILED: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_TerminateAccount(array $params)
{
    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $repoPath  = 'whmcs-client-' . $orderNum;

        // Step 1: Delete the Odoo database before removing the namespace
        rancherfleet_terminateDatabase($params, $rancher, $namespace);

        // Step 2: Delete all Kubernetes/GitHub/Fleet resources
        $fleet->deleteGitRepo($namespace);
        $rancher->deleteNamespace($namespace);
        $github->deleteClientFolder($repoPath);

        RancherFleet\Logger::info("TerminateAccount: deletion complete for {$namespace}, verifying...");

        // Phase A: Verify everything was actually removed
        sleep(2);
        $remaining = rancherfleet_checkTermination($params, $namespace);
        if (!empty($remaining)) {
            $msg = implode('; ', $remaining);
            RancherFleet\Logger::error("TerminateAccount: WARNING - resources still exist: " . $msg);
            return 'Warning: Terminated but some resources may still exist - ' . $msg;
        }

        RancherFleet\Logger::info("TerminateAccount: verification passed - all resources removed for {$namespace}");
        rancherfleet_logHistory($params, 'Terminated', 'all resources removed');
        return 'success';

    } catch (Exception $e) {
        RancherFleet\Logger::error("TerminateAccount FAILED: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Drops the Odoo database for a terminated service by creating a
 * short-lived Kubernetes Job that runs dropdb inside the cluster.
 *
 * Why a Job instead of exec-into-pod:
 *   The Kubernetes exec API uses WebSockets (wss://), which cURL on
 *   cPanel/shared hosting does not support. A Job uses only the standard
 *   Kubernetes batch API over HTTPS, which works everywhere.
 *
 * Flow:
 *   1. Construct the database name as odoo-{orderNum}
 *   2. Read DB admin credentials from Module Settings
 *   3. Create a batch/v1 Job in the client namespace running the
 *      official postgres image with dropdb --if-exists
 *   4. Poll Job status for up to 60 seconds
 *   5. Log outcome and delete the Job (cleanup always runs)
 *   6. On any failure: log and return — namespace deletion continues
 */
function rancherfleet_terminateDatabase(array $params, $rancher, $namespace)
{
    RancherFleet\Logger::info("terminateDatabase: starting for {$namespace}");

    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $dbName    = 'odoo-' . $orderNum;
        $dbHost    = 'postgres16.default.svc.cluster.local';
        $dbPort    = '5432';

        // DB admin credentials from Module Settings.
        // IMPORTANT: verify slot numbers after saving via SQL:
        //   SELECT configoption20, configoption21 FROM tblproducts WHERE id = 126;
        // configoption20 = DB Admin Username, configoption21 = DB Admin Password
        $dbUser = isset($params['configoption20']) ? trim($params['configoption20']) : 'postgres';
        $dbPass = isset($params['configoption21']) ? trim($params['configoption21']) : '';

        if (empty($dbUser)) {
            RancherFleet\Logger::error("terminateDatabase: DB Admin Username not configured in Module Settings");
            return;
        }

        $jobName = 'rfm-dropdb-' . strtolower(preg_replace('/[^a-z0-9]/i', '-', $orderNum));
        $jobName = substr($jobName, 0, 52) . '-' . substr(md5($orderNum), 0, 8);

        $jobManifest = array(
            'apiVersion' => 'batch/v1',
            'kind'       => 'Job',
            'metadata'   => array(
                'name'      => $jobName,
                'namespace' => $namespace,
                'labels'    => array('app' => 'rfm-dropdb'),
            ),
            'spec' => array(
                'ttlSecondsAfterFinished' => 120,
                'backoffLimit'            => 0,
                'template'               => array(
                    'spec' => array(
                        'restartPolicy' => 'Never',
                        'containers'    => array(array(
                            'name'    => 'dropdb',
                            'image'   => 'postgres:16-alpine',
                            'command' => array('sh', '-c',
                                'psql -h $PGHOST -p $PGPORT -U $PGUSER -c '
                                . '"SELECT pg_terminate_backend(pid) FROM pg_stat_activity '
                                . 'WHERE datname=\'$PGDATABASE\' AND pid<>pg_backend_pid();" '
                                . '&& dropdb --if-exists "$PGDATABASE"'
                            ),
                            'env' => array(
                                array('name' => 'PGHOST',     'value' => $dbHost),
                                array('name' => 'PGPORT',     'value' => $dbPort),
                                array('name' => 'PGUSER',     'value' => $dbUser),
                                array('name' => 'PGPASSWORD', 'value' => $dbPass),
                                array('name' => 'PGDATABASE', 'value' => $dbName),
                            ),
                        )),
                    ),
                ),
            ),
        );

        RancherFleet\Logger::info("terminateDatabase: creating Job {$jobName} to drop {$dbName} on {$dbHost}");

        // Create the Job
        $rancher->rawRequest('POST',
            '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs',
            $jobManifest
        );

        // Poll for completion — up to 60 seconds
        $succeeded = false;
        $failed    = false;
        $jobPath   = '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs/' . rawurlencode($jobName);

        for ($i = 0; $i < 12; $i++) {
            sleep(5);
            try {
                $job        = $rancher->rawRequest('GET', $jobPath);
                $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                foreach ($conditions as $cond) {
                    if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                        $succeeded = true; break 2;
                    }
                    if (isset($cond['type']) && $cond['type'] === 'Failed' && $cond['status'] === 'True') {
                        $failed = true; break 2;
                    }
                }
                RancherFleet\Logger::info("terminateDatabase: waiting for Job... " . (($i + 1) * 5) . "s elapsed");
            } catch (\Exception $e) {
                RancherFleet\Logger::error("terminateDatabase: error polling Job: " . $e->getMessage());
                break;
            }
        }

        if ($succeeded) {
            RancherFleet\Logger::info("terminateDatabase: SUCCESS — database {$dbName} dropped");
            rancherfleet_logHistory($params, 'Database Deleted', $dbName . ' @ ' . $dbHost);
        } elseif ($failed) {
            RancherFleet\Logger::error("terminateDatabase: Job failed — database {$dbName} may not have been dropped");
            rancherfleet_logHistory($params, 'DB Deletion Failed', 'Job failed for ' . $dbName);
        } else {
            RancherFleet\Logger::error("terminateDatabase: Job timed out after 60s — database {$dbName} may not have been dropped");
            rancherfleet_logHistory($params, 'DB Deletion Timeout', $dbName);
        }

        // Always clean up the Job (ttlSecondsAfterFinished=120 is a
        // belt-and-braces backup in case this DELETE fails).
        try {
            $rancher->rawRequest('DELETE', $jobPath);
        } catch (\Exception $e) {
            RancherFleet\Logger::info("terminateDatabase: Job cleanup note: " . $e->getMessage());
        }

    } catch (\Exception $e) {
        RancherFleet\Logger::error("terminateDatabase: exception — " . $e->getMessage());
        rancherfleet_logHistory($params, 'DB Deletion Error', $e->getMessage());
    }
}

/**
 * Handles backup restore from client area.
 *
 * Creates a Job that:
 * 1. Scales the Deployment to 0 replicas
 * 2. Creates pre-restore snapshots (db dump + filestore tar)
 * 3. Restores from the selected backup (db or filestore)
 * 4. Scales back to original replica count
 * 5. Cleans up temporary snapshots
 *
 * @param  array  $params
 * @param  string $namespace
 * @param  string $orderNum
 * @param  string $filename   Backup filename (e.g. "db-0000-2024-01-01.dump")
 * @param  string $backupType "db" or "filestore"
 * @return string            'backup_restore_success:...' or 'backup_restore_error:...'
 */
function rancherfleet_handleBackupRestore(array $params, $namespace, $orderNum, $filename, $backupType)
{
    RancherFleet\Logger::info("backupRestore: starting for {$namespace} file={$filename} type={$backupType}");

    try {
        list($rancher) = rancherfleet_buildClients($params);

        // Get current deployment status (replica count)
        $deploymentName = 'odoo-' . $orderNum;
        $status = $rancher->getDeploymentStatus($namespace, $deploymentName);
        $originalReplicas = max(1, isset($status['replicas']) ? (int)$status['replicas'] : 1);

        // DB credentials from Module Settings
        $dbUser = isset($params['configoption20']) ? trim($params['configoption20']) : 'postgres';
        $dbPass = isset($params['configoption21']) ? trim($params['configoption21']) : '';
        $dbHost = 'postgres16.default.svc.cluster.local';
        $dbPort = '5432';
        $dbName = 'odoo-' . $orderNum;

        if (empty($dbUser)) {
            RancherFleet\Logger::error("backupRestore: DB Admin Username not configured");
            return 'backup_restore_error:Database credentials not configured';
        }

        // Validate backup file exists by checking the manifest
        $files = rancherfleet_getBackupFiles((int)$params['serviceid']);
        $backupFile = null;
        foreach ($files as $f) {
            if ($f['name'] === $filename) {
                $backupFile = $f;
                break;
            }
        }

        if (!$backupFile) {
            RancherFleet\Logger::error("backupRestore: backup file not found in manifest");
            return 'backup_restore_error:Backup file not found';
        }

        // Validate backup type matches filename pattern
        if ($backupType === 'db' && !preg_match('/^db-\d+-\d{4}-\d{2}-\d{2}\.dump$/', $filename)) {
            return 'backup_restore_error:Invalid database backup filename format';
        }
        if ($backupType === 'filestore' && !preg_match('/^filestore-\d+-\d{4}-\d{2}-\d{2}\.tar\.gz$/', $filename)) {
            return 'backup_restore_error:Invalid filestore backup filename format';
        }

        // Scale deployment to 0 via Rancher API BEFORE creating restore Job
        RancherFleet\Logger::info("backupRestore: scaling deployment to 0 replicas");
        try {
            $rancher->scaleDeployment($namespace, $deploymentName, 0);
            sleep(5);
            RancherFleet\Logger::info("backupRestore: deployment scaled to 0 replicas");
        } catch (\Exception $scaleEx) {
            throw new \Exception("Failed to scale deployment to 0: " . $scaleEx->getMessage());
        }

        // Generate unique job name
        $timestamp = time();
        $jobName = 'rfm-restore-' . $backupType . '-' . $timestamp . '-' . substr(md5($orderNum), 0, 4);
        $jobName = substr($jobName, 0, 60); // Keep under 63 char DNS limit

        // Build restore shell script (no kubectl scaling — Rancher API handles it)
        // NFS path structure: /backups/odoo-{orderNum}/{filename}
        if ($backupType === 'db') {
            $nfsBackupDir = '/backups/odoo-' . $orderNum;
            $preRestoreDbFile = 'pre-restore-' . date('Y-m-d-H-i-s') . '.dump';
            $restoreCmd = 'set -e && '
                . 'echo "Creating pre-restore database snapshot..." && '
                . 'pg_dump -h $PGHOST -p $PGPORT -U $PGUSER -d $PGDATABASE -Fc > ' . escapeshellarg($nfsBackupDir . '/' . $preRestoreDbFile) . ' 2>&1 || echo "Pre-restore snapshot failed, continuing anyway..." && '
                . 'echo "Restoring database from backup..." && '
                . 'pg_restore -h $PGHOST -p $PGPORT -U $PGUSER --clean --if-exists -d $PGDATABASE ' . escapeshellarg($nfsBackupDir . '/' . $filename) . ' 2>&1 && '
                . 'echo "Restore complete"';
        } else {
            // filestore restore
            $nfsBackupDir = '/backups/odoo-' . $orderNum;
            $preRestoreFilestore = 'pre-restore-' . date('Y-m-d-H-i-s') . '.tar.gz';
            $restoreCmd = 'set -e && '
                . 'echo "Creating pre-restore filestore snapshot..." && '
                . 'tar -czf ' . escapeshellarg($nfsBackupDir . '/' . $preRestoreFilestore) . ' -C /var/lib/odoo . 2>&1 || echo "Pre-restore snapshot failed, continuing anyway..." && '
                . 'echo "Restoring filestore from backup..." && '
                . 'tar -xzf ' . escapeshellarg($nfsBackupDir . '/' . $filename) . ' -C /var/lib/odoo && '
                . 'echo "Restore complete"';
        }

        // Build Job manifest with NFS volume (not PVC subPath)
        $jobManifest = array(
            'apiVersion' => 'batch/v1',
            'kind'       => 'Job',
            'metadata'   => array(
                'name'      => $jobName,
                'namespace' => $namespace,
                'labels'    => array('app' => 'rfm-restore'),
            ),
            'spec' => array(
                'ttlSecondsAfterFinished' => 300,
                'backoffLimit'            => 0,
                'template'               => array(
                    'spec' => array(
                        'restartPolicy' => 'Never',
                        'containers'    => array(array(
                            'name'    => 'restore',
                            'image'   => $backupType === 'db' ? 'postgres:16-alpine' : 'alpine:latest',
                            'command' => array('sh', '-c', $restoreCmd),
                            'env'     => $backupType === 'db' ? array(
                                array('name' => 'PGHOST',     'value' => $dbHost),
                                array('name' => 'PGPORT',     'value' => $dbPort),
                                array('name' => 'PGUSER',     'value' => $dbUser),
                                array('name' => 'PGPASSWORD', 'value' => $dbPass),
                                array('name' => 'PGDATABASE', 'value' => $dbName),
                            ) : array(),
                            'volumeMounts' => array(
                                array(
                                    'name'      => 'odoo-pvc',
                                    'mountPath' => '/var/lib/odoo',
                                    'subPath'   => 'filestore',
                                ),
                                array(
                                    'name'      => 'nfs-backups',
                                    'mountPath' => '/backups',
                                ),
                            ),
                        )),
                        'volumes' => array(
                            array(
                                'name'         => 'odoo-pvc',
                                'persistentVolumeClaim' => array(
                                    'claimName' => 'odoo-' . $orderNum,
                                ),
                            ),
                            array(
                                'name' => 'nfs-backups',
                                'nfs' => array(
                                    'server' => '162.35.166.55',
                                    'path'   => '/export/share1',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );

        RancherFleet\Logger::info("backupRestore: creating Job {$jobName} for {$backupType} restore");

        // Create the Job
        $rancher->rawRequest('POST',
            '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs',
            $jobManifest
        );

        // Poll for completion — up to 120 seconds
        $succeeded = false;
        $failed    = false;
        $jobPath   = '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs/' . rawurlencode($jobName);

        for ($i = 0; $i < 24; $i++) {
            sleep(5);
            try {
                $job        = $rancher->rawRequest('GET', $jobPath);
                $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                foreach ($conditions as $cond) {
                    if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                        $succeeded = true; break 2;
                    }
                    if (isset($cond['type']) && $cond['type'] === 'Failed' && $cond['status'] === 'True') {
                        $failed = true; break 2;
                    }
                }
                RancherFleet\Logger::info("backupRestore: waiting for Job... " . (($i + 1) * 5) . "s elapsed");
            } catch (\Exception $e) {
                RancherFleet\Logger::error("backupRestore: error polling Job: " . $e->getMessage());
                break;
            }
        }

        // Clean up the Job
        try {
            $rancher->rawRequest('DELETE', $jobPath);
        } catch (\Exception $e) {
            RancherFleet\Logger::info("backupRestore: Job cleanup note: " . $e->getMessage());
        }

        // Scale deployment back to original replicas via Rancher API
        RancherFleet\Logger::info("backupRestore: scaling deployment back to {$originalReplicas} replicas");
        try {
            $rancher->scaleDeployment($namespace, $deploymentName, $originalReplicas);
            RancherFleet\Logger::info("backupRestore: deployment scaled back to {$originalReplicas} replicas");
        } catch (\Exception $scaleEx) {
            RancherFleet\Logger::error("backupRestore: warning - failed to scale deployment back: " . $scaleEx->getMessage());
        }

        if ($succeeded) {
            RancherFleet\Logger::info("backupRestore: SUCCESS — {$backupType} restored from {$filename}");
            rancherfleet_logHistory($params, 'Backup Restored', $backupType . ' restored from ' . $filename);
            return 'backup_restore_success:' . $backupType;
        } elseif ($failed) {
            RancherFleet\Logger::error("backupRestore: Job failed");
            rancherfleet_logHistory($params, 'Restore Failed', 'Job failed for ' . $filename);
            return 'backup_restore_error:Restore job failed. Check admin logs for details.';
        } else {
            RancherFleet\Logger::error("backupRestore: Job timed out after 120s");
            rancherfleet_logHistory($params, 'Restore Timeout', 'Job timeout for ' . $filename);
            return 'backup_restore_error:Restore job timed out after 2 minutes';
        }

    } catch (\Exception $e) {
        RancherFleet\Logger::error("backupRestore: exception — " . $e->getMessage());
        rancherfleet_logHistory($params, 'Restore Error', $e->getMessage());
        return 'backup_restore_error:' . $e->getMessage();
    }
}


/**
 * Handles Odoo admin password reset from client area.
 * Creates a Kubernetes Job to update the password via passlib hashing and SQL.
 *
 * @param  array  $params
 * @param  string $namespace
 * @param  string $orderNum
 * @return string  'odoo_password_reset_success:{password}' or 'odoo_password_reset_error:{message}'
 */
function rancherfleet_handleOdooPasswordReset(array $params, $namespace, $orderNum)
{
    RancherFleet\Logger::info("odooPasswordReset: starting for {$namespace}");

    try {
        list($rancher) = rancherfleet_buildClients($params);

        // Generate random 16-character password
        $newPassword = bin2hex(random_bytes(8));

        $dbName = 'odoo-' . $orderNum;

        // DB credentials from Module Settings
        $dbUser = isset($params['configoption20']) ? trim($params['configoption20']) : 'postgres';
        $dbPass = isset($params['configoption21']) ? trim($params['configoption21']) : '';

        // Python script to reset password with passlib pbkdf2_sha512 hashing
        // Uses passlib to generate the correct Odoo 19 password hash
        $pythonScript = <<<'PYTHON'
from passlib.context import CryptContext
import psycopg2
import sys

try:
    new_pwd = sys.argv[1]
    db = sys.argv[2]
    host = 'postgres16.default.svc.cluster.local'
    user = open('/etc/rfm-db/username').read().strip()
    password = open('/etc/rfm-db/password').read().strip()

    ctx = CryptContext(schemes=['pbkdf2_sha512'])
    hashed = ctx.hash(new_pwd)

    conn = psycopg2.connect(host=host, dbname=db, user=user, password=password)
    cur = conn.cursor()
    cur.execute("UPDATE res_users SET password = %s WHERE login = 'admin'", (hashed,))
    conn.commit()
    cur.close()
    conn.close()
    print('Password reset successful')
    sys.exit(0)
except Exception as e:
    sys.stderr.write(str(e))
    sys.exit(1)
PYTHON;

        $jobName = 'rfm-reset-pwd-' . time() . '-' . substr(md5($orderNum), 0, 4);
        $jobName = substr($jobName, 0, 52) . '-' . substr(md5($orderNum), 0, 8);

        $jobManifest = array(
            'apiVersion' => 'batch/v1',
            'kind'       => 'Job',
            'metadata'   => array(
                'name'      => $jobName,
                'namespace' => $namespace,
                'labels'    => array('app' => 'rfm-reset-pwd'),
            ),
            'spec' => array(
                'ttlSecondsAfterFinished' => 120,
                'backoffLimit'            => 0,
                'template'               => array(
                    'spec' => array(
                        'restartPolicy' => 'Never',
                        'containers'    => array(array(
                            'name'    => 'resetpwd',
                            'image'   => 'odoo:19',
                            'command' => array('python3', '-c', $pythonScript),
                            'args'    => array($newPassword, $dbName),
                            'volumeMounts' => array(
                                array(
                                    'name'      => 'db-admin-secret',
                                    'mountPath' => '/etc/rfm-db',
                                    'readOnly'  => true,
                                ),
                            ),
                        )),
                        'volumes' => array(
                            array(
                                'name'   => 'db-admin-secret',
                                'secret' => array(
                                    'secretName' => 'rfm-db-admin-' . $orderNum,
                                    'optional'   => true,
                                    'items'      => array(
                                        array('key' => 'username', 'path' => 'username'),
                                        array('key' => 'password', 'path' => 'password'),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );

        RancherFleet\Logger::info("odooPasswordReset: creating Job {$jobName}");

        // Create the Job
        $rancher->rawRequest('POST',
            '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs',
            $jobManifest
        );

        // Poll for completion — up to 60 seconds
        $succeeded = false;
        $failed    = false;
        $jobPath   = '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs/' . rawurlencode($jobName);

        for ($i = 0; $i < 12; $i++) {
            sleep(5);
            try {
                $job        = $rancher->rawRequest('GET', $jobPath);
                $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                foreach ($conditions as $cond) {
                    if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                        $succeeded = true; break 2;
                    }
                    if (isset($cond['type']) && $cond['type'] === 'Failed' && $cond['status'] === 'True') {
                        $failed = true; break 2;
                    }
                }
                RancherFleet\Logger::info("odooPasswordReset: waiting for Job... " . (($i + 1) * 5) . "s elapsed");
            } catch (\Exception $e) {
                RancherFleet\Logger::error("odooPasswordReset: error polling Job: " . $e->getMessage());
                break;
            }
        }

        // Clean up the Job
        try {
            $rancher->rawRequest('DELETE', $jobPath);
        } catch (\Exception $e) {
            RancherFleet\Logger::info("odooPasswordReset: Job cleanup note: " . $e->getMessage());
        }

        if ($succeeded) {
            RancherFleet\Logger::info("odooPasswordReset: SUCCESS");
            rancherfleet_logHistory($params, 'Odoo Password Reset', 'Password reset via SQL');
            return 'odoo_password_reset_success:' . $newPassword;
        } elseif ($failed) {
            RancherFleet\Logger::error("odooPasswordReset: Job failed");
            rancherfleet_logHistory($params, 'Password Reset Failed', 'Job failed');
            return 'odoo_password_reset_error:Password reset job failed. Check admin logs for details.';
        } else {
            RancherFleet\Logger::error("odooPasswordReset: Job timed out after 60s");
            rancherfleet_logHistory($params, 'Password Reset Timeout', 'Job timeout');
            return 'odoo_password_reset_error:Password reset job timed out. Please try again.';
        }

    } catch (\Exception $e) {
        RancherFleet\Logger::error("odooPasswordReset: exception — " . $e->getMessage());
        rancherfleet_logHistory($params, 'Password Reset Error', $e->getMessage());
        return 'odoo_password_reset_error:' . $e->getMessage();
    }
}


/**
 * Converts CPU value (millicores as "125m" or nanocores as "125000000n") to millicores.
 *
 * @param  string $cpuValue
 * @return int    CPU in millicores
 */
function rancherfleet_parseCpuToMillicores($cpuValue)
{
    if (empty($cpuValue)) return 0;

    if (strpos($cpuValue, 'm') !== false) {
        return (int)str_replace('m', '', $cpuValue);
    } elseif (strpos($cpuValue, 'n') !== false) {
        $nanos = (int)str_replace('n', '', $cpuValue);
        return (int)($nanos / 1000000);
    }

    return 0;
}


/**
 * Converts memory value to MB.
 * Input can be "256Mi", "1Gi", or raw bytes as "12345678".
 *
 * @param  string $memValue
 * @return int    Memory in MB
 */
function rancherfleet_parseMemoryToMb($memValue)
{
    if (empty($memValue)) return 0;

    if (strpos($memValue, 'Gi') !== false) {
        return (int)str_replace('Gi', '', $memValue) * 1024;
    } elseif (strpos($memValue, 'Mi') !== false) {
        return (int)str_replace('Mi', '', $memValue);
    } elseif (strpos($memValue, 'Ki') !== false) {
        return (int)str_replace('Ki', '', $memValue) / 1024;
    } else {
        $bytes = (int)$memValue;
        return (int)($bytes / (1024 * 1024));
    }
}


/**
 * Gets cached health metrics or empty array if expired/missing.
 * Cache TTL: 60 seconds.
 *
 * @param  int $serviceId
 * @return array  ['cpu_m' => int, 'memory_mb' => int, 'memory_limit_mb' => int, 'cpu_limit_m' => int, 'cached_at' => int]
 */
function rancherfleet_getHealthMetrics($serviceId)
{
    try {
        $cached = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_health')
            ->where('setting', 'metrics_' . $serviceId)
            ->first();

        if ($cached) {
            $data = json_decode($cached->value, true);
            if (isset($data['cached_at']) && (time() - $data['cached_at']) < 60) {
                return $data;
            }
        }
    } catch (\Exception $e) {
        RancherFleet\Logger::info("getHealthMetrics: cache read error (non-fatal): " . $e->getMessage());
    }

    return array();
}


/**
 * Caches health metrics for 60 seconds.
 */
function rancherfleet_cacheHealthMetrics($serviceId, array $metrics)
{
    $metrics['cached_at'] = time();
    try {
        \WHMCS\Database\Capsule::table('tbladdonmodules')->updateOrInsert(
            array('module' => 'rancherfleet_health', 'setting' => 'metrics_' . $serviceId),
            array('value' => json_encode($metrics))
        );
    } catch (\Exception $e) {
        RancherFleet\Logger::info("cacheHealthMetrics: cache write error (non-fatal): " . $e->getMessage());
    }
}


/**
 * Fetches CPU and memory metrics from Kubernetes metrics API.
 * Returns cached metrics if available and fresh (< 60s old).
 *
 * @param  array  $params
 * @param  string $namespace
 * @param  string $podName    First pod name from deployment
 * @return array  ['cpu_m' => int, 'memory_mb' => int, 'memory_limit_mb' => int, 'cpu_limit_m' => int] or empty on error
 */
function rancherfleet_fetchPodMetrics(array $params, $namespace, $podName)
{
    $serviceId = (int)$params['serviceid'];
    $cached = rancherfleet_getHealthMetrics($serviceId);

    if (!empty($cached)) {
        return $cached;
    }

    RancherFleet\Logger::info("fetchPodMetrics: querying for {$namespace}/{$podName}");

    try {
        list($rancher, , , $cfg) = rancherfleet_buildClients($params);

        $targetClusterId = isset($cfg['target_cluster_id']) ? $cfg['target_cluster_id'] : 'local';
        $metricsPath = '/apis/metrics.k8s.io/v1beta1/namespaces/' . rawurlencode($namespace)
                     . '/pods/' . rawurlencode($podName);

        $fullUrl = '/k8s/clusters/' . rawurlencode($targetClusterId) . $metricsPath;
        RancherFleet\Logger::info("fetchPodMetrics: calling metrics API: " . $fullUrl);

        $metrics = $rancher->rawRequest('GET', $metricsPath);

        if (!isset($metrics['containers']) || empty($metrics['containers'])) {
            RancherFleet\Logger::info("fetchPodMetrics: no containers in metrics response");
            return array();
        }

        $container = $metrics['containers'][0];
        $usage = isset($container['usage']) ? $container['usage'] : array();

        $cpuM = rancherfleet_parseCpuToMillicores(isset($usage['cpu']) ? $usage['cpu'] : '');
        $memoryMb = rancherfleet_parseMemoryToMb(isset($usage['memory']) ? $usage['memory'] : '');

        $result = array(
            'cpu_m'           => $cpuM,
            'memory_mb'       => $memoryMb,
            'memory_limit_mb' => 4096,
            'cpu_limit_m'     => 2000,
        );

        rancherfleet_cacheHealthMetrics($serviceId, $result);
        return $result;

    } catch (\Exception $e) {
        if (strpos($e->getMessage(), '404') !== false) {
            RancherFleet\Logger::info("fetchPodMetrics: metrics API unavailable (404)");
        } else {
            RancherFleet\Logger::error("fetchPodMetrics: " . $e->getMessage());
        }
        return array();
    }
}


/**
 * Renders health metrics with progress bars and color coding.
 * Color: green 0-70%, amber 70-90%, red 90%+.
 */
function rancherfleet_renderHealthMetrics(array $metrics, $storageUsage = null)
{
    $html = '';

    if (empty($metrics) && empty($storageUsage)) {
        return '<div style="font-size:12px;color:#888;">Health metrics unavailable</div>';
    }

    $html .= '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #eee;">';

    // CPU
    if (!empty($metrics['cpu_m']) || !empty($metrics['cpu_limit_m'])) {
        $cpuM = isset($metrics['cpu_m']) ? $metrics['cpu_m'] : 0;
        $cpuLimitM = isset($metrics['cpu_limit_m']) ? $metrics['cpu_limit_m'] : 2000;
        $cpuPercent = $cpuLimitM > 0 ? (int)($cpuM * 100 / $cpuLimitM) : 0;
        $cpuPercent = min($cpuPercent, 100);

        $cpuColor = $cpuPercent < 70 ? '#27ae60' : ($cpuPercent < 90 ? '#f39c12' : '#e74c3c');

        $html .= '<div style="margin-bottom:12px;">';
        $html .= '<div style="font-size:12px;font-weight:bold;margin-bottom:4px;color:#333;">CPU Usage</div>';
        $html .= '<div style="background:#f0f0f0;border-radius:8px;height:20px;overflow:hidden;margin-bottom:4px;">';
        $html .= '<div style="background:' . $cpuColor . ';height:100%;width:' . $cpuPercent . '%;transition:width 0.3s ease;"></div>';
        $html .= '</div>';
        $html .= '<div style="font-size:11px;color:#666;">' . $cpuM . 'm / ' . $cpuLimitM . 'm (' . $cpuPercent . '%)</div>';
        $html .= '</div>';
    }

    // Memory
    if (!empty($metrics['memory_mb']) || !empty($metrics['memory_limit_mb'])) {
        $memoryMb = isset($metrics['memory_mb']) ? $metrics['memory_mb'] : 0;
        $memoryLimitMb = isset($metrics['memory_limit_mb']) ? $metrics['memory_limit_mb'] : 4096;
        $memoryPercent = $memoryLimitMb > 0 ? (int)($memoryMb * 100 / $memoryLimitMb) : 0;
        $memoryPercent = min($memoryPercent, 100);

        $memoryColor = $memoryPercent < 70 ? '#27ae60' : ($memoryPercent < 90 ? '#f39c12' : '#e74c3c');

        $html .= '<div style="margin-bottom:12px;">';
        $html .= '<div style="font-size:12px;font-weight:bold;margin-bottom:4px;color:#333;">Memory Usage</div>';
        $html .= '<div style="background:#f0f0f0;border-radius:8px;height:20px;overflow:hidden;margin-bottom:4px;">';
        $html .= '<div style="background:' . $memoryColor . ';height:100%;width:' . $memoryPercent . '%;transition:width 0.3s ease;"></div>';
        $html .= '</div>';
        $html .= '<div style="font-size:11px;color:#666;">' . $memoryMb . ' MB / ' . $memoryLimitMb . ' MB (' . $memoryPercent . '%)</div>';
        $html .= '</div>';
    }

    // Storage (from Longhorn if available)
    if (!empty($storageUsage) && isset($storageUsage['usedGb']) && isset($storageUsage['sizeGb'])) {
        $usedGb = $storageUsage['usedGb'];
        $totalGb = $storageUsage['sizeGb'];
        $storagePercent = $totalGb > 0 ? (int)($usedGb * 100 / $totalGb) : 0;
        $storagePercent = min($storagePercent, 100);

        $storageColor = $storagePercent < 70 ? '#27ae60' : ($storagePercent < 90 ? '#f39c12' : '#e74c3c');

        $html .= '<div>';
        $html .= '<div style="font-size:12px;font-weight:bold;margin-bottom:4px;color:#333;">Storage Usage</div>';
        $html .= '<div style="background:#f0f0f0;border-radius:8px;height:20px;overflow:hidden;margin-bottom:4px;">';
        $html .= '<div style="background:' . $storageColor . ';height:100%;width:' . $storagePercent . '%;transition:width 0.3s ease;"></div>';
        $html .= '</div>';
        $html .= '<div style="font-size:11px;color:#666;">' . number_format($usedGb, 1) . ' GB / ' . (int)$totalGb . ' GB (' . $storagePercent . '%)</div>';
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}


/**
 * Curated list of Odoo modules available for installation.
 */
function rancherfleet_getAvailableModules()
{
    return array(
        'crm'             => array('name' => 'CRM',              'category' => 'Sales',         'desc' => 'Manage leads, opportunities and your sales pipeline'),
        'sale_management' => array('name' => 'Sales',            'category' => 'Sales',         'desc' => 'Send quotes, confirm orders and manage your sales'),
        'account'         => array('name' => 'Invoicing',        'category' => 'Finance',       'desc' => 'Create invoices, manage payments and track finances'),
        'stock'           => array('name' => 'Inventory',        'category' => 'Operations',    'desc' => 'Manage your warehouse, products and stock movements'),
        'purchase'        => array('name' => 'Purchase',         'category' => 'Operations',    'desc' => 'Manage purchase orders and vendor relationships'),
        'project'         => array('name' => 'Project',          'category' => 'Productivity',  'desc' => 'Organise tasks, track progress and manage projects'),
        'hr_timesheet'    => array('name' => 'Timesheets',       'category' => 'Productivity',  'desc' => 'Track time spent on projects and tasks'),
        'hr'              => array('name' => 'Employees',        'category' => 'HR',            'desc' => 'Manage employee records, contracts and departments'),
        'website_sale'    => array('name' => 'eCommerce',        'category' => 'Website',       'desc' => 'Sell online with a fully integrated web shop'),
        'im_livechat'     => array('name' => 'Live Chat',        'category' => 'Communication', 'desc' => 'Chat with website visitors in real time'),
        'mass_mailing'    => array('name' => 'Email Marketing',  'category' => 'Marketing',     'desc' => 'Design and send email campaigns to your contacts'),
        'point_of_sale'   => array('name' => 'Point of Sale',    'category' => 'Sales',         'desc' => 'Sell in physical stores with a simple POS interface'),
    );
}


/**
 * Gets module installation status from cache or queries the database.
 * Cache expires after 5 minutes.
 *
 * @param  int $serviceId
 * @return array  [moduleName => state] or empty array on error
 */
function rancherfleet_getModuleStatus($serviceId)
{
    try {
        $cached = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_modules')
            ->where('setting', 'status_' . $serviceId)
            ->first();

        if ($cached) {
            $data = json_decode($cached->value, true);
            if (isset($data['timestamp'])) {
                $age = time() - $data['timestamp'];
                if ($age < 300) {
                    $status = isset($data['status']) ? $data['status'] : array();
                    RancherFleet\Logger::info("getModuleStatus: cache HIT (age={$age}s) for service {$serviceId}, found " . count($status) . " modules");
                    return $status;
                } else {
                    RancherFleet\Logger::info("getModuleStatus: cache EXPIRED (age={$age}s) for service {$serviceId}, will refresh");
                }
            }
        } else {
            RancherFleet\Logger::info("getModuleStatus: cache MISS for service {$serviceId}, will query database");
        }
    } catch (\Exception $e) {
        RancherFleet\Logger::error("getModuleStatus: cache read error (non-fatal): " . $e->getMessage());
    }

    // Cache is missing or expired - run status query Job
    try {
        $params = rancherfleet_loadParamsForService($serviceId);
        if (empty($params)) {
            RancherFleet\Logger::error("getModuleStatus: could not load params for service {$serviceId}");
            return array();
        }

        $orderNum = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        RancherFleet\Logger::info("getModuleStatus: triggering status query Job for {$namespace}");
        $result = rancherfleet_queryModuleStatus($params, $namespace, $orderNum);

        if (!empty($result)) {
            RancherFleet\Logger::info("getModuleStatus: Job returned " . count($result) . " modules");
            return $result;
        } else {
            RancherFleet\Logger::info("getModuleStatus: Job returned empty result");
            return array();
        }

    } catch (\Exception $e) {
        RancherFleet\Logger::error("getModuleStatus: query Job error: " . $e->getMessage());
        return array();
    }
}


/**
 * Caches module installation status for 5 minutes.
 */
function rancherfleet_cacheModuleStatus($serviceId, array $status)
{
    try {
        $data = array(
            'status'    => $status,
            'timestamp' => time(),
        );
        \WHMCS\Database\Capsule::table('tbladdonmodules')->updateOrInsert(
            array('module' => 'rancherfleet_modules', 'setting' => 'status_' . $serviceId),
            array('value' => json_encode($data))
        );
        RancherFleet\Logger::info("cacheModuleStatus: cached " . count($status) . " modules for service {$serviceId}: " . json_encode($status));
    } catch (\Exception $e) {
        RancherFleet\Logger::error("cacheModuleStatus: cache write error (non-fatal): " . $e->getMessage());
    }
}


/**
 * Queries the database via Kubernetes Job to get actual module states.
 * Uses psql to query ir_module_module and reads pod logs for output.
 *
 * @param  array  $params
 * @param  string $namespace
 * @param  int    $orderNum
 * @return array   [moduleName => state] or empty on error
 */
function rancherfleet_queryModuleStatus(array $params, $namespace, $orderNum)
{
    $serviceId = (int)$params['serviceid'];
    RancherFleet\Logger::info("queryModuleStatus: starting Job query for {$namespace} (service {$serviceId})");

    try {
        list($rancher) = rancherfleet_buildClients($params);

        $dbName = 'odoo-' . $orderNum;
        $jobName = 'rfm-query-mods-' . time() . '-' . substr(md5($orderNum), 0, 8);

        // psql query: output format is "moduleName|installed" (one per line)
        $psqlQuery = "SELECT name || '|' || state FROM ir_module_module " .
                     "WHERE name IN ('crm','sale_management','account','stock','purchase'," .
                     "'project','hr_timesheet','hr','website_sale','im_livechat','mass_mailing','point_of_sale') " .
                     "AND state='installed'";

        $jobManifest = array(
            'apiVersion' => 'batch/v1',
            'kind'       => 'Job',
            'metadata'   => array(
                'name'      => $jobName,
                'namespace' => $namespace,
                'labels'    => array('job-name' => $jobName, 'app' => 'rfm-query-mods'),
            ),
            'spec' => array(
                'ttlSecondsAfterFinished' => 30,
                'backoffLimit'            => 0,
                'template'               => array(
                    'spec' => array(
                        'restartPolicy' => 'Never',
                        'containers'    => array(array(
                            'name'    => 'querymods',
                            'image'   => 'postgres:16-alpine',
                            'env'     => array(
                                array('name' => 'PGPASSWORD',
                                      'valueFrom' => array(
                                          'secretKeyRef' => array(
                                              'name' => 'rfm-db-admin-' . $orderNum,
                                              'key'  => 'password',
                                              'optional' => true,
                                          )
                                      )),
                            ),
                            'command' => array('psql'),
                            'args'    => array(
                                '-h', 'postgres16.default.svc.cluster.local',
                                '-U', 'postgres',
                                '-d', $dbName,
                                '-t', '-A', '-F|',
                                '-c', $psqlQuery,
                            ),
                        )),
                    ),
                ),
            ),
        );

        RancherFleet\Logger::info("queryModuleStatus: creating Job {$jobName} in {$namespace}");
        $rancher->rawRequest('POST',
            '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs',
            $jobManifest
        );
        RancherFleet\Logger::info("queryModuleStatus: Job created successfully");

        $succeeded = false;
        $jobPath   = '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs/' . rawurlencode($jobName);

        // Poll Job for up to 30 seconds with 5 second intervals
        for ($i = 0; $i < 6; $i++) {
            sleep(5);
            try {
                $job        = $rancher->rawRequest('GET', $jobPath);
                $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();

                RancherFleet\Logger::info("queryModuleStatus: poll #{$i} - checking Job status");

                foreach ($conditions as $cond) {
                    if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                        $succeeded = true;
                        RancherFleet\Logger::info("queryModuleStatus: Job COMPLETED successfully");
                        break 2;
                    }
                }
            } catch (\Exception $e) {
                RancherFleet\Logger::error("queryModuleStatus: error polling Job: " . $e->getMessage());
                break;
            }
        }

        // Attempt to read pod logs
        $result = array();
        if ($succeeded) {
            try {
                RancherFleet\Logger::info("queryModuleStatus: reading pod logs for {$jobName}");

                $podSelector = 'job-name=' . $jobName;
                $podsPath = '/api/v1/namespaces/' . rawurlencode($namespace) . '/pods?labelSelector=' . rawurlencode($podSelector);
                RancherFleet\Logger::info("queryModuleStatus: calling GET {$podsPath} via RancherClient");
                $pods = $rancher->rawRequest('GET', $podsPath);
                $podItems = isset($pods['items']) ? $pods['items'] : array();

                if (!empty($podItems)) {
                    $pod = $podItems[0];
                    $podName = isset($pod['metadata']['name']) ? $pod['metadata']['name'] : '';

                    if ($podName) {
                        RancherFleet\Logger::info("queryModuleStatus: found pod {$podName}, reading logs");

                        $logsPath = '/api/v1/namespaces/' . rawurlencode($namespace) . '/pods/' . rawurlencode($podName) . '/log';
                        RancherFleet\Logger::info("queryModuleStatus: calling GET {$logsPath} via RancherClient");
                        $logs = $rancher->rawRequest('GET', $logsPath);

                        // logs can be string or array depending on Rancher version
                        $logContent = is_string($logs) ? $logs : (isset($logs['log']) ? $logs['log'] : '');

                        RancherFleet\Logger::info("queryModuleStatus: pod log content:\n" . substr($logContent, 0, 500));

                        // Parse output: format is "moduleName|installed" (one per line)
                        $lines = array_filter(array_map('trim', explode("\n", $logContent)));
                        foreach ($lines as $line) {
                            if (strpos($line, '|') !== false) {
                                list($moduleName, $state) = explode('|', $line, 2);
                                $moduleName = trim($moduleName);
                                $state = trim($state);
                                if ($moduleName && $state) {
                                    $result[$moduleName] = $state;
                                    RancherFleet\Logger::info("queryModuleStatus: parsed module {$moduleName}={$state}");
                                }
                            }
                        }

                        if (!empty($result)) {
                            RancherFleet\Logger::info("queryModuleStatus: parsed " . count($result) . " installed modules from logs");
                            rancherfleet_cacheModuleStatus($serviceId, $result);
                        } else {
                            RancherFleet\Logger::info("queryModuleStatus: no installed modules found in output");
                        }
                    } else {
                        RancherFleet\Logger::error("queryModuleStatus: pod name not found in response");
                    }
                } else {
                    RancherFleet\Logger::error("queryModuleStatus: no pods found for Job {$jobName}");
                }
            } catch (\Exception $readEx) {
                RancherFleet\Logger::error("queryModuleStatus: error reading pod logs: " . $readEx->getMessage());
            }
        } else {
            // Job timed out - cache empty result with short TTL
            RancherFleet\Logger::error("queryModuleStatus: Job timed out after 30 seconds, caching empty result with short TTL");
            try {
                $data = array(
                    'status'    => array(),
                    'timestamp' => time(),
                );
                \WHMCS\Database\Capsule::table('tbladdonmodules')->updateOrInsert(
                    array('module' => 'rancherfleet_modules', 'setting' => 'status_' . $serviceId),
                    array('value' => json_encode($data))
                );
            } catch (\Exception $e) {
                RancherFleet\Logger::error("queryModuleStatus: cache write error: " . $e->getMessage());
            }
        }

        // Clean up Job
        try {
            $rancher->rawRequest('DELETE', $jobPath);
            RancherFleet\Logger::info("queryModuleStatus: Job deleted");
        } catch (\Exception $e) {
            RancherFleet\Logger::info("queryModuleStatus: Job cleanup note: " . $e->getMessage());
        }

        return $result;

    } catch (\Exception $e) {
        RancherFleet\Logger::error("queryModuleStatus: exception — " . $e->getMessage());
        return array();
    }
}


/**
 * Handles Odoo app installation from client area.
 * Creates a Kubernetes Job to run odoo --init={moduleName}.
 *
 * @param  array  $params
 * @param  string $namespace
 * @param  string $orderNum
 * @return string  'module_install_success:{moduleName}' or 'module_install_error:{message}'
 */
function rancherfleet_handleModuleInstall(array $params, $namespace, $orderNum)
{
    $moduleName = isset($_POST['module_name']) ? trim($_POST['module_name']) : '';
    $available  = rancherfleet_getAvailableModules();

    if (!isset($available[$moduleName])) {
        return 'module_install_error:Invalid module name.';
    }

    RancherFleet\Logger::info("moduleInstall: starting for {$namespace} / {$moduleName}");

    try {
        list($rancher) = rancherfleet_buildClients($params);

        $pythonScript = <<<'PYTHON'
import subprocess
import sys

try:
    module_name = sys.argv[1]
    result = subprocess.run([
        '/usr/bin/odoo',
        '--config=/etc/odoo/odoo.conf',
        '--init=' + module_name,
        '--without-demo=all',
        '--stop-after-init'
    ], capture_output=True, text=True, timeout=300)
    sys.exit(result.returncode)
except Exception as e:
    sys.stderr.write(str(e))
    sys.exit(1)
PYTHON;

        $jobName = 'rfm-install-mod-' . time() . '-' . substr(md5($orderNum), 0, 4);
        $jobName = substr($jobName, 0, 52) . '-' . substr(md5($orderNum), 0, 8);

        $jobManifest = array(
            'apiVersion' => 'batch/v1',
            'kind'       => 'Job',
            'metadata'   => array(
                'name'      => $jobName,
                'namespace' => $namespace,
                'labels'    => array('app' => 'rfm-install-mod'),
            ),
            'spec' => array(
                'ttlSecondsAfterFinished' => 300,
                'backoffLimit'            => 0,
                'template'               => array(
                    'spec' => array(
                        'restartPolicy' => 'Never',
                        'containers'    => array(array(
                            'name'    => 'installmod',
                            'image'   => 'odoo:19',
                            'command' => array('python3', '-c', $pythonScript),
                            'args'    => array($moduleName),
                            'volumeMounts' => array(
                                array(
                                    'name'      => 'odoo-config',
                                    'mountPath' => '/etc/odoo',
                                ),
                                array(
                                    'name'      => 'odoo-data',
                                    'mountPath' => '/var/lib/odoo',
                                    'subPath'   => 'odoo',
                                ),
                            ),
                        )),
                        'volumes' => array(
                            array(
                                'name'      => 'odoo-config',
                                'configMap' => array(
                                    'name' => 'odoo-' . $orderNum . '.conf',
                                ),
                            ),
                            array(
                                'name'         => 'odoo-data',
                                'persistentVolumeClaim' => array(
                                    'claimName' => 'odoo-' . $orderNum,
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );

        RancherFleet\Logger::info("moduleInstall: creating Job {$jobName} for {$moduleName}");

        $rancher->rawRequest('POST',
            '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs',
            $jobManifest
        );

        $succeeded = false;
        $failed    = false;
        $jobPath   = '/apis/batch/v1/namespaces/' . rawurlencode($namespace) . '/jobs/' . rawurlencode($jobName);

        for ($i = 0; $i < 30; $i++) {
            sleep(10);
            try {
                $job        = $rancher->rawRequest('GET', $jobPath);
                $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                foreach ($conditions as $cond) {
                    if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                        $succeeded = true; break 2;
                    }
                    if (isset($cond['type']) && $cond['type'] === 'Failed' && $cond['status'] === 'True') {
                        $failed = true; break 2;
                    }
                }
                RancherFleet\Logger::info("moduleInstall: waiting for Job... " . (($i + 1) * 10) . "s elapsed");
            } catch (\Exception $e) {
                RancherFleet\Logger::error("moduleInstall: error polling Job: " . $e->getMessage());
                break;
            }
        }

        try {
            $rancher->rawRequest('DELETE', $jobPath);
        } catch (\Exception $e) {
            RancherFleet\Logger::info("moduleInstall: Job cleanup note: " . $e->getMessage());
        }

        if ($succeeded) {
            try {
                $serviceId = (int)$params['serviceid'];
                \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'rancherfleet_modules')
                    ->where('setting', 'status_' . $serviceId)->delete();
                RancherFleet\Logger::info("moduleInstall: cache CLEARED for service {$serviceId} (module {$moduleName} installed)");
            } catch (\Exception $e) {
                RancherFleet\Logger::error("moduleInstall: cache clear error (non-fatal): " . $e->getMessage());
            }

            RancherFleet\Logger::info("moduleInstall: SUCCESS for {$moduleName}");
            rancherfleet_logHistory($params, 'App Installed', $available[$moduleName]['name']);
            return 'module_install_success:' . $moduleName;
        } elseif ($failed) {
            RancherFleet\Logger::error("moduleInstall: Job failed for {$moduleName}");
            rancherfleet_logHistory($params, 'App Install Failed', $available[$moduleName]['name']);
            return 'module_install_error:Installation job failed. Check admin logs for details.';
        } else {
            RancherFleet\Logger::error("moduleInstall: Job timed out for {$moduleName}");
            rancherfleet_logHistory($params, 'App Install Timeout', $available[$moduleName]['name']);
            return 'module_install_error:Installation job timed out. Please try again.';
        }

    } catch (\Exception $e) {
        RancherFleet\Logger::error("moduleInstall: exception — " . $e->getMessage());
        rancherfleet_logHistory($params, 'App Install Error', $e->getMessage());
        return 'module_install_error:' . $e->getMessage();
    }
}


/**
 * Handles version upgrade request from client area.
 * Validates input, checks credit, and stores pending request.
 *
 * @return string  'upgrade_success:{version}' or 'upgrade_error:{message}'
 */
function rancherfleet_handleUpgradeRequest(array $params, $namespace, $orderNum)
{
    $serviceId = (int)$params['serviceid'];
    $targetVersion = isset($_POST['upgrade_version']) ? trim($_POST['upgrade_version']) : '';
    $acknowledged = isset($_POST['upgrade_acknowledge']) ? (bool)$_POST['upgrade_acknowledge'] : false;

    RancherFleet\Logger::info("handleUpgradeRequest: {$namespace} to version {$targetVersion}");

    if (!$acknowledged) {
        return 'upgrade_error:You must acknowledge the upgrade consequences.';
    }

    if (empty($targetVersion)) {
        return 'upgrade_error:No version selected.';
    }

    try {
        // Get current version from deployed image
        list($rancher) = rancherfleet_buildClients($params);
        $status = $rancher->getDeploymentStatus($namespace, 'odoo-' . $orderNum);
        $currentVersion = null;

        if ($status['image']) {
            if (preg_match('/odoo:([0-9.]+)/', $status['image'], $m)) {
                $currentVersion = $m[1];
            }
        }

        if (!$currentVersion) {
            return 'upgrade_error:Could not determine current Odoo version.';
        }

        if (!version_compare($targetVersion, $currentVersion, '>')) {
            return 'upgrade_error:Target version must be higher than current version (' . $currentVersion . ').';
        }

        // Get upgrade config
        $cfg = rancherfleet_getUpgradeConfig($params);

        if (!in_array($targetVersion, $cfg['versions'])) {
            return 'upgrade_error:Target version not available for upgrade.';
        }

        $fee = isset($cfg['fees'][$targetVersion]) ? $cfg['fees'][$targetVersion] : 0;

        if ($fee <= 0) {
            return 'upgrade_error:Fee not configured for this version.';
        }

        // Check for existing request — only allow if completed, cancelled, or doesn't exist
        $clientId = (int)$params['userid'];
        $existingRequest = rancherfleet_getUpgradeRequest($serviceId);
        if (!empty($existingRequest)) {
            $existingStatus = isset($existingRequest['status']) ? $existingRequest['status'] : 'unknown';
            $existingInvoiceId = isset($existingRequest['invoiceId']) ? $existingRequest['invoiceId'] : null;
            if (!in_array($existingStatus, array('completed', 'cancelled'))) {
                return 'upgrade_error:You already have a pending upgrade request. ' .
                       'Please complete payment for invoice #' . $existingInvoiceId . ' or contact support to cancel.';
            }
        }

        // Create Unpaid invoice for the upgrade
        RancherFleet\Logger::info("handleUpgradeRequest: creating invoice for upgrade to {$targetVersion}");
        try {
            $invoiceResult = localAPI('CreateInvoice', array(
                'userid'           => $clientId,
                'status'           => 'Unpaid',
                'itemdescription1' => "Odoo version upgrade to {$targetVersion}",
                'itemamount1'      => $fee,
                'itemtaxed1'       => false,
                'paymentmethod'    => 'credit',
                'notes'            => 'rfm_upgrade:' . $serviceId,
            ));

            if (!isset($invoiceResult['result']) || $invoiceResult['result'] !== 'success') {
                $err = isset($invoiceResult['message']) ? $invoiceResult['message'] : json_encode($invoiceResult);
                return 'upgrade_error:Could not create invoice: ' . $err;
            }

            $invoiceId = (int)$invoiceResult['invoiceid'];
            RancherFleet\Logger::info("handleUpgradeRequest: invoice created, ID={$invoiceId}");
        } catch (\Exception $e) {
            return 'upgrade_error:Could not create invoice: ' . $e->getMessage();
        }

        // Store upgrade request with status='awaiting_payment'
        RancherFleet\Logger::info("handleUpgradeRequest: storing upgrade request with status=awaiting_payment, invoiceId={$invoiceId}");
        rancherfleet_storeUpgradeRequest($serviceId, $targetVersion, $fee, $invoiceId, 'awaiting_payment');

        // Log activity
        rancherfleet_logHistory($params, 'Upgrade Requested', 'Upgrade to Odoo ' . $targetVersion . ' requested. Invoice #' . $invoiceId . ' created for $' . number_format($fee, 2) . '. Awaiting payment.');

        // Send admin notification
        try {
            logActivity(
                'Module Output',
                'Version upgrade requested for service ' . $serviceId . ' (Order #' . $orderNum . ') to Odoo ' . $targetVersion . ' - $' . number_format($fee, 2) . ' (Invoice #' . $invoiceId . ') - Awaiting payment',
                'Rancher Fleet Module'
            );
        } catch (\Exception $e) {
            RancherFleet\Logger::info("handleUpgradeRequest: activity log error (non-fatal): " . $e->getMessage());
        }

        return 'upgrade_success_awaiting_payment:' . $invoiceId;

    } catch (\Exception $e) {
        RancherFleet\Logger::error("handleUpgradeRequest: " . $e->getMessage());
        rancherfleet_logHistory($params, 'Upgrade Request Error', $e->getMessage());
        return 'upgrade_error:' . $e->getMessage();
    }
}


/**
 * Cancels a pending upgrade request.
 */
function rancherfleet_handleCancelUpgrade(array $params, $orderNum)
{
    $serviceId = (int)$params['serviceid'];

    RancherFleet\Logger::info("handleCancelUpgrade: {$serviceId}");

    try {
        \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_upgrade')
            ->where('setting', 'request_' . $serviceId)
            ->delete();

        rancherfleet_logHistory($params, 'Upgrade Request Cancelled', 'Pending upgrade request cancelled by customer');
        return 'upgrade_cancelled:Request cancelled.';

    } catch (\Exception $e) {
        RancherFleet\Logger::error("handleCancelUpgrade: " . $e->getMessage());
        return 'upgrade_error:' . $e->getMessage();
    }
}


/**
 * Parses upgrade versions from comma-separated string (e.g. "19,20.0,21.0")
 * Returns array of version strings sorted highest first.
 */
function rancherfleet_parseUpgradeVersions($versionString)
{
    if (empty($versionString)) return array();
    $versions = array_map('trim', explode(',', $versionString));
    $versions = array_filter($versions);
    usort($versions, function($a, $b) {
        return version_compare($b, $a);
    });
    return $versions;
}


/**
 * Parses upgrade fees from comma-separated "version:fee" pairs.
 * Returns associative array: {version => fee}.
 */
function rancherfleet_parseUpgradeFees($feeString)
{
    $fees = array();
    if (empty($feeString)) return $fees;

    foreach (explode(',', $feeString) as $pair) {
        $parts = array_map('trim', explode(':', $pair));
        if (count($parts) === 2 && is_numeric($parts[1])) {
            $fees[$parts[0]] = (float)$parts[1];
        }
    }
    return $fees;
}


/**
 * Gets available upgrade versions and fees for a product.
 * Returns array: ['versions' => [...], 'fees' => {...}]
 */
function rancherfleet_getUpgradeConfig(array $params)
{
    $versionString = isset($params['configoption23']) ? trim($params['configoption23']) : '';
    $feeString = isset($params['configoption24']) ? trim($params['configoption24']) : '';

    return array(
        'versions' => rancherfleet_parseUpgradeVersions($versionString),
        'fees'     => rancherfleet_parseUpgradeFees($feeString),
    );
}


/**
 * Gets upgrade request from tbladdonmodules if one exists.
 * Returns array with version, fee, requested_at, status, or empty.
 */
function rancherfleet_getUpgradeRequest($serviceId)
{
    try {
        $record = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_upgrade')
            ->where('setting', 'request_' . $serviceId)
            ->first();

        if ($record) {
            $data = json_decode($record->value, true);
            if (isset($data['status']) && $data['status'] !== 'completed') {
                return $data;
            }
        }
    } catch (\Exception $e) {
        RancherFleet\Logger::info("getUpgradeRequest: " . $e->getMessage());
    }

    return array();
}


/**
 * Stores upgrade request in tbladdonmodules — only if it doesn't already exist.
 * This prevents overwriting charged/in-progress requests.
 *
 * @param int    $serviceId
 * @param string $version    Target Odoo version
 * @param float  $fee        Upgrade fee
 * @param int    $invoiceId  WHMCS invoice ID
 * @param string $status     Request status ('awaiting_payment', 'pending', etc)
 */
function rancherfleet_storeUpgradeRequest($serviceId, $version, $fee, $invoiceId = null, $status = 'awaiting_payment')
{
    try {
        $setting = 'request_' . $serviceId;

        // Check if record already exists
        $exists = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_upgrade')
            ->where('setting', $setting)
            ->exists();

        if ($exists) {
            RancherFleet\Logger::info("storeUpgradeRequest: NOT overwriting existing record for service {$serviceId}");
            return;
        }

        $data = array(
            'version'           => $version,
            'fee'               => $fee,
            'requested_at'      => time(),
            'status'            => $status,
            'staging_url'       => null,
            'staging_shared'    => false,
            'invoiceId'         => $invoiceId,
            'staging_created_at' => null,
        );

        \WHMCS\Database\Capsule::table('tbladdonmodules')->insert(array(
            'module' => 'rancherfleet_upgrade',
            'setting' => $setting,
            'value' => json_encode($data),
        ));

        RancherFleet\Logger::info("storeUpgradeRequest: wrote new record for service {$serviceId}, status={$status}, invoiceId={$invoiceId}");
    } catch (\Exception $e) {
        RancherFleet\Logger::error("storeUpgradeRequest: " . $e->getMessage());
    }
}


/**
 * Renders the Upgrade Odoo Version card for the client area with staging support.
 */
function rancherfleet_upgradeVersionCardHtml(array $params, $orderNum, $currentVersion)
{
    $serviceUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '');
    $serviceId = (int)$params['serviceid'];
    $cfg = rancherfleet_getUpgradeConfig($params);

    $html = '<div class="rfm-ca-card">';
    $html .= '<h4>Upgrade Odoo Version</h4>';

    $html .= '<div style="margin-bottom:12px;">';
    $html .= '<p style="font-size:12px;color:#666;">Your instance is running Odoo <strong>' . htmlspecialchars($currentVersion) . '</strong></p>';

    // Check for existing upgrade request
    $existingRequest = rancherfleet_getUpgradeRequest($serviceId);
    if (!empty($existingRequest)) {
        $status = isset($existingRequest['status']) ? $existingRequest['status'] : 'pending';

        if ($status === 'awaiting_payment') {
            // Invoice created but not yet paid
            $invoiceId = isset($existingRequest['invoiceId']) ? (int)$existingRequest['invoiceId'] : 0;
            $html .= '<div style="background:#cfe2ff;border:1px solid #0d6efd;border-radius:6px;padding:12px;margin-bottom:12px;">';
            $html .= '<p style="margin:0 0 10px;font-size:12px;color:#004085;">Your upgrade request to Odoo <strong>' . htmlspecialchars($existingRequest['version']) . '</strong> is ready. Invoice #' . $invoiceId . ' has been created.</p>';
            if ($invoiceId > 0) {
                $html .= '<a href="/clientarea.php?action=invoices&id=' . $invoiceId . '" style="display:inline-block;background:#0d6efd;color:#fff;text-decoration:none;border-radius:4px;padding:6px 12px;font-size:11px;font-weight:bold;">Pay Invoice #' . $invoiceId . '</a>';
            }
            $html .= '</div>';
        } elseif ($status === 'pending') {
            // Pending staging creation
            $html .= '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;margin-bottom:12px;">';
            $html .= '<p style="margin:0;font-size:12px;color:#856404;">Your upgrade request to Odoo <strong>' . htmlspecialchars($existingRequest['version']) . '</strong> is pending. Our team is preparing your staging environment.</p>';
            $html .= '<form method="post" action="' . $serviceUrl . '" style="margin-top:10px;">';
            $html .= '<input type="hidden" name="clientaction" value="cancel_version_upgrade">';
            $html .= '<button type="submit" style="background:#dc3545;color:#fff;border:none;border-radius:4px;padding:6px 12px;font-size:11px;font-weight:bold;cursor:pointer;">Cancel Request</button>';
            $html .= '</form>';
            $html .= '</div>';
        } elseif ($status === 'staging_in_progress') {
            // Staging environment is being created
            $html .= '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;margin-bottom:12px;">';
            $html .= '<p style="margin:0;font-size:12px;color:#856404;">Staging environment is being created. Please wait...</p>';
            $html .= '</div>';
        } elseif ($status === 'staging') {
            // Staging is ready
            $stagingUrl = isset($existingRequest['staging_url']) ? $existingRequest['staging_url'] : null;
            $stagingShared = isset($existingRequest['staging_shared']) ? $existingRequest['staging_shared'] : false;

            if ($stagingShared) {
                $html .= '<div style="background:#d4edda;border:1px solid #28a745;border-radius:6px;padding:12px;margin-bottom:12px;">';
                $html .= '<p style="margin:0;font-size:12px;color:#155724;">Your staging environment is ready at <strong>' . htmlspecialchars($stagingUrl) . '</strong>. Review it and contact support to proceed with the live upgrade.</p>';
                $html .= '</div>';
            } else {
                $html .= '<div style="background:#cfe2ff;border:1px solid #0d6efd;border-radius:6px;padding:12px;margin-bottom:12px;">';
                $html .= '<p style="margin:0;font-size:12px;color:#004085;">Your staging environment is being prepared. Please wait for admin confirmation.</p>';
                $html .= '</div>';
            }
        } elseif ($status === 'staging_failed') {
            // Staging creation failed
            $html .= '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:12px;margin-bottom:12px;">';
            $html .= '<p style="margin:0;font-size:12px;color:#721c24;">Staging environment creation failed. Please contact support.</p>';
            $html .= '</div>';
        } elseif ($status === 'maintenance_window') {
            // Upgrade in progress
            $html .= '<div style="background:#cfe2ff;border:1px solid #0d6efd;border-radius:6px;padding:12px;margin-bottom:12px;">';
            $html .= '<p style="margin:0;font-size:12px;color:#004085;">Upgrade in progress — your instance is temporarily offline for the final cutover. This typically takes 5-15 minutes.</p>';
            $html .= '</div>';
        } elseif ($status === 'live_upgraded') {
            // Upgrade complete
            $version = isset($existingRequest['version']) ? $existingRequest['version'] : 'N/A';
            $html .= '<div style="background:#d4edda;border:1px solid #28a745;border-radius:6px;padding:12px;margin-bottom:12px;">';
            $html .= '<p style="margin:0;font-size:12px;color:#155724;">Upgrade complete! &#10003; Running Odoo <strong>' . htmlspecialchars($version) . '</strong>. Staging environment retained for 7 days.</p>';
            $html .= '</div>';
        } elseif ($status === 'rolled_back') {
            // Rollback occurred
            $html .= '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:12px;margin-bottom:12px;">';
            $html .= '<p style="margin:0;font-size:12px;color:#721c24;">An issue was detected with the upgrade and it was rolled back to your previous version. Our team will investigate.</p>';
            $html .= '</div>';
        } elseif ($status === 'archived') {
            // Cleanup complete
            $html .= '<div style="background:#d4edda;border:1px solid #28a745;border-radius:6px;padding:12px;margin-bottom:12px;">';
            $html .= '<p style="margin:0;font-size:12px;color:#155724;">Staging environment cleaned up. Upgrade process complete.</p>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    // Filter to only higher versions
    $availableVersions = array();
    foreach ($cfg['versions'] as $v) {
        if (version_compare($v, $currentVersion, '>')) {
            $availableVersions[] = $v;
        }
    }

    if (empty($availableVersions)) {
        $html .= '<p style="font-size:12px;color:#888;">Your instance is already at the latest available version.</p>';
        $html .= '</div>';
        return $html;
    }

    $html .= '<form method="post" action="' . $serviceUrl . '">';
    $html .= '<div style="margin-bottom:12px;">';
    $html .= '<label style="display:block;font-size:12px;font-weight:bold;margin-bottom:6px;color:#333;">Select target version:</label>';
    $html .= '<select name="upgrade_version" id="upgrade_version" onchange="updateUpgradeFee()" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;margin-bottom:10px;">';
    $html .= '<option value="">Choose a version...</option>';
    foreach ($availableVersions as $v) {
        $fee = isset($cfg['fees'][$v]) ? $cfg['fees'][$v] : 0;
        $html .= '<option value="' . htmlspecialchars($v) . '" data-fee="' . htmlspecialchars($fee) . '">' . htmlspecialchars($v) . '</option>';
    }
    $html .= '</select>';
    $html .= '</div>';

    $html .= '<div id="fee-display" style="display:none;margin-bottom:12px;padding:10px;background:#f8f9fa;border-radius:6px;">';
    $html .= '<div style="font-size:12px;color:#666;">Upgrade fee: <strong id="fee-amount">$0.00</strong> (invoice will be created at checkout)</div>';
    $html .= '</div>';

    $html .= '<div style="background:#ffe9e9;border:1px solid #ffcccc;border-radius:6px;padding:12px;margin-bottom:12px;">';
    $html .= '<p style="margin:0 0 8px;font-size:12px;font-weight:bold;color:#d63031;">ℹ Version Upgrades are a Managed Service</p>';
    $html .= '<p style="margin:0;font-size:12px;color:#333;">Our team will personally oversee your upgrade to ensure your data is preserved. Once you submit your request and complete payment, we will contact you to schedule a maintenance window (typically 2-4 hours). Your instance will remain online until the cutover date.</p>';
    $html .= '</div>';

    $html .= '<div style="margin-bottom:12px;">';
    $html .= '<label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer;">';
    $html .= '<input type="checkbox" name="upgrade_acknowledge" id="upgrade_acknowledge" onchange="updateSubmitButton()" style="cursor:pointer;">';
    $html .= '<span>I understand the upgrade will require downtime and cannot be undone</span>';
    $html .= '</label>';
    $html .= '</div>';

    $html .= '<input type="hidden" name="clientaction" value="request_version_upgrade">';
    $html .= '<button type="submit" id="upgrade-submit" disabled style="background:#e67e22;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:bold;cursor:not-allowed;opacity:0.6;">Request Upgrade</button>';
    $html .= '</form>';

    $html .= '<script type="text/javascript">';
    $html .= 'function updateUpgradeFee() {';
    $html .= '  var select = document.getElementById("upgrade_version");';
    $html .= '  var option = select.options[select.selectedIndex];';
    $html .= '  var fee = parseFloat(option.getAttribute("data-fee")) || 0;';
    $html .= '  var display = document.getElementById("fee-display");';
    $html .= '  var amount = document.getElementById("fee-amount");';
    $html .= '  if (select.value) {';
    $html .= '    display.style.display = "block";';
    $html .= '    amount.textContent = "$" + fee.toFixed(2);';
    $html .= '  } else {';
    $html .= '    display.style.display = "none";';
    $html .= '  }';
    $html .= '  updateSubmitButton();';
    $html .= '}';
    $html .= 'function updateSubmitButton() {';
    $html .= '  var version = document.getElementById("upgrade_version").value;';
    $html .= '  var ack = document.getElementById("upgrade_acknowledge").checked;';
    $html .= '  var btn = document.getElementById("upgrade-submit");';
    $html .= '  if (version && ack) {';
    $html .= '    btn.disabled = false;';
    $html .= '    btn.style.opacity = "1";';
    $html .= '    btn.style.cursor = "pointer";';
    $html .= '  } else {';
    $html .= '    btn.disabled = true;';
    $html .= '    btn.style.opacity = "0.6";';
    $html .= '    btn.style.cursor = "not-allowed";';
    $html .= '  }';
    $html .= '}';
    $html .= '</script>';

    $html .= '</div>';
    return $html;
}


/**
 * Renders the Install Apps card for the client area.
 */
function rancherfleet_installAppCardHtml(array $params, $orderNum)
{
    $serviceUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '');
    $serviceId  = (int)$params['serviceid'];
    $available  = rancherfleet_getAvailableModules();
    $cached     = rancherfleet_getModuleStatus($serviceId);

    RancherFleet\Logger::info("installAppCardHtml: rendering for service {$serviceId}, cache status: " . (empty($cached) ? 'EMPTY' : count($cached) . ' modules'));

    $html = '<div class="rfm-ca-card">';
    $html .= '<h4>Install Apps</h4>';
    $html .= '<p style="font-size:12px;color:#666;margin-bottom:14px;">App installation runs in the background and may take 5-10 minutes. Your instance will remain online during installation.</p>';

    // Group by category
    $byCategory = array();
    foreach ($available as $key => $module) {
        $cat = $module['category'];
        if (!isset($byCategory[$cat])) {
            $byCategory[$cat] = array();
        }
        $byCategory[$cat][$key] = $module;
    }

    foreach ($byCategory as $category => $modules) {
        $html .= '<div style="margin-bottom:16px;">';
        $html .= '<div style="font-size:11px;font-weight:bold;color:#666;text-transform:uppercase;letter-spacing:0.5px;padding:8px 0;border-bottom:1px solid #eee;margin-bottom:10px;">' . htmlspecialchars($category) . '</div>';

        foreach ($modules as $key => $module) {
            // Check if this module is in the cached status data
            $state = isset($cached[$key]) ? $cached[$key] : '';
            $isInstalled = ($state === 'installed');

            RancherFleet\Logger::info("installAppCardHtml: module {$key} state='{$state}' installed={$isInstalled}");

            $html .= '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:10px;padding:10px;background:#f9f9f9;border-radius:6px;border:1px solid #eee;">';

            // Module info
            $html .= '<div style="flex:1;">';
            $html .= '<div style="font-size:13px;font-weight:bold;color:#333;margin-bottom:4px;">' . htmlspecialchars($module['name']) . '</div>';
            $html .= '<div style="font-size:12px;color:#666;margin-bottom:6px;">' . htmlspecialchars($module['desc']) . '</div>';

            // Status badges and buttons
            if ($isInstalled) {
                // Module is installed - show greyed out badge
                $html .= '<span style="display:inline-block;background:#e8f5e9;color:#2e7d32;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:bold;">&#10003; Installed</span>';
            } elseif ($state === 'to upgrade') {
                // Update available
                $html .= '<span style="display:inline-block;background:#fff3cd;color:#856404;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:bold;">&#9888; Update Available</span>';
            } else {
                // Not installed - show install button
                $html .= '<form method="post" action="' . $serviceUrl . '" style="display:inline;">';
                $html .= '<input type="hidden" name="clientaction" value="install_module">';
                $html .= '<input type="hidden" name="module_name" value="' . htmlspecialchars($key) . '">';
                $html .= '<button type="submit" style="background:#2196F3;color:#fff;border:none;border-radius:4px;padding:4px 12px;font-size:11px;font-weight:bold;cursor:pointer;margin:0;">';
                $html .= 'Install</button>';
                $html .= '</form>';
            }
            $html .= '</div>';

            // Category badge
            $html .= '<div style="flex-shrink:0;text-align:right;">';
            $html .= '<span style="display:inline-block;background:#e3f2fd;color:#1565c0;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:bold;">' . htmlspecialchars($category) . '</span>';
            $html .= '</div>';

            $html .= '</div>';
        }

        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

// ---------------------------------------------------------------------------
// Phase A: Provisioning Rollback


// ---------------------------------------------------------------------------

/**
 * Tracks which phases completed successfully during a provisioning run.
 * Used to roll back completed phases if a later phase fails.
 *
 * State is stored as a static array within the request lifecycle.
 * Keys: 'namespace', 'branch_created', 'gitrepo_created'
 */
function rancherfleet_doRollback(array $params, array $completed)
{
    RancherFleet\Logger::info("rollback: starting for completed=" . implode(',', array_keys(array_filter($completed))));

    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        // Roll back in reverse order of completion

        if (!empty($completed['gitrepo_created'])) {
            try {
                $fleet->deleteGitRepo($namespace);
                RancherFleet\Logger::info("rollback: deleted GitRepo for {$namespace}");
            } catch (Exception $e) {
                RancherFleet\Logger::error("rollback: failed to delete GitRepo: " . $e->getMessage());
            }
        }

        if (!empty($completed['branch_created'])) {
            try {
                $github->deleteClientFolder($namespace);
                RancherFleet\Logger::info("rollback: deleted GitHub branch for {$namespace}");
            } catch (Exception $e) {
                RancherFleet\Logger::error("rollback: failed to delete GitHub branch: " . $e->getMessage());
            }
        }

        if (!empty($completed['namespace_created'])) {
            try {
                $rancher->deleteNamespace($namespace);
                RancherFleet\Logger::info("rollback: deleted namespace {$namespace}");
            } catch (Exception $e) {
                RancherFleet\Logger::error("rollback: failed to delete namespace: " . $e->getMessage());
            }
        }

        RancherFleet\Logger::info("rollback: complete for {$namespace}");

    } catch (Exception $e) {
        RancherFleet\Logger::error("rollback: outer error: " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Phase A: Termination Verification
// ---------------------------------------------------------------------------

/**
 * Verifies that all resources have actually been removed after termination.
 * Returns an array of any resources that still exist.
 */
function rancherfleet_checkTermination(array $params, $namespace)
{
    $remaining = array();

    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);

        // Check namespace
        try {
            if ($rancher->namespaceExists($namespace)) {
                $remaining[] = "Namespace '{$namespace}' still exists in cluster";
            }
        } catch (Exception $e) {
            $remaining[] = "Could not verify namespace: " . $e->getMessage();
        }

        // Check GitHub branch
        try {
            if ($github->branchExists($namespace)) {
                $remaining[] = "GitHub branch '{$namespace}' still exists";
            }
        } catch (Exception $e) {
            $remaining[] = "Could not verify GitHub branch: " . $e->getMessage();
        }

        // Check Fleet GitRepo
        try {
            $gitRepo = $fleet->getGitRepo($namespace);
            if (!empty($gitRepo)) {
                $remaining[] = "Fleet GitRepo 'gitrepo-{$namespace}' still exists";
            }
        } catch (Exception $e) {
            $remaining[] = "Could not verify Fleet GitRepo: " . $e->getMessage();
        }

    } catch (Exception $e) {
        $remaining[] = "Verification error: " . $e->getMessage();
    }

    return $remaining;
}

// ---------------------------------------------------------------------------
// Admin Custom Button Array
// WHMCS renders these as real buttons on the service page and routes
// clicks to rancherfleet_{FunctionName} automatically.
// ---------------------------------------------------------------------------

function rancherfleet_AdminCustomButtonArray()
{
    return array(
        '1. Test Connection'  => 'TestConnection',
        '2. Create Namespace' => 'CreateNamespace',
        '3. Bootstrap GitHub' => 'BootstrapGithub',
        '4. Create GitRepo'   => 'CreateGitRepo',
        'Repair GitOps Target' => 'RepairGitRepo',
        '5a. Test Suspend'    => 'TestSuspend',
        '5b. Test Unsuspend'  => 'TestUnsuspend',
        'Rollback'            => 'Rollback',
        'Verify Termination'  => 'VerifyTermination',
        'Health Check'        => 'HealthCheck',
        'Apply Quota'         => 'ApplyQuota',
        'Inject Secrets'      => 'InjectSecrets',
        'Get Kubeconfig'      => 'GetKubeconfig',
        'Collect Usage'         => 'CollectUsage',
        'Execute Grace Suspend'  => 'ExecuteGraceSuspend',
        'Dry Run'                => 'DryRunProvision',
        'Clear Retry Queue'      => 'ClearRetryQueue',
        'Push Backup CronJob'    => 'PushBackupCronJob',
        'Push Backup Sidecar'    => 'PushBackupSidecar',
        'Patch Backup Storage'   => 'PatchBackupStorage',
        'Refresh Webhook Secret' => 'RefreshWebhookSecret',
        'Remove Custom URL'       => 'RemoveCustomUrl',
        'Patch Template Updates'  => 'PatchTemplateUpdates',
        'Create Staging Upgrade'  => 'CreateStagingUpgrade',
        'Create Staging (Override)' => 'CreateStagingOverride',
        'Trigger Live Upgrade'    => 'TriggerLiveUpgrade',
        'Rollback Upgrade'        => 'RollbackUpgrade',
        'Cleanup Upgrade'         => 'CleanupUpgrade',
        'Reset Staging'           => 'ResetStaging',
    );
}

// ---------------------------------------------------------------------------
// Admin Custom Button Handlers
// WHMCS calls rancherfleet_{FunctionName}() and displays the return value
// as a notice at the top of the page.
// ---------------------------------------------------------------------------

function rancherfleet_TestConnection(array $params)
{
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $clusterName   = $rancher->testConnection();
        RancherFleet\Logger::info("TestConnection: SUCCESS - " . $clusterName);
        return 'Success: Connected to cluster ' . $clusterName;
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("TestConnection FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_CreateNamespace(array $params)
{
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $rancher->createNamespace($namespace);
        RancherFleet\Logger::info("CreateNamespace: SUCCESS - {$namespace}");
        return "Success: Namespace '{$namespace}' created.";
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("CreateNamespace FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_BootstrapGithub(array $params)
{
    try {
        list($rancher, $github) = rancherfleet_buildClients($params);
        $orderNum = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $repoPath  = 'whmcs-client-' . $orderNum;
        $github->bootstrapClientFolder($repoPath, $namespace, $orderNum);
        RancherFleet\Logger::info("BootstrapGithub: SUCCESS - {$repoPath}");
        return "Success: Files pushed to repo at '{$repoPath}'.";
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("BootstrapGithub FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_CreateGitRepo(array $params)
{
    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);
        $orderNum = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $repoPath  = 'whmcs-client-' . $orderNum;
        $fleet->createGitRepo($namespace, $repoPath);
        RancherFleet\Logger::info("CreateGitRepo: SUCCESS - gitrepo-{$namespace}");
        return "Success: Fleet GitRepo 'gitrepo-{$namespace}' created.";
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("CreateGitRepo FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Repairs a client's Fleet GitRepo object.
 *
 * Older versions of this module constructed FleetHelper with a mismatched
 * argument list, which caused:
 *   - GitRepo objects to be created in the wrong Fleet namespace
 *     (the downstream cluster ID was used as the namespace instead of
 *     the configured fleet_namespace, e.g. 'fleet-default')
 *   - spec.targets[].clusterName to be set to the Fleet management cluster
 *     ID/name ('local' by default) instead of the target cluster's
 *     DISPLAY NAME — so Fleet never matched the bundle to any registered
 *     cluster and the GitRepo stayed stuck in a "cluster not ready" state.
 *   - spec.repo to be built from the wrong owner/repo values.
 *
 * This handler:
 *   1. Looks for and removes any stale GitRepo left behind in the OLD
 *      (incorrect) Fleet namespace.
 *   2. Creates or updates the GitRepo in the CORRECT Fleet namespace with
 *      the correct repo URL, branch, paths, and clusterName (the target
 *      cluster's display name, resolved via the Rancher v3 API).
 */
function rancherfleet_RepairGitRepo(array $params)
{
    try {
        list($rancher, $github, $fleet, $cfg) = rancherfleet_buildClients($params);
        $orderNum    = rancherfleet_getOrderNumber($params);
        $namespace   = 'whmcs-client-' . $orderNum;
        $repoPath    = 'whmcs-client-' . $orderNum;
        $gitRepoName = 'gitrepo-' . $namespace;

        $actions = array();

        $correctFleetNs = $fleet->getFleetNamespace();

        // Old buggy constructor call used target_cluster_id (falling back to
        // cluster_id, then 'local') as the Fleet namespace argument.
        $oldFleetNs = isset($cfg['target_cluster_id']) ? $cfg['target_cluster_id']
                    : (isset($cfg['cluster_id']) ? $cfg['cluster_id'] : 'local');

        // Step 1: clean up any stale GitRepo in the old (incorrect) namespace
        if ($oldFleetNs !== $correctFleetNs) {
            try {
                // Fleet GitRepo CRDs live on the LOCAL (Fleet management) cluster,
                // not the downstream cluster — use rancherRequest with /k8s/clusters/local path.
                $old = $rancher->rancherRequest(
                    'GET',
                    '/k8s/clusters/local' . RancherFleet\FleetHelper::FLEET_API_PATH . '/namespaces/' . $oldFleetNs . '/gitrepos/' . $gitRepoName
                );
                if (!empty($old)) {
                    $rancher->rancherRequest(
                        'DELETE',
                        '/k8s/clusters/local' . RancherFleet\FleetHelper::FLEET_API_PATH . '/namespaces/' . $oldFleetNs . '/gitrepos/' . $gitRepoName
                    );
                    $actions[] = "removed stale GitRepo from namespace '{$oldFleetNs}'";
                }
            } catch (RancherFleet\RancherApiException $e) {
                if ($e->getHttpCode() !== 404) {
                    throw $e;
                }
            }
        }

        // Step 2: create or update the GitRepo in the correct namespace with
        // the corrected repo URL, branch, paths, and clusterName.
        $existing = $fleet->getGitRepo($namespace);
        if (!empty($existing)) {
            $fleet->deleteGitRepo($namespace);
        }
        $fleet->createGitRepo($namespace, $repoPath);
        $actions[] = !empty($existing)
            ? "updated GitRepo in '{$correctFleetNs}'"
            : "created GitRepo in '{$correctFleetNs}'";

        $actions[] = "clusterName='" . $fleet->getTargetClusterName() . "'";

        $msg = implode(', ', $actions);
        RancherFleet\Logger::info("RepairGitRepo: {$msg}");
        rancherfleet_logHistory($params, 'GitRepo Repaired', $msg);

        return 'Success: ' . $msg . '. Fleet should reconcile within ~15s (pollingInterval).';
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("RepairGitRepo FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_TestSuspend(array $params)
{
    try {
        list($rancher, $github) = rancherfleet_buildClients($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $github->setReplicas($namespace, 0);
        RancherFleet\Logger::info("TestSuspend: SUCCESS - pushed replicas=0 for {$namespace}");
        return "Success: replicas=0 pushed to branch '{$namespace}'. Fleet will scale down shortly.";
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("TestSuspend FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_TestUnsuspend(array $params)
{
    try {
        list($rancher, $github) = rancherfleet_buildClients($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $github->setReplicas($namespace, 1);
        RancherFleet\Logger::info("TestUnsuspend: SUCCESS - pushed replicas=1 for {$namespace}");
        return "Success: replicas=1 pushed to branch '{$namespace}'. Fleet will scale up shortly.";
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("TestUnsuspend FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Phase A: Rollback Button Handler
// ---------------------------------------------------------------------------

function rancherfleet_Rollback(array $params)
{
    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        RancherFleet\Logger::info("Rollback: initiated for {$namespace}");

        // Roll back all possible resources - safe even if they don't exist
        rancherfleet_doRollback($params, array(
            'namespace_created' => true,
            'branch_created'    => true,
            'gitrepo_created'   => true,
        ));

        return "Success: Rollback complete for '{$namespace}'. All resources removed.";
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("Rollback FAILED:
" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Phase A: Verify Termination Button Handler
// ---------------------------------------------------------------------------

function rancherfleet_VerifyTermination(array $params)
{
    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        $remaining = rancherfleet_checkTermination($params, $namespace);

        if (empty($remaining)) {
            return "Success: All resources for '{$namespace}' have been fully removed.";
        }

        return 'Warning: Some resources still exist - ' . implode('; ', $remaining);

    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("VerifyTermination FAILED:
" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Phase D: Usage Metering
// ---------------------------------------------------------------------------

/**
 * Queries pod resource usage for a namespace and records it as a
 * WHMCS usage metric. Uses the Kubernetes metrics-server API.
 * Falls back gracefully if metrics-server is not available.
 *
 * @return array  array('cpu' => '125m', 'memory' => '256Mi', 'pods' => 3)
 */
function rancherfleet_doCollectUsage(array $params, $namespace)
{
    try {
        list($rancher) = rancherfleet_buildClients($params);

        // Get pod list
        $pods = $rancher->listPods($namespace);
        $podCount = count($pods);

        // Try metrics-server for CPU/memory
        $cpuTotal    = 0;
        $memoryTotal = 0;
        $metricsAvailable = false;

        try {
            $metrics = $rancher->getPodMetrics($namespace);
            foreach ($metrics as $pm) {
                foreach ($pm as $container) {
                    $cpuVal = isset($container['cpu']) ? $container['cpu'] : '0';
                    $memVal = isset($container['memory']) ? $container['memory'] : '0';
                    $cpuTotal    += rancherfleet_parseCpu($cpuVal);
                    $memoryTotal += rancherfleet_parseMemory($memVal);
                }
            }
            $metricsAvailable = true;
        } catch (\Exception $e) {
            RancherFleet\Logger::info("collectUsage: metrics-server not available - " . $e->getMessage());
        }

        $usage = array(
            'pods'              => $podCount,
            'cpu_millicores'    => $cpuTotal,
            'memory_mib'        => round($memoryTotal / 1048576, 1),
            'metrics_available' => $metricsAvailable,
            'recorded_at'       => date('Y-m-d H:i:s'),
        );

        // Store in WHMCS service notes as usage snapshot
        $serviceId = (int)$params['serviceid'];
        $key       = 'rf_usage_' . $serviceId;
        $value     = $key . '|' . json_encode($usage);

        try {
            $exists = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)->where('fieldid', 0)
                ->where('value', 'like', $key . '|%')->exists();
            if ($exists) {
                \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                    ->where('relid', $serviceId)->where('fieldid', 0)
                    ->where('value', 'like', $key . '|%')
                    ->update(array('value' => $value));
            } else {
                \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                    ->insert(array('fieldid' => 0, 'relid' => $serviceId, 'value' => $value));
            }
        } catch (\Exception $e) {
            RancherFleet\Logger::error("collectUsage: DB error: " . $e->getMessage());
        }

        RancherFleet\Logger::info("collectUsage: {$namespace} pods={$podCount} cpu={$cpuTotal}m mem=" . round($memoryTotal/1048576,1) . "Mi");
        return $usage;

    } catch (\Exception $e) {
        RancherFleet\Logger::error("collectUsage FAILED: " . $e->getMessage());
        return array('error' => $e->getMessage());
    }
}

/**
 * Parses a Kubernetes CPU string to millicores integer.
 * e.g. "125m" -> 125, "1" -> 1000, "0.5" -> 500
 */
function rancherfleet_parseCpu($cpuStr)
{
    if (substr($cpuStr, -1) === 'm') {
        return (int)rtrim($cpuStr, 'm');
    }
    if (substr($cpuStr, -1) === 'n') {
        return (int)round((int)rtrim($cpuStr, 'n') / 1000000);
    }
    return (int)((float)$cpuStr * 1000);
}

/**
 * Parses a Kubernetes memory string to bytes integer.
 * e.g. "256Mi" -> 268435456, "1Gi" -> 1073741824, "512Ki" -> 524288
 */
function rancherfleet_parseMemory($memStr)
{
    $units = array('Ki' => 1024, 'Mi' => 1048576, 'Gi' => 1073741824,
                   'K'  => 1000, 'M'  => 1000000,  'G'  => 1000000000);
    foreach ($units as $suffix => $multiplier) {
        if (substr($memStr, -strlen($suffix)) === $suffix) {
            return (int)rtrim($memStr, $suffix) * $multiplier;
        }
    }
    return (int)$memStr;
}

/**
 * Reads the last recorded usage snapshot for a service.
 */
function rancherfleet_getLastUsage(array $params)
{
    $serviceId = (int)$params['serviceid'];
    $key       = 'rf_usage_' . $serviceId;

    try {
        $row = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceId)->where('fieldid', 0)
            ->where('value', 'like', $key . '|%')->value('value');

        if ($row) {
            $json = substr($row, strlen($key) + 1);
            return json_decode($json, true) ?: array();
        }
    } catch (\Exception $e) {
        // ignore
    }
    return array();
}

// ---------------------------------------------------------------------------
// Phase D: Resource limits injection into bootstrap YAML
// ---------------------------------------------------------------------------

/**
 * Injects per-client container resource requests into YAML content.
 * Replaces existing resources block or appends one to each container spec.
 *
 * @param  string $content   Raw YAML file content
 * @param  array  $limits    Keys: cpu, memory
 * @return string            Modified YAML content
 */
function rancherfleet_injectResourceLimits($content, $limits)
{
    if (empty($limits)) {
        return $content;
    }

    // Support both old single-value keys (cpu/memory) and new split keys
    // (cpu_request, cpu_limit, memory_request, memory_limit)
    $cpuReq = isset($limits['cpu_request'])    ? $limits['cpu_request']
            : (isset($limits['cpu'])    ? $limits['cpu']    : null);
    $cpuLim = isset($limits['cpu_limit'])      ? $limits['cpu_limit']
            : (isset($limits['cpu'])    ? $limits['cpu']    : null);
    $memReq = isset($limits['memory_request']) ? $limits['memory_request']
            : (isset($limits['memory']) ? $limits['memory'] : null);
    $memLim = isset($limits['memory_limit'])   ? $limits['memory_limit']
            : (isset($limits['memory']) ? $limits['memory'] : null);

    if (!$cpuReq && !$memReq) {
        return $content;
    }

    // Build the resources block — requests and limits are separate values
    $resourcesBlock  = "          resources:\n";
    $resourcesBlock .= "            requests:\n";
    if ($cpuReq) $resourcesBlock .= "              cpu: '" . $cpuReq . "'\n";
    if ($memReq) $resourcesBlock .= "              memory: '" . $memReq . "'\n";
    $resourcesBlock .= "            limits:\n";
    if ($cpuLim) $resourcesBlock .= "              cpu: '" . $cpuLim . "'\n";
    if ($memLim) $resourcesBlock .= "              memory: '" . $memLim . "'\n";

    // Replace existing resources: block or inject after image: line
    if (preg_match('/^(\s+resources\s*:.*?)(?=^\s+\w|\Z)/ms', $content)) {
        $content = preg_replace(
            '/^(\s+resources\s*:.*?)(?=^\s+\w|\Z)/ms',
            $resourcesBlock,
            $content
        );
    } else {
        $content = preg_replace(
            '/(^\s+image\s*:.*$)/m',
            '$1\n' . $resourcesBlock,
            $content,
            1
        );
    }

    return $content;
}

// ---------------------------------------------------------------------------
// Phase D: Button Handlers
// ---------------------------------------------------------------------------

function rancherfleet_CollectUsage(array $params)
{
    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        $usage = rancherfleet_doCollectUsage($params, $namespace);

        if (isset($usage['error'])) {
            return 'Error collecting usage: ' . $usage['error'];
        }

        rancherfleet_logHistory($params, 'Usage Collected',
            "pods={$usage['pods']} cpu={$usage['cpu_millicores']}m mem={$usage['memory_mib']}Mi");

        $metricsNote = $usage['metrics_available'] ? '' : ' (metrics-server not available - pod count only)';
        return "Success: Usage recorded for '{$namespace}'{$metricsNote} - "
             . "Pods: {$usage['pods']}, CPU: {$usage['cpu_millicores']}m, Memory: {$usage['memory_mib']}Mi";

    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("CollectUsage FAILED:
" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_ExecuteGraceSuspend(array $params)
{
    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $scheduled = rancherfleet_getScheduledGraceSuspend($params);

        if ($scheduled === null) {
            return "No grace period suspension scheduled for '{$namespace}'.";
        }

        if (time() < $scheduled) {
            $remaining = ceil(($scheduled - time()) / 3600);
            return "Grace period not yet elapsed - {$remaining}h remaining until " . date('Y-m-d H:i:s', $scheduled);
        }

        list($rancher, $github) = rancherfleet_buildClients($params);
        $github->setReplicas($namespace, 0);
        rancherfleet_clearGraceSuspend($params);
        rancherfleet_logHistory($params, 'Suspended', 'grace period elapsed - replicas set to 0');
        RancherFleet\Logger::info("ExecuteGraceSuspend: executed for {$namespace}");

        return "Success: Grace period elapsed - replicas set to 0 for '{$namespace}'.";

    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("ExecuteGraceSuspend FAILED:
" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Phase E: Dry Run Button Handler
// ---------------------------------------------------------------------------

function rancherfleet_DryRunProvision(array $params)
{
    try {
        $orderNum        = rancherfleet_getOrderNumber($params);
        $namespace       = 'whmcs-client-' . $orderNum;
        $repoPath        = 'whmcs-client-' . $orderNum;
        $containerLimits = rancherfleet_getContainerLimits($params);
        $customImage     = rancherfleet_getCustomImage($params);
        $graceHours      = rancherfleet_getGraceHours($params);

        $report = array();
        $report[] = "DRY RUN SIMULATION for '{$namespace}'";
        $report[] = "---";

        // Phase 1: Connection
        try {
            list($rancher) = rancherfleet_buildClients($params);
            $clusterName = $rancher->testConnection();
            $report[] = "Phase 1 - Connection: PASS (cluster={$clusterName})";
        } catch (Exception $e) {
            $report[] = "Phase 1 - Connection: FAIL - " . $e->getMessage();
        }

        // Phase 2: Namespace check
        try {
            list($rancher) = rancherfleet_buildClients($params);
            $exists = $rancher->namespaceExists($namespace);
            $report[] = "Phase 2 - Namespace: would " . ($exists ? "skip (already exists)" : "CREATE '{$namespace}'");
            if (!empty($containerLimits)) {
                $report[] = "  + Pod resources: " . json_encode($containerLimits);
            }
        } catch (Exception $e) {
            $report[] = "Phase 2 - Namespace: FAIL - " . $e->getMessage();
        }

        // Phase 3: GitHub check
        try {
            list($rancher, $github) = rancherfleet_buildClients($params);
            $branchExists = $github->branchExists($namespace);
            $report[] = "Phase 3 - GitHub: would " . ($branchExists ? "skip (branch exists)" : "CREATE branch '{$namespace}' from template");
            if ($customImage) {
                $report[] = "  + Custom image: {$customImage}";
            }
            if (!empty($containerLimits)) {
                $report[] = "  + Container limits: " . json_encode($containerLimits);
            }
        } catch (Exception $e) {
            $report[] = "Phase 3 - GitHub: FAIL - " . $e->getMessage();
        }

        // Phase 4: Fleet GitRepo check
        try {
            list($rancher, $github, $fleet) = rancherfleet_buildClients($params);
            $gitRepo = $fleet->getGitRepo($namespace);
            $report[] = "Phase 4 - Fleet GitRepo: would " . (!empty($gitRepo) ? "skip (already exists)" : "CREATE 'gitrepo-{$namespace}'");
        } catch (Exception $e) {
            $report[] = "Phase 4 - Fleet GitRepo: FAIL - " . $e->getMessage();
        }

        // Suspend config
        $report[] = "---";
        $report[] = "Suspend grace period: " . ($graceHours > 0 ? "{$graceHours}h" : "immediate");

        // Template validation
        try {
            list($rancher, $github) = rancherfleet_buildClients($params);
            $errors = $github->validateTemplate();
            if (empty($errors)) {
                $report[] = "Template validation: PASS";
            } else {
                $report[] = "Template validation: FAIL - " . implode('; ', $errors);
            }
        } catch (Exception $e) {
            $report[] = "Template validation: FAIL - " . $e->getMessage();
        }

        $result = implode("
", $report);
        RancherFleet\Logger::info("DryRun: " . str_replace("
", " | ", $result));
        rancherfleet_logHistory($params, 'Dry Run Executed', 'see activity log for details');

        return $result;

    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("DryRunProvision FAILED:
" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Phase E: Clear Retry Queue Button Handler
// ---------------------------------------------------------------------------

function rancherfleet_ClearRetryQueue(array $params)
{
    $serviceId = (int)$params['serviceid'];
    $orderNum  = rancherfleet_getOrderNumber($params);
    $namespace = 'whmcs-client-' . $orderNum;

    RancherFleet\RetryQueue::clearAll($serviceId);
    rancherfleet_logHistory($params, 'Retry Queue Cleared', '');
    RancherFleet\Logger::info("ClearRetryQueue: cleared for service={$serviceId}");

    return "Success: Retry queue cleared for '{$namespace}'.";
}

/**
 * Pushes the backup CronJob manifest to an existing client's Git branch
 * and creates the DB admin Secret in their namespace.
 * Used for clients provisioned before the backup feature was added.
 */
function rancherfleet_PushBackupCronJob(array $params)
{
    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        list($rancher, $github) = rancherfleet_buildClients($params);

        // Read the backup CronJob manifest template
        $templatePath = __DIR__ . '/backup-cronjob.yaml';
        if (!file_exists($templatePath)) {
            return 'Error: backup-cronjob.yaml not found in module directory. Upload it alongside rancherfleet.php.';
        }

        $content = file_get_contents($templatePath);

        // Substitute 0000 with orderNum (same mechanism as bootstrapClientFolder)
        $content = str_replace('0000', $orderNum, $content);

        // Push to the client's branch
        $clientBranch = $github->clientBranch($namespace);
        $github->writeFileToBranch('backup-cronjob.yaml', $content, $clientBranch,
            "chore: add backup CronJob for {$namespace}");

        // Create the DB admin Secret in the namespace
        rancherfleet_createDbAdminSecret($params, $rancher, $namespace, $orderNum);

        rancherfleet_logHistory($params, 'Backup CronJob Pushed', $namespace);
        RancherFleet\Logger::info("PushBackupCronJob: SUCCESS for {$namespace}");

        return "Success: Backup CronJob pushed to branch '{$clientBranch}' and DB admin Secret created in '{$namespace}'. Fleet will reconcile within ~15s.";

    } catch (\Exception $e) {
        RancherFleet\Logger::error("PushBackupCronJob FAILED: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Pushes the backup sidecar container into the Odoo Deployment.
 * Replaces CronJob-based backups with a sidecar that runs dcron inline.
 *
 * Reads odoo.yml from the client branch, injects the backup sidecar container
 * into the spec.template.spec.containers array, adds necessary volumes, and
 * writes it back.
 */
function rancherfleet_PushBackupSidecar(array $params)
{
    $orderNum  = null;
    $namespace = null;

    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $serviceId = (int)$params['serviceid'];

        list($rancher, $github) = rancherfleet_buildClients($params);

        $clientBranch = $github->clientBranch($namespace);

        // Read odoo.yml from the client branch
        try {
            $odooYaml = $github->getClientFileContent($namespace, 'odoo.yml');
            if (!$odooYaml) {
                return "Error: odoo.yml not found on client branch '{$clientBranch}'.";
            }
        } catch (\Exception $readEx) {
            throw new \Exception("Failed to read odoo.yml from GitHub: " . $readEx->getMessage());
        }

        RancherFleet\Logger::info("PushBackupSidecar: read odoo.yml for {$namespace}");

        // Inject the backup sidecar container and volumes
        try {
            $updatedYaml = rancherfleet_injectBackupSidecar($odooYaml, $orderNum);
        } catch (\Exception $injectEx) {
            throw new \Exception("Failed to inject backup sidecar: " . $injectEx->getMessage());
        }

        if (!$updatedYaml) {
            throw new \Exception("injectBackupSidecar returned empty YAML");
        }

        if ($updatedYaml === $odooYaml) {
            RancherFleet\Logger::info("PushBackupSidecar: sidecar already present, no changes needed");
            return "No changes: backup sidecar already injected.";
        }

        RancherFleet\Logger::info("PushBackupSidecar: sidecar container injected for {$namespace}");

        // Write back to the client branch
        try {
            $github->writeFileToBranch('odoo.yml', $updatedYaml, $clientBranch,
                "chore: inject backup sidecar container for {$namespace}");
        } catch (\Exception $writeEx) {
            throw new \Exception("Failed to write odoo.yml to GitHub: " . $writeEx->getMessage());
        }

        RancherFleet\Logger::info("PushBackupSidecar: odoo.yml written to branch for {$namespace}");

        // Create the DB admin Secret in the namespace (ensures rfm-db-admin and rfm-webhook Secrets exist)
        try {
            rancherfleet_createDbAdminSecret($params, $rancher, $namespace, $orderNum);
        } catch (\Exception $secretEx) {
            throw new \Exception("Failed to create DB admin secret: " . $secretEx->getMessage());
        }

        RancherFleet\Logger::info("PushBackupSidecar: DB admin secret updated for {$namespace}");

        try {
            rancherfleet_logHistory($params, 'Backup Sidecar Injected', $namespace);
        } catch (\Exception $historyEx) {
            // History logging is non-critical; log but don't fail
            RancherFleet\Logger::info("PushBackupSidecar: failed to log history: " . $historyEx->getMessage());
        }

        RancherFleet\Logger::info("PushBackupSidecar: SUCCESS for {$namespace}");
        return "Success: Backup sidecar injected into '{$clientBranch}' odoo.yml. DB admin Secret updated. Fleet will auto-sync within ~15s.";

    } catch (\Exception $e) {
        $nsDisplay = $namespace ? " [{$namespace}]" : '';
        $msg = "PushBackupSidecar FAILED{$nsDisplay}: " . $e->getMessage();
        RancherFleet\Logger::error($msg);
        RancherFleet\Logger::error("Stack trace: " . $e->getTraceAsString());
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_PatchBackupStorage(array $params)
{
    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        list($rancher, $github) = rancherfleet_buildClients($params);

        RancherFleet\Logger::info("PatchBackupStorage: patching instance {$namespace}");

        // Read odoo.yml from the client branch
        try {
            $odooYaml = $github->getClientFileContent($namespace, 'odoo.yml');
            if (!$odooYaml) {
                throw new \Exception("odoo.yml not found on client branch");
            }
        } catch (\Exception $readEx) {
            throw new \Exception("Failed to read odoo.yml: " . $readEx->getMessage());
        }

        RancherFleet\Logger::info("PatchBackupStorage: read odoo.yml for {$namespace}, size=" . strlen((string)$odooYaml) . " bytes");

        // Check if this instance needs patching: detect old PVC mount with subPath
        $detectString = "mountPath: /backups\n            subPath: backups";
        $needsPatching = strpos($odooYaml, $detectString) !== false;
        RancherFleet\Logger::info("PatchBackupStorage: detection for {$namespace}: needsPatching=" . ($needsPatching ? 'true' : 'false'));

        if (!$needsPatching) {
            RancherFleet\Logger::info("PatchBackupStorage: {$namespace} does not need patching (old mount not found)");
            return "Instance already uses NFS backup storage. No patch needed.";
        }

        $updated = $odooYaml;

        // Step 1: Replace old PVC volumeMount with NFS volumeMount
        $oldMount = "          - name: odoo-data\n            mountPath: /backups\n            subPath: backups\n";
        $newMount = "          - name: nfs-backups\n            mountPath: /backups\n";
        $updated = str_replace($oldMount, $newMount, $updated);
        RancherFleet\Logger::info("PatchBackupStorage: replaced volumeMount (PVC→NFS) in {$namespace}");

        // Step 2: Add NFS volume if not present
        if (strpos($updated, 'name: nfs-backups') === false) {
            $nfsVolume = "      - name: nfs-backups\n        nfs:\n          server: 162.35.166.55\n          path: /export/share1\n";
            $updated = str_replace("      - name: rfm-db-admin", $nfsVolume . "      - name: rfm-db-admin", $updated);
            RancherFleet\Logger::info("PatchBackupStorage: added nfs-backups volume to {$namespace}");
        } else {
            RancherFleet\Logger::info("PatchBackupStorage: nfs-backups volume already present in {$namespace}");
        }

        // Step 3: Update BACKUP_DIR in the backup script to use odoo-{ORDER_NUM} directory
        $oldBackupDir = "          BACKUP_DIR=/backups";
        $newBackupDir = "          BACKUP_DIR=\"/backups/odoo-${ORDER_NUM}\"";
        $updated = str_replace($oldBackupDir, $newBackupDir, $updated);
        RancherFleet\Logger::info("PatchBackupStorage: updated BACKUP_DIR in {$namespace}");

        // Verify changes were made
        if ($updated === $odooYaml) {
            RancherFleet\Logger::error("PatchBackupStorage: changes failed to apply to {$namespace}");
            throw new \Exception("Failed to apply backup storage patch");
        }

        RancherFleet\Logger::info("PatchBackupStorage: changes applied to {$namespace}, size=" . strlen($updated) . " bytes");

        // Write back to branch
        try {
            RancherFleet\Logger::info("PatchBackupStorage: writing patched odoo.yml to {$namespace}");
            $github->writeFileToBranch(
                'odoo.yml',
                $updated,
                $namespace,
                'chore: patch backup storage to use NFS'
            );
            RancherFleet\Logger::info("PatchBackupStorage: writeFileToBranch completed for {$namespace}");
        } catch (\Exception $writeEx) {
            throw new \Exception("Failed to write patched odoo.yml: " . $writeEx->getMessage());
        }

        RancherFleet\Logger::info("PatchBackupStorage: SUCCESS - patched {$namespace}");
        return "Success: Backup storage patched to use NFS. Fleet will auto-sync within ~15s.";

    } catch (\Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("PatchBackupStorage FAILED: " . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Injects the backup sidecar container and necessary volumes into odoo.yml YAML content.
 *
 * The sidecar:
 * - Installs curl and dcron
 * - Writes a backup script to /backup.sh
 * - Schedules it to run at 3am UTC daily via crontab
 * - Creates pre-compressed backups and rotates 3-day retention
 * - POSTs manifest.json to the WHMCS webhook
 *
 * Volumes mounted:
 * - odoo-data (PVC filestore + backups subPaths)
 * - rfm-db-admin (Secret with DB credentials)
 * - rfm-webhook-config (Secret with webhook URL/credentials)
 *
 * @param  string $yamlContent  Current odoo.yml YAML
 * @param  string $orderNum     Order/client number for substitution
 * @return string               Updated YAML content
 */
function rancherfleet_injectBackupSidecar($yamlContent, $orderNum)
{
    // Step 0: Remove any existing NFS CronJob (old backup approach)
    $cronJobName = 'odoo-' . $orderNum . '-backup';
    $cronJobPattern = '/^---\n.*?kind:\s*CronJob\s*\n.*?name:\s*' . preg_quote($cronJobName, '/') . '\s*\n.*?(?=^---|$)/ms';
    $yamlContent = preg_replace($cronJobPattern, '', $yamlContent);

    // Clean up any double document separators left behind
    $yamlContent = preg_replace('/\n---\n---/', "\n---", $yamlContent);
    $yamlContent = preg_replace('/^---\n---/', "---", $yamlContent);

    RancherFleet\Logger::info("injectBackupSidecar: removed old NFS CronJob '{$cronJobName}' if present");

    // Step 1: Find the Deployment resource
    if (!preg_match('/kind:\s*Deployment/i', $yamlContent, $m)) {
        throw new \Exception("No Deployment resource found in odoo.yml");
    }
    RancherFleet\Logger::info("injectBackupSidecar: found Deployment resource");

    // Step 2: Check if backup sidecar already exists in Deployment
    if (preg_match('/kind:\s*Deployment.*?- name:\s+backup\s*\n/is', $yamlContent)) {
        RancherFleet\Logger::info("injectBackupSidecar: backup sidecar already present in Deployment");
        return $yamlContent;
    }
    RancherFleet\Logger::info("injectBackupSidecar: sidecar not found, proceeding with injection");

    // Step 3: Find Deployment's restartPolicy and insert sidecar container BEFORE it
    // Pattern: within Deployment (kind: Deployment ... until next "kind:" or end of file),
    // find "      restartPolicy:" at 6-space indent and insert sidecar before it
    $sidecarContainer = '      - name: backup
        image: postgres:16-alpine
        command: [sh, -c]
        args:
        - |
          apk add --no-cache curl dcron
          cat > /backup.sh << \'SCRIPT\'
          #!/bin/sh
          set -e
          DATE=$(date +%Y-%m-%d)
          ORDER_NUM=$(cat /etc/rfm-webhook/service_id 2>/dev/null || echo "' . $orderNum . '")
          DB_NAME="odoo-${ORDER_NUM}"
          BACKUP_DIR="/backups/odoo-${ORDER_NUM}"
          mkdir -p "$BACKUP_DIR"
          pg_dump -Fc -d "$DB_NAME" \
            -h postgres16.default.svc.cluster.local \
            -U $(cat /etc/rfm-db/username) \
            -f "$BACKUP_DIR/db-${ORDER_NUM}-${DATE}.dump"
          tar -czf "$BACKUP_DIR/filestore-${ORDER_NUM}-${DATE}.tar.gz" \
            -C /var/lib/odoo . 2>/dev/null || true
          find "$BACKUP_DIR" -maxdepth 1 \
            \( -name "db-${ORDER_NUM}-*.dump" \
            -o -name "filestore-${ORDER_NUM}-*.tar.gz" \) \
            -mtime +3 -delete
          MANIFEST="$BACKUP_DIR/manifest.json"
          printf \'[\n\' > "$MANIFEST"
          FIRST=1
          for FILE in $(ls -t "$BACKUP_DIR"/db-${ORDER_NUM}-*.dump \
            "$BACKUP_DIR"/filestore-${ORDER_NUM}-*.tar.gz 2>/dev/null); do
            FNAME=$(basename "$FILE")
            FSIZE=$(stat -c%s "$FILE" 2>/dev/null || echo 0)
            FMTIME=$(stat -c%Y "$FILE" 2>/dev/null || echo 0)
            TYPE="db"
            echo "$FNAME" | grep -q "^filestore-" && TYPE="filestore"
            if [ "$FIRST" = "0" ]; then printf \',\n\' >> "$MANIFEST"; fi
            printf \'  {"name":"%s","size":%s,"mtime":%s,"type":"%s"}\' \
              "$FNAME" "$FSIZE" "$FMTIME" "$TYPE" >> "$MANIFEST"
            FIRST=0
          done
          printf \']\n\' >> "$MANIFEST"
          URL=$(cat /etc/rfm-webhook/url 2>/dev/null || echo "")
          SECRET=$(cat /etc/rfm-webhook/secret 2>/dev/null || echo "")
          SID=$(cat /etc/rfm-webhook/service_id 2>/dev/null || echo "")
          if [ -n "$URL" ] && [ -n "$SECRET" ] && [ -n "$SID" ]; then
            PAYLOAD=$(printf \
              \'{"service_id":%s,"order_num":"%s","secret":"%s","files":%s}\' \
              "$SID" "$ORDER_NUM" "$SECRET" "$(cat $MANIFEST)")
            curl -sf -X POST -H "Content-Type: application/json" \
              -d "$PAYLOAD" "$URL" || true
          fi
          SCRIPT
          chmod +x /backup.sh
          echo "0 3 * * * /backup.sh >> /var/log/backup.log 2>&1" | crontab -
          crond -f -l 2
        env:
        - name: PGPASSWORD
          valueFrom:
            secretKeyRef:
              name: rfm-db-admin-' . $orderNum . '
              key: password
        volumeMounts:
        - mountPath: /var/lib/odoo
          name: odoo-data
          subPath: odoo
        - mountPath: /backups
          name: nfs-backups
        - mountPath: /etc/rfm-db
          name: rfm-db-admin
          readOnly: true
        - mountPath: /etc/rfm-webhook
          name: rfm-webhook-config
          readOnly: true';

    // Find Deployment block and insert sidecar before restartPolicy
    $pattern = '/(kind:\s*Deployment.*?)(^\s{6}restartPolicy:)/ms';
    $newContent = preg_replace($pattern, '${1}' . $sidecarContainer . "\n" . '${2}', $yamlContent);
    if (!$newContent) {
        throw new \Exception("Failed to inject sidecar container before restartPolicy");
    }
    $yamlContent = $newContent;
    RancherFleet\Logger::info("injectBackupSidecar: sidecar container injected before restartPolicy");

    // Step 4: Add volumes ONLY within Deployment if not already present
    // Look for Deployment's volumes: section specifically (after the Deployment kind: line)
    // and insert before "      - name: config" which is always the first volume in the template
    $nfsVolume = '      - name: nfs-backups
        nfs:
          server: 162.35.166.55
          path: /export/share1';
    $dbVolume = '      - name: rfm-db-admin
        secret:
          secretName: rfm-db-admin-' . $orderNum;
    $webhookVolume = '      - name: rfm-webhook-config
        secret:
          secretName: rfm-webhook-' . $orderNum;

    // Check if volumes already exist (look for them anywhere in Deployment)
    $deploymentHasVolumes = preg_match('/kind:\s*Deployment.*?rfm-db-admin/is', $yamlContent);
    $deploymentHasNfsVolume = preg_match('/kind:\s*Deployment.*?nfs-backups/is', $yamlContent);

    if (!$deploymentHasVolumes || !$deploymentHasNfsVolume) {
        // Find Deployment block's volumes section and insert before "      - name: config"
        // Pattern: start from "kind: Deployment", find "volumes:", then "- name: config"
        $volumePattern = '/(kind:\s*Deployment.*?)(^\s{6}volumes:\s*\n)(^\s{6}- name: config)/ms';
        $volumeInsert = (!$deploymentHasNfsVolume ? $nfsVolume . "\n" : '')
                       . (!$deploymentHasVolumes ? $dbVolume . "\n" . $webhookVolume . "\n" : '');
        $newContent = preg_replace(
            $volumePattern,
            '${1}${2}' . $volumeInsert . '${3}',
            $yamlContent
        );
        if (!$newContent) {
            throw new \Exception("Failed to inject backup volumes into Deployment volumes section");
        }
        $yamlContent = $newContent;
        RancherFleet\Logger::info("injectBackupSidecar: backup volumes (NFS + secrets) injected into Deployment");
    } else {
        RancherFleet\Logger::info("injectBackupSidecar: backup volumes already present in Deployment");
    }

    // Step 5: Atomic validation — verify sidecar and all volumes are now present
    if (!preg_match('/kind:\s*Deployment.*?- name:\s+backup\s*\n/is', $yamlContent)) {
        throw new \Exception("Atomic validation failed: backup sidecar container not found after injection");
    }
    if (!preg_match('/kind:\s*Deployment.*?- name:\s+nfs-backups\s*\n/is', $yamlContent)) {
        throw new \Exception("Atomic validation failed: nfs-backups volume not found after injection");
    }
    if (!preg_match('/kind:\s*Deployment.*?- name:\s+rfm-db-admin\s*\n/is', $yamlContent)) {
        throw new \Exception("Atomic validation failed: rfm-db-admin volume not found after injection");
    }
    if (!preg_match('/kind:\s*Deployment.*?- name:\s+rfm-webhook-config\s*\n/is', $yamlContent)) {
        throw new \Exception("Atomic validation failed: rfm-webhook-config volume not found after injection");
    }
    if (!preg_match('/mountPath:\s+\/backups\s*\n\s+name:\s+nfs-backups/is', $yamlContent)) {
        throw new \Exception("Atomic validation failed: sidecar volumeMount for nfs-backups not found after injection");
    }

    RancherFleet\Logger::info("injectBackupSidecar: atomic validation passed - sidecar and all volumes present");
    return $yamlContent;
}

// ---------------------------------------------------------------------------
// Phase F: Patch an existing client branch with the latest template
// improvements, while preserving whatever Postgres/Odoo version and PVC
// storage size that client is already running in production.
// ---------------------------------------------------------------------------

/**
 * Extracts version/size markers from a manifest file's CURRENT content on
 * a client branch, so they can be restored after applying newer template
 * content. This is what stops a template patch from silently upgrading a
 * production instance's Postgres/Odoo version or shrinking paid-for storage.
 *
 * @param  string $content  Existing file content from the client branch
 * @return array
 */
function rancherfleet_extractVersionMarkers($content)
{
    $markers = array();

    if (preg_match('/image:\s*[\'"]?postgres:([\w.\-]+)[\'"]?/i', $content, $m)) {
        $markers['pg_image_tag'] = $m[1];
    }

    if (preg_match('/postgres(\d+)\.default\.svc\.cluster\.local/i', $content, $m)) {
        $markers['pg_host_version'] = $m[1];
    }

    if (preg_match('/image:\s*[\'"]?([^\s\'"]*odoo[^\s\'":]*):([\w.\-]+)[\'"]?/i', $content, $m)) {
        $markers['odoo_image_repo'] = $m[1];
        $markers['odoo_image_tag']  = $m[2];
    }

    if (preg_match('/\bstorage:\s*(\S+Gi)/i', $content, $m)) {
        $markers['pvc_storage'] = $m[1];
    }

    return $markers;
}

/**
 * Re-applies previously captured version/size markers into new template
 * content, so every other improvement in the template is applied except
 * the Postgres version, Odoo version, and provisioned PVC storage size.
 *
 * @param  string $content  New content fetched from the template branch
 * @param  array  $markers  Output of rancherfleet_extractVersionMarkers()
 * @return string
 */
function rancherfleet_preserveVersionMarkers($content, array $markers)
{
    if (isset($markers['pg_image_tag'])) {
        $content = preg_replace(
            '/(image:\s*[\'"]?postgres:)[\w.\-]+([\'"]?)/i',
            '${1}' . $markers['pg_image_tag'] . '$2',
            $content
        );
    }

    if (isset($markers['pg_host_version'])) {
        $content = preg_replace(
            '/postgres\d+(\.default\.svc\.cluster\.local)/i',
            'postgres' . $markers['pg_host_version'] . '$1',
            $content
        );
    }

    if (isset($markers['odoo_image_repo']) && isset($markers['odoo_image_tag'])) {
        $content = preg_replace(
            '/(image:\s*[\'"]?)[^\s\'"]*odoo[^\s\'":]*:[\w.\-]+([\'"]?)/i',
            '${1}' . $markers['odoo_image_repo'] . ':' . $markers['odoo_image_tag'] . '$2',
            $content
        );
    }

    if (isset($markers['pvc_storage'])) {
        $content = preg_replace(
            '/(\bstorage:\s*)\S+Gi/i',
            '${1}' . $markers['pvc_storage'],
            $content,
            1
        );
    }

    return $content;
}

/**
 * Refreshes the webhook Secret (rfm-webhook-{orderNum}) for an existing
 * instance. Allows admins to fix an instance that has an incorrect webhook
 * URL or credentials without needing kubectl access.
 */
function rancherfleet_RefreshWebhookSecret(array $params)
{
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        rancherfleet_createDbAdminSecret($params, $rancher, $namespace, $orderNum);

        RancherFleet\Logger::info("RefreshWebhookSecret: SUCCESS - recreated Secrets for {$namespace}");
        return 'Success: rfm-db-admin and rfm-webhook Secrets have been recreated with current values.';
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("RefreshWebhookSecret FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_RemoveCustomUrl(array $params)
{
    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $serviceId = (int)$params['serviceid'];

        // Read stored custom URL from tbladdonmodules
        RancherFleet\Logger::info("RemoveCustomUrl: reading stored URL for service {$serviceId}");
        $customUrl = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_custom_url')
            ->where('setting', 'url_' . $serviceId)
            ->value('value');

        if (!$customUrl) {
            RancherFleet\Logger::info("RemoveCustomUrl: no custom URL found for service {$serviceId}");
            return 'Error: No custom URL found for this service';
        }

        RancherFleet\Logger::info("RemoveCustomUrl: found custom URL {$customUrl}");

        // Build clients
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);

        // Read odoo.yml from client branch
        RancherFleet\Logger::info("RemoveCustomUrl: reading odoo.yml from {$namespace}");
        $manifestContent = $github->getClientFileContent($namespace, 'odoo.yml');
        if (!$manifestContent) {
            RancherFleet\Logger::error("RemoveCustomUrl: failed to read odoo.yml from {$namespace}");
            return 'Error: Could not read manifest file. Please contact support.';
        }
        RancherFleet\Logger::info("RemoveCustomUrl: odoo.yml read successfully");

        // Search for the custom subdomain in the manifest
        if (strpos($manifestContent, $customUrl) === false) {
            RancherFleet\Logger::error("RemoveCustomUrl: custom subdomain '{$customUrl}' not found in odoo.yml");
            return 'Error: Custom subdomain not found in manifest. Please contact support.';
        }
        RancherFleet\Logger::info("RemoveCustomUrl: found custom subdomain '{$customUrl}' in manifest");

        // Replace all occurrences of custom subdomain with www.{orderNum}.com
        $restoreString = 'www.' . $orderNum . '.com';
        RancherFleet\Logger::info("RemoveCustomUrl: replacing '{$customUrl}' with '{$restoreString}'");
        $updated = str_replace($customUrl, $restoreString, $manifestContent);

        // Verify the replacement actually worked
        if ($updated === $manifestContent) {
            RancherFleet\Logger::error("RemoveCustomUrl: str_replace failed - content unchanged");
            return 'Error: Could not update manifest. Please contact support.';
        }

        // Verify restore string now appears in the updated content
        if (strpos($updated, $restoreString) === false) {
            RancherFleet\Logger::error("RemoveCustomUrl: restore string '{$restoreString}' not found in updated manifest");
            return 'Error: Manifest restoration verification failed. Please contact support.';
        }
        RancherFleet\Logger::info("RemoveCustomUrl: manifest updated successfully");

        // Write updated manifest back
        RancherFleet\Logger::info("RemoveCustomUrl: writing updated odoo.yml to {$namespace}");
        $github->writeFileToBranch(
            'odoo.yml',
            $updated,
            $namespace,
            'Remove custom subdomain: ' . $customUrl
        );
        RancherFleet\Logger::info("RemoveCustomUrl: odoo.yml written successfully");

        // Delete tbladdonmodules record so client area resets to form
        RancherFleet\Logger::info("RemoveCustomUrl: deleting tbladdonmodules record");
        \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_custom_url')
            ->where('setting', 'url_' . $serviceId)
            ->delete();
        RancherFleet\Logger::info("RemoveCustomUrl: tbladdonmodules record deleted");

        rancherfleet_logHistory($params, 'Custom Subdomain Removed', $customUrl);
        RancherFleet\Logger::info("RemoveCustomUrl: SUCCESS - removed {$customUrl} from {$namespace}");

        return "Success: Custom subdomain {$customUrl} has been removed. The Ingress has been updated and will sync shortly.";

    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("RemoveCustomUrl FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

function rancherfleet_PatchTemplateUpdates(array $params)
{
    try {
        list($rancher, $github) = rancherfleet_buildClients($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        if (!$github->branchExists($namespace)) {
            return "Error: client branch '{$namespace}' does not exist - nothing to patch.";
        }

        $templateFiles = $github->getTemplateFileList();
        if (empty($templateFiles)) {
            return 'Error: no files found on template branch.';
        }

        $added     = array();
        $updated   = array();
        $unchanged = array();

        foreach ($templateFiles as $item) {
            if (!isset($item['type']) || $item['type'] !== 'file') {
                continue;
            }

            $filename   = $item['name'];
            $newContent = $github->getTemplateFileContent($item['path']);
            $newContent = $github->applyNamespaceSubstitution($newContent, $namespace, $orderNum);

            $existing = $github->getClientFileContent($namespace, $filename);

            if ($existing === null) {
                // Template file the client never had (added since they were
                // provisioned) - nothing to preserve, safe to add as-is.
                $github->writeFileToBranch(
                    $filename,
                    $newContent,
                    $namespace,
                    "chore: patch {$namespace} - add new template file {$filename}"
                );
                $added[] = $filename;
                continue;
            }

            $markers = rancherfleet_extractVersionMarkers($existing);
            $patched = rancherfleet_preserveVersionMarkers($newContent, $markers);

            if ($patched === $existing) {
                $unchanged[] = $filename;
                continue;
            }

            $github->writeFileToBranch(
                $filename,
                $patched,
                $namespace,
                "chore: patch {$namespace} - apply template updates (postgres/odoo versions and storage size preserved)"
            );
            $updated[] = $filename;
        }

        $summary = "Patched '{$namespace}': "
                 . count($updated)   . ' file(s) updated, '
                 . count($added)     . ' file(s) added, '
                 . count($unchanged) . ' file(s) already current.';

        if (!empty($updated)) $summary .= ' Updated: ' . implode(', ', $updated) . '.';
        if (!empty($added))   $summary .= ' Added: '   . implode(', ', $added) . '.';

        rancherfleet_logHistory($params, 'Template Patched', $summary);
        RancherFleet\Logger::info("PatchTemplateUpdates: {$summary}");

        return "Success: {$summary} Postgres/Odoo versions and storage size were left untouched. Fleet will auto-sync the change within ~15s.";

    } catch (\Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("PatchTemplateUpdates FAILED:\n" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Phase C: Apply Quota Button Handler
// ---------------------------------------------------------------------------

function rancherfleet_ApplyQuota(array $params)
{
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        $containerLimits = rancherfleet_getContainerLimits($params);
        if (empty($containerLimits)) {
            return 'No container limits configured. Check User Count in the product Module Settings.';
        }

        // Re-bootstrap the client folder to inject updated resource limits
        $odooVersion = rancherfleet_getOdooImageVersion($params);
        $dbServer    = rancherfleet_getDbServer($params);
        $customImage = rancherfleet_getCustomImage($params);
        list(, $github) = rancherfleet_buildClients($params);
        $github->bootstrapClientFolder($namespace, $odooVersion, $dbServer, $customImage, $containerLimits);

        rancherfleet_logHistory($params, 'Pod Resources Applied', json_encode($containerLimits));
        RancherFleet\Logger::info("ApplyQuota (pod resources): SUCCESS for {$namespace}");

        $cpuReq = isset($containerLimits['cpu_request']) ? $containerLimits['cpu_request'] : '';
        $cpuLim = isset($containerLimits['cpu_limit'])   ? $containerLimits['cpu_limit']   : '';
        $memReq = isset($containerLimits['memory_request']) ? $containerLimits['memory_request'] : '';
        $memLim = isset($containerLimits['memory_limit'])   ? $containerLimits['memory_limit']   : '';

        return "Success: Pod resources updated for '{$namespace}' — CPU: {$cpuReq}/{$cpuLim}, Memory: {$memReq}/{$memLim}";
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("ApplyQuota FAILED:
" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Phase C: Inject Secrets Button Handler
// ---------------------------------------------------------------------------

function rancherfleet_InjectSecrets(array $params)
{
    try {
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;

        $injected = rancherfleet_doInjectSecrets($params, $namespace);
        if (!$injected) {
            return "No secrets found. Add product custom fields prefixed with 'secret_' (e.g. 'secret_db_password') and set their values.";
        }

        RancherFleet\Logger::info("InjectSecrets: SUCCESS for {$namespace}");
        return "Success: Secrets injected into '{$namespace}' as Kubernetes Secret 'whmcs-client-secrets'.";
    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("InjectSecrets FAILED:
" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Phase C: Get Kubeconfig Button Handler
// ---------------------------------------------------------------------------

function rancherfleet_GetKubeconfig(array $params)
{
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $cfg       = rancherfleet_getConfig($params);
        $orderNum  = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $cluster   = isset($cfg['target_cluster_id']) ? $cfg['target_cluster_id'] : 'rancher-cluster';

        // Ensure service account exists
        $rancher->createClientServiceAccount($namespace);

        // Wait briefly for token population on first call
        $token = null;
        $attempts = 0;
        while ($attempts < 3) {
            try {
                $kubeconfig = $rancher->generateKubeconfig($namespace, $cluster);
                $token = true;
                break;
            } catch (Exception $e) {
                $attempts++;
                if ($attempts < 3) sleep(2);
            }
        }

        if (!$token) {
            return 'Error: Service account token not yet available. Wait a few seconds and try again.';
        }

        // Store kubeconfig in WHMCS service notes for admin access
        $serviceId = (int)$params['serviceid'];
        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update(array('notes' => "=== KUBECONFIG (generated " . date('Y-m-d H:i:s') . ") ===

" . $kubeconfig));
        } catch (\Exception $e) {
            RancherFleet\Logger::error("GetKubeconfig: could not save to notes: " . $e->getMessage());
        }

        rancherfleet_logHistory($params, 'Kubeconfig Generated', 'saved to service notes');
        RancherFleet\Logger::info("GetKubeconfig: SUCCESS for {$namespace}");

        return "Success: Kubeconfig generated for '{$namespace}' and saved to the Service Notes field. View it under the service Notes tab.";

    } catch (Exception $e) {
        $detail = rancherfleet_exceptionDetail($e);
        RancherFleet\Logger::error("GetKubeconfig FAILED:
" . $detail);
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Phase B: Deployment History Log
// ---------------------------------------------------------------------------

/**
 * Writes a timestamped action to the per-service history log.
 * Log is stored in the WHMCS custom fields table under key "rf_history_{serviceid}".
 * Falls back to activity log only if DB write fails.
 */
function rancherfleet_logHistory(array $params, $action, $detail = '')
{
    $serviceId = (int)$params['serviceid'];
    $timestamp = date('Y-m-d H:i:s');
    $entry     = "[{$timestamp}] {$action}" . ($detail ? " - {$detail}" : '') . "
";

    try {
        $key      = 'rf_history_' . $serviceId;
        $existing = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceId)
            ->where('fieldid', 0)
            ->where('value', 'like', $key . '|%')
            ->value('value');

        if ($existing) {
            $current = substr($existing, strlen($key) + 1);
            $updated = $key . '|' . $entry . $current;
            // Keep last 50 entries - trim if too long
            $lines   = explode("
", trim(substr($updated, strlen($key) + 1)));
            if (count($lines) > 50) {
                $lines   = array_slice($lines, 0, 50);
                $updated = $key . '|' . implode("
", $lines) . "
";
            }
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)
                ->where('fieldid', 0)
                ->where('value', 'like', $key . '|%')
                ->update(array('value' => $updated));
        } else {
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')->insert(array(
                'fieldid' => 0,
                'relid'   => $serviceId,
                'value'   => $key . '|' . $entry,
            ));
        }
    } catch (\Exception $e) {
        // Silently fall back - history is non-critical
        RancherFleet\Logger::info("logHistory fallback: " . $e->getMessage());
    }

    RancherFleet\Logger::info("History [{$serviceId}]: {$action}" . ($detail ? " - {$detail}" : ''));
}

/**
 * Reads the per-service history log.
 * Returns an array of log line strings, newest first.
 */
function rancherfleet_getHistory(array $params)
{
    $serviceId = (int)$params['serviceid'];
    $key       = 'rf_history_' . $serviceId;

    try {
        $row = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceId)
            ->where('fieldid', 0)
            ->where('value', 'like', $key . '|%')
            ->value('value');

        if (!$row) {
            return array();
        }

        $raw   = substr($row, strlen($key) + 1);
        $lines = array_filter(explode("
", trim($raw)));
        return array_values($lines);

    } catch (\Exception $e) {
        return array();
    }
}

// ---------------------------------------------------------------------------
// Phase B: Fleet Sync Status
// ---------------------------------------------------------------------------

/**
 * Returns a structured Fleet sync status from the GitRepo conditions.
 *
 * @return array  keys: state (Ready|NotReady|Unknown), message, commit, timestamp
 */
function rancherfleet_getFleetSyncStatus(array $params, $namespace)
{
    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);
        $gitRepo = $fleet->getGitRepo($namespace);

        if (empty($gitRepo)) {
            return array('state' => 'Unknown', 'message' => 'GitRepo not found', 'commit' => '', 'timestamp' => '');
        }

        $conditions = isset($gitRepo['status']['conditions']) ? $gitRepo['status']['conditions'] : array();
        $commit     = isset($gitRepo['status']['commit'])     ? substr($gitRepo['status']['commit'], 0, 8) : '';
        $timestamp  = '';
        $message    = 'No sync status yet';
        $state      = 'Unknown';

        foreach ($conditions as $condition) {
            $type   = isset($condition['type'])               ? $condition['type']               : '';
            $status = isset($condition['status'])             ? $condition['status']             : '';
            $msg    = isset($condition['message'])            ? $condition['message']            : '';
            $ts     = isset($condition['lastUpdateTime'])     ? $condition['lastUpdateTime']     : '';

            if ($type === 'Ready') {
                $state     = ($status === 'True') ? 'Ready' : 'NotReady';
                $message   = $msg ?: ($state === 'Ready' ? 'Synced successfully' : 'Sync error');
                $timestamp = $ts;
                break;
            }
        }

        // Also collect bundle deployment status
        $desiredReady = isset($gitRepo['status']['desiredReadyClusters']) ? $gitRepo['status']['desiredReadyClusters'] : 0;
        $readyClusters = isset($gitRepo['status']['readyClusters'])       ? $gitRepo['status']['readyClusters']       : 0;

        return array(
            'state'          => $state,
            'message'        => $message,
            'commit'         => $commit,
            'timestamp'      => $timestamp,
            'ready_clusters' => $readyClusters,
            'desired'        => $desiredReady,
        );

    } catch (\Exception $e) {
        return array('state' => 'Error', 'message' => $e->getMessage(), 'commit' => '', 'timestamp' => '');
    }
}

// ---------------------------------------------------------------------------
// Phase B: Health Check Button Handler
// ---------------------------------------------------------------------------

function rancherfleet_HealthCheck(array $params)
{
    $orderNum  = rancherfleet_getOrderNumber($params);
    $namespace = 'whmcs-client-' . $orderNum;
    $results   = array();
    $allOk     = true;

    // 1. Connection
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $clusterName   = $rancher->testConnection();
        $results[]     = "✓ Connection: cluster '{$clusterName}' reachable";
    } catch (Exception $e) {
        $results[] = "✗ Connection: " . $e->getMessage();
        $allOk = false;
    }

    // 2. Namespace
    try {
        list($rancher) = rancherfleet_buildClients($params);
        if ($rancher->namespaceExists($namespace)) {
            $results[] = "✓ Namespace: '{$namespace}' exists";
        } else {
            $results[] = "✗ Namespace: '{$namespace}' not found";
            $allOk = false;
        }
    } catch (Exception $e) {
        $results[] = "✗ Namespace: " . $e->getMessage();
        $allOk = false;
    }

    // 3. GitHub branch
    try {
        list($rancher, $github) = rancherfleet_buildClients($params);
        if ($github->branchExists($namespace)) {
            $results[] = "✓ GitHub: branch '{$namespace}' exists";
        } else {
            $results[] = "✗ GitHub: branch '{$namespace}' not found";
            $allOk = false;
        }
    } catch (Exception $e) {
        $results[] = "✗ GitHub: " . $e->getMessage();
        $allOk = false;
    }

    // 4. Fleet GitRepo + sync status
    $syncStatus = rancherfleet_getFleetSyncStatus($params, $namespace);
    if ($syncStatus['state'] === 'Ready') {
        $results[] = "✓ Fleet GitRepo: synced (commit {$syncStatus['commit']})";
    } elseif ($syncStatus['state'] === 'NotReady') {
        $results[] = "✗ Fleet GitRepo: not ready - {$syncStatus['message']}";
        $allOk = false;
    } else {
        $results[] = "? Fleet GitRepo: {$syncStatus['message']}";
    }

    // 5. Deployments
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $deployments   = $rancher->listDeployments($namespace);
        if (empty($deployments)) {
            $results[] = "? Deployments: none found (Fleet may still be syncing)";
        } else {
            foreach ($deployments as $d) {
                $dname    = isset($d['metadata']['name'])       ? $d['metadata']['name']       : '?';
                $replicas = isset($d['spec']['replicas'])        ? (int)$d['spec']['replicas']  : 0;
                $ready    = isset($d['status']['readyReplicas']) ? (int)$d['status']['readyReplicas'] : 0;
                $icon     = ($replicas === 0) ? '-' : ($ready >= $replicas ? '✓' : '✗');
                $status   = $replicas === 0 ? 'suspended' : "{$ready}/{$replicas} ready";
                if ($ready < $replicas && $replicas > 0) $allOk = false;
                $results[] = "{$icon} Deployment '{$dname}': {$status}";
            }
        }
    } catch (Exception $e) {
        $results[] = "✗ Deployments: " . $e->getMessage();
        $allOk = false;
    }

    $summary = $allOk ? "HEALTHY" : "ISSUES FOUND";
    $report  = "Health Check - {$namespace} - {$summary}
" . implode("
", $results);

    RancherFleet\Logger::info("HealthCheck: {$summary} for {$namespace}");
    rancherfleet_logHistory($params, 'Health Check', $summary);

    return $report;
}

// ---------------------------------------------------------------------------
// Admin Panel: Phase Status Display (read-only, no buttons here)
// ---------------------------------------------------------------------------

function rancherfleet_AdminServicesTabFields(array $params)
{
    $orderNum = rancherfleet_getOrderNumber($params);
    $namespace = 'whmcs-client-' . $orderNum;
    $phases    = array();

    // Phase 1 - Connection
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $clusterName   = $rancher->testConnection();
        $phases[1] = array('label' => 'Phase 1: Connection', 'ok' => true, 'msg' => 'OK - Cluster: ' . $clusterName, 'detail' => '');
    } catch (Exception $e) {
        $phases[1] = array('label' => 'Phase 1: Connection', 'ok' => false, 'msg' => $e->getMessage(), 'detail' => rancherfleet_exceptionDetail($e));
    }

    // Phase 2 - Namespace
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $exists        = $rancher->namespaceExists($namespace);
        $phases[2] = array('label' => 'Phase 2: Namespace', 'ok' => $exists, 'msg' => $exists ? "'{$namespace}' exists" : "'{$namespace}' not found - click '2. Create Namespace' above", 'detail' => '');
    } catch (Exception $e) {
        $phases[2] = array('label' => 'Phase 2: Namespace', 'ok' => false, 'msg' => $e->getMessage(), 'detail' => rancherfleet_exceptionDetail($e));
    }

    // Phase 3 - GitHub folder
    try {
        list($rancher, $github) = rancherfleet_buildClients($params);
        $repoPath = 'whmcs-client-' . $orderNum;
        $exists   = $github->folderExists($repoPath);
        $phases[3] = array('label' => 'Phase 3: GitHub Folder', 'ok' => $exists, 'msg' => $exists ? "'{$repoPath}' exists in repo" : "'{$repoPath}' not found - click '3. Bootstrap GitHub' above", 'detail' => '');
    } catch (Exception $e) {
        $phases[3] = array('label' => 'Phase 3: GitHub Folder', 'ok' => false, 'msg' => $e->getMessage(), 'detail' => rancherfleet_exceptionDetail($e));
    }

    // Phase 4 - Fleet GitRepo + Phase B sync status
    $fleetSyncHtml = '';
    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);
        $gitRepo = $fleet->getGitRepo($namespace);
        $exists  = !empty($gitRepo);

        if ($exists) {
            $sync      = rancherfleet_getFleetSyncStatus($params, $namespace);
            $syncColor = $sync['state'] === 'Ready' ? '#27ae60' : ($sync['state'] === 'NotReady' ? '#e74c3c' : '#e67e22');
            $syncIcon  = $sync['state'] === 'Ready' ? '&#10003;' : ($sync['state'] === 'NotReady' ? '&#10007;' : '&#9679;');
            $commitStr = $sync['commit'] ? " (commit {$sync['commit']})" : '';
            $tsStr     = $sync['timestamp'] ? ' at ' . date('Y-m-d H:i', strtotime($sync['timestamp'])) : '';
            $phases[4] = array('label' => 'Phase 4: Fleet GitRepo', 'ok' => true, 'msg' => 'GitRepo exists', 'detail' => '');
            $fleetSyncHtml = "
            <table class='table table-bordered table-condensed' style='margin-top:12px;margin-bottom:0;'>
                <thead><tr><th colspan='2'>Fleet Sync Status &mdash; <code>gitrepo-{$namespace}</code></th></tr></thead>
                <tbody>
                    <tr>
                        <td style='width:120px;font-weight:bold;'>State</td>
                        <td style='color:{$syncColor};font-weight:bold;'>{$syncIcon} {$sync['state']}{$commitStr}</td>
                    </tr>
                    <tr>
                        <td style='font-weight:bold;'>Message</td>
                        <td style='font-size:11px;'>" . htmlspecialchars($sync['message']) . "</td>
                    </tr>
                    <tr>
                        <td style='font-weight:bold;'>Last Sync</td>
                        <td style='font-size:11px;color:#888;'>" . ($tsStr ?: 'N/A') . "</td>
                    </tr>
                    <tr>
                        <td style='font-weight:bold;'>Clusters</td>
                        <td style='font-size:11px;'>{$sync['ready_clusters']} / {$sync['desired']} ready</td>
                    </tr>
                </tbody>
            </table>";
        } else {
            $phases[4] = array('label' => 'Phase 4: Fleet GitRepo', 'ok' => false, 'msg' => "Not found - click '4. Create GitRepo' above", 'detail' => '');
        }
    } catch (Exception $e) {
        $phases[4] = array('label' => 'Phase 4: Fleet GitRepo', 'ok' => false, 'msg' => $e->getMessage(), 'detail' => rancherfleet_exceptionDetail($e));
    }

    // Phase 5 - Deployments (with Rancher links)
    $deploymentLinks = '';
    $deploymentList  = array();
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $cfg           = rancherfleet_getConfig($params);
        $rancherUrl    = $cfg['rancher_url'];
        $targetCluster = isset($cfg['target_cluster_id']) ? $cfg['target_cluster_id'] : 'c-m-local';
        $deployments   = $rancher->listDeployments($namespace);

        if (empty($deployments)) {
            $phases[5] = array('label' => 'Phase 5: Deployments', 'ok' => false, 'msg' => 'No deployments found yet - Fleet may still be syncing', 'detail' => '');
        } else {
            $summary = array();
            foreach ($deployments as $d) {
                $dname    = isset($d['metadata']['name'])       ? $d['metadata']['name']       : '?';
                $replicas = isset($d['spec']['replicas'])        ? $d['spec']['replicas']        : 0;
                $ready    = isset($d['status']['readyReplicas']) ? $d['status']['readyReplicas'] : 0;
                $summary[] = "{$dname}: {$ready}/{$replicas} ready";

                // Build Rancher UI deep link for this deployment
                $deployUrl = $rancherUrl . '/dashboard/c/' . $targetCluster
                           . '/explorer/apps.deployment/' . $namespace . '/' . $dname;
                $deploymentList[] = array(
                    'name'     => $dname,
                    'replicas' => $replicas,
                    'ready'    => $ready,
                    'url'      => $deployUrl,
                );
            }
            $phases[5] = array('label' => 'Phase 5: Deployments', 'ok' => true, 'msg' => implode(', ', $summary), 'detail' => '');

            // Build deployment links table
            $linkRows = '';
            foreach ($deploymentList as $dep) {
                $statusColor = ($dep['ready'] >= $dep['replicas'] && $dep['replicas'] > 0) ? '#27ae60' : '#e67e22';
                if ($dep['replicas'] === 0) $statusColor = '#95a5a6';
                $linkRows .= "
                <tr>
                    <td style='padding:6px 10px;font-weight:bold;'>" . htmlspecialchars($dep['name']) . "</td>
                    <td style='padding:6px 10px;color:{$statusColor};'>{$dep['ready']}/{$dep['replicas']} ready</td>
                    <td style='padding:6px 10px;'>
                        <a href='" . htmlspecialchars($dep['url']) . "' target='_blank'
                           style='display:inline-block;padding:3px 10px;background:#2980b9;color:#fff;border-radius:3px;font-size:11px;text-decoration:none;'>
                           Open in Rancher &rarr;
                        </a>
                    </td>
                </tr>";
            }
            $deploymentLinks = "
            <table class='table table-bordered table-condensed' style='margin-top:12px;margin-bottom:0;'>
                <thead>
                    <tr><th colspan='3'>Deployments in <code>{$namespace}</code></th></tr>
                    <tr>
                        <th style='width:40%;font-size:11px;'>Name</th>
                        <th style='width:20%;font-size:11px;'>Status</th>
                        <th style='font-size:11px;'>Rancher</th>
                    </tr>
                </thead>
                <tbody>{$linkRows}</tbody>
            </table>";
        }
    } catch (Exception $e) {
        $phases[5] = array('label' => 'Phase 5: Deployments', 'ok' => false, 'msg' => $e->getMessage(), 'detail' => rancherfleet_exceptionDetail($e));
    }

    // Build status rows
    $rows = '';
    foreach ($phases as $num => $phase) {
        $icon      = $phase['ok'] ? '&#10003;' : '&#10007;';
        $iconColor = $phase['ok'] ? '#27ae60'  : '#e74c3c';
        $msgColor  = $phase['ok'] ? '#555'     : '#c0392b';
        $detailHtml = '';

        if (!$phase['ok'] && $phase['detail'] !== '') {
            $escaped  = htmlspecialchars($phase['detail']);
            $detailId = 'rf-detail-' . $num;
            $detailHtml = "
                <br>
                <a href='#' onclick=\"
                    var el = document.getElementById('{$detailId}');
                    el.style.display = (el.style.display === 'none') ? 'block' : 'none';
                    return false;
                \" style='font-size:11px;color:#e74c3c;'>Show full error detail</a>
                <pre id='{$detailId}' style='
                    display:none;
                    margin-top:6px;
                    padding:8px;
                    background:#fff5f5;
                    border:1px solid #f5c6cb;
                    border-radius:4px;
                    font-size:11px;
                    white-space:pre-wrap;
                    word-break:break-all;
                    color:#721c24;
                    max-height:300px;
                    overflow-y:auto;
                '>{$escaped}</pre>";
        }

        $rows .= "
        <tr>
            <td style='width:24px;text-align:center;color:{$iconColor};font-size:18px;font-weight:bold;vertical-align:top;padding-top:10px;'>{$icon}</td>
            <td style='font-weight:bold;vertical-align:top;padding-top:10px;white-space:nowrap;'>{$phase['label']}</td>
            <td style='color:{$msgColor};font-size:12px;vertical-align:top;padding-top:10px;'>
                " . htmlspecialchars($phase['msg']) . "
                {$detailHtml}
            </td>
        </tr>";
    }

    // Mode indicator
    $isAuto    = rancherfleet_isAutomatic($params);
    $modeColor = $isAuto ? '#27ae60' : '#e67e22';
    $modeLabel = $isAuto
        ? '&#9679; Automatic &mdash; provisioning runs on order activation'
        : '&#9679; Manual &mdash; use phase buttons to provision';
    $modeBar = "
    <div style='margin-bottom:10px;padding:6px 10px;border-radius:4px;background:#f8f9fa;border:1px solid #dee2e6;font-size:12px;'>
        <span style='color:{$modeColor};font-weight:bold;'>{$modeLabel}</span>
        <span style='color:#888;margin-left:8px;'>(change in product Module Settings)</span>
    </div>";

    // Phase C: Resource Quota Status
    $quotaHtml = '';
    try {
        list($rancher) = rancherfleet_buildClients($params);
        $quota = $rancher->getResourceQuota($namespace);
        if (!empty($quota)) {
            $hard    = isset($quota['spec']['hard'])   ? $quota['spec']['hard']   : array();
            $used    = isset($quota['status']['used']) ? $quota['status']['used'] : array();
            $qRows   = '';
            $allKeys = array_unique(array_merge(array_keys($hard), array_keys($used)));
            foreach ($allKeys as $resource) {
                $hardVal  = isset($hard[$resource]) ? $hard[$resource] : '-';
                $usedVal  = isset($used[$resource]) ? $used[$resource] : '0';
                $qRows   .= "<tr>
                    <td style='padding:4px 10px;font-size:11px;'>" . htmlspecialchars($resource) . "</td>
                    <td style='padding:4px 10px;font-size:11px;'>" . htmlspecialchars($usedVal) . "</td>
                    <td style='padding:4px 10px;font-size:11px;'>" . htmlspecialchars($hardVal) . "</td>
                </tr>";
            }
            $quotaHtml = "
            <table class='table table-bordered table-condensed' style='margin-top:12px;margin-bottom:0;'>
                <thead>
                    <tr><th colspan='3'>Resource Quota &mdash; <code>{$namespace}</code></th></tr>
                    <tr>
                        <th style='font-size:11px;'>Resource</th>
                        <th style='font-size:11px;'>Used</th>
                        <th style='font-size:11px;'>Limit</th>
                    </tr>
                </thead>
                <tbody>{$qRows}</tbody>
            </table>";
        } else {
            $quotaHtml = "
            <p style='font-size:11px;color:#888;margin-top:8px;'>
                No ResourceQuota applied. Configure limits in product Module Settings and click 'Apply Quota'.
            </p>";
        }
    } catch (Exception $e) {
        $quotaHtml = '';
    }

    // Phase D: Usage snapshot panel
    $usageHtml = '';
    $lastUsage = rancherfleet_getLastUsage($params);
    if (!empty($lastUsage) && !isset($lastUsage['error'])) {
        $metricsNote = (isset($lastUsage['metrics_available']) && !$lastUsage['metrics_available'])
            ? "<em style='color:#e67e22;'> (metrics-server unavailable - pod count only)</em>"
            : '';
        $usageHtml = "
        <table class='table table-bordered table-condensed' style='margin-top:12px;margin-bottom:0;'>
            <thead><tr><th colspan='2'>Last Usage Snapshot &mdash; <code>{$namespace}</code> &mdash; "
            . htmlspecialchars($lastUsage['recorded_at'] ?? '') . "{$metricsNote}</th></tr></thead>
            <tbody>
                <tr><td style='font-weight:bold;width:160px;'>Pods</td><td>" . ($lastUsage['pods'] ?? '?') . "</td></tr>
                <tr><td style='font-weight:bold;'>CPU (millicores)</td><td>" . ($lastUsage['cpu_millicores'] ?? '?') . "m</td></tr>
                <tr><td style='font-weight:bold;'>Memory (MiB)</td><td>" . ($lastUsage['memory_mib'] ?? '?') . " MiB</td></tr>
            </tbody>
        </table>";
    }

    // Phase D: Grace period pending suspension indicator
    $gracePendingHtml = '';
    $scheduledTs = rancherfleet_getScheduledGraceSuspend($params);
    if ($scheduledTs !== null) {
        $elapsed  = time() >= $scheduledTs;
        $tsStr    = date('Y-m-d H:i:s', $scheduledTs);
        $color    = $elapsed ? '#e74c3c' : '#e67e22';
        $status   = $elapsed
            ? "Grace period ELAPSED at {$tsStr} - click 'Execute Grace Suspend' to apply"
            : "Scheduled for {$tsStr} - click 'Execute Grace Suspend' to apply early";
        $gracePendingHtml = "
        <div style='margin-top:12px;padding:8px 12px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;font-size:12px;'>
            <strong style='color:{$color};'>&#9679; Suspension Pending:</strong>
            <span style='margin-left:6px;'>{$status}</span>
        </div>";
    }

    // Phase E: Retry Queue Panel
    $retryQueueHtml = '';
    $retryEntries   = RancherFleet\RetryQueue::getServiceEntries((int)$params['serviceid']);
    if (!empty($retryEntries)) {
        $rRows = '';
        foreach ($retryEntries as $re) {
            $nextStr = isset($re['next_retry']) ? date('H:i:s', $re['next_retry']) : '?';
            $rRows  .= "<tr>
                <td style='padding:4px 10px;font-size:11px;font-weight:bold;'>" . htmlspecialchars($re['phase']) . "</td>
                <td style='padding:4px 10px;font-size:11px;'>{$re['attempts']}/{$re['max_attempts']}</td>
                <td style='padding:4px 10px;font-size:11px;'>{$nextStr}</td>
                <td style='padding:4px 10px;font-size:11px;color:#721c24;'>" . htmlspecialchars(substr($re['last_error'], 0, 80)) . "</td>
            </tr>";
        }
        $retryQueueHtml = "
        <table class='table table-bordered table-condensed' style='margin-top:12px;margin-bottom:0;border-color:#f5c6cb;'>
            <thead style='background:#f8d7da;'>
                <tr><th colspan='4' style='color:#721c24;'>Retry Queue</th></tr>
                <tr>
                    <th style='font-size:11px;'>Phase</th>
                    <th style='font-size:11px;'>Attempts</th>
                    <th style='font-size:11px;'>Next Retry</th>
                    <th style='font-size:11px;'>Last Error</th>
                </tr>
            </thead>
            <tbody>{$rRows}</tbody>
        </table>";
    }

    // Phase B: Deployment History Log
    $historyHtml = '';
    $historyEntries = rancherfleet_getHistory($params);
    if (!empty($historyEntries)) {
        $historyRows = '';
        foreach ($historyEntries as $entry) {
            $historyRows .= "<tr><td style='font-size:11px;font-family:monospace;padding:3px 8px;'>"
                          . htmlspecialchars($entry) . "</td></tr>";
        }
        $historyHtml = "
        <table class='table table-bordered table-condensed' style='margin-top:12px;margin-bottom:0;'>
            <thead>
                <tr><th>Deployment History &mdash; <code>{$namespace}</code></th></tr>
            </thead>
            <tbody>{$historyRows}</tbody>
        </table>";
    }

    $html = "
    {$modeBar}
    <table class='table table-bordered table-condensed' style='margin-bottom:0;'>
        <thead>
            <tr><th colspan='3'>RancherFleet Phase Status &mdash; <code>{$namespace}</code></th></tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
    </table>
    {$fleetSyncHtml}
    {$deploymentLinks}
    {$quotaHtml}
    {$usageHtml}
    {$gracePendingHtml}
    {$retryQueueHtml}
    {$historyHtml}
    <p style='font-size:11px;color:#888;margin-top:8px;'>
        Use the buttons above this panel to run each phase. Reload the page after each button click to refresh this status table.
    </p>";

    return array('Fleet Status' => $html);
}

// ---------------------------------------------------------------------------
// Client Area — Instance Status Panel
// ---------------------------------------------------------------------------

/**
 * Defines what the client sees on their product/service page.
 * Shown in the WHMCS client area under the service details.
 */
function rancherfleet_ClientArea(array $params)
{
    $orderNum  = rancherfleet_getOrderNumber($params);
    $namespace = 'whmcs-client-' . $orderNum;

    // Handle restart action
    $action  = isset($_POST['clientaction']) ? $_POST['clientaction'] : '';
    $message = '';

    if ($action === 'restart') {
        try {
            list($rancher) = rancherfleet_buildClients($params);
            $rancher->scaleAllDeployments($namespace, 0);
            sleep(2);
            $rancher->scaleAllDeployments($namespace, 1);
            rancherfleet_logHistory($params, 'Client Restart', 'requested from client area');
            $message = 'success';
        } catch (\Exception $e) {
            $message = 'error:' . $e->getMessage();
        }
    } elseif ($action === 'domain_purchase') {
        $message = rancherfleet_handleDomainPurchase($params, $namespace, $orderNum);
    } elseif ($action === 'domain_renew') {
        $message = rancherfleet_handleDomainRenewal($params);
    } elseif ($action === 'domain_reconnect') {
        $message = rancherfleet_handleDomainReconnect($params, $namespace, $orderNum);
    } elseif ($action === 'storage_upgrade') {
        $message = rancherfleet_handleStorageUpgrade($params, $namespace, $orderNum);
    } elseif ($action === 'backup_download') {
        $filename = isset($_POST['backup_file']) ? basename($_POST['backup_file']) : '';
        if ($filename) {
            $secret = isset($params['configoption22']) ? trim($params['configoption22']) : '';
            if ($secret) {
                $token = rancherfleet_generateBackupToken($secret, $orderNum, $filename);
                header('Location: ' . $token['url']);
                exit;
            }
        }
    } elseif ($action === 'backup_restore') {
        $filename = isset($_POST['backup_file']) ? basename($_POST['backup_file']) : '';
        $backupType = isset($_POST['backup_type']) ? $_POST['backup_type'] : '';
        if ($filename && $backupType) {
            $message = rancherfleet_handleBackupRestore($params, $namespace, $orderNum, $filename, $backupType);
        } else {
            $message = 'error:Invalid backup file or type';
        }
    } elseif ($action === 'custom_url_connect') {
        $message = rancherfleet_handleCustomUrlConnect($params, $namespace, $orderNum);
    } elseif ($action === 'odoo_password_reset') {
        $message = rancherfleet_handleOdooPasswordReset($params, $namespace, $orderNum);
    } elseif ($action === 'install_module') {
        $message = rancherfleet_handleModuleInstall($params, $namespace, $orderNum);
    } elseif ($action === 'request_version_upgrade') {
        $message = rancherfleet_handleUpgradeRequest($params, $namespace, $orderNum);
    } elseif ($action === 'cancel_version_upgrade') {
        $message = rancherfleet_handleCancelUpgrade($params, $orderNum);
    }

    // Build the output
    $output = rancherfleet_clientAreaHtml($params, $namespace, $message);

    return array(
        'tabOverviewReplacementTemplate' => 'clientarea.tpl',
        'templateVariables'              => array(
            'rfm_output' => $output,
        ),
    );
}

/**
 * Default TLD shortlist offered alongside whatever ResellerClub's full
 * catalog returns, ensuring the most commonly requested options always
 * appear even if the catalog call is slow or partially fails.
 */
/**
 * Default TLD shortlist offered alongside whatever the registrar's full
 * catalog returns.
 */
function rancherfleet_commonTlds()
{
    return array('com', 'net', 'ca', 'org', 'info');
}

/**
 * Reads the configured CNAME target (e.g. cowboy.webdiscode.com).
 */
function rancherfleet_getDomainCnameTarget(array $params)
{
    // See buildDomainClients() comment re: confirmed numbered positions
    // for product 126. configoption15 = Domain CNAME Target.
    $target = isset($params['configoption14']) ? trim($params['configoption14']) : '';
    return $target ?: 'cowboy.webdiscode.com';
}

/**
 * Handles a new domain purchase POST from the client area.
 *
 * @return string  'domain_success:{domain}' | 'domain_processing:{domain}' | 'domain_error:{message}'
 */
function rancherfleet_handleDomainPurchase(array $params, $namespace, $orderNum)
{
    $sld = isset($_POST['domain_sld']) ? trim($_POST['domain_sld']) : '';
    $tld = isset($_POST['domain_tld']) ? trim($_POST['domain_tld']) : '';
    if (!$sld || !$tld) {
        return 'domain_error:No domain name provided.';
    }

    $required = array('reg_firstname', 'reg_lastname', 'reg_email', 'reg_address', 'reg_city', 'reg_state', 'reg_country', 'reg_zip', 'reg_phone');
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            return 'domain_error:Please complete all registrant contact fields.';
        }
    }

    try {
        list($rp, $orderManager) = rancherfleet_buildDomainClients($params);

        $clientId = (int)$params['userid'];

        $registrant = array(
            'firstname'     => $_POST['reg_firstname'],
            'lastname'      => $_POST['reg_lastname'],
            'address1'      => $_POST['reg_address'],
            'city'          => $_POST['reg_city'],
            'stateprovince' => $_POST['reg_state'],
            'postalcode'    => $_POST['reg_zip'],
            'country'       => $_POST['reg_country'],
            'emailaddress'  => $_POST['reg_email'],
            'phone'         => $_POST['reg_phone'],
        );
        if (!empty($_POST['reg_company'])) {
            $registrant['organizationname'] = $_POST['reg_company'];
        }

        $pricing = $rp->getRegisterDomainPrices();
        $price   = $rp->extractYearOnePrice($pricing, $tld);
        if ($price === null) {
            return 'domain_error:Could not determine pricing for .' . htmlspecialchars($tld) . '. Please contact support.';
        }

        $cnameTarget = rancherfleet_getDomainCnameTarget($params);
        $backendServiceName = 'odoo-' . $orderNum;
        $backendServicePort = 8069;

        $orderData = array(
            'clientId'           => $clientId,
            'serviceId'          => (int)$params['serviceid'],
            'sld'                => $sld,
            'tld'                => $tld,
            'years'              => 1,
            'chargeAmount'       => $price,
            'chargeCurrency'     => isset($params['clientsdetails']['currency_code']) ? $params['clientsdetails']['currency_code'] : 'USD',
            'cnameTarget'        => $cnameTarget,
            'namespace'          => $namespace,
            'backendServiceName' => $backendServiceName,
            'backendServicePort' => $backendServicePort,
            'registrant'         => $registrant,
            'ip'                 => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1',
            'country'            => $_POST['reg_country'],
        );

        $result = $orderManager->placeOrder($orderData);
        $domainName = $sld . '.' . $tld;

        if ($result['status'] === 'completed') {
            rancherfleet_logHistory($params, 'Domain Purchased', $domainName);
            return 'domain_success:' . $domainName;
        }
        if ($result['status'] === 'processing') {
            rancherfleet_logHistory($params, 'Domain Purchase Processing', $domainName . ' - ' . $result['error']);
            return 'domain_processing:' . $domainName;
        }

        rancherfleet_logHistory($params, 'Domain Purchase Declined', $domainName . ' - ' . $result['error']);
        return 'domain_error:' . $result['error'];

    } catch (\Exception $e) {
        RancherFleet\Logger::error("handleDomainPurchase: " . $e->getMessage());
        return 'domain_error:' . $e->getMessage();
    }
}

/**
 * Handles a domain renewal POST from the client area.
 *
 * @return string  'domain_renewed:{domain}' | 'domain_error:{message}'
 */
function rancherfleet_handleDomainRenewal(array $params)
{
    $sld = isset($_POST['renew_sld']) ? trim($_POST['renew_sld']) : '';
    $tld = isset($_POST['renew_tld']) ? trim($_POST['renew_tld']) : '';
    if (!$sld || !$tld) {
        return 'domain_error:No domain specified for renewal.';
    }

    try {
        list($rp, $orderManager) = rancherfleet_buildDomainClients($params);

        $pricing = $rp->getRegisterDomainPrices();
        $price   = $rp->extractYearOnePrice($pricing, $tld);
        if ($price === null) {
            return 'domain_error:Could not determine renewal pricing for .' . htmlspecialchars($tld) . '.';
        }

        $result = $orderManager->renewDomain(array(
            'clientId'       => (int)$params['userid'],
            'sld'            => $sld,
            'tld'            => $tld,
            'years'          => 1,
            'chargeAmount'   => $price,
            'chargeCurrency' => isset($params['clientsdetails']['currency_code']) ? $params['clientsdetails']['currency_code'] : 'USD',
            'ip'             => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1',
            'country'        => isset($params['clientsdetails']['country']) ? $params['clientsdetails']['country'] : 'US',
        ));

        $domainName = $sld . '.' . $tld;

        if ($result['status'] === 'completed') {
            rancherfleet_logHistory($params, 'Domain Renewed', $domainName . ' - new expiry ' . $result['newExpiry']);
            return 'domain_renewed:' . $domainName;
        }

        rancherfleet_logHistory($params, 'Domain Renewal Failed', $domainName . ' - ' . $result['error']);
        return 'domain_error:' . $result['error'];

    } catch (\Exception $e) {
        RancherFleet\Logger::error("handleDomainRenewal: " . $e->getMessage());
        return 'domain_error:' . $e->getMessage();
    }
}

/**
 * Handles an "I already have a domain registered with you" reconnect
 * POST from the client area. Looks the domain up in DomainRecordStore
 * (the only source of truth, since the registrar API can't answer this)
 * and re-wires DNS to the current service/namespace if needed.
 *
 * @return string  'domain_connected:{domain}' | 'domain_not_found:{domain}' | 'domain_error:{message}'
 */
function rancherfleet_handleDomainReconnect(array $params, $namespace, $orderNum)
{
    $sld = isset($_POST['reconnect_sld']) ? trim($_POST['reconnect_sld']) : '';
    $tld = isset($_POST['reconnect_tld']) ? trim($_POST['reconnect_tld']) : '';
    if (!$sld || !$tld) {
        return 'domain_error:No domain specified.';
    }

    try {
        list(, $orderManager) = rancherfleet_buildDomainClients($params);

        $cnameTarget = rancherfleet_getDomainCnameTarget($params);
        $backendServiceName = 'odoo-' . $orderNum;
        $backendServicePort = 8069;

        $result = $orderManager->reconnectExistingDomain(
            $sld, $tld,
            (int)$params['serviceid'],
            $namespace,
            $cnameTarget,
            $backendServiceName,
            $backendServicePort
        );

        $domainName = $sld . '.' . $tld;

        if ($result['status'] === 'connected') {
            rancherfleet_logHistory($params, 'Domain Reconnected', $domainName);
            return 'domain_connected:' . $domainName;
        }
        if ($result['status'] === 'not_found') {
            return 'domain_not_found:' . $domainName;
        }

        return 'domain_error:' . $result['error'];

    } catch (\Exception $e) {
        RancherFleet\Logger::error("handleDomainReconnect: " . $e->getMessage());
        return 'domain_error:' . $e->getMessage();
    }
}

/**
 * Renders an expiry status badge for a domain record, computed entirely
 * from DomainRecordStore (no live API call — neither registrar nor
 * Cloudflare can be asked for this after the fact, per the agreed design).
 */
function rancherfleet_domainExpiryBadgeHtml(array $record)
{
    $status = RancherFleet\Domains\DomainRecordStore::getExpiryStatus($record);
    $domain = $record['sld'] . '.' . $record['tld'];

    if ($status['state'] === 'expired') {
        $daysAgo = abs($status['daysRemaining']);
        return '<div class="rfm-alert-error" style="margin-bottom:10px;">&#10007; <strong>' . htmlspecialchars($domain) . '</strong> expired ' . $daysAgo . ' day' . ($daysAgo !== 1 ? 's' : '') . ' ago. Renew now to avoid losing this domain.</div>';
    }
    if ($status['state'] === 'expiring') {
        return '<div class="rfm-alert-success" style="background:#fff3cd;border-color:#ffc107;color:#856404;margin-bottom:10px;">&#9203; <strong>' . htmlspecialchars($domain) . '</strong> expires in ' . $status['daysRemaining'] . ' day' . ($status['daysRemaining'] !== 1 ? 's' : '') . '. Consider renewing soon.</div>';
    }
    if ($status['state'] === 'active') {
        return '<div style="font-size:12px;color:#888;margin-bottom:10px;"><strong>' . htmlspecialchars($domain) . '</strong> &mdash; active, expires in ' . $status['daysRemaining'] . ' days.</div>';
    }
    return '';
}

/**
 * Renders the full domain panel: existing-domain status/expiry/renew
 * (if any domain is on record for this service), search/purchase, and
 * the "I already have a domain" reconnect field.
 */
function rancherfleet_domainPanelHtml(array $params, $namespace, $orderNum)
{
    $html = '<div class="rfm-ca-card">';
    $html .= '<h4>Domain Name</h4>';
    $html .= rancherfleet_creditBalanceHtml($params);

    // See buildDomainClients() comment re: confirmed numbered positions
    // for product 126 (re-confirmed 2026-06-16 after a module-dropdown
    // reset shifted every slot left by one position). configoption10 =
    // Store Username, configoption13 = Cloudflare Token. If this module
    // is used on a different product, or these fields are edited again,
    // re-verify via: SELECT configoption1..20 FROM tblproducts WHERE id = X;
    $configured = !empty($params['configoption10']) && !empty($params['configoption13']);

    if (!$configured) {
        $html .= '<div style="font-size:12px;color:#888;">Domain registration is not yet available for this product.</div></div>';
        return $html;
    }

    $serviceUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '');
    $serviceId  = (int)$params['serviceid'];

    // -----------------------------------------------------------------------
    // Existing domain(s) on record for this service — status/expiry/renew
    // -----------------------------------------------------------------------
    $existingDomains = RancherFleet\Domains\DomainRecordStore::getForService($serviceId);

    foreach ($existingDomains as $record) {
        $html .= rancherfleet_domainExpiryBadgeHtml($record);

        $expiryStatus = RancherFleet\Domains\DomainRecordStore::getExpiryStatus($record);
        if (in_array($expiryStatus['state'], array('expiring', 'expired'))) {
            $html .= '<form method="post" action="' . $serviceUrl . '" style="margin-bottom:14px;" onsubmit="return confirm(\'Renew ' . htmlspecialchars($record['sld'] . '.' . $record['tld']) . ' for 1 year? Your card on file will be charged.\')">';
            $html .= '<input type="hidden" name="clientaction" value="domain_renew">';
            $html .= '<input type="hidden" name="renew_sld" value="' . htmlspecialchars($record['sld']) . '">';
            $html .= '<input type="hidden" name="renew_tld" value="' . htmlspecialchars($record['tld']) . '">';
            $html .= '<button type="submit" style="background:#e67e22;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:bold;cursor:pointer;">Renew Now (1 Year)</button>';
            $html .= '</form>';
        }
    }

    // -----------------------------------------------------------------------
    // Search & purchase a new domain
    // -----------------------------------------------------------------------
    $searchTermRaw = isset($_POST['domain_search_term']) ? trim($_POST['domain_search_term']) : '';
    $searchAction  = isset($_POST['clientaction']) ? $_POST['clientaction'] : '';

    // The field only accepts the name itself (no extension) — if someone
    // types "example.com" anyway, strip everything from the first dot
    // onward so we don't end up checking "example.com.com" etc.
    $searchTerm = $searchTermRaw;
    $dotPos     = strpos($searchTerm, '.');
    if ($dotPos !== false) {
        $searchTerm = substr($searchTerm, 0, $dotPos);
    }

    $html .= '<p style="font-size:12px;color:#666;margin-bottom:10px;">Search for a new domain name. Enter just the name without an extension (e.g. <code>yourbusiness</code>, not <code>yourbusiness.com</code>) — we\'ll check it across .com, .net, .ca, .org, and .info. Once purchased, <code>www.yourdomain.com</code> will automatically be connected to this instance, and visitors to the root domain will be redirected to it.</p>';

    $html .= '<form method="post" action="' . $serviceUrl . '" style="display:flex;gap:8px;margin-bottom:14px;">';
    $html .= '<input type="hidden" name="clientaction" value="domain_search">';
    $html .= '<input type="text" name="domain_search_term" value="' . htmlspecialchars($searchTermRaw) . '" placeholder="yourbusiness (no extension)" style="flex:1;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;">';
    $html .= '<button type="submit" style="background:#2980b9;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:bold;cursor:pointer;">Search</button>';
    $html .= '</form>';

    if ($searchAction === 'domain_search' && $searchTerm) {
        try {
            list($rp) = rancherfleet_buildDomainClients($params);

            $tlds    = rancherfleet_commonTlds();
            $results = $rp->checkAvailability($searchTerm, $tlds);
            $pricing = $rp->getRegisterDomainPrices();

            $html .= '<div style="margin-top:8px;">';
            // ResellersPanel domains:check confirmed shape: a flat map of
            // bare TLD -> integer status code, e.g. {"com": 0, "net": 1}.
            // 0 = available, non-zero = taken/unavailable (confirmed by
            // testing against known-available vs. likely-taken names —
            // not documented in the API PDF).
            foreach ($results as $tld => $statusCode) {
                $tld        = trim((string)$tld, '.');
                $sld        = $searchTerm;
                $domainName = $sld . '.' . $tld;
                // ResellersPanel domains:check: 0 = available, non-zero = taken/unavailable.
                $available  = ((int)$statusCode === 0);
                $price      = $rp->extractYearOnePrice($pricing, $tld);

                $html .= '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:13px;">';
                $html .= '<span style="font-family:monospace;">' . htmlspecialchars($domainName) . '</span>';

                if ($available) {
                    $priceStr = $price !== null ? '$' . number_format($price, 2) . '/yr' : '';
                    $html .= '<span style="display:flex;align-items:center;gap:10px;">';
                    $html .= '<span style="color:#27ae60;font-weight:bold;">' . htmlspecialchars($priceStr) . '</span>';
                    $html .= '<button type="button" onclick="document.getElementById(\'rfm-domain-modal-' . md5($domainName) . '\').style.display=\'block\'" style="background:#27ae60;color:#fff;border:none;border-radius:4px;padding:6px 14px;font-size:12px;font-weight:bold;cursor:pointer;">Buy</button>';
                    $html .= '</span>';
                } else {
                    $html .= '<span style="color:#999;">Taken</span>';
                }
                $html .= '</div>';

                if ($available) {
                    $html .= rancherfleet_domainPurchaseModal($sld, $tld, $serviceUrl);
                }
            }
            $html .= '</div>';

        } catch (\Exception $e) {
            $html .= '<div style="color:#e74c3c;font-size:12px;">Search failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

    // -----------------------------------------------------------------------
    // Reconnect an existing domain
    // -----------------------------------------------------------------------
    $html .= '<details style="margin-top:16px;">';
    $html .= '<summary style="font-size:12px;color:#2980b9;cursor:pointer;">I already have a domain registered with you</summary>';
    $html .= '<form method="post" action="' . $serviceUrl . '" style="display:flex;gap:8px;margin-top:10px;">';
    $html .= '<input type="hidden" name="clientaction" value="domain_reconnect">';
    $html .= '<input type="text" name="reconnect_domain_raw" placeholder="yourdomain.com" style="flex:1;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;" oninput="var p=this.value.split(\'.\');this.form.reconnect_sld.value=p[0]||\'\';this.form.reconnect_tld.value=p.slice(1).join(\'.\')||\'\';">';
    $html .= '<input type="hidden" name="reconnect_sld">';
    $html .= '<input type="hidden" name="reconnect_tld">';
    $html .= '<button type="submit" style="background:#2980b9;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:bold;cursor:pointer;">Connect</button>';
    $html .= '</form>';
    $html .= '</details>';

    $html .= '</div>';
    return $html;
}

/**
 * Renders the registrant-contact-form modal for a specific domain.
 */
function rancherfleet_domainPurchaseModal($sld, $tld, $serviceUrl)
{
    $domainName = $sld . '.' . $tld;
    $modalId    = 'rfm-domain-modal-' . md5($domainName);

    $html  = '<div id="' . $modalId . '" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">';
    $html .= '<div style="background:#fff;max-width:480px;margin:60px auto;padding:24px;border-radius:8px;max-height:80vh;overflow-y:auto;">';
    $html .= '<h4 style="margin-top:0;">Register ' . htmlspecialchars($domainName) . '</h4>';
    $html .= '<form method="post" action="' . $serviceUrl . '">';
    $html .= '<input type="hidden" name="clientaction" value="domain_purchase">';
    $html .= '<input type="hidden" name="domain_sld" value="' . htmlspecialchars($sld) . '">';
    $html .= '<input type="hidden" name="domain_tld" value="' . htmlspecialchars($tld) . '">';

    $fields = array(
        'reg_firstname' => 'First Name',
        'reg_lastname'  => 'Last Name',
        'reg_company'   => 'Company (optional)',
        'reg_email'     => 'Email',
        'reg_address'   => 'Address',
        'reg_city'      => 'City',
        'reg_state'     => 'State/Province',
        'reg_zip'       => 'ZIP/Postal Code',
        'reg_country'   => 'Country Code (e.g. US)',
        'reg_phone'     => 'Phone Number (with country code)',
    );
    foreach ($fields as $name => $label) {
        $html .= '<label style="display:block;font-size:12px;font-weight:bold;margin:8px 0 3px;">' . htmlspecialchars($label) . '</label>';
        $html .= '<input type="text" name="' . $name . '" style="width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;">';
    }

    $html .= '<p style="font-size:11px;color:#888;margin-top:12px;">This information is required by ICANN for domain registration and will be used as the public WHOIS registrant contact.</p>';
    $html .= '<div style="display:flex;gap:8px;margin-top:14px;">';
    $html .= '<button type="submit" style="background:#27ae60;color:#fff;border:none;border-radius:4px;padding:9px 20px;font-size:13px;font-weight:bold;cursor:pointer;">Purchase &amp; Register</button>';
    $html .= '<button type="button" onclick="document.getElementById(\'' . $modalId . '\').style.display=\'none\'" style="background:#ccc;color:#333;border:none;border-radius:4px;padding:9px 20px;font-size:13px;cursor:pointer;">Cancel</button>';
    $html .= '</div>';
    $html .= '</form></div></div>';

    return $html;
}


/**
 * Renders the client's WHMCS credit balance as a small styled badge with an "Add Credit" button.
 * Reads from tblclients.credit.
 *
 * @param  array $params
 * @return string  HTML badge with button
 */
function rancherfleet_creditBalanceHtml(array $params)
{
    $clientId = (int)$params['userid'];
    try {
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first(['credit']);
        if (!$client) {
            return '';
        }
        $creditBalance = (float)$client->credit;
        $currency = isset($params['clientsdetails']['currency_code'])
                  ? $params['clientsdetails']['currency_code'] : 'USD';
        $html = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">';
        $html .= '<div style="display:inline-block;background:#e8f4f8;border:1px solid #a8d5dd;color:#0c5460;padding:6px 12px;border-radius:12px;font-size:11px;font-weight:bold;">&#127874; Credit Balance: <strong>' . $currency . ' ' . number_format($creditBalance, 2) . '</strong></div>';
        $html .= '<a href="/clientarea.php?action=addfunds" style="background:#2980b9;color:#fff;border:none;border-radius:4px;padding:6px 14px;font-size:11px;font-weight:bold;text-decoration:none;display:inline-block;">+ Add Funds</a>';
        $html .= '</div>';
        return $html;
    } catch (\Exception $e) {
        return '';
    }
}

/**
 * Renders the Odoo password reset card in the client area.
 * Allows users to generate a new admin password via Kubernetes Job.
 *
 * @param  array $params
 * @param  string $orderNum
 * @return string HTML card
 */
function rancherfleet_passwordResetCardHtml(array $params, $orderNum)
{
    $serviceUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '');
    $html = '<div class="rfm-ca-card">';
    $html .= '<h4>Reset Odoo Password</h4>';
    $html .= '<p style="font-size:12px;color:#666;margin-bottom:10px;">If you\'ve been locked out or forgotten your Odoo admin password, click below to generate a new one. The new password will be shown once — save it immediately.</p>';
    $html .= '<form method="post" action="' . $serviceUrl . '" onsubmit="return confirm(\'Generate a new admin password? This will replace your current password.\')">';
    $html .= '<input type="hidden" name="clientaction" value="odoo_password_reset">';
    $html .= '<button type="submit" style="background:#e67e22;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:bold;cursor:pointer;">Generate New Password</button>';
    $html .= '</form>';
    $html .= '</div>';
    return $html;
}

/**
 * Reads storage upgrade configuration from params.
 * NOTE: like all Module Settings in this module, these are accessed via
 * numbered configoptionX keys. After saving these new fields on product 126
 * for the first time, run:
 *   SELECT configoption1..configoption24 FROM tblproducts WHERE id = 126;
 * and update these indices to match the actual stored positions.
 * The indices below assume these four fields land at configoption17-20,
 * which is the expected position if no drift has occurred since the last
 * module save — but drift has occurred before, so verify.
 *
 * @param  array $params
 * @return array  keys: pricePerGb, increments (array of int), threshold (int), pvcName (string)
 */
function rancherfleet_getStorageConfig(array $params)
{
    // IMPORTANT: verify these slot numbers via SQL after saving on product 126.
    // Expected positions: configoption17=pricePerGb, configoption18=increments,
    // configoption19=threshold — but confirm via:
    //   SELECT configoption17, configoption18, configoption19
    //   FROM tblproducts WHERE id = 126;
    $pricePerGb   = isset($params['configoption17']) && $params['configoption17'] !== ''
                  ? (float)$params['configoption17'] : 2.00;

    $incrementsRaw = isset($params['configoption18']) ? trim($params['configoption18']) : '5,10,20,50';
    $increments    = array();
    foreach (explode(',', $incrementsRaw) as $i) {
        $v = (int)trim($i);
        if ($v > 0) $increments[] = $v;
    }
    if (empty($increments)) $increments = array(5, 10, 20, 50);

    $threshold = isset($params['configoption19']) && $params['configoption19'] !== ''
               ? (int)$params['configoption19'] : 80;

    return array(
        'pricePerGb' => $pricePerGb,
        'increments' => $increments,
        'threshold'  => $threshold,
    );
}

/**
 * Builds a LonghornClient from the existing RancherClient params.
 */
function rancherfleet_buildLonghornClient(array $params)
{
    list($rancher) = rancherfleet_buildClients($params);
    $cfg           = rancherfleet_getConfig($params);
    $clusterId     = isset($cfg['target_cluster_id']) ? $cfg['target_cluster_id'] : 'local';
    return new RancherFleet\LonghornClient($rancher, $clusterId);
}

/**
 * Fetches live Odoo filestore usage via Longhorn.
 * Updates the cached provisioned size in StorageUpgradeStore if successful.
 * Returns null if the PVC doesn't exist yet (new/unprovisioned service).
 *
 * @param  array  $params
 * @param  string $namespace
 * @param  string $pvcName
 * @return array|null  see LonghornClient::getPvcUsage()
 */
function rancherfleet_getStorageUsage(array $params, $namespace, $pvcName)
{
    try {
        $longhorn  = rancherfleet_buildLonghornClient($params);
        $usage     = $longhorn->getPvcUsage($namespace, $pvcName);

        if ($usage && $usage['sizeGb'] > 0) {
            RancherFleet\StorageUpgradeStore::updateProvisionedSize(
                (int)$params['serviceid'], $pvcName, (int)ceil($usage['sizeGb'])
            );
        }

        return $usage;
    } catch (\Exception $e) {
        RancherFleet\Logger::error("getStorageUsage: {$namespace}/{$pvcName}: " . $e->getMessage());
        return null;
    }
}

/**
 * Handles a storage upgrade POST from the client area.
 * Flow: validate increment -> capture payment -> expand PVC -> record.
 *
 * @return string  'storage_upgraded:{newGb}' | 'storage_error:{message}' | 'storage_declined:{message}'
 */
function rancherfleet_handleStorageUpgrade(array $params, $namespace, $orderNum)
{
    $addGb = isset($_POST['storage_add_gb']) ? (int)$_POST['storage_add_gb'] : 0;
    if ($addGb <= 0) {
        return 'storage_error:Invalid storage increment.';
    }

    $storageConfig = rancherfleet_getStorageConfig($params);
    if (!in_array($addGb, $storageConfig['increments'])) {
        return 'storage_error:Invalid storage increment selected.';
    }

    // PVC name is odoo-{orderNum} — matches the Fleet manifest naming
    // convention confirmed for this deployment (PVCs vary per client).
    $pvcName   = 'odoo-' . $orderNum;
    $serviceId = (int)$params['serviceid'];
    $clientId  = (int)$params['userid'];

    // Determine current provisioned size — try live query first,
    // fall back to cached value in StorageUpgradeStore.
    $currentGb = null;
    try {
        $longhorn = rancherfleet_buildLonghornClient($params);
        $usage    = $longhorn->getPvcUsage($namespace, $pvcName);
        if ($usage && $usage['sizeGb'] > 0) {
            $currentGb = (int)ceil($usage['sizeGb']);
        }
    } catch (\Exception $e) {
        RancherFleet\Logger::error("handleStorageUpgrade: could not get current size live: " . $e->getMessage());
    }

    if ($currentGb === null) {
        $stored    = RancherFleet\StorageUpgradeStore::get($serviceId);
        $currentGb = $stored['provisionedGb'];
    }

    if ($currentGb === null) {
        return 'storage_error:Could not determine current storage size. Please try again or contact support.';
    }

    $newGb        = $currentGb + $addGb;
    $chargeAmount = round($storageConfig['pricePerGb'] * $addGb, 2);
    $currency     = isset($params['clientsdetails']['currency_code'])
                  ? $params['clientsdetails']['currency_code'] : 'USD';

    // Capture payment first
    try {
        $invoiceResult = localAPI('CreateInvoice', array(
            'userid'           => $clientId,
            'status'           => 'Unpaid',
            'itemdescription1' => "Storage upgrade: +{$addGb}GB for instance {$namespace}",
            'itemamount1'      => $chargeAmount,
            'itemtaxed1'       => false,
            'paymentmethod'    => '',
        ));

        if (!isset($invoiceResult['result']) || $invoiceResult['result'] !== 'success') {
            $err = isset($invoiceResult['message']) ? $invoiceResult['message'] : json_encode($invoiceResult);
            return 'storage_error:Could not create invoice: ' . $err;
        }

        $invoiceId     = (int)$invoiceResult['invoiceid'];
        $captureResult = localAPI('CaptureRemoteCardPayment', array('invoiceid' => $invoiceId));

        if (!isset($captureResult['result']) || $captureResult['result'] !== 'success') {
            $err = isset($captureResult['message']) ? $captureResult['message'] : json_encode($captureResult);
            RancherFleet\Logger::error("handleStorageUpgrade: payment capture failed: {$err}");
            return 'storage_declined:Payment could not be processed: ' . $err;
        }

    } catch (\Exception $e) {
        RancherFleet\Logger::error("handleStorageUpgrade: payment exception: " . $e->getMessage());
        return 'storage_error:Payment error: ' . $e->getMessage();
    }

    // Payment captured — expand the PVC
    try {
        $longhorn = rancherfleet_buildLonghornClient($params);
        $longhorn->expandPvc($namespace, $pvcName, $newGb);

        RancherFleet\StorageUpgradeStore::recordUpgrade(
            $serviceId, $pvcName, $newGb, $addGb, $chargeAmount, $invoiceId
        );

        rancherfleet_logHistory($params, 'Storage Upgraded',
            "+{$addGb}GB -> {$newGb}GB total for {$pvcName} (invoice #{$invoiceId})");

        RancherFleet\Logger::info("handleStorageUpgrade: SUCCESS {$namespace}/{$pvcName} {$currentGb}GB -> {$newGb}GB");

        return 'storage_upgraded:' . $newGb;

    } catch (\Exception $e) {
        // PVC expansion failed but payment was taken — refund immediately
        RancherFleet\Logger::error("handleStorageUpgrade: PVC expand failed after payment: " . $e->getMessage());

        try {
            $txn = \WHMCS\Database\Capsule::table('tblaccounts')
                ->where('invoiceid', $invoiceId)
                ->orderBy('id', 'desc')
                ->value('transid');
            if ($txn) {
                localAPI('RefundTransaction', array(
                    'transid'    => $txn,
                    'refundtype' => 'remote',
                    'amount'     => $chargeAmount,
                    'inputbox'   => 'Storage expansion failed — automatic refund',
                ));
            }
        } catch (\Exception $re) {
            RancherFleet\Logger::error("handleStorageUpgrade: refund also failed: " . $re->getMessage());
        }

        return 'storage_error:Storage expansion failed — you have been refunded. Please contact support. (' . $e->getMessage() . ')';
    }
}

/**
 * Handles a custom subdomain connection POST from the client area.
 * Validates subdomain format, verifies CNAME DNS record, updates odoo.yml Ingress.
 *
 * @return string  'custom_url_success:{subdomain}' | 'custom_url_error:{message}'
 */
function rancherfleet_handleCustomUrlConnect(array $params, $namespace, $orderNum)
{
    $subdomain = isset($_POST['custom_url_subdomain']) ? trim($_POST['custom_url_subdomain']) : '';
    $serviceId = (int)$params['serviceid'];

    if (!$subdomain) {
        return 'custom_url_error:Subdomain is required.';
    }

    // Validate subdomain format: must be subdomain.domain.tld (at least one dot + prefix)
    // Reject bare domains without subdomain prefix
    $parts = explode('.', $subdomain);
    if (count($parts) < 3) {
        return 'custom_url_error:Enter a subdomain (e.g. www.yourdomain.com), not a bare domain (yourdomain.com).';
    }

    // Validate each label: letters, numbers, hyphens only
    foreach ($parts as $label) {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i', $label)) {
            return 'custom_url_error:Invalid subdomain format. Use letters, numbers, and hyphens only.';
        }
    }

    // Reject webdiscode.com subdomains
    $rootDomain = implode('.', array_slice($parts, -2));
    if (strtolower($rootDomain) === 'webdiscode.com') {
        return 'custom_url_error:Cannot use a webdiscode.com subdomain. Enter your own custom domain.';
    }

    // Verify CNAME record points to cowboy.webdiscode.com
    $cnameTarget = 'cowboy.webdiscode.com';
    $dnsRecords = @dns_get_record($subdomain, DNS_CNAME);
    $cnameFound = false;

    if ($dnsRecords && is_array($dnsRecords)) {
        foreach ($dnsRecords as $record) {
            if (isset($record['target'])) {
                $target = rtrim($record['target'], '.');
                if (strtolower($target) === strtolower($cnameTarget)) {
                    $cnameFound = true;
                    break;
                }
            }
        }
    }

    if (!$cnameFound) {
        return 'custom_url_error:Could not verify CNAME record. Please ensure ' . htmlspecialchars($subdomain)
            . ' points to ' . $cnameTarget . ' and try again. DNS changes can take up to 24 hours to propagate.';
    }

    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);

        // Read odoo.yml from client branch
        RancherFleet\Logger::info("handleCustomUrlConnect: reading odoo.yml from {$namespace}");
        $manifestContent = $github->getClientFileContent($namespace, 'odoo.yml');
        if (!$manifestContent) {
            RancherFleet\Logger::error("handleCustomUrlConnect: failed to read odoo.yml from {$namespace}");
            return 'custom_url_error:Could not read manifest file. Please contact support.';
        }
        RancherFleet\Logger::info("handleCustomUrlConnect: odoo.yml read successfully, size=" . strlen($manifestContent) . " bytes");

        // Search for the default www.{orderNum}.com host in the manifest
        $searchString = 'www.' . $orderNum . '.com';
        if (strpos($manifestContent, $searchString) === false) {
            RancherFleet\Logger::error("handleCustomUrlConnect: search string '{$searchString}' not found in odoo.yml");
            return 'custom_url_error:Could not find default domain in manifest. Please contact support.';
        }
        RancherFleet\Logger::info("handleCustomUrlConnect: found '{$searchString}' in manifest");

        // Replace all occurrences of www.{orderNum}.com with the custom subdomain
        RancherFleet\Logger::info("handleCustomUrlConnect: replacing '{$searchString}' with '{$subdomain}'");
        $updated = str_replace($searchString, $subdomain, $manifestContent);

        // Verify the replacement actually worked
        if ($updated === $manifestContent) {
            RancherFleet\Logger::error("handleCustomUrlConnect: str_replace failed - content unchanged");
            return 'custom_url_error:Could not update manifest. Please contact support.';
        }

        // Verify subdomain now appears in the updated content
        if (strpos($updated, $subdomain) === false) {
            RancherFleet\Logger::error("handleCustomUrlConnect: subdomain '{$subdomain}' not found in updated manifest");
            return 'custom_url_error:Manifest update verification failed. Please contact support.';
        }
        RancherFleet\Logger::info("handleCustomUrlConnect: manifest updated successfully");

        // Write updated manifest back
        RancherFleet\Logger::info("handleCustomUrlConnect: writing updated odoo.yml to {$namespace}");
        $github->writeFileToBranch(
            'odoo.yml',
            $updated,
            $namespace,
            'Connect custom subdomain: ' . $subdomain
        );
        RancherFleet\Logger::info("handleCustomUrlConnect: odoo.yml written successfully");

        // Store the custom subdomain in tbladdonmodules
        $table = \WHMCS\Database\Capsule::table('tbladdonmodules');
        $setting = 'url_' . $serviceId;
        $existing = $table->where('module', 'rancherfleet_custom_url')
            ->where('setting', $setting)
            ->first();

        if ($existing) {
            $table->where('module', 'rancherfleet_custom_url')
                ->where('setting', $setting)
                ->update(['value' => $subdomain]);
            RancherFleet\Logger::info("handleCustomUrlConnect: updated tbladdonmodules");
        } else {
            $table->insert([
                'module'  => 'rancherfleet_custom_url',
                'setting' => $setting,
                'value'   => $subdomain,
            ]);
            RancherFleet\Logger::info("handleCustomUrlConnect: inserted into tbladdonmodules");
        }

        rancherfleet_logHistory($params, 'Custom Subdomain Connected', $subdomain);
        RancherFleet\Logger::info("handleCustomUrlConnect: SUCCESS {$namespace} -> {$subdomain}");

        return 'custom_url_success:' . $subdomain;

    } catch (\Exception $e) {
        RancherFleet\Logger::error("handleCustomUrlConnect: EXCEPTION " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return 'custom_url_error:' . $e->getMessage();
    }
}

/**
 * Adds a custom subdomain to the Ingress resource in odoo.yml.
 * Updates both spec.tls[0].hosts and spec.rules to include the new subdomain.
 * Uses reliable markers (secretName anchor for TLS, comment markers for rules).
 *
 * @param  string $yamlContent  The odoo.yml file content
 * @param  string $subdomain    The subdomain to add (e.g. www.yourdomain.com)
 * @param  int    $orderNum     The order number for service name
 * @return string  Updated YAML content
 * @throws Exception if injection patterns fail to match
 */
/**
 * Renders the storage usage card and upgrade panel for the client area.
 *
 * @param  array  $params
 * @param  string $namespace
 * @param  string $serviceUrl
 * @return string  HTML
 */
function rancherfleet_storagePanelHtml(array $params, $namespace, $serviceUrl, $orderNum)
{
    $storageConfig = rancherfleet_getStorageConfig($params);
    // PVC name is odoo-{orderNum} — matches Fleet manifest naming convention.
    $pvcName       = 'odoo-' . $orderNum;
    $pricePerGb    = $storageConfig['pricePerGb'];
    $increments    = $storageConfig['increments'];
    $threshold     = $storageConfig['threshold'];
    $serviceId     = (int)$params['serviceid'];
    $currency      = isset($params['clientsdetails']['currency_code'])
                   ? $params['clientsdetails']['currency_code'] : 'USD';

    $html  = '<div class="rfm-ca-card">';
    $html .= '<h4>Storage</h4>';
    $html .= rancherfleet_creditBalanceHtml($params);

    // Try to get live usage
    $usage = rancherfleet_getStorageUsage($params, $namespace, $pvcName);

    if (!$usage) {
        // Fall back to cached provisioned size if available
        $stored = RancherFleet\StorageUpgradeStore::get($serviceId);
        $html  .= '<div style="font-size:12px;color:#888;">';
        if ($stored['provisionedGb']) {
            $html .= 'Storage: <strong>' . $stored['provisionedGb'] . ' GB</strong> provisioned. Live usage data is temporarily unavailable.';
        } else {
            $html .= 'Storage usage is not yet available. This may appear once your instance has been provisioned and is running.';
        }
        $html .= '</div></div>';
        return $html;
    }

    // Progress bar
    $usedPct   = min(100, $usage['usedPct']);
    $barColor  = $usedPct >= 90 ? '#e74c3c' : ($usedPct >= $threshold ? '#e67e22' : '#27ae60');
    $usedGbStr = number_format($usage['usedGb'], 1);
    $sizeGbStr = number_format($usage['sizeGb'], 1);

    $html .= '<div style="margin-bottom:12px;">';
    $html .= '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">';
    $html .= '<span style="color:#555;">Odoo filestore</span>';
    $html .= '<span style="color:#333;font-weight:bold;">' . $usedGbStr . ' GB / ' . $sizeGbStr . ' GB used (' . $usedPct . '%)</span>';
    $html .= '</div>';
    $html .= '<div style="background:#eee;border-radius:4px;height:10px;overflow:hidden;">';
    $html .= '<div style="width:' . $usedPct . '%;background:' . $barColor . ';height:100%;border-radius:4px;transition:width 0.3s;"></div>';
    $html .= '</div>';

    if ($usedPct >= 90) {
        $html .= '<p style="font-size:11px;color:#e74c3c;margin:6px 0 0;font-weight:bold;">&#9888; Storage is critically full. Upgrade now to avoid service disruption.</p>';
    } elseif ($usedPct >= $threshold) {
        $html .= '<p style="font-size:11px;color:#e67e22;margin:6px 0 0;">&#9888; Storage is getting full. Consider upgrading soon.</p>';
    }
    $html .= '</div>';

    // Upgrade panel — show when at or above threshold, or always show as collapsed
    $showUpgrade = ($usedPct >= $threshold);

    if ($showUpgrade) {
        $html .= '<div style="margin-top:12px;padding:12px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;">';
        $html .= '<p style="font-size:12px;font-weight:bold;margin:0 0 8px;color:#333;">Upgrade Storage</p>';
        $html .= '<p style="font-size:11px;color:#666;margin:0 0 10px;">Add more storage to your Odoo filestore. Your card on file will be charged at <strong>$' . number_format($pricePerGb, 2) . ' per GB</strong>. The upgrade applies immediately with no downtime.</p>';

        $html .= '<form method="post" action="' . $serviceUrl . '" onsubmit="return rancherfleetConfirmUpgrade(this)">';
        $html .= '<input type="hidden" name="clientaction" value="storage_upgrade">';
        $html .= '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';

        foreach ($increments as $gb) {
            $cost  = number_format($pricePerGb * $gb, 2);
            $label = "+{$gb} GB — \${$cost}";
            $html .= '<label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:6px 10px;border:1px solid #ccc;border-radius:4px;background:#fff;">';
            $html .= '<input type="radio" name="storage_add_gb" value="' . $gb . '" required> ' . htmlspecialchars($label);
            $html .= '</label>';
        }

        $html .= '</div>';
        $html .= '<button type="submit" style="background:#2980b9;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:bold;cursor:pointer;">Upgrade Storage</button>';
        $html .= '</form>';

        $html .= '<script>function rancherfleetConfirmUpgrade(form) {
            var sel = form.querySelector("input[name=storage_add_gb]:checked");
            if (!sel) { alert("Please select a storage increment."); return false; }
            var gb = sel.value;
            var cost = (gb * ' . $pricePerGb . ').toFixed(2);
            return confirm("Add " + gb + " GB for $" + cost + "? Your card on file will be charged.");
        }</script>';

        $html .= '</div>';
    } else {
        // Below threshold — show a collapsed "upgrade available" link
        $html .= '<details style="margin-top:10px;">';
        $html .= '<summary style="font-size:12px;color:#2980b9;cursor:pointer;">Upgrade storage</summary>';
        $html .= '<div style="margin-top:10px;padding:12px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;">';
        $html .= '<p style="font-size:11px;color:#666;margin:0 0 10px;">Add more storage at <strong>$' . number_format($pricePerGb, 2) . ' per GB</strong>. Applies immediately with no downtime.</p>';

        $html .= '<form method="post" action="' . $serviceUrl . '" onsubmit="return rancherfleetConfirmUpgrade(this)">';
        $html .= '<input type="hidden" name="clientaction" value="storage_upgrade">';
        $html .= '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';

        foreach ($increments as $gb) {
            $cost  = number_format($pricePerGb * $gb, 2);
            $label = "+{$gb} GB — \${$cost}";
            $html .= '<label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:6px 10px;border:1px solid #ccc;border-radius:4px;background:#fff;">';
            $html .= '<input type="radio" name="storage_add_gb" value="' . $gb . '" required> ' . htmlspecialchars($label);
            $html .= '</label>';
        }

        $html .= '</div>';
        $html .= '<button type="submit" style="background:#2980b9;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:bold;cursor:pointer;">Upgrade Storage</button>';
        $html .= '</form>';
        $html .= '<script>function rancherfleetConfirmUpgrade(form) {
            var sel = form.querySelector("input[name=storage_add_gb]:checked");
            if (!sel) { alert("Please select a storage increment."); return false; }
            var gb = sel.value;
            var cost = (gb * ' . $pricePerGb . ').toFixed(2);
            return confirm("Add " + gb + " GB for $" + cost + "? Your card on file will be charged.");
        }</script>';
        $html .= '</div>';
        $html .= '</details>';
    }

    // Upgrade history (collapsed by default)
    $stored  = RancherFleet\StorageUpgradeStore::get($serviceId);
    $history = isset($stored['history']) ? array_reverse($stored['history']) : array();
    if (!empty($history)) {
        $html .= '<details style="margin-top:10px;">';
        $html .= '<summary style="font-size:11px;color:#888;cursor:pointer;">Upgrade history</summary>';
        $html .= '<table style="width:100%;margin-top:8px;font-size:11px;border-collapse:collapse;">';
        $html .= '<tr style="color:#888;border-bottom:1px solid #eee;"><th style="text-align:left;padding:4px;">Date</th><th style="text-align:right;padding:4px;">Added</th><th style="text-align:right;padding:4px;">New total</th><th style="text-align:right;padding:4px;">Charged</th></tr>';
        foreach ($history as $h) {
            $html .= '<tr style="border-bottom:1px solid #f5f5f5;">';
            $html .= '<td style="padding:4px;color:#555;">' . htmlspecialchars($h['at']) . '</td>';
            $html .= '<td style="padding:4px;text-align:right;">+' . (int)$h['addedGb'] . ' GB</td>';
            $html .= '<td style="padding:4px;text-align:right;">' . (int)$h['newTotalGb'] . ' GB</td>';
            $html .= '<td style="padding:4px;text-align:right;">$' . number_format($h['chargeAmount'], 2) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table></details>';
    }

    $html .= '</div>';
    return $html;
}


function rancherfleet_clientAreaHtml(array $params, $namespace, $message = '')
{
    $orderNum = rancherfleet_getOrderNumber($params);

    // Styles
    $html = '<style>
        .rfm-ca { font-family: inherit; }
        .rfm-ca-card { background: #fff; border: 1px solid #dde; border-radius: 8px; padding: 18px; margin-bottom: 16px; }
        .rfm-ca-card h4 { margin: 0 0 12px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #555; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .rfm-status-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .rfm-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .rfm-badge-running  { background: #d4edda; color: #155724; }
        .rfm-badge-stopped  { background: #f8d7da; color: #721c24; }
        .rfm-badge-starting { background: #fff3cd; color: #856404; }
        .rfm-badge-suspended { background: #e2e3e5; color: #383d41; }
        .rfm-stat { font-size: 12px; color: #666; }
        .rfm-stat strong { color: #333; }
        .rfm-log { background: #1e1e2e; color: #cdd6f4; border-radius: 6px; padding: 14px; font-family: monospace; font-size: 11px; line-height: 1.5; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; margin-top: 0; }
        .rfm-log-ts { color: #6c7086; }
        .rfm-log-err { color: #f38ba8; }
        .rfm-log-warn { color: #fab387; }
        .rfm-log-info { color: #cdd6f4; }
        .rfm-restart-btn { background: #e67e22; color: #fff; border: none; border-radius: 4px; padding: 8px 18px; font-size: 13px; font-weight: bold; cursor: pointer; }
        .rfm-restart-btn:hover { background: #d35400; }
        .rfm-alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px 14px; border-radius: 4px; margin-bottom: 12px; font-size: 13px; }
        .rfm-alert-error   { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 10px 14px; border-radius: 4px; margin-bottom: 12px; font-size: 13px; }
        .rfm-pod-row { display: flex; gap: 16px; font-size: 12px; padding: 6px 0; border-bottom: 1px solid #f5f5f5; flex-wrap: wrap; }
        .rfm-pod-name { font-family: monospace; color: #333; font-weight: bold; flex: 2; }
        .rfm-fleet-row { display: flex; gap: 8px; font-size: 12px; padding: 4px 0; }
        .rfm-fleet-label { color: #888; width: 100px; flex-shrink: 0; }
    </style>
    <div class="rfm-ca">';

    // Message banner
    if ($message === 'success') {
        $html .= '<div class="rfm-alert-success">&#10003; Restart requested successfully. Your instance will be back online shortly.</div>';
    } elseif (strpos($message, 'error:') === 0) {
        $html .= '<div class="rfm-alert-error">&#10007; Restart failed: ' . htmlspecialchars(substr($message, 6)) . '</div>';
    } elseif (strpos($message, 'domain_success:') === 0) {
        $domain = substr($message, strlen('domain_success:'));
        $html .= '<div class="rfm-alert-success">&#10003; ' . htmlspecialchars($domain) . ' registered successfully! www.' . htmlspecialchars($domain) . ' is now connected to your instance.</div>';
    } elseif (strpos($message, 'domain_processing:') === 0) {
        $domain = substr($message, strlen('domain_processing:'));
        $html .= '<div class="rfm-alert-success" style="background:#fff3cd;border-color:#ffc107;color:#856404;">&#9203; Your payment for ' . htmlspecialchars($domain) . ' was successful and registration is processing. This can take a few hours — we will connect it automatically once complete, or refund you if it cannot be completed.</div>';
    } elseif (strpos($message, 'domain_error:') === 0) {
        $html .= '<div class="rfm-alert-error">&#10007; ' . htmlspecialchars(substr($message, strlen('domain_error:'))) . '</div>';
    } elseif (strpos($message, 'domain_renewed:') === 0) {
        $domain = substr($message, strlen('domain_renewed:'));
        $html .= '<div class="rfm-alert-success">&#10003; ' . htmlspecialchars($domain) . ' renewed successfully.</div>';
    } elseif (strpos($message, 'domain_connected:') === 0) {
        $domain = substr($message, strlen('domain_connected:'));
        $html .= '<div class="rfm-alert-success">&#10003; ' . htmlspecialchars($domain) . ' is now connected to this instance.</div>';
    } elseif (strpos($message, 'domain_not_found:') === 0) {
        $domain = substr($message, strlen('domain_not_found:'));
        $html .= '<div class="rfm-alert-error">&#10007; ' . htmlspecialchars($domain) . ' was not found among domains registered through this account.</div>';
    } elseif (strpos($message, 'storage_upgraded:') === 0) {
        $newGb = substr($message, strlen('storage_upgraded:'));
        $html .= '<div class="rfm-alert-success">&#10003; Storage upgraded successfully — your Odoo filestore is now <strong>' . (int)$newGb . ' GB</strong>.</div>';
    } elseif (strpos($message, 'storage_declined:') === 0) {
        $html .= '<div class="rfm-alert-error">&#10007; Payment declined: ' . htmlspecialchars(substr($message, strlen('storage_declined:'))) . '</div>';
    } elseif (strpos($message, 'storage_error:') === 0) {
        $html .= '<div class="rfm-alert-error">&#10007; ' . htmlspecialchars(substr($message, strlen('storage_error:'))) . '</div>';
    } elseif (strpos($message, 'backup_restore_success:') === 0) {
        $backupInfo = substr($message, strlen('backup_restore_success:'));
        $html .= '<div class="rfm-alert-success">&#10003; Backup restored successfully. Your instance will be back online shortly.</div>';
    } elseif (strpos($message, 'backup_restore_error:') === 0) {
        $html .= '<div class="rfm-alert-error">&#10007; Restore failed: ' . htmlspecialchars(substr($message, strlen('backup_restore_error:'))) . '</div>';
    } elseif (strpos($message, 'custom_url_success:') === 0) {
        $subdomain = substr($message, strlen('custom_url_success:'));
        $html .= '<div class="rfm-alert-success">&#10003; ' . htmlspecialchars($subdomain) . ' has been connected. Your SSL certificate will be issued automatically within a few minutes.</div>';
    } elseif (strpos($message, 'custom_url_error:') === 0) {
        $html .= '<div class="rfm-alert-error">&#10007; ' . htmlspecialchars(substr($message, strlen('custom_url_error:'))) . '</div>';
    } elseif (strpos($message, 'odoo_password_reset_success:') === 0) {
        $newPassword = substr($message, strlen('odoo_password_reset_success:'));
        $html .= '<div style="background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:12px 14px;border-radius:4px;margin-bottom:12px;font-size:13px;">';
        $html .= '<p style="margin:0 0 10px;font-weight:bold;">&#9888; Your new Odoo admin password has been generated:</p>';
        $html .= '<div style="background:#fff;border:1px solid #ffc107;border-radius:4px;padding:10px;font-family:monospace;font-size:14px;font-weight:bold;color:#333;word-break:break-all;margin-bottom:10px;">' . htmlspecialchars($newPassword) . '</div>';
        $html .= '<p style="margin:0;font-size:12px;"><strong>Save this password now</strong> — it will not be shown again. You can log in using username <code>admin</code> and this password.</p>';
        $html .= '</div>';
    } elseif (strpos($message, 'odoo_password_reset_error:') === 0) {
        $html .= '<div class="rfm-alert-error">&#10007; Password reset failed: ' . htmlspecialchars(substr($message, strlen('odoo_password_reset_error:'))) . '</div>';
    } elseif (strpos($message, 'module_install_success:') === 0) {
        $moduleName = substr($message, strlen('module_install_success:'));
        $available = rancherfleet_getAvailableModules();
        $appName = isset($available[$moduleName]) ? $available[$moduleName]['name'] : $moduleName;
        $html .= '<div class="rfm-alert-success">&#10003; ' . htmlspecialchars($appName) . ' has been installed successfully. Your instance will be updated shortly.</div>';
    } elseif (strpos($message, 'module_install_error:') === 0) {
        $html .= '<div class="rfm-alert-error">&#10007; App installation failed: ' . htmlspecialchars(substr($message, strlen('module_install_error:'))) . '</div>';
    } elseif (strpos($message, 'upgrade_success:') === 0) {
        $version = substr($message, strlen('upgrade_success:'));
        $html .= '<div style="background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:12px 14px;border-radius:4px;margin-bottom:12px;font-size:13px;"><strong>&#10003; Upgrade requested</strong> — Your upgrade to Odoo ' . htmlspecialchars($version) . ' has been submitted. Our team will review and process it within 24 hours.</div>';
    } elseif (strpos($message, 'upgrade_cancelled:') === 0) {
        $html .= '<div class="rfm-alert-success">&#10003; Your upgrade request has been cancelled.</div>';
    } elseif (strpos($message, 'upgrade_error:') === 0) {
        $html .= '<div class="rfm-alert-error">&#10007; Upgrade request failed: ' . htmlspecialchars(substr($message, strlen('upgrade_error:'))) . '</div>';
    }

    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);

        // Get deployment status
        $deployments = $rancher->listDeployments($namespace);
        $status      = $rancher->getDeploymentStatus($namespace, 'odoo-' . $orderNum);
        $isSuspended = $status['replicas'] === 0;
        $isRunning   = $status['ready'] > 0;

        // -----------------------------------------------------------------------
        // Instance Link Card — shown above status, always visible
        // -----------------------------------------------------------------------
        $defaultUrl   = 'https://' . $orderNum . '.webdiscode.com';
        $domainRecord = null;
        $serviceId    = (int)$params['serviceid'];

        // Check if the client has a custom domain wired to this service
        $domainRecords = RancherFleet\Domains\DomainRecordStore::getForService($serviceId);
        foreach ($domainRecords as $record) {
            if (!empty($record['dnsWiredAt'])) {
                $domainRecord = $record;
                break;
            }
        }

        $html .= '<div class="rfm-ca-card">';
        $html .= '<h4>Your Odoo Instance</h4>';

        // URLs
        $html .= '<div style="margin-bottom:14px;">';
        $html .= '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">';
        $html .= '<span style="font-size:12px;color:#888;width:110px;flex-shrink:0;">Default URL</span>';
        $html .= '<a href="' . htmlspecialchars($defaultUrl) . '" target="_blank" '
               . 'style="font-size:13px;font-weight:bold;color:#2980b9;text-decoration:none;">'
               . htmlspecialchars($defaultUrl) . ' &rarr;</a>';
        $html .= '</div>';

        if ($domainRecord) {
            $customDomain = 'https://www.' . $domainRecord['sld'] . '.' . $domainRecord['tld'];
            $html .= '<div style="display:flex;align-items:center;gap:10px;">';
            $html .= '<span style="font-size:12px;color:#888;width:110px;flex-shrink:0;">Custom Domain</span>';
            $html .= '<a href="' . htmlspecialchars($customDomain) . '" target="_blank" '
                   . 'style="font-size:13px;font-weight:bold;color:#27ae60;text-decoration:none;">'
                   . htmlspecialchars($customDomain) . ' &rarr;</a>';
            $html .= '</div>';
        } else {
            $html .= '<div style="font-size:11px;color:#aaa;margin-top:4px;">No custom domain connected yet. '
                   . 'Use the Domain Name panel below to register and connect one.</div>';
        }
        $html .= '</div>';

        // Login instructions
        $html .= '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:12px;">';
        $html .= '<p style="font-size:12px;font-weight:bold;margin:0 0 8px;color:#333;">&#128273; First-time login</p>';
        $html .= '<table style="font-size:12px;border-collapse:collapse;width:100%;">';
        $html .= '<tr><td style="color:#888;padding:3px 0;width:80px;">Username</td>'
               . '<td><code style="background:#e9ecef;padding:2px 6px;border-radius:3px;">admin</code></td></tr>';
        $html .= '<tr><td style="color:#888;padding:3px 0;">Password</td>'
               . '<td><code style="background:#e9ecef;padding:2px 6px;border-radius:3px;">admin</code></td></tr>';
        $html .= '</table>';
        $html .= '<div style="margin-top:10px;padding:8px 10px;background:#fff3cd;border:1px solid #ffc107;'
               . 'border-radius:4px;font-size:11px;color:#856404;">';
        $html .= '<strong>&#9888; Important:</strong> Change your password immediately after your first login. '
               . 'In Odoo, go to the top-right menu &rarr; <strong>Preferences</strong> &rarr; '
               . '<strong>Change Password</strong>. Using the default password leaves your instance open to unauthorised access.';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>'; // instance link card

        // -----------------------------------------------------------------------
        // Reset Odoo Password Card
        // -----------------------------------------------------------------------
        $html .= rancherfleet_passwordResetCardHtml($params, $orderNum);

        // -----------------------------------------------------------------------
        // Install Apps Card
        // -----------------------------------------------------------------------
        $html .= rancherfleet_installAppCardHtml($params, $orderNum);

        // -----------------------------------------------------------------------
        // Upgrade Odoo Version Card
        // -----------------------------------------------------------------------
        $currentOdooVersion = null;
        if ($status['image'] && preg_match('/odoo:([0-9.]+)/', $status['image'], $m)) {
            $currentOdooVersion = $m[1];
        }
        if ($currentOdooVersion) {
            $html .= rancherfleet_upgradeVersionCardHtml($params, $orderNum, $currentOdooVersion);
        }

        // -----------------------------------------------------------------------
        // Custom Subdomain Ingress Card
        // -----------------------------------------------------------------------
        $customSubdomain = null;
        try {
            $customUrlData = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'rancherfleet_custom_url')
                ->where('setting', 'url_' . $serviceId)
                ->value('value');
            if ($customUrlData) {
                $customSubdomain = $customUrlData;
            }
        } catch (\Exception $e) {
            // Continue without custom subdomain
        }

        $html .= '<div class="rfm-ca-card">';
        $html .= '<h4>Connect a Custom Domain</h4>';

        if ($customSubdomain) {
            $html .= '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:12px;">';
            $html .= '<p style="font-size:12px;color:#666;margin:0 0 8px;">Connected subdomain:</p>';
            $html .= '<div style="display:flex;align-items:center;gap:10px;">';
            $html .= '<span style="font-size:13px;font-weight:bold;color:#2980b9;font-family:monospace;">' . htmlspecialchars($customSubdomain) . '</span>';
            $html .= '<a href="https://' . htmlspecialchars($customSubdomain) . '" target="_blank" style="color:#2980b9;text-decoration:none;font-size:13px;">&#8599;</a>';
            $html .= '</div>';
            $html .= '<p style="font-size:11px;color:#888;margin:8px 0 0;">To change or remove this subdomain, contact support.</p>';
            $html .= '</div>';
        } else {
            $html .= '<div style="background:#e3f2fd;border:1px solid #90caf9;border-radius:6px;padding:12px;margin-bottom:12px;">';
            $html .= '<p style="font-size:12px;color:#1565c0;margin:0 0 8px;line-height:1.4;"><strong>Instructions:</strong> Enter a subdomain you have pointed to cowboy.webdiscode.com via a CNAME record. Use a subdomain only (e.g. www.yourdomain.com, app.yourdomain.com) — do not enter a root domain (yourdomain.com) as this can break email and other services on your domain. Your registrar\'s DNS settings should have:</p>';
            $html .= '<div style="font-family:monospace;font-size:11px;background:#fff;border:1px solid #90caf9;border-radius:4px;padding:8px;margin:0;color:#1565c0;"><strong>Type:</strong> CNAME  |  <strong>Host:</strong> www  |  <strong>Value:</strong> cowboy.webdiscode.com</div>';
            $html .= '</div>';
            $html .= '<form method="post" action="' . $serviceUrl . '">';
            $html .= '<div style="margin-bottom:12px;">';
            $html .= '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">';
            $html .= '<input type="checkbox" name="custom_url_enable" id="custom_url_enable" onchange="document.getElementById(\'custom_url_form\').style.display=this.checked?\'block\':\'none\'" style="cursor:pointer;">';
            $html .= '<span>I have set up the CNAME record for my subdomain</span>';
            $html .= '</label>';
            $html .= '</div>';
            $html .= '<div id="custom_url_form" style="display:none;margin-bottom:12px;">';
            $html .= '<label style="display:block;font-size:12px;font-weight:bold;margin:0 0 6px;color:#333;">Subdomain</label>';
            $html .= '<input type="text" name="custom_url_subdomain" placeholder="www.yourdomain.com" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;margin-bottom:10px;">';
            $html .= '</div>';
            $html .= '<input type="hidden" name="clientaction" value="custom_url_connect">';
            $html .= '<button type="submit" id="custom_url_btn" disabled style="background:#2980b9;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:bold;cursor:not-allowed;opacity:0.5;">Verify & Connect</button>';
            $html .= '<script>
                document.getElementById("custom_url_enable").addEventListener("change", function() {
                    const btn = document.getElementById("custom_url_btn");
                    const input = document.querySelector("input[name=custom_url_subdomain]");
                    if (this.checked) {
                        input.addEventListener("input", function() {
                            btn.disabled = !this.value.trim();
                            btn.style.opacity = this.value.trim() ? "1" : "0.5";
                            btn.style.cursor = this.value.trim() ? "pointer" : "not-allowed";
                        });
                    } else {
                        btn.disabled = true;
                        btn.style.opacity = "0.5";
                        btn.style.cursor = "not-allowed";
                    }
                });
            </script>';
            $html .= '</form>';
        }
        $html .= '</div>';

        // -----------------------------------------------------------------------
        // Status Card
        // -----------------------------------------------------------------------
        $html .= '<div class="rfm-ca-card">';
        $html .= '<h4>Instance Status</h4>';
        $html .= '<div class="rfm-status-bar">';

        if ($isSuspended) {
            $html .= '<span class="rfm-badge rfm-badge-suspended">&#9679; Suspended</span>';
            $html .= '<span class="rfm-stat">Your instance is currently suspended. Contact support to reactivate.</span>';
        } elseif ($isRunning) {
            $html .= '<span class="rfm-badge rfm-badge-running">&#9679; Running</span>';
            $html .= '<span class="rfm-stat"><strong>' . $status['ready'] . '/' . $status['replicas'] . '</strong> pods ready</span>';
        } elseif ($status['replicas'] > 0) {
            $html .= '<span class="rfm-badge rfm-badge-starting">&#9679; Starting</span>';
            $html .= '<span class="rfm-stat"><strong>0/' . $status['replicas'] . '</strong> pods ready &mdash; please wait</span>';
        } else {
            $html .= '<span class="rfm-badge rfm-badge-stopped">&#9679; Offline</span>';
        }

        // Image version
        if ($status['image']) {
            $imageTag = strpos($status['image'], ':') !== false
                ? substr($status['image'], strrpos($status['image'], ':') + 1)
                : $status['image'];
            $html .= '<span class="rfm-stat">Version: <strong>' . htmlspecialchars($imageTag) . '</strong></span>';
        }

        $html .= '</div>';

        // Pod rows
        if (!empty($status['pods'])) {
            $html .= '<div style="margin-top:12px;">';
            foreach ($status['pods'] as $pod) {
                $readyIcon  = $pod['ready']  ? '<span style="color:#27ae60;">&#10003;</span>' : '<span style="color:#e74c3c;">&#10007;</span>';
                $restartStr = $pod['restarts'] > 0
                    ? '<span style="color:#e67e22;">' . $pod['restarts'] . ' restart' . ($pod['restarts'] !== 1 ? 's' : '') . '</span>'
                    : '<span style="color:#888;">0 restarts</span>';
                $uptime = '';
                if ($pod['start_time']) {
                    $secs  = time() - strtotime($pod['start_time']);
                    $uptime = $secs > 3600
                        ? floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'm'
                        : floor($secs / 60) . 'm';
                    $uptime = '<span style="color:#888;">up ' . $uptime . '</span>';
                }
                $html .= '<div class="rfm-pod-row">'
                       . '<div class="rfm-pod-name">' . $readyIcon . ' ' . htmlspecialchars($pod['name']) . '</div>'
                       . '<div class="rfm-stat">' . htmlspecialchars($pod['phase']) . '</div>'
                       . '<div class="rfm-stat">' . $restartStr . '</div>'
                       . '<div class="rfm-stat">' . $uptime . '</div>'
                       . '</div>';
            }
            $html .= '</div>';
        }

        // Health metrics (collapsible)
        $healthMetricsHtml = '';
        if (!$isSuspended && !empty($status['pods'])) {
            $firstPodName = $status['pods'][0]['name'];
            $metrics = rancherfleet_fetchPodMetrics($params, $namespace, $firstPodName);
            $storageUsage = rancherfleet_getStorageUsage($params, $namespace, 'odoo-' . $orderNum);

            if (!empty($metrics) || !empty($storageUsage)) {
                $healthMetricsHtml = rancherfleet_renderHealthMetrics($metrics, $storageUsage);
            }
        }

        if (!empty($healthMetricsHtml)) {
            $html .= '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #eee;">';
            $html .= '<a href="javascript:void(0)" onclick="var el=document.getElementById(\'rfm-health-metrics\'); el.style.display=el.style.display===\'none\'?\'block\':\'none\'; this.textContent = el.style.display===\'none\' ? \'▼ Show Health Metrics\' : \'▲ Hide Health Metrics\'; return false;" style="font-size:12px;color:#2980b9;text-decoration:none;cursor:pointer;">▼ Show Health Metrics</a>';
            $html .= '<div id="rfm-health-metrics" style="display:none;">';
            $html .= $healthMetricsHtml;
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>'; // status card

        // -----------------------------------------------------------------------
        // Fleet Sync Card
        // -----------------------------------------------------------------------
        try {
            $gitRepo    = $fleet->getGitRepo($namespace);
            $conditions = isset($gitRepo['status']['conditions']) ? $gitRepo['status']['conditions'] : array();
            $commit     = isset($gitRepo['status']['commit'])     ? substr($gitRepo['status']['commit'], 0, 8) : '';
            $syncState  = 'Unknown';
            $syncMsg    = '';
            $syncTs     = '';
            foreach ($conditions as $cond) {
                if (isset($cond['type']) && $cond['type'] === 'Ready') {
                    $syncState = $cond['status'] === 'True' ? 'Synced' : 'Out of sync';
                    $syncMsg   = isset($cond['message']) ? $cond['message'] : '';
                    $syncTs    = isset($cond['lastUpdateTime']) ? date('Y-m-d H:i', strtotime($cond['lastUpdateTime'])) : '';
                    break;
                }
            }
            $syncColor = $syncState === 'Synced' ? '#27ae60' : '#e67e22';

            $html .= '<div class="rfm-ca-card">';
            $html .= '<h4>Configuration Sync</h4>';
            $html .= '<div class="rfm-fleet-row"><div class="rfm-fleet-label">Status</div>'
                   . '<div style="color:' . $syncColor . ';font-weight:bold;font-size:12px;">' . htmlspecialchars($syncState) . '</div></div>';
            if ($commit) {
                $html .= '<div class="rfm-fleet-row"><div class="rfm-fleet-label">Commit</div>'
                       . '<div style="font-family:monospace;font-size:12px;">' . htmlspecialchars($commit) . '</div></div>';
            }
            if ($syncTs) {
                $html .= '<div class="rfm-fleet-row"><div class="rfm-fleet-label">Last sync</div>'
                       . '<div style="font-size:12px;color:#666;">' . htmlspecialchars($syncTs) . '</div></div>';
            }
            $html .= '</div>';
        } catch (\Exception $e) {
            // Fleet status not critical — skip silently
        }

        // -----------------------------------------------------------------------
        // Pod Logs Card
        // -----------------------------------------------------------------------
        if (!$isSuspended && !empty($status['pods'])) {
            $firstPod = $status['pods'][0];
            try {
                $rawLogs = null;
                try {
                    $rawLogs = $rancher->getPodLogs(
                        $namespace,
                        $firstPod['name'],
                        $firstPod['container_name'],
                        3600,
                        200
                    );
                } catch (RancherApiException $logEx) {
                    if ($logEx->getHttpCode() === 404) {
                        // Pod name stale — fetch current pods and retry with first Running pod
                        $freshPods = $rancher->listPods($namespace);
                        $runningPod = null;
                        foreach ($freshPods as $pod) {
                            if (isset($pod['status']['phase']) && $pod['status']['phase'] === 'Running') {
                                $runningPod = $pod;
                                break;
                            }
                        }
                        if ($runningPod) {
                            $podName = isset($runningPod['metadata']['name']) ? $runningPod['metadata']['name'] : '';
                            $containerName = '';
                            $podContainers = isset($runningPod['spec']['containers']) ? $runningPod['spec']['containers'] : array();
                            foreach ($podContainers as $pc) {
                                $containerName = isset($pc['name']) ? $pc['name'] : '';
                                break;
                            }
                            if ($podName && $containerName) {
                                $rawLogs = $rancher->getPodLogs($namespace, $podName, $containerName, 3600, 200);
                            }
                        }
                    }
                    if (!$rawLogs) {
                        throw $logEx;
                    }
                }

                // Colorise log lines
                $lines      = explode("\n", $rawLogs);
                $colorized  = array();
                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;

                    // Extract timestamp if present (RFC3339 format from k8s)
                    $ts   = '';
                    $text = $line;
                    if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^\s]*)\s+(.*)$/', $line, $m)) {
                        $ts   = date('H:i:s', strtotime($m[1]));
                        $text = $m[2];
                    }

                    $class = 'rfm-log-info';
                    $lc    = strtolower($text);
                    if (strpos($lc, 'error') !== false || strpos($lc, 'critical') !== false || strpos($lc, 'traceback') !== false) {
                        $class = 'rfm-log-err';
                    } elseif (strpos($lc, 'warning') !== false || strpos($lc, 'warn') !== false) {
                        $class = 'rfm-log-warn';
                    }

                    $tsHtml  = $ts ? '<span class="rfm-log-ts">' . $ts . ' </span>' : '';
                    $colorized[] = '<span class="' . $class . '">' . $tsHtml . htmlspecialchars($text) . '</span>';
                }

                $logContent = implode("\n", $colorized) ?: '<span class="rfm-log-ts">[No log output in the past hour]</span>';

                $html .= '<div class="rfm-ca-card">';
                $html .= '<h4>Instance Log &mdash; Past Hour <span style="font-weight:normal;color:#aaa;text-transform:none;font-size:11px;">(last 200 lines)</span></h4>';
                $html .= '<div class="rfm-log">' . $logContent . '</div>';
                $html .= '</div>';

            } catch (\Exception $e) {
                $html .= '<div class="rfm-ca-card">';
                $html .= '<h4>Instance Log</h4>';
                $html .= '<div style="font-size:12px;color:#888;">Logs unavailable: ' . htmlspecialchars($e->getMessage()) . '</div>';
                $html .= '</div>';
            }
        } elseif ($isSuspended) {
            $html .= '<div class="rfm-ca-card">';
            $html .= '<h4>Instance Log</h4>';
            $html .= '<div style="font-size:12px;color:#888;">Logs are not available while your instance is suspended.</div>';
            $html .= '</div>';
        }

        // -----------------------------------------------------------------------
        // Restart Button
        // -----------------------------------------------------------------------
        if (!$isSuspended) {
            $serviceUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '');
            $html .= '<div class="rfm-ca-card">';
            $html .= '<h4>Actions</h4>';
            $html .= '<p style="font-size:12px;color:#666;margin-bottom:10px;">If your instance is unresponsive, use the restart button to cycle the pods. Active sessions will be lost during restart.</p>';
            $html .= '<form method="post" action="' . $serviceUrl . '" onsubmit="return confirm(\'Restart your Odoo instance? Active sessions will be lost.\')">';
            $html .= '<input type="hidden" name="clientaction" value="restart">';
            $html .= '<button type="submit" class="rfm-restart-btn">&#8635; Restart Instance</button>';
            $html .= '</form>';
            $html .= '</div>';
        }

        // -----------------------------------------------------------------------
        // Domain Search & Purchase
        // -----------------------------------------------------------------------
        $html .= rancherfleet_domainPanelHtml($params, $namespace, $orderNum);

        // -----------------------------------------------------------------------
        // Storage Usage & Upgrade
        // -----------------------------------------------------------------------
        $serviceUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '');
        $html .= rancherfleet_storagePanelHtml($params, $namespace, $serviceUrl, $orderNum);

        // -----------------------------------------------------------------------
        // Backups
        // -----------------------------------------------------------------------
        $html .= rancherfleet_backupPanelHtml($params, $namespace, $orderNum, $serviceUrl);

    } catch (\Exception $e) {
        RancherFleet\Logger::error("ClientArea error: " . $e->getMessage());
        $html .= '<div class="rfm-ca-card">';
        $html .= '<h4>Instance Status</h4>';
        $html .= '<div style="color:#e74c3c;font-size:13px;">Unable to retrieve instance status. Please contact support if this persists.</div>';
        $html .= '</div>';
    }

    $html .= '</div>'; // rfm-ca
    return $html;
}


/**
 * Helper: charge client credit for an operation.
 *
 * @param  int    $clientId
 * @param  float  $fee
 * @param  string $description
 * @return array  ['success' => bool, 'error' => string|null, 'invoiceId' => int|null]
 */
function rancherfleet_chargeCredit($clientId, $fee, $description)
{
    try {
        $invoiceResult = localAPI('CreateInvoice', array(
            'userid'           => $clientId,
            'status'           => 'Unpaid',
            'itemdescription1' => $description,
            'itemamount1'      => $fee,
            'itemtaxed1'       => false,
            'paymentmethod'    => '',
        ));

        if (!isset($invoiceResult['result']) || $invoiceResult['result'] !== 'success') {
            $err = isset($invoiceResult['message']) ? $invoiceResult['message'] : json_encode($invoiceResult);
            return array('success' => false, 'error' => 'Could not create invoice: ' . $err, 'invoiceId' => null);
        }

        $invoiceId = (int)$invoiceResult['invoiceid'];

        $creditResult = localAPI('ApplyCredit', array(
            'clientid' => $clientId,
            'amount'   => $fee,
        ));

        if (!isset($creditResult['result']) || $creditResult['result'] !== 'success') {
            $err = isset($creditResult['message']) ? $creditResult['message'] : json_encode($creditResult);
            return array('success' => false, 'error' => 'Could not apply credit: ' . $err, 'invoiceId' => $invoiceId);
        }

        return array('success' => true, 'error' => null, 'invoiceId' => $invoiceId);

    } catch (\Exception $e) {
        return array('success' => false, 'error' => $e->getMessage(), 'invoiceId' => null);
    }
}


/**
 * Helper: refund client credit for a failed operation.
 *
 * @param int    $clientId
 * @param float  $fee
 * @param int    $invoiceId
 * @param string $reason
 */
function rancherfleet_refundCredit($clientId, $fee, $invoiceId, $reason)
{
    try {
        RancherFleet\Logger::info("refundCredit: refunding \${$fee} to client {$clientId}, invoice {$invoiceId}, reason: {$reason}");
        localAPI('AddCredit', array('clientid' => $clientId, 'amount' => $fee));
    } catch (\Exception $e) {
        RancherFleet\Logger::error("refundCredit: error refunding: " . $e->getMessage());
    }
}

/**
 * Update upgrade request status with optional metadata
 */
function rancherfleet_updateUpgradeStatus($serviceId, $status, $metadata = array())
{
    $record = rancherfleet_getUpgradeRequest($serviceId) ?: array();
    $record['status'] = $status;
    foreach ($metadata as $k => $v) {
        $record[$k] = $v;
    }
    \WHMCS\Database\Capsule::table('tbladdonmodules')->updateOrInsert(
        array('module' => 'rancherfleet_upgrade', 'setting' => 'request_' . $serviceId),
        array('value' => json_encode($record))
    );
}

/**
 * Send upgrade notification emails
 */
function rancherfleet_sendUpgradeEmail($userId, $type, $data)
{
    $templates = array(
        'staging_ready' => array(
            'subject' => 'Your Odoo upgrade is in progress',
            'body' => 'Your Odoo instance is being upgraded to version {targetVersion}. A staging environment is available at {stagingUrl} for your review. We will contact you to schedule the final maintenance window.',
        ),
        'upgrade_complete' => array(
            'subject' => 'Your Odoo upgrade is complete',
            'body' => 'Your Odoo instance has been successfully upgraded to version {targetVersion}. Please log in and verify your data. If you notice any issues, please contact support immediately.',
        ),
        'upgrade_rollback' => array(
            'subject' => 'Your Odoo upgrade was rolled back',
            'body' => 'An issue was detected with your Odoo {targetVersion} upgrade. We have rolled back to your previous version. Our support team will investigate and contact you shortly.',
        ),
    );

    if (!isset($templates[$type])) return;

    $tpl = $templates[$type];
    $body = $tpl['body'];
    foreach ($data as $k => $v) {
        $body = str_replace('{' . $k . '}', $v, $body);
    }

    send_client_email($userId, $tpl['subject'], $body);
    RancherFleet\Logger::info("Upgrade email sent: type={$type}, user={$userId}");
}


/**
 * Admin button handler to create a staging environment for version upgrade.
 * Creates staging namespace, database, branch, and GitRepo.
 */
function rancherfleet_CreateStagingUpgrade(array $params)
{
    RancherFleet\Logger::info("CreateStagingUpgrade: starting");

    try {
        $orderNum = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $stagingNamespace = $namespace . '-staging';
        $serviceId = (int)$params['serviceid'];
        $clientId = (int)$params['userid'];

        // Read raw DB value BEFORE parsing
        $raw = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_upgrade')
            ->where('setting', 'request_' . $serviceId)
            ->value('value');
        RancherFleet\Logger::info("CreateStagingUpgrade: raw DB value: " . $raw);

        // Read pending upgrade request
        $request = rancherfleet_getUpgradeRequest($serviceId);
        if (empty($request)) {
            return 'No pending upgrade request for this service.';
        }

        RancherFleet\Logger::info("CreateStagingUpgrade: request details: " . json_encode($request));

        // Validate request status — only proceed if invoice has been paid
        $requestStatus = isset($request['status']) ? $request['status'] : 'unknown';
        $invoiceId = isset($request['invoiceId']) ? $request['invoiceId'] : null;

        if ($requestStatus === 'awaiting_payment') {
            return 'Error: Payment not yet received. Ask the client to pay invoice #' . $invoiceId . ' first.';
        }

        if (!in_array($requestStatus, array('pending', 'staging_in_progress'))) {
            return 'Error: Request status is ' . $requestStatus . ', cannot proceed. Use "Reset Staging" button to retry or contact support.';
        }

        $targetVersion = $request['version'];
        $fee = $request['fee'];

        if (!$invoiceId) {
            return 'Error: Request has no invoiceId. Please contact support.';
        }

        RancherFleet\Logger::info("CreateStagingUpgrade: proceeding with staging, version={$targetVersion}, fee={$fee}, invoice={$invoiceId} (already paid via invoice)");

        list($rancher, $github, $fleet, $cfg) = rancherfleet_buildClients($params);

        // Get current version
        $deploymentStatus = $rancher->getDeploymentStatus($namespace, 'odoo-' . $orderNum);
        if ($deploymentStatus['image'] && preg_match('/odoo:([0-9.]+)/', $deploymentStatus['image'], $m)) {
            $currentVersion = $m[1];
        } else {
            return 'Error: Could not determine current Odoo version.';
        }

        // Update status IMMEDIATELY to mark staging as in progress
        \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_upgrade')
            ->where('setting', 'request_' . $serviceId)
            ->update(array('value' => json_encode(array_merge($request, array(
                'status'    => 'staging_in_progress',
            )))));
        RancherFleet\Logger::info("CreateStagingUpgrade: status updated to staging_in_progress");

        // 2. Create staging namespace (backup runs automatically via sidecar)
        // On retry, namespace may already exist — silently ignore 409 Conflict
        RancherFleet\Logger::info("CreateStagingUpgrade: creating staging namespace");
        try {
            $rancher->createNamespace($stagingNamespace);
            sleep(2);
        } catch (RancherFleet\RancherApiException $nsEx) {
            if ($nsEx->getHttpCode() === 409) {
                RancherFleet\Logger::info("CreateStagingUpgrade: namespace {$stagingNamespace} already exists, proceeding");
            } else {
                RancherFleet\Logger::error("CreateStagingUpgrade: namespace creation failed: " . $nsEx->getMessage());
                return 'Error: Failed to create staging namespace: ' . $nsEx->getMessage();
            }
        } catch (\Exception $nsEx) {
            RancherFleet\Logger::error("CreateStagingUpgrade: namespace creation failed: " . $nsEx->getMessage());
            return 'Error: Failed to create staging namespace: ' . $nsEx->getMessage();
        }

        // 3. Run OpenUpgrade Job to migrate database
        // OpenUpgrade handles sequential version migrations: 16→17, 17→18, 18→19, etc.
        // Job runs in rfm-upgrade namespace and creates odoo-{orderNum}-staging database
        RancherFleet\Logger::info("CreateStagingUpgrade: running OpenUpgrade migration Job");
        try {
            $dbName = 'odoo-' . $orderNum;
            $dbSecretName = 'rfm-db-admin-' . $orderNum;
            $timestamp = time();
            $jobName = 'openupgrade-' . $orderNum . '-' . $timestamp;

            // Get current Odoo version from production deployment
            $currentVersion = null;
            $prodStatus = $rancher->getDeploymentStatus($namespace, 'odoo-' . $orderNum);
            if ($prodStatus['image'] && preg_match('/odoo:([0-9.]+)/', $prodStatus['image'], $m)) {
                $currentVersion = $m[1];
            }

            if (!$currentVersion) {
                $rancher->deleteNamespace($stagingNamespace);
                return 'Error: Could not determine current Odoo version from production deployment';
            }

            // Extract major version numbers for FROM and TO
            $currentMajor = (int)explode('.', $currentVersion)[0];
            $targetMajor = (int)explode('.', $targetVersion)[0];

            if ($targetMajor <= $currentMajor) {
                $rancher->deleteNamespace($stagingNamespace);
                return 'Error: Target version must be higher than current version';
            }

            RancherFleet\Logger::info("CreateStagingUpgrade: OpenUpgrade from {$currentMajor} to {$targetMajor}");

            $jobSpec = array(
                'apiVersion' => 'batch/v1',
                'kind'       => 'Job',
                'metadata'   => array('name' => $jobName, 'namespace' => 'rfm-upgrade'),
                'spec'       => array(
                    'ttlSecondsAfterFinished' => 3600,
                    'backoffLimit'            => 0,
                    'template' => array(
                        'spec' => array(
                            'restartPolicy' => 'Never',
                            'serviceAccountName' => 'openupgrade-runner',
                            'volumes' => array(
                                array(
                                    'name' => 'odoo-filestore',
                                    'persistentVolumeClaim' => array(
                                        'claimName' => 'odoo-' . $orderNum,
                                        'readOnly'  => false,
                                    ),
                                ),
                                array(
                                    'name' => 'upgrade-scripts',
                                    'configMap' => array(
                                        'name' => 'openupgrade-scripts',
                                        'defaultMode' => 0755,
                                    ),
                                ),
                            ),
                            'containers' => array(
                                array(
                                    'name'  => 'openupgrade',
                                    'image' => 'ikus060/openupgrade:' . $targetMajor,
                                    'command' => array('/scripts/run-upgrade.sh'),
                                    'env' => array(
                                        array('name' => 'SOURCE_DB', 'value' => $dbName),
                                        array('name' => 'TARGET_DB', 'value' => $dbName . '-staging'),
                                        array('name' => 'FROM_VERSION', 'value' => (string)$currentMajor),
                                        array('name' => 'TO_VERSION', 'value' => (string)$targetMajor),
                                        array('name' => 'PG_HOST', 'value' => 'postgres16.default.svc.cluster.local'),
                                        array(
                                            'name' => 'PG_USER',
                                            'valueFrom' => array(
                                                'secretKeyRef' => array(
                                                    'name' => $dbSecretName,
                                                    'namespace' => 'default',
                                                    'key' => 'username',
                                                    'optional' => true,
                                                ),
                                            ),
                                        ),
                                        array(
                                            'name' => 'PGPASSWORD',
                                            'valueFrom' => array(
                                                'secretKeyRef' => array(
                                                    'name' => $dbSecretName,
                                                    'namespace' => 'default',
                                                    'key' => 'password',
                                                    'optional' => true,
                                                ),
                                            ),
                                        ),
                                    ),
                                    'volumeMounts' => array(
                                        array(
                                            'name'      => 'odoo-filestore',
                                            'mountPath' => '/var/lib/odoo',
                                            'subPath'   => 'odoo',
                                        ),
                                        array(
                                            'name'      => 'upgrade-scripts',
                                            'mountPath' => '/scripts',
                                        ),
                                    ),
                                    'resources' => array(
                                        'requests' => array(
                                            'cpu'    => '500m',
                                            'memory' => '1Gi',
                                        ),
                                        'limits' => array(
                                            'cpu'    => '2',
                                            'memory' => '4Gi',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );

            RancherFleet\Logger::info("CreateStagingUpgrade: OpenUpgrade Job spec = " . json_encode($jobSpec));

            $response = $rancher->rawRequest('POST',
                '/apis/batch/v1/namespaces/rfm-upgrade/jobs',
                $jobSpec
            );

            RancherFleet\Logger::info("CreateStagingUpgrade: OpenUpgrade Job creation response = " . json_encode($response));

            // Poll for completion — up to 3600 seconds (1 hour)
            // OpenUpgrade can take a long time, so log every 60 seconds
            $jobPath = '/apis/batch/v1/namespaces/rfm-upgrade/jobs/' . rawurlencode($jobName);
            $succeeded = false;
            $failed = false;
            $lastLogTime = time();
            $pollCount = 0;
            $maxPolls = 720;  // 3600 seconds / 5 second interval

            for ($i = 0; $i < $maxPolls; $i++) {
                sleep(5);
                $pollCount++;

                // Log progress every 60 seconds
                if (time() - $lastLogTime >= 60) {
                    $elapsedMin = ($pollCount * 5) / 60;
                    RancherFleet\Logger::info("CreateStagingUpgrade: OpenUpgrade Job running... {$elapsedMin} minutes elapsed");
                    $lastLogTime = time();
                }

                try {
                    $job = $rancher->rawRequest('GET', $jobPath);
                    $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                    foreach ($conditions as $cond) {
                        if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                            $succeeded = true; break 2;
                        }
                        if (isset($cond['type']) && $cond['type'] === 'Failed' && $cond['status'] === 'True') {
                            $failed = true; break 2;
                        }
                    }
                } catch (\Exception $pollEx) {
                    RancherFleet\Logger::error("CreateStagingUpgrade: error polling OpenUpgrade Job: " . $pollEx->getMessage());
                    break;
                }
            }

            // Clean up the Job
            try {
                $rancher->rawRequest('DELETE', $jobPath);
            } catch (\Exception $e) {
                RancherFleet\Logger::info("CreateStagingUpgrade: OpenUpgrade Job cleanup note: " . $e->getMessage());
            }

            if (!$succeeded) {
                $rancher->deleteNamespace($stagingNamespace);
                $errMsg = $failed ? 'OpenUpgrade Job failed' : 'OpenUpgrade Job timed out after 3600s';
                RancherFleet\Logger::error("CreateStagingUpgrade: OpenUpgrade migration failed — {$errMsg}");
                return 'Error: Failed to migrate database with OpenUpgrade: ' . $errMsg;
            }

            RancherFleet\Logger::info("CreateStagingUpgrade: OpenUpgrade migration completed successfully");

            // 3b. Rename database from odoo-{orderNum}_{targetVersion} to odoo-{orderNum}-staging
            $upgradedDbName = 'odoo-' . $orderNum . '_' . $targetMajor;
            $stagingDbName = 'odoo-' . $orderNum . '-staging';

            RancherFleet\Logger::info("CreateStagingUpgrade: renaming database from {$upgradedDbName} to {$stagingDbName}");

            try {
                $dbSecretName = 'rfm-db-admin-' . $orderNum;
                $renameJobSpec = array(
                    'apiVersion' => 'batch/v1',
                    'kind'       => 'Job',
                    'metadata'   => array('name' => 'renamedb-' . $orderNum . '-staging', 'namespace' => 'default'),
                    'spec'       => array(
                        'ttlSecondsAfterFinished' => 60,
                        'backoffLimit'            => 0,
                        'template' => array(
                            'spec' => array(
                                'restartPolicy' => 'Never',
                                'volumes' => array(
                                    array(
                                        'name' => 'db-credentials',
                                        'secret' => array('secretName' => $dbSecretName),
                                    ),
                                ),
                                'containers' => array(
                                    array(
                                        'name'  => 'postgres',
                                        'image' => 'postgres:16-alpine',
                                        'volumeMounts' => array(
                                            array(
                                                'name'      => 'db-credentials',
                                                'mountPath' => '/etc/rfm-db',
                                                'readOnly'  => true,
                                            ),
                                        ),
                                        'command' => array('sh', '-c'),
                                        'args'    => array(
                                            "DB_USER=\$(cat /etc/rfm-db/username)\n" .
                                            "DB_PASS=\$(cat /etc/rfm-db/password)\n" .
                                            "export PGPASSWORD=\"\$DB_PASS\"\n" .
                                            "\n" .
                                            "# Terminate active connections to staging database\n" .
                                            "psql -h postgres16.default.svc.cluster.local \\\n" .
                                            "  -U \"\$DB_USER\" -d postgres \\\n" .
                                            "  -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='" . $stagingDbName . "' AND pid<>pg_backend_pid();\" 2>/dev/null || true\n" .
                                            "\n" .
                                            "# Drop old staging database if it exists\n" .
                                            "dropdb -h postgres16.default.svc.cluster.local \\\n" .
                                            "  -U \"\$DB_USER\" \\\n" .
                                            "  --if-exists \"" . $stagingDbName . "\" 2>/dev/null || true\n" .
                                            "\n" .
                                            "# Rename upgraded database to staging name\n" .
                                            "psql -h postgres16.default.svc.cluster.local \\\n" .
                                            "  -U \"\$DB_USER\" -d postgres \\\n" .
                                            "  -c \"ALTER DATABASE \\\"" . $upgradedDbName . "\\\" RENAME TO \\\"" . $stagingDbName . "\\\";\"\n" .
                                            "\n" .
                                            "echo \"Database renamed successfully\""
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                );

                RancherFleet\Logger::info("CreateStagingUpgrade: database rename Job spec = " . json_encode($renameJobSpec));

                $renameJobPath = '/api/v1/namespaces/default/jobs';
                $response = $rancher->rawRequest('POST', $renameJobPath, $renameJobSpec);

                RancherFleet\Logger::info("CreateStagingUpgrade: database rename Job creation response = " . json_encode($response));

                // Poll for rename completion — up to 120 seconds
                $renameJobName = 'renamedb-' . $orderNum . '-staging';
                $renameJobPath = '/apis/batch/v1/namespaces/default/jobs/' . rawurlencode($renameJobName);
                $renameSucceeded = false;
                $renameFailed = false;

                for ($i = 0; $i < 24; $i++) {
                    sleep(5);
                    try {
                        $job = $rancher->rawRequest('GET', $renameJobPath);
                        $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                        foreach ($conditions as $cond) {
                            if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                                $renameSucceeded = true; break 2;
                            }
                            if (isset($cond['type']) && $cond['type'] === 'Failed' && $cond['status'] === 'True') {
                                $renameFailed = true; break 2;
                            }
                        }
                        RancherFleet\Logger::info("CreateStagingUpgrade: waiting for database rename Job... " . (($i + 1) * 5) . "s elapsed");
                    } catch (\Exception $pollEx) {
                        RancherFleet\Logger::error("CreateStagingUpgrade: error polling rename Job: " . $pollEx->getMessage());
                        break;
                    }
                }

                // Clean up the rename Job
                try {
                    $rancher->rawRequest('DELETE', $renameJobPath);
                } catch (\Exception $e) {
                    RancherFleet\Logger::info("CreateStagingUpgrade: rename Job cleanup note: " . $e->getMessage());
                }

                if (!$renameSucceeded) {
                    $rancher->deleteNamespace($stagingNamespace);
                    $errMsg = $renameFailed ? 'Rename Job failed' : 'Rename Job timed out after 120s';
                    RancherFleet\Logger::error("CreateStagingUpgrade: database rename failed — {$errMsg}");
                    return 'Error: Failed to rename staging database: ' . $errMsg;
                }

                RancherFleet\Logger::info("CreateStagingUpgrade: database renamed successfully to {$stagingDbName}");
            } catch (\Exception $renameEx) {
                RancherFleet\Logger::error("CreateStagingUpgrade: database rename failed: " . $renameEx->getMessage());
                $rancher->deleteNamespace($stagingNamespace);
                return 'Error: Failed to rename staging database: ' . $renameEx->getMessage();
            }
        } catch (\Exception $dbEx) {
            RancherFleet\Logger::error("CreateStagingUpgrade: OpenUpgrade migration failed: " . $dbEx->getMessage());
            $rancher->deleteNamespace($stagingNamespace);
            return 'Error: Failed to run OpenUpgrade migration: ' . $dbEx->getMessage();
        }

        // 4. Clone client branch to staging branch
        RancherFleet\Logger::info("CreateStagingUpgrade: cloning branch");
        try {
            $clientBranch = 'whmcs-client-' . $orderNum;
            $stagingBranch = $clientBranch . '-staging';
            $github->createBranchFrom($stagingBranch, $clientBranch);
        } catch (\Exception $branchEx) {
            RancherFleet\Logger::error("CreateStagingUpgrade: branch clone failed: " . $branchEx->getMessage());
            $rancher->deleteNamespace($stagingNamespace);
            return 'Error: Failed to clone branch: ' . $branchEx->getMessage();
        }

        // 5. Update staging branch manifests
        RancherFleet\Logger::info("CreateStagingUpgrade: updating staging manifests");
        try {
            $stagingBranch = 'whmcs-client-' . $orderNum . '-staging';

            // Get current odoo.yml from client branch
            $content = $github->getClientFileContent($namespace, 'odoo.yml');
            if (empty($content)) {
                throw new \Exception("odoo.yml not found in client branch {$clientBranch}");
            }

            // Replacement order is critical to avoid corrupting values:
            // 1. Image version first (safest, no overlap with other values)
            // 2. Ingress hostname (before database name to avoid '0000' overlap)
            // 3. Database name (orderNum appears in both DB names and hostnames)
            // 4. Namespace references last

            $updated = $content;

            // 1. Replace image version
            $updated = str_replace('image: odoo:' . $currentVersion, 'image: odoo:' . $targetVersion, $updated);

            // 2. Replace ingress hostname: {orderNum}.webdiscode.com -> staging-{orderNum}.webdiscode.com
            // This matches HTTPS Ingress rules, TLS hosts, and HTTP redirect rules
            $updated = str_replace(
                $orderNum . '.webdiscode.com',
                'staging-' . $orderNum . '.webdiscode.com',
                $updated
            );

            // 3. Replace database name: odoo-{orderNum} -> odoo-{orderNum}-staging
            $updated = str_replace('odoo-' . $orderNum, 'odoo-' . $orderNum . '-staging', $updated);

            // 4. Replace namespace references: whmcs-client-{orderNum} -> whmcs-client-{orderNum}-staging
            $updated = str_replace('whmcs-client-' . $orderNum, $stagingNamespace, $updated);

            $github->writeFileToBranch(
                'odoo.yml',
                $updated,
                $stagingBranch,
                'Staging environment for Odoo upgrade to ' . $targetVersion
            );

            RancherFleet\Logger::info("CreateStagingUpgrade: manifests updated");
        } catch (\Exception $manifestEx) {
            RancherFleet\Logger::error("CreateStagingUpgrade: manifest update failed: " . $manifestEx->getMessage());
            $rancher->deleteNamespace($stagingNamespace);
            $github->deleteBranch($stagingBranch);
            return 'Error: Failed to update staging manifests: ' . $manifestEx->getMessage();
        }

        // 6. Create staging Fleet GitRepo
        RancherFleet\Logger::info("CreateStagingUpgrade: creating staging GitRepo");
        try {
            // FleetHelper.createGitRepo() automatically uses the correct cluster name
            // and Fleet namespace configuration — we just pass the namespace name
            $fleet->createGitRepo($stagingNamespace, $stagingNamespace);
            sleep(5);

            RancherFleet\Logger::info("CreateStagingUpgrade: staging GitRepo created");
        } catch (\Exception $gitRepoEx) {
            RancherFleet\Logger::error("CreateStagingUpgrade: GitRepo creation failed: " . $gitRepoEx->getMessage());
            $rancher->deleteNamespace($stagingNamespace);
            $github->deleteBranch('whmcs-client-' . $orderNum . '-staging');
            return 'Error: Failed to create staging GitRepo: ' . $gitRepoEx->getMessage();
        }

        // 7. Update request record
        $stagingUrl = 'https://staging-' . $orderNum . '.webdiscode.com';
        try {
            $data = array(
                'version'            => $targetVersion,
                'fee'                => $fee,
                'requested_at'       => $request['requested_at'],
                'status'             => 'staging',
                'staging_url'        => $stagingUrl,
                'staging_shared'     => false,
                'invoiceId'          => $invoiceId,
                'staging_created_at' => time(),
            );
            RancherFleet\Logger::info("CreateStagingUpgrade: writing request record, status=staging, invoiceId={$invoiceId}");
            \WHMCS\Database\Capsule::table('tbladdonmodules')->updateOrInsert(
                array('module' => 'rancherfleet_upgrade', 'setting' => 'request_' . $serviceId),
                array('value' => json_encode($data))
            );
        } catch (\Exception $e) {
            RancherFleet\Logger::error("CreateStagingUpgrade: failed to update request: " . $e->getMessage());
        }

        // 9. Store cleanup job
        try {
            $cleanupData = array(
                'namespace'      => $stagingNamespace,
                'branch'         => 'whmcs-client-' . $orderNum . '-staging',
                'gitrepo'        => 'gitrepo-' . $stagingNamespace,
                'db'             => 'odoo-' . $orderNum . '-staging',
                'cleanup_at'     => time() + 172800,  // 48 hours
            );
            \WHMCS\Database\Capsule::table('tbladdonmodules')->updateOrInsert(
                array('module' => 'rancherfleet_upgrade_cleanup', 'setting' => 'cleanup_' . $serviceId),
                array('value' => json_encode($cleanupData))
            );
        } catch (\Exception $e) {
            RancherFleet\Logger::error("CreateStagingUpgrade: failed to store cleanup job: " . $e->getMessage());
        }

        rancherfleet_logHistory($params, 'Staging Upgrade Created', 'Staging environment created for Odoo upgrade to ' . $targetVersion . ' at ' . $stagingUrl);

        $successMsg = "Success: Staging environment created and database migrated to Odoo {$targetVersion}.\n\n" .
                     "Staging namespace: whmcs-client-{$orderNum}-staging\n" .
                     "Staging URL: {$stagingUrl}\n" .
                     "Staging database: odoo-{$orderNum}-staging on postgres16.default.svc.cluster.local\n\n" .
                     "The OpenUpgrade migration ran sequentially through each major version.\n" .
                     "The staging deployment uses the production instance configuration.\n\n" .
                     "Next steps:\n" .
                     "1. Visit {$stagingUrl} and verify the migrated instance is accessible\n" .
                     "2. Test functionality and data integrity\n" .
                     "3. Click 'Trigger Live Upgrade' to cutover to production\n\n" .
                     "Note: Live upgrade will scale production to 0, update the image tag, and scale back to 1.";

        return $successMsg;

    } catch (\Exception $e) {
        RancherFleet\Logger::error("CreateStagingUpgrade: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}


/**
 * Executes the actual version upgrade:
 * 1. Charge credit
 * 2. Take backup
 * 3. Scale to 0
 * 4. Update odoo.yml with new version
 * 5. Scale to 1
 * 6. Record history
 *
 * @return string  'Success:...' or 'Error:...'
 */
function rancherfleet_executeVersionUpgrade(array $params, $namespace, $orderNum, $targetVersion, $fee)
{
    try {
        list($rancher, $github) = rancherfleet_buildClients($params);
        $clientId = (int)$params['userid'];
        $serviceId = (int)$params['serviceid'];

        // Get current version
        $status = $rancher->getDeploymentStatus($namespace, 'odoo-' . $orderNum);
        $currentVersion = null;
        if ($status['image'] && preg_match('/odoo:([0-9.]+)/', $status['image'], $m)) {
            $currentVersion = $m[1];
        }

        if (!$currentVersion) {
            return 'Error: Could not determine current Odoo version.';
        }

        RancherFleet\Logger::info("executeVersionUpgrade: from {$currentVersion} to {$targetVersion}");

        // 1. Charge credit
        RancherFleet\Logger::info("executeVersionUpgrade: charging ${fee}");
        try {
            $invoiceResult = localAPI('CreateInvoice', array(
                'userid'           => $clientId,
                'status'           => 'Unpaid',
                'itemdescription1' => "Odoo version upgrade from {$currentVersion} to {$targetVersion}",
                'itemamount1'      => $fee,
                'itemtaxed1'       => false,
                'paymentmethod'    => '',
            ));

            if (!isset($invoiceResult['result']) || $invoiceResult['result'] !== 'success') {
                $err = isset($invoiceResult['message']) ? $invoiceResult['message'] : json_encode($invoiceResult);
                return 'Error: Could not create invoice: ' . $err;
            }

            $invoiceId = (int)$invoiceResult['invoiceid'];

            $creditResult = localAPI('ApplyCredit', array(
                'clientid' => $clientId,
                'amount'   => $fee,
            ));

            if (!isset($creditResult['result']) || $creditResult['result'] !== 'success') {
                $err = isset($creditResult['message']) ? $creditResult['message'] : json_encode($creditResult);
                return 'Error: Could not apply credit: ' . $err;
            }

            RancherFleet\Logger::info("executeVersionUpgrade: credit charged, invoice {$invoiceId}");
        } catch (\Exception $payEx) {
            RancherFleet\Logger::error("executeVersionUpgrade: payment error: " . $payEx->getMessage());
            return 'Error: Payment processing failed: ' . $payEx->getMessage();
        }

        // 2. Take backup
        RancherFleet\Logger::info("executeVersionUpgrade: taking pre-upgrade backup");
        try {
            $backupResult = rancherfleet_handleBackupRestore($params, $namespace, $orderNum, '', 'db', true);
            if (strpos($backupResult, 'error') !== false) {
                RancherFleet\Logger::error("executeVersionUpgrade: backup failed, refunding");
                localAPI('AddCredit', array('clientid' => $clientId, 'amount' => $fee));
                return 'Error: Backup failed during pre-upgrade snapshot. Credit refunded.';
            }
        } catch (\Exception $backEx) {
            RancherFleet\Logger::error("executeVersionUpgrade: backup exception: " . $backEx->getMessage());
            localAPI('AddCredit', array('clientid' => $clientId, 'amount' => $fee));
            return 'Error: ' . $backEx->getMessage() . ' Credit refunded.';
        }

        // 3. Scale deployment to 0
        RancherFleet\Logger::info("executeVersionUpgrade: scaling to 0");
        try {
            $rancher->scaleDeployment($namespace, 'odoo-' . $orderNum, 0);
            sleep(3);
        } catch (\Exception $scaleEx) {
            RancherFleet\Logger::error("executeVersionUpgrade: scale error: " . $scaleEx->getMessage());
            localAPI('AddCredit', array('clientid' => $clientId, 'amount' => $fee));
            return 'Error: Failed to scale deployment down. ' . $scaleEx->getMessage() . ' Credit refunded.';
        }

        // 4. Update odoo.yml with new version
        RancherFleet\Logger::info("executeVersionUpgrade: updating odoo.yml");
        try {
            $branch = 'whmcs-client-' . $orderNum;
            $content = $github->getFileContent($branch, 'odoo.yml');

            $oldImage = 'image: odoo:' . preg_quote($currentVersion);
            $newImage = 'image: odoo:' . $targetVersion;

            if (!preg_match('/' . $oldImage . '/', $content)) {
                throw new \Exception('Current image tag not found in odoo.yml');
            }

            $updated = preg_replace('/' . $oldImage . '/', $newImage, $content);

            $github->createOrUpdateFile(
                $branch,
                'odoo.yml',
                $updated,
                'Upgrade Odoo from ' . $currentVersion . ' to ' . $targetVersion
            );

            RancherFleet\Logger::info("executeVersionUpgrade: odoo.yml updated");
            sleep(5);
        } catch (\Exception $updateEx) {
            RancherFleet\Logger::error("executeVersionUpgrade: update error: " . $updateEx->getMessage());
            $rancher->scaleDeployment($namespace, 'odoo-' . $orderNum, 1);
            localAPI('AddCredit', array('clientid' => $clientId, 'amount' => $fee));
            return 'Error: Failed to update deployment. ' . $updateEx->getMessage() . ' Credit refunded and deployment restored.';
        }

        // 5. Scale deployment back to 1
        RancherFleet\Logger::info("executeVersionUpgrade: scaling back to 1");
        try {
            $rancher->scaleDeployment($namespace, 'odoo-' . $orderNum, 1);
            sleep(3);
        } catch (\Exception $scaleBackEx) {
            RancherFleet\Logger::error("executeVersionUpgrade: scale back error: " . $scaleBackEx->getMessage());
            return 'Warning: Deployment scaled back but may need manual intervention. ' . $scaleBackEx->getMessage();
        }

        // 6. Record in history
        $invoiceId = isset($invoiceId) ? $invoiceId : 0;
        rancherfleet_logHistory($params, 'Odoo Upgraded', 'Upgraded from ' . $currentVersion . ' to ' . $targetVersion . ' (Invoice #' . $invoiceId . ', $' . number_format($fee, 2) . ')');

        RancherFleet\Logger::info("executeVersionUpgrade: SUCCESS");
        return 'Success: Odoo upgraded from ' . $currentVersion . ' to ' . $targetVersion . '. Invoice #' . $invoiceId . ' charged $' . number_format($fee, 2) . ' from credit balance.';

    } catch (\Exception $e) {
        RancherFleet\Logger::error("executeVersionUpgrade: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}


/**
 * Admin button handler to share staging URL with client.
 */
function rancherfleet_ShareStagingUrl(array $params)
{
    RancherFleet\Logger::info("ShareStagingUrl: starting");

    try {
        $serviceId = (int)$params['serviceid'];
        $clientId = (int)$params['userid'];

        // Read upgrade request
        $request = rancherfleet_getUpgradeRequest($serviceId);
        if (empty($request) || $request['status'] !== 'staging') {
            return 'No staging upgrade in progress for this service.';
        }

        $stagingUrl = isset($request['staging_url']) ? $request['staging_url'] : null;
        if (!$stagingUrl) {
            return 'Error: Staging URL not found in request.';
        }

        // Send email to client
        try {
            sendEmail('client_upgrade_staging_ready', $clientId, array(
                'staging_url' => $stagingUrl,
            ));
        } catch (\Exception $emailEx) {
            RancherFleet\Logger::error("ShareStagingUrl: email error: " . $emailEx->getMessage());
        }

        // Update request
        $request['staging_shared'] = true;
        RancherFleet\Logger::info("ShareStagingUrl: writing request record, staging_shared=true");
        \WHMCS\Database\Capsule::table('tbladdonmodules')->updateOrInsert(
            array('module' => 'rancherfleet_upgrade', 'setting' => 'request_' . $serviceId),
            array('value' => json_encode($request))
        );

        rancherfleet_logHistory($params, 'Staging URL Shared', 'Staging URL ' . $stagingUrl . ' shared with client');

        return 'Success: Staging URL shared with client at ' . $stagingUrl;

    } catch (\Exception $e) {
        RancherFleet\Logger::error("ShareStagingUrl: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}


/**
 * Admin-initiated staging upgrade (no payment required).
 * Creates staging from Module Settings target version.
 */
function rancherfleet_CreateStagingOverride(array $params)
{
    RancherFleet\Logger::info("CreateStagingOverride: starting");

    try {
        $orderNum = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $serviceId = (int)$params['serviceid'];

        // Get target version from Module Settings
        $targetVersion = $params['configoption15'] ?? '';
        if (empty($targetVersion)) {
            return 'Error: No target version configured in Module Settings (configoption15).';
        }

        $targetMajor = (int)explode('.', $targetVersion)[0];

        // Get current version
        $deployment = $params['configoptions']['Current Version'] ?? null;
        if (!$deployment) {
            list($rancher) = rancherfleet_buildClients($params);
            $dep = $rancher->getDeployment($namespace, 'odoo');
            $currentVersion = $dep['spec']['template']['spec']['containers'][0]['image'] ?? 'odoo:16';
            $deployment = $currentVersion;
        }
        $currentVersion = str_replace('odoo:', '', $deployment);
        $currentMajor = (int)explode('.', $currentVersion)[0];

        if ($targetMajor <= $currentMajor) {
            return 'Error: Target version must be higher than current version.';
        }

        if ($currentMajor < 16) {
            return 'Error: Direct upgrade from Odoo ' . $currentMajor . ' is not currently supported. The earliest supported source version is Odoo 16 upgrading to Odoo 17.';
        }

        $supportedVersions = array(17, 18);
        if (!in_array($targetMajor, $supportedVersions)) {
            return 'Error: Odoo ' . $targetMajor . ' does not have an available OpenUpgrade Docker image. Currently supported: ' . implode(', ', $supportedVersions);
        }

        RancherFleet\Logger::info("CreateStagingOverride: admin upgrade from {$currentMajor} to {$targetMajor}");

        // Reuse CreateStagingUpgrade logic but with admin_override flag
        // This marks the upgrade request with admin_override = true so it doesn't require payment
        $upgradeRequest = array(
            'status'         => 'pending',
            'version'        => $targetVersion,
            'fromVersion'    => $currentVersion,
            'admin_override' => true,
            'requested_at'   => time(),
            'invoiceId'      => 0,
        );

        \WHMCS\Database\Capsule::table('tbladdonmodules')->updateOrInsert(
            array('module' => 'rancherfleet_upgrade', 'setting' => 'request_' . $serviceId),
            array('value' => json_encode($upgradeRequest))
        );

        RancherFleet\Logger::info("CreateStagingOverride: upgrade request created with admin_override=true");

        // Now call the actual staging creation (similar to CreateStagingUpgrade)
        // This will use the same Job-based approach
        return 'Success: Admin override upgrade initiated. Follow with "Create Staging Upgrade" button to run OpenUpgrade migration.';

    } catch (\Exception $e) {
        RancherFleet\Logger::error("CreateStagingOverride: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}


/**
 * Trigger live upgrade using Option D maintenance window approach.
 * Scales down, takes fresh dump, runs OpenUpgrade, atomically switches DB.
 */
function rancherfleet_TriggerLiveUpgrade(array $params)
{
    RancherFleet\Logger::info("TriggerLiveUpgrade: starting Option D maintenance window");

    try {
        $orderNum = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $serviceId = (int)$params['serviceid'];

        $request = rancherfleet_getUpgradeRequest($serviceId);
        if (empty($request) || $request['status'] !== 'staging') {
            return 'No staging upgrade ready for this service.';
        }

        $targetVersion = $request['version'];
        $targetMajor = (int)explode('.', $targetVersion)[0];
        $currentVersion = $request['fromVersion'] ?? '16.0';
        $currentMajor = (int)explode('.', $currentVersion)[0];

        RancherFleet\Logger::info("TriggerLiveUpgrade: {$namespace} {$currentMajor} -> {$targetMajor} (Option D)");

        list($rancher, $github) = rancherfleet_buildClients($params);

        // 1. Scale production to 0 (start maintenance window)
        RancherFleet\Logger::info("TriggerLiveUpgrade: entering maintenance window, scaling production to 0");
        rancherfleet_updateUpgradeStatus($serviceId, 'maintenance_window', array('maintenance_started_at' => time()));

        try {
            $rancher->scaleDeployment($namespace, 'odoo', 0);
            sleep(10);
        } catch (\Exception $e) {
            RancherFleet\Logger::error("TriggerLiveUpgrade: scale down failed: " . $e->getMessage());
            return 'Error: Failed to scale down production. ' . $e->getMessage();
        }

        $dbSecretName = 'rfm-db-admin-' . $orderNum;
        $prodDbName = 'odoo-' . $orderNum;
        $preUpgradeDb = 'odoo-' . $orderNum . '-pre-upgrade';
        $upgradedDbName = 'odoo-' . $orderNum . '_' . $targetMajor;

        // 2. Take fresh pg_dump of production database and backup current DB
        RancherFleet\Logger::info("TriggerLiveUpgrade: backing up production database");
        try {
            $backupJobSpec = array(
                'apiVersion' => 'batch/v1',
                'kind'       => 'Job',
                'metadata'   => array('name' => 'backupdb-' . $orderNum . '-upgrade', 'namespace' => 'default'),
                'spec'       => array(
                    'ttlSecondsAfterFinished' => 60,
                    'backoffLimit'            => 0,
                    'template' => array(
                        'spec' => array(
                            'restartPolicy' => 'Never',
                            'volumes' => array(
                                array(
                                    'name' => 'db-credentials',
                                    'secret' => array('secretName' => $dbSecretName),
                                ),
                            ),
                            'containers' => array(
                                array(
                                    'name'  => 'postgres',
                                    'image' => 'postgres:16-alpine',
                                    'volumeMounts' => array(
                                        array(
                                            'name'      => 'db-credentials',
                                            'mountPath' => '/etc/rfm-db',
                                            'readOnly'  => true,
                                        ),
                                    ),
                                    'command' => array('sh', '-c'),
                                    'args'    => array(
                                        "DB_USER=\$(cat /etc/rfm-db/username)\n" .
                                        "DB_PASS=\$(cat /etc/rfm-db/password)\n" .
                                        "export PGPASSWORD=\"\$DB_PASS\"\n" .
                                        "\n" .
                                        "# Terminate active connections\n" .
                                        "psql -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" -d postgres \\\n" .
                                        "  -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='" . $prodDbName . "' AND pid<>pg_backend_pid();\" 2>/dev/null || true\n" .
                                        "\n" .
                                        "# Delete old pre-upgrade backup if exists\n" .
                                        "dropdb -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" --if-exists \"" . $preUpgradeDb . "\" 2>/dev/null || true\n" .
                                        "\n" .
                                        "# Clone current database as backup (safe atomic copy)\n" .
                                        "createdb -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" \\\n" .
                                        "  --template \"" . $prodDbName . "\" \"" . $preUpgradeDb . "\"\n" .
                                        "\n" .
                                        "echo \"Database backup complete\""
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );

            $rancher->rawRequest('POST', '/apis/batch/v1/namespaces/default/jobs', $backupJobSpec);

            $backupJobName = 'backupdb-' . $orderNum . '-upgrade';
            $backupJobPath = '/apis/batch/v1/namespaces/default/jobs/' . rawurlencode($backupJobName);
            $backupSucceeded = false;

            for ($i = 0; $i < 24; $i++) {
                sleep(5);
                try {
                    $job = $rancher->rawRequest('GET', $backupJobPath);
                    $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                    foreach ($conditions as $cond) {
                        if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                            $backupSucceeded = true; break 2;
                        }
                    }
                } catch (\Exception $e) {
                    RancherFleet\Logger::error("TriggerLiveUpgrade: error polling backup Job: " . $e->getMessage());
                }
            }

            try {
                $rancher->rawRequest('DELETE', $backupJobPath);
            } catch (\Exception $e) {
                RancherFleet\Logger::info("TriggerLiveUpgrade: backup Job cleanup note: " . $e->getMessage());
            }

            if (!$backupSucceeded) {
                $rancher->scaleDeployment($namespace, 'odoo', 1);
                return 'Error: Failed to backup production database.';
            }
        } catch (\Exception $backupEx) {
            $rancher->scaleDeployment($namespace, 'odoo', 1);
            return 'Error: Database backup failed: ' . $backupEx->getMessage();
        }

        // 3. Run OpenUpgrade on production database (creates odoo-{orderNum}_{targetVersion})
        RancherFleet\Logger::info("TriggerLiveUpgrade: running OpenUpgrade on production database");
        try {
            $upgradeJobSpec = array(
                'apiVersion' => 'batch/v1',
                'kind'       => 'Job',
                'metadata'   => array('name' => 'upgrade-live-' . $orderNum, 'namespace' => 'rfm-upgrade'),
                'spec'       => array(
                    'ttlSecondsAfterFinished' => 3600,
                    'backoffLimit'            => 0,
                    'template' => array(
                        'spec' => array(
                            'restartPolicy' => 'Never',
                            'serviceAccountName' => 'openupgrade-runner',
                            'volumes' => array(
                                array(
                                    'name' => 'odoo-data',
                                    'persistentVolumeClaim' => array(
                                        'claimName' => 'odoo-' . $orderNum,
                                        'readOnly'  => false,
                                    ),
                                ),
                            ),
                            'containers' => array(
                                array(
                                    'name'  => 'openupgrade',
                                    'image' => 'ikus060/openupgrade:' . $targetMajor,
                                    'command' => array('odoo-openupgrade'),
                                    'args' => array('--db-name', $prodDbName, '--neutralize'),
                                    'env' => array(
                                        array('name' => 'HOST', 'value' => 'postgres16.default.svc.cluster.local'),
                                        array(
                                            'name' => 'USER',
                                            'valueFrom' => array(
                                                'secretKeyRef' => array(
                                                    'name' => $dbSecretName,
                                                    'namespace' => 'default',
                                                    'key' => 'username',
                                                    'optional' => true,
                                                ),
                                            ),
                                        ),
                                        array(
                                            'name' => 'PASSWORD',
                                            'valueFrom' => array(
                                                'secretKeyRef' => array(
                                                    'name' => $dbSecretName,
                                                    'namespace' => 'default',
                                                    'key' => 'password',
                                                    'optional' => true,
                                                ),
                                            ),
                                        ),
                                    ),
                                    'volumeMounts' => array(
                                        array(
                                            'name'      => 'odoo-data',
                                            'mountPath' => '/var/lib/odoo',
                                            'subPath'   => 'odoo',
                                        ),
                                    ),
                                    'resources' => array(
                                        'requests' => array(
                                            'cpu'    => '500m',
                                            'memory' => '1Gi',
                                        ),
                                        'limits' => array(
                                            'cpu'    => '2',
                                            'memory' => '4Gi',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );

            $rancher->rawRequest('POST', '/apis/batch/v1/namespaces/rfm-upgrade/jobs', $upgradeJobSpec);

            $upgradeJobName = 'upgrade-live-' . $orderNum;
            $upgradeJobPath = '/apis/batch/v1/namespaces/rfm-upgrade/jobs/' . rawurlencode($upgradeJobName);
            $upgradeSucceeded = false;
            $lastLogTime = time();
            $pollCount = 0;

            for ($i = 0; $i < 720; $i++) {  // 3600 seconds
                sleep(5);
                $pollCount++;

                if (time() - $lastLogTime >= 60) {
                    $elapsedMin = ($pollCount * 5) / 60;
                    RancherFleet\Logger::info("TriggerLiveUpgrade: OpenUpgrade running... {$elapsedMin} minutes elapsed");
                    $lastLogTime = time();
                }

                try {
                    $job = $rancher->rawRequest('GET', $upgradeJobPath);
                    $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                    foreach ($conditions as $cond) {
                        if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                            $upgradeSucceeded = true; break 2;
                        }
                    }
                } catch (\Exception $e) {
                    RancherFleet\Logger::error("TriggerLiveUpgrade: error polling upgrade Job: " . $e->getMessage());
                }
            }

            try {
                $rancher->rawRequest('DELETE', $upgradeJobPath);
            } catch (\Exception $e) {
                RancherFleet\Logger::info("TriggerLiveUpgrade: upgrade Job cleanup note: " . $e->getMessage());
            }

            if (!$upgradeSucceeded) {
                $rancher->scaleDeployment($namespace, 'odoo', 1);
                return 'Error: OpenUpgrade Job failed or timed out during maintenance window.';
            }
        } catch (\Exception $upgradeEx) {
            $rancher->scaleDeployment($namespace, 'odoo', 1);
            return 'Error: OpenUpgrade failed: ' . $upgradeEx->getMessage();
        }

        // 4. Rename upgraded database to production
        RancherFleet\Logger::info("TriggerLiveUpgrade: renaming upgraded database to production");
        try {
            $renameJobSpec = array(
                'apiVersion' => 'batch/v1',
                'kind'       => 'Job',
                'metadata'   => array('name' => 'renamedb-live-' . $orderNum, 'namespace' => 'default'),
                'spec'       => array(
                    'ttlSecondsAfterFinished' => 60,
                    'backoffLimit'            => 0,
                    'template' => array(
                        'spec' => array(
                            'restartPolicy' => 'Never',
                            'volumes' => array(
                                array(
                                    'name' => 'db-credentials',
                                    'secret' => array('secretName' => $dbSecretName),
                                ),
                            ),
                            'containers' => array(
                                array(
                                    'name'  => 'postgres',
                                    'image' => 'postgres:16-alpine',
                                    'volumeMounts' => array(
                                        array(
                                            'name'      => 'db-credentials',
                                            'mountPath' => '/etc/rfm-db',
                                            'readOnly'  => true,
                                        ),
                                    ),
                                    'command' => array('sh', '-c'),
                                    'args'    => array(
                                        "DB_USER=\$(cat /etc/rfm-db/username)\n" .
                                        "DB_PASS=\$(cat /etc/rfm-db/password)\n" .
                                        "export PGPASSWORD=\"\$DB_PASS\"\n" .
                                        "\n" .
                                        "# Atomic swap: old prod -> deleted, upgraded -> prod\n" .
                                        "psql -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" -d postgres \\\n" .
                                        "  -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='" . $prodDbName . "' AND pid<>pg_backend_pid();\" 2>/dev/null || true\n" .
                                        "\n" .
                                        "dropdb -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" --if-exists \"" . $prodDbName . "\" 2>/dev/null || true\n" .
                                        "\n" .
                                        "psql -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" -d postgres \\\n" .
                                        "  -c \"ALTER DATABASE \\\"" . $upgradedDbName . "\\\" RENAME TO \\\"" . $prodDbName . "\\\";\"\n" .
                                        "\n" .
                                        "echo \"Database switch complete\""
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );

            $rancher->rawRequest('POST', '/apis/batch/v1/namespaces/default/jobs', $renameJobSpec);

            $renameJobName = 'renamedb-live-' . $orderNum;
            $renameJobPath = '/apis/batch/v1/namespaces/default/jobs/' . rawurlencode($renameJobName);
            $renameSucceeded = false;

            for ($i = 0; $i < 24; $i++) {
                sleep(5);
                try {
                    $job = $rancher->rawRequest('GET', $renameJobPath);
                    $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                    foreach ($conditions as $cond) {
                        if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                            $renameSucceeded = true; break 2;
                        }
                    }
                } catch (\Exception $e) {
                    RancherFleet\Logger::error("TriggerLiveUpgrade: error polling rename Job: " . $e->getMessage());
                }
            }

            try {
                $rancher->rawRequest('DELETE', $renameJobPath);
            } catch (\Exception $e) {
                RancherFleet\Logger::info("TriggerLiveUpgrade: rename Job cleanup note: " . $e->getMessage());
            }

            if (!$renameSucceeded) {
                $rancher->scaleDeployment($namespace, 'odoo', 1);
                return 'Error: Failed to switch production database to upgraded version.';
            }
        } catch (\Exception $renameEx) {
            $rancher->scaleDeployment($namespace, 'odoo', 1);
            return 'Error: Database rename failed: ' . $renameEx->getMessage();
        }

        // 5. Update image tag
        RancherFleet\Logger::info("TriggerLiveUpgrade: updating image tag to {$targetVersion}");
        try {
            $branch = 'whmcs-client-' . $orderNum;
            $content = $github->getFileContent($branch, 'odoo.yml');

            $updated = str_replace('image: odoo:' . $currentVersion, 'image: odoo:' . $targetVersion, $content);

            if ($updated === $content) {
                RancherFleet\Logger::warn("TriggerLiveUpgrade: image tag replacement not found, attempting odoo:{currentVersion} pattern");
                $updated = preg_replace('/image:\s*odoo:[0-9.]+/', 'image: odoo:' . $targetVersion, $content);
            }

            $github->createOrUpdateFile(
                $branch,
                'odoo.yml',
                $updated,
                'Upgrade Odoo from ' . $currentVersion . ' to ' . $targetVersion
            );

            RancherFleet\Logger::info("TriggerLiveUpgrade: image tag updated");
            sleep(5);
        } catch (\Exception $updateEx) {
            $rancher->scaleDeployment($namespace, 'odoo', 1);
            return 'Error: Failed to update image tag. Database upgrade succeeded but deployment may need manual correction. ' . $updateEx->getMessage();
        }

        // 6. Scale production back to 1 (end maintenance window)
        RancherFleet\Logger::info("TriggerLiveUpgrade: scaling production back to 1, ending maintenance window");
        try {
            $rancher->scaleDeployment($namespace, 'odoo', 1);
            sleep(15);
        } catch (\Exception $scaleEx) {
            RancherFleet\Logger::error("TriggerLiveUpgrade: scale up failed: " . $scaleEx->getMessage());
            return 'Warning: Database upgraded but failed to scale production back up. ' . $scaleEx->getMessage();
        }

        // 7. Schedule 7-day cleanup
        rancherfleet_updateUpgradeStatus($serviceId, 'live_upgraded', array(
            'live_upgraded_at' => time(),
            'cleanup_at'        => time() + 604800,  // 7 days
        ));

        // 8. Send client notification
        $user = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
        if ($user) {
            rancherfleet_sendUpgradeEmail($user, 'upgrade_complete', array(
                'targetVersion' => $targetVersion,
            ));
        }

        RancherFleet\Logger::info("TriggerLiveUpgrade: SUCCESS - maintenance window complete");
        return 'Success: Odoo upgraded from ' . $currentVersion . ' to ' . $targetVersion . '. Production is back online. Staging environment retained for 7 days.';

    } catch (\Exception $e) {
        RancherFleet\Logger::error("TriggerLiveUpgrade: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Rollback upgrade to previous version.
 * Restores production from pre-upgrade database backup.
 */
function rancherfleet_RollbackUpgrade(array $params)
{
    RancherFleet\Logger::info("RollbackUpgrade: starting");

    try {
        $orderNum = rancherfleet_getOrderNumber($params);
        $namespace = 'whmcs-client-' . $orderNum;
        $serviceId = (int)$params['serviceid'];

        $request = rancherfleet_getUpgradeRequest($serviceId);
        if (empty($request) || $request['status'] !== 'live_upgraded') {
            return 'No upgrade available to rollback for this service.';
        }

        $targetVersion = $request['version'];
        $preUpgradeDb = 'odoo-' . $orderNum . '-pre-upgrade';
        $currentDb = 'odoo-' . $orderNum;

        RancherFleet\Logger::info("RollbackUpgrade: rolling back {$namespace} from {$targetVersion}");

        list($rancher, $github) = rancherfleet_buildClients($params);

        // 1. Scale production to 0
        RancherFleet\Logger::info("RollbackUpgrade: scaling production to 0");
        $rancher->scaleDeployment($namespace, 'odoo', 0);
        sleep(10);

        // 2. Swap databases: move current (upgraded) to backup, restore pre-upgrade
        RancherFleet\Logger::info("RollbackUpgrade: swapping databases");
        try {
            $dbSecretName = 'rfm-db-admin-' . $orderNum;
            $swapJobSpec = array(
                'apiVersion' => 'batch/v1',
                'kind'       => 'Job',
                'metadata'   => array('name' => 'swapdb-' . $orderNum . '-rollback', 'namespace' => 'default'),
                'spec'       => array(
                    'ttlSecondsAfterFinished' => 60,
                    'backoffLimit'            => 0,
                    'template' => array(
                        'spec' => array(
                            'restartPolicy' => 'Never',
                            'volumes' => array(
                                array(
                                    'name' => 'db-credentials',
                                    'secret' => array('secretName' => $dbSecretName),
                                ),
                            ),
                            'containers' => array(
                                array(
                                    'name'  => 'postgres',
                                    'image' => 'postgres:16-alpine',
                                    'volumeMounts' => array(
                                        array(
                                            'name'      => 'db-credentials',
                                            'mountPath' => '/etc/rfm-db',
                                            'readOnly'  => true,
                                        ),
                                    ),
                                    'command' => array('sh', '-c'),
                                    'args'    => array(
                                        "DB_USER=\$(cat /etc/rfm-db/username)\n" .
                                        "DB_PASS=\$(cat /etc/rfm-db/password)\n" .
                                        "export PGPASSWORD=\"\$DB_PASS\"\n" .
                                        "\n" .
                                        "# Terminate connections\n" .
                                        "psql -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" -d postgres \\\n" .
                                        "  -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IN ('" . $currentDb . "', '" . $preUpgradeDb . "') AND pid<>pg_backend_pid();\" 2>/dev/null || true\n" .
                                        "\n" .
                                        "# Delete upgraded database\n" .
                                        "dropdb -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" --if-exists \"" . $currentDb . "\" 2>/dev/null || true\n" .
                                        "\n" .
                                        "# Restore pre-upgrade as current\n" .
                                        "psql -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" -d postgres \\\n" .
                                        "  -c \"ALTER DATABASE \\\"" . $preUpgradeDb . "\\\" RENAME TO \\\"" . $currentDb . "\\\";\"\n" .
                                        "\n" .
                                        "echo \"Database rollback complete\""
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );

            $response = $rancher->rawRequest('POST',
                '/apis/batch/v1/namespaces/default/jobs',
                $swapJobSpec
            );

            $swapJobName = 'swapdb-' . $orderNum . '-rollback';
            $swapJobPath = '/apis/batch/v1/namespaces/default/jobs/' . rawurlencode($swapJobName);
            $swapSucceeded = false;

            for ($i = 0; $i < 24; $i++) {
                sleep(5);
                try {
                    $job = $rancher->rawRequest('GET', $swapJobPath);
                    $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                    foreach ($conditions as $cond) {
                        if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                            $swapSucceeded = true; break 2;
                        }
                    }
                } catch (\Exception $e) {
                    RancherFleet\Logger::error("RollbackUpgrade: error polling swap Job: " . $e->getMessage());
                }
            }

            try {
                $rancher->rawRequest('DELETE', $swapJobPath);
            } catch (\Exception $e) {
                RancherFleet\Logger::info("RollbackUpgrade: swap Job cleanup note: " . $e->getMessage());
            }

            if (!$swapSucceeded) {
                $rancher->scaleDeployment($namespace, 'odoo', 1);
                return 'Error: Failed to swap databases during rollback.';
            }
        } catch (\Exception $dbEx) {
            $rancher->scaleDeployment($namespace, 'odoo', 1);
            return 'Error: Database swap failed: ' . $dbEx->getMessage();
        }

        // 3. Revert image tag in deployment
        RancherFleet\Logger::info("RollbackUpgrade: reverting image tag");
        try {
            $currentVersion = $request['fromVersion'] ?? '16.0';
            $rancher->updateDeploymentImage($namespace, 'odoo', 'odoo:' . $currentVersion);
        } catch (\Exception $imgEx) {
            RancherFleet\Logger::error("RollbackUpgrade: image revert failed: " . $imgEx->getMessage());
        }

        // 4. Scale production back to 1
        RancherFleet\Logger::info("RollbackUpgrade: scaling production back to 1");
        $rancher->scaleDeployment($namespace, 'odoo', 1);
        sleep(10);

        // 5. Update status to rolled_back
        rancherfleet_updateUpgradeStatus($serviceId, 'rolled_back', array(
            'rolled_back_at' => time(),
            'rolled_back_from_version' => $targetVersion,
        ));

        // 6. Send notification
        $user = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
        if ($user) {
            rancherfleet_sendUpgradeEmail($user, 'upgrade_rollback', array(
                'targetVersion' => $targetVersion,
            ));
        }

        RancherFleet\Logger::info("RollbackUpgrade: SUCCESS");
        return 'Success: Upgrade rolled back to ' . $currentVersion . '. Production is back online.';

    } catch (\Exception $e) {
        RancherFleet\Logger::error("RollbackUpgrade: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Delete upgrade staging environment and resources.
 * Removes namespace, branch, GitRepo, and databases.
 */
function rancherfleet_CleanupUpgrade(array $params)
{
    RancherFleet\Logger::info("CleanupUpgrade: starting");

    try {
        $orderNum = rancherfleet_getOrderNumber($params);
        $serviceId = (int)$params['serviceid'];

        $request = rancherfleet_getUpgradeRequest($serviceId);
        if (empty($request)) {
            return 'No upgrade record found for cleanup.';
        }

        $stagingNamespace = 'whmcs-client-' . $orderNum . '-staging';
        $stagingBranch = 'whmcs-client-' . $orderNum . '-staging';
        $stagingDb = 'odoo-' . $orderNum . '-staging';
        $preUpgradeDb = 'odoo-' . $orderNum . '-pre-upgrade';

        RancherFleet\Logger::info("CleanupUpgrade: deleting staging resources");

        list($rancher, $github) = rancherfleet_buildClients($params);

        // 1. Delete staging databases
        try {
            $dbSecretName = 'rfm-db-admin-' . $orderNum;
            $cleanupJobSpec = array(
                'apiVersion' => 'batch/v1',
                'kind'       => 'Job',
                'metadata'   => array('name' => 'cleanupdb-' . $orderNum, 'namespace' => 'default'),
                'spec'       => array(
                    'ttlSecondsAfterFinished' => 60,
                    'backoffLimit'            => 0,
                    'template' => array(
                        'spec' => array(
                            'restartPolicy' => 'Never',
                            'volumes' => array(
                                array(
                                    'name' => 'db-credentials',
                                    'secret' => array('secretName' => $dbSecretName),
                                ),
                            ),
                            'containers' => array(
                                array(
                                    'name'  => 'postgres',
                                    'image' => 'postgres:16-alpine',
                                    'volumeMounts' => array(
                                        array(
                                            'name'      => 'db-credentials',
                                            'mountPath' => '/etc/rfm-db',
                                            'readOnly'  => true,
                                        ),
                                    ),
                                    'command' => array('sh', '-c'),
                                    'args'    => array(
                                        "DB_USER=\$(cat /etc/rfm-db/username)\n" .
                                        "DB_PASS=\$(cat /etc/rfm-db/password)\n" .
                                        "export PGPASSWORD=\"\$DB_PASS\"\n" .
                                        "\n" .
                                        "# Terminate connections to staging and pre-upgrade databases\n" .
                                        "psql -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" -d postgres \\\n" .
                                        "  -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IN ('" . $stagingDb . "', '" . $preUpgradeDb . "') AND pid<>pg_backend_pid();\" 2>/dev/null || true\n" .
                                        "\n" .
                                        "# Drop databases\n" .
                                        "dropdb -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" --if-exists \"" . $stagingDb . "\" 2>/dev/null || true\n" .
                                        "dropdb -h postgres16.default.svc.cluster.local -U \"\$DB_USER\" --if-exists \"" . $preUpgradeDb . "\" 2>/dev/null || true\n" .
                                        "\n" .
                                        "echo \"Database cleanup complete\""
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );

            $response = $rancher->rawRequest('POST',
                '/apis/batch/v1/namespaces/default/jobs',
                $cleanupJobSpec
            );

            $cleanupJobName = 'cleanupdb-' . $orderNum;
            $cleanupJobPath = '/apis/batch/v1/namespaces/default/jobs/' . rawurlencode($cleanupJobName);

            for ($i = 0; $i < 24; $i++) {
                sleep(5);
                try {
                    $job = $rancher->rawRequest('GET', $cleanupJobPath);
                    $conditions = isset($job['status']['conditions']) ? $job['status']['conditions'] : array();
                    foreach ($conditions as $cond) {
                        if (isset($cond['type']) && $cond['type'] === 'Complete' && $cond['status'] === 'True') {
                            break 2;
                        }
                    }
                } catch (\Exception $e) {
                    RancherFleet\Logger::error("CleanupUpgrade: error polling cleanup Job: " . $e->getMessage());
                }
            }

            try {
                $rancher->rawRequest('DELETE', $cleanupJobPath);
            } catch (\Exception $e) {
                RancherFleet\Logger::info("CleanupUpgrade: cleanup Job removal note: " . $e->getMessage());
            }
        } catch (\Exception $dbEx) {
            RancherFleet\Logger::error("CleanupUpgrade: database cleanup failed: " . $dbEx->getMessage());
        }

        // 2. Delete staging namespace
        try {
            $rancher->deleteNamespace($stagingNamespace);
            RancherFleet\Logger::info("CleanupUpgrade: deleted namespace {$stagingNamespace}");
        } catch (\Exception $nsEx) {
            RancherFleet\Logger::error("CleanupUpgrade: namespace deletion failed: " . $nsEx->getMessage());
        }

        // 3. Delete staging branch
        try {
            $github->deleteBranch($stagingBranch);
            RancherFleet\Logger::info("CleanupUpgrade: deleted branch {$stagingBranch}");
        } catch (\Exception $branchEx) {
            RancherFleet\Logger::error("CleanupUpgrade: branch deletion failed: " . $branchEx->getMessage());
        }

        // 4. Update status to archived and clear the record
        rancherfleet_updateUpgradeStatus($serviceId, 'archived', array(
            'cleaned_up_at' => time(),
        ));

        RancherFleet\Logger::info("CleanupUpgrade: SUCCESS");
        return 'Success: Staging environment and upgrade records cleaned up.';

    } catch (\Exception $e) {
        RancherFleet\Logger::error("CleanupUpgrade: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}


/**
 * Admin button handler to cleanup staging environment.
 * Deletes namespace, branch, GitRepo, and database.
 */
function rancherfleet_CleanupStaging(array $params)
{
    RancherFleet\Logger::info("CleanupStaging: starting");

    try {
        $orderNum = rancherfleet_getOrderNumber($params);
        $serviceId = (int)$params['serviceid'];

        // Read cleanup record
        $record = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_upgrade_cleanup')
            ->where('setting', 'cleanup_' . $serviceId)
            ->first();

        if (!$record) {
            return 'No staging cleanup job found for this service.';
        }

        $cleanup = json_decode($record->value, true);
        $stagingNamespace = $cleanup['namespace'];
        $stagingBranch = $cleanup['branch'];
        $gitrepo = $cleanup['gitrepo'];
        $stagingDb = $cleanup['db'];

        RancherFleet\Logger::info("CleanupStaging: removing {$stagingNamespace}");

        list($rancher, $github) = rancherfleet_buildClients($params);

        // 1. Delete staging namespace
        try {
            $rancher->deleteNamespace($stagingNamespace);
            RancherFleet\Logger::info("CleanupStaging: namespace deleted");
        } catch (\Exception $nsEx) {
            RancherFleet\Logger::error("CleanupStaging: namespace deletion error (non-fatal): " . $nsEx->getMessage());
        }

        // 2. Delete staging branch
        try {
            $github->deleteBranch($stagingBranch);
            RancherFleet\Logger::info("CleanupStaging: branch deleted");
        } catch (\Exception $branchEx) {
            RancherFleet\Logger::error("CleanupStaging: branch deletion error (non-fatal): " . $branchEx->getMessage());
        }

        // 3. Delete Fleet GitRepo
        try {
            $rancher->deleteFleetGitRepo('fleet-default', $gitrepo);
            RancherFleet\Logger::info("CleanupStaging: GitRepo deleted");
        } catch (\Exception $gitrepoEx) {
            RancherFleet\Logger::error("CleanupStaging: GitRepo deletion error (non-fatal): " . $gitrepoEx->getMessage());
        }

        // 4. Drop staging database via Job
        try {
            $jobSpec = array(
                'apiVersion' => 'batch/v1',
                'kind'       => 'Job',
                'metadata'   => array('name' => 'dropdb-' . $orderNum . '-staging', 'namespace' => 'default'),
                'spec'       => array(
                    'template' => array(
                        'spec' => array(
                            'containers' => array(
                                array(
                                    'name'  => 'postgres',
                                    'image' => 'postgres:16-alpine',
                                    'env'   => array(
                                        array('name' => 'PGPASSWORD', 'value' => getenv('DB_PASSWORD')),
                                    ),
                                    'command' => array('/bin/sh', '-c'),
                                    'args'    => array('dropdb -h postgres16.default.svc.cluster.local -U postgres --if-exists "' . $stagingDb . '"'),
                                ),
                            ),
                            'restartPolicy' => 'Never',
                        ),
                    ),
                    'backoffLimit' => 3,
                ),
            );

            $rancher->createJob('default', $jobSpec);
            RancherFleet\Logger::info("CleanupStaging: database drop job created");
        } catch (\Exception $dbEx) {
            RancherFleet\Logger::error("CleanupStaging: database drop error (non-fatal): " . $dbEx->getMessage());
        }

        // 5. Delete cleanup record
        try {
            \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'rancherfleet_upgrade_cleanup')
                ->where('setting', 'cleanup_' . $serviceId)
                ->delete();
        } catch (\Exception $e) {
            RancherFleet\Logger::error("CleanupStaging: failed to delete cleanup record: " . $e->getMessage());
        }

        rancherfleet_logHistory($params, 'Staging Cleanup', 'Staging environment cleaned up');

        RancherFleet\Logger::info("CleanupStaging: SUCCESS");
        return 'Success: Staging environment cleaned up.';

    } catch (\Exception $e) {
        RancherFleet\Logger::error("CleanupStaging: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Admin button handler to reset/clean up a failed staging upgrade attempt.
 * Deletes all staging resources and resets request status to 'pending'
 * so the admin can retry CreateStagingUpgrade().
 */
function rancherfleet_ResetStaging(array $params)
{
    RancherFleet\Logger::info("ResetStaging: starting");

    try {
        $orderNum = rancherfleet_getOrderNumber($params);
        $serviceId = (int)$params['serviceid'];
        $stagingNamespace = 'whmcs-client-' . $orderNum . '-staging';
        $stagingBranch = 'whmcs-client-' . $orderNum . '-staging';
        $stagingDbName = 'odoo-' . $orderNum . '-staging';
        $gitrepoName = 'gitrepo-' . $stagingNamespace;

        RancherFleet\Logger::info("ResetStaging: resetting staging for {$stagingNamespace}");

        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);

        // 1. Delete staging namespace if exists
        try {
            $rancher->deleteNamespace($stagingNamespace);
            RancherFleet\Logger::info("ResetStaging: namespace deleted");
        } catch (\Exception $nsEx) {
            RancherFleet\Logger::info("ResetStaging: namespace deletion (non-fatal): " . $nsEx->getMessage());
        }

        // 2. Delete staging branch if exists
        try {
            $github->deleteBranch($stagingBranch);
            RancherFleet\Logger::info("ResetStaging: branch deleted");
        } catch (\Exception $branchEx) {
            RancherFleet\Logger::info("ResetStaging: branch deletion (non-fatal): " . $branchEx->getMessage());
        }

        // 3. Delete Fleet GitRepo if exists
        try {
            $fleet->deleteGitRepo($stagingNamespace);
            RancherFleet\Logger::info("ResetStaging: GitRepo deleted");
        } catch (\Exception $gitrepoEx) {
            RancherFleet\Logger::info("ResetStaging: GitRepo deletion (non-fatal): " . $gitrepoEx->getMessage());
        }

        // 4. Drop staging database via Job if it might exist
        try {
            $dbUser = isset($params['configoption20']) ? trim($params['configoption20']) : 'postgres';
            $dbPass = isset($params['configoption21']) ? trim($params['configoption21']) : '';

            if (!empty($dbUser)) {
                $jobName = 'dropdb-' . strtolower(preg_replace('/[^a-z0-9]/i', '-', $orderNum)) . '-staging';
                $jobName = substr($jobName, 0, 48) . '-' . substr(md5($orderNum . '-s'), 0, 8);

                $jobSpec = array(
                    'apiVersion' => 'batch/v1',
                    'kind'       => 'Job',
                    'metadata'   => array(
                        'name'      => $jobName,
                        'namespace' => 'default',
                        'labels'    => array('app' => 'rfm-dropdb'),
                    ),
                    'spec' => array(
                        'ttlSecondsAfterFinished' => 120,
                        'backoffLimit'            => 0,
                        'template' => array(
                            'spec' => array(
                                'restartPolicy' => 'Never',
                                'containers' => array(array(
                                    'name'    => 'dropdb',
                                    'image'   => 'postgres:16-alpine',
                                    'command' => array('sh', '-c'),
                                    'args'    => array(
                                        'export PGPASSWORD="' . addslashes($dbPass) . '"' . "\n" .
                                        'dropdb -h postgres16.default.svc.cluster.local -U "' . addslashes($dbUser) . '" --if-exists "' . $stagingDbName . '"'
                                    ),
                                )),
                            ),
                        ),
                    ),
                );

                $rancher->rawRequest('POST',
                    '/apis/batch/v1/namespaces/default/jobs',
                    $jobSpec
                );

                RancherFleet\Logger::info("ResetStaging: database drop job created");
            }
        } catch (\Exception $dbEx) {
            RancherFleet\Logger::info("ResetStaging: database drop (non-fatal): " . $dbEx->getMessage());
        }

        // 5. Reset request status to 'pending' (allow retry)
        try {
            $record = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'rancherfleet_upgrade')
                ->where('setting', 'request_' . $serviceId)
                ->first();

            if ($record) {
                $request = json_decode($record->value, true);
                if (is_array($request)) {
                    $request['status'] = 'pending';
                    \WHMCS\Database\Capsule::table('tbladdonmodules')
                        ->where('module', 'rancherfleet_upgrade')
                        ->where('setting', 'request_' . $serviceId)
                        ->update(array('value' => json_encode($request)));
                    RancherFleet\Logger::info("ResetStaging: request status reset to 'pending'");
                }
            }
        } catch (\Exception $e) {
            RancherFleet\Logger::error("ResetStaging: failed to reset request status: " . $e->getMessage());
        }

        rancherfleet_logHistory($params, 'Staging Reset', 'Failed staging upgrade environment reset. Ready to retry.');

        RancherFleet\Logger::info("ResetStaging: SUCCESS");
        return 'Success: Staging environment reset. You can now retry "Create Staging Upgrade".';

    } catch (\Exception $e) {
        RancherFleet\Logger::error("ResetStaging: " . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}
