# Drupal 11 Upgrade — Group Module Findings & Plan

**Date:** 2026-05-29
**Branch:** `feature/drupal-upgrade`
**Author:** Engineering (recon by Claude Code)
**Scope:** Assess the contrib **Group** module as the first/blocking dependency for the Drupal 11 upgrade, then sequence the remaining module updates.

---

## 1. Executive Summary

The Group module is the **single largest blocker** for Drupal 11 and must be migrated first.

- Installed: **`drupal/group` 1.6.0** (`composer.json` constraint `^1.3`). Group 1.x supports `^9.5 || ^10` only — **no Drupal 11 release exists on the 1.x branch**.
- Drupal 11 requires **Group 2.x or 3.x**, which is a **major rewrite**, not a point upgrade:
  - The `group_content` entity type was renamed to **`group_relationship`** (entity type ID, base tables, classes, plugins).
  - The `GroupContent` class → **`GroupRelationship`**; `GroupContentEnabler` plugins → **`GroupRelationType`** plugins.
  - The `group.membership_loader` service / `GroupMembershipLoaderInterface` changed in 2.x and is **removed in 3.x** (replaced by `$group->getMember()` / relationship queries).
- **Group is foundational to Bebbo:** every country is a Group; per-country content/user access, Views filtering, moderation-action permissions, and per-group language visibility all ride on it. ~54 config files and 3 custom modules touch the Group API.
- Both Group patches we carry are written against 1.x and **will not apply** to 2.x/3.x.

**Verdict:** Treat Group as a dedicated, isolated migration milestone with its own branch, data-migration testing on a DB copy, and full regression of country access + moderation flows **before** any other D11 module work.

---

## 2. Current State

### Core & PHP
| Item | Current | D11 target |
|------|---------|-----------|
| `drupal/core` | 10.5.4 | 11.x (11.1+ recommended) |
| PHP | 8.3 | 8.3 OK (D11 needs ≥ 8.3) ✅ |

### Group footprint
- Enabled submodules (`config/sync/core.extension.yml`): `group`, `gnode`.
- **54** config YAML files reference `group_content` / `group_membership` / `gnode`.
- Group bundle in use: **`country`** (each country = one Group); relationship bundle `country-group_membership`.
- Group roles in config: `country-member`, `country-admin`.

### Patches we carry (both 1.x-only)
| Patch | Source | Breaks on 2.x/3.x? |
|-------|--------|--------------------|
| "Allow group admins to create user account and add to group" | `group-manage-users-2949408-45.patch` (d.o) | **Yes** — re-source the 2.x/3.x version of issue #2949408, or it may be obsolete. |
| "Fix Entity type doesn't exists" | `patches/group/group_fix.patch` (custom; rewrites `group_query_entity_query_alter`, swaps `QueryAccess\EntityQueryAlter` → `Query\EntityQueryAlter`) | **Yes** — query-access internals are reorganised in 2.x/3.x. Likely **unneeded** (the bug it patches may be fixed upstream). Re-evaluate, don't blindly port. |

---

## 3. Why Group Blocks Drupal 11

Group 1.x has **no D11-compatible release** and is effectively end-of-life. The 1.x → 2.x jump renamed the core entity:

| Group 1.x | Group 2.x / 3.x |
|-----------|-----------------|
| Entity type `group_content` | `group_relationship` |
| Class `Drupal\group\Entity\GroupContent` | `Drupal\group\Entity\GroupRelationship` (1.x name kept as deprecated alias in 2.x, **removed in 3.x**) |
| `GroupContentEnabler` plugin type | `GroupRelationType` plugin type |
| `group.membership_loader` + `GroupMembershipLoaderInterface` | changed signature in 2.x, **removed in 3.x** → use `$group->getMember($account)`, `$group->getMembers()`, relationship storage queries |
| `gnode` submodule | node support folded into generic relation plugins; `gnode` reworked/removed |

There is **no supported direct 1.x → 3.x data migration**. The path is **1.x → 2.x** (update hooks rename the entity type and migrate stored data) **then 2.x → 3.x**. Skipping 2.x risks orphaning group membership/content data.

---

## 4. Custom Code Touchpoints (must be rewritten)

All under `docroot/modules/custom/`. Every `group.membership_loader` / `GroupMembershipLoaderInterface` / `GroupContent` / `group_content`-storage reference needs migration.

### 4a. `pb_custom_field` — heaviest user
Workflow/bulk **Action plugins** all inject `group.membership_loader` (`GroupMembershipLoaderInterface`) and call `loadByUser()` → `$membership->getGroup()`:

