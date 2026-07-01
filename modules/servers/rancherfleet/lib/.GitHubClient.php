<?php
/**
 * GitHubClient
 *
 * Interacts with the GitHub REST API v3 to manage client branches
 * and folders in the manifests repository.
 *
 * Bootstrap flow:
 *   1. Create branch  rancher/whmcs-client-{id}  from  rancher/odoo-0000
 *   2. List all files in rancher/odoo-0000 root on the template branch
 *   3. Copy each file into rancher/whmcs-client-{id}/ on the new branch,
 *      replacing any namespace references with the client namespace
 *
 * Termination flow:
 *   1. Delete all files in rancher/whmcs-client-{id}/ on the client branch
 *   2. Delete the client branch entirely
 */

namespace RancherFleet;

class GitHubClient
{
    private $pat;
    private $owner;
    private $repo;
    private $templateBranch;  // odoo-0000
    private $repoPrivate;
    private $gitSshKey;
    private $gitBasicUser;
    private $gitBasicPassword;
    private $timeout = 30;

    const API_BASE        = 'https://api.github.com';
    const TEMPLATE_BRANCH = 'odoo-0000';
    const FOLDER_PREFIX   = 'whmcs-client';

    public function __construct(
        $pat,
        $owner,
        $repo,
        $branch,            // kept for interface compatibility, unused — client branch is derived
        $repoPrivate       = false,
        $gitSshKey         = '',
        $gitBasicUser      = '',
        $gitBasicPassword  = ''
    ) {
        $this->pat              = $pat;
        $this->owner            = $owner;
        $this->repo             = $repo;
        $this->templateBranch   = self::TEMPLATE_BRANCH;
        $this->repoPrivate      = $repoPrivate;
        $this->gitSshKey        = $gitSshKey;
        $this->gitBasicUser     = $gitBasicUser;
        $this->gitBasicPassword = $gitBasicPassword;
    }

    // -----------------------------------------------------------------------
    // Client branch name helper
    // -----------------------------------------------------------------------

    /**
     * Returns the client branch name for a given namespace.
     * e.g. whmcs-client-384 -> rancher/whmcs-client-384
     */
    public function clientBranch($namespace)
    {
        // Branch name is plain: whmcs-client-{id}
        return $namespace;
    }

    /**
     * Returns the folder path within the client branch.
     * e.g. whmcs-client-384 -> rancher/whmcs-client-384
     */
    public function clientFolderPath($namespace)
    {
        // Folder inside the branch: whmcs-client-{id}
        return $namespace;
    }

    // -----------------------------------------------------------------------
    // Phase 3: Bootstrap
    // -----------------------------------------------------------------------

    /**
     * Checks whether the client branch already exists.
     *
     * @param  string $repoPath  unused — kept for interface compatibility
     * @return bool
     */
    public function folderExists($repoPath)
    {
        $namespace    = $this->namespaceFromPath($repoPath);
        $clientBranch = $this->clientBranch($namespace);
        return $this->branchExists($clientBranch);
    }

