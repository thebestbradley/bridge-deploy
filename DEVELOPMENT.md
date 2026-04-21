# Development & Release Workflow

> **For maintainers.** End users should read the main [README](./README.md).

This project's code lives in **two places** that stay in sync via `git subtree`:

| Location | Purpose | Repo |
|----------|---------|------|
| **Primary (working)** | Everyday development. All edits happen here. | `thebestbradley/local-sites` — private monorepo |
| **Distribution (public)** | What end users download and clone. | `thebestbradley/bridge-deploy` — public, standalone |

The public repo is a **subtree view** of the `local-plugin-theme-development/app/public/wp-content/plugins/_bridge-deploy-repo/` subfolder in the private monorepo.

---

## Why this setup?

- We develop inside `local-sites/` because that's where the plugins actually run against live local WordPress sites for testing
- We distribute from a clean public repo so contributors don't see our internal project noise
- `git subtree` keeps both histories aligned without nested `.git/` directories or submodule ceremony

Alternative approaches considered and rejected:
- **Git submodule** — forces the folder out of `local-sites/`, breaks live testing
- **Two independent repos with manual copy-paste** — guaranteed drift
- **Monorepo-only (no public repo)** — project isn't discoverable

---

## Daily Workflow

### Edit code

Work in the `local-sites` clone like any other day. Edit `bridge-engine/` or `deploy-bridge/` files directly, commit to `local-sites` with normal commit messages:

```bash
cd "~/Local Sites"
# edit stuff...
git add local-plugin-theme-development/app/public/wp-content/plugins/_bridge-deploy-repo/
git commit -m "fix(bridge-engine): handle Windows drive-letter paths"
git push origin <your-branch>
```

### Publish changes to the public repo

After pushing to `local-sites`, sync the subtree:

```bash
./scripts/sync-bridge-deploy.sh push
```

That's it. The standalone `bridge-deploy` repo on GitHub now mirrors the latest state. Commit messages and history are preserved (only commits that actually touched the subtree are included).

### Check what's out of sync

```bash
./scripts/sync-bridge-deploy.sh status
```

Shows the last 10 commits on each side so you can see what hasn't been pushed yet.

### Pull changes from the public repo

If a contributor opens a PR on the public `bridge-deploy` repo and it gets merged:

```bash
./scripts/sync-bridge-deploy.sh pull
```

This merges the public repo's changes back into `local-sites/` as a squash commit.

### Tag a release

When you want to cut a release on the public repo:

```bash
./scripts/sync-bridge-deploy.sh release v1.2.0
```

This:
1. Pushes any pending changes first
2. Creates a `v1.2.0` tag on the public repo
3. Prints the command to create a GitHub Release (copy-paste it)

Then publish release notes:

```bash
gh release create v1.2.0 --repo thebestbradley/bridge-deploy --generate-notes
```

---

## How `git subtree` Works (the short version)

- The subfolder's files live in `local-sites/` at the path `local-plugin-theme-development/app/public/wp-content/plugins/_bridge-deploy-repo/`
- When you `subtree push`, git walks the `local-sites` history and cherry-picks only commits that touched that subfolder, stripping the path prefix so files appear at the repo root in `bridge-deploy`
- When you `subtree pull`, git does the reverse: takes public-repo commits and replays them under the prefix in `local-sites`
- No `.git/` directory inside the subfolder, no submodule tracking, nothing weird on `git status`

---

## Subtree Quirks to Know About

### "Updates were rejected" on push

If someone pushed directly to the public repo (e.g. accepted a PR) and you haven't pulled yet, `subtree push` will be rejected. Fix:

```bash
./scripts/sync-bridge-deploy.sh pull
./scripts/sync-bridge-deploy.sh push
```

### The first push is slow

Subtree rewrites history on every push (scanning every commit). For this repo with a few hundred commits it's fast; if it ever gets slow, switch to `git subtree split` + cached branch.

### Commits touching BOTH subtree files AND other files

If a single commit in `local-sites` changed both `_bridge-deploy-repo/` files and, say, `scripts/`, subtree push includes only the subtree portion. The commit message and author are preserved, but the commit SHA will differ on the public side (it's a filtered cherry-pick).

### Contributors opening PRs on the public repo

They clone `bridge-deploy`, open a PR, it gets merged. Next `subtree pull` brings those commits into `local-sites` as a single squash commit under the subtree prefix. Original authorship shows up in the squash commit message.

---

## Directory Structure (reminder)

Inside `local-sites/`:

```
local-sites/
├── ... (lots of other project stuff)
└── local-plugin-theme-development/app/public/wp-content/plugins/
    └── _bridge-deploy-repo/          ← subtree source
        ├── README.md
        ├── LICENSE
        ├── DEVELOPMENT.md             ← this file
        ├── .gitignore
        ├── bridge-engine/
        └── deploy-bridge/
```

Inside the public `bridge-deploy` repo (after subtree push):

```
bridge-deploy/                         ← what contributors clone
├── README.md
├── LICENSE
├── DEVELOPMENT.md
├── .gitignore
├── bridge-engine/
└── deploy-bridge/
```

Same files, no path prefix.

---

## Why the folder is named `_bridge-deploy-repo`

The leading underscore prevents WordPress from trying to activate it as a plugin (WP skips folders starting with `_`). The folder is a sibling of actual WP plugins in `wp-content/plugins/` because we test the plugins inside a live WordPress site. You can safely ignore the folder when browsing that plugins directory.

---

## First-Time Setup (new machine)

If you cloned `local-sites` on a fresh machine and want to work on bridge-deploy:

1. The subtree script and prefix are already in place — nothing to configure
2. Run `./scripts/sync-bridge-deploy.sh status` to verify the remote can be reached
3. Make your changes in `_bridge-deploy-repo/`, commit to `local-sites`, then `sync-bridge-deploy.sh push`

The first run adds the `bridge-deploy` git remote automatically; subsequent runs reuse it.

---

## Versioning

Both plugins follow semver and bump together for paired releases:

| Release | Bridge Engine | Deploy Bridge | Tag on public repo |
|---------|---------------|---------------|---------------------|
| 1.0 | 1.0.0 | — | (none; internal) |
| 1.1 | 1.1.0 | 1.0.0 | `v1.1.0` |
| ... | | | |

When bumping, update:
1. Plugin header `Version:` comment in the PHP file
2. The `*_VERSION` constant
3. The Changelog section in each plugin's README
4. The root `README.md` changelog

Then `sync-bridge-deploy.sh release vX.Y.Z`.