- `src/Plugin/Action/AssigncontentAction.php` — `GroupMembershipLoaderInterface` (L48), `loadByUser` (L230), `getGroup()` (L236), `Group::loadMultiple()` (L221), `Group::load()` (L291), imports `GroupContent` (L22)
- `src/Plugin/Action/ChangeToSMEAction.php` (L39, L163, L169)
- `src/Plugin/Action/ChangedToSeniorEditorAction.php` (L36, L126, L143, L189)
- `src/Plugin/Action/ChangedToPublishedAction.php` (L36, L126, L143, L189)
- `src/Plugin/Action/ChangedToArchiveAction.php` (L39, L129, L146, L190)
- `src/Plugin/Action/MovefrompublishtosmeAction.php` (constructor injects `group.membership_loader` L127/L144/L164; `loadByUser` L183; `getGroup()` L188)
- `src/Plugin/Action/MovefrompublishtosenioreditorAction.php` (L67, L110, L121, L160)
- `src/Plugin/Action/MovefrompublishtodraftAction.php` (L37, L127, L144, L186)
- `src/ChangeActionStatus.php`, `ChangeintoSMEActionStatus.php`, `ChangeintoSeniorEditorActionStatus.php`, `ChangeintoPublishActionStatus.php`, `ChangeintoArchiveActionStatus.php` — each calls `->getGroup()` (~L40)
- `pb_custom_field.module`:
  - Direct `getStorage('group_content')` + `loadByProperties(['entity_id' => ...])` then `getGroup()` / fallback `->get('gid')` — **L2236–L2258** (node access check). The `group_content` storage ID → `group_relationship`; `gid` field name unchanged but verify.
  - `Group::load()` at L441, L445, L522; `->getGroup()` at L394, L561, L613, L700, L787, L827, L950.
  - `src/Routing/AdminRouteSubscriber.php` L47 — route string `group_content`.

**Migration pattern:** replace `\Drupal::service('group.membership_loader')->loadByUser($user)` (returns `GroupMembership[]`, each `->getGroup()`) with the 3.x equivalent — load the user's memberships via `group_relationship` storage / `GroupMembership` value objects from `$group->getMember()`. Confirm exact 3.x API against the module's `group.api.php` before coding.

### 4b. `group_country_field` — cross-cutting Views/form alterations
`group_country_field.module`:
- `use Drupal\group\Entity\Group;` (L10) — class unchanged, OK.
- `\Drupal::service('group.membership_loader')` + `loadByUser` + `getGroup()` (L66–L69) — **must migrate**.
- `hook_views_query_alter` for View **`duplicate_of_moderated_group_content`** (L56–L134): joins/conditions reference table `groups_field_data_group_content_field_data` and field `...group_content.label` (L76). **Table/relationship names change with the entity rename** — Views config + these raw query alters must be reworked together.
- `Group::load()` (L100, L141), `field_language` access (L106) — class API OK, just data path.

### 4c. `language_visibility_control`
- `src/LanguageVisibilityService.php` references Group API (per-group language visibility). Audit for `group_content`/membership-loader usage during migration.

> **Note:** the wiki feature note `feature-country-groups.md` confirms this is "cross-cutting … affects almost every admin interface." Regression scope is wide.

---

## 5. Configuration Touchpoints (~54 files)

The `group_content` → `group_relationship` rename ripples through config. The Group 2.x update hooks rewrite **stored entity data**, but **exported YAML must be re-exported** to match the new entity type IDs. Key affected configs in `config/sync/`:

- **Group entity config:** `group.content_type.country-group_membership.yml`, `group.role.country-member.yml`, `group.role.country-admin.yml`
- **Base field overrides:** `core.base_field_override.group_content.country-group_membership.*` (label, changed, created, metatag, path) — IDs change to `group_relationship.*`
- **Form/view displays:** `core.entity_form_display.group_content...`, `core.entity_view_display.group_content...`
- **Field storage:** `field.storage.group_content.group_roles.yml`
- **Language settings:** `language.content_settings.group_content.country-group_membership.yml`
- **Pathauto:** `pathauto.pattern.group_content.yml`
- **Views** referencing the group_content base table: `duplicate_of_moderated_group_content`, `content_listing`, `country_content_listing`, `global_content_listing`, `global_content_listing_country_users`, `global_reports`, `group_members`, `users_list`, `users_reports`, `user_admin_people`, `recent_logged_in_users`, `child_growth_reports`, `top_5_contents`
- **Splits:** `config_split.config_split.bebbo_site.yml`, `config_split.config_split.bangladesh_site.yml` reference group config — re-check after rename.
- `structure_sync.data.yml` — contains group taxonomy/data snapshot.

**Rule reminder (CLAUDE.md):** do **not** `drush cex` + commit the whole diff. After the module update + `cim`, export and commit **only** the group-related YAML that changed. `core.extension.yml` stays the single source of truth — `gnode` may need removal/replacement there once 2.x/3.x is in.

---

## 6. Other Contrib — Drupal 11 Readiness

From `composer.lock` (installed version vs. its `drupal/core` constraint):

### Blockers (no D11 in installed version — must bump or replace)
| Module | Installed | Constraint | Action |
|--------|-----------|-----------|--------|
| **group** | 1.6.0 | `^9.5 \|\| ^10` | → 2.x then 3.x (this doc) |
| **access_policy** | 1.0.0-rc1 | `^9.2 \|\| ^10` | Check for D11 release; tightly coupled to Group access — migrate **with** Group. |
| **video_embed_field** | 2.5.0 | `^9.2 \|\| ^10` | Needs newer release or patch; check D11 issue. |
| **ckeditor_media_embed** | 1.14.0 | `^8 \|\| ^9 \|\| ^10` | Check D11 release / replacement. |
| **migrate_source_csv** | 3.7.0 | `>=9.1` | Loose constraint — likely fine on D11, but bump to a release that declares D11. |