    /**
     * Full bootstrap for a new client:
     *   1. Create branch rancher/whmcs-client-{id} from rancher/odoo-0000
     *   2. Copy template files into rancher/whmcs-client-{id}/ on new branch
     *      with namespace substitution applied
     *
     * @param string $repoPath   rancher/whmcs-client-{id}
     * @param string $namespace  whmcs-client-{id}
     * @param string $orderNum  WHMCS human-readable order number
     */
    /**
     * Phase A: Validates the template branch before provisioning.
     *
     * Checks:
     *  - Template branch exists
     *  - Branch contains at least one YAML file
     *  - Each YAML file is non-empty
     *  - Each YAML file contains at least one Kubernetes kind declaration
     *
     * @return array  Empty on success, list of error strings on failure
     */
    public function validateTemplate()
    {
        $errors = array();

        // Check template branch exists
        if (!$this->branchExists($this->templateBranch)) {
            $errors[] = "Template branch '{$this->templateBranch}' does not exist in repo '{$this->repo}'";
            return $errors;
        }

        // List files in template branch root
        try {
            $files = $this->listTemplateFiles();
        } catch (GitHubApiException $e) {
            $errors[] = "Could not list template files: " . $e->getMessage();
            return $errors;
        }

        $yamlFiles = array();
        foreach ($files as $item) {
            if (isset($item['type']) && $item['type'] === 'file') {
                if (preg_match('/\.ya?ml$/i', $item['name'])) {
                    $yamlFiles[] = $item;
                }
            }
        }

        if (empty($yamlFiles)) {
            $errors[] = "Template branch '{$this->templateBranch}' contains no YAML files at root level";
            return $errors;
        }

        // Validate each YAML file
        foreach ($yamlFiles as $item) {
            try {
                $fileContent = $this->getFileContent($item['path'], $this->templateBranch);

                if (empty(trim($fileContent))) {
                    $errors[] = "Template file '{$item['name']}' is empty";
                    continue;
                }

                // Check for at least one Kubernetes kind declaration
                if (!preg_match('/^kind\s*:/m', $fileContent)) {
                    $errors[] = "Template file '{$item['name']}' does not appear to contain a valid Kubernetes manifest (no 'kind:' found)";
                }

            } catch (GitHubApiException $e) {
                $errors[] = "Could not read template file '{$item['name']}': " . $e->getMessage();
            }
        }

        if (empty($errors)) {
            Logger::info("validateTemplate: passed - " . count($yamlFiles) . " YAML files validated on " . $this->templateBranch);
        } else {
            Logger::error("validateTemplate: failed - " . implode('; ', $errors));
        }

        return $errors;
    }

    public function bootstrapClientFolder($repoPath, $namespace, $orderNum, $containerLimits = array(), $customImage = '')
    {
        $clientBranch = $this->clientBranch($namespace);

        Logger::info("bootstrapClientFolder: owner=" . $this->owner . " repo=" . $this->repo);
        Logger::info("bootstrapClientFolder: creating branch {$clientBranch} from {$this->templateBranch}");

        // Step 1: Create the client branch from the template branch
        $this->createBranchFrom($clientBranch, $this->templateBranch);

        // Step 2: List files in the template branch root
        $templateFiles = $this->listTemplateFiles();

        if (empty($templateFiles)) {
            Logger::info("bootstrapClientFolder: no files found in template branch root, skipping copy");
            return;
        }

        // Step 3: Copy each file to the ROOT of the new branch (no subfolder)
        foreach ($templateFiles as $item) {
            if (!isset($item['type']) || $item['type'] !== 'file') {
                continue;
            }

            $filename = $item['name'];
            $content  = $this->getFileContent($item['path'], $this->templateBranch);

            // Replace 0000 with order number throughout
            $content = $this->substituteNamespace($content, $namespace, $orderNum);

            // Phase D: Inject per-client container resource limits if configured
            if (!empty($containerLimits)) {
                $content = $this->injectContainerLimits($content, $containerLimits);
            }

            // Phase E: Override container image if custom image specified
            if (!empty($customImage)) {
                $content = preg_replace(
                    '/^(\s+image\s*:).*/m',
                    '$1 ' . $customImage,
                    $content
                );
            }

            // Write directly to branch root, no subfolder
            $this->createOrUpdateFile(
                $filename,
                'chore: provision client ' . $namespace . ' from template',
                $content,
                $clientBranch
            );

            Logger::info("bootstrapClientFolder: {$item['path']} -> root/{$filename} on {$clientBranch}");
        }

        Logger::info("bootstrapClientFolder: SUCCESS branch={$clientBranch}");
    }

