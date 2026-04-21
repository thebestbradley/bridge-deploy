# Bridge Engine

**The local-side command center for the Bridge Deploy suite.**

Bridge Engine is a WordPress plugin you install on your **local dev environment**. It packages files from any local WordPress site and pushes them to staging/production sites running the [Deploy Bridge](../deploy-bridge/) companion plugin.

> This is half of a pair. For the full system overview, see the [root README](../README.md).

---

## What It Does

- Manage multiple staging/production site connections from one dashboard
- Push themes, mu-plugins, or custom `wp-content` paths to remote sites with one click
- Pull remote content back (files, or LiveCanvas `lc_partial` posts as JSON)
- Remote quick actions: flush caches, list themes, publish partial drafts, fetch partials
- Activity log of the last 50 operations

---

## Installation

1. Zip this folder (the contents of `bridge-engine/`)
2. Upload to your **local** WordPress via **Plugins → Add New → Upload Plugin**
3. Activate
4. Go to **Tools → Bridge Engine**

---

## First-Time Setup

### 1. Point to your local sites directory

Go to **Tools → Bridge Engine → Settings** and enter the absolute path to the directory that contains your local WordPress sites. Bridge Engine will auto-detect a sensible default, but you can override it.

| Environment | Typical Path |
|-------------|--------------|
| Local by Flywheel (macOS) | `/Users/YOU/Local Sites` |
| Local by Flywheel (Windows) | `C:/Users/YOU/Local Sites` |
| Local Lightning | `/Users/YOU/Local Sites` |
| MAMP | `/Applications/MAMP/htdocs` |
| Laravel Valet | `/Users/YOU/Sites` |
| DDEV | `~/ddev-projects` |
| XAMPP (macOS) | `/Applications/XAMPP/htdocs` |
| XAMPP (Windows) | `C:/xampp/htdocs` |

After saving, the Settings tab shows how many WordPress sites were detected in that directory — confirm this matches your expectations.

Bridge Engine auto-detects WordPress layouts inside each site folder:
- `<site>/app/public/wp-content` (Local by Flywheel)
- `<site>/public/wp-content` (Local Lightning)
- `<site>/wp-content` (plain installs)

### 2. Add a staging/production connection

Before this step, make sure **Deploy Bridge** is installed and activated on your staging/production site. You'll need the API key it generates.

Go to **Tools → Bridge Engine → Sites** and fill in the form:

| Field | What goes here |
|-------|----------------|
| **Alias** | Short name for this connection (e.g. `staging`, `probate-v2-prod`) |
| **Staging URL** | Full URL of the remote site, e.g. `https://staging.yoursite.com` |
| **API Key** | From **Tools → Deploy Bridge → Settings** on the remote site |
| **Shared Secret** | Same secret set on the remote side, or blank to disable HMAC |
| **Local Site** | Pick from the dropdown of detected local sites |
| **Theme Slug** | Child theme folder to push (e.g. `picostrap5-my-child`) |

Click **Save Site**, then **Test** on that row. Green status = connected.

### 3. Push files

Go to the **Push / Pull** tab, select your site, choose what to push:

- **mu-plugins** — entire `mu-plugins/` directory
- **Theme** — the child theme you configured
- **Custom paths** — comma-separated relative paths (e.g. `plugins/my-plugin, themes/my-theme`)

Hit **Push**. Bridge Engine zips the selected files locally, POSTs them to Deploy Bridge's REST endpoint, and gets back a manifest of what landed.

---

## Admin Tabs

### Sites
Table of configured connections with status, last-check time, and Test/Delete actions. Add/edit form below.

### Push / Pull
Main deployment interface. Push or pull files per connection. Quick-action buttons for remote cache flush, theme listing, partial publishing.

### Log
Last 50 operations. Each entry shows direction (push/pull/test/eval/config), site, summary, file count, and success/error status.

### Settings
- Configurable local sites directory
- Status indicator (directory exists? how many sites detected?)
- Common paths for every major local dev environment
- List of all detected sites in your configured directory

---

## How It Talks to Deploy Bridge

Every request goes over HTTPS to `https://<remote>/wp-json/deploy-bridge/v1/<endpoint>`.

Headers on every request:
```
X-Deploy-Key: <your-api-key>
X-Deploy-Signature: <hmac-sha256-of-body>   (if secret configured)
```

Endpoints used:
- `GET /status` — test connection, returns remote WP/PHP/theme info
- `POST /push` — upload zip of files to extract
- `POST /pull` — download a directory as zip, or fetch lc_partials as JSON
- `POST /eval` — run a pre-defined remote action

Full endpoint reference: see the [Deploy Bridge README](../deploy-bridge/README.md#rest-api-reference).

---

## Data Storage

All Bridge Engine data lives in `wp_options`:

| Option | Contents |
|--------|----------|
| `dbc_settings` | Plugin settings (local sites directory) |
| `dbc_sites` | Array of configured staging/production connections |
| `dbc_log` | Last 50 operations |

Pulled `lc_partials` are saved to the WordPress uploads directory under `bridge-engine-logs/` for later reference.

---

## Security Notes

- Bridge Engine is for **local dev use only** — don't install it on a public site
- API keys and secrets are stored in `wp_options` unencrypted. Anyone with WP admin access can read them.
- The plugin does not run arbitrary remote code — the `eval` endpoint on Deploy Bridge only accepts pre-defined action names
- All requests require a nonce in addition to WP admin capabilities

---

## Changelog

### 1.1.0
- **Breaking:** removed hardcoded `DBC_LOCAL_SITES_DIR` constant — path is now configured in the Settings tab
- Added Settings tab with path validation and site detection
- Added support for multiple local environment layouts (Local by Flywheel, Lightning, plain WP, MAMP, Valet, DDEV, XAMPP)
- `pull partials` output now writes to WP uploads dir instead of a hardcoded path
- Better error messages when a site's `wp-content` can't be located

### 1.0.0
- Initial release

---

## License

GPL-2.0-or-later
