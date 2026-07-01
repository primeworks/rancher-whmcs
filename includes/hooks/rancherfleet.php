<?php
/**
 * RancherFleet WHMCS Hooks
 *
 * Registers:
 *   1. CronJob hook — processes the retry queue on each WHMCS cron run
 *   2. Fleet webhook receiver — handles incoming Fleet status notifications
 *
 * Install: place this file in /includes/hooks/rancherfleet.php
 * (WHMCS auto-loads all PHP files in that directory)
 *
 * @version 3.4.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once ROOTDIR . '/modules/servers/rancherfleet/lib/Logger.php';
require_once ROOTDIR . '/modules/servers/rancherfleet/lib/RetryQueue.php';

// ---------------------------------------------------------------------------
// Migration AJAX Hook
// ---------------------------------------------------------------------------

if (!class_exists('RfmAjaxHandler')) {
    class RfmAjaxHandler
    {
        public static function buildClients()
        {
            $server = \WHMCS\Database\Capsule::table('tblservers')
                ->where('type', 'rancherfleet')
                ->where('active', 1)
                ->first();
            if (!$server) {
                throw new \Exception('No active RancherFleet server found.');
            }
            $server    = (array)$server;
            $hash      = json_decode(isset($server['accesshash']) ? $server['accesshash'] : '{}', true);
            $url       = 'https://' . rtrim($server['hostname'], '/');
            $token     = $server['password'];
            $clusterId = isset($hash['target_cluster_id']) ? $hash['target_cluster_id'] : (isset($hash['cluster_id']) ? $hash['cluster_id'] : '');
            $rancher   = new \RancherFleet\RancherClient($url, $token, $clusterId);
            $exec      = new \RancherFleet\Migration\RancherExec($rancher, $clusterId);
            return array($rancher, $exec, $clusterId);
        }

        public static function buildDepInfo($exec, $ns, $dep)
        {
            $r = $exec->getDeploymentRaw($ns, $dep);
            $containers = isset($r['spec']['template']['spec']['containers']) ? $r['spec']['template']['spec']['containers'] : array();
            $cname = $dep; $image = '';
            foreach ($containers as $c) {
                $cname = isset($c['name'])  ? $c['name']  : $dep;
                $image = isset($c['image']) ? $c['image'] : '';
                break;
            }
            return array(
                'name'           => $dep,
                'namespace'      => $ns,
                'image'          => $image,
                'container_name' => $cname,
                'replicas'       => isset($r['spec']['replicas'])        ? (int)$r['spec']['replicas']        : 0,
                'ready'          => isset($r['status']['readyReplicas']) ? (int)$r['status']['readyReplicas'] : 0,
            );
        }

        public static function handle($action)
        {
            switch ($action) {
                case 'get_deployments':
                    list($rancher, $exec) = self::buildClients();
                    return array('success' => true, 'deployments' => $exec->discoverOdooDeployments());

                case 'get_databases':
                    $ns  = isset($_POST['namespace'])  ? $_POST['namespace']  : '';
                    $dep = isset($_POST['deployment']) ? $_POST['deployment'] : '';
                    list($rancher, $exec) = self::buildClients();
                    $pod = $exec->getRunningPod($ns, $dep);
                    if (!$pod) {
                        return array('success' => false, 'error' => 'No running pod found. Deployment may be suspended.');
                    }
                    $podName    = $pod['metadata']['name'];
                    $containers = isset($pod['spec']['containers']) ? $pod['spec']['containers'] : array();
                    $cname      = $dep;
                    foreach ($containers as $c) { $cname = isset($c['name']) ? $c['name'] : $dep; break; }
                    $envVars  = $exec->getPodEnvVars($pod, $cname);
                    $dbConfig = $exec->extractDbConfig($envVars);
                    $dbs      = $exec->listDatabases($ns, $podName, $cname, $dbConfig);
                    $fs       = $exec->detectFilestore($ns, $dep);
                    return array(
                        'success'   => true,
                        'databases' => array_values($dbs),
                        'db_host'   => $dbConfig['host'],
                        'db_port'   => $dbConfig['port'],
                        'db_user'   => $dbConfig['user'],
                        'pod_name'  => $podName,
                        'container' => $cname,
                        'filestore' => $fs,
                    );

                case 'run_safety_tests':
                    $sourceNs  = isset($_POST['source_ns'])  ? $_POST['source_ns']  : '';
                    $sourceDep = isset($_POST['source_dep']) ? $_POST['source_dep'] : '';
                    $sourceDb  = isset($_POST['source_db'])  ? $_POST['source_db']  : '';
                    $destNs    = isset($_POST['dest_ns'])    ? $_POST['dest_ns']    : '';
                    $destDep   = isset($_POST['dest_dep'])   ? $_POST['dest_dep']   : '';
                    $destDb    = isset($_POST['dest_db'])    ? $_POST['dest_db']    : '';
                    $overwrite = isset($_POST['overwrite'])  && $_POST['overwrite'] === '1';
                    list($rancher, $exec) = self::buildClients();
                    $si      = self::buildDepInfo($exec, $sourceNs, $sourceDep);
                    $di      = self::buildDepInfo($exec, $destNs,   $destDep);
                    $checker = new \RancherFleet\Migration\MigrationSafetyChecker($exec, $rancher);
                    $results = $checker->runAll($si, $di, array(
                        'source_db' => $sourceDb, 'dest_db' => $destDb, 'allow_overwrite' => $overwrite,
                    ));
                    return array(
                        'success'     => true,
                        'results'     => $results,
                        'summary'     => \RancherFleet\Migration\MigrationSafetyChecker::summary($results),
                        'can_migrate' => \RancherFleet\Migration\MigrationSafetyChecker::allPassed($results),
                    );

                case 'run_migration':
                    require_once ROOTDIR . '/modules/addons/rancherfleet_migration/rancherfleet_migration.php';
                    return rfm_ajaxRunMigration();

                default:
                    return array('success' => false, 'error' => 'Unknown action: ' . $action);
            }
        }
    }
}

add_hook('AdminAreaPage', 1, function($vars) {
    if (!isset($_GET['rfm_ajax'])) return;
    if (!isset($_GET['module']) || $_GET['module'] !== 'rancherfleet_migration') return;
    $action = isset($_POST['rfm_action']) ? $_POST['rfm_action'] : '';
    if (!$action) return;

    require_once ROOTDIR . '/modules/servers/rancherfleet/lib/RancherClient.php';
    require_once ROOTDIR . '/modules/addons/rancherfleet_migration/RancherExec.php';
    require_once ROOTDIR . '/modules/addons/rancherfleet_migration/MigrationSafetyChecker.php';

    try {
        $response = RfmAjaxHandler::handle($action);
    } catch (\Exception $e) {
        $response = array('success' => false, 'error' => $e->getMessage());
    }

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
});

// ---------------------------------------------------------------------------
// Hook 1: Cron — Process Retry Queue
// ---------------------------------------------------------------------------

add_hook('CronJob', 1, function($vars) {
    RancherFleet\Logger::info("RetryQueue cron: checking for due entries");

    $dueEntries = RancherFleet\RetryQueue::getDueEntries();

    if (empty($dueEntries)) {
        RancherFleet\Logger::info("RetryQueue cron: no entries due");
        return;
    }

    RancherFleet\Logger::info("RetryQueue cron: processing " . count($dueEntries) . " entries");

    // Load WHMCS module functions
    if (!function_exists('rancherfleet_CreateNamespace')) {
        @include_once ROOTDIR . '/modules/servers/rancherfleet/rancherfleet.php';
    }

    foreach ($dueEntries as $entry) {
        $serviceId = (int)$entry['service_id'];
        $phase     = $entry['phase'];
        $namespace = $entry['namespace'];

        RancherFleet\Logger::info("RetryQueue cron: retrying service={$serviceId} phase={$phase} attempt={$entry['attempts']}");

        try {
            // Load WHMCS module params for this service
            $params = rancherfleet_loadParamsForService($serviceId);
            if (empty($params)) {
                RancherFleet\Logger::error("RetryQueue cron: could not load params for service={$serviceId}");
                continue;
            }

            // Execute the phase function
            $fnName = 'rancherfleet_' . $phase;
            if (!function_exists($fnName)) {
                RancherFleet\Logger::error("RetryQueue cron: function {$fnName} not found");
                RancherFleet\RetryQueue::dequeue($serviceId, $phase);
                continue;
            }

            $result = $fnName($params);

            if ($result === 'success' || strpos($result, 'Success') === 0) {
                RancherFleet\Logger::info("RetryQueue cron: SUCCESS service={$serviceId} phase={$phase}");
                RancherFleet\RetryQueue::dequeue($serviceId, $phase);
                rancherfleet_logHistory($params, "Retry Success: {$phase}", "attempt {$entry['attempts']}");
            } else {
                RancherFleet\Logger::error("RetryQueue cron: FAILED service={$serviceId} phase={$phase}: {$result}");
                RancherFleet\RetryQueue::enqueue($serviceId, $phase, $namespace, $result);
            }

        } catch (\Exception $e) {
            RancherFleet\Logger::error("RetryQueue cron: exception for service={$serviceId}: " . $e->getMessage());
            RancherFleet\RetryQueue::enqueue($serviceId, $phase, $namespace, $e->getMessage());
        }
    }
});

// ---------------------------------------------------------------------------
// Hook 1b: Cron — Process Pending Domain Orders
// ---------------------------------------------------------------------------

/**
 * Independent from the infrastructure RetryQueue above — domain orders
 * already have a captured customer payment and a hard attempt cap that
 * triggers a refund, which is different enough in shape from the
 * uncapped, no-money infrastructure retries that a separate store
 * (DomainRetryStore) and a separate cron pass are used rather than
 * threading domain orders through the same queue.
 */
