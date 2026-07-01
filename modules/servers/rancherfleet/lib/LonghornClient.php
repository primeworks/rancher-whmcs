<?php
/**
 * LonghornClient
 *
 * Reads volume usage and expands volumes via Longhorn's REST API,
 * accessed through the Rancher API proxy so no separate credentials
 * or ingress for Longhorn are required.
 *
 * Longhorn's API is at:
 *   /k8s/clusters/{clusterId}/api/v1/namespaces/longhorn-system/services/http:longhorn-backend:9500/proxy/v1/
 *
 * Two operations used here:
 *   GET  /v1/volumes/{volumeName}   -> size, actualSize, state
 *   POST /v1/volumes/{volumeName}?action=expand  -> expand to new size
 *
 * Volume names are derived from PVC names: Kubernetes names the Longhorn
 * volume after the PVC's volumeName field (the PV name), which follows
 * the pattern pvc-{uuid}. We look up the PV name via the Kubernetes API
 * first, then use that to query Longhorn.
 */

namespace RancherFleet;

class LonghornClient
{
    /** @var RancherClient */
    private $rancher;

    /** @var string  e.g. "c-m-abcd1234" */
    private $clusterId;

    /** Base path for the Longhorn service proxy through Rancher */
    const LONGHORN_PROXY = '/api/v1/namespaces/longhorn-system/services/longhorn-backend:9500/proxy/v1';

    public function __construct(RancherClient $rancher, $clusterId)
    {
        $this->rancher   = $rancher;
        $this->clusterId = $clusterId;
    }

    // -----------------------------------------------------------------------
    // PVC -> Longhorn volume name resolution
    // -----------------------------------------------------------------------

