<?php
/**
 * RancherFleet Migration AJAX Hook
 *
 * Handles AJAX requests from the migration addon UI.
 * Kept in a separate hooks file to avoid affecting other admin pages.
 *
 * Install: place in /includes/hooks/rancherfleet_migration.php
 *
 * @version 1.0.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

// ---------------------------------------------------------------------------
// Migration AJAX Handler
// ---------------------------------------------------------------------------

add_hook('AdminAreaPage', 1, function($vars) {
    // Double-check we should handle this
    if (!isset($_GET['rfm_ajax'])) return;
    if (!isset($_GET['module']) || $_GET['module'] !== 'rancherfleet_migration') return;

    $action = isset($_POST['rfm_action']) ? $_POST['rfm_action'] : '';
    if (!$action) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'error' => 'No action specified'));
        exit;
    }

    // Load dependencies
    require_once ROOTDIR . '/modules/servers/rancherfleet/lib/Logger.php';
    require_once ROOTDIR . '/modules/servers/rancherfleet/lib/RancherClient.php';
    require_once ROOTDIR . '/modules/addons/rancherfleet_migration/RancherExec.php';
    require_once ROOTDIR . '/modules/addons/rancherfleet_migration/MigrationSafetyChecker.php';

    try {
        $response = rfmh_dispatch($action);
    } catch (\Exception $e) {
        $response = array('success' => false, 'error' => $e->getMessage());
    }

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
});

// ---------------------------------------------------------------------------
// Action dispatcher
// ---------------------------------------------------------------------------

function rfmh_dispatch($action)
{
    switch ($action) {
        case 'get_deployments':
            return rfmh_getDeployments();

        case 'get_databases':
            return rfmh_getDatabases(
                isset($_POST['namespace'])  ? $_POST['namespace']  : '',
                isset($_POST['deployment']) ? $_POST['deployment'] : ''
            );

        case 'run_safety_tests':
            return rfmh_runSafetyTests();

        case 'run_migration':
            return rfmh_runMigration();

        default:
            return array('success' => false, 'error' => 'Unknown action: ' . $action);
    }
}

// ---------------------------------------------------------------------------
// Build Rancher clients from server config
// ---------------------------------------------------------------------------

function rfmh_getClients()
{
    $server = \WHMCS\Database\Capsule::table('tblservers')
        ->where('type', 'rancherfleet')
        ->where('active', 1)
        ->first();

    if (!$server) {
        throw new \Exception('No active RancherFleet server found. Add one at Setup > Products/Services > Servers.');
    }

    $server    = (array)$server;
    $hash      = json_decode(isset($server['accesshash']) ? $server['accesshash'] : '{}', true);
    $url       = 'https://' . rtrim($server['hostname'], '/');
    $token     = $server['password'];
    $clusterId = isset($hash['target_cluster_id']) ? $hash['target_cluster_id']
               : (isset($hash['cluster_id'])       ? $hash['cluster_id'] : '');

    $rancher = new \RancherFleet\RancherClient($url, $token, $clusterId);
    $exec    = new \RancherFleet\Migration\RancherExec($rancher, $clusterId);

    return array($rancher, $exec, $clusterId);
}

function rfmh_getDepInfo($exec, $ns, $dep)
{
    $r          = $exec->getDeploymentRaw($ns, $dep);
    $containers = isset($r['spec']['template']['spec']['containers'])
                ? $r['spec']['template']['spec']['containers'] : array();
    $cname = $dep;
    $image = '';
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

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

function rfmh_getDeployments()
{
    list($rancher, $exec) = rfmh_getClients();
    return array('success' => true, 'deployments' => $exec->discoverOdooDeployments());
}

function rfmh_getDatabases($ns, $dep)
{
    list($rancher, $exec) = rfmh_getClients();

    $pod = $exec->getRunningPod($ns, $dep);
    if (!$pod) {
        return array('success' => false, 'error' => 'No running pod found. Deployment may be suspended — scale to 1 replica first.');
    }

    $podName    = $pod['metadata']['name'];
    $containers = isset($pod['spec']['containers']) ? $pod['spec']['containers'] : array();
    $cname      = $dep;
    foreach ($containers as $c) {
        $cname = isset($c['name']) ? $c['name'] : $dep;
        break;
    }

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
}

function rfmh_runSafetyTests()
{
    $sourceNs  = isset($_POST['source_ns'])  ? $_POST['source_ns']  : '';
    $sourceDep = isset($_POST['source_dep']) ? $_POST['source_dep'] : '';
    $sourceDb  = isset($_POST['source_db'])  ? $_POST['source_db']  : '';
    $destNs    = isset($_POST['dest_ns'])    ? $_POST['dest_ns']    : '';
    $destDep   = isset($_POST['dest_dep'])   ? $_POST['dest_dep']   : '';
    $destDb    = isset($_POST['dest_db'])    ? $_POST['dest_db']    : '';
    $overwrite = isset($_POST['overwrite'])  && $_POST['overwrite'] === '1';

    list($rancher, $exec) = rfmh_getClients();
    $si      = rfmh_getDepInfo($exec, $sourceNs, $sourceDep);
    $di      = rfmh_getDepInfo($exec, $destNs,   $destDep);
    $checker = new \RancherFleet\Migration\MigrationSafetyChecker($exec, $rancher);
    $results = $checker->runAll($si, $di, array(
        'source_db'       => $sourceDb,
        'dest_db'         => $destDb,
        'allow_overwrite' => $overwrite,
    ));

    return array(
        'success'     => true,
        'results'     => $results,
        'summary'     => \RancherFleet\Migration\MigrationSafetyChecker::summary($results),
        'can_migrate' => \RancherFleet\Migration\MigrationSafetyChecker::allPassed($results),
    );
}

function rfmh_runMigration()
{
    $sourceNs  = isset($_POST['source_ns'])  ? $_POST['source_ns']  : '';
    $sourceDep = isset($_POST['source_dep']) ? $_POST['source_dep'] : '';
    $sourceDb  = isset($_POST['source_db'])  ? $_POST['source_db']  : '';
    $destNs    = isset($_POST['dest_ns'])    ? $_POST['dest_ns']    : '';
    $destDep   = isset($_POST['dest_dep'])   ? $_POST['dest_dep']   : '';
    $destDb    = isset($_POST['dest_db'])    ? $_POST['dest_db']    : '';
    $log       = array();

    list($rancher, $exec) = rfmh_getClients();
    $si = rfmh_getDepInfo($exec, $sourceNs, $sourceDep);
    $di = rfmh_getDepInfo($exec, $destNs,   $destDep);

    $sourcePod = $exec->getRunningPod($sourceNs, $sourceDep);
    $destPod   = $exec->getRunningPod($destNs,   $destDep);

    if (!$sourcePod || !$destPod) {
        return array('success' => false, 'error' => 'Pods not running', 'log' => $log);
    }

    $sourcePodName = $sourcePod['metadata']['name'];
    $destPodName   = $destPod['metadata']['name'];
    $sourceEnv     = $exec->getPodEnvVars($sourcePod, $si['container_name']);
    $destEnv       = $exec->getPodEnvVars($destPod,   $di['container_name']);
    $sourceDbConf  = $exec->extractDbConfig($sourceEnv);
    $destDbConf    = $exec->extractDbConfig($destEnv);
    $sourceFs      = $exec->detectFilestore($sourceNs, $sourceDep);
    $destFs        = $exec->detectFilestore($destNs,   $destDep);

    $log[] = array('time' => date('H:i:s'), 'level' => 'info', 'msg' => "Starting: {$sourceNs}/{$sourceDep} -> {$destNs}/{$destDep}");

    // Suspend source
    $rancher->scaleAllDeployments($sourceNs, 0);
    sleep(3);
    $log[] = array('time' => date('H:i:s'), 'level' => 'success', 'msg' => "Source suspended");

    // Scale to 1 for dump
    $rancher->scaleAllDeployments($sourceNs, 1);
    sleep(5);
    $sourcePod = null;
    for ($i = 0; $i < 6; $i++) {
        $sourcePod = $exec->getRunningPod($sourceNs, $sourceDep);
        if ($sourcePod) break;
        sleep(5);
    }
    if (!$sourcePod) {
        return array('success' => false, 'log' => $log, 'error' => 'Source pod did not start for dump');
    }
    $sourcePodName = $sourcePod['metadata']['name'];
    $log[] = array('time' => date('H:i:s'), 'level' => 'success', 'msg' => "Source pod ready: {$sourcePodName}");

    // Migrate database
    $dbResult = $exec->migrateDatabase(
        array('namespace' => $sourceNs, 'pod_name' => $sourcePodName, 'container_name' => $si['container_name'], 'db_config' => $sourceDbConf, 'db_name' => $sourceDb),
        array('namespace' => $destNs,   'pod_name' => $destPodName,   'container_name' => $di['container_name'], 'db_config' => $destDbConf,   'db_name' => $destDb)
    );
    if (!$dbResult['success']) {
        $rancher->scaleAllDeployments($sourceNs, 0);
        $log[] = array('time' => date('H:i:s'), 'level' => 'error', 'msg' => "DB FAILED: " . $dbResult['message']);
        return array('success' => false, 'log' => $log, 'error' => $dbResult['message']);
    }
    $log[] = array('time' => date('H:i:s'), 'level' => 'success', 'msg' => $dbResult['message']);

    // Migrate filestore
    $fsMigrated = false;
    if ($sourceFs['pvc_name'] && $destFs['pvc_name']) {
        $fsResult = $exec->migrateFilestore(
            array('namespace' => $sourceNs, 'pod_name' => $sourcePodName, 'container_name' => $si['container_name'], 'mount_path' => $sourceFs['mount_path']),
            array('namespace' => $destNs,   'pod_name' => $destPodName,   'container_name' => $di['container_name'], 'mount_path' => $destFs['mount_path'])
        );
        $log[] = array('time' => date('H:i:s'), 'level' => $fsResult['success'] ? 'success' : 'warn', 'msg' => $fsResult['message']);
        $fsMigrated = $fsResult['success'];
    } else {
        $log[] = array('time' => date('H:i:s'), 'level' => 'info', 'msg' => "No PVC filestore detected — skipping");
    }

    // Scale source back to 0
    $rancher->scaleAllDeployments($sourceNs, 0);
    $log[] = array('time' => date('H:i:s'), 'level' => 'success', 'msg' => "Done. Both deployments suspended. Start destination manually when ready.");

    \RancherFleet\Logger::info("Migration complete: {$sourceNs}/{$sourceDep} -> {$destNs}/{$destDep}");

    return array(
        'success'            => true,
        'log'                => $log,
        'db_migrated'        => true,
        'filestore_migrated' => $fsMigrated,
    );
}
