# rancherfleet Module — Developer Reference

## Module overview

`rancherfleet.php` is a WHMCS server provisioning module. WHMCS calls its lifecycle
hooks (`CreateAccount`, `SuspendAccount`, `TerminateAccount`, etc.) and the module
orchestrates Rancher/Fleet/GitHub to provision Odoo instances.

## Provisioning flow

```
CreateAccount()
  ├── testConnection()          Phase 1: validate Rancher API
  ├── createNamespace()         Phase 2: create whmcs-client-{orderNum} namespace
  ├── createClientServiceAccount()
  ├── doInjectSecrets()         inject secret_ custom fields as k8s Secret
  ├── createDbAdminSecret()     create rfm-db-admin-{orderNum} + rfm-webhook-{orderNum} Secrets
  ├── bootstrapClientFolder()   Phase 3: push manifests to GitHub branch
  └── createGitRepo()           Phase 4: create Fleet GitRepo CRD
```

## File structure conventions

- Files are written to the **branch root** (no subfolder) on each client branch
- Branch name = namespace = `whmcs-client-{orderNum}`
- `clientFolderPath()` returns `''` (empty string = root)
- `0000` in template files is replaced with `{orderNum}` by `substituteNamespace()`

## Known gotchas

### WebSocket exec limitation
cPanel's cURL does not support `wss://` (WebSocket). This means:
- `kubectl exec` / Rancher exec API CANNOT be used from WHMCS
- Any operation requiring exec must use a **Kubernetes Job** instead
- The database termination Job (`rfm-dropdb-{orderNum}`) uses this pattern
- The backup system uses a webhook callback instead of exec to read file listings

### configoption slot drift
Module Settings slots (`configoption1`–`24`) are product-specific and can drift:
- Slots are assigned at first-save and do NOT always match current `_ConfigOptions()` order
- Switching module to "None" and back shifts all slots by one
- Always verify with SQL after any field changes (see root CLAUDE.md)
- NEVER use `$params['configoptions']['Friendly Name']` for Module Settings

### Rancher API paths
- Fleet CRDs (GitRepo, Bundle) live on the **local** management cluster:
  `/k8s/clusters/local/apis/fleet.cattle.io/v1/...`
- Workloads (Deployments, Pods, Secrets) live on the **downstream** cluster:
  `/k8s/clusters/{targetClusterId}/api/v1/...`
- Longhorn API proxied via Rancher:
  `/k8s/clusters/{targetClusterId}/api/v1/namespaces/longhorn-system/services/longhorn-backend:9500/proxy/v1/...`

### ResellersPanel API
- All responses are wrapped in a numbered envelope: `{"1": {...actual response...}}`
- Use `unwrapResponse()` before calling `assertNoError()`
- `domains:check` result is a flat map: `{"com": 0, "net": 1}` where 0 = available
- `order:order_domains` is the correct command for domain-only purchases (not `order:create`)
- Auth via query params: `auth_username` + `auth_password`

### Git branch file paths
- `clientFolderPath()` returns `''` — files are at branch root, not in a subfolder
- `createOrUpdateFile()` handles empty folder prefix correctly
- `updatePvcStorageInManifest()` scans the branch root for PVC manifests
- PVC name pattern: `odoo-{orderNum}` (hyphen, not underscore)
- Database name pattern: `odoo-{orderNum}` (same)
- Filestore mount path: `/var/lib/odoo`

## Client area panels (rendered in order)

1. **Your Odoo Instance** — default URL + custom domain + login instructions
2. **Instance Status** — pod status, Fleet sync, logs, restart button
3. **Domain Name** — search/purchase via ResellersPanel, reconnect existing
4. **Storage** — Longhorn usage bar, upgrade flow (charges credit balance)
5. **Backups** — lists from cached manifest, signed download links

## Payment system

All client-facing charges (domain purchase, storage upgrade) debit the client's
WHMCS credit balance:
1. Check balance ≥ amount
2. `CreateInvoice` (status: Unpaid)
3. `ApplyCredit` (deducts balance + marks invoice Paid atomically)
4. Proceed with action
5. On failure: `AddCredit` (positive amount) to refund, cancel invoice

