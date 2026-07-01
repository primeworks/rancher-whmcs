<?php
/**
 * CloudflareClient
 *
 * Minimal wrapper around the Cloudflare API v4, used as the DNS host for
 * domains purchased through ResellersPanel — whose own API only sets
 * nameservers and has no per-record (CNAME/A) management endpoint.
 *
 * Flow per domain:
 *   1. createZone($domain)              -> Cloudflare assigns 2 nameservers
 *   2. (caller passes those nameservers to ResellersPanelClient::setNameservers)
 *   3. addCnameRecord($zoneId, 'www', $target)
 *   4. addARecord($zoneId, '@', $ip)
 *
 * Auth: Bearer token (API Token, scoped to Zone:Edit + Zone:Read +
 * DNS:Edit at minimum). Created under My Profile > API Tokens in the
 * Cloudflare dashboard — not the legacy Global API Key.
 */

namespace RancherFleet\Domains;

class CloudflareClient
{
    const BASE_URL = 'https://api.cloudflare.com/client/v4';

    private $apiToken;
    private $timeout = 30;

    public function __construct($apiToken)
    {
        $this->apiToken = $apiToken;
    }

    /**
     * Creates a new zone (i.e. onboards a domain to Cloudflare DNS).
     *
     * @param  string $domainName  e.g. "example.com"
     * @return array  array('zoneId' => string, 'nameservers' => array)
     * @throws CloudflareApiException
     */
    public function createZone($domainName)
    {
        $response = $this->request('POST', '/zones', array(
            'name' => $domainName,
            // 'type' defaults to 'full' — Cloudflare becomes authoritative
            // DNS for the whole domain, which is what we want here.
        ));

        $result = isset($response['result']) ? $response['result'] : array();
        $zoneId = isset($result['id']) ? $result['id'] : null;
        $ns     = isset($result['name_servers']) ? $result['name_servers'] : array();

        if (!$zoneId) {
            throw new CloudflareApiException('createZone: no zone ID in response for ' . $domainName);
        }

        return array('zoneId' => $zoneId, 'nameservers' => $ns);
    }

    /**
     * Looks up an existing zone by domain name. Returns null if not found
     * (an expected case, not an error) rather than throwing.
     *
     * @param  string $domainName
     * @return array|null  array('zoneId' => string, 'nameservers' => array, 'status' => string)
     */
    public function findZone($domainName)
    {
        try {
            $response = $this->request('GET', '/zones?name=' . rawurlencode($domainName));
        } catch (CloudflareApiException $e) {
            return null;
        }

        $results = isset($response['result']) ? $response['result'] : array();
        if (empty($results)) {
            return null;
        }

        $zone = $results[0];
        return array(
            'zoneId'      => $zone['id'],
            'nameservers' => isset($zone['name_servers']) ? $zone['name_servers'] : array(),
            'status'      => isset($zone['status']) ? $zone['status'] : '',
        );
    }

    /**
     * Adds a CNAME record.
     *
     * @param  string $zoneId
     * @param  string $host    Subdomain label, e.g. "www" (not the full FQDN)
     * @param  string $target  e.g. "cowboy.webdiscode.com"
     * @param  bool   $proxied  Whether to proxy through Cloudflare (orange
     *                           cloud) vs DNS-only (gray cloud). DNS-only
     *                           is used by default since the origin
     *                           (Rancher ingress) already terminates TLS
     *                           and proxying would add an extra hop and
     *                           Cloudflare-side cert requirement that
     *                           isn't needed here.
     * @return string  record ID
     * @throws CloudflareApiException
     */
    public function addCnameRecord($zoneId, $host, $target, $proxied = false)
    {
        $response = $this->request('POST', '/zones/' . $zoneId . '/dns_records', array(
            'type'    => 'CNAME',
            'name'    => $host,
            'content' => $target,
            'ttl'     => 1, // automatic
            'proxied' => $proxied,
        ));

        $result = isset($response['result']) ? $response['result'] : array();
        if (empty($result['id'])) {
            throw new CloudflareApiException('addCnameRecord: no record ID in response');
        }
        return $result['id'];
    }

    /**
     * Adds an A record — used for the root/apex domain, which cannot
     * carry a CNAME per DNS spec.
     *
     * @param  string $zoneId
     * @param  string $host  '@' for the zone apex
     * @param  string $ip
     * @param  bool   $proxied
     * @return string  record ID
     * @throws CloudflareApiException
     */
    public function addARecord($zoneId, $host, $ip, $proxied = false)
    {
        $response = $this->request('POST', '/zones/' . $zoneId . '/dns_records', array(
            'type'    => 'A',
            'name'    => $host,
            'content' => $ip,
            'ttl'     => 1,
            'proxied' => $proxied,
        ));

        $result = isset($response['result']) ? $response['result'] : array();
        if (empty($result['id'])) {
            throw new CloudflareApiException('addARecord: no record ID in response');
        }
        return $result['id'];
    }

    // -----------------------------------------------------------------------
    // HTTP transport
    // -----------------------------------------------------------------------

    private function request($method, $path, array $body = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            self::BASE_URL . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json',
        ));

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new CloudflareApiException('cURL error: ' . $err);
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            throw new CloudflareApiException("Failed to decode Cloudflare response [{$code}]: " . substr($raw, 0, 300));
        }

        $success = isset($decoded['success']) ? $decoded['success'] : false;
        if (!$success || $code >= 400) {
            $errors = isset($decoded['errors']) ? json_encode($decoded['errors']) : 'unknown error';
            throw new CloudflareApiException("Cloudflare API error [{$code}]: {$errors}");
        }

        return $decoded;
    }
}

class CloudflareApiException extends \Exception
{
}
