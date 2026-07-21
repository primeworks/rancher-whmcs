# RancherFleet WHMCS Module — Repository Overview

## Repository Structure

**Primary Repo:** `primeworks/rancher` (single unified repository)
**This Branch:** `whmcs-module` (WHMCS server provisioning module)
**Related Branches:** 
- `backup-server` — cluster infrastructure (nginx, openupgrade pipeline)
- `odoo-0000` — Odoo instance template for Fleet
- `whmcs-client-{orderNum}` — per-client Fleet manifests (dynamically created)

**Local Path:** `Z:\git\RancherFleet` (all branches cloned from primeworks/rancher)

## What this branch contains

The `whmcs-module` branch contains the WHMCS provisioning module (`rancherfleet`) that manages
Odoo instances deployed via Rancher Fleet GitOps on a Kubernetes cluster. It handles:

- Automated provisioning of Odoo instances (namespace, GitHub branch, Fleet GitRepo)
- Client area dashboard (instance status, domain purchase, storage upgrades, backups)
- Domain registration via ResellersPanel + Cloudflare DNS
- Storage metering and upgrades via Longhorn API
- Daily backups to PVC with WHMCS webhook notification
- Database termination via Kubernetes Jobs (not exec — see gotchas)
- An admin-area AJAX endpoint (`includes/hooks/rancherfleet_migration.php`) for
  migrating an Odoo deployment between namespaces — its actual exec/DB logic lives
  in `modules/addons/rancherfleet_migration/`, an addon module **not included in
  this repo**

## Repository structure

```
/
├── modules/
│   └── servers/
│       └── rancherfleet/           ← main module directory
│           ├── rancherfleet.php    ← primary module file (provisioning + client area)
│           ├── backup_webhook.php  ← receives CronJob completion POSTs from cluster
│           ├── backup-cronjob.yaml ← Fleet template manifest (copied to odoo-0000 branch)
│           ├── templates/clientarea.tpl
│           └── lib/
│               ├── RancherClient.php
│               ├── GitHubClient.php
│               ├── FleetHelper.php
│               ├── Logger.php
│               ├── RetryQueue.php
│               ├── LonghornClient.php
│               ├── StorageUpgradeStore.php
│               ├── DomainOrderManager.php  ← stray unused duplicate, see module CLAUDE.md gotchas
│               └── Domains/
│                   ├── ResellersPanelClient.php
│                   ├── CloudflareClient.php
│                   ├── DomainOrderManager.php  ← the one actually loaded
│                   ├── DomainRecordStore.php
│                   ├── DomainRetryStore.php
│                   └── IngressHelper.php   ← Traefik IngressRoute for root-domain redirects
└── includes/
    └── hooks/
        ├── rancherfleet.php            ← cron hook for the infrastructure provisioning
        │                                  retry queue (RetryQueue), plus the Fleet status
        │                                  webhook receiver. Does NOT process the domain
        │                                  registration retry queue — see module CLAUDE.md.
        └── rancherfleet_migration.php   ← AJAX dispatcher for a migration addon UI; requires
                                             modules/addons/rancherfleet_migration/, which is
                                             NOT part of this repo
```

## Deployment

**Automated:** Every push to the `whmcs-module` branch triggers GitHub Actions (`.github/workflows/deploy.yml`)
which FTPs the changed files to the cPanel server at `/www/host.webdiscode.com/`.

**Manual:** Upload files via cPanel File Manager if Actions is unavailable.

After deploying `rancherfleet.php`, no WHMCS cache flush is needed — PHP files are
read on each request.

## Related repositories and branches

All code is now consolidated in `primeworks/rancher`. The repository contains multiple
branches for different purposes:

| Branch | Purpose | Content |
|--------|---------|---------|
| `whmcs-module` | WHMCS server provisioning module | `modules/servers/rancherfleet/`, `includes/hooks/` |
| `backup-server` | Cluster infrastructure | nginx, openupgrade pipeline, support containers |
| `odoo-0000` | Odoo instance template | Fleet-managed manifests, backup sidecar, pod specs |
| `whmcs-client-{orderNum}` | Per-client Fleet manifests | Client-specific deployments (dynamically created) |

**Previous separate repositories (now consolidated):**
- `primeworks/rancher-whmcs` → `whmcs-module` branch in `primeworks/rancher`

**Cloning for local development:**
```bash
git clone https://github.com/primeworks/rancher.git
cd rancher
git checkout whmcs-module
```

## Key infrastructure

| Component | Value |
|-----------|-------|
| Rancher URL | https://cattle.webdiscode.com |
| Fleet repo | primeworks/rancher (separate repo) |
| Fleet template branch | odoo-0000 |
| Client namespace pattern | whmcs-client-{orderNum} |
| Ingress controller | Traefik (not nginx) |
| Postgres | `db16` (postgres16 StatefulSet in `default` namespace) is the default; configurable per-product via the "Database Server" Module Setting (`db{N}` identifier, e.g. `db19`) |
| Postgres hostname (in-cluster) | `postgres{N}.default.svc.cluster.local`, N from "Database Server" above (defaults to `postgres16...`) |
| Longhorn | Distributed block storage, API via Rancher proxy |
| NFS | 162.35.166.55:/export/share1 (used by Longhorn) |
| Domain registrar | ResellersPanel.com (api.duoservers.com) |
| DNS hosting | Cloudflare |
| WHMCS admin path | /mxchaka/ |
| WHMCS version | 8.1.3 |

## Environment-specific configuration

Module settings are stored in `tblproducts.configoption1`–`configoption24` columns,
accessed as `$params['configoptionN']` (numbered, NOT friendly-name keyed).

**Critical:** `$params['configoptions']['Friendly Name']` is ONLY for Configurable
Options (customer-facing), NOT for Module Settings. Module Settings are ONLY ever
available as `$params['configoptionN']`.

After adding or reordering Module Settings fields, always verify actual slot
assignments via direct SQL:
```sql
SELECT configoption1, configoption2, ..., configoption24
FROM tblproducts WHERE id = {product_id};
```

The numbered slots do NOT reliably match definition order in `_ConfigOptions()` —
they can drift after field additions, module resets, or switching the module dropdown
to "None" and back. Never assume position from definition order alone.

`_ConfigOptions()` currently defines 22 fields — only 2 of the 24 slots remain
free. Several helper functions also still read the (customer-facing-only)
`$params['configoptions']['Friendly Name']` array instead of `configoptionN`
for Module Settings — see "configoption access pattern is inconsistent" in
`modules/servers/rancherfleet/CLAUDE.md` before assuming a setting works.

## Related repositories

- `primeworks/rancher` — Fleet GitOps repo with client branches and `odoo-0000` template
  (completely separate from this repo — do not mix deployment workflows)
