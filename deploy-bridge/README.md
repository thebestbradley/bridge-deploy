# Deploy Bridge

**One-click file sync from your local WordPress dev environment to staging and production — no SSH, no FTP, no manual exports.**

Deploy Bridge is a two-plugin system:

| Plugin | Installs On | Role |
|--------|-------------|------|
| **Bridge Engine** | Your local dev site (WordPress/Local by Flywheel) | Command center — zips files and pushes them |
| **Deploy Bridge** *(this plugin)* | Every staging / production site | REST API receiver — accepts and extracts the zip |

You configure a site connection once, then push themes, mu-plugins, or any `wp-content` path with a single button click. No server access required on the receiving end beyond a standard WordPress REST API.

---

## How It Works

```
Local dev site                              Staging / Production site
┌─────────────────────┐    HTTPS POST      ┌─────────────────────────┐
│  Bridge Engine      │ ─────────────────► │  Deploy Bridge          │
│  (WP plugin)        │  /wp-json/deploy-  │  (this plugin)          │
│                     │  bridge/v1/push    │                         │
│  Selects files      │                    │  Verifies API key       │
│  Creates zip        │  X-Deploy-Key: *** │  Checks HMAC signature  │
│  POSTs zip          │  X-Deploy-Sig: *** │  Validates paths        │
│                     │                    │  Extracts zip           │
│  ← 200 OK ─────────┤◄───────────────────│  Flushes all caches     │
│    { files: 42 }    │                    │  Logs operation         │
└─────────────────────┘                    └─────────────────────────┘
```

Files are transferred inside a password-protected REST endpoint using an API key you generate. An optional HMAC signature prevents tampering in transit. All file paths are validated against an allowlist before extraction.

---

## Requirements

**Deploy Bridge (receiver — this plugin):**
- WordPress 5.9+
- PHP 7.4+
- `ZipArchive` PHP extension (standard on most hosts)
- REST API accessible from your local machine (not blocked by firewall)

**Bridge Engine (sender — the companion plugin):**
- WordPress 5.9+ (on your local dev environment)
- PHP 7.4+
- `ZipArchive` PHP extension
- Network access to the staging/production site's REST API

---

## Installation

### Step 1 — Install Deploy Bridge on staging/production

1. Download or clone this plugin
2. Zip the `deploy-bridge/` folder
3. In your staging/production WordPress admin: **Plugins → Add New → Upload Plugin**
4. Upload and activate

Repeat for every staging or production site you want to push to.

### Step 2 — Generate an API key

1. Go to **Tools → Deploy Bridge** on your staging/production site
2. On the **Settings** tab, click **Generate Random Key**
3. Copy the generated key — you'll need it in Bridge Engine
4. Optionally set a **Shared Secret** for HMAC signature verification (recommended for HTTP sites)
5. Click **Save Settings**

The API endpoint URL is shown at the top of the Settings page:
```
https://your-staging-site.com/wp-json/deploy-bridge/v1
```

### Step 3 — Install Bridge Engine on your local dev site

1. Install and activate the **Bridge Engine** plugin on your local WordPress site
2. Go to **Tools → Bridge Engine**
3. Click **Add Site** and fill in:

   | Field | Value | Example |
   |-------|-------|---------|
   | Alias | Short name for this connection | `staging` |
   | URL | Full URL of the staging site | `https://staging.yoursite.com` |
   | API Key | Key generated in Step 2 | `a3f9b2c1...` |
   | Shared Secret | Same secret set in Step 2 | `supersecret` (or blank) |
   | Local Site Folder | Which local site to push *from* | `my-site` |
   | Theme Slug | Child theme folder to push | `my-theme-child` |

4. Click **Test Connection** — you should see a green success response showing the staging site's WP version, active theme, and PHP version

### Step 4 — Push files

1. Go to the **Push/Pull** tab in Bridge Engine
2. Select the target staging site from the dropdown
3. Choose what to push:
   - **mu-plugins** — pushes the entire `mu-plugins/` directory
   - **Theme** — pushes the child theme specified in the site config
   - **Custom paths** — comma-separated paths relative to `wp-content/` (e.g. `plugins/my-plugin, themes/my-theme`)
4. Click **Push** — Bridge Engine zips the selected files locally and sends them to Deploy Bridge
5. Deploy Bridge extracts them and flushes all caches automatically

---

## Security

### API Key authentication

