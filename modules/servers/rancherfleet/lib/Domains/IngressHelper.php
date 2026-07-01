<?php
/**
 * IngressHelper
 *
 * Two responsibilities:
 *   1. Resolve the IP address customers' root-domain A records should
 *      point to — the external IP of the ingress controller's Service
 *      (LoadBalancer or NodePort), looked up dynamically from the cluster
 *      rather than hardcoded in module config, per the agreed design.
 *   2. Create/update a Traefik Middleware + Ingress pair per domain that
 *      issues an HTTP 301 from the root domain to "www.{domain}", so that
 *      the root->www redirect happens at the HTTP layer (your
 *      infrastructure) rather than relying on the registrar's own
 *      forwarding product.
 *
 * Assumes a Traefik ingress controller (the default on Rancher-provisioned
 * clusters). Traefik requires the redirect to be declared as a separate
 * Middleware custom resource (traefik.io/v1alpha1) and referenced from the
 * Ingress via the "traefik.ingress.kubernetes.io/router.middlewares"
 * annotation — unlike NGINX, which supports a single redirect annotation
 * directly on the Ingress.
 */

namespace RancherFleet\Domains;

use RancherFleet\RancherClient;
use RancherFleet\Logger;

class IngressHelper
{
    private $rancher;

    /** @var string|null cached LB IP for this request lifecycle */
    private static $cachedLbIp = null;

    public function __construct(RancherClient $rancher)
    {
        $this->rancher = $rancher;
    }

    /**
     * Finds the external IP (or hostname) of the ingress controller's
     * Service. Tries common ingress-nginx namespace/service name
     * conventions first, then falls back to scanning all namespaces for
     * any Service of type LoadBalancer with an ingress-related name —
     * since cluster setups vary in exactly where the controller lives.
     *
     * @return string  IP or hostname; throws if nothing found
     * @throws \Exception
     */
    public function getIngressExternalAddress()
    {
        if (self::$cachedLbIp !== null) {
            return self::$cachedLbIp;
        }

        $candidates = array(
            array('ns' => 'kube-system',   'svc' => 'traefik'),
            array('ns' => 'traefik',       'svc' => 'traefik'),
            array('ns' => 'kube-system',   'svc' => 'traefik-ingress-service'),
            array('ns' => 'ingress-nginx', 'svc' => 'ingress-nginx-controller'),
        );

        foreach ($candidates as $c) {
            $addr = $this->tryGetServiceExternalAddress($c['ns'], $c['svc']);
            if ($addr) {
                Logger::info("IngressHelper: resolved ingress address {$addr} from {$c['ns']}/{$c['svc']}");
                self::$cachedLbIp = $addr;
                return $addr;
            }
        }

        // Fallback: scan all namespaces for any LoadBalancer Service whose
        // name suggests it's an ingress controller.
        $addr = $this->scanForIngressLoadBalancer();
        if ($addr) {
            Logger::info("IngressHelper: resolved ingress address {$addr} via namespace scan");
            self::$cachedLbIp = $addr;
            return $addr;
        }

        throw new \Exception(
            'Could not determine ingress controller external address. ' .
            'Checked common namespace/service name conventions and scanned ' .
            'for LoadBalancer services; none had an external IP/hostname assigned yet.'
        );
    }

    private function tryGetServiceExternalAddress($namespace, $serviceName)
    {
        try {
            $svc = $this->rancher->rawRequest(
                'GET',
                '/api/v1/namespaces/' . $namespace . '/services/' . $serviceName
            );
        } catch (\Exception $e) {
            return null;
        }

        return $this->extractExternalAddress($svc);
    }

