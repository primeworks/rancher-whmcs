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
    // Fallback: numbered key (used in some WHMCS versions)
    if (isset($params['configoption1'])) {
        return $params['configoption1'] === 'on';
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
    $val = !empty($co['User Count'])
        ? (int)trim($co['User Count'])
        : (isset($params['configoption12']) ? (int)trim($params['configoption12']) : 5);
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

    // 2. Numbered key — Odoo Version is option 13 in our ConfigOptions list
    if (!empty($params['configoption13'])) return trim($params['configoption13']);

    // 3. Service custom fields
    $customfields = isset($params['customfields']) ? $params['customfields'] : array();
    if (!empty($customfields['Odoo Version'])) return trim($customfields['Odoo Version']);

    // 4. DB lookup
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
    $hours = !empty($co['Suspend Grace Hours']) ? (int)trim($co['Suspend Grace Hours']) : (isset($params['configoption7']) ? (int)trim($params['configoption7']) : 0);
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
    $manualCpu    = !empty($co['Container CPU Request'])    ? trim($co['Container CPU Request'])
                  : (isset($params['configoption8']) ? trim($params['configoption8']) : '');
    $manualMemory = !empty($co['Container Memory Request']) ? trim($co['Container Memory Request'])
                  : (isset($params['configoption9']) ? trim($params['configoption9']) : '');

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
    return !empty($co['Custom Image']) ? trim($co['Custom Image']) : (isset($params['configoption10']) ? trim($params['configoption10']) : '');
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
    if (isset($params['configoption11'])) {
        return $params['configoption11'] === 'on';
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
// Phase C: Secret injection from WHMCS custom fields
// ---------------------------------------------------------------------------

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
    $targetClusterName = $rancher->getClusterName();

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
    $co = isset($params['configoptions']) ? $params['configoptions'] : array();

    $rpUsername = !empty($co['ResellersPanel Store Username']) ? trim($co['ResellersPanel Store Username'])
                : (isset($params['configoption15']) ? trim($params['configoption15']) : '');
    $rpPassword = !empty($co['ResellersPanel API Password']) ? trim($co['ResellersPanel API Password'])
                : (isset($params['configoption16']) ? trim($params['configoption16']) : '');
    $rpApiUrl   = !empty($co['ResellersPanel API URL']) ? trim($co['ResellersPanel API URL'])
                : (isset($params['configoption17']) ? trim($params['configoption17']) : '');
    $cfToken    = !empty($co['Cloudflare API Token']) ? trim($co['Cloudflare API Token'])
                : (isset($params['configoption18']) ? trim($params['configoption18']) : '');

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
        $rancher->testConnection();
    } catch (Exception $e) {
        RancherFleet\RetryQueue::enqueue($serviceId, 'TestConnection', $namespace, $e->getMessage());
        return 'Error (Phase 1 - Connection): ' . $e->getMessage();
    }

    // Phase 2: Create namespace
    try {
        list($rancher) = rancherfleet_buildClients($params);

        // Phase A: Validate template before creating namespace
        list($rancher, $github) = rancherfleet_buildClients($params);
        $validationErrors = $github->validateTemplate();
        if (!empty($validationErrors)) {
            return 'Error (Phase 3 - Template Validation): ' . implode('; ', $validationErrors);
        }

        $rancher->createNamespace($namespace);
        $completed['namespace_created'] = true;

        // Phase C: Create client ServiceAccount for kubeconfig
        $rancher->createClientServiceAccount($namespace);
        RancherFleet\Logger::info("CreateAccount: service account created for {$namespace}");

        // Phase C: Inject secrets from product custom fields
        rancherfleet_doInjectSecrets($params, $namespace);

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
 * Drops the Odoo database for a terminated service.
 *
 * Strategy:
 *   1. Detect DB config from deployment YAML env vars
 *   2. Fall back to service custom fields if env vars unavailable
 *   3. Scale namespace to 1 replica temporarily to exec the dropdb command
 *   4. Drop the database
 *   5. Scale back to 0
 *   6. On any failure: log the error and return (namespace deletion continues)
 */
function rancherfleet_terminateDatabase(array $params, $rancher, $namespace)
{
    RancherFleet\Logger::info("terminateDatabase: starting for {$namespace}");

    try {
        // Load RancherExec for deployment env var reading
        $addonExecPath = ROOTDIR . '/modules/addons/rancherfleet_migration/RancherExec.php';
        if (!file_exists($addonExecPath)) {
            RancherFleet\Logger::info("terminateDatabase: RancherExec not available, skipping DB deletion");
            return;
        }
        require_once $addonExecPath;

        // Get cluster ID from server config
        $cfg       = rancherfleet_getConfig($params);
        $clusterId = isset($cfg['target_cluster_id']) ? $cfg['target_cluster_id'] : '';
        $exec      = new \RancherFleet\Migration\RancherExec($rancher, $clusterId);

        // Step 1: Detect DB config from deployment YAML env vars
        $deploymentName = rancherfleet_detectDeploymentName($exec, $namespace);
        $dbConfig       = array();

        if ($deploymentName) {
            try {
                $envVars  = $exec->getDeploymentEnvVars($namespace, $deploymentName);
                $dbConfig = $exec->extractDbConfig($envVars);
                RancherFleet\Logger::info("terminateDatabase: detected DB host={$dbConfig['host']} user={$dbConfig['user']} db={$dbConfig['dbname']} from env vars");
            } catch (\Exception $e) {
                RancherFleet\Logger::info("terminateDatabase: env var detection failed: " . $e->getMessage());
            }
        }

        // Step 2: Fall back to service custom fields if host not detected
        if (empty($dbConfig['host'])) {
            $dbServer = rancherfleet_getDbServer($params);
            $dbName   = rancherfleet_getServiceDbName($params, $namespace);
            if ($dbServer && $dbName) {
                $dbConfig = array(
                    'host'     => $dbServer,
                    'port'     => '5432',
                    'user'     => 'odoo',
                    'password' => '',
                    'dbname'   => $dbName,
                );
                RancherFleet\Logger::info("terminateDatabase: using custom fields host={$dbServer} db={$dbName}");
            }
        }

        if (empty($dbConfig['host']) || empty($dbConfig['dbname'])) {
            RancherFleet\Logger::error("terminateDatabase: could not determine DB host or name, skipping DB deletion");
            return;
        }

        // Step 3: Ensure a pod is running for exec
        $wasSuspended = false;
        $pod          = null;

        if ($deploymentName) {
            $pod = $exec->getRunningPod($namespace, $deploymentName);
            if (!$pod) {
                // Scale to 1 temporarily — wait up to 120s for pod to be Running
                RancherFleet\Logger::info("terminateDatabase: scaling to 1 replica for exec");
                $rancher->scaleAllDeployments($namespace, 1);
                $wasSuspended = true;
                sleep(10);
                for ($i = 0; $i < 11; $i++) {
                    $pod = $exec->getRunningPod($namespace, $deploymentName);
                    if ($pod) {
                        RancherFleet\Logger::info("terminateDatabase: pod ready after ~" . (10 + $i * 10) . "s");
                        break;
                    }
                    RancherFleet\Logger::info("terminateDatabase: waiting for pod... " . (10 + $i * 10) . "s elapsed");
                    sleep(10);
                }
            }
        }

        // Last resort — use any pod in the namespace even if not fully Ready
        if (!$pod) {
            $allPods = $rancher->listPods($namespace);
            foreach ($allPods as $p) {
                $phase = isset($p['status']['phase']) ? $p['status']['phase'] : '';
                if (in_array($phase, array('Running', 'Pending'))) {
                    RancherFleet\Logger::info("terminateDatabase: using pod in phase={$phase}");
                    if ($phase === 'Pending') sleep(10); // Give pending pod a moment
                    $pod = $p;
                    break;
                }
            }
        }

        if (!$pod) {
            RancherFleet\Logger::error("terminateDatabase: no pod available after 120s, skipping DB deletion");
            rancherfleet_logHistory($params, 'DB Deletion Skipped', 'pod did not start within 120s');
            if ($wasSuspended) $rancher->scaleAllDeployments($namespace, 0);
            return;
        }

        $podName   = $pod['metadata']['name'];
        $containers = isset($pod['spec']['containers']) ? $pod['spec']['containers'] : array();
        $cname      = $deploymentName ?: '';
        foreach ($containers as $c) { $cname = isset($c['name']) ? $c['name'] : $cname; break; }

        // Step 4: Drop the database
        RancherFleet\Logger::info("terminateDatabase: dropping database {$dbConfig['dbname']} on {$dbConfig['host']}");

        $pgpassEnv = !empty($dbConfig['password']) ? 'PGPASSWORD=' . escapeshellarg($dbConfig['password']) . ' ' : '';
        $dbName    = $dbConfig['dbname'];
        $dbHost    = $dbConfig['host'];
        $dbPort    = $dbConfig['port'] ?: '5432';
        $dbUser    = $dbConfig['user'] ?: 'odoo';

        $terminateSql = 'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ' . "'" . addslashes($dbName) . "'" . ' AND pid <> pg_backend_pid();';
        $shellCmd  = $pgpassEnv . 'psql'
                   . ' -h ' . escapeshellarg($dbHost)
                   . ' -p ' . escapeshellarg($dbPort)
                   . ' -U ' . escapeshellarg($dbUser)
                   . ' -c ' . escapeshellarg($terminateSql)
                   . ' && '
                   . $pgpassEnv . 'dropdb'
                   . ' -h ' . escapeshellarg($dbHost)
                   . ' -p ' . escapeshellarg($dbPort)
                   . ' -U ' . escapeshellarg($dbUser)
                   . ' --if-exists '
                   . escapeshellarg($dbName);
        $dropCmd   = array('sh', '-c', $shellCmd);

        $result = $exec->execInPod($namespace, $podName, $cname, $dropCmd);

        if ($result['exit_code'] === 0) {
            RancherFleet\Logger::info("terminateDatabase: database {$dbConfig['dbname']} dropped successfully");
            rancherfleet_logHistory($params, 'Database Deleted', $dbConfig['dbname'] . ' @ ' . $dbConfig['host']);
        } else {
            RancherFleet\Logger::error("terminateDatabase: dropdb failed: " . $result['stderr']);
            rancherfleet_logHistory($params, 'DB Deletion Failed', substr($result['stderr'], 0, 200));
        }

        // Step 5: Scale back to 0 if we scaled up
        if ($wasSuspended) {
            $rancher->scaleAllDeployments($namespace, 0);
            RancherFleet\Logger::info("terminateDatabase: scaled back to 0");
        }

    } catch (\Exception $e) {
        // DB deletion failure must not block namespace deletion
        RancherFleet\Logger::error("terminateDatabase: exception - " . $e->getMessage());
        rancherfleet_logHistory($params, 'DB Deletion Error', $e->getMessage());
    }
}

/**
 * Detects the Odoo deployment name in a namespace.
 * Looks for any deployment with "odoo" in the name or image.
 */
function rancherfleet_detectDeploymentName($exec, $namespace)
{
    // Derive from namespace name — whmcs-client-{orderNum} -> odoo-{orderNum}
    $orderNum = str_replace('whmcs-client-', '', $namespace);
    if ($orderNum && $orderNum !== $namespace) {
        return 'odoo-' . $orderNum;
    }

    // Fallback: list all deployments in namespace and pick first one with odoo in name
    try {
        $response = $exec->getDeploymentRaw($namespace, '');
        // getDeploymentRaw fetches a single deployment — use rawRequest for list
    } catch (\Exception $e) { /* ignore */ }

    return null;
}

/**
 * Returns the database name for a service.
 * Reads from WHMCS service notes or history log for the database name
 * recorded during provisioning.
 */
function rancherfleet_getServiceDbName(array $params, $namespace)
{
    // Default: Odoo database name typically matches the order number or 'odoo'
    $orderNum = rancherfleet_getOrderNumber($params);

    // Check custom fields for an explicit DB name
    $serviceId = (int)$params['serviceid'];
    try {
        $field = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfieldsvalues.relid', $serviceId)
            ->whereIn('tblcustomfields.fieldname', array('Database Name', 'db_name', 'Odoo Database'))
            ->value('tblcustomfieldsvalues.value');
        if (!empty($field)) return trim($field);
    } catch (\Exception $e) { /* fallback */ }

    // Default to 'odoo' — the standard Odoo database name
    return 'odoo';
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
                // not the downstream cluster — use localClusterRequest().
                $old = $rancher->localClusterRequest(
                    'GET',
                    RancherFleet\FleetHelper::FLEET_API_PATH . '/namespaces/' . $oldFleetNs . '/gitrepos/' . $gitRepoName
                );
                if (!empty($old)) {
                    $rancher->localClusterRequest(
                        'DELETE',
                        RancherFleet\FleetHelper::FLEET_API_PATH . '/namespaces/' . $oldFleetNs . '/gitrepos/' . $gitRepoName
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
            $fleet->updateGitRepoTarget($namespace, $repoPath);
            $actions[] = "updated GitRepo in '{$correctFleetNs}'";
        } else {
            $fleet->createGitRepo($namespace, $repoPath);
            $actions[] = "created GitRepo in '{$correctFleetNs}'";
        }

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
    return array('com', 'net', 'org', 'io', 'co', 'app', 'dev', 'biz', 'info');
}

/**
 * Reads the configured CNAME target (e.g. cowboy.webdiscode.com).
 */
function rancherfleet_getDomainCnameTarget(array $params)
{
    $co = isset($params['configoptions']) ? $params['configoptions'] : array();
    $target = !empty($co['Domain CNAME Target']) ? trim($co['Domain CNAME Target'])
            : (isset($params['configoption19']) ? trim($params['configoption19']) : '');
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

    $co = isset($params['configoptions']) ? $params['configoptions'] : array();
    $configured = (!empty($co['ResellersPanel Store Username']) || !empty($params['configoption15']))
               && (!empty($co['Cloudflare API Token']) || !empty($params['configoption18']));

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
    $searchTerm   = isset($_POST['domain_search_term']) ? trim($_POST['domain_search_term']) : '';
    $searchAction = isset($_POST['clientaction']) ? $_POST['clientaction'] : '';

    $html .= '<p style="font-size:12px;color:#666;margin-bottom:10px;">Search for a new domain name. Once purchased, <code>www.yourdomain.com</code> will automatically be connected to this instance, and visitors to the root domain will be redirected to it.</p>';

    $html .= '<form method="post" action="' . $serviceUrl . '" style="display:flex;gap:8px;margin-bottom:14px;">';
    $html .= '<input type="hidden" name="clientaction" value="domain_search">';
    $html .= '<input type="text" name="domain_search_term" value="' . htmlspecialchars($searchTerm) . '" placeholder="yourbusinessname" style="flex:1;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;">';
    $html .= '<button type="submit" style="background:#2980b9;color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:13px;font-weight:bold;cursor:pointer;">Search</button>';
    $html .= '</form>';

    if ($searchAction === 'domain_search' && $searchTerm) {
        try {
            list($rp) = rancherfleet_buildDomainClients($params);

            $tlds    = rancherfleet_commonTlds();
            $results = $rp->checkAvailability($searchTerm, $tlds);
            $pricing = $rp->getRegisterDomainPrices();

            $html .= '<div style="margin-top:8px;">';
            foreach ($results as $entry) {
                $domainName = isset($entry['domain']) ? $entry['domain'] : (isset($entry['name']) ? $entry['name'] : '');
                $tld        = isset($entry['tld']) ? trim($entry['tld'], '.') : substr($domainName, strpos($domainName, '.') + 1);
                $sld        = isset($entry['sld']) ? $entry['sld'] : substr($domainName, 0, strpos($domainName, '.'));
                $available  = !empty($entry['available']) || (isset($entry['status']) && strtolower($entry['status']) === 'available');
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
    }

    try {
        list($rancher, $github, $fleet) = rancherfleet_buildClients($params);

        // Get deployment status
        $deployments = $rancher->listDeployments($namespace);
        $status      = $rancher->getDeploymentStatus($namespace, 'odoo-' . $orderNum);
        $isSuspended = $status['replicas'] === 0;
        $isRunning   = $status['ready'] > 0;

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
                $rawLogs = $rancher->getPodLogs(
                    $namespace,
                    $firstPod['name'],
                    $firstPod['container_name'],
                    3600,
                    200
                );

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