    /**
     * Sets replicas to the given count in all YAML files on the client branch.
     * Pushes directly to the branch — Fleet picks up the change and applies it.
     *
     * @param string $namespace  whmcs-client-{orderNum}
     * @param int    $replicas   0 to suspend, 1 to unsuspend
     */
    public function setReplicas($namespace, $replicas)
    {
        $clientBranch = $this->clientBranch($namespace);

        Logger::info("setReplicas: branch={$clientBranch} replicas={$replicas}");

        // List all files at the root of the client branch
        $files = $this->listDirectoryOnBranch('', $clientBranch);

        foreach ($files as $item) {
            if (!isset($item['type']) || $item['type'] !== 'file') {
                continue;
            }

            $filename = $item['name'];

            // Only process YAML files
            if (!preg_match('/\.ya?ml$/i', $filename)) {
                continue;
            }

            $content = $this->getFileContent($item['path'], $clientBranch);

            // Replace replicas value — matches "replicas: N" with any whitespace
            $updated = preg_replace(
                '/^(\s*replicas\s*:\s*)\d+/m',
                '${1}' . (int)$replicas,
                $content
            );

            // Only push if the content actually changed
            if ($updated === $content) {
                Logger::info("setReplicas: no replicas field found in {$filename}, skipping");
                continue;
            }

            $action = $replicas === 0 ? 'suspend' : 'unsuspend';
            $this->createOrUpdateFile(
                $filename,
                "ops: {$action} {$namespace} - set replicas to {$replicas}",
                $updated,
                $clientBranch
            );

            Logger::info("setReplicas: updated {$filename} replicas={$replicas} on {$clientBranch}");
        }
    }

    /**
     * Deletes the client folder contents and then the client branch.
     *
     * @param string $repoPath  rancher/whmcs-client-{id}
     */
    public function deleteClientFolder($repoPath)
    {
        $namespace    = $this->namespaceFromPath($repoPath);
        $clientBranch = $this->clientBranch($namespace);
        $clientFolder = $this->clientFolderPath($namespace);

        Logger::info("deleteClientFolder: branch={$clientBranch} folder={$clientFolder}");

        // Delete files in the client folder on the client branch
        try {
            $contents = $this->listDirectoryOnBranch($clientFolder, $clientBranch);
            foreach ($contents as $item) {
                if (isset($item['type']) && $item['type'] === 'file') {
                    $this->deleteFile(
                        $item['path'],
                        $item['sha'],
                        'chore: terminate client ' . $namespace,
                        $clientBranch
                    );
                    Logger::info("deleteClientFolder: deleted " . $item['path']);
                }
            }
        } catch (GitHubApiException $e) {
            if ($e->getHttpCode() !== 404) {
                throw $e;
            }
            Logger::info("deleteClientFolder: folder not found, skipping file deletion.");
        }

        // Delete the branch itself
        $this->deleteBranch($clientBranch);
        Logger::info("deleteClientFolder: branch {$clientBranch} deleted");
    }

    // -----------------------------------------------------------------------
    // Branch operations
    // -----------------------------------------------------------------------

