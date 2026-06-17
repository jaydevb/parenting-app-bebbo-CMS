# Bebbo CMS — Operational Runbook

> **Audience:** developers and operators running, maintaining, and troubleshooting Bebbo locally and on Acquia.
> **Scope:** day-to-day operational procedures — local lifecycle, per-site Drush, config import/export, code-quality gate, custom Drush commands, maintenance scripts, deploy steps, troubleshooting.
> **Verified against:** repository `HEAD` (branch `develop`). Every command, alias, script path, and binary below was confirmed in the repo. Items that do **not** work as a naive reader might expect are flagged ⚠️. Deep dives live in the sibling docs linked in [§12](#12-related-docs).

---

## 1. Quick Reference

| Task | Command |
|------|---------|
| Start / stop local env | `ddev start` · `ddev stop` · `ddev restart` · `ddev poweroff` |
| Install PHP deps | `ddev composer install` |
| Rebuild cache (default site) | `ddev drush @ddev.bebbo cr` |
| Import config (a site) | `ddev drush @ddev.<alias> cim -y` |
| One-time admin login link | `ddev drush @ddev.<alias> uli` |
| Run full quality gate | see [§6.2](#62-code-quality-checks) |
| Secret scan setup | auto via `composer install`; see [§6.1](#61-secret-scanning-pre-commit-hook) |
| Open phpMyAdmin | `ddev phpmyadmin` |
| Project info / URLs | `ddev describe` |

> `<alias>` is one of the **7 local Drush aliases** — they are **not** the site directory names. See [§3](#3-site--alias-map).

---

## 2. Local Environment Lifecycle (DDEV)

Source: `.ddev/config.yaml` (project `bebbo.app`, PHP 8.4, Apache-FPM, MariaDB 10.11). See [`ENVIRONMENTS.md`](ENVIRONMENTS.md) §2.

```bash
ddev start                 # boot containers
ddev composer install      # install dependencies (Drush is installed via composer)
ddev describe              # show per-site URLs and DB ports
ddev launch                # open default site in browser
ddev stop                  # stop this project
ddev poweroff              # stop ALL ddev projects
```

- Local databases are **per-site** (`bangladesh_db`, `turkey_db`, …); the default site uses DDEV's base `db`. Created by `.ddev/mysql/init-databases.sql`.
- phpMyAdmin: `ddev phpmyadmin` (custom host command — opens phpMyAdmin on the DDEV-assigned port).
- Run a raw SQL file against a site DB, e.g. truncate content: `ddev mysql <db_name> < scripts/truncate_content.sql` (see [§8](#8-maintenance-scripts)).

---

## 3. Site ↔ Alias Map

The local Drush alias key, the site directory, and the config folder do **not** all match — but each site now resolves by **both** its short alias and its directory name. Source: `drush/sites/ddev.site.yml`, `docroot/sites/`, `config/`.

| Site | Local Drush aliases (both work) | `docroot/sites/` dir | `config/` folder |
|------|----------------------------------|----------------------|------------------|
| Default (Bebbo) | `@ddev.bebbo` · `@ddev.default` | `default` | `bebbo` |
| Bangladesh | `@ddev.bangla` · `@ddev.bangladesh` | `bangladesh` | `bangla` |
| Ecuador | `@ddev.ec` · `@ddev.ecuador` | `ecuador` | `ecuador` |
| Pakistan | `@ddev.pakistan` | `pakistan` | `pakistan` |
| Pacific Islands (Bebbo Pacific) | `@ddev.ws` · `@ddev.somoa` | `somoa` | `somoa` |
| Turkey | `@ddev.tr` · `@ddev.turkey` | `turkey` | `turkey` |
| Zimbabwe | `@ddev.zw` · `@ddev.zimbabwe` | `zimbabwe` | `zimbabwe` |

> Both the short key and the site-directory name are defined in `drush/sites/ddev.site.yml`, so `@ddev.bangla` and `@ddev.bangladesh` are equivalent.
>
> Remote Acquia aliases (`@parentbuddy2.dev|test|prod`) live in `drush/sites/parentbuddy2.site.yml` and are for cloud envs only — see [`CICD_DEPLOYMENT.md`](CICD_DEPLOYMENT.md) §3.

---

## 4. Cache & Routine Drush Operations

Run against any single site with its alias:

```bash
ddev drush @ddev.tr cr            # rebuild cache
ddev drush @ddev.tr cron          # run cron
ddev drush @ddev.tr uli           # admin one-time login link
ddev drush @ddev.tr updb -y       # run pending DB updates
ddev drush @ddev.tr cst           # show config status (drift)
```

To run across all 7 locally, loop the aliases (`bebbo bangla ec pakistan ws tr zw`).

---

## 5. Configuration Import / Export

Config layering (shared `config/sync` + per-site split) is documented in [`CONFIGURATION.md`](CONFIGURATION.md) and [`ENVIRONMENTS.md`](ENVIRONMENTS.md) §6.

### Import (apply committed config to a site)

```bash
ddev drush @ddev.<alias> cim -y
ddev drush @ddev.<alias> cr
```

A clean state is when `cim` is a no-op (no pending changes) and `cst` shows no drift.

### Export (only when you intend to change committed config)

```bash
ddev drush @ddev.bebbo cex -y     # export from the DEFAULT site
```

Then commit **only** the YAML files for the change you made — not the full diff.

> **Adding a module to ONE site** (verified file targets): declare it in that site's split entity `config/sync/config_split.config_split.<site>_site.yml` (the `module:` field), place the module's config YAML under that site's `config/<folder>/`, then `cim -y` on that site only. Do **not** edit `core.extension.yml` (it is the shared source of truth for all 7 sites) and do **not** touch other splits. Note the split entity is named after the **directory** (`bangladesh_site`), while the default site's split is `bebbo_site`.

---

## 6. Code Quality Gate (pre-commit)

### 6.1 Secret scanning (pre-commit hook)

A git pre-commit hook scans staged files for hardcoded secrets (API keys, tokens, passwords, private keys). It is **auto-installed** by `composer install` / `composer update` via the `install-git-hooks` script in `composer.json`.

- Source: `scripts/git-hooks/pre-commit`
- Installed to: `.git/hooks/pre-commit`
- No external dependencies — uses `grep` only
- Blocks commit if secrets detected; bypass with `git commit --no-verify` (use with caution)
- Also blocks forbidden files (`.env`, `auth.json`, `*.pem`, `*.key`, etc.)

To manually re-install if needed:

```bash
cp scripts/git-hooks/pre-commit .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit
```

### 6.2 Code quality checks

The exact checks CI runs (`.github/workflows/pipelines.yml`, job `ci-checks`). Run locally before pushing:

```bash
composer validate --no-check-all --ansi
ddev composer install
ddev exec ./vendor/bin/phpcs docroot/modules/custom --standard=Drupal,DrupalPractice
ddev exec ./vendor/bin/drupal-check -d docroot/modules/custom
ddev exec ./vendor/bin/phplint docroot/modules/custom
```

PHPCS config: `phpcs.xml.dist`. All four must pass — `deploy-dev`/`deploy-stage` are gated on `ci-checks`.

---

## 7. Custom Drush Commands

All 11 are **legacy-style** (`@command` annotations) across three custom modules. Run with a site alias, e.g. `ddev drush @ddev.bangla <command>`. Most mutating commands are **dry-run by default** and need `--execute` to write — verify before running on real data.

### `file_sanitizer`

| Command | Alias | Action | Key args/options |
|---------|-------|--------|------------------|
| `node:touch` | — | Re-save published nodes to bump `changed` across translations (email suppressed) | `--nid`, `--limit`, `--type` |
| `file-sanitizer:scan` | — | Scan cover-image files for unsafe filenames (dry-run) | `--execute`, `--limit` |
| `file-sanitizer:scan-mime` | — | Find files whose extension ≠ actual MIME type | `--execute`, `--limit` |
| `file-sanitizer:scan-body` | `fssb` | Scan body-embedded media for unsafe filenames | arg `content_type`; `--execute`, `--limit` |

### `custom_article`

| Command | Alias | Action | Key args/options |
|---------|-------|--------|------------------|
| `custom-article:copy-keyword` | `copy-keyword` | Copy taxonomy keyword names into `field_meta_keywords` (200/run) | arg `node_type`; arg `offset` (default 0) |
| `custom-article:custom-article-update` | `custom-article-update` | Set `field_do_not_feature` = inverse of `field_suggest_as_daily_reads` on non-English published articles | — |
| `custom-article:delete-terms` | `dlt` | Delete a hardcoded babuni-specific list of taxonomy TIDs | — |

### `bebbo_serializer`

| Command | Alias | Action | Key args/options |
|---------|-------|--------|------------------|
| `embedded-images:populate` | `eip` | Populate `field_embedded_images` from body HTML image URLs | arg `content_type`; `--limit`, `--dry-run` |
| `body-rendered:populate` | `brp` | Populate `field_body_rendered` from body HTML | arg `content_type`; `--limit`, `--dry-run` |
| `file-paths:fix` | `fpf` | Rewrite `/sites/default/files/` refs to current site + copy files (⚠️ refuses to run on default site) | arg `content_type`; `--limit`, `--dry-run`, `--taxonomy` |
| `bebbo:remove-tr-translations` | `rm-tr` | Remove Turkish (`tr`) translations from nodes/terms/media + path aliases (dry-run) | `--execute`, `--force-delete-default`, `--entity-type`, `--batch-size` (50) |

---

## 8. Maintenance Scripts

Source: `scripts/`.

### `scripts/truncate_content.sql`
Truncates content tables (node, content_moderation_state, media, files, taxonomy, groups, menu_link_content, block_content, path_alias, shortcut) inside `SET FOREIGN_KEY_CHECKS=0/1`. Plain SQL — **destructive**. Invoke against a target DB:

```bash
ddev mysql <db_name> < scripts/truncate_content.sql
```

---

## 9. Deployment

Full pipeline walkthrough: [`CICD_DEPLOYMENT.md`](CICD_DEPLOYMENT.md). Operator summary (verified from `pipelines.yml` + `hooks/`):

| To deploy to… | Do this | Result |
|---------------|---------|--------|
| **Dev** | push / merge to `develop` | CI runs, then `acli push:artifact @parentbuddy2.dev` (PHP 8.4) |
| **Stage** | push / merge to `stage` | CI runs, then `acli push:artifact @parentbuddy2.test` (PHP 8.4) |
| **Prod** | **manual only** | no `deploy-prod` job; cloud hook **skips** DB/config on prod |

- `main` is **not** a deploy trigger.
- Post-deploy (non-prod), the Acquia cloud hook loops all 7 sites with labeled progress (`Site N/7`, steps `[1/5]`–`[5/5]`) running `drush cr / updb -y / cim -y` (with `cim` run twice). See [`ENVIRONMENTS.md`](ENVIRONMENTS.md) §8.
- ⚠️ `acli` is **not** in `composer.json`/`vendor/bin` — CI downloads it from GitHub releases at runtime. There is no repo-provided `acli`; install it globally if deploying by hand.

---

## 10. Cross-Site Content Sync (Entity Share)

Content is not shared at the DB level. Moving content between sites/environments uses the **Entity Share** module (`drupal/entity_share`). Each site exposes JSON:API channels and can pull from a configured remote (e.g. pull Prod content to a local/Dev site). Channel/remote config is per-site (in the site's config split). See [`DEPENDENCIES.md`](DEPENDENCIES.md) §3.7 and [`ENVIRONMENTS.md`](ENVIRONMENTS.md) §9.

---

## 11. Troubleshooting

| Symptom | Cause / Fix |
|---------|-------------|
| `@ddev.<site>` "alias not found" | Both short keys and directory names are defined in `drush/sites/ddev.site.yml`. If still failing, run from project root and check the alias file (see [§3](#3-site--alias-map)). |
| `composer post-create-project-cmd` fails: `blt: command not found` | ⚠️ Expected — `acquia/blt` is **not installed** (legacy). Don't run the create-project script. `composer nuke` is fine (it does not call blt). |
| Config keeps re-importing / drift after `cim` | Confirm you exported from `@ddev.bebbo` and committed only the intended YAML; check the change belongs to `config/sync` vs the right `config/<folder>` split. |
| Custom Drush command "writes nothing" | Most are dry-run by default — add `--execute` (file-sanitizer/rm-tr) once verified. |
| `file-paths:fix` refuses to run | By design it will not run on the default site — target a country alias. |
| Container / port issues | `ddev restart`, then `ddev poweroff && ddev start`. Check `ddev describe`. |

---

## 12. Related Docs

| Topic | Doc |
|-------|-----|
| Environments, settings load, cloud hooks | [`ENVIRONMENTS.md`](ENVIRONMENTS.md) |
| CI/CD pipeline & deploy internals | [`CICD_DEPLOYMENT.md`](CICD_DEPLOYMENT.md) |
| Config reference (roles, fields, workflow, groups) | [`CONFIGURATION.md`](CONFIGURATION.md) |
| Dependencies & third-party services | [`DEPENDENCIES.md`](DEPENDENCIES.md) |
| Custom modules reference | [`MODULES.md`](MODULES.md) |
| System architecture | [`ARCHITECTURE.md`](ARCHITECTURE.md) |
