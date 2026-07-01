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
    private $targetClusterName;
    private $fleetNamespace;
    private $githubOwner;
    private $githubRepo;
    private $githubBranch;
    private $repoPrivate;
    private $gitSshKey;
    private $gitBasicUser;
    private $gitBasicPassword;

    const FLEET_API_PATH = '/apis/fleet.cattle.io/v1alpha1';

    /**
     * @param RancherClient $rancher
     * @param string $targetClusterName  The Fleet Cluster CRD's metadata.name for the
     *                                    downstream/target cluster (the cluster's
     *                                    DISPLAY NAME, not its c-xxxxx ID). Used in
     *                                    spec.targets[].clusterName so Fleet matches
     *                                    bundles to the correct registered cluster.
     * @param string $fleetNamespace     Fleet workspace namespace where GitRepo
     *                                    objects live (e.g. 'fleet-default').
     * @param string $githubOwner
     * @param string $githubRepo
     * @param string $githubBranch
     * @param bool   $repoPrivate
     * @param string $gitSshKey
     * @param string $gitBasicUser
     * @param string $gitBasicPassword
     */
    public function __construct(
        RancherClient $rancher,
        $targetClusterName,
        $fleetNamespace,
        $githubOwner,
        $githubRepo,
        $githubBranch,
        $repoPrivate       = false,
        $gitSshKey         = '',
        $gitBasicUser      = '',
        $gitBasicPassword  = ''
    ) {
        $this->rancher           = $rancher;
        $this->targetClusterName = $targetClusterName;
        $this->fleetNamespace    = $fleetNamespace;
        $this->githubOwner       = $githubOwner;
        $this->githubRepo        = $githubRepo;
        $this->githubBranch      = $githubBranch;
        $this->repoPrivate       = $repoPrivate;
        $this->gitSshKey         = $gitSshKey;
        $this->gitBasicUser      = $gitBasicUser;
        $this->gitBasicPassword  = $gitBasicPassword;
    }

    /**
     * Returns the cluster display name used for spec.targets[].clusterName.
     */
    public function getTargetClusterName()
    {
        return $this->targetClusterName;
    }

    /**
     * Returns the Fleet workspace namespace where GitRepo objects are created.
     */
    public function getFleetNamespace()
    {
        return $this->fleetNamespace;
    }

    // -----------------------------------------------------------------------
    // Phase 4: GitRepo CRUD
    // -----------------------------------------------------------------------

    /**
     * Builds the GitRepo spec block shared by create and update operations.
     *
     * @param  string $namespace  Target namespace (whmcs-client-{id})
     * @param  string $repoPath   Path in repo (clients/whmcs-client-{id})
     * @return array
     */
    private function buildGitRepoSpec($namespace, $repoPath)
    {
        $repoUrl = 'https://github.com/' . $this->githubOwner . '/' . $this->githubRepo . '.git';

        // No 'paths' set — Fleet defaults to scanning the branch root ('.')
        // recursively, which picks up the client's manifests regardless of
        // which subfolder they live in. Setting paths to a custom value
        // (e.g. the namespace-named subfolder) caused Fleet to report
        // "no resource found at the following paths to deploy" on this
        // Rancher/Fleet version.
        $spec = array(
            'repo'            => $repoUrl,
            'branch'          => $namespace,
            'pollingInterval' => '15s',
            // targetNamespace forces ALL resources in the bundle into this
            // namespace, regardless of whether the YAML manifests set
            // metadata.namespace themselves. Without this, resources with no
            // namespace set in their manifest land in 'default'.
            'targetNamespace' => $namespace,
            'targets'         => array(
                array(
                    // clusterName selects WHICH cluster to deploy to — it has
                    // no effect on which namespace resources land in.
                    // (targets[] does not support a namespaceSelector field.)
                    'clusterName' => $this->targetClusterName,
                ),
            ),
        );

        if ($this->repoPrivate) {
            $gitRepoName = $this->gitRepoName($namespace);
            if (!empty($this->gitSshKey)) {
                $this->createSshSecret($gitRepoName . '-ssh');
                $spec['clientSecretName'] = $gitRepoName . '-ssh';
            } elseif (!empty($this->gitBasicUser)) {
                $this->createBasicAuthSecret($gitRepoName . '-basic');
                $spec['clientSecretName'] = $gitRepoName . '-basic';
            }
        }

        return $spec;
    }

    /**
     * Creates a Fleet GitRepo for a client namespace.
     *
     * @param string $namespace  Target namespace (whmcs-client-{id})
     * @param string $repoPath   Path in repo (clients/whmcs-client-{id})
     */
    public function createGitRepo($namespace, $repoPath)
    {
        $gitRepoName = $this->gitRepoName($namespace);
        $spec        = $this->buildGitRepoSpec($namespace, $repoPath);

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

        Logger::info("createGitRepo: {$gitRepoName} -> {$spec['repo']} branch={$namespace} paths=(default: .) (clusterName={$this->targetClusterName}, fleetNs={$this->fleetNamespace})");

        $this->fleetRequest(
            'POST',
            '/namespaces/' . $this->fleetNamespace . '/gitrepos',
            $gitRepo
        );
    }

    /**
     * Updates an existing Fleet GitRepo's spec — repo URL, branch, paths,
     * and target clusterName — to the currently configured values.
     *
     * Use this to repair a GitRepo that was created with incorrect values
     * (e.g. wrong clusterName or repo URL from a prior bug). Preserves the
     * object's metadata (including resourceVersion) via a GET-then-PUT.
     *
     * @param  string $namespace  Target namespace (whmcs-client-{id})
     * @param  string $repoPath   Path in repo (clients/whmcs-client-{id})
     * @throws RancherApiException if the GitRepo does not exist
     */
    public function updateGitRepoTarget($namespace, $repoPath)
    {
        $gitRepoName = $this->gitRepoName($namespace);

        $existing = $this->fleetRequest(
            'GET',
            '/namespaces/' . $this->fleetNamespace . '/gitrepos/' . $gitRepoName
        );

        $spec = $this->buildGitRepoSpec($namespace, $repoPath);

        // Preserve metadata (resourceVersion, uid, labels, etc.) — only replace spec
        $existing['spec'] = $spec;
        if (!isset($existing['metadata']['labels'])) {
            $existing['metadata']['labels'] = array();
        }
        $existing['metadata']['labels']['managed-by']      = 'whmcs-rancherfleet';
        $existing['metadata']['labels']['whmcs-namespace'] = $namespace;

        Logger::info("updateGitRepoTarget: {$gitRepoName} -> {$spec['repo']} branch={$namespace} paths=(default: .) (clusterName={$this->targetClusterName}, fleetNs={$this->fleetNamespace})");

        $this->fleetRequest(
            'PUT',
            '/namespaces/' . $this->fleetNamespace . '/gitrepos/' . $gitRepoName,
            $existing
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
            $this->rancher->localClusterRequest('POST', '/api/v1/namespaces/' . $this->fleetNamespace . '/secrets', $secret);
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
            $this->rancher->localClusterRequest('POST', '/api/v1/namespaces/' . $this->fleetNamespace . '/secrets', $secret);
        } catch (RancherApiException $e) {
            if ($e->getHttpCode() !== 409) {
                throw $e;
            }
        }
    }

    private function deleteSecret($secretName)
    {
        try {
            $this->rancher->localClusterRequest(
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
        // Fleet GitRepo/Bundle CRDs always live on the LOCAL (Fleet management)
        // cluster, regardless of which downstream cluster spec.targets[].clusterName
        // points at. Using the RancherClient's configured (downstream) clusterId
        // here returns 404 on Rancher 2.14.
        return $this->rancher->localClusterRequest($method, self::FLEET_API_PATH . $path, $body);
    }
}