### Ready (installed version already allows `^11`)
content_moderation_notifications 3.8.0, entity_share 3.12.0, feeds 3.0.0, feeds_tamper 2.0.0-beta4, imageapi_optimize 4.1.1, inline_entity_form 3.0.0-rc21, jsonapi_extras 3.26.0, jsonapi_page_limit 1.3.0, lang_dropdown 2.4.0, memcache 2.7.0, menu_per_role 1.8.0, migrate_plus 6.0.8, restui 1.22.0, **tmgmt 1.18.0**, views_bulk_operations 4.3.4.

> These still need re-test on D11 even though the constraint allows it. Patches against them (`tmgmt`, `lang_dropdown`, `inline_entity_form`, etc. in `/patches/`) must be re-verified to still apply.

### Other patched modules to re-verify (in `/patches/`)
`content_moderation`, `config_split`, `entity_share`, `inline_entity_form`, `tmgmt`, `views_bulk_operations`, `view_custom_table`, `imagemagick`, `date_popup`, `autocomplete_id`, `gin`, plus core `htaccess.patch`. Each patch must be re-rolled against the D11-compatible release or dropped if fixed upstream.

---

## 7. Recommended Phased Plan (overview)

> Each phase is gated: **PHPCS clean + drupal-check clean + `cim` is a no-op** + manual regression of country access & moderation before moving on.

| Phase | Goal | Group version | Gate |
|-------|------|---------------|------|
| 0 | Prep & baseline | 1.6.0 | regression checklist captured, disposable DB ready |
| 1 | Group **1.x → 2.x** (entity rename + data migrate) | 2.x | memberships verified, custom code green |
| 2 | Group **2.x → 3.x** (drop membership_loader) | 3.x | memberships verified, regression green |
| 3 | Other D11-blocker contrib | — | builds, patches re-rolled |
| 4 | Core → D11 | — | CI green, no config drift |
| 5 | Per-site rollout (7×) | — | each site verified |

The Group jump (Phases 0–2) is the hard part and is expanded into a runbook below. Phases 3–5 are summarised in §10.

---

## 8. Group Migration — Detailed Runbook

> **Golden rule:** Group's own `drush updb` update hooks perform the
> `group_content → group_relationship` entity rename and migrate stored
> membership/content data. We never hand-write that data move. Our job is to
> make each `updb` **reversible** (DB backup) and **verifiable** (pre/post
> membership snapshot via the scripts in §11). Run the whole runbook on a
> **disposable DB copy first**, then per production site.

### Phase 0 — Prep & baseline

**0.1 Branch**
```bash
git checkout feature/drupal-upgrade
git checkout -b feature/d11-group-migration
```

**0.2 Disposable DB**
Import a production-like DB into a throwaway DDEV instance (e.g. from `pakistan_db.sql.gz` or a fresh per-site dump). Group update hooks mutate data — never first-run on a DB you cannot discard.

**0.3 Capture regression checklist** (this is the acceptance test for every phase):
- [ ] Country-scoped content listing shows only the editor's country content
- [ ] "Assign content" bulk action assigns to correct country + language list
- [ ] Each moderation action works: → SME, → Senior Editor, → Published, → Archive, → Draft
- [ ] Per-group language dropdown (`AssigncontentAction::getlanguages`) returns that group's languages only
- [ ] Group member Views (`group_members`, `users_list`, `users_reports`, `user_admin_people`) populate
- [ ] Node access check (`pb_custom_field.module:2236`) grants/denies by country correctly
- [ ] `duplicate_of_moderated_group_content` View filters by group language and shows latest revision
- [ ] A non-admin in country A cannot see/edit country B content (**security gate**)

**0.4 Baseline snapshot of memberships** (on current 1.x, before touching anything):
```bash
scripts/group_migration/migrate_group.sh <site> 1to2   # step 1 backs up + snapshots before updb
# or snapshot only, manually:
ddev drush @ddev.<site> scr scripts/group_migration/group_membership_snapshot.php -- snapshot artifacts/group_migration/<site>_baseline.json
```

**0.5 Record row counts** (independent cross-check of the JSON snapshot):
```bash
ddev drush @ddev.<site> sqlq "SELECT type, COUNT(*) FROM group_content_field_data GROUP BY type;"
ddev drush @ddev.<site> sqlq "SELECT COUNT(*) FROM groups;"
```

### Phase 1 — Group 1.x → 2.x

**1.1 Remove 1.x-only patches** from `composer.json` `extra.patches."drupal/group"`:
- `group-manage-users-2949408-45.patch` — re-source the 2.x version of issue #2949408 only if still needed.
- `patches/group/group_fix.patch` — re-evaluate; likely fixed upstream in 2.x (see §9 open question 3). Do **not** blindly port.

**1.2 Bump the constraint** (commit composer.json/lock once; do not run per site):
```bash
ddev composer require 'drupal/group:^2' --with-all-dependencies
# access_policy depends on Group — resolve it to a 2.x-compatible release in the SAME require:
ddev composer require 'drupal/group:^2' 'drupal/access_policy:^2' --with-all-dependencies
```

**1.3 Run the migration on the disposable DB:**
```bash
scripts/group_migration/migrate_group.sh <site> 1to2
```
This backs up → snapshots → `updb` (entity rename + data migrate) → `cr` → **verifies memberships preserved**. Non-zero exit = drift; the script prints the rollback command.

