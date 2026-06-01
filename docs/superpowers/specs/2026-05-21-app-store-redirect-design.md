# App Store Redirect Settings — Design Spec

**Date:** 2026-05-21
**Module:** `pb_custom_form`
**Approach:** Drupal controller + config form (Approach 1)

---

## Problem

Each Bebbo subsite needs a `/downloadapp.html` page that redirects QR code scanners to the correct app listing — Google Play on Android, App Store on iOS, site homepage on desktop. Currently a static HTML file with hardcoded bebbo.app URLs exists at `docroot/downloadapp.html`. Country sites cannot configure their own store listing URLs.

## Solution

Two new components in `pb_custom_form`:

1. **Config form** — Admin enters App Store + Google Play URLs per site
2. **Controller** — Serves `/downloadapp.html` with dynamic URL injection

---

## 1. Config Form: `AppStoreRedirectForm`

### Path

`/admin/config/parent-buddy/app-store-redirect`

### Permission

`administer site configuration` (existing permission, matches other PB admin forms)

### Config Object

`pb_custom_form.app_store_redirect`

```yaml
app_store_url: ''
google_play_url: ''
```

### Form Fields

| Field | Form API type | Key | Helper text |
|-------|--------------|-----|-------------|
| App Store listing | `url` | `app_store_url` | `e.g. https://apps.apple.com/app/bebbo-parenting-app/id1588918146` |
| Google Play listing | `url` | `google_play_url` | `e.g. https://play.google.com/store/apps/details?id=org.unicef.ecar.bebbo` |

### Validation

- Both fields use `#type => 'url'` which provides built-in URL validation
- Both fields optional — allows partial save (controller handles missing values gracefully)

### Menu Link

Under Parent Buddy admin menu (`pb_custom_form.admin_config_parent_buddy`), weight 106.

```yaml
# pb_custom_form.links.menu.yml
pb_custom_form.app_store_redirect:
  title: 'App Store Redirect Settings'
  route_name: pb_custom_form.app_store_redirect
  parent: pb_custom_form.admin_config_parent_buddy
  description: 'Configure app store URLs for QR code redirect page'
  weight: 106
```

### Class

`Drupal\pb_custom_form\Form\AppStoreRedirectForm` extends `ConfigFormBase`.

Follows exact pattern of existing `RedirectManagementForm` and `SettingsForm`:
- `getEditableConfigNames()` returns `['pb_custom_form.app_store_redirect']`
- `getFormId()` returns `'pb_custom_form_app_store_redirect'`
- `buildForm()` creates two `url` fields with defaults from config
- `submitForm()` saves both values to config

---

## 2. Controller: `AppStoreRedirectController`

### Route

```yaml
# pb_custom_form.routing.yml
pb_custom_form.app_store_redirect_page:
  path: '/downloadapp.html'
  defaults:
    _controller: '\Drupal\pb_custom_form\Controller\AppStoreRedirectController::render'
    _title: 'Download App'
  requirements:
    _access: 'TRUE'
```

Public access — no authentication required. This is a landing page for QR code scanners.

### Behavior

Controller reads three URLs:
1. `google_play_url` from `pb_custom_form.app_store_redirect` config
2. `app_store_url` from `pb_custom_form.app_store_redirect` config
3. Homepage URL — derived from `\Drupal::config('system.site')->get('page.front')`, converted to absolute URL via `Url::fromUserInput()->setAbsolute()->toString()`

Returns a `Symfony\Component\HttpFoundation\Response` object (not a render array) with:
- `Content-Type: text/html; charset=UTF-8`
- Full HTML document with inline JavaScript

### Fallback Logic

| Condition | Behavior |
|-----------|----------|
| Both URLs configured | Full redirect script (Android → Play, iOS → App Store, Desktop → homepage) |
| Neither URL configured | 302 redirect to site homepage |
| Only one configured | Redirect script with configured platform, other platform falls through to homepage |

### HTML Output

Matches existing `downloadapp.html` structure exactly:

