# RancherFleet WHMCS Module — Repository Overview

## What this repo is

This repository contains the WHMCS provisioning module (`rancherfleet`) that manages
Odoo instances deployed via Rancher Fleet GitOps on a Kubernetes cluster. It handles:

- Automated provisioning of Odoo instances (namespace, GitHub branch, Fleet GitRepo)
- Client area dashboard (instance status, domain purchase, storage upgrades, backups)
- Domain registration via ResellersPanel + Cloudflare DNS
- Storage metering and upgrades via Longhorn API
- Daily backups to PVC with WHMCS webhook notification
- Database termination via Kubernetes Jobs (not exec — see gotchas)

## Repository structure

```
/
├── modules/
│   └── servers/
│       └── rancherfleet/           ← main module directory
│           ├── rancherfleet.php    ← primary module file (provisioning + client area)
│           ├── backup_webhook.php  ← receives CronJob completion POSTs from cluster
│           ├── backup-cronjob.yaml ← Fleet template manifest (copied to odoo-0000 branch)
│           └── lib/
│               ├── RancherClient.php
│               ├── GitHubClient.php
│               ├── FleetHelper.php
│               ├── Logger.php
│               ├── RetryQueue.php
│               ├── LonghornClient.php
│               ├── StorageUpgradeStore.php
│               └── Domains/
│                   ├── ResellersPanelClient.php
│                   ├── CloudflareClient.php
│                   ├── DomainOrderManager.php
│                   ├── DomainRecordStore.php
│                   └── DomainRetryStore.php
└── includes/
    └── hooks/
        └── rancherfleet.php        ← cron hook for domain retry queue
```

## Deployment

**Automated:** Every push to `main` triggers GitHub Actions (`.github/workflows/deploy.yml`)
which FTPs the changed files to the cPanel server at `/www/host.webdiscode.com/`.

**Manual:** Upload files via cPanel File Manager if Actions is unavailable.

After deploying `rancherfleet.php`, no WHMCS cache flush is needed — PHP files are
read on each request.

## Key infrastructure

| Component | Value |
|-----------|-------|
| Rancher URL | https://cattle.webdiscode.com |
| Fleet repo | primeworks/rancher (separate repo) |
| Fleet template branch | odoo-0000 |
| Client namespace pattern | whmcs-client-{orderNum} |
| Ingress controller | Traefik (not nginx) |
| Postgres | postgres16 StatefulSet in `default` namespace |
| Postgres hostname (in-cluster) | postgres16.default.svc.cluster.local |
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

## Related repositories

- `primeworks/rancher` — Fleet GitOps repo with client branches and `odoo-0000` template
  (completely separate from this repo — do not mix deployment workflows)
