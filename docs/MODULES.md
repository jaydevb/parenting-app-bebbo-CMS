# Bebbo Custom Modules Reference

> **Audience:** backend maintainers, code reviewers, onboarding developers.
> **Scope:** all 15 custom modules in `docroot/modules/custom/`, their purpose, hooks, services, routes, database tables, and interdependencies.
> **Verified against:** repository `HEAD` (branch `feature/group3-manage-users`). Every hook, class, service, and route below was confirmed in source.

---

## Module Index

| # | Module | Package | Purpose | Complexity |
|---|--------|---------|---------|------------|
| 1 | [`bebbo_api_security`](#1-bebbo_api_security) | Security | Device attestation, JWT auth for V2 API | High |
| 2 | [`bebbo_serializer`](#2-bebbo_serializer) | Serialization | V2 REST API serialization, response envelopes, ETag | High |
| 3 | [`custom_serialization`](#3-custom_serialization) | Serialization | V1 REST API serialization, media/field transforms | High |
| 4 | [`pb_custom_field`](#4-pb_custom_field) | Editorial | Field access, form alterations, editorial workflow, group membership | High |
| 5 | [`pb_custom_form`](#5-pb_custom_form) | Admin | Admin config forms, force-update API table, mobile share, CSV export | High |
| 6 | [`pb_content_analytics`](#6-pb_content_analytics) | Analytics | BigQuery sync for read/like counts, analytics reports | Medium |
| 7 | [`group_country_field`](#7-group_country_field) | Groups | Views query alteration for group-scoped content, TMGMT form control | Medium |
| 8 | [`language_visibility_control`](#8-language_visibility_control) | Language | Per-group mobile language visibility, API response filtering | Medium |
| 9 | [`language_custom_field`](#9-language_custom_field) | Language | Custom fields on language entities (locale, luxon, plural) | Low |
| 10 | [`custom_article`](#10-custom_article) | Content | Batch keyword lowercasing, cross-translation field copy, Drush utilities | Low |
| 11 | [`file_sanitizer`](#11-file_sanitizer) | Media | Filename sanitization on upload, MIME mismatch scanning | Low |
| 12 | [`pb_custom_rest_api`](#12-pb_custom_rest_api) | API | Force-update check REST resource (`/api/check-update/{country}`) | Low |
| 13 | [`pb_custom_standard_deviation`](#13-pb_custom_standard_deviation) | API | V1 standard deviation Views style plugin | Low |
| 14 | [`pb_custom_migrate`](#14-pb_custom_migrate) | Migration | 207 CSV-based content migration configs | Low |
| 15 | [`pb_strings`](#15-pb_strings) | Content | Strings taxonomy management, unique name enforcement, bulk translate UI | Low |

---

## 1. `bebbo_api_security`

**Name:** Bebbo API Security
**Core:** `^10 || ^11`
**Dependencies:** `drupal:key`, `drupal:views`, `view_custom_table:view_custom_table`
**Full documentation:** [`API_SECURITY.md`](API_SECURITY.md)

### Purpose

Device authentication for the V2 content API. Apps prove authenticity via platform attestation (Apple App Attest / Google Play Integrity) or sideloaded challenge-response, then receive JWT access tokens for protected endpoints.

### Services

| Service ID | Class | Role |
|-----------|-------|------|
| `bebbo_api_security.device_registry` | `DeviceRegistryService` | DB CRUD for devices, tokens, challenges; cron purge |
| `bebbo_api_security.jwt_service` | `JwtService` | RS256 JWT create/validate; refresh token rotation with replay detection |
| `bebbo_api_security.google_play_integrity` | `GooglePlayIntegrityService` | Android Play Integrity verdict verification |
| `bebbo_api_security.apple_app_attest` | `AppleAppAttestService` | iOS App Attest offline verification (CBOR + OpenSSL) |
| `bebbo_api_security.sideloaded_verification` | `SideloadedVerificationService` | EC P-256 challenge-response for sideloaded builds |
| `bebbo_api_security.request_subscriber` | `ApiSecuritySubscriber` | `KernelEvents::REQUEST` (priority 300) — JWT enforcement on protected paths |

### Routes

| Path | Method | Access | Handler |
|------|--------|--------|---------|
| `/api/security/register` | POST | public | `SecurityController::register` |
| `/api/security/device/register` | POST | public | `SecurityController::deviceRegister` |
| `/api/security/device/verify` | POST | public | `SecurityController::deviceVerify` |
| `/api/security/refresh` | POST | public | `SecurityController::refresh` |
| `/api/security/revoke` | POST | public (Bearer required in handler) | `SecurityController::revoke` |
| `/admin/config/parent-buddy/api-security` | GET/POST | `administer bebbo api security` | `ApiSecuritySettingsForm` |

### Hooks

| Hook | Behavior |
|------|----------|
| `hook_cron` | Purges expired challenges, revoked/expired refresh tokens, trims security log |

### Database Tables

`bebbo_api_devices`, `bebbo_api_refresh_tokens`, `bebbo_api_challenges`, `bebbo_api_security_log` — schema details in [`API_SECURITY.md` §8](API_SECURITY.md#8-data-model).

### Permissions

- `administer bebbo api security` (restrict access: true)

### Update Hooks

- `10001`: Installs admin Views (devices, tokens, challenges, security log)

### Tests

3 Unit + 5 Kernel test classes in `tests/src/`.

---

## 2. `bebbo_serializer`

**Name:** Bebbo Serializer
**Core:** `^10 || ^11`
**Dependencies:** `drupal:rest`, `drupal:serialization`, `language_visibility_control:language_visibility_control`

### Purpose

V2 REST API serialization. Provides the `bebbo_serializer` Views style plugin that transforms Views REST export rows into the Bebbo JSON envelope (`{status, total, langcode, datetime, data}`), the `bebbo_json` encoder, ETag support, and presave logic for computed fields.

### Services

| Service ID | Class | Role |
|-----------|-------|------|
| `bebbo_serializer.encoder.bebbo_json` | `BebboEncoder` | JSON encoder with `UNESCAPED_SLASHES \| UNESCAPED_UNICODE` |
| `bebbo_serializer.request_format_subscriber` | `RequestFormatSubscriber` | `KernelEvents::REQUEST` (priority 1000) — registers `bebbo_json` MIME type |
| `bebbo_serializer.etag_response_subscriber` | `EtagResponseSubscriber` | `KernelEvents::RESPONSE` (priority 0) — ETag/304 support |
| `bebbo_serializer.body_image_processor` | `BodyImageProcessor` | Renders body HTML, extracts image URLs, converts to WebP |

### Hooks

| Hook | Behavior |
|------|----------|
| `hook_node_presave` | Populates `field_number_of_modules` (course), `field_number_of_questions` (quiz), truncates height/weight decimals (pregnancy_weekly_overview), auto-populates `field_embedded_images` and `field_body_rendered` for published nodes |
| `hook_node_predelete` | Deletes orphaned quiz_questions nodes when quiz node deleted |
| `hook_views_query_alter` | Adds Pregnancy term to child_age filter when `?pregnancy=true` on articles endpoint |
| `hook_form_alter` | Makes module/question count fields readonly on course/quiz forms; validates course expiry, passing score, question count |
| `hook_inline_entity_form_translation_restrict_alter` | Allows adding new course modules on translation forms |
| `hook_inline_entity_form_entity_form_alter` | Renames IEF "Add another item" → "Add another answer" on quiz forms |

### Key Classes

| Class | Type | Role |
|-------|------|------|
| `BebboSerializer` | Views style plugin | 20+ `transformX()` methods for per-endpoint row transformation |
| `BebboEncoder` | Encoder | `bebbo_json` format encoding |
| `BodyImageProcessor` | Service | Presave body HTML → image URL extraction |
| `QuizAnswerItem` | Field type | Custom compound field (value + is_correct) |
| `QuizAnswerFormatter` | Field formatter | Displays quiz answers with correct indicator |
| `QuizAnswerWidget` | Field widget | Form widget for quiz answers |

### Drush Commands

| Command class | Purpose |
|---------------|---------|
| `EmbeddedImagesCommands` | Batch populate `field_embedded_images` from body |
| `BodyRenderedCommands` | Batch populate `field_body_rendered` |
| `FilePathsFixCommands` | Fix file path issues |
| `RemoveTrTranslationsCommands` | Remove Turkish translations |

### Update Hooks

- `10001`: Deletes orphaned paragraph and consumer entities
- `10002`: Migrates `field_content_toggle` value `ai_chatbot` → `chatbot`
- `10003`: Migrates `field_content_toggle` allowed values to machine names, sets canonical field storage config

---

## 3. `custom_serialization`

**Name:** PB API Serialization
**Core:** `^9.2 || ^10 || ^11`
**Dependencies:** `language_visibility_control:language_visibility_control`

### Purpose

V1 REST API serialization. Provides the `custom_serialization` Views style plugin for legacy `/api/*` endpoints and the `CustomSerializerHelper` service for batched entity loading, caching, and media resolution.

### Services

| Service ID | Class | Role |
|-----------|-------|------|
| `custom_serialization.helper` | `CustomSerializerHelper` | Batch entity loading, request-level caching, Vimeo API, WebP conversion |

### Key Classes

| Class | Type | Role |
|-------|------|------|
| `CustomSerializer` | Views style plugin | ~500 line `render()` with substring-dispatched per-endpoint transforms |
| `CustomSerializerHelper` | Service | Media/file/taxonomy batch loading with static + persistent caches |

### Caching in `CustomSerializerHelper`

| Cache | Scope | TTL |
|-------|-------|-----|
| Media, file, language, group entities | Request-level static | Per-request |
| Country groups, taxonomy terms | Request-level static | Per-request |
| Image style | Request-level static | Per-request |
| Vimeo thumbnails (success) | Persistent (`cache.default`) | 24h (86400s) |
| Vimeo thumbnails (failure) | Persistent (`cache.default`) | 5min (300s) |
| Pregnancy term ID | Persistent (`cache.default`) | Permanent (tag: `taxonomy_term_list:child_age`) |

### Batch Loading Methods

`loadMediaBatch`, `loadFileBatch`, `loadTaxonomyTermsBatch`, `loadConfigurableLanguagesBatch`, `loadGroupsBatch`, `getFileUrisBatch`, `getMediaAltTextBatch`, `getNodeTitlesBatch`, `getTaxonomyTermsBatch`, `getLanguageDataBatch` — all reduce N+1 queries to single DB calls.

---

## 4. `pb_custom_field`

**Name:** Custom Field
**Core:** `^9.2 || ^10 || ^11`

### Purpose

The largest editorial module. Controls field access, form alterations, editorial menu visibility, group membership workflows, content assignment actions, and admin route marking. Central to the multi-country editorial workflow.

### Services

| Service ID | Class | Role |
|-----------|-------|------|
| `pb_custom_field.admin_route_subscriber` | `AdminRouteSubscriber` | Marks 40+ Views/editorial routes as admin routes (for Gin theme) |
| `pb_custom_field.group_create_form_route_subscriber` | `GroupCreateFormRouteSubscriber` | Swaps group membership create controller for wizard |

### Hooks (23+)

| Hook | Behavior |
|------|----------|
| `hook_user_update` | Clears user caches on update |
| `hook_form_alter` | Language filters, field access control, validation on node/media forms |
| `hook_link_alter` | Alters editorial menu links for country users |
| `hook_preprocess_menu` | Filters editorial menu by user roles |
| `hook_preprocess_html` | Loads homepage styling, Kosovo-specific assets |
| `hook_preprocess_page` | Attaches mylib and admin_rtl libraries |
| `hook_block_access` | Restricts block visibility for non-admin users |
| `hook_views_pre_view` | View header customization, language filtering |
| `hook_views_pre_render` | Transforms force_update_check view data |
| `hook_views_pre_execute` | Column sorting conversions |
| `hook_views_query_alter` | Cross-country access, language filtering, TMGMT query mods |
| `hook_menu_local_tasks_alter` | Removes/displays tabs based on roles |
| `hook_menu_local_actions_alter` | Renames "Add member" → "Add existing member" |
| `hook_entity_operation_alter` | Controls edit/delete/translate by moderation state |
| `hook_entity_field_access` | Lets group admins set user status via group UI |
| `hook_user_login_form_submit` | Dashboard redirect after login — early-returns if user is anonymous (guards against redirect before TFA verification) |
| `hook_form_email_tfa_email_tfa_verify_login_alter` | Adds dashboard redirect submit handler to TFA OTP verification form, so user lands on `/dashboard` after successful MFA |
| `hook_form_user_login_form_alter` | Adds password reset link |
| `hook_form_user_form_alter` | Customizes user profile form |
| `hook_form_user_register_form_alter` | Group membership creation wizard, restricts role selection |
| `hook_form_user_admin_permissions_alter` | Attaches FPA Gin styling |
| `hook_content_moderation_notification_mail_data_alter` | Filters moderation emails by user language/opt-in |
| `hook_toolbar` | Adds admin UI language switcher dropdown |
| `hook_group_relation_type_alter` | Sets `entity_access=TRUE` on `group_membership` plugin |
| `hook_allowed_languages_form_media_library_*_alter` (×2) | Filters media library language dropdowns (oEmbed + upload forms) |

### Key Classes

| Class | Type | Role |
|-------|------|------|
| `GroupMembershipCreateController` | Controller | Extends `GroupRelationshipController`, uses "register" form operation (Group 3.x manage-users) |
| `BebboMembershipAccessControl` | Group relation handler | Guards uid:1 and own-account access (patch #2949408) |
| `AssigncontentStatus` | Batch helper | Batch assigns content to country languages as drafts |
| `AdminRouteSubscriber` | Route subscriber | Marks 40+ editorial routes as admin |

**VBO Action Plugins** (`src/Plugin/Action/`):

| Class | Role |
|-------|------|
| `AssigncontentAction` | Assigns content to country languages |
| `ChangedToArchiveAction` | Changes moderation state to Archive |
| `ChangedToPublishedAction` | Changes moderation state to Published |
| `ChangedToSeniorEditorAction` | Changes moderation state to Senior Editor review |
| `ChangeToSMEAction` | Changes moderation state to SME review |
| `MovefrompublishtodraftAction` | Moves from Published to Draft |
| `MovefrompublishtosenioreditorAction` | Moves from Published to Senior Editor |
| `MovefrompublishtosmeAction` | Moves from Published to SME |

**Batch Handler Classes** (`src/`):

`ChangeActionStatus`, `ChangeintoArchiveActionStatus`, `ChangeintoPublishActionStatus`, `ChangeintoSeniorEditorActionStatus`, `ChangeintoSMEActionStatus` — batch processing callbacks for the corresponding VBO actions.

### Helper Functions

- `_pb_custom_field_get_target_roles()` → `['editor', 'se', 'sme', 'reviewer']`
- `_pb_custom_field_get_trusted_roles()` → `['editor', 'se', 'sme']`
- `_pb_custom_field_get_user_groups_cached()` — Group memberships with 5min TTL cache
- `_pb_custom_field_is_multi_country_user()` — Checks multiple group memberships
- `_pb_custom_field_get_user_primary_group()` — First user's primary group
- `_pb_custom_field_get_user_group_languages()` — Language codes from primary group
- `_pb_custom_field_get_user_allowed_languages()` — From profile or groups

### Service Provider

`PbCustomFieldServiceProvider` — Decorates Group's membership access control with `BebboMembershipAccessControl`.

### Update Hooks

- `10003`: Migrates `field_content_toggle` keys from title-case to machine names

### Libraries

`mylib` (admin CSS), `admin_rtl` (RTL CSS), `toolbar_language_switcher` (JS/CSS), `homepage` (public landing pages), `homepage_grid` (Bootstrap grid), `fpa_gin_style` (Gin theme customizations)

### Tests

`MembershipEntityAccessTest` — Kernel test for group membership access control.

---

## 5. `pb_custom_form`

**Name:** Custom Form
**Core:** `^9.2 || ^10 || ^11`

### Purpose

Admin configuration forms (force-update, mobile share, app store redirect, redirect management), mobile share page rendering, Entity Share CSV export, TMGMT form/view integration, and the `forcefull_check_update_api` database table.

### Services

| Service ID | Class | Role |
|-----------|-------|------|
| `pb_custom_form.internal_content_node_redirect` | `InternalContentNodeRedirect` | Event subscriber — redirects internal node URLs by site context |

### Permissions

| Permission | Description |
|-----------|-------------|
| `manage mobile javascript` | Access mobile app share link JavaScript |
| `forcefull update check` | Access force update check API config |
| `manage redirect settings` | Access redirect management configuration |

### Routes

| Path | Handler | Permission |
|------|---------|------------|
| `/admin/config/parent_buddy` | Menu container | `administer site configuration` |
| `/admin/config/parent-buddy/forcefull-update-check` | `CustomForm` | `forcefull update check` |
| `/admin/config/parent-buddy/admin-parent-buddy` | `SettingsForm` | `administer site configuration` |
| `/admin/config/parent-buddy/mobile-javascript` | `MobileAppShareLinkForm` | `administer site configuration` |
| `/admin/config/parent-buddy/redirect-management` | `RedirectManagementForm` | `manage redirect settings` |
| `/admin/config/parent-buddy/app-store-redirect` | `AppStoreRedirectForm` | `administer site configuration` |
| `/admin/config/parent-buddy/apply-trans-related-articles-video` | `ApplyTransRelatedArticlesVideo` | `administer site configuration` |
| `/forcefull-update-check` | `ForceUpdateCheckForm` | `forcefull update check` |
| `/share/{param1}/{param2}/{param3}` | `PbMobile::render` | `manage mobile javascript` |
| `/foleja/share/{param1}/{param2}/{param3}` | `PbMobile::kosovorender` | `manage mobile javascript` |
| `/downloadapp.html` | `AppStoreRedirectController::render` | public |
| `/admin/content/entity_share/pull/export/csv` | `CsvExportController::download` | `entity_share_client_pull_content` |
| `/admin/content/entity_share/pull/export/csv/batch` | `CsvExportController::downloadBatch` | `entity_share_client_pull_content` |
| `/admin/content/entity_share/pull/export/csv/download` | `CsvExportController::downloadCompleted` | `entity_share_client_pull_content` |

### Hooks

| Hook | Behavior |
|------|----------|
| `hook_views_query_alter` | Filters articles by pregnancy status; Node ID filtering for TMGMT |
| `hook_theme` | Defines `pb-mobile` and `kosovo-mobile` theme templates |
| `hook_query_TAG_alter` | Custom filtering/sorting for TMGMT translatable entities |
| `hook_form_alter` | Article categorization AJAX, TMGMT modifications, Entity Share CSV button |
| `hook_views_post_execute` | Deduplicates pinned content in API responses |
| `hook_preprocess_page` | Ensures TMGMT local tasks visibility |
| `hook_menu_local_tasks_alter` | Adds TMGMT local tasks to source overview pages |
| `hook_form_tmgmt_overview_form_alter` | TMGMT overview sorting/filtering (form-specific alter) |

> **Dead code note:** `pb_custom_form_pb_custom_field_preprocess_views_view_field()` (`.module:670`) has an `Implements hook_preprocess_views_view_field()` docblock but its function name carries an extra `pb_custom_field` segment, so Drupal's hook system never invokes it. Effectively dead — not an active hook.

### Database Table

**`forcefull_check_update_api`** — stores force-update flags per country per update type. Schema details in [`API_REFERENCE.md` §9.1](API_REFERENCE.md#91-forcefull_check_update_api-table-schema).

### Key Classes

| Class | Type | Role |
|-------|------|------|
| `CsvExportController` | Controller | Batch CSV export from Entity Share remote data |
| `PbMobile` | Controller | Mobile share page + Kosovo variant |
| `AppStoreRedirectController` | Controller | QR code landing page for app download |
| `ChangeNodeStatus` | Batch processor | Archives nodes during country offload |
| `ApplyNodeTranslations` | Batch processor | Applies field values across node translations |
| 7 Form classes | Forms | Various admin config forms |

### Update Hooks

`70010` through `70018` — Mostly column migrations for the `forcefull_check_update_api` table (google_play_url, app_store_url, etc.). Exception: `70017` installs the `config_split` module.

---

## 6. `pb_content_analytics`

**Name:** Content Analytics
**Core:** `^9.2 || ^10 || ^11`
**Dependencies:** none declared in `.info.yml`

### Purpose

Syncs content engagement data (read counts, like counts) from Google BigQuery into Drupal node fields (`field_like_count`, `field_read_count`, `field_analytics_updated`). Provides admin reports and manual sync UI.

### Services

| Service ID | Class | Role |
|-----------|-------|------|
| `pb_content_analytics.sync_service` | `AnalyticsSyncService` | BigQuery API client, batch node field updates |
| `pb_content_analytics.feeds_import_subscriber` | `FeedsImportSubscriber` | Event subscriber for Feeds import events |

### Routes

| Path | Handler | Permission |
|------|---------|------------|
| `/admin/config/content-analytics/settings` | `ContentAnalyticsSettingsForm` | `administer content analytics settings` |
| `/admin/config/content-analytics/sync` | `ContentAnalyticsSyncForm` | `manage content analytics sync` |
| `/admin/config/content-analytics/sync/now` | `AnalyticsSyncController::syncNow` | `manage content analytics sync` |

### Permissions

| Permission | Description |
|-----------|-------------|
| `administer content analytics settings` | Configure analytics settings |
| `manage content analytics sync` | Trigger manual syncs |
| `view content analytics report` | View analytics reports |

### Hooks

| Hook | Behavior |
|------|----------|
| `hook_cron` | Auto-syncs analytics on schedule (configurable: daily/weekly) |
| `hook_form_alter` | Disables analytics fields on node forms (read-only); adds CSV export buttons to analytics views |
| `hook_views_pre_render` | Hides body fields in reports based on filter |
| `hook_node_view` | Displays analytics counts in node view |

### Database Table

**`pb_analytics_sync_log`** — tracks sync operations (timestamp, status, count, errors).

### Config

6 install configs including Views for analytics reports and Feeds import definitions.

### Update Hooks

- `9001`, `9002`: Schema/data migrations for analytics tables

> **Note:** The V2 API emits `read_count` and `love_count` — the storage field is `field_like_count` but the API key is `love_count`.

---

## 7. `group_country_field`

**Name:** Group Country Field
**Core:** `^9.2 || ^10 || ^11`

### Purpose

Group-based Views query alteration and TMGMT form control. Ensures Views filter by group language configuration, shows latest revisions for moderated content, and restricts TMGMT moderation options by role.

### Hooks

| Hook | Behavior |
|------|----------|
| `hook_form_alter` | Restricts TMGMT job item moderation state options by role (global_admin, editor, translator) |
| `hook_views_query_alter` | Filters views by group language (`field_language`); ensures latest revision per node/language for moderated group content; filters "recent logged in users" by group membership |
| `hook_views_pre_render` | Sets dynamic view title from group label |
| `hook_form_views_exposed_form_alter` | Populates "Updated by" filter with group members; removes locked system languages from language filters; renames date filter labels |

No services, routes, or database tables.

---

## 8. `language_visibility_control`

**Name:** Language Visibility Control
**Core:** `^10 || ^11`
**Dependencies:** `group:group`, `drupal:language`

### Purpose

Controls which languages appear in the mobile app API per country group. Adds a "Language Visibility in Mobile App" fieldset to country group forms and filters API responses accordingly.

### Services

| Service ID | Class | Role |
|-----------|-------|------|
| `language_visibility_control.service` | `LanguageVisibilityService` | Manages per-group language visibility; used by both serializers |
| `language_visibility_control.api_response_subscriber` | `ApiResponseSubscriber` | `KernelEvents::RESPONSE` (priority -10) — filters languages in V1 `/api/country-groups` only (V2 handles filtering in `BebboSerializer`) |

### Hooks

| Hook | Behavior |
|------|----------|
| `hook_form_alter` | Adds language visibility checkboxes to country group add/edit forms |
| `hook_help` | Module help text |
| Custom validate/submit | `language_visibility_control_group_form_validate`, `language_visibility_control_group_form_submit` |

### Install

Creates `field_language_visibility_in_app` (string field, unlimited cardinality, max_length 32) on group entities.

### Key Methods (`LanguageVisibilityService`)

- `getAllGroupLanguages(Group)` — All langcodes in `field_language`
- `getVisibleLanguages(Group)` — Langcodes in `field_language_visibility_in_app`
- `filterLanguageDataForApi(array, Group)` — Filters to visible languages (falls back to all group languages if no visibility config)
- `getLanguageVisibilityStats()` — Stats across all country groups

---

## 9. `language_custom_field`

**Name:** Language Custom Field
**Core:** `^10 || ^11`
**Dependencies:** `field:field`, `field:field_ui`

### Purpose

Adds custom fields to Drupal language entities: local name, locale code, Luxon locale, and plural configuration. Stored in a custom database table.

### Hooks

| Hook | Behavior |
|------|----------|
| `hook_form_alter` | Adds custom fields (custom_language_name_local, custom_locale, custom_luxon, custom_plural) to `language_admin_edit_form` |
| `hook_preprocess_node` | Adds `body_summary` to node template variables |
| `hook_node_view` | Adds `body_summary` to node view build |

### Database Table

**`custom_language_data`**

| Column | Type |
|--------|------|
| `id` | serial PK |
| `langcode` | varchar(12) |
| `custom_locale` | varchar(255) |
| `custom_luxon` | varchar(255) |
| `custom_plural` | varchar(255) |
| `custom_language_name_local` | varchar(255) |
| `created_date` | varchar(255), not null |

### Update Hooks

- `9001`: Adds `custom_language_name_local` column

---

## 10. `custom_article`

**Name:** Custom Article Update
**Core:** `^10 || ^11`

### Purpose

Utility module for batch content operations: lowercase keyword taxonomy terms, copy field values between translations, and Drush commands for content management.

### Routes

| Path | Handler | Permission |
|------|---------|------------|
| `/admin/content/copy-translated-field` | `CopyTranslatedField` | `administer site configuration` |
| `/admin/content/lowercase-keywords` | `LowercaseKeywordsForm` | `administer taxonomy` |

### Key Classes

| Class | Type | Role |
|-------|------|------|
| `LowercaseKeywordsForm` | Form | Batch lowercases keyword taxonomy terms |
| `CopyTranslatedField` | Form | Copies field values between node translations |
| `CustomArticleUpdate` | Drush commands | Article update operations |
| `CopyKeyword` | Drush commands | Keyword copy operations |
| `DeleteTaxonomyTermsCommands` | Drush commands | Taxonomy term deletion |

---

## 11. `file_sanitizer`

**Name:** File Sanitizer
**Core:** `^10 || ^11`

### Purpose

Sanitizes filenames on upload (transliteration, unsafe character removal, lowercasing) and provides Drush commands for scanning existing files for issues.

### Services

| Service ID | Class | Role |
|-----------|-------|------|
| `file_sanitizer.filename_sanitizer` | `FilenameSanitizer` | Filename validation and sanitization (transliteration → ASCII) |

### Hooks

| Hook | Behavior |
|------|----------|
| `hook_file_presave` | Sanitizes filenames for NEW image uploads only (`temporary://` scheme). Skips system-generated files (`public://`, `private://`) and non-image MIME types. |

### `FilenameSanitizer` Pipeline

1. URL-decode
2. Split extension
3. Lowercase
4. Transliterate UTF-8 → ASCII
5. Replace dots with dashes
6. Replace unsafe characters with dashes
7. Collapse consecutive dashes
8. Clean extension

### Drush Commands

| Command class | Purpose |
|---------------|---------|
| `FileSanitizerCommands` | Scan cover images, detect MIME mismatches, scan body-embedded media — generates CSV reports |
| `NodeTouchCommands` | Re-save nodes to update `changed` timestamp (all translations, chunks of 50) |

---

## 12. `pb_custom_rest_api`

**Name:** Custom REST API
**Core:** `^9.2 || ^10 || ^11`

### Purpose

Single REST resource plugin for the force-update check endpoint.

### REST Resources

**V1 — `CustomRestResource`**

| Property | Value | Source |
|----------|-------|--------|
| Plugin ID | `custom_rest_resource` | `@RestResource` annotation |
| Path | `/api/check-update/{country}` | annotation `uri_paths.canonical` |
| Method | GET | `config/sync/rest.resource.custom_rest_resource.yml` |
| Auth | `basic_auth` | `config/sync/rest.resource.custom_rest_resource.yml` |

The plugin annotation declares only `id`, `label`, and `uri_paths`. Enabled methods, formats, and authentication are defined in the REST resource config (`rest.resource.custom_rest_resource.yml`), which lives in shared base config (`config/sync/`), not in this module.

`CustomRestResource::get($country)` queries the `forcefull_check_update_api` table (owned by `pb_custom_form`) for the latest `content_update` and `app_update` records by country group ID.

**V2 — `V2CustomRestResource`**

| Property | Value | Source |
|----------|-------|--------|
| Plugin ID | `v2_custom_rest_resource` | `@RestResource` annotation |
| Path | `/v2/api/check-update/{country}` | annotation `uri_paths.canonical` |
| Method | GET | `config/sync/rest.resource.v2_custom_rest_resource.yml` |
| Auth | `basic_auth` | `config/sync/rest.resource.v2_custom_rest_resource.yml` |

Extends `CustomRestResource` with zero logic changes — identical response at a V2 URL path. Added to support the second app version.

Full response shape documented in [`API_REFERENCE.md` §9](API_REFERENCE.md#9-force-update-rest-resource).

---

## 13. `pb_custom_standard_deviation`

**Name:** PB Custom Standard Deviation
**Core:** `^9.2 || ^10 || ^11`

### Purpose

V1-only Views style plugin for the `/api/standard_deviation/%` endpoint. Transforms child-growth data into nested structures keyed by growth type and SD label.

### Views Style Plugin

`CustomStandardDeviation` extends `Serializer`:
- Groups rows by growth type (`height_for_age`, `height_for_weight` → renamed to `weight_for_height` in output)
- Buckets by child-age ranges
- Maps SD labels: `goodText`, `warrningSmallLengthText`, `emergencySmallLengthText`, `warrningBigLengthText` (height_for_age); `goodText`, `warrningSmallHeightText`, `emergencySmallHeightText`, `warrningBigHeightText`, `emergencyBigHeightText` (weight_for_height)
- Language validation via `checkRequestParams()`

---

## 14. `pb_custom_migrate`

**Name:** PB Custom Migrate (Multilingual)
**Core:** `^9.2 || ^10 || ^11`
**Dependencies:** `drupal:system`, `migrate`, `migrate_drupal`, `migrate_plus`, `migrate_tools`, `migrate_source_csv`, `node`, `taxonomy`, `content_translation`

### Purpose

CSV-based content migration definitions. 207 migration config files in `config/install/` covering per-country, per-language content imports for activities, articles, FAQs, and other content types.

### Structure

- `config/install/migrate_plus.migration.*.yml` — 207 migration configs
- `sources/` — Migration source CSV files and mappings
- `.module` — Empty (documentation comment only)

---

## 15. `pb_strings`

**Name:** PB Strings
**Core:** `^10 || ^11`
**Dependencies:** `drupal:taxonomy`, `drupal:content_translation`

### Purpose

Manages the `strings` taxonomy vocabulary with UI for bulk translation and enforces unique `field_unique_name` values on string terms.

### Routes

| Path | Handler | Access |
|------|---------|--------|
| `/admin/structure/taxonomy/manage/{taxonomy_vocabulary}/strings-list` | `StringsListController::listPage` | `_custom_access: StringsListController::access` |

> The route uses `_custom_access` (not `_permission`); the `access()` method enforces the `administer strings` / `translate strings` permissions internally.

### Permissions

| Permission | Description |
|-----------|-------------|
| `administer strings` | Manage string terms |
| `translate strings` | Translate string terms |

### Hooks

| Hook | Behavior |
|------|----------|
| `hook_taxonomy_term_presave` | Enforces unique `field_unique_name` across all `strings` terms |
| `hook_form_taxonomy_term_strings_form_alter` | Adds validation callback to strings term form |

### Key Classes

| Class | Type | Role |
|-------|------|------|
| `StringsListController` | Controller | List page with access check |
| `StringsFilterForm` | Form | Filter form for strings listing |
| `StringsTranslateForm` | Form | Bulk translation form |

---

## Cross-Module Dependencies

```
bebbo_serializer ──→ language_visibility_control ──→ group (contrib)
                                                  ──→ drupal:language
custom_serialization ──→ language_visibility_control

bebbo_api_security ──→ drupal:key
                   ──→ drupal:views
                   ──→ view_custom_table (contrib)

pb_custom_rest_api ──→ pb_custom_form (uses its DB table)

pb_custom_migrate ──→ migrate, migrate_plus, migrate_tools, migrate_source_csv

pb_strings ──→ drupal:taxonomy, drupal:content_translation
language_custom_field ──→ field:field, field:field_ui
```

---

## Database Tables (custom)

| Table | Owner module | Purpose |
|-------|-------------|---------|
| `forcefull_check_update_api` | `pb_custom_form` | Force-update flags per country |
| `custom_language_data` | `language_custom_field` | Custom fields on language entities |
| `bebbo_api_devices` | `bebbo_api_security` | Registered devices |
| `bebbo_api_refresh_tokens` | `bebbo_api_security` | Hashed refresh tokens with family-based rotation |
| `bebbo_api_challenges` | `bebbo_api_security` | Single-use nonces for sideloaded verification |
| `bebbo_api_security_log` | `bebbo_api_security` | Security audit trail |
| `pb_analytics_sync_log` | `pb_content_analytics` | Analytics sync tracking |

---

## Cron Implementations

| Module | Behavior |
|--------|----------|
| `bebbo_api_security` | Purge expired challenges, revoked/expired tokens, trim security log |
| `pb_content_analytics` | Auto-sync analytics from BigQuery (configurable schedule) |

---

## Event Subscribers

| Service ID | Event | Priority | Module | Effect |
|-----------|-------|----------|--------|--------|
| `bebbo_serializer.request_format_subscriber` | `REQUEST` | 1000 | `bebbo_serializer` | Registers `bebbo_json` MIME type |
| `bebbo_api_security.request_subscriber` | `REQUEST` | 300 | `bebbo_api_security` | JWT enforcement |
| `pb_custom_form.internal_content_node_redirect` | `REQUEST` | 30 | `pb_custom_form` | Internal node URL redirect |
| `bebbo_serializer.etag_response_subscriber` | `RESPONSE` | 0 | `bebbo_serializer` | ETag/304 |
| `language_visibility_control.api_response_subscriber` | `RESPONSE` | -10 | `language_visibility_control` | V1 country-groups language filtering |
| `pb_content_analytics.feeds_import_subscriber` | Feeds events | — | `pb_content_analytics` | Feeds import integration |

---

## Related Documentation

| Topic | Document |
|-------|----------|
| REST API endpoints & response shapes | [`API_REFERENCE.md`](API_REFERENCE.md) |
| Device attestation & JWT security | [`API_SECURITY.md`](API_SECURITY.md) |
| System architecture | [`ARCHITECTURE.md`](ARCHITECTURE.md) |
