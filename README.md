# Bridge Deploy

**Push WordPress files from your local dev environment to staging and production over authenticated REST API. No SSH, FTP, or manual imports.**

Bridge Deploy is a two-plugin suite:

| Plugin | Installs On | Role |
|--------|-------------|------|
| **[Bridge Engine](./bridge-engine/)** | Your local dev WordPress | Sender — zips files and pushes them to the API |
| **[Deploy Bridge](./deploy-bridge/)** | Every staging / production site | Receiver — authenticates, validates, and extracts |

Together they give you one-click deploys for themes, mu-plugins, and any `wp-content` path. Both ends authenticate with an API key; optional HMAC signature verification prevents in-transit tampering.

---

## Why this exists

Traditional WordPress deployment is a mess:

- **FTP / SFTP** — credentials to manage, slow, easy to overwrite the wrong file
- **SSH + git pull** — requires shell access (many managed hosts don't provide it) and remote git setup
- **WP Migrate DB Pro** — excellent for DB, doesn't handle file sync
- **Plugin zips uploaded by hand** — tedious, error-prone, breaks on large directories
- **Managed-host git integrations** — often limited to the theme, require specific repo layouts

Bridge Deploy sidesteps all of it. Every managed WordPress host supports the REST API. If you can reach `/wp-json/` from your local machine, you can deploy.

---

## Architecture

```
Local dev site                              Staging / Production site
┌─────────────────────┐    HTTPS POST      ┌─────────────────────────┐
│  Bridge Engine      │ ─────────────────► │  Deploy Bridge          │
│                     │  /wp-json/deploy-  │                         │
│  • Admin UI         │  bridge/v1/push    │  • REST API endpoints   │
│  • Site connections │                    │  • API key auth         │
│  • File packaging   │  X-Deploy-Key: *** │  • HMAC signature check │
│  • Push/Pull/Eval   │  X-Deploy-Sig: *** │  • Path allowlist       │
│                     │                    │  • Auto cache flush     │
│  ← 200 OK ─────────┤◄───────────────────│  • Activity log         │
│    { files: 42 }    │                    │                         │
└─────────────────────┘                    └─────────────────────────┘
```

Files are zipped locally, POSTed to the staging REST endpoint with an API key header, and extracted on arrival. Path traversal is rejected. Allowlist checks every file before write. All major WordPress cache plugins are flushed automatically after extraction.

---

## Quick Start

### On your staging / production site

1. Zip the `deploy-bridge/` folder from this repo
2. Upload via **Plugins → Add New → Upload Plugin**
3. Activate
4. Go to **Tools → Deploy Bridge → Settings**
5. Click **Generate Random Key**, save, and copy the key
6. (Optional) Set a shared secret for HMAC signature verification

### On your local dev WordPress

1. Zip the `bridge-engine/` folder from this repo
2. Install and activate on your local WP
3. Go to **Tools → Bridge Engine → Settings** and set your local sites directory (e.g. `/Users/you/Local Sites`)
4. Go to the **Sites** tab and add a connection:
   - **URL** — your staging site URL
   - **API Key** — the key from step 5 above
   - **Shared Secret** — same string you set in step 6 (or blank)
   - **Local Site Folder** — which local site to push from
   - **Theme Slug** — which child theme folder to push
5. Click **Test Connection** — green success means you're live
6. Switch to the **Push/Pull** tab and push files

Full setup docs:
- **[Bridge Engine README](./bridge-engine/README.md)** — sender plugin
- **[Deploy Bridge README](./deploy-bridge/README.md)** — receiver plugin (includes full REST API reference)

---

## Features

### Sending (Bridge Engine)

- Manage multiple staging/production sites from one interface
- Per-site configuration: URL, API key, secret, local source folder, theme slug
- Test connection button with diagnostic output (WP version, PHP version, active theme)
- Push: mu-plugins, themes, or custom comma-separated paths
- Pull: mu-plugins, themes, custom directories, or LiveCanvas partials as JSON
- Quick actions: flush cache, list themes, publish partial drafts, fetch partials
- Activity log of the last 50 operations with timestamp, direction, file count, status
- Configurable local sites directory (works with Local by Flywheel, Lightning, MAMP, Valet, DDEV, XAMPP)

### Receiving (Deploy Bridge)

- REST API endpoints: `status`, `push`, `pull`, `eval`
- API key + optional HMAC-SHA256 signature verification
- Path allowlist with traversal (`../`) rejection
- Multi-host cache flush: WP object cache, transients, WP Super Cache, W3TC, WP Rocket, LiteSpeed, SG Optimizer
- Pre-defined eval actions (no arbitrary code execution): `flush_cache`, `get_partials`, `list_themes`, `publish_drafts`
- Admin UI: Settings / Activity Log / Setup Guide tabs
- Activity log of the last 100 operations with full file manifests

---

## Repository Structure

```
bridge-deploy/
├── README.md                  ← you are here
├── LICENSE                    ← GPL-2.0+
├── bridge-engine/             ← LOCAL sender plugin
│   ├── bridge-engine.php
│   └── README.md
└── deploy-bridge/             ← STAGING receiver plugin
    ├── deploy-bridge.php
    └── README.md
```

Each subfolder is a self-contained WordPress plugin. Zip the subfolder, upload, activate — no build step, no dependencies, no composer install.

---

## Security Model

**Trust boundaries:**

1. **Authentication** — every request requires a matching API key. Without it, HTTP 403. Keys are stored in `wp_options` and never echoed back.
2. **Integrity (optional)** — HMAC-SHA256 signature on push request body. Rejects tampered payloads. Recommended when staging is HTTP-only.
3. **Authorization** — the path allowlist rejects writes outside your configured prefixes. Defaults to `themes`, `mu-plugins`, `plugins`; can be narrowed further.
4. **Path safety** — directory traversal (`../`), absolute paths, and null-byte injection are rejected regardless of the allowlist.
5. **No code execution** — the `eval` endpoint only runs pre-defined actions. There is no arbitrary PHP execution path.

**What this does not protect against:**

- A compromised WP admin account with access to the Deploy Bridge settings page (they could rewrite paths/keys)
- A compromised local dev machine pushing malicious files to staging (trust chain starts with your machine)
- Network-layer attacks if you run staging over HTTP without enabling the HMAC signature

Treat the API key like a production SSH key: don't commit it, don't share it, rotate if leaked.

---

## Requirements

Both plugins require:
- WordPress 5.9+
- PHP 7.4+
- `ZipArchive` PHP extension (standard on most hosts; on cPanel look for `php-zip`)

Bridge Engine additionally needs filesystem read access to your local sites directory. Deploy Bridge needs `WP_CONTENT_DIR` to be writable by PHP (the default for standard WP installs).

---

## Supported Local Environments

Bridge Engine auto-detects WordPress layouts inside your sites directory:

| Environment | Detected Layout |
|-------------|-----------------|
| Local by Flywheel | `<site>/app/public/wp-content` |
| Local Lightning   | `<site>/public/wp-content` |
| Plain WP install  | `<site>/wp-content` |
| MAMP / XAMPP      | `<site>/wp-content` |
| Laravel Valet     | `<site>/wp-content` |
| DDEV              | `<site>/wp-content` |

If you use a layout that isn't on this list, add it as a custom case in `DeployBridgeCommander::resolve_wp_content()` and submit a PR.

---

## License

GPL-2.0-or-later

---

## Contributing

Contributions welcome. The two plugins are deliberately simple — single-file PHP, no build step, no framework. If you're adding a feature, please:

1. Keep both sides of the protocol in sync (any new endpoint in Deploy Bridge should have a corresponding UI/action in Bridge Engine)
2. Document new REST endpoints in the Deploy Bridge README
3. Add a Changelog entry in the plugin's README
4. Bump version numbers in both the plugin header and the `*_VERSION` constant

**Maintainer workflow:** This project's canonical source lives in a private monorepo and is mirrored here via `git subtree`. See [DEVELOPMENT.md](./DEVELOPMENT.md) for the full sync workflow, release process, and directory layout.

---

## Changelog

### Bridge Deploy 1.1 (current)

- **Bridge Engine 1.1.0:** configurable local sites directory (no more hardcoded paths), multi-environment layout detection (Flywheel, Lightning, MAMP, Valet, plain WP), pull-partial logs moved to WP uploads dir
- **Deploy Bridge 1.0.0:** initial release

### Bridge Deploy 1.0

- **Bridge Engine 1.0.0:** initial release — sender plugin with site connections, push/pull, activity log
