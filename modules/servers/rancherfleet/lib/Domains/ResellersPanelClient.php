<?php
/**
 * ResellersPanelClient
 *
 * Thin wrapper around the ResellersPanel.com Reseller API (documented in
 * the project's ResellersPanelAPI.pdf, v2.4.1, June 2025).
 *
 * Important differences from a typical registrar API (e.g. ResellerClub),
 * which shaped how this client and DomainOrderManager are built:
 *
 *   - Auth is auth_username (store name) + auth_password (RSP password) as
 *     query parameters, not a reseller-ID/api-key pair.
 *   - This is a thin ORDER-SUBMISSION API. There is no documented endpoint
 *     to list a client's existing domains, fetch a domain's expiry date,
 *     or read back currently-configured DNS/nameservers. Anything in that
 *     category must be tracked by US at the time we make the relevant
 *     call — see DomainRecordStore.
 *   - domains:change_dns sets NAMESERVERS only — there is no per-record
 *     (CNAME/A) DNS management endpoint. Actual DNS hosting (the www
 *     CNAME, root A record) is therefore handled by Cloudflare, with this
 *     client only pointing the domain's nameservers at Cloudflare's via
 *     change_dns. See CloudflareClient.
 *   - order:create and renew:create are REDIRECT-based: a successful call
 *     returns redirect_url/method/parameters describing an HTML form the
 *     end user's browser must submit to complete payment with a payment
 *     processor. Using payment_method=Wallet avoids this entirely (pays
 *     from the already-funded reseller wallet balance), which is what
 *     this client defaults to, since the customer's card is charged
 *     separately via WHMCS and a second payment redirect would be
 *     confusing and redundant.
 */

namespace RancherFleet\Domains;

class ResellersPanelClient
{
    private $baseUrl;
    private $authUsername;
    private $authPassword;
    private $timeout = 30;

    /**
     * @param string $baseUrl       e.g. "https://api.resellerspanel.com" —
     *                                confirm the exact API host with
     *                                ResellersPanel support / control panel;
     *                                the documentation describes the query
     *                                format but not a single canonical host.
     * @param string $authUsername  Store name (auth_username)
     * @param string $authPassword  RSP password (auth_password)
     */
    public function __construct($baseUrl, $authUsername, $authPassword)
    {
        $this->baseUrl      = rtrim($baseUrl, '/');
        $this->authUsername = $authUsername;
        $this->authPassword = $authPassword;
    }

    // -----------------------------------------------------------------------
    // Availability
    // -----------------------------------------------------------------------

    /**
     * Checks domain name availability.
     * Section: domains, Command: check
     *
     * @param  string $name  SLD only, e.g. "example"
     * @param  array  $tlds  e.g. array('com', 'net'). Empty = API's default list.
     * @return array  Decoded 'result' array from the API response.
     * @throws ResellersPanelApiException
     */
    public function checkAvailability($name, array $tlds = array())
    {
        $params = array(
            'section' => 'domains',
            'command' => 'check',
            'name'    => $name,
        );
        foreach ($tlds as $i => $tld) {
            $params["tlds[{$i}]"] = $tld;
        }

        $response = $this->singleCommandRequest($params);

        // ResellersPanel wraps responses in a numbered-key envelope
        // (e.g. {"1": {...}}) even for single-command requests — confirmed
        // directly against the live API, not documented in the PDF spec.
        // Unwrap it before looking for error_code/result.
        $inner = $this->unwrapResponse($response);
        $this->assertNoError($inner);

        return isset($inner['result']) ? $inner['result'] : array();
    }

    // -----------------------------------------------------------------------
    // Pricing
    // -----------------------------------------------------------------------

    /**
     * Returns regular (non-promotional) domain prices for all offered TLDs.
     * Section: products, Command: get_registerdomains
     *
     * @return array  Decoded 'registerdomains' array.
     * @throws ResellersPanelApiException
     */
    public function getRegisterDomainPrices()
    {
        $params = array(
            'section' => 'products',
            'command' => 'get_registerdomains',
        );
        $response = $this->singleCommandRequest($params);
        $inner = $this->unwrapResponse($response);
        $this->assertNoError($inner);

        return isset($inner['registerdomains']) ? $inner['registerdomains'] : array();
    }