    /**
     * Given a namespace and PVC name, returns the Longhorn volume name
     * (which is the underlying PV name, e.g. "pvc-abc123...").
     *
     * @param  string $namespace
     * @param  string $pvcName     e.g. "odoo-filestore"
     * @return string|null         Longhorn volume name, or null if PVC not found
     */
    public function resolveVolumeNameFromPvc($namespace, $pvcName)
    {
        try {
            $path = '/api/v1/namespaces/' . rawurlencode($namespace)
                  . '/persistentvolumeclaims/' . rawurlencode($pvcName);
            $pvc  = $this->rancher->rawRequest('GET', $path);

            return isset($pvc['spec']['volumeName']) ? $pvc['spec']['volumeName'] : null;
        } catch (\Exception $e) {
            Logger::error("LonghornClient: resolveVolumeNameFromPvc failed for {$namespace}/{$pvcName}: " . $e->getMessage());
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Usage metering
    // -----------------------------------------------------------------------

    /**
     * Returns usage data for a Longhorn volume by its volume name.
     *
     * @param  string $volumeName  e.g. "pvc-abc123-..."
     * @return array  array(
     *   'volumeName'    => string,
     *   'sizeBytes'     => int,     // provisioned capacity
     *   'usedBytes'     => int,     // actual bytes written
     *   'sizeGb'        => float,
     *   'usedGb'        => float,
     *   'usedPct'       => float,   // 0-100
     *   'state'         => string,  // 'attached', 'detached', etc.
     * )
     * @throws LonghornApiException
     */
    public function getVolumeUsage($volumeName)
    {
        $path     = self::LONGHORN_PROXY . '/volumes/' . rawurlencode($volumeName);
        $response = $this->longhornRequest('GET', $path);

        $sizeBytes = isset($response['size']) ? (int)$response['size'] : 0;

        // actualSize is on the controller object, not the volume root.
        // Confirmed against live Longhorn v1.11.2 — the volume-level
        // actualSize field does not exist in this version.
        $usedBytes = 0;
        if (isset($response['controllers']) && is_array($response['controllers'])) {
            foreach ($response['controllers'] as $ctrl) {
                $ctrlActual = isset($ctrl['actualSize']) ? (int)$ctrl['actualSize'] : 0;
                if ($ctrlActual > $usedBytes) {
                    $usedBytes = $ctrlActual;
                }
            }
        }
        $state     = isset($response['state'])      ? $response['state']            : 'unknown';

        $sizeGb  = $sizeBytes > 0 ? round($sizeBytes / (1024 ** 3), 2) : 0;
        $usedGb  = $usedBytes > 0 ? round($usedBytes / (1024 ** 3), 2) : 0;
        $usedPct = $sizeBytes > 0 ? round(($usedBytes / $sizeBytes) * 100, 1) : 0;

        return array(
            'volumeName' => $volumeName,
            'sizeBytes'  => $sizeBytes,
            'usedBytes'  => $usedBytes,
            'sizeGb'     => $sizeGb,
            'usedGb'     => $usedGb,
            'usedPct'    => $usedPct,
            'state'      => $state,
        );
    }

    /**
     * Convenience method: resolves PVC -> volume name, then returns usage.
     * Returns null (rather than throwing) if the PVC or volume isn't found,
     * since a new service may not yet have its PVC created.
     *
     * @param  string $namespace
     * @param  string $pvcName
     * @return array|null
     */
    public function getPvcUsage($namespace, $pvcName)
    {
        $volumeName = $this->resolveVolumeNameFromPvc($namespace, $pvcName);
        if (!$volumeName) {
            return null;
        }

        try {
            return $this->getVolumeUsage($volumeName);
        } catch (LonghornApiException $e) {
            Logger::error("LonghornClient: getVolumeUsage failed for {$volumeName}: " . $e->getMessage());
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // PVC expansion
    // -----------------------------------------------------------------------

    /**
     * Expands a PVC to a new size by patching the PVC's
     * spec.resources.requests.storage field via the Kubernetes API.
     * Longhorn's allowVolumeExpansion=true handles the actual resize
     * without a pod restart.
     *
     * Also expands the underlying Longhorn volume via Longhorn's own
     * expand action, to keep both in sync — Kubernetes and Longhorn
     * may not always reconcile automatically depending on version.
     *
     * @param  string $namespace
     * @param  string $pvcName        e.g. "odoo-filestore"
     * @param  int    $newSizeGb      New total size in GB (must be > current)
     * @throws LonghornApiException   if Longhorn expand fails
     * @throws \Exception             if Kubernetes patch fails
     */
    public function expandPvc($namespace, $pvcName, $newSizeGb)
    {
        $newSizeStr = $newSizeGb . 'Gi';

        // Step 1: Patch the PVC via Kubernetes API (strategic merge patch)
        $pvcPath = '/api/v1/namespaces/' . rawurlencode($namespace)
                 . '/persistentvolumeclaims/' . rawurlencode($pvcName);
        $patch   = array(
            'spec' => array(
                'resources' => array(
                    'requests' => array(
                        'storage' => $newSizeStr,
                    ),
                ),
            ),
        );
        $this->rancher->rawRequest('PATCH', $pvcPath, $patch, array(
            'Content-Type: application/strategic-merge-patch+json',
        ));

        Logger::info("LonghornClient: patched PVC {$namespace}/{$pvcName} to {$newSizeStr}");

        // Step 2: Also call Longhorn's expand action to ensure the volume
        // backend is updated — belt-and-braces for older Longhorn versions
        // that don't auto-reconcile from the PVC patch alone.
        $volumeName = $this->resolveVolumeNameFromPvc($namespace, $pvcName);
        if ($volumeName) {
            try {
                $expandPath = self::LONGHORN_PROXY . '/volumes/'
                            . rawurlencode($volumeName) . '?action=expand';
                $newSizeBytes = $newSizeGb * (1024 ** 3);
                $this->longhornRequest('POST', $expandPath, array(
                    'size' => (string)$newSizeBytes,
                ));
                Logger::info("LonghornClient: Longhorn expand action fired for {$volumeName} -> {$newSizeBytes} bytes");
            } catch (LonghornApiException $e) {
                // Log but don't throw — the PVC patch already succeeded
                // and Longhorn will typically reconcile from it anyway.
                Logger::error("LonghornClient: Longhorn expand action failed (PVC patch succeeded): " . $e->getMessage());
            }
        }
    }

    // -----------------------------------------------------------------------
    // HTTP transport — proxies through Rancher's cluster API
    // -----------------------------------------------------------------------

    /**
     * Makes a request to the Longhorn API via the Rancher cluster proxy.
     * The Longhorn service proxy path is injected before the Longhorn-relative
     * $path, so callers use Longhorn-native paths (e.g. '/v1/volumes/...').
     *
     * @param  string $method   GET|POST
     * @param  string $path     Full path including LONGHORN_PROXY prefix
     * @param  array  $body     Optional JSON body (for POST)
     * @return array
     * @throws LonghornApiException
     */
    private function longhornRequest($method, $path, array $body = null)
    {
        try {
            return $this->rancher->rawRequest($method, $path, $body);
        } catch (\Exception $e) {
            throw new LonghornApiException(
                "Longhorn API error [{$method} {$path}]: " . $e->getMessage(),
                0, $e
            );
        }
    }
}

class LonghornApiException extends \Exception {}