    private function scanForIngressLoadBalancer()
    {
        try {
            $namespaces = $this->rancher->rawRequest('GET', '/api/v1/namespaces');
        } catch (\Exception $e) {
            return null;
        }

        $nsItems = isset($namespaces['items']) ? $namespaces['items'] : array();

        foreach ($nsItems as $ns) {
            $nsName = isset($ns['metadata']['name']) ? $ns['metadata']['name'] : '';
            if (!$nsName) {
                continue;
            }

            try {
                $services = $this->rancher->rawRequest('GET', '/api/v1/namespaces/' . $nsName . '/services');
            } catch (\Exception $e) {
                continue;
            }

            $svcItems = isset($services['items']) ? $services['items'] : array();
            foreach ($svcItems as $svc) {
                $svcName = isset($svc['metadata']['name']) ? strtolower($svc['metadata']['name']) : '';
                $svcType = isset($svc['spec']['type']) ? $svc['spec']['type'] : '';

                if ($svcType !== 'LoadBalancer') {
                    continue;
                }
                if (strpos($svcName, 'ingress') === false && strpos($svcName, 'traefik') === false) {
                    continue;
                }

                $addr = $this->extractExternalAddress($svc);
                if ($addr) {
                    return $addr;
                }
            }
        }

        return null;
    }