Every request to Deploy Bridge must include your API key in the `X-Deploy-Key` header. Requests without a valid key return HTTP 403. Keys are stored in `wp_options` and never exposed in logs or responses.

**Key requirements:** Generate a cryptographically random key of at least 32 characters. The Settings page includes a one-click generator that creates a 64-character hex key using `crypto.getRandomValues`.

### HMAC signature verification (optional but recommended)

When you set a Shared Secret, Bridge Engine signs every push request body with HMAC-SHA256 and includes the signature in the `X-Deploy-Signature` header. Deploy Bridge recomputes the signature from the received body and rejects the request if they don't match.

This prevents:
- **Man-in-the-middle tampering** — even if HTTPS is intercepted, a modified payload won't match the signature
- **Replay attacks** — an attacker who captures a request can't modify its contents

To enable: set the same string in the **Shared Secret** field on both the staging site (Deploy Bridge) and the local site connection (Bridge Engine).

### Path allowlist

Deploy Bridge will only extract files that begin with one of your configured **Allowed Paths**. By default:
```
themes
mu-plugins
plugins
```

You can restrict this further — for example, to only allow pushing a single theme:
```
themes/my-theme-child
mu-plugins
```

Any file in the zip whose path doesn't start with an allowed prefix is silently skipped and logged. Path traversal sequences (`../`) and absolute paths are always rejected regardless of the allowlist.

### What Deploy Bridge never does

- Never exposes file contents without authentication
- Never writes outside `wp-content/`
- Never executes arbitrary PHP (the `eval` endpoint only runs pre-defined actions: `flush_cache`, `get_partials`, `list_themes`, `publish_drafts`)
- Never logs API keys or secrets

---

## REST API Reference

All endpoints are under `/wp-json/deploy-bridge/v1/`. All require the `X-Deploy-Key` header.

---

### `GET /status`

Test the connection. Returns information about the remote site.

**Request:**
```http
GET /wp-json/deploy-bridge/v1/status
X-Deploy-Key: your-api-key
```

**Response:**
```json
{
  "status": "ok",
  "plugin_version": "1.0.0",
  "wp_version": "6.7.1",
  "site_url": "https://staging.yoursite.com",
  "allowed_paths": ["themes", "mu-plugins"],
  "php_version": "8.2.14",
  "wp_content_dir": "/var/www/html/wp-content",
  "active_theme": "my-theme-child"
}
```

---

### `POST /push`

Upload and extract a zip of files. The zip is sent as a multipart file upload or raw request body.

**Request:**
```http
POST /wp-json/deploy-bridge/v1/push
X-Deploy-Key: your-api-key
X-Deploy-Signature: sha256-hmac-of-body   (if secret configured)
Content-Type: multipart/form-data

file=@deploy.zip
```

Files in the zip are extracted to `wp-content/` on the remote server. Paths outside the allowlist are skipped. Caches are flushed after extraction.

**Response (success):**
```json
{
  "success": true,
  "files": 42,
  "manifest": [
    "themes/my-theme-child/style.css",
    "themes/my-theme-child/functions.php",
    "mu-plugins/my-plugin.php"
  ],
  "skipped": [],
  "message": "Deployed 42 files successfully."
}
```

**Response (partial — some files skipped):**
```json
{
  "success": true,
  "files": 38,
  "manifest": ["..."],
  "skipped": [
    "plugins/woocommerce/woocommerce.php (not in allowed paths)"
  ],
  "message": "Deployed 38 files successfully."
}
```

---

### `POST /pull`

Download files from staging to local, or fetch lc_partial post data as JSON.

**Pull a directory (returns zip):**
```http
POST /wp-json/deploy-bridge/v1/pull
X-Deploy-Key: your-api-key
Content-Type: application/json

{ "type": "dir", "dir": "themes/my-theme-child" }
```

Response: `Content-Type: application/zip` with `X-Deploy-Files: N` header.

**Pull LiveCanvas partials as JSON:**
```http
POST /wp-json/deploy-bridge/v1/pull
X-Deploy-Key: your-api-key
Content-Type: application/json

{ "type": "partials" }
```

Response:
```json
{
  "success": true,
  "count": 12,
  "partials": [
    {
      "ID": 101,
      "post_title": "Header — Default",
      "post_status": "publish",
      "post_content": "...",
      "post_name": "header-default",
      "post_modified": "2026-04-20 10:00:00"
    }
  ]
}
```

---

### `POST /eval`

Run a pre-defined remote action. These are safe, read-only or side-effect-limited operations — no arbitrary code execution.

