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

### configoption access pattern is inconsistent (unresolved)
Despite the confirmed rule above, several helper functions still read
`$params['configoptions']['Friendly Name']` (the customer-facing Configurable
Options array) instead of the numbered `configoptionN` slot — which the
module's own code comments confirm is the wrong array for Module Settings:
- Affected: `rancherfleet_isAutomatic()`, `rancherfleet_getGraceHours()`,
  `rancherfleet_getContainerLimits()`, `rancherfleet_getCustomImage()`,
  `rancherfleet_isDryRun()`, `rancherfleet_getUserCount()` — plus the first
  fallback step of `rancherfleet_getOdooImageVersion()` / `rancherfleet_getDbServer()`.
- Correctly-fixed examples use `configoptionN` directly:
  `rancherfleet_buildDomainClients()` (configoption10-13),
  `rancherfleet_getStorageConfig()` (configoption17-19),
  `rancherfleet_createDbAdminSecret()` (configoption20-22), `rancherfleet_getConfig()`.
- Practical effect: Automatic Provisioning, Suspend Grace Hours, Container
  CPU/Memory overrides, Custom Image, Dry Run Mode, and User Count likely
  always fall through to their hardcoded defaults from Module Settings —
  they'd only pick up a value if the same friendly name also happens to be
  set up as a WHMCS Configurable Option on the product.
- If a Module Settings field doesn't seem to take effect, check whether the
  reading function uses `configoptions[]` (probably broken) before assuming
  it's a slot-drift issue.

### configoption slots are nearly full
`_ConfigOptions()` currently defines 22 fields (configoption1–22 in
definition order) against a hard cap of 24 — only 2 slots remain for future
Module Settings fields. `configoption22` = Backup Auth Secret.

### Domain retry queue is defined but never runs
`DomainRetryStore::getDueOrders()` and `DomainOrderManager::retryPendingOrder()`
exist and are fully implemented, but nothing in the codebase calls them — no
cron hook processes pending/failed domain registrations. The `CronJob` hook
in `includes/hooks/rancherfleet.php` only drains `RancherFleet\RetryQueue`
(infrastructure provisioning phases: TestConnection, CreateNamespace,
BootstrapGithub, CreateGitRepo). The "Clear Retry Queue" admin button also
only clears that infrastructure queue, not pending domain orders. A domain
purchase that fails registration will sit in `pending_orders` (see table
below) indefinitely unless something is added to process it.

### Retry queue cron only loads configoption1-9
`rancherfleet_loadParamsForService()` in `includes/hooks/rancherfleet.php`
(used by the `CronJob` hook to rebuild `$params` for retried phases) only
copies `configoption1`–`configoption9`. This happens to cover everything the
four current retry phases need, but if a new phase is ever added to
`RancherFleet\RetryQueue` that depends on a later slot (e.g. ResellersPanel
or Backup Auth Secret settings), it silently won't see that value via cron —
extend the loop range at the same time.

### Migration hook depends on files outside this repo
`includes/hooks/rancherfleet_migration.php` requires
`modules/addons/rancherfleet_migration/RancherExec.php` and
`MigrationSafetyChecker.php`, which are **not present in this repository**.
The hook will fatal if that addon module isn't deployed separately on the
server.

### Stray duplicate file
`modules/servers/rancherfleet/lib/DomainOrderManager.php` is an unused,
byte-identical duplicate of `lib/Domains/DomainOrderManager.php`. The module
only ever `require_once`s the one under `lib/Domains/`.

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

## Client area panels (rendered in order, each its own `.rfm-ca-card`)

1. **Your Odoo Instance** — default URL + custom domain + login instructions
2. **Instance Status** — badge (running/suspended/starting/offline), pod rows, image version
3. **Configuration Sync** — Fleet GitRepo sync state, commit, last sync time (skipped silently on error)
4. **Instance Log — Past Hour** — colourised last 200 lines from the first pod (skipped while suspended)
5. **Actions** — restart button (scales to 0 then back to 1); hidden while suspended
6. **Domain Name** — search/purchase via ResellersPanel, reconnect existing (`rancherfleet_domainPanelHtml()`)
7. **Storage** — Longhorn usage bar, upgrade flow (`rancherfleet_storagePanelHtml()`)
8. **Backups** — lists from cached manifest, signed download links (`rancherfleet_backupPanelHtml()`)

Built by `rancherfleet_clientAreaHtml()`; panels 6-8 are each a separate function.

## Admin custom buttons (`rancherfleet_AdminCustomButtonArray()`)