**1.4 Rewrite custom code to 2.x API** (Section 4). Minimum set:
- All `pb_custom_field` Action plugins: replace injected `group.membership_loader` / `GroupMembershipLoaderInterface`.
- `pb_custom_field.module:2236` — `getStorage('group_content')` → `getStorage('group_relationship')`.
- `group_country_field.module` — membership-loader calls (L66–69) + Views query-alter table names (L76, `groups_field_data_group_content_field_data`).
- `language_visibility_control/src/LanguageVisibilityService.php` — audit.
- Bump `group_country_field.info.yml` `core_version_requirement` already allows `^11` ✅; verify others.

**1.5 Re-export ONLY group config** (per CLAUDE.md — never full `cex`):
```bash
ddev drush @ddev.<site> cex -y --diff      # inspect first
# git add ONLY the renamed group_relationship.* / group.* YAML that changed
```

**1.6 Run the full Phase-0 regression checklist.** Fix Views query-alters until green.

**1.7 Quality gate:**
```bash
vendor/bin/phpcs docroot/modules/custom --standard=Drupal,DrupalPractice
vendor/bin/drupal-check -d docroot/modules/custom
vendor/bin/phplint docroot/modules/custom
ddev drush @ddev.<site> cim -y && ddev drush @ddev.<site> cst   # cim must be a no-op
```

### Phase 2 — Group 2.x → 3.x

**2.1 Bump:**
```bash
ddev composer require 'drupal/group:^3' --with-all-dependencies
```

**2.2 Migrate + verify** (same wrapper, label `2to3`):
```bash
scripts/group_migration/migrate_group.sh <site> 2to3
```

**2.3 Remove `group.membership_loader`** (deleted in 3.x). Replace remaining usage with `$group->getMember($account)` / `$group->getMembers()` / `group_relationship` storage queries. Confirm exact signatures against the 3.x `group.api.php`.

**2.4 Regression checklist + quality gate again** (as 1.6/1.7).

---

## 9. Rollback Plan

Every `updb` is preceded by a gzipped DB dump in `artifacts/group_migration/<site>_<step>_pre.sql.gz`. Rollback is **DB restore + code revert**, in that order is not required but both are needed.

### Per-site DB rollback (fast)
```bash
# Restores the pre-migration dump for one site:
scripts/group_migration/migrate_group.sh <site> --rollback artifacts/group_migration/<site>_1to2_pre.sql.gz
```
This drops the current DB, restores the dump, and rebuilds cache.

### Code rollback
```bash
git checkout composer.json composer.lock
ddev composer install        # reinstalls Group 1.6.0 + 1.x patches
# revert custom-code commits for this phase:
git revert <phase-commit-range>   # or git reset --hard <pre-phase-tag> on the feature branch
```

### Rollback decision matrix
| Symptom | Action |
|---------|--------|
| `migrate_group.sh` verify FAILS (membership/content drift) | Immediate DB rollback for that site; investigate `updb` log before retry. |
| `updb` errors mid-run | DB rollback (the partial schema is unusable); fix cause; retry. |
| Regression checklist fails but data intact | Keep DB; fix custom code; re-run checklist. No rollback needed. |
| `cim` not a no-op (config drift) | Re-export group YAML; do **not** force-import. No DB rollback needed. |
| Production incident post-deploy | Acquia: restore site DB from pre-deploy backup; redeploy previous release tag. |

### Safety invariants
- **Never** run a phase on production before the disposable-DB run is green end-to-end.
- **Always** confirm the `*_pre.sql.gz` exists and is non-empty before `updb`.
- Tag the branch before each phase: `git tag pre-group-1to2` / `pre-group-2to3` for one-command code reset.
- Keep the JSON membership snapshot (`artifacts/group_migration/<site>_*_snapshot.json`) as the source of truth for "what the user→country mapping was".

---

## 10. Remaining Phases (3–5) summary

**Phase 3 — Other D11-blocker contrib:** bump `video_embed_field`, `ckeditor_media_embed`, `migrate_source_csv` to D11 releases (or replace). Re-roll their patches.

**Phase 4 — Core → D11:** `composer require drupal/core-recommended:^11 …` (with `core-*` siblings), update platform if needed → `drush updb`, full `cim` per site, `drush cst` no drift, CI green.

**Phase 5 — Per-site rollout:** run the Phase 1–2 runbook (backup → snapshot → updb → verify → cim) on each of the 7 sites: default, bangladesh, turkey, ecuador, pacific_islands, somoa, zimbabwe. Each has its own DB — validate per site.

---

## 11. Migration Scripts

Committed under `scripts/group_migration/`:

| File | Purpose |
|------|---------|
| `group_membership_snapshot.php` | Version-agnostic snapshot/verify of **user→group memberships** (uid, gid, group_roles), groups, and content-relation counts. Auto-detects `group_content` (1.x) vs `group_relationship` (2.x/3.x). Exit 1 on any drift — usable as a migration gate. |
| `migrate_group.sh` | Per-site orchestrator: DB backup → pre snapshot → `updb` → `cr` → verify. `--rollback` restores a pre-migration dump. |

