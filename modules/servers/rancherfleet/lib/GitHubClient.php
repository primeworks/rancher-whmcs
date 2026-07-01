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
        // Files are written to the branch root — the branch itself
        // (whmcs-client-{id}) is the client's isolated workspace.
        // Previously this returned $namespace causing files to land in
        // a subfolder that Fleet didn't read from.
        return '';
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
     * @param int    $serviceId  WHMCS service ID
     */
    public function bootstrapClientFolder($repoPath, $namespace, $serviceId)
    {
        $clientBranch  = $this->clientBranch($namespace);
        $clientFolder  = $this->clientFolderPath($namespace);

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

        // Step 3: Copy each file into rancher/whmcs-client-{id}/ on the new branch
        foreach ($templateFiles as $item) {
            if (!isset($item['type']) || $item['type'] !== 'file') {
                continue;
            }

            $filename = $item['name'];
            $content  = $this->getFileContent($item['path'], $this->templateBranch);

            // Replace namespace placeholder in YAML content
            $content = $this->substituteNamespace($content, $namespace, $serviceId);

            $destPath = $clientFolder !== '' ? $clientFolder . '/' . $filename : $filename;
            $this->createOrUpdateFile(
                $destPath,
                'chore: provision client ' . $namespace . ' from template',
                $content,
                $clientBranch
            );

            Logger::info("bootstrapClientFolder: copied {$item['path']} -> {$destPath}");
        }

        Logger::info("bootstrapClientFolder: SUCCESS branch={$clientBranch} folder={$clientFolder}");
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
    /**
     * Writes (creates or updates) a single file on an existing branch.
     * Public wrapper around createOrUpdateFile for use by admin button handlers.
     *
     * @param  string $filename   File name (no path prefix — written to branch root)
     * @param  string $content    Raw file content
     * @param  string $branch     Branch name
     * @param  string $message    Commit message
     */
    public function writeFileToBranch($filename, $content, $branch, $message)
    {
        $this->createOrUpdateFile($filename, $message, $content, $branch);
    }

    /**
     * Updates the storage size for a named PVC in the client's Git branch.
     *
     * Scans all files on the client branch, finds the one containing both
     * "kind: PersistentVolumeClaim" and "name: {pvcName}", then replaces the
     * "storage: {old}" line within that PVC block with the new size.
     *
     * Called after a successful live PVC expansion to keep the Git manifest
     * in sync — so if Fleet ever re-applies the branch (e.g. after a repair),
     * it uses the current size rather than the original provisioned size.
     *
     * @param  string $namespace  e.g. "whmcs-client-AF4E52"
     * @param  string $pvcName    e.g. "odoo-AF4E52"
     * @param  int    $newSizeGb  New total size in GB e.g. 10
     * @throws GitHubApiException  if the file cannot be found or updated
     */
    public function updatePvcStorageInManifest($namespace, $pvcName, $newSizeGb)
    {
        $clientBranch = $this->clientBranch($namespace);
        $clientFolder = $this->clientFolderPath($namespace);
        $newSizeStr   = $newSizeGb . 'Gi';

        // Files sit at the branch root — no subfolder per client.
        // The branch itself (whmcs-client-{namespace}) is the client's
        // isolated workspace, so all manifests are in the root.
        Logger::info("GitHubClient: updatePvcStorageInManifest scanning root of branch={$clientBranch}");
        $files = $this->listDirectoryOnBranch('', $clientBranch);
        if (empty($files)) {
            throw new GitHubApiException("No files found on branch {$clientBranch} in {$clientFolder}", 0, '');
        }

        foreach ($files as $item) {
            if (!isset($item['type']) || $item['type'] !== 'file') {
                continue;
            }

            $filePath = $item['path'];
            $content  = $this->getFileContent($filePath, $clientBranch);

            // Only process files that contain this PVC
            if (strpos($content, 'kind: PersistentVolumeClaim') === false) {
                continue;
            }
            if (strpos($content, 'name: ' . $pvcName) === false) {
                continue;
            }

            // Replace "storage: {anything}Gi" within the PVC block.
            // The pattern matches the storage line that appears after
            // "kind: PersistentVolumeClaim" in this file, under
            // resources.requests — using a targeted regex that matches
            // the indented storage line and replaces only its value.
            $updated = preg_replace(
                '/(\bstorage:\s*)\S+Gi/',
                '${1}' . $newSizeStr,
                $content,
                1  // replace only the first occurrence — the PVC storage line
            );

            if ($updated === $content) {
                // Storage line not found or already at the target size
                Logger::info("GitHubClient: updatePvcStorageInManifest: no change needed in {$filePath}");
                return;
            }

            $this->createOrUpdateFile(
                $filePath,
                "chore: expand {$pvcName} storage to {$newSizeStr}",
                $updated,
                $clientBranch
            );

            Logger::info("GitHubClient: updated {$pvcName} storage to {$newSizeStr} in {$filePath} on {$clientBranch}");
            return;
        }

        throw new GitHubApiException(
            "Could not find a PVC manifest for '{$pvcName}' on branch {$clientBranch}",
            0, ''
        );
    }

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
     * @param  int    $serviceId  WHMCS service ID
     * @return string             Modified content
     */
    private function substituteNamespace($content, $namespace, $serviceId)
    {
        // Replace all instances of 0000 with serviceId (e.g. odoo-0000 -> odoo-381)
        $content = str_replace('0000', $serviceId, $content);

        // Prepend a generated header comment
        $header = "# Provisioned by WHMCS RancherFleet\n"
                . "# Service ID : {$serviceId}\n"
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
