<?php
/**
 * DomainOrderManager
 *
 * Orchestrates domain purchase, renewal, and reconnection end-to-end
 * across three systems:
 *   - WHMCS (capture/refund customer payment via stored card)
 *   - ResellersPanel (domain registration/renewal, nameserver assignment)
 *   - Cloudflare (actual DNS hosting — CNAME/A records; see CloudflareClient
 *     for why ResellersPanel itself can't do this)
 *
 * Three flows:
 *   placeOrder()              — new domain purchase
 *   renewDomain()              — renew an existing (tracked) domain
 *   reconnectExistingDomain()  — customer enters a domain they already
 *                                 registered with us previously (e.g. on
 *                                 another instance); verify it's ours via
 *                                 DomainRecordStore, then (re)wire DNS if
 *                                 not already marked wired.
 *
 * Payment is captured via WHMCS BEFORE any ResellersPanel/Cloudflare call,
 * and refunded only after DomainRetryStore's retry cap is exhausted —
 * matching the original agreed design (give the reseller wallet time to
 * be topped up rather than refunding on the first failure).
 */

namespace RancherFleet\Domains;

use RancherFleet\Logger;

class DomainOrderManager
{
    /** @var ResellersPanelClient */
    private $rp;

    /** @var CloudflareClient */
    private $cf;

    /** @var \RancherFleet\RancherClient */
    private $rancher;

    /** @var IngressHelper */
    private $ingress;

    public function __construct(ResellersPanelClient $rp, CloudflareClient $cf, \RancherFleet\RancherClient $rancher)
    {
        $this->rp      = $rp;
        $this->cf      = $cf;
        $this->rancher = $rancher;
        $this->ingress = new IngressHelper($rancher);
    }

    // -----------------------------------------------------------------------
    // Payment (WHMCS credit balance)
    // -----------------------------------------------------------------------

    /**
     * Charges the client's WHMCS credit balance.
     * Creates a paid invoice for the paper trail.
     */
    public function capturePayment($clientId, $amount, $currencyCode, $description)
    {
        $clientId = (int)$clientId;
        $amount   = round((float)$amount, 2);

        // Check balance
        try {
            $credit  = \WHMCS\Database\Capsule::table('tblclients')->where('id', $clientId)->value('credit');
            $balance = $credit !== null ? (float)$credit : 0.0;
        } catch (\Exception $e) {
            return array('success' => false, 'error' => 'Could not read credit balance: ' . $e->getMessage());
        }

        if ($balance < $amount) {
            return array(
                'success' => false,
                'error'   => 'Insufficient credit balance. Available: $' . number_format($balance, 2)
                           . ', required: $' . number_format($amount, 2) . '.',
            );
        }

        // Create invoice
        try {
            $invoiceResult = localAPI('CreateInvoice', array(
                'userid'           => $clientId,
                'status'           => 'Unpaid',
                'itemdescription1' => $description,
                'itemamount1'      => $amount,
                'itemtaxed1'       => false,
                'paymentmethod'    => 'credit',
            ));
            if (!isset($invoiceResult['result']) || $invoiceResult['result'] !== 'success') {
                $err = isset($invoiceResult['message']) ? $invoiceResult['message'] : json_encode($invoiceResult);
                return array('success' => false, 'error' => 'Could not create invoice: ' . $err);
            }
            $invoiceId = (int)$invoiceResult['invoiceid'];
        } catch (\Exception $e) {
            return array('success' => false, 'error' => 'Invoice creation error: ' . $e->getMessage());
        }

        // Apply credit to invoice atomically — deducts balance and marks paid.
        try {
            $creditResult = localAPI('ApplyCredit', array(
                'invoiceid' => $invoiceId,
                'amount'    => number_format($amount, 2, '.', ''),
                'noemail'   => true,
            ));
            if (!isset($creditResult['result']) || $creditResult['result'] !== 'success') {
                $err = isset($creditResult['message']) ? $creditResult['message'] : json_encode($creditResult);
                try { localAPI('UpdateInvoice', array('invoiceid' => $invoiceId, 'status' => 'Cancelled')); } catch (\Exception $ve) {}
                return array('success' => false, 'error' => 'Credit deduction failed: ' . $err);
            }
        } catch (\Exception $e) {
            try { localAPI('UpdateInvoice', array('invoiceid' => $invoiceId, 'status' => 'Cancelled')); } catch (\Exception $ve) {}
            return array('success' => false, 'error' => 'Credit deduction error: ' . $e->getMessage());
        }

        Logger::info("DomainOrderManager: charged \${$amount} from client {$clientId} credit, invoice #{$invoiceId}");
        return array('success' => true, 'invoiceId' => $invoiceId, 'transactionId' => 'credit');
    }