add_hook('CronJob', 1, function($vars) {
    require_once ROOTDIR . '/modules/servers/rancherfleet/lib/Domains/ResellersPanelClient.php';
    require_once ROOTDIR . '/modules/servers/rancherfleet/lib/Domains/CloudflareClient.php';
    require_once ROOTDIR . '/modules/servers/rancherfleet/lib/Domains/DomainRetryStore.php';
    require_once ROOTDIR . '/modules/servers/rancherfleet/lib/Domains/DomainRecordStore.php';
    require_once ROOTDIR . '/modules/servers/rancherfleet/lib/Domains/IngressHelper.php';
    require_once ROOTDIR . '/modules/servers/rancherfleet/lib/Domains/DomainOrderManager.php';

    RancherFleet\Logger::info("DomainRetry cron: checking for due orders");

    $dueOrders = RancherFleet\Domains\DomainRetryStore::getDueOrders();

    if (empty($dueOrders)) {
        RancherFleet\Logger::info("DomainRetry cron: no orders due");
        return;
    }

    RancherFleet\Logger::info("DomainRetry cron: processing " . count($dueOrders) . " order(s)");

    if (!function_exists('rancherfleet_buildDomainClients')) {
        @include_once ROOTDIR . '/modules/servers/rancherfleet/rancherfleet.php';
    }

    foreach ($dueOrders as $token => $orderData) {
        RancherFleet\Logger::info("DomainRetry cron: retrying token={$token} domain={$orderData['domainName']} attempt=" . ((int)$orderData['attempts'] + 1));

        try {
            $serviceId = isset($orderData['serviceId']) ? (int)$orderData['serviceId'] : 0;
            $params    = $serviceId ? rancherfleet_loadParamsForService($serviceId) : array();
            if (empty($params)) {
                RancherFleet\Logger::error("DomainRetry cron: could not load params for service={$serviceId} (order token={$token})");
                continue;
            }

            list(, $orderManager) = rancherfleet_buildDomainClients($params);
            $resultMsg = $orderManager->retryPendingOrder($token, $orderData);

            RancherFleet\Logger::info("DomainRetry cron: token={$token} result: {$resultMsg}");

        } catch (\Exception $e) {
            RancherFleet\Logger::error("DomainRetry cron: exception for token={$token}: " . $e->getMessage());
            // Still record this as a failed attempt so it doesn't retry
            // again immediately on the next cron tick.
            RancherFleet\Domains\DomainRetryStore::recordFailure($token, $e->getMessage());
        }
    }
});

