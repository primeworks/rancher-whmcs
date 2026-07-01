<?php
/**
 * RancherFleet Backup Webhook
 *
 * Receives POST notifications from the backup CronJob when a backup
 * completes. Stores the backup manifest (list of files, sizes, dates)
 * in tbladdonmodules so the client area can display available backups
 * without needing exec access into the pod.
 *
 * The CronJob posts to:
 *   https://{whmcs-host}/modules/servers/rancherfleet/backup_webhook.php
 *
 * POST body (JSON):
 *   {
 *     "service_id": 401,
 *     "order_num": "7375712254",
 *     "secret": "{shared secret from Module Settings}",
 *     "files": [
 *       {"name": "db-7375712254-2026-06-28.dump", "size": 1234567, "mtime": 1719532800, "type": "db"},
 *       {"name": "filestore-7375712254-2026-06-28.tar.gz", "size": 2345678, "mtime": 1719532801, "type": "filestore"}
 *     ]
 *   }
 *
 * Place this file at:
 *   {whmcs_root}/modules/servers/rancherfleet/backup_webhook.php
 *
 * The CronJob manifest must be updated to POST to this URL after
 * writing manifest.json. The shared secret must match the
 * 'Backup Auth Secret' Module Settings field on product 126.
 */

// Bootstrap WHMCS
define('WHMCS_ROOT', dirname(dirname(dirname(dirname(__FILE__)))));
if (!file_exists(WHMCS_ROOT . '/init.php')) {
    http_response_code(500);
    exit('WHMCS root not found');
}
require_once WHMCS_ROOT . '/init.php';
require_once WHMCS_ROOT . '/includes/functions.php';

use WHMCS\Database\Capsule;

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(array('error' => 'Method not allowed')));
}

// Parse JSON body
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data || !isset($data['service_id'], $data['order_num'], $data['secret'], $data['files'])) {
    http_response_code(400);
    exit(json_encode(array('error' => 'Invalid payload')));
}

$serviceId = (int)$data['service_id'];
$orderNum  = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['order_num']);
$secret    = $data['secret'];
$files     = $data['files'];

// Verify secret against the Module Settings value for product 126
// (or whichever product this service belongs to)
try {
    $productId = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->value('packageid');

    $storedSecret = Capsule::table('tblproducts')
        ->where('id', (int)$productId)
        ->value('configoption22');

    if (empty($storedSecret) || !hash_equals(trim($storedSecret), $secret)) {
        http_response_code(403);
        exit(json_encode(array('error' => 'Invalid secret')));
    }
} catch (\Exception $e) {
    http_response_code(500);
    exit(json_encode(array('error' => 'Database error')));
}

// Validate files array
$sanitised = array();
foreach ($files as $f) {
    if (!isset($f['name'], $f['size'], $f['mtime'], $f['type'])) continue;
    $name = basename($f['name']);
    if (!preg_match('/^(db|filestore)-[a-zA-Z0-9_-]+-\d{4}-\d{2}-\d{2}\.(dump|tar\.gz)$/', $name)) continue;
    $sanitised[] = array(
        'name'  => $name,
        'size'  => (int)$f['size'],
        'mtime' => (int)$f['mtime'],
        'type'  => in_array($f['type'], array('db', 'filestore')) ? $f['type'] : 'unknown',
    );
}

// Store in tbladdonmodules
$json   = json_encode($sanitised);
$exists = Capsule::table('tbladdonmodules')
    ->where('module', 'rancherfleet_backups')
    ->where('setting', 'manifest_' . $serviceId)
    ->exists();

if ($exists) {
    Capsule::table('tbladdonmodules')
        ->where('module', 'rancherfleet_backups')
        ->where('setting', 'manifest_' . $serviceId)
        ->update(array('value' => $json));
} else {
    Capsule::table('tbladdonmodules')->insert(array(
        'module'  => 'rancherfleet_backups',
        'setting' => 'manifest_' . $serviceId,
        'value'   => $json,
    ));
}

// Log it
logActivity("RancherFleet: backup manifest updated for service #{$serviceId} ({$orderNum}), " . count($sanitised) . " files");

http_response_code(200);
echo json_encode(array('ok' => true, 'files' => count($sanitised)));