    /**
     * Checks whether a branch exists in the repo.
     */
    public function branchExists($branchName)
    {
        $url = self::API_BASE . '/repos/' . $this->owner . '/' . $this->repo
             . '/branches/' . $branchName;
        Logger::info("branchExists: checking URL=" . $url);
        try {
            $this->request('GET', $url);
            return true;
        } catch (GitHubApiException $e) {
            if ($e->getHttpCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Creates a new branch from an existing branch.
     * Idempotent — if branch already exists, skips creation.
     *
     * @param string $newBranch      Branch to create
     * @param string $sourceBranch   Branch to branch from
     */
    private function createBranchFrom($newBranch, $sourceBranch)
    {
        if ($this->branchExists($newBranch)) {
            Logger::info("createBranchFrom: {$newBranch} already exists, skipping.");
            return;
        }

        // Get the SHA of the tip of the source branch
        $sha = $this->getBranchSha($sourceBranch);

        // Create the new branch via the Git refs API
        $url  = self::API_BASE . '/repos/' . $this->owner . '/' . $this->repo . '/git/refs';
        $body = array(
            'ref' => 'refs/heads/' . $newBranch,
            'sha' => $sha,
        );

        $this->request('POST', $url, $body);
        Logger::info("createBranchFrom: created {$newBranch} from {$sourceBranch} at {$sha}");
    }

    /**
     * Gets the HEAD commit SHA of a branch.
     */
    private function getBranchSha($branchName)
    {
        $url = self::API_BASE . '/repos/' . $this->owner . '/' . $this->repo
             . '/branches/' . $branchName;
        Logger::info("getBranchSha: owner=" . $this->owner . " repo=" . $this->repo . " branch=" . $branchName);
        Logger::info("getBranchSha: URL=" . $url);
        $response = $this->request('GET', $url);

        if (!isset($response['commit']['sha'])) {
            throw new GitHubApiException(
                "Could not get SHA for branch '{$branchName}' — does it exist?",
                404,
                ''
            );
        }

        return $response['commit']['sha'];
    }

    /**
     * Deletes a branch from the repo.
     * Silently ignores 404 (already deleted).
     */
    private function deleteBranch($branchName)
    {
        $url = self::API_BASE . '/repos/' . $this->owner . '/' . $this->repo
             . '/git/refs/heads/' . str_replace('%2F', '/', rawurlencode($branchName));
        try {
            $this->request('DELETE', $url);
        } catch (GitHubApiException $e) {
            if ($e->getHttpCode() !== 404 && $e->getHttpCode() !== 422) {
                throw $e;
            }
        }
    }

    // -----------------------------------------------------------------------
    // Template file operations
    // -----------------------------------------------------------------------

    /**
     * Lists all files at the root of the template branch.
     *
     * @return array  Array of file objects from GitHub contents API
     */
    private function listTemplateFiles()
    {
        $url = self::API_BASE . '/repos/' . $this->owner . '/' . $this->repo
             . '/contents/?ref=' . str_replace('%2F', '/', rawurlencode($this->templateBranch));
        return $this->request('GET', $url);
    }

    /**
     * Fetches and decodes the content of a file on a specific branch.
     *
     * @param  string $path    File path in repo
     * @param  string $branch  Branch name
     * @return string          Raw file content (decoded from base64)
     */
    private function getFileContent($path, $branch)
    {
        $url      = self::API_BASE . '/repos/' . $this->owner . '/' . $this->repo
                  . '/contents/' . $path . '?ref=' . str_replace('%2F', '/', rawurlencode($branch));
        $response = $this->request('GET', $url);

        if (!isset($response['content'])) {
            throw new GitHubApiException("No content returned for file: {$path}", 0, '');
        }

        // GitHub returns base64-encoded content with newlines
        return base64_decode(str_replace("\n", '', $response['content']));
    }

    /**
     * Replaces namespace placeholders in template YAML content.
     *
     * Looks for any occurrence of 'odoo-0000' or 'odoo0000' in the content
     * and replaces with the actual client namespace.
     * Also injects the WHMCS service ID as a label.
     *
     * @param  string $content    Raw YAML content from template
     * @param  string $namespace  Target namespace e.g. whmcs-client-384
     * @param  string $orderNum  WHMCS human-readable order number
     * @return string             Modified content
     */
    /**
     * Phase D: Injects container resource requests/limits into YAML content.
     * Replaces the resources: block or appends after the first image: line.
     *
     * @param  string $content         Raw YAML content
     * @param  array  $containerLimits Keys: cpu, memory
     * @return string                  Modified YAML content
     */
    private function injectContainerLimits($content, $containerLimits)
    {
        $cpu    = isset($containerLimits['cpu'])    ? $containerLimits['cpu']    : null;
        $memory = isset($containerLimits['memory']) ? $containerLimits['memory'] : null;

        if (!$cpu && !$memory) {
            return $content;
        }

        // Only apply to Deployment manifests
        if (!preg_match('/^kind\s*:\s*Deployment/m', $content)) {
            return $content;
        }

        $resourcesYaml  = "          resources:\n";
        $resourcesYaml .= "            requests:\n";
        if ($cpu)    $resourcesYaml .= "              cpu: '" . $cpu . "'\n";
        if ($memory) $resourcesYaml .= "              memory: '" . $memory . "'\n";
        $resourcesYaml .= "            limits:\n";
        if ($cpu)    $resourcesYaml .= "              cpu: '" . $cpu . "'\n";
        if ($memory) $resourcesYaml .= "              memory: '" . $memory . "'\n";
";
";

        // Replace existing resources: block if present
        if (preg_match('/^[ \t]+resources[ \t]*:/m', $content)) {
            $content = preg_replace(
                '/^([ \t]+resources[ \t]*:(?:\n[ \t]+.*)*)/m',
                rtrim($resourcesYaml),
                $content,
                1
            );
        } else {
            // Inject after first image: line
            $content = preg_replace(
                '/(^[ \t]+image[ \t]*:.*$)/m',
                "$1
" . rtrim($resourcesYaml),
                $content,
                1
            );
        }

        return $content;
    }

    private function substituteNamespace($content, $namespace, $orderNum)
    {
        // Replace all instances of 0000 with orderNum (e.g. odoo-0000 -> odoo-381)
        
        $content = str_replace('0000', $orderNum, $content);

        // Prepend a generated header comment
        $header = "# Provisioned by WHMCS RancherFleet\n"
                . "# Order Number: {$orderNum}\n"
                . "# Namespace  : {$namespace}\n"
                . "# Template   : " . self::TEMPLATE_BRANCH . "\n"
                . "#\n";

        return $header . $content;
    }

    // -----------------------------------------------------------------------
    // File operations
    // -----------------------------------------------------------------------

    private function listDirectoryOnBranch($path, $branch)
    {
        $url = self::API_BASE . '/repos/' . $this->owner . '/' . $this->repo
             . '/contents/' . $path . '?ref=' . str_replace('%2F', '/', rawurlencode($branch));
        return $this->request('GET', $url);
    }

    private function createOrUpdateFile($path, $message, $content, $branch)
    {
        $url  = self::API_BASE . '/repos/' . $this->owner . '/' . $this->repo . '/contents/' . $path;
        $body = array(
            'message' => $message,
            'content' => base64_encode($content),
            'branch'  => $branch,
        );

        // Check if file already exists on this branch (get SHA for update)
        try {
            $existing    = $this->request('GET', $url . '?ref=' . str_replace('%2F', '/', rawurlencode($branch)));
            $body['sha'] = $existing['sha'];
        } catch (GitHubApiException $e) {
            if ($e->getHttpCode() !== 404) {
                throw $e;
            }
        }

        $this->request('PUT', $url, $body);
    }

    private function deleteFile($path, $sha, $message, $branch)
    {
        $url  = self::API_BASE . '/repos/' . $this->owner . '/' . $this->repo . '/contents/' . $path;
        $body = array(
            'message' => $message,
            'sha'     => $sha,
            'branch'  => $branch,
        );
        $this->request('DELETE', $url, $body);
    }

    // -----------------------------------------------------------------------
    // Internal: derive namespace from repoPath
    // -----------------------------------------------------------------------

    /**
     * Extracts the namespace from a repo path.
     * e.g. "rancher/whmcs-client-384" -> "whmcs-client-384"
     */
    private function namespaceFromPath($repoPath)
    {
        $parts = explode('/', $repoPath);
        return end($parts);
    }

    // -----------------------------------------------------------------------
    // HTTP transport
    // -----------------------------------------------------------------------

    private function request($method, $url, $body = array())
    {
        $headers = array(
            'Authorization: Bearer ' . $this->pat,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: WHMCS-RancherFleet/2.0',
            'Content-Type: application/json',
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $this->timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS,      5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if (!empty($body) && strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new GitHubApiException('cURL error: ' . $curlErr, 0, '');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = isset($decoded['message']) ? $decoded['message'] : $raw;
            throw new GitHubApiException(
                'GitHub API error [' . $httpCode . ']: ' . $msg,
                $httpCode,
                $raw
            );
        }

        return $decoded;
    }
}
