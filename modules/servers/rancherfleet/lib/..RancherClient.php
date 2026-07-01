<?php
/**
 * RancherClient
 *
 * Wraps the Rancher 2.8 API for:
 *   - Connection testing
 *   - Namespace CRUD
 *   - Deployment scaling
 *
 * Uses Rancher's kubectl-proxy endpoint:
 *   {rancher_url}/k8s/clusters/{cluster_id}/...
 */

namespace RancherFleet;

class RancherClient
{
    private $baseUrl;
    private $token;
    private $clusterId;
    private $timeout = 30;

    public function __construct($baseUrl, $token, $clusterId)
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->token     = $token;
        $this->clusterId = $clusterId;
    }

    // -----------------------------------------------------------------------
    // Phase 1: Connection Test
    // -----------------------------------------------------------------------

    /**
     * Tests the Rancher API connection by fetching cluster info.
     * Returns the cluster name on success.
     *
     * @throws RancherApiException on failure
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    public function testConnection()
    {
        // Fetch configured cluster info
        $url      = $this->baseUrl . '/v3/clusters/' . $this->clusterId;
        $response = $this->request('GET', $url);
        $name     = isset($response['name']) ? $response['name'] : $this->clusterId;

        // Check if Fleet CRDs exist on this cluster
        try {
            $fleetUrl    = $this->k8sUrl('/apis/fleet.cattle.io/v1alpha1');
            $fleetResp   = $this->request('GET', $fleetUrl);
            $resources   = isset($fleetResp['resources']) ? $fleetResp['resources'] : array();
            $kinds = array();
            foreach ($resources as $r) {
                $kinds[] = isset($r['kind']) ? $r['kind'] : '?';
            }
            Logger::info("Fleet CRDs on cluster: " . implode(', ', $kinds));
        } catch (\Exception $e) {
            Logger::info("Fleet CRDs not found on cluster c-bm96f: " . $e->getMessage());
        }

        return $name;
    }

    // -----------------------------------------------------------------------
    // Phase 2: Namespace Operations
    // -----------------------------------------------------------------------

    /**
     * Checks whether a namespace exists in the cluster.
     *
     * @param  string $namespace
     * @return bool
     */
    public function namespaceExists($namespace)
    {
        $url = $this->k8sUrl('/api/v1/namespaces/' . $namespace);
        try {
            $this->request('GET', $url);
            return true;
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Creates a Kubernetes namespace.
     * Idempotent — 409 Conflict is silently ignored.
     *
     * @param string $namespace
     */
    public function createNamespace($namespace)
    {
        $url  = $this->k8sUrl('/api/v1/namespaces');
        $body = array(
            'apiVersion' => 'v1',
            'kind'       => 'Namespace',
            'metadata'   => array(
                'name'   => $namespace,
                'labels' => array(
                    'managed-by' => 'whmcs-rancherfleet',
                ),
            ),
        );

        try {
            $this->request('POST', $url, $body);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 409) {
                throw $e;
            }
            Logger::info("createNamespace: {$namespace} already exists, skipping.");
        }
    }

    /**
     * Deletes a Kubernetes namespace.
     * Idempotent — 404 Not Found is silently ignored.
     *
     * @param string $namespace
     */
    public function deleteNamespace($namespace)
    {
        $url = $this->k8sUrl('/api/v1/namespaces/' . $namespace);
        try {
            $this->request('DELETE', $url);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 404) {
                throw $e;
            }
            Logger::info("deleteNamespace: {$namespace} not found, skipping.");
        }
    }

    // -----------------------------------------------------------------------
    // Phase 5: Deployment Scaling
    // -----------------------------------------------------------------------

    /**
     * Returns all Deployment objects in a namespace.
     *
     * @param  string $namespace
     * @return array
     */
    public function listDeployments($namespace)
    {
        $url      = $this->k8sUrl('/apis/apps/v1/namespaces/' . $namespace . '/deployments');
        $response = $this->request('GET', $url);
        return isset($response['items']) ? $response['items'] : array();
    }

    /**
     * Scales ALL Deployments in a namespace to the given replica count.
     *
     * @param string $namespace
     * @param int    $replicas
     */
    public function scaleAllDeployments($namespace, $replicas)
    {
        $deployments = $this->listDeployments($namespace);

        if (empty($deployments)) {
            Logger::info("scaleAllDeployments: no deployments found in {$namespace}");
            return;
        }

        foreach ($deployments as $deployment) {
            $name = isset($deployment['metadata']['name']) ? $deployment['metadata']['name'] : null;
            if (!$name) {
                continue;
            }
            $this->scaleDeployment($namespace, $name, $replicas);
        }
    }

    /**
     * Scales a single Deployment via JSON Merge Patch.
     *
     * @param string $namespace
     * @param string $deploymentName
     * @param int    $replicas
     */
    public function scaleDeployment($namespace, $deploymentName, $replicas)
    {
        $url   = $this->k8sUrl('/apis/apps/v1/namespaces/' . $namespace . '/deployments/' . $deploymentName);
        $patch = array('spec' => array('replicas' => (int)$replicas));

        Logger::info("scaleDeployment: {$namespace}/{$deploymentName} -> {$replicas} replicas");

        $this->request('PATCH', $url, $patch, array(
            'Content-Type: application/merge-patch+json',
        ));
    }

    // -----------------------------------------------------------------------
    // Public raw request (used by FleetHelper)
    // -----------------------------------------------------------------------

    /**
     * Makes a request to a raw Kubernetes API path via Rancher kubectl-proxy.
     *
     * @param  string $method
     * @param  string $path    e.g. /apis/fleet.cattle.io/v1alpha1/...
     * @param  array  $body
     * @return array
     */

    // -----------------------------------------------------------------------
    // Phase C: Resource Quotas
    // -----------------------------------------------------------------------

    /**
     * Applies a ResourceQuota to a namespace.
     * Creates if not exists, updates if already present (idempotent).
     *
     * @param string $namespace
     * @param array  $limits  Keys: cpu, memory, storage, pods
     *                        e.g. array('cpu' => '2', 'memory' => '2Gi', 'pods' => '20')
     */
    public function applyResourceQuota($namespace, $limits)
    {
        $url  = $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/resourcequotas/rancherfleet-quota');
        $body = array(
            'apiVersion' => 'v1',
            'kind'       => 'ResourceQuota',
            'metadata'   => array(
                'name'      => 'rancherfleet-quota',
                'namespace' => $namespace,
                'labels'    => array('managed-by' => 'whmcs-rancherfleet'),
            ),
            'spec' => array(
                'hard' => array_filter(array(
                    'limits.cpu'            => isset($limits['cpu'])     ? $limits['cpu']     : null,
                    'limits.memory'         => isset($limits['memory'])  ? $limits['memory']  : null,
                    'requests.storage'      => isset($limits['storage']) ? $limits['storage'] : null,
                    'pods'                  => isset($limits['pods'])    ? $limits['pods']    : null,
                    'services'              => isset($limits['services']) ? $limits['services'] : null,
                )),
            ),
        );

        Logger::info("applyResourceQuota: {$namespace} limits=" . json_encode($limits));

        // Try PUT (update) first, fall back to POST (create)
        try {
            $this->request('PUT', $url, $body);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() === 404) {
                $createUrl = $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/resourcequotas');
                $this->request('POST', $createUrl, $body);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Retrieves the current ResourceQuota for a namespace.
     * Returns empty array if no quota exists.
     */
    public function getResourceQuota($namespace)
    {
        $url = $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/resourcequotas/rancherfleet-quota');
        try {
            return $this->request('GET', $url);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() === 404) {
                return array();
            }
            throw $e;
        }
    }

    /**
     * Deletes the ResourceQuota from a namespace.
     * Idempotent — 404 is silently ignored.
     */
    public function deleteResourceQuota($namespace)
    {
        $url = $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/resourcequotas/rancherfleet-quota');
        try {
            $this->request('DELETE', $url);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 404) {
                throw $e;
            }
        }
    }

    // -----------------------------------------------------------------------
    // Phase C: Secret Injection
    // -----------------------------------------------------------------------

    /**
     * Creates or updates a Kubernetes Secret in a namespace.
     *
     * @param string $namespace
     * @param string $secretName   Name for the Secret object
     * @param array  $data         Key-value pairs (values will be base64-encoded)
     * @param string $type         Secret type (default: Opaque)
     */
    public function applySecret($namespace, $secretName, $data, $type = 'Opaque')
    {
        // Base64-encode all values
        $encodedData = array();
        foreach ($data as $k => $v) {
            $encodedData[$k] = base64_encode((string)$v);
        }

        $body = array(
            'apiVersion' => 'v1',
            'kind'       => 'Secret',
            'metadata'   => array(
                'name'      => $secretName,
                'namespace' => $namespace,
                'labels'    => array('managed-by' => 'whmcs-rancherfleet'),
            ),
            'type' => $type,
            'data' => $encodedData,
        );

        Logger::info("applySecret: {$namespace}/{$secretName} keys=" . implode(',', array_keys($data)));

        $url = $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/secrets/' . $secretName);
        try {
            $this->request('PUT', $url, $body);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() === 404) {
                $createUrl = $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/secrets');
                $this->request('POST', $createUrl, $body);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Deletes a named Secret from a namespace.
     * Idempotent — 404 is silently ignored.
     */
    public function deleteSecret($namespace, $secretName)
    {
        $url = $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/secrets/' . $secretName);
        try {
            $this->request('DELETE', $url);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 404) {
                throw $e;
            }
        }
    }

    // -----------------------------------------------------------------------
    // Phase C: Per-client Kubeconfig
    // -----------------------------------------------------------------------

    /**
     * Creates a ServiceAccount, Role, and RoleBinding for namespace-scoped
     * kubectl access. The ServiceAccount is named "whmcs-client" within the namespace.
     *
     * @param string $namespace
     */
    public function createClientServiceAccount($namespace)
    {
        $saName = 'whmcs-client';

        // ServiceAccount
        $sa = array(
            'apiVersion' => 'v1',
            'kind'       => 'ServiceAccount',
            'metadata'   => array(
                'name'      => $saName,
                'namespace' => $namespace,
                'labels'    => array('managed-by' => 'whmcs-rancherfleet'),
            ),
        );
        try {
            $this->request('POST', $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/serviceaccounts'), $sa);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 409) throw $e;
        }

        // Role — namespace-scoped, full access to core resources only
        $role = array(
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind'       => 'Role',
            'metadata'   => array(
                'name'      => 'whmcs-client-role',
                'namespace' => $namespace,
                'labels'    => array('managed-by' => 'whmcs-rancherfleet'),
            ),
            'rules' => array(
                array(
                    'apiGroups' => array('', 'apps', 'extensions'),
                    'resources' => array('pods', 'deployments', 'services', 'configmaps',
                                        'persistentvolumeclaims', 'events', 'replicasets'),
                    'verbs'     => array('get', 'list', 'watch'),
                ),
            ),
        );
        try {
            $this->request('POST', $this->k8sUrl('/apis/rbac.authorization.k8s.io/v1/namespaces/' . $namespace . '/roles'), $role);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 409) throw $e;
        }

        // RoleBinding
        $rb = array(
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind'       => 'RoleBinding',
            'metadata'   => array(
                'name'      => 'whmcs-client-binding',
                'namespace' => $namespace,
                'labels'    => array('managed-by' => 'whmcs-rancherfleet'),
            ),
            'subjects' => array(
                array('kind' => 'ServiceAccount', 'name' => $saName, 'namespace' => $namespace),
            ),
            'roleRef' => array(
                'kind'     => 'Role',
                'name'     => 'whmcs-client-role',
                'apiGroup' => 'rbac.authorization.k8s.io',
            ),
        );
        try {
            $this->request('POST', $this->k8sUrl('/apis/rbac.authorization.k8s.io/v1/namespaces/' . $namespace . '/rolebindings'), $rb);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 409) throw $e;
        }

        // Create a long-lived token secret for the ServiceAccount
        $tokenSecret = array(
            'apiVersion' => 'v1',
            'kind'       => 'Secret',
            'metadata'   => array(
                'name'        => 'whmcs-client-token',
                'namespace'   => $namespace,
                'annotations' => array(
                    'kubernetes.io/service-account.name' => $saName,
                ),
                'labels' => array('managed-by' => 'whmcs-rancherfleet'),
            ),
            'type' => 'kubernetes.io/service-account-token',
        );
        try {
            $this->request('POST', $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/secrets'), $tokenSecret);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 409) throw $e;
        }

        Logger::info("createClientServiceAccount: created SA/Role/RoleBinding/Token for {$namespace}");
    }

    /**
     * Retrieves the bearer token for the client ServiceAccount.
     * May need a few seconds after SA creation for the token to be populated.
     *
     * @param  string $namespace
     * @return string  Bearer token string
     * @throws RancherApiException if token not yet available
     */
    public function getClientServiceAccountToken($namespace)
    {
        $url      = $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/secrets/whmcs-client-token');
        $response = $this->request('GET', $url);
        $tokenB64 = isset($response['data']['token']) ? $response['data']['token'] : '';

        if (empty($tokenB64)) {
            throw new RancherApiException('Service account token not yet available — try again in a few seconds', 0, '');
        }

        return base64_decode($tokenB64);
    }

    /**
     * Generates a kubeconfig YAML string for the client ServiceAccount.
     * The kubeconfig is scoped to the client namespace only.
     *
     * @param  string $namespace
     * @param  string $clusterName  Display name for the cluster in kubeconfig
     * @return string  YAML kubeconfig content
     */
    public function generateKubeconfig($namespace, $clusterName = 'rancher-cluster')
    {
        $token      = $this->getClientServiceAccountToken($namespace);
        $serverUrl  = $this->baseUrl . '/k8s/clusters/' . $this->clusterId;
        $saName     = 'whmcs-client';
        $contextName = $clusterName . '-' . $namespace;

        $kubeconfig = "apiVersion: v1
"
            . "kind: Config
"
            . "clusters:
"
            . "  - name: {$clusterName}
"
            . "    cluster:
"
            . "      server: {$serverUrl}
"
            . "      insecure-skip-tls-verify: true
"
            . "users:
"
            . "  - name: {$saName}
"
            . "    user:
"
            . "      token: {$token}
"
            . "contexts:
"
            . "  - name: {$contextName}
"
            . "    context:
"
            . "      cluster: {$clusterName}
"
            . "      user: {$saName}
"
            . "      namespace: {$namespace}
"
            . "current-context: {$contextName}
";

        Logger::info("generateKubeconfig: generated for {$namespace}");
        return $kubeconfig;
    }


    // -----------------------------------------------------------------------
    // Phase D: Pod listing and metrics
    // -----------------------------------------------------------------------

    /**
     * Lists all pods in a namespace.
     *
     * @param  string $namespace
     * @return array  Array of pod objects
     */
    public function listPods($namespace)
    {
        $url      = $this->k8sUrl('/api/v1/namespaces/' . $namespace . '/pods');
        $response = $this->request('GET', $url);
        return isset($response['items']) ? $response['items'] : array();
    }

    /**
     * Fetches pod CPU/memory usage from metrics-server.
     * Returns array of container metrics per pod.
     *
     * @param  string $namespace
     * @return array
     * @throws RancherApiException if metrics-server not available
     */
    public function getPodMetrics($namespace)
    {
        $url      = $this->k8sUrl('/apis/metrics.k8s.io/v1beta1/namespaces/' . $namespace . '/pods');
        $response = $this->request('GET', $url);
        $items    = isset($response['items']) ? $response['items'] : array();

        $result = array();
        foreach ($items as $pod) {
            $containers = isset($pod['containers']) ? $pod['containers'] : array();
            $podMetrics = array();
            foreach ($containers as $c) {
                $podMetrics[] = array(
                    'cpu'    => isset($c['usage']['cpu'])    ? $c['usage']['cpu']    : '0',
                    'memory' => isset($c['usage']['memory']) ? $c['usage']['memory'] : '0',
                );
            }
            $result[] = $podMetrics;
        }
        return $result;
    }


    // -----------------------------------------------------------------------
    // Client Area: Pod Logs and Status
    // -----------------------------------------------------------------------

    /**
     * Fetches stdout logs for a pod from the past N seconds.
     * Uses the Kubernetes pod logs API — no exec required.
     *
     * @param  string $namespace
     * @param  string $podName
     * @param  string $containerName  Leave empty for first/only container
     * @param  int    $sinceSeconds   How far back to fetch (default 3600 = 1 hour)
     * @param  int    $tailLines      Max lines to return (default 200)
     * @return string  Raw log text
     */
    public function getPodLogs($namespace, $podName, $containerName = '', $sinceSeconds = 3600, $tailLines = 200)
    {
        $params = 'sinceSeconds=' . (int)$sinceSeconds . '&tailLines=' . (int)$tailLines . '&timestamps=true';
        if ($containerName) {
            $params .= '&container=' . urlencode($containerName);
        }

        $url = $this->k8sUrl(
            '/api/v1/namespaces/' . $namespace . '/pods/' . $podName . '/log?' . $params
        );

        // Logs endpoint returns plain text, not JSON
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $this->token,
            'Accept: text/plain',
        ));

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RancherApiException('cURL error fetching logs: ' . $curlErr, 0, '');
        }

        if ($httpCode === 400 && strpos($raw, 'waiting to start') !== false) {
            return '[Pod is waiting to start — no logs yet]';
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = json_decode($raw, true);
            $msg = isset($msg['message']) ? $msg['message'] : $raw;
            throw new RancherApiException('Log fetch error [' . $httpCode . ']: ' . $msg, $httpCode, $raw);
        }

        return $raw ?: '[No log output in the selected time range]';
    }

    /**
     * Returns a rich status summary for a deployment including pod states.
     *
     * @param  string $namespace
     * @param  string $deploymentName
     * @return array  keys: replicas, ready, available, pods, condition, image
     */
    public function getDeploymentStatus($namespace, $deploymentName)
    {
        try {
            $dep = $this->rawRequest('GET', '/apis/apps/v1/namespaces/' . $namespace . '/deployments/' . $deploymentName);
        } catch (RancherApiException $e) {
            return array('error' => $e->getMessage());
        }

        $replicas   = isset($dep['spec']['replicas'])             ? (int)$dep['spec']['replicas']             : 0;
        $ready      = isset($dep['status']['readyReplicas'])      ? (int)$dep['status']['readyReplicas']      : 0;
        $available  = isset($dep['status']['availableReplicas'])  ? (int)$dep['status']['availableReplicas']  : 0;
        $updated    = isset($dep['status']['updatedReplicas'])    ? (int)$dep['status']['updatedReplicas']    : 0;

        // Get container image
        $containers = isset($dep['spec']['template']['spec']['containers'])
            ? $dep['spec']['template']['spec']['containers'] : array();
        $image = '';
        foreach ($containers as $c) {
            $image = isset($c['image']) ? $c['image'] : '';
            break;
        }

        // Get conditions
        $conditions = isset($dep['status']['conditions']) ? $dep['status']['conditions'] : array();
        $condition  = 'Unknown';
        $condMsg    = '';
        foreach ($conditions as $cond) {
            if (isset($cond['type']) && $cond['type'] === 'Available') {
                $condition = $cond['status'] === 'True' ? 'Available' : 'Unavailable';
                $condMsg   = isset($cond['message']) ? $cond['message'] : '';
                break;
            }
        }

        // Get pod list with their states
        $pods     = $this->listPods($namespace);
        $podInfos = array();
        foreach ($pods as $pod) {
            $podName  = isset($pod['metadata']['name'])   ? $pod['metadata']['name']   : '';
            $phase    = isset($pod['status']['phase'])     ? $pod['status']['phase']    : 'Unknown';
            $podReady = false;
            $restarts = 0;
            $startTime = isset($pod['status']['startTime']) ? $pod['status']['startTime'] : '';

            $containerStatuses = isset($pod['status']['containerStatuses'])
                ? $pod['status']['containerStatuses'] : array();
            foreach ($containerStatuses as $cs) {
                if (isset($cs['ready']))            $podReady = $cs['ready'];
                if (isset($cs['restartCount']))     $restarts = (int)$cs['restartCount'];
            }

            // Get first container name
            $podContainers = isset($pod['spec']['containers']) ? $pod['spec']['containers'] : array();
            $containerName = '';
            foreach ($podContainers as $pc) {
                $containerName = isset($pc['name']) ? $pc['name'] : '';
                break;
            }

            $podInfos[] = array(
                'name'           => $podName,
                'phase'          => $phase,
                'ready'          => $podReady,
                'restarts'       => $restarts,
                'start_time'     => $startTime,
                'container_name' => $containerName,
            );
        }

        return array(
            'replicas'   => $replicas,
            'ready'      => $ready,
            'available'  => $available,
            'updated'    => $updated,
            'condition'  => $condition,
            'cond_msg'   => $condMsg,
            'image'      => $image,
            'pods'       => $podInfos,
            'error'      => '',
        );
    }

    public function rawRequest($method, $path, $body = array())
    {
        $url = $this->k8sUrl($path);
        return $this->request($method, $url, $body);
    }

    /**
     * Makes a request directly to the Rancher base URL (Steve API).
     * Used for resources like Fleet GitRepos that are managed at the
     * Rancher level, not through the kubectl-proxy cluster endpoint.
     *
     * @param  string $method
     * @param  string $path    e.g. /v1/fleet.cattle.io.gitrepos/fleet-default
     * @param  array  $body
     * @return array
     */
    public function rancherRequest($method, $path, $body = array())
    {
        // Routes to Rancher base URL with Bearer auth — same as rawRequest
        // but without the cluster proxy prefix, for paths like
        // /k8s/clusters/local/... which address the local Fleet cluster.
        $url = $this->baseUrl . $path;
        return $this->request($method, $url, $body);
    }

    // -----------------------------------------------------------------------
    // Internal: URL builder
    // -----------------------------------------------------------------------

    private function k8sUrl($path)
    {
        return $this->baseUrl . '/k8s/clusters/' . $this->clusterId . $path;
    }

    // -----------------------------------------------------------------------
    // Internal: HTTP transport
    // -----------------------------------------------------------------------

    /**
     * Executes a cURL request.
     *
     * @param  string   $method
     * @param  string   $url
     * @param  array    $body
     * @param  string[] $extraHeaders
     * @return array
     * @throws RancherApiException
     */
    private function request($method, $url, $body = array(), $extraHeaders = array())
    {
        $headers = array(
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Content-Type: application/json',
        );

        // Extra headers override defaults — replace Content-Type if provided
        foreach ($extraHeaders as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                // Remove existing Content-Type and replace with the new one
                foreach ($headers as $k => $v) {
                    if (stripos($v, 'Content-Type:') === 0) {
                        unset($headers[$k]);
                    }
                }
            }
            $headers[] = $h;
        }
        $headers = array_values($headers);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $this->timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS,      5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        // Keep Authorization header when following redirects (HTTP -> HTTPS)
        curl_setopt($ch, CURLOPT_UNRESTRICTED_AUTH, true);

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RancherApiException('cURL error: ' . $curlErr, 0);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = array('raw' => $raw);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = isset($decoded['message']) ? $decoded['message'] : $raw;
            throw new RancherApiException(
                'Rancher API error [' . $httpCode . ']: ' . $msg,
                $httpCode,
                $raw
            );
        }

        return $decoded;
    }
}