    /**
     * Extracts the 1-year price for a single TLD from a pricing array
     * previously returned by getRegisterDomainPrices().
     *
     * The documented response shape for products section calls collapses
     * by period/currency when only one is present (see "no_collapse"
     * parameters in the docs) — this method handles both the collapsed
     * and uncollapsed shapes defensively, since which one is returned
     * depends on how many periods/currencies this store is configured
     * with, not something we control per-call without passing
     * periods_no_collapse=1 / currencies_no_collapse=1 explicitly.
     *
     * @param  array  $pricing
     * @param  string $tld
     * @param  string $currency  default 'USD'
     * @return float|null
     */
    public function extractYearOnePrice(array $pricing, $tld, $currency = 'USD')
    {
        foreach ($pricing as $entry) {
            $entryTld = isset($entry['tld']) ? trim($entry['tld'], '.') : '';
            if (strcasecmp($entryTld, $tld) !== 0) {
                continue;
            }

            // Try a few plausible shapes for where the price lives.
            $candidates = array();
            if (isset($entry['price']['period_12'][$currency])) {
                $candidates[] = $entry['price']['period_12'][$currency];
            }
            if (isset($entry['price'][$currency]['period_12'])) {
                $candidates[] = $entry['price'][$currency]['period_12'];
            }
            if (isset($entry['price']) && is_numeric($entry['price'])) {
                $candidates[] = $entry['price'];
            }
            if (isset($entry['period_12'][$currency])) {
                $candidates[] = $entry['period_12'][$currency];
            }

            foreach ($candidates as $c) {
                if (is_numeric($c)) {
                    return (float)$c;
                }
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    /**
     * Submits a domain registration (or transfer) order, paid from the
     * reseller wallet balance (payment_method=Wallet) rather than a
     * redirect-based processor — the customer's actual charge happens
     * separately via WHMCS, so no second payment UI should be shown here.
     *
     * Section: order, Command: order_domains
     *
     * This is the correct command for a DOMAIN-ONLY purchase. The
     * sibling command order:create is for hosting PLAN signups that can
     * optionally bundle a domain — it requires a 'plan' ID and is not
     * usable for a pure domain purchase, so it is intentionally not used
     * here.
     *
     * @param  array $params  Required keys per the API docs: client_id
     *   or username, ip, currency, price_type, payment_method (defaulted
     *   below), country, return_url, cancel_url, domains (array — see
     *   $domainEntry shape below).
     * @param  array $domainEntry  keys: type ('register'|'transfer'), sld,
     *   tld, period (years), contacts (array — registrant required,
     *   admin/tech/billing optional and default to registrant),
     *   epp (optional, transfer only)
     *
     * @return array  Full decoded response (redirect_url etc., though
     *                  with payment_method=Wallet these should not be
     *                  needed for a successful order).
     * @throws ResellersPanelApiException
     */
    public function registerDomain(array $params, array $domainEntry)
    {
        $defaults = array(
            'section'        => 'order',
            'command'        => 'order_domains',
            'payment_method' => 'Wallet',
        );
        $body = array_merge($defaults, $params, array(
            'domains' => array($domainEntry),
        ));

        $response = $this->singleCommandRequest($body, 'POST');
        $inner = $this->unwrapResponse($response);
        $this->assertNoError($inner);

        return $inner;
    }

    // -----------------------------------------------------------------------
    // Renewal
    // -----------------------------------------------------------------------

    /**
     * Submits a domain renewal order, paid from the reseller wallet.
     * Section: renew, Command: create
     *
     * @param  array  $clientIdentity  array('client_id' => ...) OR
     *                                  array('username' => ...)
     * @param  string $sld
     * @param  string $tld
     * @param  int    $periodYears
     * @param  array  $extra           Additional required fields: ip,
     *                                   currency, price_type, country,
     *                                   return_url, cancel_url
     * @return array
     * @throws ResellersPanelApiException
     */
    public function renewDomain(array $clientIdentity, $sld, $tld, $periodYears, array $extra = array())
    {
        $body = array_merge(array(
            'section'        => 'renew',
            'command'        => 'create',
            'payment_method' => 'Wallet',
            'renew_plan'     => 0,
            'domains'        => array(
                array('sld' => $sld, 'tld' => $tld, 'period' => $periodYears),
            ),
        ), $clientIdentity, $extra);

        $response = $this->singleCommandRequest($body, 'POST');
        $inner = $this->unwrapResponse($response);
        $this->assertNoError($inner);

        return $inner;
    }

    // -----------------------------------------------------------------------
    // Nameservers
    // -----------------------------------------------------------------------

    /**
     * Sets a domain's nameservers — the only DNS-level control this API
     * exposes. Used to point a freshly registered/renewed domain at
     * Cloudflare's assigned nameservers.
     *
     * Section: domains, Command: change_dns
     *
     * @param  string $sld
     * @param  string $tld
     * @param  array  $nameservers  2-4 nameserver hostnames, e.g. Cloudflare's
     * @return bool    true on success
     * @throws ResellersPanelApiException
     */
    public function setNameservers($sld, $tld, array $nameservers)
    {
        if (count($nameservers) < 2) {
            throw new ResellersPanelApiException('setNameservers requires at least ns1 and ns2.');
        }

        $body = array(
            'section' => 'domains',
            'command' => 'change_dns',
            'sld'     => $sld,
            'tld'     => $tld,
            'ns1'     => $nameservers[0],
            'ns2'     => $nameservers[1],
        );
        if (isset($nameservers[2])) $body['ns3'] = $nameservers[2];
        if (isset($nameservers[3])) $body['ns4'] = $nameservers[3];

        $response = $this->singleCommandRequest($body, 'POST');
        $inner = $this->unwrapResponse($response);
        $this->assertNoError($inner);

        return true;
    }

    // -----------------------------------------------------------------------
    // Contacts
    // -----------------------------------------------------------------------

    /**
     * Sets a domain's registrant/admin/tech/billing contacts. Per the API
     * docs, only 'registrant' is required — admin/tech/billing default to
     * the registrant's details if omitted, which is the behavior this
     * method relies on by passing only registrant unless told otherwise.
     *
     * Section: domains, Command: set_contacts
     *
     * @param  string $sld
     * @param  string $tld
     * @param  array  $registrant  keys: firstname, lastname, address1,
     *   postalcode, city, stateprovince, country, emailaddress, phone
     *   (organizationname, address2, fax optional)
     * @return bool
     * @throws ResellersPanelApiException
     */
    public function setContacts($sld, $tld, array $registrant)
    {
        $body = array(
            'section'    => 'domains',
            'command'    => 'set_contacts',
            'sld'        => $sld,
            'tld'        => $tld,
            'registrant' => $registrant,
        );

        $response = $this->singleCommandRequest($body, 'POST');
        $inner = $this->unwrapResponse($response);
        $this->assertNoError($inner);

        return true;
    }

    // -----------------------------------------------------------------------
    // Internal: error handling
    // -----------------------------------------------------------------------

    /**
     * Unwraps ResellersPanel's numbered-key response envelope.
     *
     * Confirmed directly against the live API: even single-command
     * requests come back wrapped as {"1": {...actual response...}},
     * matching the documented shape for MULTI-command queries — not
     * what the single-command query documentation implies. This is
     * undocumented behavior discovered through testing, not described
     * in the official PDF spec.
     *
     * Defensively also handles the case where a future API version (or
     * a different command) returns the inner shape directly, unwrapped.
     *
     * @param  array $response
     * @return array
     */
    private function unwrapResponse(array $response)
    {
        if (isset($response['error_code'])) {
            // Already unwrapped — not enveloped.
            return $response;
        }

        if (isset($response['1']) && is_array($response['1'])) {
            return $response['1'];
        }

        // Fall back to the first array value found, in case the envelope
        // key isn't literally "1" for some response types.
        foreach ($response as $value) {
            if (is_array($value) && isset($value['error_code'])) {
                return $value;
            }
        }

        return $response;
    }

    private function assertNoError(array $response)
    {
        $code = isset($response['error_code']) ? (int)$response['error_code'] : 0;
        if ($code !== 0) {
            $msg = isset($response['error_msg']) ? $response['error_msg'] : 'Unknown error';
            throw new ResellersPanelApiException("ResellersPanel API error [{$code}]: {$msg}");
        }
    }

    // -----------------------------------------------------------------------
    // HTTP transport
    // -----------------------------------------------------------------------

    /**
     * Builds and sends a single-command query per the documented format:
     *   ?auth_username=...&auth_password=...&section=...&command=...&{params}
     *
     * Nested arrays (e.g. domains[0][sld]=foo) are flattened into the
     * documented bracket-index query syntax.
     */
    private function singleCommandRequest(array $params, $method = 'GET')
    {
        $query = array(
            'auth_username' => $this->authUsername,
            'auth_password' => $this->authPassword,
            'return_type'   => 'serialization', // PHP-serialized is easier to
                                                 // parse reliably than the
                                                 // XML alternative this API
                                                 // defaults to.
        );

        $flat = array();
        $this->flattenParams($params, '', $flat);
        $query = array_merge($query, $flat);

        $queryString = http_build_query($query);
        $url = $this->baseUrl . '/';

        if ($method === 'GET') {
            return $this->request('GET', $url . '?' . $queryString);
        }

        return $this->request('POST', $url, $queryString);
    }

    /**
     * Flattens a nested params array into bracket-indexed keys matching
     * the API's documented multi-value syntax, e.g.:
     *   array('domains' => array(array('sld'=>'x','tld'=>'com')))
     *   -> domains[0][sld]=x&domains[0][tld]=com
     */
    private function flattenParams($params, $prefix, array &$out)
    {
        foreach ($params as $key => $value) {
            $fullKey = $prefix === '' ? $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                $this->flattenParams($value, $fullKey, $out);
            } else {
                $out[$fullKey] = $value;
            }
        }
    }

    private function request($method, $url, $body = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  $method);

        if ($method === 'POST' && $body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new ResellersPanelApiException('cURL error requesting [' . $url . ']: ' . $err);
        }
        if ($code >= 400) {
            throw new ResellersPanelApiException("ResellersPanel HTTP error [{$code}]: " . substr($raw, 0, 300));
        }

        // return_type=serialization -> PHP serialized response.
        $decoded = @unserialize($raw);
        if ($decoded === false && $raw !== 'b:0;') {
            throw new ResellersPanelApiException('Failed to unserialize ResellersPanel response: ' . substr($raw, 0, 300));
        }

        return is_array($decoded) ? $decoded : array();    }
}

class ResellersPanelApiException extends \Exception
{
}