**Never use `AddCredit` with a negative amount** — WHMCS rejects it.
**Never use `CaptureRemoteCardPayment`** — replaced entirely by credit flow.

## Backup system

- CronJob runs daily at 3am UTC in each client namespace
- Writes `db-{orderNum}-{date}.dump` and `filestore-{orderNum}-{date}.tar.gz`
  to `/backups/` subPath on the Odoo PVC
- Retains 3 days, deletes older files automatically
- Writes `manifest.json` then POSTs it to `backup_webhook.php` on WHMCS server
- Webhook stores manifest in `tbladdonmodules` (module=`rancherfleet_backups`)
- Client area reads from that cached manifest — no exec required
- Download links are HMAC-SHA256 signed, 60-second TTL, validated by `backup-auth.php`
  via nginx `auth_request` on `backups.webdiscode.com`

## Database operations

All database operations use Kubernetes Jobs (not exec):

**Termination Job** (`terminateDatabase()`):
- Image: `postgres:16-alpine`
- Connects to `postgres16.default.svc.cluster.local`
- Runs `pg_terminate_backend` + `dropdb --if-exists odoo-{orderNum}`
- Polls Job status for up to 60 seconds, then deletes the Job

**Backup CronJob**:
- Same image and DB connection
- Credentials from `rfm-db-admin-{orderNum}` Secret
- Webhook config from `rfm-webhook-{orderNum}` Secret

## Patching existing client branches with newer template improvements

`rancherfleet_PatchTemplateUpdates()` (admin button "Patch Template Updates") lets
an admin bring an older, already-provisioned client branch up to date with
whatever has changed on `odoo-0000` since that client was bootstrapped —
without upgrading the Postgres/Odoo version they're running in production or
shrinking storage they've paid to upgrade.

- Walks every file at the root of `odoo-0000`, fetches the client's current
  version of the same filename (if any), and:
  - **File doesn't exist on the client branch** → added as-is (namespace-substituted)
    — there's nothing to preserve, so this is how new template files (like
    `backup-cronjob.yaml` was for old clients) reach older instances.
  - **File exists on both** → `rancherfleet_extractVersionMarkers()` pulls the
    Postgres image tag, the `postgres{N}.default.svc.cluster.local` host
    version, the Odoo image repo+tag, and the PVC `storage:` size out of the
    client's *current* file, then `rancherfleet_preserveVersionMarkers()`
    re-stamps those exact values into the new template content before it's
    written back. Everything else in the file (resource blocks, new fields,
    fixed bugs, etc.) comes from the template.
  - Unchanged files (after marker preservation) are left alone — no commit.
- No Fleet/GitRepo changes needed — Fleet auto-syncs on branch commit (same as
  `PushBackupCronJob` and `updatePvcStorageInManifest`).
- Does **not** delete files removed from the template, and does **not** touch
  CPU/memory requests — use `ApplyQuota` for resource-tier changes.
- If you add a *new* kind of version-locked value to the templates later
  (e.g. a Redis version), extend both `rancherfleet_extractVersionMarkers()`
  and `rancherfleet_preserveVersionMarkers()` with a matching regex pair —
  otherwise a patch run will silently overwrite it with the template default.

## Persistent state (tbladdonmodules JSON blobs)

| module | setting | purpose |
|--------|---------|---------|
| `rancherfleet_domains` | `domain_records` | DomainRecordStore — ownership/expiry/DNS |
| `rancherfleet_domains` | `pending_orders` | DomainRetryStore — failed registration queue |
| `rancherfleet_storage` | `storage_records` | StorageUpgradeStore — PVC size + history |
| `rancherfleet_backups` | `manifest_{serviceId}` | cached backup file listing per service |

## Testing checklist after changes

- [ ] PHP lint: `php -l rancherfleet.php` (and all lib/ files)
- [ ] Check brace balance if doing large edits
- [ ] Verify configoption slots via SQL if any Module Settings fields were added/removed
- [ ] Test against a service on the correct product (check `pid` in debug output)
- [ ] Check Module Log after any client area action