    public function refundPayment($invoiceId, $transactionId, $amount)
    {
        try {
            $clientId = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', (int)$invoiceId)->value('userid');
            if (!$clientId) {
                return array('success' => false, 'error' => 'Invoice not found');
            }
            $result = localAPI('AddCredit', array(
                'clientid'    => (int)$clientId,
                'description' => 'Refund for invoice #' . $invoiceId . ' — domain order could not be completed',
                'amount'      => round((float)$amount, 2),
            ));
            if (!isset($result['result']) || $result['result'] !== 'success') {
                $err = isset($result['message']) ? $result['message'] : json_encode($result);
                Logger::error("DomainOrderManager: credit refund failed invoice #{$invoiceId}: {$err}");
                return array('success' => false, 'error' => $err);
            }
            try { localAPI('UpdateInvoice', array('invoiceid' => $invoiceId, 'status' => 'Cancelled')); } catch (\Exception $e) {}
            Logger::info("DomainOrderManager: refunded \${$amount} credit to client {$clientId} invoice #{$invoiceId}");
            return array('success' => true, 'refundTransactionId' => 'credit-refund');
        } catch (\Exception $e) {
            Logger::error("DomainOrderManager: refundPayment exception: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    private function findTransactionIdForInvoice($invoiceId)
    {
        return 'credit';
    }

    // -----------------------------------------------------------------------
    // DNS wiring — shared by purchase, renewal, and reconnect flows
    // -----------------------------------------------------------------------

    /**
     * Creates (or reuses) a Cloudflare zone for the domain, points the
     * domain's nameservers at it via ResellersPanel, and creates the www
     * CNAME + root A record. Marks DomainRecordStore as DNS-wired on
     * success.
     *
     * Idempotent: safe to call again on a domain that's already wired.
     *
     * @param  string $sld
     * @param  string $tld
     * @param  string $cnameTarget
     * @param  string $namespace
     * @param  string $backendServiceName
     * @param  int    $backendServicePort
     * @return array  array('success' => bool, 'error' => string|null, 'cloudflareZoneId' => string|null)
     */
    public function wireDns($sld, $tld, $cnameTarget, $namespace, $backendServiceName, $backendServicePort = 80)
    {
        $domain = $sld . '.' . $tld;

        try {
            $zone = $this->cf->findZone($domain);
            if (!$zone) {
                $zone = $this->cf->createZone($domain);
            }

            $this->rp->setNameservers($sld, $tld, $zone['nameservers']);

            try {
                $this->cf->addCnameRecord($zone['zoneId'], 'www', $cnameTarget);
            } catch (CloudflareApiException $e) {
                Logger::info("wireDns: addCnameRecord for {$domain} returned: " . $e->getMessage());
            }

            $rootIp = $this->ingress->getIngressExternalAddress();
            try {
                $this->cf->addARecord($zone['zoneId'], '@', $rootIp);
            } catch (CloudflareApiException $e) {
                Logger::info("wireDns: addARecord for {$domain} returned: " . $e->getMessage());
            }

            $this->ingress->createRootRedirect($namespace, $domain, $backendServiceName, $backendServicePort);

            DomainRecordStore::markDnsWired($sld, $tld);

            Logger::info("wireDns: SUCCESS for {$domain} (zone={$zone['zoneId']})");

            return array('success' => true, 'cloudflareZoneId' => $zone['zoneId']);

        } catch (\Exception $e) {
            Logger::error("wireDns: FAILED for {$domain}: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // New domain purchase
    // -----------------------------------------------------------------------

    public function placeOrder(array $orderData)
    {
        $capture = $this->capturePayment(
            $orderData['clientId'],
            $orderData['chargeAmount'],
            $orderData['chargeCurrency'],
            'Domain registration: ' . $orderData['sld'] . '.' . $orderData['tld']
        );

        if (!$capture['success']) {
            return array('status' => 'declined', 'error' => $capture['error']);
        }

        $orderData['invoiceId']     = $capture['invoiceId'];
        $orderData['transactionId'] = $capture['transactionId'];

        $regResult = $this->attemptRegistration($orderData);

        if ($regResult['success']) {
            return array('status' => 'completed', 'orderToken' => null);
        }

        $token = DomainRetryStore::create($orderData);
        DomainRetryStore::recordFailure($token, $regResult['error']);

        Logger::info("DomainOrderManager: domain {$orderData['sld']}.{$orderData['tld']} payment captured, registration pending retry. token={$token}");

        return array('status' => 'processing', 'orderToken' => $token, 'error' => $regResult['error']);
    }

    public function attemptRegistration(array $orderData)
    {
        $sld = $orderData['sld'];
        $tld = $orderData['tld'];

        try {
            $this->rp->registerDomain(
                array(
                    'client_id'  => $orderData['clientId'],
                    'ip'         => $orderData['ip'],
                    'currency'   => $orderData['chargeCurrency'],
                    'price_type' => 'price',
                    'country'    => $orderData['country'],
                    'return_url' => 'https://thankyou.duoservers.com/',
                    'cancel_url' => 'https://thankyou.duoservers.com/',
                ),
                array(
                    'type'     => 'register',
                    'sld'      => $sld,
                    'tld'      => $tld,
                    'period'   => isset($orderData['years']) ? $orderData['years'] : 1,
                    'contacts' => $orderData['registrant'],
                )
            );

            Logger::info("DomainOrderManager: ResellersPanel registration SUCCESS {$sld}.{$tld}");

        } catch (ResellersPanelApiException $e) {
            Logger::info("DomainOrderManager: ResellersPanel registration FAILED {$sld}.{$tld}: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }

        $years     = isset($orderData['years']) ? (int)$orderData['years'] : 1;
        $expiresAt = date('Y-m-d', strtotime("+{$years} years"));

        DomainRecordStore::create($sld, $tld, array(
            'serviceId'           => $orderData['serviceId'],
            'clientId'            => $orderData['clientId'],
            'namespace'           => $orderData['namespace'],
            'registeredAt'        => date('Y-m-d'),
            'periodYears'         => $years,
            'expiresAt'           => $expiresAt,
            'cnameHost'           => 'www',
            'cnameTarget'         => $orderData['cnameTarget'],
            'backendServiceName'  => $orderData['backendServiceName'],
            'backendServicePort'  => isset($orderData['backendServicePort']) ? $orderData['backendServicePort'] : 80,
        ));

        $dnsResult = $this->wireDns(
            $sld, $tld,
            $orderData['cnameTarget'],
            $orderData['namespace'],
            $orderData['backendServiceName'],
            isset($orderData['backendServicePort']) ? $orderData['backendServicePort'] : 80
        );

        if (!$dnsResult['success']) {
            Logger::error("DomainOrderManager: {$sld}.{$tld} registered but DNS wiring failed: " . $dnsResult['error']);
        }

        return array('success' => true);
    }

    // -----------------------------------------------------------------------
    // Retry path (cron, registration failures only)
    // -----------------------------------------------------------------------

    public function retryPendingOrder($token, array $orderData)
    {
        $regResult = $this->attemptRegistration($orderData);

        if ($regResult['success']) {
            DomainRetryStore::markSucceeded($token, $regResult);
            return "SUCCESS: {$orderData['sld']}.{$orderData['tld']} registered on retry.";
        }

        $exhausted = DomainRetryStore::recordFailure($token, $regResult['error']);

        if (!$exhausted) {
            return "RETRY SCHEDULED: {$orderData['sld']}.{$orderData['tld']} - {$regResult['error']}";
        }

        $refund = $this->refundPayment($orderData['invoiceId'], $orderData['transactionId'], $orderData['chargeAmount']);

        if ($refund['success']) {
            DomainRetryStore::markRefunded($token, $refund['refundTransactionId']);
            return "EXHAUSTED + REFUNDED: {$orderData['sld']}.{$orderData['tld']} could not be registered after max attempts; customer refunded.";
        }

        DomainRetryStore::markRefundFailed($token, $refund['error']);
        return "EXHAUSTED + REFUND FAILED: {$orderData['sld']}.{$orderData['tld']} - manual intervention required. Refund error: {$refund['error']}";
    }

    // -----------------------------------------------------------------------
    // Renewal
    // -----------------------------------------------------------------------

    public function renewDomain(array $params)
    {
        $sld = $params['sld'];
        $tld = $params['tld'];

        $existing = DomainRecordStore::get($sld, $tld);
        if (!$existing) {
            return array('status' => 'error', 'error' => "No record of {$sld}.{$tld} being registered through this account.");
        }

        $capture = $this->capturePayment(
            $params['clientId'],
            $params['chargeAmount'],
            $params['chargeCurrency'],
            'Domain renewal: ' . $sld . '.' . $tld
        );

        if (!$capture['success']) {
            return array('status' => 'declined', 'error' => $capture['error']);
        }

        try {
            $years = isset($params['years']) ? (int)$params['years'] : 1;

            $this->rp->renewDomain(
                array('client_id' => $params['clientId']),
                $sld, $tld, $years,
                array(
                    'ip'         => $params['ip'],
                    'currency'   => $params['chargeCurrency'],
                    'price_type' => 'price',
                    'country'    => $params['country'],
                    'return_url' => 'https://thankyou.duoservers.com/',
                    'cancel_url' => 'https://thankyou.duoservers.com/',
                )
            );

            $currentExpiry = isset($existing['expiresAt']) ? $existing['expiresAt'] : date('Y-m-d');
            $newExpiry     = date('Y-m-d', strtotime($currentExpiry . " +{$years} years"));

            DomainRecordStore::recordRenewal($sld, $tld, $newExpiry);

            Logger::info("DomainOrderManager: renewal SUCCESS {$sld}.{$tld} new expiry={$newExpiry}");

            return array('status' => 'completed', 'newExpiry' => $newExpiry);

        } catch (ResellersPanelApiException $e) {
            Logger::error("DomainOrderManager: renewal FAILED {$sld}.{$tld}: " . $e->getMessage());

            $refund = $this->refundPayment($capture['invoiceId'], $capture['transactionId'], $params['chargeAmount']);
            if (!$refund['success']) {
                Logger::error("DomainOrderManager: renewal refund ALSO FAILED {$sld}.{$tld}: " . $refund['error']);
            }

            return array('status' => 'error', 'error' => $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Reconnect an existing domain
    // -----------------------------------------------------------------------

    public function reconnectExistingDomain($sld, $tld, $serviceId, $namespace, $cnameTarget, $backendServiceName, $backendServicePort = 80)
    {
        $existing = DomainRecordStore::get($sld, $tld);

        if (!$existing) {
            return array(
                'status' => 'not_found',
                'error'  => "{$sld}.{$tld} was not found among domains registered through this account.",
            );
        }

        if (!empty($existing['dnsWiredAt']) && isset($existing['serviceId']) && (int)$existing['serviceId'] === (int)$serviceId) {
            return array('status' => 'connected', 'alreadyWired' => true);
        }

        $dnsResult = $this->wireDns($sld, $tld, $cnameTarget, $namespace, $backendServiceName, $backendServicePort);

        if (!$dnsResult['success']) {
            return array('status' => 'error', 'error' => $dnsResult['error']);
        }

        DomainRecordStore::create($sld, $tld, array_merge($existing, array(
            'serviceId'           => $serviceId,
            'namespace'           => $namespace,
            'cnameTarget'         => $cnameTarget,
            'backendServiceName'  => $backendServiceName,
            'backendServicePort'  => $backendServicePort,
        )));

        return array('status' => 'connected', 'alreadyWired' => false);
    }
}
