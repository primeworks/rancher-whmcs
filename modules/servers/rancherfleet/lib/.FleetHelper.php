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
    private $clusterId;        // Fleet management cluster (local)
    private $targetClusterId;  // Downstream cluster where deployments run
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
        $targetClusterId,
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
        $this->targetClusterId  = $targetClusterId;
        $this->fleetNamespace   = $fleetNamespace;
        $this->githubOwner      = $githubOwner;
        $this->githubRepo       = $githubRepo;
        $this->githubBranch     = $githubBranch;
        $this->repoPrivate      = $repoPrivate;
        $this->gitSshKey        = $gitSshKey;
        $this->gitBasicUser     = $gitBasicUser;
        $this->gitBasicPassword = $gitBasicPassword;
    }

    // -----------------------------------------------------------------------
    // Phase 4: GitRepo CRUD
    // -----------------------------------------------------------------------

    /**
     * Creates a Fleet GitRepo for a client namespace.
     *
     * @param string $namespace  Target namespace (whmcs-client-{id})
     * @param string $repoPath   Path in repo (clients/whmcs-client-{id})
     */
    public function createGitRepo($namespace, $repoPath)
    {
        $gitRepoName = $this->gitRepoName($namespace);
        $repoUrl     = 'https://github.com/' . $this->githubOwner . '/' . $this->githubRepo . '.git';

        $spec = array(
            'repo'            => $repoUrl,
            'branch'          => $namespace,
            'paths'           => array('.'),  // watch branch root
            'pollingInterval' => '15s',
            // targetNamespace forces Fleet to deploy all resources into this namespace
            'targetNamespace' => $namespace,
            'targets'         => array(
                array(
                    'clusterName'     => $this->targetClusterId,
                    'namespaceSelector' => array(
                        'matchLabels' => array(
                            'kubernetes.io/metadata.name' => $namespace,
                        ),
                    ),
                ),
            ),
        );

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
        // Fleet GitRepo CRDs live on the Fleet management cluster (clusterId = local).
        // We build the path manually so it uses clusterId (local), not targetClusterId.
        $apiPath = '/k8s/clusters/' . $this->clusterId . self::FLEET_API_PATH . $path;
        return $this->rancher->rancherRequest($method, $apiPath, $body);
    }
}