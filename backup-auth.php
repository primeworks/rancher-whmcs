<?php
/**
 * backup-auth.php
 *
 * Nginx auth_request validator for backup download links.
 * Place this file at a publicly reachable URL on your WHMCS server,
 * e.g. https://host.webdiscode.com/backup-auth.php
 *
 * nginx calls this before serving any file from backups.webdiscode.com.
 * Returns 200 (allow) or 403 (deny).
 *
 * Expected query parameters on the download URL:
 *   ?token={hmac}&expires={timestamp}&order={orderNum}
 *
 * The shared secret must match the 'Backup Auth Secret' Module Settings
 * field on product 126 in WHMCS.
 *
 * nginx configuration (in the backup server block):
 *   auth_request /auth;
 *   location = /auth {
 *     internal;
 *     proxy_pass https://host.webdiscode.com/backup-auth.php;
 *     proxy_pass_request_body off;
 *     proxy_set_header Content-Length "";
 *     proxy_set_header X-Original-URI $request_uri;
 *   }
 */

// -----------------------------------------------------------------------
// Configuration — must match 'Backup Auth Secret' in WHMCS Module Settings
// -----------------------------------------------------------------------
define('BACKUP_AUTH_SECRET', getenv('BACKUP_AUTH_SECRET') ?: 'REPLACE_WITH_YOUR_SECRET');

// -----------------------------------------------------------------------
// Validation
// -----------------------------------------------------------------------

$uri = isset($_SERVER['HTTP_X_ORIGINAL_URI'])
     ? $_SERVER['HTTP_X_ORIGINAL_URI']
     : (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');

// Parse query string from the original URI
$queryString = '';
if (strpos($uri, '?') !== false) {
    list($path, $queryString) = explode('?', $uri, 2);
} else {
    $path = $uri;
}
parse_str($queryString, $query);

$token    = isset($query['token'])   ? $query['token']              : '';
$expires  = isset($query['expires']) ? (int)$query['expires']       : 0;
$orderNum = isset($query['order'])   ? preg_replace('/[^a-zA-Z0-9_-]/', '', $query['order']) : '';

// Extract filename from path: /{orderNum}/{filename}
$pathParts = explode('/', trim($path, '/'));
$filename  = count($pathParts) >= 2 ? end($pathParts) : '';

// Check expiry
if ($expires < time()) {
    http_response_code(403);
    exit('Token expired');
}

// Check token
if (empty($token) || empty($orderNum) || empty($filename)) {
    http_response_code(403);
    exit('Missing parameters');
}

$data     = $orderNum . '|' . $filename . '|' . $expires;
$expected = hash_hmac('sha256', $data, BACKUP_AUTH_SECRET);

if (!hash_equals($expected, $token)) {
    http_response_code(403);
    exit('Invalid token');
}

// Valid — allow the request
http_response_code(200);
exit('OK');
