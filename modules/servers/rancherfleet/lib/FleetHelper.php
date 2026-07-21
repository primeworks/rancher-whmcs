<?php
/**
 * FleetHelper
 *
 * Manages Rancher Fleet GitRepo custom resources.
 *
 * Fleet GitRepo CRD:
 *   group:    fleet.cattle.io
 *   version:  v1alpha1
 *   resource: gitrepos
 */

namespace RancherFleet;

class FleetHelper
{
    private $rancher;
    private $clusterId;
    private $fleetNamespace;
    private $githubOwner;
    private $githubRepo;
    private $githubBranch;
    private $repoPrivate;
    private $gitSshKey;
    private $gitBasicUser;
    private $gitBasicPassword;

    const FLEET_API_PATH = '/apis/fleet.cattle.io/v1alpha1';

    public function __construct(
        RancherClient $rancher,
        $clusterId,
        $fleetNamespace,
        $githubOwner,
        $githubRepo,
        $githubBranch,
        $repoPrivate       = false,
        $gitSshKey         = '',
        $gitBasicUser      = '',
        $gitBasicPassword  = ''
    ) {
        $this->rancher          = $rancher;
        $this->clusterId        = $clusterId;
        $this->fleetNamespace   = $fleetNamespace;
        $this->githubOwner      = $githubOwner;
        $this->githubRepo       = $githubRepo;
        $this->githubBranch     = $githubBranch;
        $this->repoPrivate      = $repoPrivate;
        $this->gitSshKey        = $gitSshKey;
        $this->gitBasicUser     = $gitBasicUser;
        $this->gitBasicPassword = $gitBasicPassword;
    }

    public function getFleetNamespace()
    {
        return $this->fleetNamespace;
    }

    public function getTargetClusterName()
    {
        return $this->clusterId;
    }

    // -----------------------------------------------------------------------
    // Phase 4: GitRepo CRUD
    // -----------------------------------------------------------------------

    /**
     * Creates a Fleet GitRepo for a client namespace.
     * Files are expected at the branch root (no subfolder).
     *
     * @param string $namespace  Target namespace (whmcs-client-{id})
     * @param string $repoPath   (Deprecated, no longer used — files are at branch root)
     */
    public function createGitRepo($namespace, $repoPath)
    {
        $gitRepoName = $this->gitRepoName($namespace);
        $repoUrl     = 'https://github.com/' . $this->githubOwner . '/' . $this->githubRepo . '.git';

        $spec = array(
            'repo'            => $repoUrl,
            'branch'          => $namespace,
            'pollingInterval' => '15s',
            'targets'         => array(
                array(
                    'clusterName' => $this->clusterId,
                    'namespaceSelector' => array(
                        'matchLabels' => array(
                            'kubernetes.io/metadata.name' => $namespace,
                        ),
                    ),
                ),
            ),
        );

        // Use the pre-created private repo secret in cattle-fleet-system
        $spec['clientSecretName'] = 'rancher-private-repo-secret';

        if ($this->repoPrivate) {
            if (!empty($this->gitSshKey)) {
                $this->createSshSecret($gitRepoName . '-ssh');
                $spec['clientSecretName'] = $gitRepoName . '-ssh';
            } elseif (!empty($this->gitBasicUser)) {
                $this->createBasicAuthSecret($gitRepoName . '-basic');
                $spec['clientSecretName'] = $gitRepoName . '-basic';
            }
        }

        $gitRepo = array(
            'apiVersion' => 'fleet.cattle.io/v1alpha1',
            'kind'       => 'GitRepo',
            'metadata'   => array(
                'name'      => $gitRepoName,
                'namespace' => $this->fleetNamespace,
                'labels'    => array(
                    'managed-by'      => 'whmcs-rancherfleet',
                    'whmcs-namespace' => $namespace,
                ),
            ),
            'spec' => $spec,
        );

        Logger::info("createGitRepo: {$gitRepoName} -> {$repoUrl}/{$repoPath}");

        $this->fleetRequest(
            'POST',
            '/namespaces/' . $this->fleetNamespace . '/gitrepos',
            $gitRepo
        );
    }

    /**
     * Retrieves a Fleet GitRepo object.
     *
     * @param  string $namespace
     * @return array  GitRepo resource or empty array if not found
     */
    public function getGitRepo($namespace)
    {
        $gitRepoName = $this->gitRepoName($namespace);
        try {
            return $this->fleetRequest(
                'GET',
                '/namespaces/' . $this->fleetNamespace . '/gitrepos/' . $gitRepoName
            );
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() === 404) {
                return array();
            }
            throw $e;
        }
    }

    /**
     * Deletes a Fleet GitRepo object.
     * Idempotent — 404 is silently ignored.
     *
     * @param string $namespace
     */
    public function deleteGitRepo($namespace)
    {
        $gitRepoName = $this->gitRepoName($namespace);
        Logger::info("deleteGitRepo: {$gitRepoName}");

        try {
            $this->fleetRequest(
                'DELETE',
                '/namespaces/' . $this->fleetNamespace . '/gitrepos/' . $gitRepoName
            );
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 404) {
                throw $e;
            }
            Logger::info("deleteGitRepo: {$gitRepoName} not found, skipping.");
        }

        $this->deleteSecret($gitRepoName . '-ssh');
        $this->deleteSecret($gitRepoName . '-basic');
    }

    // -----------------------------------------------------------------------
    // Auth secrets for private repos
    // -----------------------------------------------------------------------

    private function createSshSecret($secretName)
    {
        $secret = array(
            'apiVersion' => 'v1',
            'kind'       => 'Secret',
            'metadata'   => array(
                'name'      => $secretName,
                'namespace' => $this->fleetNamespace,
            ),
            'type' => 'Opaque',
            'data' => array(
                'ssh-privatekey' => base64_encode($this->gitSshKey),
            ),
        );
        try {
            $this->rancher->rawRequest('POST', '/api/v1/namespaces/' . $this->fleetNamespace . '/secrets', $secret);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 409) {
                throw $e;
            }
        }
    }

    private function createBasicAuthSecret($secretName)
    {
        $secret = array(
            'apiVersion' => 'v1',
            'kind'       => 'Secret',
            'metadata'   => array(
                'name'      => $secretName,
                'namespace' => $this->fleetNamespace,
            ),
            'type' => 'kubernetes.io/basic-auth',
            'data' => array(
                'username' => base64_encode($this->gitBasicUser),
                'password' => base64_encode($this->gitBasicPassword),
            ),
        );
        try {
            $this->rancher->rawRequest('POST', '/api/v1/namespaces/' . $this->fleetNamespace . '/secrets', $secret);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 409) {
                throw $e;
            }
        }
    }

    private function deleteSecret($secretName)
    {
        try {
            $this->rancher->rawRequest(
                'DELETE',
                '/api/v1/namespaces/' . $this->fleetNamespace . '/secrets/' . $secretName
            );
        } catch (RancherApiException $e) {
            // Silently ignore — secret may not exist
        }
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private function gitRepoName($namespace)
    {
        return 'gitrepo-' . $namespace;
    }

    private function fleetRequest($method, $path, $body = array())
    {
        // Fleet GitRepo CRD lives on the local management cluster, not the downstream cluster.
        // Use rancherRequest with full path including /k8s/clusters/local prefix.
        return $this->rancher->rancherRequest($method, '/k8s/clusters/local' . self::FLEET_API_PATH . $path, $body);
    }
}