**Run order (per site, per step):**
```bash
# 1. bump composer.json once (see §8 Phase 1.2 / Phase 2.1), commit
# 2. migrate + verify one site:
scripts/group_migration/migrate_group.sh bangladesh 1to2
# 3. on failure, roll back that site:
scripts/group_migration/migrate_group.sh bangladesh --rollback artifacts/group_migration/bangladesh_1to2_pre.sql.gz
```

**What the snapshot guarantees:** if `verify` passes, every pre-migration `(country-group, user, roles)` membership still exists post-migration with identical roles, group count is unchanged, and per-group content-relation counts match. This is the concrete proof that **user country assignments survived** the entity rename.

> The script only reads/verifies — it does **not** create memberships. If `verify` reports `membership LOST`, that is a real data-migration failure → roll back, do not patch around it.

---

## 12. Risks

- **Data loss on group_content rename** — highest risk. The 2.x update hook migrates membership/content data; a failed/partial run silently breaks country access. Mitigate: test on disposable DB copies, verify row counts before/after.
- **Views breakage** — many admin Views join the group_content base table directly; raw query-alters in `group_country_field.module` will silently return wrong/empty results if table names aren't updated.
- **Access regressions** — `access_policy` + Group together enforce country scoping. A subtle break = users seeing other countries' content. Treat access as a security-grade regression test.
- **Patch rot** — ~15 patches across contrib + core; any that fail to apply block `composer install` in CI/Acquia.
- **Multisite multiplication** — every migration step runs 7×. Budget for it.

---

## 13. Open Questions (need answers before Phase 1)

1. **Target Group version:** ✅ **RESOLVED** — end state **3.x** (2.x↔3.x functionally identical; the data migration is the single 1.x→2.x hop, then bump to 3.x).
2. **`access_policy` future:** ✅ **RESOLVED** — `2.0.0-rc1` supports `^10.3 || ^11`; migrate alongside Group (`drupal/access_policy:^2`).
3. **Is `group_fix.patch` still needed?** Verify whether the `group_query_entity_query_alter` bug it fixes is resolved in 2.x/3.x before porting.
4. **D11 minor target:** 11.0 vs 11.1+ (affects which contrib releases qualify).
5. **gnode:** confirm replacement plan for the `gnode` submodule under 2.x/3.x.

---

## 🔧 TOBEFIXED — Deferred Decisions (revisit before go-live)

> Parked items the migration must NOT silently drop. Each blocks go-live, not
> the dev migration. **Remind the team about these at the end of the migration.**