| Button label | Handler function |
|--------------|-------------------|
| 1. Test Connection | `TestConnection` |
| 2. Create Namespace | `CreateNamespace` |
| 3. Bootstrap GitHub | `BootstrapGithub` |
| 4. Create GitRepo | `CreateGitRepo` |
| Repair GitOps Target | `RepairGitRepo` |
| 5a. Test Suspend | `TestSuspend` |
| 5b. Test Unsuspend | `TestUnsuspend` |
| Rollback | `Rollback` |
| Verify Termination | `VerifyTermination` |
| Health Check | `HealthCheck` |
| Apply Quota | `ApplyQuota` |
| Inject Secrets | `InjectSecrets` |
| Get Kubeconfig | `GetKubeconfig` |
| Collect Usage | `CollectUsage` |
| Execute Grace Suspend | `ExecuteGraceSuspend` |
| Dry Run | `DryRunProvision` |
| Clear Retry Queue | `ClearRetryQueue` (infrastructure queue only — see gotchas) |
| Push Backup CronJob | `PushBackupCronJob` |
| Patch Template Updates | `PatchTemplateUpdates` |

The Admin Services tab also renders a live phase 1-5 dashboard
(`rancherfleet_AdminServicesTabFields()`) with Rancher deep links per
deployment — separate from the buttons above.

## Payment system

**Two different flows are in use, per feature — this is not one unified system:**

### Domain purchase / renewal — WHMCS credit balance
Handled by `DomainOrderManager::capturePayment()` / `refundPayment()`
(`lib/Domains/DomainOrderManager.php`):
1. Check credit balance ≥ amount
2. `CreateInvoice` (status: Unpaid)
3. `ApplyCredit` (deducts balance + marks invoice Paid atomically)
4. Proceed with registration
5. On exhausted retry: `AddCredit` (positive amount) to refund, cancel invoice

**Never use `AddCredit` with a negative amount** — WHMCS rejects it.

### Storage upgrade — stored card capture
Handled inline in `rancherfleet_handleStorageUpgrade()` (`rancherfleet.php`):
1. `CreateInvoice` (status: Unpaid)
2. `CaptureRemoteCardPayment` (charges the client's stored card on file)
3. Proceed with Longhorn PVC expansion
4. On failure after payment: look up the transaction ID from `tblaccounts`
   and issue `RefundTransaction`

These two flows are **not interchangeable** — don't assume a fix to one
payment path applies to the other.

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

## Version upgrade process

Version upgrades are **semi-automated** because Odoo schema migrations require
OpenUpgrade, which cannot be automated safely within WHMCS without access to
the full Odoo application context.

### Upgrade workflow

1. **Client initiates upgrade** (`request_version_upgrade` client action)
   - Creates an unpaid invoice for the upgrade fee
   - Client is redirected to payment page

2. **Admin creates staging environment** (`CreateStagingUpgrade` button)
   - Creates staging namespace: `whmcs-client-{orderNum}-staging`
   - Clones client Git branch: `whmcs-client-{orderNum}-staging`
   - Dumps production database to NFS: `/backups/odoo-{orderNum}/upgrade-{orderNum}-{date}.dump`
   - Creates staging Fleet GitRepo for the cloned branch
   - Returns a message with the database dump location and manual next steps

3. **Admin manually runs OpenUpgrade** (outside WHMCS)
   - Download dump from `/backups/odoo-{orderNum}/upgrade-{orderNum}-{date}.dump`
   - Run OpenUpgrade tool locally on the dump
   - Restore upgraded database as `odoo-{orderNum}-staging` to `postgres16.default.svc.cluster.local`

4. **Admin triggers live upgrade** (`TriggerLiveUpgrade` button)
   - Verifies `odoo-{orderNum}-staging` database exists
   - Scales production deployment to 0
   - Updates `odoo.yml` with new Odoo image version
   - Scales production deployment back to 1
   - Marks upgrade request as 'completed'

### Why manual OpenUpgrade?

Simply cloning the production database and changing the Odoo image tag does not
work because Odoo uses an extensible metadata layer with version-specific schema
definitions. Upgrading requires running the `odoo-bin` tool with the `--upgrade`
flag against the cloned database on a machine with the target Odoo version installed.
This is why the staging database is manually prepared with OpenUpgrade before
the live cutover.

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
| `rancherfleet_domains` | `pending_orders` | DomainRetryStore — failed registration queue (⚠ not currently drained by any cron — see gotchas) |
| `rancherfleet_storage` | `storage_records` | StorageUpgradeStore — PVC size + history |
| `rancherfleet_backups` | `manifest_{serviceId}` | cached backup file listing per service |

## Testing checklist after changes

- [ ] PHP lint: `php -l rancherfleet.php` (and all lib/ files)
- [ ] Check brace balance if doing large edits
- [ ] Verify configoption slots via SQL if any Module Settings fields were added/removed
- [ ] Test against a service on the correct product (check `pid` in debug output)
- [ ] Check Module Log after any client area action (`RancherFleet\Logger` also
      writes to `modules/servers/rancherfleet/rancherfleet.log`)