**Flush all caches:**
```http
POST /wp-json/deploy-bridge/v1/eval
X-Deploy-Key: your-api-key
Content-Type: application/json

{ "action": "flush_cache" }
```

Response:
```json
{
  "success": true,
  "flushed": ["wp_object_cache", "transients", "wp_rocket"]
}
```

Cache flush covers: WP object cache, transients, WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache, SiteGround Optimizer.

**List installed themes:**
```json
{ "action": "list_themes" }
```

**Publish draft LiveCanvas partials:**
```json
{ "action": "publish_drafts", "params": { "post_type": "lc_partial" } }
```

**Get LiveCanvas partials (alias for pull/partials):**
```json
{ "action": "get_partials" }
```

---

## Admin Interface

### Settings tab

- **API Endpoint** — copy this URL into Bridge Engine's site configuration
- **API Key** — generate or enter your authentication key
- **HMAC Secret** — optional shared secret for request signature verification (shown as `***` after saving)
- **Allowed Paths** — one path prefix per line, relative to `wp-content/`

### Activity Log tab

Logs the last 100 operations with timestamp, direction (push/pull/eval), summary, file count, and status. Useful for confirming that a push landed and which files were deployed.

### Setup Guide tab

Step-by-step instructions with your site's actual endpoint URL pre-filled.

---

## Allowed Paths — Examples

**Default (permissive):**
```
themes
mu-plugins
plugins
```

**Locked to a single theme and mu-plugins (recommended for production):**
```
themes/my-theme-child
mu-plugins
```

**Multiple specific plugins:**
```
themes/my-theme-child
mu-plugins
plugins/my-custom-plugin
plugins/my-other-plugin
```

Changes to Allowed Paths take effect immediately — no cache flush needed.

---

## Troubleshooting

### "Invalid API key" (HTTP 403)

- Confirm the key in Bridge Engine exactly matches the key in Deploy Bridge Settings
- Keys are case-sensitive and must not have leading/trailing spaces
- If you regenerated the key, update it in Bridge Engine too

### "Missing X-Deploy-Signature" (HTTP 403)

- You set a Shared Secret on Deploy Bridge but Bridge Engine doesn't have it (or has a different one)
- Either add the same secret to the Bridge Engine site config, or clear the secret in Deploy Bridge Settings

### "ZipArchive PHP extension not available" (HTTP 500)

- `ZipArchive` is standard on most hosts but not all
- Ask your host to enable the `php-zip` extension, or use an alternative archive method
- On cPanel hosts: PHP Selector → Extensions → enable `zip`

### Test Connection succeeds but Push fails

- Check that the files you're pushing exist in the local `wp-content/` path
- Check the Allowed Paths — files outside the allowlist are rejected silently
- Increase PHP `max_execution_time` and `post_max_size` on the staging server if pushing large directories (recommended: 120s / 256MB)

### "REST API inaccessible" from behind a firewall

- Some staging environments block external REST API access
- Add your local machine's IP to the server's allowlist
- Alternatively, use a VPN or set up port forwarding
- On WP Engine/Kinsta/similar managed hosts: check if REST API access requires authentication at the server level

### Files pushed but site still shows old version

- Cache not flushed — click **Flush Cache** in Bridge Engine's quick actions
- Server-level cache (Nginx FastCGI, Varnish) not covered by the WP cache flush — purge it manually or via your host's dashboard
- Confirm with a hard refresh (Cmd+Shift+R / Ctrl+Shift+R) or incognito window

### Checking what was actually deployed

Go to **Tools → Deploy Bridge → Activity Log** on the staging site. Each push entry shows a full file manifest of what landed.

---

## Configuration Reference

All configuration is stored in `wp_options` under the key `deploy_bridge_config`:

```json
{
  "api_key": "a3f9b2c1d4e5f6...",
  "secret": "your-shared-secret",
  "allowed_paths": ["themes/my-theme-child", "mu-plugins"]
}
```

The activity log is stored separately under `deploy_bridge_log` (last 100 entries).

---

## Changelog

### 1.0.0
- Initial release
- REST API endpoints: status, push, pull, eval
- Path allowlist with traversal protection
- HMAC signature verification
- Multi-host cache flush (WP, W3TC, WP Rocket, LiteSpeed, SG Optimizer)
- Admin UI with Settings, Activity Log, and Setup Guide tabs
- LiveCanvas partial support (pull as JSON, publish drafts)

---

## License

GPL-2.0+