```html
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Download {site_name} app</title>
  <script>
    const ua = navigator.userAgent
    if (/android/i.test(ua)) {
      location.replace('{google_play_url}')
    } else if (/iphone|ipad|ipod/i.test(ua)) {
      location.replace('{app_store_url}')
    } else {
      location.replace('{homepage_url}')
    }
  </script>
</head>
<body>
  <noscript>
    <p>
      JavaScript is required for automatic redirection.<br>
      Please visit our <a href="{homepage_url}">landing page</a>.
    </p>
  </noscript>
  <p style="display:none;" id="fallback-link">
    If you are not redirected, please visit our <a
    href="{homepage_url}">landing page</a>.
  </p>
  <script>
    setTimeout(function() {
      document.getElementById('fallback-link').style.display = 'block';
    }, 5000);
  </script>
</body>
</html>
```

Replacements:
- `{google_play_url}` — from config (if missing, replaced with `{homepage_url}`)
- `{app_store_url}` — from config (if missing, replaced with `{homepage_url}`)
- `{homepage_url}` — absolute URL of site front page
- `{site_name}` — from `system.site` `name` config

### Caching

Response includes:
- `Cache-Control: public, max-age=3600` (1 hour)
- `X-Drupal-Cache-Tags: config:pb_custom_form.app_store_redirect config:system.site`

Cache auto-invalidates when admin saves form or site settings change.

### Security

URLs inserted into HTML are escaped via `Html::escape()` to prevent XSS if config is tampered with.

---

## 3. Multisite / Config Split

### How It Works

- Route defined once in `pb_custom_form` — shared codebase, active on all sites
- Config Split transparently returns each site's override when controller reads config
- Homepage auto-detected per site from `system.site` (already split per site)

### Config Files

Base config (empty defaults):

```
config/sync/pb_custom_form.app_store_redirect.yml
```

Per-site overrides (admin configures via form, exported via `drush cex`):

```
config/bebbo/pb_custom_form.app_store_redirect.yml
config/bangla/pb_custom_form.app_store_redirect.yml
config/ecuador/pb_custom_form.app_store_redirect.yml
config/turkey/pb_custom_form.app_store_redirect.yml
config/somoa/pb_custom_form.app_store_redirect.yml
config/pacific_islands/pb_custom_form.app_store_redirect.yml
config/zimbabwe/pb_custom_form.app_store_redirect.yml
config/pakistan/pb_custom_form.app_store_redirect.yml
```

Each site's split definition must include `pb_custom_form.app_store_redirect` in its `complete_list` so values are split per site.

---

## 4. Cleanup

Delete existing static file `docroot/downloadapp.html`. Apache serves static files before Drupal routes — file would shadow the controller on all sites.

---

## 5. Files Changed

| File | Action | Description |
|------|--------|-------------|
| `docroot/modules/custom/pb_custom_form/src/Form/AppStoreRedirectForm.php` | Create | Config form class |
| `docroot/modules/custom/pb_custom_form/src/Controller/AppStoreRedirectController.php` | Create | Controller serving redirect HTML |
| `docroot/modules/custom/pb_custom_form/pb_custom_form.routing.yml` | Edit | Add 2 routes (form + controller) |
| `docroot/modules/custom/pb_custom_form/pb_custom_form.links.menu.yml` | Edit | Add menu link for form |
| `config/sync/pb_custom_form.app_store_redirect.yml` | Create | Default config with empty values |
| `config/{site}/pb_custom_form.app_store_redirect.yml` | Create (per site) | Per-site URL overrides |
| `config/sync/config_split.config_split.{site}_site.yml` | Edit (per site) | Add config to complete_list |
| `docroot/downloadapp.html` | Delete | Replaced by controller |

---

## Acceptance Criteria

1. Config form at `/admin/config/parent-buddy/app-store-redirect` allows admin to add/edit App Store and Google Play URLs
2. Opening `/downloadapp.html` on Android redirects to Google Play URL entered in config
3. Opening `/downloadapp.html` on iOS redirects to App Store URL entered in config
4. Opening `/downloadapp.html` on desktop redirects to site homepage
5. Each subsite can configure its own store listing URLs independently
6. If no URLs configured, page redirects to homepage (no broken experience)
7. Noscript fallback shows link to homepage
8. 5-second timeout shows fallback link if JS redirect fails
