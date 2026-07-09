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

require_once __DIR__ . '/../../modules/servers/rancherfleet/lib/Logger.php';
require_once __DIR__ . '/../../modules/servers/rancherfleet/lib/RetryQueue.php';

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
        @include_once __DIR__ . '/../../modules/servers/rancherfleet/rancherfleet.php';
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

    // Process staging cleanup jobs (after 48 hours)
    RancherFleet\Logger::info("Cleanup cron: checking for expired staging cleanup jobs");

    try {
        $cleanupJobs = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_upgrade_cleanup')
            ->get();

        foreach ($cleanupJobs as $job) {
            $cleanup = json_decode($job->value, true);
            $cleanupAt = isset($cleanup['cleanup_at']) ? $cleanup['cleanup_at'] : 0;

            if ($cleanupAt > 0 && $cleanupAt < time()) {
                $setting = $job->setting;
                if (preg_match('/^cleanup_(\d+)$/', $setting, $m)) {
                    $serviceId = (int)$m[1];
                    RancherFleet\Logger::info("Cleanup cron: auto-cleaning up staging for service={$serviceId}");

                    $params = rancherfleet_loadParamsForService($serviceId);
                    if (!empty($params)) {
                        rancherfleet_CleanupStaging($params);
                    }
                }
            }
        }
    } catch (\Exception $e) {
        RancherFleet\Logger::error("Cleanup cron: error: " . $e->getMessage());
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
// Hook 3: Invoice Paid — Update upgrade request status
// ---------------------------------------------------------------------------

/**
 * When an upgrade invoice is paid, transition the request from
 * 'awaiting_payment' to 'pending' so the admin can proceed with CreateStagingUpgrade.
 */
add_hook('InvoicePaid', 1, function($vars) {
    $invoiceId = isset($vars['invoiceid']) ? (int)$vars['invoiceid'] : 0;
    if (!$invoiceId) {
        return;
    }

    try {
        // Fetch invoice to check notes for rfm_upgrade:{serviceId}
        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first();

        if (!$invoice) {
            return;
        }

        $notes = isset($invoice->notes) ? $invoice->notes : '';
        if (empty($notes) || !preg_match('/rfm_upgrade:(\d+)/', $notes, $matches)) {
            return;
        }

        $serviceId = (int)$matches[1];
        RancherFleet\Logger::info("InvoicePaid: upgrade invoice {$invoiceId} paid for service={$serviceId}");

        // Load the upgrade request
        $record = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'rancherfleet_upgrade')
            ->where('setting', 'request_' . $serviceId)
            ->first();

        if (!$record) {
            RancherFleet\Logger::error("InvoicePaid: no upgrade request found for service={$serviceId}");
            return;
        }

        $request = json_decode($record->value, true);
        if (!is_array($request)) {
            $request = array();
        }

        // Only transition if currently awaiting payment
        if (isset($request['status']) && $request['status'] === 'awaiting_payment') {
            $request['status'] = 'pending';
            $request['paid_at'] = time();

            \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'rancherfleet_upgrade')
                ->where('setting', 'request_' . $serviceId)
                ->update(array('value' => json_encode($request)));

            RancherFleet\Logger::info("InvoicePaid: updated request status to 'pending' for service={$serviceId}");

            // Log activity for admin visibility
            logActivity("RancherFleet: Upgrade invoice #{$invoiceId} paid for service #{$serviceId}. Admin may now create staging environment.", $serviceId);
        }

    } catch (\Exception $e) {
        RancherFleet\Logger::error("InvoicePaid: error: " . $e->getMessage());
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
function rancherfleet_loadParamsForService($serviceId)
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
}
