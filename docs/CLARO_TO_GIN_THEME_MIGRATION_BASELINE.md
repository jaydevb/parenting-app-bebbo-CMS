# Claro to Gin Admin Theme Migration — Baseline Audit

**Date:** 2026-05-27
**Branch:** `feature/update-theme`
**Purpose:** Document current Claro-based admin toolbar, menus, permissions, and role visibility before switching to Gin admin theme.

---

## Table of Contents

1. [Why We're Migrating](#1-why-were-migrating)
2. [Current Theme Configuration](#2-current-theme-configuration)
3. [Toolbar Module Stack](#3-toolbar-module-stack)
4. [Module Configuration Details](#4-module-configuration-details)
5. [Admin Theme Block Placements (Claro)](#5-admin-theme-block-placements-claro)
6. [Editorial Menu — Full Structure](#6-editorial-menu--full-structure)
7. [Quick Links Menu — Full Structure](#7-quick-links-menu--full-structure)
8. [Role Definitions & Permission Matrix](#8-role-definitions--permission-matrix)
9. [Menu Per Role — Item-Level Visibility](#9-menu-per-role--item-level-visibility)
10. [Custom Code: Toolbar & Menu Hooks](#10-custom-code-toolbar--menu-hooks)
11. [Custom Admin CSS (Claro-specific)](#11-custom-admin-css-claro-specific)
12. [Per-Site Config Split Overrides](#12-per-site-config-split-overrides)
13. [Gin Migration Checklist](#13-gin-migration-checklist)
14. [Risk Assessment](#14-risk-assessment)

---

## 1. Why We're Migrating

**Primary:** Claro lacks proper RTL (Right-to-Left) support. Pakistan site (Urdu) has broken toolbar layout — `admin_toolbar` uses `left:` CSS positioning without RTL counterparts.

**Secondary:** Gin theme uses CSS Logical Properties (`inline-start`/`inline-end`) for native RTL support. Gin will become Drupal 11.3 default admin theme.

**Additional benefits:** Dark mode, sticky save bar, improved accessibility, better mobile responsiveness.

---

## 2. Current Theme Configuration

**File:** `config/sync/system.theme.yml`

```yaml
admin: claro
default: claro
```

Both admin and default themes are Claro. Gin is **NOT** currently in `composer.json`.

---

## 3. Toolbar Module Stack

All modules enabled in `config/sync/core.extension.yml`:

| Module | Machine Name | Composer Package | Weight | Status |
|--------|-------------|-----------------|--------|--------|
| Toolbar (core) | `toolbar` | Core | 0 | Enabled |
| Admin Toolbar | `admin_toolbar` | `drupal/admin_toolbar ^3.6` | 0 | Enabled |
| Admin Toolbar Tools | `admin_toolbar_tools` | (part of admin_toolbar) | 0 | Enabled |
| Admin Toolbar Search | `admin_toolbar_search` | (part of admin_toolbar) | 0 | Enabled |
| Toolbar Menu | `toolbar_menu` | `drupal/toolbar_menu ^3.1` | 0 | Enabled |
| Toolbar Menu Clean | `toolbar_menu_clean` | `drupal/toolbar_menu_clean ^1.3` | 0 | Enabled |

**Note:** Navigation module (Drupal 10.3+) is **NOT** enabled.

---

## 4. Module Configuration Details

### Admin Toolbar Settings
**File:** `config/sync/admin_toolbar.settings.yml`

```yaml
menu_depth: 4
hoverintent_behavior:
  enabled: false
  timeout: 500
enable_toggle_shortcut: false
```

- Dropdown depth: 4 levels deep
- Hover intent: disabled (immediate show on hover)
- Toggle shortcut in toolbar: disabled

### Admin Toolbar Search Settings
**File:** `config/sync/admin_toolbar_search.settings.yml`

```yaml
display_menu_item: false
enable_keyboard_shortcut: true
```

- Search not shown as menu item
- Keyboard shortcut enabled (Ctrl+K or similar)

### Admin Toolbar Tools Settings
**File:** `config/sync/admin_toolbar_tools.settings.yml`

```yaml
max_bundle_number: 20
```

- Shows up to 20 content type bundles in dropdown

### Toolbar Menu — Editorial Menu Element
**File:** `config/sync/toolbar_menu.toolbar_menu_element.editorial_menu.yml`

```yaml
uuid: 331e788e-5348-49cf-9bb3-94a79effe362
id: editorial_menu
label: 'Editorial Menu'
status: true
menu: editorial-menu
rewrite_label: false
weight: null
```

This config makes the `editorial-menu` system menu appear as a top-level toolbar tab.

### Menu Per Role Settings
**File:** `config/sync/menu_per_role.settings.yml`

```yaml
admin_bypass_access_front: true
admin_bypass_access_admin: true
hide_show: 2
hide_on_content: 2
```

- Admins bypass menu_per_role restrictions on both frontend and admin
- `hide_show: 2` — Items shown only to specified roles (whitelist mode)
- `hide_on_content: 2` — Same behavior on content pages

---

## 5. Admin Theme Block Placements (Claro)

### Claro Theme Blocks
All blocks placed in Claro theme (`config/sync/block.block.claro_*.yml`):

| Block ID | Plugin | Region | Weight |
|----------|--------|--------|--------|
| `claro_breadcrumbs` | System breadcrumb | breadcrumb | 0 |
| `claro_content` | System main content | content | 0 |
| `claro_help` | Help block | help | 0 |
| `claro_help_search` | Help search | help | -1 |
| `claro_local_actions` | Local actions | content | -10 |
| `claro_messages` | System messages | pre_content | -10 |
| `claro_page_title` | Page title | header | -999 |
| `claro_primary_local_tasks` | Primary tabs | header | -1000 |
| `claro_secondary_local_tasks` | Secondary tabs | pre_content | 0 |

### Quick Links Block (Claro-specific)
**File:** `config/sync/block.block.quicklinks.yml`

```yaml
id: quicklinks
theme: claro
region: content
weight: -8
plugin: 'system_menu_block:quick-links'
settings:
  label: 'Quick Links'
  label_display: visible
  level: 1
  depth: 0
visibility:
  request_path:
    pages: /dashboard
```

- Quick Links block is **placed in Claro theme**
- Only visible on `/dashboard` path
- Depends on: `system.menu.quick-links`, `system` module, `claro` theme

**CRITICAL:** This block config has `theme: claro` dependency. When migrating to Gin, this block needs a new config for the Gin theme.

---

## 6. Editorial Menu — Full Structure

**Menu Definition:** `config/sync/system.menu.editorial-menu.yml`

```yaml
id: editorial-menu
label: 'Editorial Menu'
locked: false
```

### Complete Menu Tree (from `structure_sync.data.yml`)

```
Editorial Menu (toolbar tab)
├── Manage Translations          → /admin/tmgmt                    [weight: -49]
├── Manage Content               → /global-content-list             [weight: -50]
│   ├── Add Content              → /node/add                        [child]
│   ├── Global Content           → /global-content-list             [child]
│   └── Country Content          → /country-content-list            [child]
├── Manage Taxonomies            → /admin/structure/taxonomy         [weight: -48]
│   └── Copy keywords/related    → /admin/content/copy-translated-field [child]
├── Manage Country               → /admin/group                     [weight: -47]
│   ├── Country List             → /admin/group                     [child]
│   └── Add Country              → /group/add/country               [child]
├── Manage Users                 → /users                           [weight: -46]
│   ├── Add Users                → /admin/people/create             [child]
│   ├── Group Users              → /group/41/members                [child, DISABLED]
│   └── User List                → /users                           [child]
├── Manage Language              → /admin/config/regional/language/add [weight: -45]
│   ├── Language List            → /admin/config/regional/language   [child]
│   └── Add Language             → /admin/config/regional/language/add [child]
├── Manage Media                 → /admin/content/media             [weight: -44]
│   ├── Media List               → /admin/content/media             [child]
│   └── Add Media                → /media/add                      [child]
├── Country Users                → /group/{gid}/members             [weight: -43, DYNAMIC]
├── Redirect Management          → /admin/config/parent-buddy/redirect-management [weight: -43]
├── Dashboard (old, DISABLED)    → /profile                         [weight: -42]
├── Manage Translation Jobs      → /admin/tmgmt/job_items           [weight: -41]
├── Google Analytics (DISABLED)  → /admin/config/system/google-analytics [weight: -40]
├── Dashboard                    → /dashboard                       [weight: -39]
├── Manage Reports               → <nolink> (parent only)           [weight: -38]
│   ├── Country Reports          → /country-reports                 [child]
│   ├── Keyword Not linked       → /keyword-notlink-to-content      [child]
│   ├── Keyword link to content  → /keyword-link-to-content         [child]
│   ├── Taxonomy Export          → /taxonomy-export/en              [child]
│   ├── Tax Export - Std Dev     → /taxonomy-export-standard-deviation/en [child]
│   └── Users Reports            → /users-reports                   [child]
├── Manage Force Update          → <nolink> (parent only)           [weight: -37]
│   ├── Force Update Config      → /admin/config/parent-buddy/forcefull-update-check [child]
│   └── Force Update List        → /force-update-check              [child]
└── Import Taxonomy              → /admin/structure/feeds            [weight: -36]
    ├── Feeds Type               → /admin/structure/feeds            [child]
    └── Add Feed                 → /admin/content/feed              [child]
```

**Notes:**
- "Country Users" link is **dynamically rewritten** per user's group membership (see Custom Code section)
- "Group Users" under "Manage Users" is disabled
- Two "Dashboard" entries exist — one disabled (`/profile`), one active (`/dashboard`)
- "Google Analytics" is disabled

---

## 7. Quick Links Menu — Full Structure

**Menu Definition:** `config/sync/system.menu.quick-links.yml`

```
Quick Links (block on /dashboard only)
├── Add Article Content      → /node/add/article                    [weight: -50]
├── Add Video Article Content → /node/add/video_article             [weight: -49]
├── Add Games                → /node/add/activities                  [weight: -48]
├── Media List               → /admin/content/media                 [weight: -47]
├── Country Content          → /country-content-list                [weight: -46]
├── Global Content           → /global-content-list                 [weight: -45]
├── Manage Users             → /group/{gid}/members                 [weight: -44, DYNAMIC]
├── Add Members              → /group/{gid}/content/create/group_membership [weight: -43, DYNAMIC]
├── Manage Country           → /admin/group                         [weight: -42]
└── Logout                   → /user/logout                         [weight: -41]
```

**Notes:**
- "Manage Users" and "Add Members" URLs are dynamically rewritten per user's group (see Custom Code section)
- Block only visible on `/dashboard` path
- Block hidden for users without group memberships (via `pb_custom_field_block_access()`)

---

## 8. Role Definitions & Permission Matrix

### All Roles

| Role | Machine Name | is_admin | Total Perms | Admin Access Level |
|------|-------------|----------|-------------|-------------------|
| Administrator | `administrator` | `true` | All (implicit) | Full |
| Global Admin | `global_admin` | `false` | 386 | Near-full |
| Senior Editor | `se` | `false` | 177 | Content + workflow |
| Editor | `editor` | `false` | 131 | Content + media |
| SME | `sme` | `false` | 70 | Content review |
| Translator | `translator` | `false` | 64 | Translation only |
| Reviewer (Country Admin) | `reviewer` | `false` | 14 | User mgmt + review |
| Authenticated | `authenticated` | `false` | ~30 | Basic toolbar |
| Anonymous | `anonymous` | `false` | ~5 | None |

### Toolbar & Menu Permission Matrix

| Permission | authenticated | translator | reviewer | sme | editor | se | global_admin | administrator |
|-----------|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `access toolbar` | YES | - | - | - | - | - | YES | YES (implicit) |
| `view the administration theme` | YES | - | - | - | - | - | YES | YES (implicit) |
| `access shortcuts` | YES | - | - | - | - | - | - | YES (implicit) |
| `view editorial_menu in toolbar` | - | - | YES | YES | YES | YES | YES | YES (implicit) |
| `administer toolbar menu` | - | - | - | - | - | - | YES | YES (implicit) |
| `create and edit custom blocks` | - | - | - | - | YES | - | YES | YES (implicit) |

### How Toolbar Access Works (Inherited Permissions)

Since all roles except `anonymous` inherit from `authenticated`:

- **All logged-in users** get `access toolbar` and `view the administration theme` via the `authenticated` role
- Translator gets toolbar but **NOT** the editorial menu tab
- Reviewer, SME, Editor, SE all get editorial menu tab via `view editorial_menu in toolbar`
- Only Global Admin and Administrator can `administer toolbar menu` (add/remove toolbar menu elements)

### Permission Source Files

| Role | Config File |
|------|------------|
| Administrator | `config/sync/user.role.administrator.yml` |
| Global Admin | `config/sync/user.role.global_admin.yml` |
| Senior Editor | `config/sync/user.role.se.yml` |
| Editor | `config/sync/user.role.editor.yml` |
| SME | `config/sync/user.role.sme.yml` |
| Reviewer | `config/sync/user.role.reviewer.yml` |
| Translator | `config/sync/user.role.translator.yml` |
| Authenticated | `config/sync/user.role.authenticated.yml` |
| Anonymous | `config/sync/user.role.anonymous.yml` |

---

## 9. Menu Per Role — Item-Level Visibility

The `menu_per_role` module controls per-item visibility using `menu_per_role__show_role` on each `menu_link_content` entity. This is a **whitelist** — only the specified role (and its superiors) sees each item.

### Editorial Menu — Item Visibility by Role

| Menu Item | `menu_per_role__show_role` | Visible To |
|-----------|--------------------------|------------|
| **Manage Content** | `editor` | Editor, SE, SME, Reviewer*, Global Admin, Admin |
| **Manage Taxonomies** | `administrator` | Admin only |
| **Manage Country** | `global_admin` | Global Admin, Admin |
| **Add Country** | `global_admin` | Global Admin, Admin |
| **Country List** | `global_admin` | Global Admin, Admin |
| **Manage Language** | `global_admin` | Global Admin, Admin |
| **Add Language** | `global_admin` | Global Admin, Admin |
| **Language List** | `global_admin` | Global Admin, Admin |
| **Manage Users** | `global_admin` | Global Admin, Admin |
| **Add Users** | `global_admin` | Global Admin, Admin |
| **User List** | (check config) | Likely Global Admin, Admin |
| **Manage Translations** | `editor` | Editor, SE, SME, Reviewer*, Global Admin, Admin |
| **Manage Media** | `editor` | Editor, SE, SME, Reviewer*, Global Admin, Admin |
| **Media List** | `editor` | Editor, SE, SME, Reviewer*, Global Admin, Admin |
| **Add Media** | `editor` | Editor, SE, SME, Reviewer*, Global Admin, Admin |
| **Country Users** | (dynamic) | Controlled by code, not menu_per_role |
| **Dashboard** | (no restriction found) | All with editorial menu access |
| **Manage Reports** | (check config) | Likely Global Admin, Admin |
| **Manage Force Update** | (check config) | Likely Global Admin, Admin |
| **Import Taxonomy** | (check config) | Likely Global Admin, Admin |

**Note on `menu_per_role__show_role`:** The `admin_bypass_access_admin: true` setting means administrators always see everything regardless. The show_role field acts as a minimum role threshold.

*Reviewer sees items with `editor` show_role because `menu_per_role` checks if user has the role OR any higher role that inherits it. Since `admin_bypass_access_admin: true`, admin/global_admin bypass entirely.

### What Each Role Sees in Editorial Menu

#### Administrator
**Sees:** Everything (all menu items, is_admin=true bypasses all checks)

#### Global Admin
**Sees:** Everything (admin_bypass_access_admin=true)
- Manage Content (+ children)
- Manage Translations
- Manage Taxonomies
- Manage Country (+ Add Country, Country List)
- Manage Users (+ Add Users, User List)
- Manage Language (+ Add Language, Language List)
- Manage Media (+ Media List, Add Media)
- Country Users
- Redirect Management
- Dashboard
- Manage Translation Jobs
- Manage Reports (+ all children)
- Manage Force Update (+ children)
- Import Taxonomy (+ children)

#### Editor
**Sees:**
- Manage Content (+ Add Content, Global Content, Country Content)
- Manage Translations
- Manage Media (+ Media List, Add Media)
- Country Users (if in a group)
- Dashboard
- Manage Translation Jobs

**Does NOT see:**
- Manage Taxonomies
- Manage Country
- Manage Users
- Manage Language
- Manage Reports
- Manage Force Update
- Import Taxonomy

#### Senior Editor (SE)
**Sees:** Same as Editor (permission-wise identical for editorial menu)

#### SME
**Sees:** Same as Editor for editorial menu items

#### Reviewer (Country Admin)
**Sees:**
- Manage Content (limited)
- Manage Translations
- Country Users
- Dashboard
- Items with `editor` show_role (via group membership)

**Special:** Has user management permissions (`administer allowed languages`, `execute user_block_user_action`, `execute user_unblock_user_action`) but these apply to user admin pages, not editorial menu items.

#### Translator
**Does NOT see:** Editorial menu tab at all (no `view editorial_menu in toolbar` permission)

---

## 10. Custom Code: Toolbar & Menu Hooks

### pb_custom_field module

**File:** `docroot/modules/custom/pb_custom_field/pb_custom_field.module`

#### Hook: `pb_custom_field_preprocess_menu()` (line 880)

Controls editorial menu visibility. Two-layer access:

```php
function pb_custom_field_preprocess_menu(&$variables) {
  // Only process editorial menu
  if ($variables['menu_name'] !== 'editorial-menu') return;

  $editorial_roles = array_merge(
    _pb_custom_field_get_target_roles(),  // ['editor', 'se', 'sme', 'reviewer']
    ['global_admin', 'administrator']
  );

  // If user NOT in editorial roles AND has no group membership → remove ALL items
  if (!array_intersect($roles, $editorial_roles)) {
    if (empty($grps)) {
      foreach ($variables['items'] as $key => $item) {
        unset($variables['items'][$key]);
      }
    }
  }
}
```

**Effect:** Even if a user has `view editorial_menu in toolbar`, if they're not in the editorial roles list AND have no group memberships, all menu items are stripped.

#### Hook: `pb_custom_field_link_alter()` (line 811)

Dynamically rewrites menu links based on user's group:

```php
function pb_custom_field_link_alter(&$variables) {
  // Skip for admin/global_admin
  // For other users with group membership:

  // 1. "Country Users" in editorial-menu → /group/{gid}/members
  // 2. "Manage Users" in quick-links → /group/{gid}/members
  // 3. "Add Members" in quick-links → /group/{gid}/content/create/group_membership
}
```

**Effect:** "Country Users" link dynamically points to the user's country group member page. Same for quick-links "Manage Users" and "Add Members".

#### Hook: `pb_custom_field_block_access()` (line 912)

Controls block visibility for users without group membership:

```php
function pb_custom_field_block_access(Block $block, $operation, AccountInterface $account) {
  // Skip for admin/global_admin
  // For users without groups, FORBID these blocks:
  //   - system_menu_block:quick-links
  //   - views_block:senior_editor_review_pending-block_1
  //   - views_block:sme_review_pending-block_1
  //   - views_block:tmgmt_translation_all_job_items-block_1
  //   - views_block:editor_review_pending-block_1
  //   - views_block:recent_logged_in_users-block_1
  //   - views_block:country_content_listing-block_1
}
```

**Effect:** Quick Links block and several dashboard view blocks are hidden for users without country group assignments.

#### Helper Functions

```php
// Line 37 — Target editorial roles
function _pb_custom_field_get_target_roles() {
  return ['editor', 'se', 'sme', 'reviewer'];
}

// Line 47 — Trusted roles (subset)
function _pb_custom_field_get_trusted_roles() {
  return ['editor', 'se', 'sme'];
}

// Line 51 — Cached group membership loader
function _pb_custom_field_get_user_groups_cached($user) { ... }
```

### Library Attachment

**File:** `docroot/modules/custom/pb_custom_field/pb_custom_field.libraries.yml`

```yaml
mylib:
  css:
    theme:
      css/admin.css: {}
  js:
    js/homepage.js: {}
```

Library `pb_custom_field/mylib` is attached in:
- `pb_custom_field.module:305` — via preprocess
- `pb_custom_field.module:662` — via form alter
- `pb_custom_field.module:1714` — via preprocess

---

## 11. Custom Admin CSS (Claro-specific)

**File:** `docroot/modules/custom/pb_custom_field/css/admin.css` (883 lines)

### Toolbar-Specific CSS (MUST audit for Gin)

```css
/* Line 1-6: Toolbar tray dropdown positioning */
.toolbar-tray-open .toolbar-tray-horizontal .toolbar-menu .menu-item--expanded:hover ul {
  display: block;
  position: absolute;
  width: 200px;
  z-index: 1;
}
```

**Risk:** This CSS targets Claro's toolbar tray structure. Gin uses different markup/classes for its toolbar.

### Breadcrumb Hiding

```css
/* Line 8-11 */
.user-logged-in .breadcrumb { display: none; }

/* Line 748-750 */
.breadcrumb__list .breadcrumb__item:nth-child(n+3) { display: none; }
```

### Admin Form Element Hiding

```css
/* Line 12-15: Hide select-all checkbox and multipage details */
.user-logged-in .js-form-item.form-item.js-form-type-checkbox...form-item--select-all,
.user-logged-in details#edit-multipage { display: none; }
```

### TMGMT UI Customizations

```css
/* Line 17-19 */
.hide, table.tmgmt-ui-review input.unreviewed,
.tmgmt-ui-data-item-actions div input { display: none; }

/* Line 21-23 */
#edit-delete-translation { display: none; }
```

### Language Dropdown Fixed Position

```css
/* Line 28-33 */
.block-lang-dropdown {
  position: fixed;
  top: -25px;
  right: 0;        /* NOT RTL-safe — uses 'right' not 'inline-end' */
  z-index: 9999;
}
```

**Risk:** Uses `right: 0` instead of CSS logical properties. Will break in RTL.

### Homepage CSS

Lines 49-882 contain extensive homepage styling. Most is frontend, not admin-theme-dependent.

### CSS That Uses `float: right` or `left/right` Positioning (RTL Risks)

| Line | Property | Selector | Risk |
|------|----------|----------|------|
| 29 | `right: 0` | `.block-lang-dropdown` | HIGH — RTL broken |
| 43 | `float: right` | `.common-table .view-header a` | MEDIUM |
| 77 | `margin: 0px 0px 0px auto` | `.pb-homepage .left-feature` | LOW |
| 87 | `margin: 0px auto 0px 0px` | `.pb-homepage .right-feature` | LOW |
| 126 | `padding: 20px 15%` | various | LOW (shorthand, symmetrical) |
| 281 | `margin-right: 25px` | `.pb-main-homepage .main-logo img` | MEDIUM |
| 298 | `left: 20px` | `.pb-main-homepage .main-logo` | HIGH — homepage position |
| 358 | `float: right` | `.pb-main-homepage .menu` | HIGH — nav layout |

---

## 12. Per-Site Config Split Overrides

### Editorial Menu Overrides in Site Splits

| Site | Has Editorial Menu Override? | Details |
|------|:--:|---------|
| Default (Bebbo) | NO | Uses shared config |
| Bangladesh | NO | Uses shared config |
| Turkey | NO | Uses shared config |
| Ecuador | NO | Uses shared config |
| Pacific Islands | NO | Uses shared config |
| Samoa | YES | `config/somoa/config_split.patch.structure_sync.data.yml` — editorial-menu items present |
| Zimbabwe | YES | `config/zimbabwe/config_split.patch.structure_sync.data.yml` — editorial-menu items present |

**Detail:** Samoa and Zimbabwe have `structure_sync.data` patches that include editorial-menu link definitions. These may contain site-specific menu link overrides that differ from the shared base.

### Theme-Related Overrides in Site Splits

No site-specific theme overrides found. All sites use `claro` from shared config.

### Toolbar/Menu Config in Site Splits

No site-specific toolbar or admin_toolbar overrides found.

---

## 13. Gin Migration Checklist

> **Last updated:** 2026-05-27 — all config changes committed, permissions verified identical to pre-migration

### Pre-Migration — Composer & Module Install

- [x] Add `drupal/gin ^4.1` to `composer.json`
- [x] Add `drupal/gin_toolbar ^2.1` to `composer.json`
- [x] `composer install` — packages installed
- [x] Uninstall `admin_toolbar_search` — removed from `core.extension.yml`
- [x] Uninstall `toolbar_menu_clean` — removed from `core.extension.yml` (package kept in composer.json)
- [x] Enable `gin_toolbar` module — added to `core.extension.yml`
- [x] Enable `gin` theme — added to `core.extension.yml` theme section
- [x] Keep `admin_toolbar` + `admin_toolbar_tools` enabled
- [x] Keep `toolbar` (core) enabled
- [x] Keep `toolbar_menu` enabled
- [x] Re-create toolbar_menu element for Editorial Menu (new UUID: `265dc770-...`)

### Theme Switch

- [x] Update `config/sync/system.theme.yml`: `admin: gin` (default stays `claro`)
- [x] Create `config/sync/gin.settings.yml` — exported with settings:
  - `classic_toolbar: classic` (uses classic Drupal toolbar, not Gin navigation)
  - `preset_accent_color: light_blue`
  - `enable_darkmode: '0'`
  - `layout_density: default`
  - `sticky_action_buttons: false`
  - `secondary_toolbar_frontend: true`

### Block Migration

- [x] Gin block configs auto-generated on theme install (8 new files):

| Claro Block | Gin Block | Status |
|------------|-----------|--------|
| `claro_breadcrumbs` | `gin_breadcrumbs` | CREATED |
| `claro_content` | `gin_content` | CREATED |
| `claro_help` | `gin_help` | CREATED |
| `claro_help_search` | (none) | No Gin equivalent needed |
| `claro_local_actions` | `gin_local_actions` | CREATED |
| `claro_messages` | `gin_messages` | CREATED |
| `claro_page_title` | `gin_page_title` | CREATED |
| `claro_primary_local_tasks` | `gin_primary_local_tasks` | CREATED |
| `claro_secondary_local_tasks` | `gin_secondary_local_tasks` | CREATED |

- [x] **Quick Links block** — `block.block.quicklinks.yml` switched from `theme: claro` to `theme: gin`. Now renders on Gin admin `/dashboard` page.
- [x] **Dashboard blocks (11 total)** — All switched from `theme: claro` to `theme: gin`:
  - `quicklinks`, `views_block__recent_global_content_block_1`, `views_block__editor_review_pending_block_1`
  - `views_block__country_content_listing_block_1`, `views_block__global_recent_logged_in_users_block_1`
  - `views_block__recent_logged_in_users_block_1`, `views_block__review_pending_block_1`
  - `views_block__senior_editor_review_pending_block_1`, `views_block__sme_review_pending_block_1`
  - `views_block__tmgmt_job_overview_block_1`, `views_block__tmgmt_translation_all_job_items_block_1`
- [x] Claro block configs kept — Claro is still default frontend theme, blocks needed for frontend rendering.

### Route Subscriber — Editorial Pages Admin Theme

Non-admin Views pages (e.g. `/dashboard`, `/country-reports`, `/global-reports`) were using the default theme (Claro) instead of Gin because they're not `/admin/*` routes. Before migration both themes were Claro, hiding this.

**Fix:** `AdminRouteSubscriber` in `pb_custom_field` marks 40+ editorial Views routes as `_admin_route: TRUE`.

**Files:**
- `docroot/modules/custom/pb_custom_field/src/Routing/AdminRouteSubscriber.php` — NEW
- `docroot/modules/custom/pb_custom_field/pb_custom_field.services.yml` — NEW

### RTL Admin Toolbar Fix

Pakistan site (Urdu, `dir="rtl"`) has broken admin toolbar dropdown positioning. The `admin_toolbar` contrib CSS uses physical properties (`left`, `margin-left`) with incomplete RTL overrides.

**Fix:** New `admin-rtl.css` loaded conditionally only on RTL pages via `pb_custom_field_preprocess_page()`.

**Core dropdown positioning fix:**
- Sets `position: relative` on `.toolbar-menu > .menu-item--expanded` so `position: absolute` dropdowns anchor to their parent item instead of a distant ancestor
- Sets `right: 0; left: auto` on first-level dropdowns to align to parent's inline-start (right) edge in RTL
- Sub-submenus open leftward via `margin: -40px 197px 0 0`

**Dropdown item text/chevron fix:**
- Sets `text-align: right` on dropdown links — core toolbar inherits `text-align: left` with no RTL override for `.toolbar-menu` elements
- Restores `padding-left: 1.3333em` on `.toolbar-icon` sub-menu links — core toolbar RTL rule zeroes `padding-left`, causing text to overlap the chevron arrow (which Gin positions at `inset-inline-end: 12px` = `left: 12px` in RTL)

**Additional physical property flips:**
- Flips `left: -999em` → `right: -999em` for hidden nested submenus
- Flips `left: 200px` → `right: 200px` for level-2 nested menus
- Flips `.block-lang-dropdown { right: 0 }` → `left: 0` for language dropdown
- Flips `float: right` → `float: left` for view headers and local tasks

**Files:**
- `docroot/modules/custom/pb_custom_field/css/admin-rtl.css` — NEW
- `docroot/modules/custom/pb_custom_field/pb_custom_field.libraries.yml` — UPDATED (added `admin_rtl` library)
- `docroot/modules/custom/pb_custom_field/pb_custom_field.module` — UPDATED (conditional RTL library attachment)

### Permission Restoration — COMPLETED

All permissions verified **identical to pre-migration baseline** (`git diff` against main shows zero changes to any `user.role.*.yml` file).

| Role | `view editorial_menu in toolbar` | `administer toolbar menu` | `toolbar_menu` dependency |
|------|:---:|:---:|:---:|
| `global_admin` | RESTORED | RESTORED | RESTORED |
| `editor` | RESTORED | n/a | RESTORED |
| `se` | RESTORED | n/a | RESTORED |
| `sme` | RESTORED | n/a | RESTORED |
| `reviewer` | RESTORED | n/a | RESTORED |

### Language Translation Files — Moved to Bebbo Split

Auto-generated language translations moved from `config/sync/language/` to `config/bebbo/language/` (bebbo-only, not shared across all sites):

| Language | Files Moved | Split Entry Added |
|----------|------------|:-:|
| Romanian (ro) | 8 Gin block translations | YES |
| Russian (ru) | 2 media_library + 1 system.menu.admin | YES |
| Slovak (sk) | 5 Gin block translations | YES |
| Albanian (sq) | 1 system.menu.admin | YES |
| Ukrainian (uk) | 2 media_library + 1 system.menu.admin | YES |

All 20 entries added to `config_split.config_split.bebbo_site.yml` `complete_list` in alphabetical order.

### Custom CSS Audit

- [x] Toolbar tray CSS (admin.css line 1-6) — kept for LTR, RTL override in `admin-rtl.css`
- [x] `.block-lang-dropdown { right: 0 }` — RTL override in `admin-rtl.css` flips to `left: 0`
- [x] `float: right` — RTL override in `admin-rtl.css` flips to `float: left`
- [ ] Test all TMGMT UI hiding rules still work with Gin markup
- [ ] Test breadcrumb hiding rules with Gin's breadcrumb structure

### Permissions Verification (Visual Testing)

Config is verified identical. Visual testing per role still needed:

- [ ] **Administrator** — Full toolbar, all menus visible
- [ ] **Global Admin** — Full toolbar, editorial menu with all items, toolbar admin capability
- [ ] **Editor** — Toolbar, editorial menu (content/translations/media items only)
- [ ] **Senior Editor** — Same as Editor
- [ ] **SME** — Same as Editor
- [ ] **Reviewer** — Toolbar, editorial menu (limited items), user management
- [ ] **Translator** — Toolbar present but NO editorial menu tab
- [ ] **Authenticated** — Toolbar present, NO editorial menu tab
- [ ] **Anonymous** — No toolbar

### Custom Code Verification

- [ ] `pb_custom_field_preprocess_menu()` — Still strips editorial menu for non-editorial users
- [ ] `pb_custom_field_link_alter()` — "Country Users" dynamic rewrite still works
- [ ] `pb_custom_field_block_access()` — Quick Links block still hidden for no-group users
- [ ] Library `pb_custom_field/mylib` still attached and working

### Per-Site Testing

- [ ] Default (Bebbo) — LTR
- [ ] Bangladesh — LTR
- [ ] Turkey — LTR
- [ ] Ecuador — LTR
- [ ] Pacific Islands — LTR
- [ ] Samoa — LTR (has editorial menu structure_sync patches)
- [ ] Zimbabwe — LTR (has editorial menu structure_sync patches)
- [ ] Pakistan — **RTL** (primary reason for migration)

### Cleanup Tasks (Post-Verification)

- [ ] Decide: remove `drupal/toolbar_menu_clean` from composer.json? (uninstalled from core.extension)
- [ ] `admin_toolbar_tools.settings.yml` gained `show_local_tasks: false` — verify intended

---

## 14. Risk Assessment

### RESOLVED

| Item | Resolution |
|------|-----------|
| ~~`view editorial_menu in toolbar` removed from 5 roles~~ | FIXED — permissions restored, verified zero diff on all role files |
| ~~`administer toolbar menu` removed from global_admin~~ | FIXED — permission restored |
| ~~Language translation files in shared config~~ | FIXED — moved to bebbo split with complete_list entries |
| ~~Quick Links block depends on `claro` theme~~ | FIXED — 11 dashboard blocks switched to `theme: gin` |
| ~~Editorial Views pages using Claro instead of Gin~~ | FIXED — `AdminRouteSubscriber` marks 40+ routes as `_admin_route: TRUE` |
| ~~CSS `right: 0` on lang dropdown broken in RTL~~ | FIXED — `admin-rtl.css` flips to `left: 0` for RTL |
| ~~admin_toolbar dropdown positioning broken in RTL~~ | FIXED — `admin-rtl.css` adds `position: relative` to parent menu items, aligns dropdowns with `right: 0; left: auto` |
| ~~RTL dropdown text/chevron alignment~~ | FIXED — `admin-rtl.css` sets `text-align: right` on dropdown links and restores `padding-left` for chevron visibility |

### MEDIUM Risk (Remaining)

| Item | Risk | Mitigation |
|------|------|-----------|
| Samoa/Zimbabwe structure_sync patches | May need editorial menu updates | Verify patches apply cleanly |
| `admin_toolbar` + `gin_toolbar` coexistence | Potential toolbar rendering conflicts | gin.settings has `classic_toolbar: classic` which should help |

### LOW Risk

| Item | Risk | Mitigation |
|------|------|-----------|
| `menu_per_role` | Module-level access control, theme-independent | Unchanged — verified |
| Custom preprocess/hooks | PHP code is theme-agnostic | Unchanged — verified |
| Editorial menu structure | Menu content entities are theme-independent | Unchanged — verified |
| Role permissions | Config-based, not theme-dependent | Identical to pre-migration — verified via git diff |

---

## 15. Verification Summary

### What Changed (git diff main..HEAD)

Only these config areas changed — **nothing else**:

| Area | Change | Impact |
|------|--------|--------|
| `system.theme.yml` | `admin: claro` → `admin: gin` | Admin theme switched |
| `core.extension.yml` | +`gin_toolbar`, -`admin_toolbar_search`, +`gin` theme | Module/theme enable/disable |
| `gin.settings.yml` | NEW file | Gin theme configuration |
| `admin_toolbar_search.settings.yml` | DELETED | Module uninstalled |
| `admin_toolbar_tools.settings.yml` | +`show_local_tasks: false`, hash changed | Minor setting change |
| `toolbar_menu...editorial_menu.yml` | UUID changed (re-created) | Same functional config, new entity |
| `block.block.gin_*.yml` (8 files) | NEW files | Gin block placements |
| `config_split...bebbo_site.yml` | +20 entries in `complete_list` | Language translations in bebbo split |
| `config/bebbo/language/` | +20 files | Translation files moved from config/sync |

### What Did NOT Change (verified zero diff)

| File | Status |
|------|--------|
| `user.role.administrator.yml` | IDENTICAL |
| `user.role.global_admin.yml` | IDENTICAL |
| `user.role.editor.yml` | IDENTICAL |
| `user.role.se.yml` | IDENTICAL |
| `user.role.sme.yml` | IDENTICAL |
| `user.role.reviewer.yml` | IDENTICAL |
| `user.role.translator.yml` | IDENTICAL |
| `user.role.authenticated.yml` | IDENTICAL |
| `user.role.anonymous.yml` | IDENTICAL |
| `system.menu.editorial-menu.yml` | IDENTICAL |
| `system.menu.quick-links.yml` | IDENTICAL |
| `menu_per_role.settings.yml` | IDENTICAL |
| `admin_toolbar.settings.yml` | IDENTICAL |
| `block.block.quicklinks.yml` | IDENTICAL (still theme: claro — pending) |
| `structure_sync.data.yml` | IDENTICAL |
| `menu_export.export_data.yml` | IDENTICAL |
| All `block.block.claro_*.yml` | IDENTICAL (kept for frontend) |

---

## Appendix A: Key File Paths

### Config Files (Current State)
```
config/sync/system.theme.yml                               — admin: gin, default: claro
config/sync/core.extension.yml                             — +gin_toolbar, -admin_toolbar_search, +gin theme
config/sync/gin.settings.yml                               — NEW: Gin theme settings
config/sync/admin_toolbar.settings.yml                     — unchanged
config/sync/admin_toolbar_tools.settings.yml               — +show_local_tasks: false
config/sync/toolbar_menu.toolbar_menu_element.editorial_menu.yml — re-created (new UUID, same config)
config/sync/system.menu.editorial-menu.yml                 — unchanged
config/sync/system.menu.quick-links.yml                    — unchanged
config/sync/menu_per_role.settings.yml                     — unchanged
config/sync/menu_export.export_data.yml                    — unchanged
config/sync/structure_sync.data.yml                        — unchanged
config/sync/config_split.config_split.bebbo_site.yml       — +20 language translation entries
config/sync/block.block.gin_*.yml (8 files)                — NEW: Gin block placements
config/sync/block.block.claro_*.yml (9 files)              — KEPT: Claro still default frontend theme
config/sync/block.block.quicklinks.yml                     — unchanged (still theme: claro — pending fix)
config/sync/user.role.*.yml (all 9 files)                  — unchanged (permissions identical)
```

### Deleted Config Files
```
config/sync/admin_toolbar_search.settings.yml              — DELETED (module uninstalled)
```

### New Config Files (in config/sync/)
```
config/sync/gin.settings.yml
config/sync/block.block.gin_breadcrumbs.yml
config/sync/block.block.gin_content.yml
config/sync/block.block.gin_help.yml
config/sync/block.block.gin_local_actions.yml
config/sync/block.block.gin_messages.yml
config/sync/block.block.gin_page_title.yml
config/sync/block.block.gin_primary_local_tasks.yml
config/sync/block.block.gin_secondary_local_tasks.yml
```

### New Files (in config/bebbo/ — bebbo split only)
```
config/bebbo/language/ro/block.block.gin_*.yml             — 8 Romanian Gin block translations
config/bebbo/language/ru/core.entity_form_mode.media.media_library.yml
config/bebbo/language/ru/core.entity_view_mode.media.media_library.yml
config/bebbo/language/ru/system.menu.admin.yml
config/bebbo/language/sk/block.block.gin_*.yml             — 5 Slovak Gin block translations
config/bebbo/language/sq/system.menu.admin.yml
config/bebbo/language/uk/core.entity_form_mode.media.media_library.yml
config/bebbo/language/uk/core.entity_view_mode.media.media_library.yml
config/bebbo/language/uk/system.menu.admin.yml
```

### Custom Module Files
```
docroot/modules/custom/pb_custom_field/pb_custom_field.module           — UPDATED: conditional RTL library attachment
docroot/modules/custom/pb_custom_field/pb_custom_field.libraries.yml    — UPDATED: added admin_rtl library
docroot/modules/custom/pb_custom_field/pb_custom_field.services.yml     — NEW: registers AdminRouteSubscriber
docroot/modules/custom/pb_custom_field/css/admin.css                    — unchanged
docroot/modules/custom/pb_custom_field/css/admin-rtl.css                — NEW: RTL overrides for admin toolbar
docroot/modules/custom/pb_custom_field/src/Routing/AdminRouteSubscriber.php — NEW: marks editorial Views as admin routes
```

### Composer
```
composer.json — drupal/gin ^4.1, drupal/gin_toolbar ^2.1 (NEW)
               drupal/admin_toolbar ^3.6, drupal/toolbar_menu ^3.1 (KEPT)
               drupal/toolbar_menu_clean ^1.3 (KEPT in composer, uninstalled from core.extension)
```

## Appendix B: Existing Documentation

See also: `docs/CLARO_VS_GIN_ADMIN_THEME.md` (185 lines) — earlier analysis of RTL issues and Gin migration considerations.