1. **manage-users flow — DEFERRED TO CLIENT** *(highest-risk admin feature)*
   - **What:** the "country admin creates a NEW user account + adds them to the country group in one step" flow.
   - **Source:** a **pure drupal.org community patch** (`group-manage-users-2949408-45.patch`, issue #2949408) — **not** custom-tailored to Bebbo. The patch reworks `GroupContentEnabler/GroupMembership`, `GroupContentController`, `GroupContentForm` (all renamed/rewritten in 2.x → `GroupRelationType`/`GroupRelationship*`), adds a `group.create_member` 2-step wizard + a custom access handler + `views.view.group_members.yml` edits.
   - **Status on 2.x/3.x:** issue #2949408 is still **"Needs work"** — **never committed to any branch**, no 2.x/3.x port exists. So it is NOT in any newer Group release and cannot be re-rolled mechanically (every class it touches is renamed).
   - **Options:** (a) **reimplement custom** small module on the 2.x/3.x `GroupRelationType` API — faithful, no UX change; (b) **`ginvite`** — invite-by-email, **UX change** (admin no longer creates the account directly); (c) **drop** — admins use core `/admin/people` to create the user, then "Add existing member".
   - **Decision owner:** CLIENT. Pending input on how often country admins create accounts. Until decided, the migration proceeds **without** this flow (admins fall back to core user-create + add-existing-member).

---

## 14. Verification Commands (per CLAUDE.md)

```bash
ddev composer install
ddev drush updb -y                 # run on a DISPOSABLE DB copy first
ddev drush cr
ddev drush cim -y                  # per affected site
ddev drush cst                     # expect: no drift
composer validate --no-check-all --ansi
vendor/bin/phpcs docroot/modules/custom --standard=Drupal,DrupalPractice
vendor/bin/drupal-check -d docroot/modules/custom
vendor/bin/phplint docroot/modules/custom
```

---

*Recon method: composer.lock parse, grep of custom-module Group API symbols, config/sync scan, patch inspection, Bebbo wiki `feature-country-groups.md`. Exact 2.x/3.x API signatures must be confirmed against the module's `group.api.php` / upgrade docs on drupal.org before writing migration code.*

---

## Appendix A — Group Flow: Before vs After (client-facing explanation)

> Plain-language summary of what the Group upgrade changes. Use this to brief
> the client. **Headline: the way editors and country admins use Bebbo day-to-day
> stays the same — the changes are mostly internal plumbing plus a few admin
> screens.** The risk is in faithfully re-creating Bebbo's custom flows on the
> new Group engine, not in the features themselves disappearing.

### A.1 What Group does today (current behaviour)

| Area | How it works now (Group 1.x) |
|------|------------------------------|
| Countries | Each country is a "Group". Editors/admins belong to one or more country groups. |
| Content scoping | Editors only see/manage content for their country. Custom code filters every listing by the editor's country group. |
| Assigning content | The bulk **"Assign content"** action assigns content to a country + that country's languages. |
| Moderation | Workflow actions (→ SME, → Senior Editor, → Published, → Archive, → Draft) are restricted by the editor's role within their country group. |
| Languages | Each country group defines which languages appear (per-group `field_language`), driving dropdowns and the public API. |
| Membership | Users are added to a country group; a country admin can create a user and add them to the country (via a custom patch). |
| Permissions | Group permissions are granted per group-type role (e.g. `country-member`, `country-admin`). |

### A.2 What changes with the upgrade

**Stays the same (no client/editor-visible change — if custom code is rewritten faithfully):**
- Each country is still a Group; users still belong to country groups.
- Content is still scoped by country; editors still see only their country.
- "Assign content" and all moderation actions still behave identically (these are **our** custom code — we port them to the new API so the button does the same thing).
- Per-country language lists still drive dropdowns and the API.
- The public-facing app/API is **unaffected** — this is a back-office change.

**Changes under the hood (invisible to editors, important for the dev team):**
| Now | After |
|-----|-------|
| Internal entity called `group_content` | Renamed to `group_relationship` (data is migrated automatically). |
| Service `group.membership_loader` used to find a user's groups | Removed — replaced by a new mechanism (`$group->getMember()`). |
| Node-to-group link via `gnode` submodule | Reworked into the new generic "relationship" system. |
| Two custom Group patches | Must be re-created or dropped (may be fixed upstream). |

**Changes visible to admins (one-time, back-office only):**
| Now | After | Client impact |
|-----|-------|---------------|
| Admin screens/menus say "Group content" | Re-labelled "Relationships"; some admin URLs change | Cosmetic wording/links differ; same actions available. |
| Group permissions grid (1.x layout) | Reorganised permission model (scopes: member / non-member). | **One-time:** admins re-confirm/re-grant group permissions after upgrade. |
| Country admin creates user + adds to country (custom patch) | Patch must be re-sourced for the new version | **Re-test required** — this specific flow is the highest-risk admin feature. |
| Group roles attached to group type | Role model reworked (individual vs. global roles) | Existing `country-member` / `country-admin` roles re-mapped during migration; verify after. |

### A.3 What to flag to the client (risks / required validation)

1. **Re-testing window needed.** Because the engine is rebuilt, we must re-run a full back-office regression (content scoping, assign, moderation, member management, permissions) on each of the 7 country sites before go-live.
2. **Country admin "add user" flow** depends on a community patch that must be re-created on the new version — explicitly call this out as a test item.
3. **Group permissions** likely need a one-time re-grant/confirmation by admins post-upgrade.
4. **Admin wording/links change** ("Group content" → "Relationships") — minor retraining / screenshot updates if there's a user manual.
5. **No change to the public app or API**, and **no loss of any country/membership data** — every user's country assignment is snapshotted and verified before and after migration (see §11).
6. **Two-step upgrade** (1.x → 2.x → 3.x) per site, each with backup + rollback — schedule maintenance windows accordingly.

> **One-line client summary:** "The country/permissions system is being rebuilt on a newer engine required for Drupal 11. Editors won't notice a difference; admins will see some renamed screens and need to re-confirm permissions once. No country data is lost, and the public app is unaffected — but it requires careful re-testing of the back office on each country site."

*Note: exact admin-screen wording and the precise 2.x-vs-3.x permission model should be confirmed against the installed Group release before finalising client-facing screenshots.*

---

## Appendix B — Progress Log (running record of what we did)

> Chronological log of actual work done against this plan, so anyone can see
> what was executed, how, and what we found. Newest entries at the bottom.

### 2026-06-02 — Resolved §13 open questions (drupal.org recon)

Checked release/issue status on drupal.org before any code or DB work:

| Question | Answer | Source |
|----------|--------|--------|
| §13.1 Target Group version | Group **2.x and 3.x are functionally identical** (machine-name difference only, **no 2→3 migration path**). Only risky data migration is **1.x → 2.x**. End state = 3.x. | group releases page |
| §13.2 `access_policy` D11 | **2.0.0-rc1** (29 May 2025) supports `^10.3 || ^11` — D11 gate **cleared** (RC quality, flag risk). | project/access_policy |
| §13.3 manage-users patch (#2949408) | **Dead on 2.x/3.x.** Status "Needs work"; maintainer: porting is a "big job, architecture changed a lot". Patch #56 is 1.x-only. → reimplement / use `ginvite` / drop. **Open decision.** | node/2949408 |

**Plan correction:** §3/§8 assumed a hard 1→2→3 chain. Since 2.x↔3.x are equivalent, the **only** data-migration risk is the single **1.x → 2.x** hop; 2→3 is trivial.

### 2026-06-02 — Pre-upgrade baseline tooling (Phase 0.4/0.5)

Goal: write down EVERY group + relationship per site as the verification/restore
source of truth, and prove the snapshot has zero counting mistakes.

**1. Extended `scripts/group_migration/group_membership_snapshot.php`:**
- Added a per-row record of every relation (`relations[]`: `rid, gid, bundle, target_type, target_id, langcode, roles`) plus `relation_total`. Memberships logic left untouched.
- `verify` now also checks each baseline relation still exists post-migration, keyed on the version-stable tuple `gid:target_type:target_id` (rid/bundle excluded — they change in the rename).
- **Gotcha found:** `drush scr` does **not** propagate PHP exit codes — it returns status **1** for ANY `exit()`, including `exit(0)` and a clean `verify`. Replaced the exit-code gate with a printed sentinel **`GM-RESULT: PASS|FAIL`** that callers grep. ⚠️ `migrate_group.sh`'s existing exit-code gate is broken for the same reason and needs the same fix before Phase 1.

**2. Added `scripts/group_migration/baseline_all_sites.sh`** — per site:
- runs the snapshot → `artifacts/group_migration/<site>_baseline.json` (path passed as the **container** path `/var/www/html/...` because drush runs inside DDEV; host reads it back through the mount);
- raw `sql-dump` of **all** `group*` tables (33 tables) → `artifacts/group_migration/<site>_group_tables_pre.sql.gz` (restore source);
- **triple cross-check** (the "no mistake" guarantee): entity-API `relation_total` == per-row array length == raw SQL `COUNT(*)`; plus base vs `_field_data` table, membership rows, and group count. Any disagreement = FAIL.

**3. Ran it on all 7 sites** (`bebbo bangla ec pakistan tr ws zw`):

| Site | Groups | Members | Relations | Result |
|------|-------:|--------:|----------:|--------|
| bebbo | 18 | 233 | 233 | ✅ PASS |
| bangla | 1 | 26 | 26 | ✅ PASS |
| ec | 1 | 25 | 25 | ✅ PASS |
| tr | 1 | 11 | 11 | ✅ PASS |
| ws | 2 | 40 | 40 | ✅ PASS |
| zw | 1 | 43 | 43 | ✅ PASS |
| **pakistan** | 1 | 1 | 240 | ❌ **FAIL — DB integrity** |

**Pakistan finding (caught by the triple-check):** `group_content` base = **240** rows but `group_content_field_data` = **1**. **239 orphan base rows** (uuid+type+langcode only; no gid/entity_id/user/roles/revisions). Only **1 real membership**: uid 1 `useradmin`, role `country-admin`. Confirmed by user: PK is newly created, only uid 1 should be a member. Root cause: the Entity Share content pull (duplicate-UUID crash workaround left half-formed `group_content` shells). These orphans would garbage/break the 1.x→2.x `updb` and must be cleaned first.

**PK cleanup — DONE (2026-06-02):** ran (pre-dump `pakistan_group_tables_pre.sql.gz` taken first):
```sql
DELETE FROM group_content WHERE id NOT IN (SELECT id FROM group_content_field_data);
```
`group_content` base 240 → 1, `drush cr`, baseline re-run → **`PASS rel=1 members=1 groups=1`**. The `pakistan_*` artifacts were regenerated and now reflect the clean state (correct restore source going forward).

**Status:** Phase 0 baseline captured and **green for all 7 sites**.

### 2026-06-02 — Decisions locked, manage-users deferred

- Group **end state = 3.x**; `access_policy → ^2` alongside it (§13.1/§13.2 resolved).
- **manage-users flow → DEFERRED TO CLIENT**, parked in the new **🔧 TOBEFIXED** section. Confirmed it's a pure d.o community patch (#2949408), not in any newer release, requires rebuild. Migration proceeds without it for now.
- **Next:** Phase 1 — update Group 1.x → 2.x (entity rename via `updb` on a disposable DB), then bump to 3.x. Prereqs first: branch, remove 1.x patches, fix `migrate_group.sh` gate (`GM-RESULT` sentinel).

### 2026-06-02 — Phase 1 prereqs + composer bump to 2.x (code level)

Done (all on branch `feature/d11-group-migration`):
1. **Branch** `feature/d11-group-migration` created off `feature/drupal-upgrade`.
2. **Removed both 1.x Group patches** from `composer.json` (`group-manage-users-2949408-45.patch` + `patches/group/group_fix.patch`) — neither applies to 2.x and `composer-exit-on-patch-failure: true` would abort install.
3. **Fixed `migrate_group.sh`** — same two bugs as the snapshot script: (a) snapshot path now container-absolute, (b) pre/post gates now grep `GM-RESULT: PASS` instead of the (always-1) drush exit code; also hardened the pre-`updb` backup (pipe to gzip + empty-file guard).

**Composer bump — BLOCKER found then resolved:**
- First attempt `require group:^2 access_policy:^2 -W` **failed**: `--with-all-dependencies` let the solver drag `drupal/core` toward 11.x/12.x-dev, all advisory-blocked → dead-end.
- Root cause (real blocker): **the pinned core `10.5.4` is itself behind security advisories** (June 2026, up to SA-CORE-2026-004), and `core-recommended` was pinned to the *exact* `10.5.4` (not `^10.5`). Composer's insecure-blocking refuses to load any core in `access_policy`'s `^10.3 || ^11` range during re-resolution → nothing satisfies. **Lesson: a stale exact-pinned core blocks ALL dependency re-resolution, not just core.**
- Fix (user chose target **10.6.10** — last D10 minor, best D11 stepping-stone): bumped the 3 core packages to `10.6.10` **together with** `group:^2 access_policy:^2` in one `require -W`. The pinned secure core anchored the solver. **EXIT 0.**

**Landed:** core **10.6.10**, group **2.3.2**, access_policy **2.0.0-rc1**; `GroupRelationship.php` (2.x entity classes) present; core patches reapplied; 3 advisories remain on 3 other contrib packages (Phase 3). **No DB touched yet** — `updb` (the irreversible 1.x→2.x entity rename + core 10.5→10.6 hooks) is the next step, to run via `migrate_group.sh <site> 1to2` on a disposable DB, one site first.

### 2026-06-02 — Phase 1 DB migration (1.x → 2.x) COMPLETE for all 7 sites

`migrate_group.sh` reworked before running: with the new Group code installed a *live* pre-snapshot is impossible (entity type is the new one but its table doesn't exist pre-`updb`), so the script now uses the **existing `<site>_baseline.json`** (captured on Group 1.6.0) as the pre-migration reference and verifies the post-`updb` data against it. Also fixed a `set -e` abort: `VAR="$(drush scr …)"` tripped on drush's always-1 exit; added `|| true` and gate on the `GM-RESULT` sentinel.

**Key correction:** the 1.x→2.x step does NOT rename the entity type — it stays `group_content` (only PHP classes were renamed to `GroupRelationship*`). Update hooks run: `group_update_9200–9211`, `system_update_10600`, `gnode_update_8007`. The `group_content → group_relationship` **table** rename is the **2.x→3.x** step.

**Results (each: full DB backup → updb → cr → verify vs baseline):**

| Site | Groups | Members | Relations | 1→2 |
|------|-------:|--------:|----------:|:---:|
| bangla | 1 | 26 | 26 | ✅ |
| bebbo | 18 | 233 | 233 | ✅ |
| ec | 1 | 25 | 25 | ✅ |
| pakistan | 1 | 1 | 1 | ✅ |
| tr | 1 | 11 | 11 | ✅ |
| ws | 2 | 40 | 40 | ✅ |
| zw | 1 | 43 | 43 | ✅ |

All memberships preserved, verified against the 1.x baselines. Per-site pre-`updb` backups in `artifacts/group_migration/<site>_1to2_pre.sql.gz`.

**Gotcha — tr:** first run aborted at the backup step — `mariadb-dump Error 2013: Lost connection … dumping table 'batch' at row 25`. An oversized stuck row in the transient `batch` table crashed the dump. Fix: `TRUNCATE TABLE batch` (transient batch-job state only — no content/config/membership data), then re-ran → PASS. Watch for this on other sites' dumps.

**Safety confirmed:** on branch `feature/d11-group-migration` (main + feature/drupal-upgrade untouched); **zero** custom-module files changed yet; every site has a full pre-`updb` backup. Local DDEV only — Acquia/production never contacted.

**Next:** Phase 1.4 — rewrite custom code to the 2.x API (`pb_custom_field` actions, `group_country_field` Views query-alters, `pb_custom_field.module:~2236`), then regression + quality gate, then Phase 2 (2.x→3.x).

### 2026-06-02 — Phase 1.4: mapped what actually breaks on 2.x, then rewrote

Mapped empirically against installed Group **2.3.2** (grep + live runtime probes on the migrated bangla), correcting several doc assumptions:

**What 2.x KEEPS (works as-is — defer to 3.x):**
- Entity type id is **still `group_content`** (`hasDefinition('group_content')=Y`, `group_relationship=N`). So `getStorage('group_content')` (e.g. `pb_custom_field.module:2236`) works.
- `group.membership_loader` service + `GroupMembershipLoaderInterface` + `loadByUser()` + `->getGroup()` — all present in 2.x (removed only in 3.x).
- Entity routes `entity.group_content.*` still exist.
- All custom Views **execute** (entity-API layer remaps correctly).

**What 2.x BREAKS (the real surface — the *storage tables* were renamed at 2.x, NOT 3.x):**
- Tables `group_content` / `group_content_field_data` → **`group_relationship` / `group_relationship_field_data`** (+ new `group_config_wrapper`). The entity id stayed `group_content` but its base/data tables are the new names.
- So **raw SQL / Views-alias string-matches on the old table name break.** Found + fixed exactly 5 spots (+1 dead import):

| File:line | Was | Now |
|-----------|-----|-----|
| `pb_custom_field.module:1127,1133` | `select/fields('group_content_field_data')` | `…('group_relationship_field_data')` |
| `pb_custom_field.module:1433,1435` | alias `groups_field_data_group_content_field_data.id` | `…group_relationship_field_data.id` (scopes `users_list` to reviewer's country — security) |
| `group_country_field.module:76` | alias `…group_content_field_data.label` | `…group_relationship_field_data.label` (`recent_logged_in_users`) |
| `AssigncontentAction.php:22` | dead `use …\GroupContent` | removed (class gone in 2.x) |

New Views alias confirmed by dumping the live built query: `groups_field_data_group_relationship_field_data`. The moderated-group alter (`group_country_field.module:92`) needed no change — it filters on `node_field_revision`, no group table.

**Verified:** PHPCS + drupal-check + phplint clean on all touched files (auto-hook); no stale `group_content_field_data` refs remain; runtime re-smoke on bangla — raw query OK, all probed views execute.

**Still pending for full Phase 1 sign-off:** browser regression with a logged-in reviewer (the §0.3 checklist — country scoping, assign, moderation actions), then re-export ONLY changed group config + confirm `cim` no-op, then Phase 2 (2→3).