// ---------------------------------------------------------------------------
// Hook 2: Fleet Webhook Receiver
// ---------------------------------------------------------------------------

/**
 * Fleet webhook receiver.
 *
 * Fleet does not natively send webhooks, but this hook provides an endpoint
 * that can be called by external tools (e.g. Alertmanager, custom Fleet
 * controllers, or a simple cron script) to push GitRepo status updates
 * into WHMCS.
 *
 * Endpoint: POST /includes/hooks/rancherfleet.php?action=fleet_webhook
 *
 * Payload (JSON):
 * {
 *   "gitrepo_name":  "gitrepo-whmcs-client-AF4E52",
 *   "namespace":     "whmcs-client-AF4E52",
 *   "state":         "Ready",
 *   "message":       "Synced successfully",
 *   "commit":        "a3f9c12d"
 * }
 *
 * The hook finds the matching WHMCS service and writes the status
 * to the service history log.
 */
add_hook('ShutdownHook', 1, function($vars) {
    // Only process if this is a webhook request
    if (!isset($_GET['action']) || $_GET['action'] !== 'fleet_webhook') {
        return;
    }

    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(array('error' => 'Invalid JSON payload'));
        return;
    }

    $namespace = isset($payload['namespace']) ? $payload['namespace'] : '';
    $state     = isset($payload['state'])     ? $payload['state']     : 'Unknown';
    $message   = isset($payload['message'])   ? $payload['message']   : '';
    $commit    = isset($payload['commit'])    ? $payload['commit']    : '';

    if (empty($namespace)) {
        http_response_code(400);
        echo json_encode(array('error' => 'namespace required'));
        return;
    }

    RancherFleet\Logger::info("Webhook: received state={$state} namespace={$namespace} commit={$commit}");

    // Find matching WHMCS service by namespace pattern whmcs-client-{orderNum}
    // The order number is stored in tblorders.ordernum
    try {
        // Extract order number from namespace
        $orderNum = str_replace('whmcs-client-', '', $namespace);

        $serviceId = \WHMCS\Database\Capsule::table('tblhosting')
            ->join('tblorders', 'tblorders.id', '=', 'tblhosting.orderid')
            ->where('tblorders.ordernum', $orderNum)
            ->value('tblhosting.id');

        if (!$serviceId) {
            RancherFleet\Logger::error("Webhook: no service found for namespace={$namespace}");
            http_response_code(404);
            echo json_encode(array('error' => 'Service not found for namespace ' . $namespace));
            return;
        }

        // Write status to history log using fake params array
        $fakeParams = array('serviceid' => $serviceId);
        $detail     = "state={$state}" . ($commit ? " commit={$commit}" : '') . ($message ? " msg={$message}" : '');

        // We can't call rancherfleet_logHistory directly without full params,
        // so we write to the DB directly using the same key format
        $key   = 'rf_history_' . $serviceId;
        $entry = '[' . date('Y-m-d H:i:s') . '] Fleet Webhook: ' . $detail . "\n";

        $existing = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceId)
            ->where('fieldid', 0)
            ->where('value', 'like', $key . '|%')
            ->value('value');

        if ($existing) {
            $current = substr($existing, strlen($key) + 1);
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)
                ->where('fieldid', 0)
                ->where('value', 'like', $key . '|%')
                ->update(array('value' => $key . '|' . $entry . $current));
        } else {
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->insert(array('fieldid' => 0, 'relid' => $serviceId, 'value' => $key . '|' . $entry));
        }

        RancherFleet\Logger::info("Webhook: logged to service={$serviceId}");

        http_response_code(200);
        echo json_encode(array('ok' => true, 'service_id' => $serviceId));

    } catch (\Exception $e) {
        RancherFleet\Logger::error("Webhook: error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(array('error' => $e->getMessage()));
    }
});