    private function extractExternalAddress($svc)
    {
        $ingressList = isset($svc['status']['loadBalancer']['ingress'])
            ? $svc['status']['loadBalancer']['ingress']
            : array();

        foreach ($ingressList as $entry) {
            if (!empty($entry['ip'])) {
                return $entry['ip'];
            }
            if (!empty($entry['hostname'])) {
                return $entry['hostname'];
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Root -> www redirect Ingress
    // -----------------------------------------------------------------------

    /**
     * Creates (or replaces) a Traefik Middleware CRD plus an Ingress
     * resource that, together, issue a permanent (301) redirect from the
     * root domain to "www.{domain}".
     *
     * Traefik (unlike NGINX) does not support a redirect via a single
     * Ingress annotation. It requires a separate Middleware resource
     * (traefik.io/v1alpha1, kind: Middleware) declaring the redirect, then
     * a standard Ingress referencing it via the
     * "traefik.ingress.kubernetes.io/router.middlewares" annotation in the
     * form "{namespace}-{middlewareName}@kubernetescrd".
     *
     * This Ingress/Middleware pair lives in the same namespace as the
     * client's Odoo deployment.
     *
     * @param  string $namespace          Client namespace (whmcs-client-{id})
     * @param  string $rootDomain         e.g. "example.com" (no www, no scheme)
     * @param  string $backendServiceName The client's existing Odoo Service
     *                                      name in this namespace — used only
     *                                      to satisfy the ingress controller's
     *                                      backend-existence validation; the
     *                                      redirect middleware means it is
     *                                      never actually routed to.
     * @param  int    $backendServicePort
     * @throws \Exception
     */
    public function createRootRedirect($namespace, $rootDomain, $backendServiceName, $backendServicePort = 80)
    {
        $slug          = preg_replace('/[^a-z0-9\-]/', '-', strtolower($rootDomain));
        $middlewareName = 'redirect-www-' . $slug;
        $ingressName    = 'redirect-' . $slug;
        $wwwDomain      = 'www.' . $rootDomain;

        $this->upsertRedirectMiddleware($namespace, $middlewareName, $wwwDomain);

        $manifest = $this->buildRedirectIngressManifest(
            $ingressName,
            $rootDomain,
            $namespace,
            $middlewareName,
            $backendServiceName,
            $backendServicePort
        );

        $url = '/apis/networking.k8s.io/v1/namespaces/' . $namespace . '/ingresses';

        try {
            $this->rancher->rawRequest('POST', $url, $manifest);
            Logger::info("createRootRedirect: created {$ingressName} in {$namespace} ({$rootDomain} -> {$wwwDomain})");
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '409') !== false || stripos($e->getMessage(), 'already exists') !== false) {
                $existing = $this->rancher->rawRequest('GET', $url . '/' . $ingressName);
                $manifest['metadata']['resourceVersion'] = isset($existing['metadata']['resourceVersion'])
                    ? $existing['metadata']['resourceVersion'] : null;
                $this->rancher->rawRequest('PUT', $url . '/' . $ingressName, $manifest);
                Logger::info("createRootRedirect: updated existing {$ingressName} in {$namespace}");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Creates or updates the Traefik Middleware CRD that performs the
     * actual redirect. Separated from the Ingress because Traefik requires
     * it as a standalone resource referenced by annotation, not inline.
     */
    private function upsertRedirectMiddleware($namespace, $middlewareName, $wwwDomain)
    {
        $manifest = array(
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind'       => 'Middleware',
            'metadata'   => array(
                'name'   => $middlewareName,
                'labels' => array('managed-by' => 'whmcs-rancherfleet-domains'),
            ),
            'spec' => array(
                'redirectRegex' => array(
                    'regex'       => '^https?://(?:www\\.)?(.*)',
                    'replacement' => 'https://' . $wwwDomain . '/${1}',
                    'permanent'   => true,
                ),
            ),
        );

        $url = '/apis/traefik.io/v1alpha1/namespaces/' . $namespace . '/middlewares';

        try {
            $this->rancher->rawRequest('POST', $url, $manifest);
            Logger::info("upsertRedirectMiddleware: created {$middlewareName} in {$namespace}");
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '409') !== false || stripos($e->getMessage(), 'already exists') !== false) {
                $existing = $this->rancher->rawRequest('GET', $url . '/' . $middlewareName);
                $manifest['metadata']['resourceVersion'] = isset($existing['metadata']['resourceVersion'])
                    ? $existing['metadata']['resourceVersion'] : null;
                $this->rancher->rawRequest('PUT', $url . '/' . $middlewareName, $manifest);
                Logger::info("upsertRedirectMiddleware: updated existing {$middlewareName} in {$namespace}");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Builds the Ingress manifest referencing the Traefik redirect
     * Middleware via the router.middlewares annotation.
     */
    private function buildRedirectIngressManifest($ingressName, $rootDomain, $namespace, $middlewareName, $backendServiceName, $backendServicePort = 80)
    {
        return array(
            'apiVersion' => 'networking.k8s.io/v1',
            'kind'       => 'Ingress',
            'metadata'   => array(
                'name'        => $ingressName,
                'annotations' => array(
                    'traefik.ingress.kubernetes.io/router.middlewares' =>
                        $namespace . '-' . $middlewareName . '@kubernetescrd',
                ),
                'labels' => array(
                    'managed-by' => 'whmcs-rancherfleet-domains',
                ),
            ),
            'spec' => array(
                'ingressClassName' => 'traefik',
                'rules' => array(
                    array(
                        'host' => $rootDomain,
                        'http' => array(
                            'paths' => array(
                                array(
                                    'path'     => '/',
                                    'pathType' => 'Prefix',
                                    'backend' => array(
                                        'service' => array(
                                            'name' => $backendServiceName,
                                            'port' => array('number' => (int)$backendServicePort),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Removes the redirect Ingress and Middleware for a domain, if present.
     * Idempotent — a missing resource is not treated as an error.
     */
    public function deleteRootRedirect($namespace, $rootDomain)
    {
        $slug           = preg_replace('/[^a-z0-9\-]/', '-', strtolower($rootDomain));
        $middlewareName = 'redirect-www-' . $slug;
        $ingressName    = 'redirect-' . $slug;

        try {
            $this->rancher->rawRequest('DELETE', '/apis/networking.k8s.io/v1/namespaces/' . $namespace . '/ingresses/' . $ingressName);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '404') === false) {
                throw $e;
            }
        }

        try {
            $this->rancher->rawRequest('DELETE', '/apis/traefik.io/v1alpha1/namespaces/' . $namespace . '/middlewares/' . $middlewareName);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '404') === false) {
                throw $e;
            }
        }
    }
}