// ---------------------------------------------------------------------------
// Helper: load module params for a service ID
// ---------------------------------------------------------------------------

/**
 * Reconstructs a minimal WHMCS module params array for a given service ID.
 * Used by the cron retry queue to call phase functions.
 *
 * @param  int   $serviceId
 * @return array WHMCS params array, or empty array on failure
 */
if (!function_exists('rancherfleet_loadParamsForService')) { function rancherfleet_loadParamsForService($serviceId)
{
    try {
        $service = \WHMCS\Database\Capsule::table('tblhosting')
            ->join('tblservers', 'tblservers.id', '=', 'tblhosting.server')
            ->join('tblorders', 'tblorders.id', '=', 'tblhosting.orderid')
            ->where('tblhosting.id', $serviceId)
            ->select(
                'tblhosting.id as serviceid',
                'tblhosting.packageid',
                'tblhosting.orderid',
                'tblservers.hostname as serverhostname',
                'tblservers.password as serverpassword',
                'tblservers.accesshash as serveraccesshash',
                'tblorders.ordernum'
            )
            ->first();

        if (!$service) {
            return array();
        }

        $service  = (array)$service;

        // Load product config options
        $product  = \WHMCS\Database\Capsule::table('tblproducts')
            ->where('id', $service['packageid'])
            ->first();
        $product  = $product ? (array)$product : array();

        $configOptions = array();
        if (isset($product['configoption1'])) {
            for ($i = 1; $i <= 9; $i++) {
                $k = 'configoption' . $i;
                $configOptions[$k] = isset($product[$k]) ? $product[$k] : '';
            }
        }

        return array_merge($service, $configOptions);

    } catch (\Exception $e) {
        RancherFleet\Logger::error("loadParamsForService: " . $e->getMessage());
        return array();
    }
} }
