# Bebbo API Reference — REST (V1 + V2), Force-Update, JSON:API

> **Audience:** mobile-integration engineers, backend maintainers, support, QA.
> **Scope:** every HTTP API the CMS exposes to clients — the legacy **V1 REST** (`/api/*`), the current **V2 REST** (`/v2/api/*`), the **Force-Update REST resource** (`/api/check-update/{country}`), and **JSON:API** (`/jsonapi/*`). Field shapes, envelopes, query params, auth, and the V1→V2 changes.
> **Verified against:** repository `HEAD` (branch `feature/group3-manage-users`). Endpoint paths come from the Views configs; envelopes/field transforms from the serializer source. Items that could not be confirmed in code are flagged inline. **No GraphQL exists** (see `ARCHITECTURE.md`).

---

## 1. API Surfaces at a Glance

| Surface | Base | Served by | Status |
|---------|------|-----------|--------|
| **V1 REST** | `/api/*` | `custom_serialization` Views style (+ `bebbo_serializer` for `/api/strings`, `pb_custom_standard_deviation` for `/api/standard_deviation`) | Legacy — still live |
| **V2 REST** | `/v2/api/*` | `bebbo_serializer` Views style | Current |
| **Force-Update** | `/api/check-update/{country}` | `pb_custom_rest_api` REST resource (`custom_rest_resource`) | Live |
| **JSON:API** | `/jsonapi/*` | core `jsonapi` + `jsonapi_extras` | Enabled, **read-only** |

The V1 and V2 content endpoints are **Drupal Views REST/page displays**, not REST plugins. Each display renders rows, then a custom Views *style plugin* serializes them into the Bebbo JSON envelope.

---

## 2. Common Conventions (V1 & V2 content endpoints)

### URL pattern
```
/api/{resource}/{langcode}            (V1)
/v2/api/{resource}/{langcode}         (V2)
```
The trailing path arg is the **langcode** (e.g. `en`, `bn`, `ur`). Taxonomy adds a vocabulary slug: `/v2/api/taxonomies/{langcode}/{vocab}`.

- **V1 langcode resolution:** `CustomSerializer::render()` splits the request path and uses the langcode segment; it is validated against enabled languages **and** per-group mobile language visibility (`language_visibility_control`). Invalid → `{status:400,"message":"Request language is wrong"}`; not visible in any country group → `{status:403,"message":"Language not available"}`.
- **V2 langcode resolution:** `resolveLangcode()` takes the view arg if it is a valid language, else the current language; same visibility validation via `checkLanguageVisibility()` (skipped for country-groups).

### Standard response envelope
```json
{
  "status": 200,
  "total": 177,
  "langcode": "en",
  "datetime": "2026-06-15 12:00",
  "data": [ ... ]
}
```
- `datetime` is **server-generated** (not a request echo), formatted `Y-m-d H:i` in timezone **`Asia/Kolkata`** (both V1 `CustomSerializerHelper` and V2 `BebboSerializer::render()`).
- **Empty result:** `{status:204,"message":"No Records Found","datetime":"…"}` — no `data`/`total`/`langcode`. (Note: `204` is a JSON body field; the HTTP status is not necessarily 204.)
- Envelope variants per endpoint family are listed in their sections below.

### V2 JSON encoder (`BebboEncoder`, format `bebbo_json`)
```php
json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
```
Only those two flags. **No pretty-print, and no `&nbsp;` substitution** — earlier documentation that claimed pretty-print/`&nbsp;` is incorrect. Empty/error envelopes are encoded as plain `json`, success envelopes as `bebbo_json` (`getOutputFormat()`).

### Authentication
- **V1 & V2 content endpoints:** the Views displays declare **no auth provider** (`auth: {}`) and gate only on the `access content` permission (held by anonymous). They are effectively **public reads**. Some displays set `disable_sql_rewrite: true` (in V2: `articles_rest_export`, `child_dev_boy_rest_export`, `child_dev_girl_rest_export`, `child_growth_rest_export` — 4 of ~22 displays, not all).
- **Device/JWT protection:** the `bebbo_api_security` subscriber can require a Bearer JWT on `/v2/api/*` (and `/api/check-update/`) when enforcement is on. **V1 `/api/*` content endpoints are NOT in the default protected set.** Full detail: **`API_SECURITY.md`**.
- **`/api/check-update/{country}`:** `basic_auth` only (see [§9](#9-force-update-rest-resource)).

---

## 3. V1 → V2: What Changed

| Aspect | V1 (`custom_serialization`) | V2 (`bebbo_serializer`) |
|--------|-----------------------------|--------------------------|
| Dispatch | `render()` branches by **`strpos()` substring** on the request path | `transformRows()` `match()` on **Views display id** → per-endpoint `transformX()` method |
| Body/media | Runtime DOMDocument parsing, per-row `customMediaFormatter()` (N+1 entity loads) | Pre-computed `field_body_rendered` / `field_embedded_images`; `parseViewCoverImage()` parses embedded view HTML (batched) |
| `total` | `count($data)` (rendered row count) | `$this->view->total_rows` (query count, filters applied; falls back to `count($rows)` if unavailable) |
| Type casting | inline `(int)`, comma-split | helper methods `castToInt/castToBool/castToNumber/toIntArray/toStringArray` |
| New content types | — | **Course** (`/v2/api/course`), **Quiz** (`/v2/api/quiz`) |
| New endpoints | — | **`/v2/api/guide`**, **`/v2/api/weekly-overview`** |
| Dropped in V2 | `sponsors`, `strings`*, `related-article-contents/*/milestone`, `updated-pinned-contents/*/faq`, the `pinned-contents/*/faq|child_growth|health_check_ups|vaccinations` variants | (not re-implemented) |
| Engagement counts | — | `read_count`, `love_count` on activities/articles/video-articles/course |

\* `/api/strings/%` is actually served by the **`bebbo_serializer`** style (not `custom_serialization`), even though it sits under `/api/`. There is no `/v2/api/strings`.

> Both V1 and V2 remain live. The mobile app migrated to V2; V1 is retained for backward compatibility.

---

## 4. V1 REST Endpoints (`/api/*`)

**Dispatch:** a single Views style plugin (`custom_serialization`) serves all `api/*` displays in `views.view.articles.yml`. `render()` reads the current path, JSON-renders each row (the row already carries **short keys** — `id`, `type`, `title`, `body`, `cover_image`, …), then post-processes by substring-matched endpoint type.

### 4.1 Endpoint inventory

| Path | View / style | Notes |
|------|--------------|-------|
| `/api/articles/%` | articles / custom_serialization | Pregnancy term preserved; child-age arrays filtered |
| `/api/video-articles/%` | articles / custom_serialization | |
| `/api/activities/%` | articles / custom_serialization | |
| `/api/milestones/%` | articles / custom_serialization | |
| `/api/faqs/%` | articles / custom_serialization | |
| `/api/basic-pages/%` | articles / custom_serialization | adds `unique_name` (lowercased English title, spaces→`_`) |
| `/api/daily-homescreen-messages/%` | articles / custom_serialization | minimal (id, type, title, dates) |
| `/api/vaccinations/%` | articles / custom_serialization | |
| `/api/child-development-data/%` | articles / custom_serialization | |
| `/api/child-growth-data/%` | articles / custom_serialization | |
| `/api/health-checkup-data/%` | articles / custom_serialization | |
| `/api/surveys/%` | articles / custom_serialization | |
| `/api/archive/%` | articles / custom_serialization | grouped `{Type:[ids]}` envelope |
| `/api/standard_deviation/%` | articles / **`pb_custom_standard_deviation`** | nested SD envelope (see [§8](#8-standard-deviation)) |
| `/api/pinned-contents/%/child_development/40` (boy) | articles / custom_serialization | type-specific media swap; dedup by id |
| `/api/pinned-contents/%/child_development/41` (girl) | articles / custom_serialization | " |
| `/api/pinned-contents/%/child_growth` | articles / custom_serialization | **V1-only** |
| `/api/pinned-contents/%/faq` | articles / custom_serialization | **V1-only** |
| `/api/pinned-contents/%/health_check_ups` | articles / custom_serialization | **V1-only** |
| `/api/pinned-contents/%/vaccinations` | articles / custom_serialization | **V1-only** |
| `/api/updated-pinned-contents/%/faq` | articles / custom_serialization | **V1-only** |
| `/api/related-article-contents/%/milestone` | articles / custom_serialization | **V1-only**; dedup by id |
| `/api/country-groups/%` | country_listing / custom_serialization | langcode forced to `en` |
| `/api/vocabularies/%` | tax / custom_serialization | keyed map (see [§7](#7-taxonomy--vocabulary)) |
| `/api/taxonomies/%/%` | tax / custom_serialization | keyed map |
| `/api/strings/%` | tax / **`bebbo_serializer`** | **V1-only** |
| `/api/sponsors/%` | sponsors_list / custom_serialization | **V1-only**; `%` is country-group id (`all` allowed), no `langcode` key in output |

### 4.2 Shared value transforms (`CustomSerializer::render`)

Applied per-row to whichever short keys are present:

- **Text decode:** `title`, `question` → decode `&#039;`/`&quot;` then `htmlspecialchars_decode`.
- **HTML body cleanup** (`body`, `summary`, `answer_part_1`, `answer_part_2`): absolutise `/sites/default/files/` & `/media/oembed` URLs; strip `<span>`, empty `<p>`/`<strong>`, inline `style=`, remote-video width/height, CKEditor label div; `html_entity_decode`.
- **`embedded_images`** (array of `<img>` src, kept relative) extracted for types Article / Games / Basic page / Video Article.
- **Media objects** (`cover_image`, `country_flag`, `country_sponsor_logo`, `unicef_logo`, `country_national_partner`, `cover_video`) via `customMediaFormatter()`: image → `{url,name,alt}` (`content_1200xh_` style, WebP); remote_video/video → `{url,name,site}` (or thumbnail `{url,name,alt}` for `cover_image`); empty → `{url:'',name:'',alt:''}`.
- **Int arrays** (`child_age`, `keywords`, `related_articles`, `related_video_articles`, `related_activities`, `related_milestone`): comma-split, each `intval()`.
- **Int casts** (`id`, `field_type_of_article`, `category`, `subcategory`, `child_gender`, `parent_gender`, `licensed`, `premature`, `mandatory`, `growth_type`, `standard_deviation`, `growth_period`, `activity_category`, `equipment`, `type_of_support`, `pinned_video_article`, `pinned_article`, `old_calendar`, `related_article`, `chatbot_subcategory`, …): empty → `0`.
- **Endpoint-specific:** pinned-contents — Article rows drop `cover_video`/`cover_video_image`, Video-Article rows move `cover_video_image`→`cover_image`, plus **dedup by `id`**; basic-pages — add `unique_name`; articles/taxonomies — strip the Pregnancy term from child-age arrays (kept for `api/articles`).

> The per-row field **set** for each endpoint is selected by the corresponding `views.view.articles.yml` display (verified field-by-field in config). The serializer renders those fields under short keys and applies the transforms above. For the concrete shapes, the V1 endpoints mirror their V2 equivalents in [§5](#5-v2-rest-endpoints-v2api) — except the V1-only endpoints (sponsors, strings, the extra pinned/updated/related variants) and the grouped `archive` / nested `standard_deviation` shapes.

### 4.3 V1 query parameters

V1 honors exactly **one** parameter inside the serializer: **`pregnancy=true`** — on the taxonomies branch it keeps the Pregnancy `child_age` term (otherwise hidden); ignored on `api/articles`. The other filter params below ([§6](#6-query-parameters)) are Views exposed filters configured on the article view and apply to V1 too.

---

## 5. V2 REST Endpoints (`/v2/api/*`)

**Dispatch:** `transformRows($displayId)` `match()` → `transformX()`. Mapping (display id → method → path), verified in `BebboSerializer.php` + `views.view.bebbo_v2_apis.yml`:

| Path | Display id | Transform |
|------|-----------|-----------|
| `/v2/api/activities/%` | `activities_rest_export` | `transformActivities` |
| `/v2/api/articles/%` | `articles_rest_export` | `transformArticles` |
| `/v2/api/video-articles/%` | `video_article_rest_export` | `transformVideoArticles` |
| `/v2/api/faqs/%` | `faq_rest_export` | `transformFaq` |
| `/v2/api/basic-pages/%` | `basic_page_rest_export` | `transformBasicPages` |
| `/v2/api/daily-homescreen-messages/%` | `home_screen_rest_export` | `transformDailyHomeScreenMessages` |
| `/v2/api/vaccinations/%` | `vaccination_rest_export` | `transformVaccinations` |
| `/v2/api/milestones/%` | `milestones_rest_export` | `transformMilestones` |
| `/v2/api/pinned-contents/%/child_development/40` | `child_dev_boy_rest_export` | `transformChildDevPinned` |
| `/v2/api/pinned-contents/%/child_development/41` | `child_dev_girl_rest_export` | `transformChildDevPinned` |
| `/v2/api/child-development-data/%` | `child_development_rest_export` | `transformChildDevelopment` |
| `/v2/api/child-growth-data/%` | `child_growth_rest_export` | `transformChildGrowth` |
| `/v2/api/health-checkup-data/%` | `health_checkup_rest_export` | `transformHealthCheckUps` |
| `/v2/api/surveys/%` | `survey_rest_export` | `transformSurveys` |
| `/v2/api/weekly-overview/%` | `weekly_overview_export` | `transformPregnancyWeekly` |
| `/v2/api/guide/%` | `guide_rest_export` | `transformGuide` |
| `/v2/api/standard_deviation/%` | `standard_deviation_rest_export` | `transformStandardDeviation` |
| `/v2/api/archive/%` | `archive_rest_export` | `transformArchive` |
| `/v2/api/course/%` | `course_rest_export` | `transformCourse` |
| `/v2/api/quiz/%` | `quiz_rest_export` | `transformQuiz` |
| `/v2/api/country-groups/%` | `country_listing_export` (country_listing view) | `transformCountryGroups` |
| `/v2/api/vocabularies/%` | `vocabulary_rest_export` (tax view) | `transformVocabularies` |
| `/v2/api/taxonomies/%/%` | `terms_rest_export` (tax view) | `transformTaxonomies` |

### 5.1 Envelope variants
- **Standard:** `{status,total,langcode,datetime,data}`; `total = (int) view->total_rows`.
- **Taxonomy / Vocabulary:** `{status,langcode,datetime,data}` — **no `total`**.
- **Standard deviation:** `{status,langcode,data}` — **no `total`, no `datetime`**.
- **Archive:** `data` is grouped `{ "<ContentType>": [id,…] }`; `total = array_sum(map count)` (sum of IDs, not entity count).
- **Guide:** `related_articles` key is **removed when empty** (not `[]`).
- **Empty / language error:** `204`/`400`/`403` as in [§2](#2-common-conventions-v1--v2-content-endpoints).

### 5.2 Type-cast helpers
`castToInt` (→int, default 0) · `castToBool` (`FILTER_VALIDATE_BOOLEAN`) · `castToNumber` (natural int/float, non-numeric→0) · `toIntArray` (dedup int array) · `toStringArray` (trim, drop empty) · `decodeHtmlEntities` (`htmlspecialchars_decode ENT_QUOTES|ENT_HTML5`). Media object `{url,name,alt}` (webp, absolute); video object `{url,name,site}`.

### 5.3 Per-endpoint response fields

Every `data[]` row includes both **typed keys** (explicitly cast by the transform — marked with type annotations below) and **passthrough keys** (rendered by the View's `data_field` row plugin and forwarded as-is). Both appear in the JSON response. The cast helpers (`castToInt`, `toIntArray`, etc.) only operate on keys already present in the View output — they skip missing keys.

**Common passthrough keys** present on most content endpoints (omitted from the per-endpoint table):
- `type` (string) — content-type label. Present on: activities, articles, video-articles, faqs, basic-pages, daily-homescreen-messages, vaccinations, milestones, pinned-contents, child-development-data, child-growth-data, health-checkup-data, quiz. **Not** on: course, weekly-overview, guide, standard-deviation, archive, country-groups. Surveys have a `type` key but it comes from `field_type` (survey type), not the content-type label.
- `created_at` (string) — formatted creation timestamp. Present on all content endpoints **except** standard-deviation.
- `updated_at` (string) — formatted last-modified timestamp. Present on all content endpoints **except** standard-deviation.

| Endpoint | All response keys |
|----------|-------------------|
| **activities** | `id`(int), `activity_category`(int), `equipment`(int), `type_of_support`(int), `mandatory`(int), `read_count`(int), `love_count`(int), `child_age`(int[]), `related_milestone`(int[]), `embedded_images`(str[]), `title`(decoded), `cover_image`{url,name,alt}, `body`(HTML), `summary`(HTML) |
| **articles** | `id`(int), `field_type_of_article`(int), `category`(int), `subcategory`(int), `child_gender`(int), `parent_gender`(int), `premature`(int), `read_count`(int), `love_count`(int), `child_age`(int[]), `keywords`(int[]), `related_articles`(int[]), `related_video_articles`(int[]), `target_audience`(int[]), `embedded_images`(str[]), `title`(decoded), `cover_image`{url,name,alt} (built from `cover_image_mid/_url/_name/_alt`, empty→`{'','',''}`), `body`(HTML), `summary`(HTML), `meta_keywords`, `do_not_feature` |
| **video-articles** | `id`(int), `category`(int), `child_gender`(int), `parent_gender`(int), `licensed`(int), `premature`(int), `mandatory`(int), `read_count`(int), `love_count`(int), `child_age`(int[]), `keywords`(int[]), `related_articles`(int[]), `related_video_articles`(int[]), `target_audience`(int[]), `embedded_images`(str[]), `title`(decoded), `cover_video`{url,name,site}, `cover_image`{url,name,alt}, `body`(HTML), `summary`(HTML) |
| **faqs** | `id`(int), `chatbot_subcategory`(int), `related_article`(int), `mandatory`(int), `child_age`(int[]), `question`(decoded), `answer_part_1`(HTML), `answer_part_2`(HTML) |
| **basic-pages** | `id`(int), `mandatory`(int), `embedded_images`(str[]), `title`(decoded), `unique_name` (lowercased English title, spaces→`_`), `body`(HTML) |
| **daily-homescreen-messages** | `id`(int), `title`(decoded) |
| **vaccinations** | `id`(int), `growth_period`(int), `pinned_video_article`(int), `old_calendar`(int), `pinned_article`(int), `related_articles`(int[]), `title`(decoded), `uuid` |
| **milestones** | `id`(int), `mandatory`(int), `child_age`(int[]), `related_activities`(int[]), `related_articles`(int[]), `related_video_articles`(int[]), `title`(decoded), `body`(HTML) |
| **pinned-contents .../40 & .../41** | `id`(int), `category`(int), `child_gender`(int), `parent_gender`(int), `licensed`(int), `premature`(int), `mandatory`(int), `child_age`(int[]), `keywords`(int[]), `related_articles`(int[]), `title`(decoded); dedup by `id`; Video-Article rows: `cover_video`{url,name,site}, `cover_image`{url,name,alt}; passthrough: `body`(HTML), `summary`(HTML) |
| **child-development-data** | `id`(int), `boy_video_article`(int), `girl_video_article`(int), `mandatory`(int), `child_age`(int[]), `title`(decoded), `milestone` |
| **child-growth-data** | `id`(int), `growth_type`(int), `standard_deviation`(int), `mandatory`(int), `child_age`(int[]), `pinned_articles`(int[]), `title`, `body`(HTML) |
| **health-checkup-data** | `id`(int), `growth_period`(int), `pinned_article`(int), `pinned_video_article`(int), `title`(decoded); dedup by `id`. Note: the transform casts additional fields (`category`, `child_gender`, etc.) but the View display only provides the 8 fields listed here — the extra casts are no-ops. |
| **surveys** | `id`(int), `title`(decoded), `body`(HTML), `type` (survey type from `field_type`, not content-type label), `survey_feedback_link` |
| **weekly-overview** | `id`(int), `prental_age`(int), `licensed`(int), `average_height`(number), `average_weight`(number), `related_articles`(int[]), `featured_image_1`{url,name,alt}, `featured_image_2`{url,name,alt}, `title`, `fun_fact` |
| **guide** | `id`(int), `child_age`(int), `licensed`(int), `related_articles`(int[], dropped if empty), `related_games`(int[]), `title`, `message` |
| **archive** | grouped `{ "<Type>": [id,…] }` |
| **country-groups** | see [§5.5](#55-country-groups) |
| **course** | see [§5.4](#54-course--quiz) |
| **quiz** | see [§5.4](#54-course--quiz) |

> **Engagement counts:** the emitted JSON keys are **`read_count`** and **`love_count`** (present only on activities, articles, video-articles, course). Note the underlying Drupal field is `field_like_count` (see `pb_content_analytics`), but the V2 API key is `love_count`, not `like_count`. There is no `like_count` key in the API output.

#### JSON response examples

All content endpoints return the **standard envelope** wrapping a `data[]` array:

```json
{
  "status": 200,
  "total": 177,
  "langcode": "en",
  "datetime": "2026-06-17 12:00",
  "data": [ ...items... ]
}
```

Envelope variants: **taxonomy/vocabulary** omit `total`; **standard-deviation** omits `total` and `datetime`; **archive** has a grouped `data` object (not array). See [§5.1](#51-envelope-variants).

The examples below show a **single `data[]` item** for each endpoint. Timestamps (`created_at`, `updated_at`) use Drupal's configured "medium" date format.

---

**Activities** (`/v2/api/activities/%`):
```json
{
  "id": 456,
  "type": "Activity",
  "title": "Sing and Clap Together",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "body": "<p>Clap your hands while singing a favorite song...</p>",
  "summary": "A fun singing activity for toddlers",
  "activity_category": 3,
  "equipment": 0,
  "type_of_support": 2,
  "mandatory": 0,
  "child_age": [10, 20],
  "related_milestone": [100, 101],
  "embedded_images": ["https://example.com/sites/default/files/img1.webp"],
  "cover_image": {"url": "https://example.com/image.webp", "name": "activity-cover", "alt": "Children singing"},
  "love_count": 25,
  "read_count": 150
}
```

---

**Articles** (`/v2/api/articles/%`):
```json
{
  "id": 123,
  "type": "Article",
  "title": "Breastfeeding Basics",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "body": "<p>Breastfeeding provides essential nutrition...</p>",
  "summary": "A guide to breastfeeding for new mothers",
  "field_type_of_article": 1,
  "category": 5,
  "subcategory": 12,
  "child_gender": 0,
  "parent_gender": 0,
  "premature": 0,
  "child_age": [10, 20, 30],
  "keywords": [45, 67],
  "related_articles": [124, 125],
  "related_video_articles": [200],
  "target_audience": [1, 2],
  "embedded_images": ["https://example.com/sites/default/files/img1.webp"],
  "cover_image": {"url": "https://example.com/image.webp", "name": "cover", "alt": "Mother breastfeeding"},
  "love_count": 100,
  "read_count": 500,
  "meta_keywords": "breastfeeding, nutrition, baby",
  "do_not_feature": "0"
}
```

> `cover_image` is built from intermediate View fields (`cover_image_mid`, `cover_image_url`, `cover_image_name`, `cover_image_alt`) which are removed from output. Image URL is converted to WebP. When `cover_image_mid` is 0/empty, returns `{"url":"","name":"","alt":""}`.

---

**Video Articles** (`/v2/api/video-articles/%`):
```json
{
  "id": 200,
  "type": "Video Article",
  "title": "How to Bathe Your Baby",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "body": "<p>Step-by-step guide to bathing your newborn...</p>",
  "summary": "Safe bathing tips for new parents",
  "category": 3,
  "child_gender": 0,
  "parent_gender": 0,
  "licensed": 0,
  "premature": 0,
  "mandatory": 0,
  "child_age": [10, 20],
  "keywords": [45],
  "related_articles": [123],
  "related_video_articles": [201],
  "target_audience": [1],
  "embedded_images": ["https://example.com/sites/default/files/img.webp"],
  "cover_video": {"url": "https://www.youtube.com/watch?v=abc123", "name": "bath-video", "site": "youtube"},
  "cover_image": {"url": "https://example.com/thumb.webp", "name": "thumbnail", "alt": "Baby bath"},
  "love_count": 75,
  "read_count": 300
}
```

> `cover_video` is parsed from an embedded view rendering a remote_video/video media entity. `site` is `"youtube"` or `"vimeo"`. `cover_image` is the video thumbnail parsed via `parseViewCoverImage`.

---

**FAQs** (`/v2/api/faqs/%`):
```json
{
  "id": 300,
  "type": "FAQ",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "question": "When should I start solid foods?",
  "answer_part_1": "<p>Around 6 months of age...</p>",
  "answer_part_2": "<p>Start with soft, mashed foods...</p>",
  "chatbot_subcategory": 5,
  "related_article": 123,
  "mandatory": 0,
  "child_age": [20, 30]
}
```

> FAQs have no `title` key — the node title is aliased to `question`. No media fields, no engagement counts, no embedded images.

---

**Basic Pages** (`/v2/api/basic-pages/%`):
```json
{
  "id": 400,
  "type": "Basic page",
  "title": "About Bebbo",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "body": "<p>Bebbo is a parenting app by UNICEF...</p>",
  "mandatory": 0,
  "embedded_images": ["https://example.com/sites/default/files/img.webp"],
  "unique_name": "about_bebbo"
}
```

> `unique_name` is generated from the **English** node title (lowercased, spaces→`_`), regardless of the requested language. Empty string if no English title exists.

---

**Daily Homescreen Messages** (`/v2/api/daily-homescreen-messages/%`):
```json
{
  "id": 500,
  "type": "Daily Homescreen Message",
  "title": "Your baby loves hearing your voice!",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30"
}
```

> Simplest content endpoint — only `id` and `title` are typed; `type`, `created_at`, `updated_at` pass through.

---

**Vaccinations** (`/v2/api/vaccinations/%`):
```json
{
  "id": 600,
  "type": "Vaccination",
  "title": "BCG Vaccine",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "growth_period": 1,
  "pinned_article": 123,
  "pinned_video_article": 200,
  "related_articles": [124, 125],
  "old_calendar": 0
}
```

> Only vaccination endpoint exposes `uuid`. `related_articles` comes from `field_related_articles_vacci` (a separate field from the standard `field_related_articles`).

---

**Milestones** (`/v2/api/milestones/%`):
```json
{
  "id": 700,
  "type": "Milestone",
  "title": "First Steps",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "body": "<p>Most children take their first steps between 9 and 12 months...</p>",
  "mandatory": 0,
  "child_age": [30, 40],
  "related_activities": [456],
  "related_articles": [123],
  "related_video_articles": [200]
}
```

---

**Pinned Contents — Boy/Girl** (`/v2/api/pinned-contents/%/child_development/40` and `.../41`):

Article row:
```json
{
  "id": 123,
  "type": "Article",
  "title": "Fine Motor Skills at 12 Months",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "body": "<p>At 12 months your child may...</p>",
  "summary": "Motor development milestones",
  "category": 5,
  "child_gender": 0,
  "parent_gender": 0,
  "licensed": 0,
  "premature": 0,
  "mandatory": 0,
  "child_age": [30],
  "keywords": [45],
  "related_articles": [124]
}
```

Video Article row (same endpoint, different `type`):
```json
{
  "id": 201,
  "type": "Video Article",
  "title": "Watch: 12-Month Motor Skills",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "body": "<p>...</p>",
  "summary": "...",
  "category": 5,
  "child_gender": 0,
  "parent_gender": 0,
  "licensed": 0,
  "premature": 0,
  "mandatory": 0,
  "child_age": [30],
  "keywords": [45],
  "related_articles": [123],
  "cover_video": {"url": "https://www.youtube.com/watch?v=xyz", "name": "motor-skills", "site": "youtube"},
  "cover_image": {"url": "https://example.com/thumb.webp", "name": "thumbnail", "alt": "Motor skills video"}
}
```

> Rows are **deduplicated by `id`**. Article-type rows do **not** get `cover_video`/`cover_image`; only Video Article rows do. The `boy_video_article`/`girl_video_article` keys listed in earlier doc versions are **not** present — those fields belong to the child-development-data endpoint.

---

**Child Development Data** (`/v2/api/child-development-data/%`):
```json
{
  "id": 800,
  "type": "Child development",
  "title": "12-18 Months Development",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "child_age": [30, 40],
  "boy_video_article": 200,
  "girl_video_article": 201,
  "mandatory": 0,
  "milestone": "First words, walking, grasping objects"
}
```

> `milestone` is from `field_milestone_instructions` (passthrough). This endpoint returns the child_development nodes themselves — unlike pinned-contents which returns the referenced articles.

---

**Child Growth Data** (`/v2/api/child-growth-data/%`):
```json
{
  "id": 900,
  "type": "Child growth",
  "title": "Weight for Age",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "body": "<p>Weight-for-age chart guidance...</p>",
  "growth_type": 1,
  "standard_deviation": 2,
  "child_age": [10, 20],
  "pinned_articles": [123],
  "mandatory": 0
}
```

> `title` passes through as-is (not `decodeHtmlEntities`). `pinned_articles` is aliased from `field_related_articles`.

---

**Health Checkup Data** (`/v2/api/health-checkup-data/%`):
```json
{
  "id": 1000,
  "type": "Health checkup",
  "title": "6-Month Checkup",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "growth_period": 3,
  "pinned_article": 123,
  "pinned_video_article": 200
}
```

> Rows are **deduplicated by `id`**. The View display only provides these 8 keys. The transform contains additional casts for `category`, `child_gender`, `parent_gender`, `licensed`, `premature`, `mandatory`, `child_age`, `related_articles`, `related_video_articles` and cover-image type-switching logic, but those are all no-ops since the View does not include those fields.

---

**Surveys** (`/v2/api/surveys/%`):
```json
{
  "id": 1100,
  "title": "Parenting Confidence Survey",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "body": "<p>Rate how confident you feel about...</p>",
  "type": "assessment",
  "survey_feedback_link": "https://example.com/survey"
}
```

> `type` here is from `field_type` (the survey's own type field), **not** the content-type label. This is the only endpoint where `type` does not mean the Drupal bundle label. No common `type` passthrough from `node_field_data.type`.

---

**Weekly Overview** (`/v2/api/weekly-overview/%`):
```json
{
  "id": 1200,
  "title": "Week 20",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "prental_age": 20,
  "licensed": 0,
  "average_height": 25.5,
  "average_weight": 0.3,
  "related_articles": [123, 124],
  "featured_image_1": {"url": "https://example.com/w20.webp", "name": "week20", "alt": "Week 20"},
  "featured_image_2": {"url": "https://example.com/w20b.webp", "name": "week20b", "alt": "Week 20 size"},
  "fun_fact": "Your baby is about the size of a banana!"
}
```

> No `type` passthrough (View omits `node_field_data.type`). `average_height`/`average_weight` use `castToNumber` (natural int or float, non-numeric→0). `featured_image_1`/`featured_image_2` are parsed from embedded view renders.

---

**Guide** (`/v2/api/guide/%`):
```json
{
  "id": 1300,
  "title": "0-6 Months Guide",
  "created_at": "Wed, 06/15/2026 - 10:00",
  "updated_at": "Mon, 06/16/2026 - 14:30",
  "child_age": 10,
  "licensed": 0,
  "related_articles": [123, 124],
  "related_games": [456, 457],
  "message": "Welcome to the first 6 months!"
}
```

> `related_articles` is **removed entirely** (not set to `[]`) when empty. No `type` passthrough.

---

**Standard Deviation** (`/v2/api/standard_deviation/%`):

Full response (non-standard envelope — no `total`, no `datetime`):
```json
{
  "status": 200,
  "langcode": "en",
  "data": {
    "weight_for_height": [
      {
        "child_age": [10, 20],
        "goodText": {
          "articleID": [123],
          "name": "Normal growth",
          "text": "<p>Your child's weight is within the normal range.</p>"
        },
        "warrningSmallHeightText": {
          "articleID": [124],
          "name": "Warning - low",
          "text": "<p>Your child may be underweight...</p>"
        },
        "emergencySmallHeightText": {
          "articleID": [],
          "name": "Emergency - low",
          "text": "<p>Seek medical attention...</p>"
        },
        "warrningBigHeightText": {
          "articleID": [125],
          "name": "Warning - high",
          "text": "<p>Your child may be overweight...</p>"
        },
        "emergencyBigHeightText": {
          "articleID": [],
          "name": "Emergency - high",
          "text": "<p>Seek medical attention...</p>"
        }
      }
    ],
    "height_for_age": [
      {
        "child_age": [10, 20],
        "goodText": {
          "articleID": [126],
          "name": "Normal height",
          "text": "<p>Your child's height is within the normal range.</p>"
        },
        "warrningSmallLengthText": {
          "articleID": [127],
          "name": "Warning - short",
          "text": "<p>Your child may be shorter than expected...</p>"
        },
        "emergencySmallLengthText": {
          "articleID": [],
          "name": "Emergency - short",
          "text": "<p>Seek medical attention...</p>"
        },
        "warrningBigLengthText": {
          "articleID": [128],
          "name": "Warning - tall",
          "text": "<p>Your child is taller than expected...</p>"
        }
      }
    ]
  }
}
```

> Each SD-label object has `{articleID(int[]), name(string), text(string)}`. `data` is grouped by growth type, then bucketed by child-age ranges. Internal growth type `height_for_weight` is renamed to `weight_for_height` in output.

---

**Archive** (`/v2/api/archive/%`):

Full response (standard envelope, but `data` is a **keyed object** not an array):
```json
{
  "status": 200,
  "total": 15,
  "langcode": "en",
  "datetime": "2026-06-17 12:00",
  "data": {
    "Article": [123, 456, 789],
    "Video Article": [200, 201],
    "FAQ": [300, 301],
    "Activity": [450, 451],
    "Basic page": [400]
  }
}
```

> `total` is the **sum of all IDs** across content types, not the number of content types. Keys are content-type labels; values are int arrays of deleted node IDs.

---

**Vocabularies** (`/v2/api/vocabularies/%`):

Full response (no `total` in envelope):
```json
{
  "status": 200,
  "langcode": "en",
  "datetime": "2026-06-17 12:00",
  "data": {
    "child_age": {"name": "Child's Age"},
    "category": {"name": "Category"},
    "growth_period": {"name": "Growth Period"},
    "activity_category": {"name": "Activity Category"},
    "growth_type": {"name": "Growth Type"},
    "child_gender": {"name": "Child Gender"},
    "parent_gender": {"name": "Parent Gender"}
  }
}
```

> Returns a **keyed object** (not array). Each key is a vocabulary machine name; value is `{"name": "Translated Label"}`. The `keywords` vocabulary is always skipped. Labels prefer the requested language's translation; fall back to the default-language label.

---

**Taxonomies** (`/v2/api/taxonomies/%/%`):

Full response (no `total` in envelope, keyed by vocabulary):
```json
{
  "status": 200,
  "langcode": "en",
  "datetime": "2026-06-17 12:00",
  "data": {
    "growth_period": [
      {"id": 1, "name": "0-2 months", "vaccination_opens": 0, "short_name": "0-2m", "unique_name": "0_2_months"}
    ],
    "child_age": [
      {"id": 10, "name": "0-1 month", "days_from": 0, "days_to": 30, "buffers_days": 5, "age_bracket": [1, 2]}
    ],
    "growth_introductory": [
      {"id": 50, "name": "Newborn", "body": "<p>Your newborn...</p>", "days_from": 0, "days_to": 30}
    ],
    "chatbot_subcategory": [
      {"id": 60, "name": "Feeding", "parent_category_id": 5, "unique_name": "feeding"}
    ],
    "category": [
      {"id": 5, "name": "Nutrition", "unique_name": "nutrition", "field_type_of_article": "General"}
    ],
    "activity_category": [
      {"id": 3, "name": "Music & Songs", "unique_name": "music_songs"}
    ],
    "type_of_article": [
      {"id": 1, "name": "General"}
    ]
  }
}
```

> **Specialty vocabs** (`growth_period`, `child_age`, `growth_introductory`, `chatbot_subcategory`, `category`): each has a unique term shape (see keys above). **Unique-name vocabs** (`growth_type`, `activity_category`, `child_gender`, `parent_gender`, `relationship_to_parent`, `chatbot_category`, `subcategory`, `target_audience`, `course_category`): `{id, name, unique_name}`. **Basic vocabs** (everything else): `{id, name}`. The `keywords` vocabulary is always skipped.

---

**Country-groups** (`/v2/api/country-groups/%`):

Full response (standard envelope, langcode forced to `en`):
```json
{
  "status": 200,
  "total": 6,
  "langcode": "en",
  "datetime": "2026-06-17 12:00",
  "data": [
    {
      "CountryID": "45",
      "name": "Bangladesh",
      "country_email": "admin@babuni.app",
      "app_name": "Babuni",
      "content_toggle": "1, 2",
      "all_logos": "...",
      "country_national_partner": {"url": "https://example.com/partner.webp", "name": "partner", "alt": "National Partner"},
      "country_sponsor_logo": {"url": "https://example.com/sponsor.webp", "name": "sponsor", "alt": "Sponsor"},
      "unicef_logo": {"url": "https://example.com/unicef.webp", "name": "unicef", "alt": "UNICEF"},
      "field_2_0_branding": {"url": "https://example.com/branding.webp", "name": "branding", "alt": "Branding"},
      "languages": [
        {
          "name": "Bangladesh",
          "displayName": "বাংলা",
          "languageCode": "bn",
          "locale": "bn_BD",
          "luxonLocale": "bn",
          "pluralShow": "1",
          "content_toggle": "1"
        }
      ]
    },
    {
      "CountryID": "126",
      "name": "Rest of the world",
      "displayName": "Rest of the world",
      "country_email": "",
      "app_name": "Bebbo",
      "content_toggle": "",
      "all_logos": "...",
      "country_national_partner": {"url": "", "name": "", "alt": ""},
      "country_sponsor_logo": {"url": "", "name": "", "alt": ""},
      "unicef_logo": {"url": "", "name": "", "alt": ""},
      "field_2_0_branding": {"url": "", "name": "", "alt": ""},
      "languages": [
        {"name": "Rest of the world", "displayName": "English", "languageCode": "en", "locale": "en_US", "luxonLocale": "en", "pluralShow": "1"},
        {"name": "Rest of the world", "displayName": "Русский", "languageCode": "ru", "locale": "ru_RU", "luxonLocale": "ru", "pluralShow": "1"}
      ]
    }
  ]
}
```

> `CountryID 131` is filtered out. `CountryID 126` gets `displayName` (absent on other groups), hardcoded `en`+`ru` languages (without per-language `content_toggle`), and is always moved to the end of the array. See [§5.5](#55-country-groups) for full key details.

### 5.4 Course & Quiz

#### `/v2/api/course/%`

Full response shape (standard envelope + all data keys):

```json
{
  "status": 200,
  "total": 5,
  "langcode": "en",
  "datetime": "2026-06-17 12:00",
  "data": [
    {
      "id": 123,
      "title": "Parenting Skills",
      "created_at": "Wed, 06/15/2026 - 10:00",
      "updated_at": "Mon, 06/16/2026 - 14:30",
      "course_description": "<p>Course overview HTML</p>",
      "course_category": [7, 8],
      "child_age": [10, 20, 30],
      "cover_image": {"url": "https://example.com/image.webp", "name": "cover", "alt": "Cover"},
      "course_duration": 30,
      "course_expiry": "2027-06-15",
      "target_audience": [5, 6],
      "course_link": "https://example.com/course",
      "module_numbering": "1.1",
      "number_of_modules": 3,
      "module_locked": false,
      "certificate": {"url": "https://example.com/cert.webp", "name": "cert", "alt": "Certificate"},
      "final_assessment": 456,
      "feedback_required": true,
      "feedback_question": ["How was the course?", "Would you recommend it?"],
      "love_count": 50,
      "read_count": 100,
      "licensed": 1,
      "module": [
        {
          "module_title": "Introduction to Responsive Caregiving",
          "content_numbering": "1.1",
          "optional_module": false,
          "course_content": [101, 102, 103],
          "resource_file": {"url": "https://example.com/handout", "name": "Handout"},
          "resource_file_internal": {"url": "https://example.com/guide.pdf", "name": "Facilitator Guide"}
        }
      ]
    }
  ]
}
```

**Course top-level keys:**

| Key | Type | Source / Notes |
|-----|------|---------------|
| `id` | int | Node ID (`castToInt`) |
| `title` | string | Node title (`decodeHtmlEntities`) |
| `created_at` | string | Formatted creation timestamp (passthrough) |
| `updated_at` | string | Formatted last-modified timestamp (passthrough) |
| `course_description` | string | HTML body from `field_description` (passthrough) |
| `course_category` | int[] | Taxonomy term IDs (`toIntArray`) |
| `child_age` | int[] | Child-age term IDs (`toIntArray`) |
| `cover_image` | {url,name,alt} | WebP image via `parseViewCoverImage` |
| `course_duration` | int | Duration in minutes (`castToInt`) |
| `course_expiry` | string | Expiry date (passthrough) |
| `target_audience` | int[] | Audience term IDs (`toIntArray`) |
| `course_link` | string | External course URL (passthrough) |
| `module_numbering` | string | Numbering style from `field_module_numbering_style` (passthrough) |
| `number_of_modules` | int | Module count (`castToInt`) |
| `module_locked` | bool | Must complete modules in order (`castToBool`) |
| `certificate` | {url,name,alt} | Certificate image via `parseViewCoverImage` |
| `final_assessment` | int | Assessment quiz node ID (`castToInt`, 0 if none) |
| `feedback_required` | bool | Whether post-course feedback is required (`castToBool`) |
| `feedback_question` | string[] | Feedback question prompts (`toStringArray`, raw output) |
| `love_count` | int | Like/love count (`castToInt`) |
| `read_count` | int | Read count (`castToInt`) |
| `licensed` | int | Licensed content flag (`castToInt`) |
| `module` | array | Course modules — see below |

> Note: course does **not** have the common `type` passthrough key (the View display omits `node_field_data.type`).

**Module object** (each element in `module[]`, sourced from `courses_module` nodes via `field_course_modules`, language-aware, delta-ordered):

| Key | Type | Description |
|-----|------|-------------|
| `module_title` | string | Module title (`field_module_title`) |
| `content_numbering` | string | Numbering/labeling style (`field_numbering_style`) |
| `optional_module` | bool | Whether module is optional (`field_optional_module`) |
| `course_content` | int[] | Referenced content node IDs (`field_course_content`) |
| `resource_file` | {url,name} \| null | External link resource (`field_resource_file_external`); `null` if not set |
| `resource_file_internal` | {url,name} \| null | Internal document media (`field_resource_file_internal`); `null` if not set |

---

#### `/v2/api/quiz/%`

Full response shape:

```json
{
  "status": 200,
  "total": 3,
  "langcode": "en",
  "datetime": "2026-06-17 12:00",
  "data": [
    {
      "id": 789,
      "type": "Quiz",
      "title": "Module 1 Assessment",
      "created_at": "Wed, 06/15/2026 - 10:00",
      "updated_at": "Mon, 06/16/2026 - 14:30",
      "instructions": "<p>Answer all questions to complete the assessment.</p>",
      "passing_score": 70,
      "number_of_questions": 5,
      "quiz_type": "assessment",
      "licensed": 1,
      "questions": [
        {
          "type": "multiple_choice",
          "question": "What is responsive caregiving?",
          "image": {"url": "https://example.com/q1.webp", "name": "q1", "alt": "Question image"},
          "answers": [
            {"answer": "Reacting to a child's cues", "correct_answer": true},
            {"answer": "Ignoring tantrums", "correct_answer": false}
          ],
          "explanation": "Responsive caregiving means noticing and responding to a child's signals."
        }
      ]
    }
  ]
}
```

**Quiz top-level keys:**

| Key | Type | Source / Notes |
|-----|------|---------------|
| `id` | int | Node ID (`castToInt`) |
| `type` | string | Content-type label (passthrough from `node_field_data.type`) |
| `title` | string | Node title (`decodeHtmlEntities`) |
| `created_at` | string | Formatted creation timestamp (passthrough) |
| `updated_at` | string | Formatted last-modified timestamp (passthrough) |
| `instructions` | string | HTML instructions from `field_instructions` (passthrough) |
| `passing_score` | int | Minimum passing score (`castToInt`) |
| `number_of_questions` | int | Total question count (`castToInt`) |
| `quiz_type` | string | Quiz type from `field_quiz_type` (passthrough) |
| `licensed` | int | Licensed content flag (`castToInt`) |
| `questions` | array | Quiz questions — see below |

**Question object** (each element in `questions[]`, sourced from `quiz_questions` nodes via `field_quiz_questions`, language-aware, delta-ordered):

| Key | Type | Description |
|-----|------|-------------|
| `type` | string | Question type (`field_question_type`, e.g. `multiple_choice`) |
| `question` | string | Question text (`field_question`) |
| `image` | {url,name,alt} | Question image; `{"url":"","name":"","alt":""}` if not set |
| `answers` | array | Answer options from `field_answers` (empty answers filtered out) |
| `explanation` | string | Explanation text (`field_explanation`) |

**Answer object** (each element in `answers[]`):

| Key | Type | Description |
|-----|------|-------------|
| `answer` | string | Answer text |
| `correct_answer` | bool | Whether this is the correct answer |

### 5.5 Country-groups

`transformCountryGroups` filters out `CountryID 131`, dedups by CountryID, parses media, builds language arrays, and removes raw `langcode`. Langcode is forced to `en` for this endpoint. `CountryID 126` ("Rest of the world") gets hardcoded `en`+`ru` languages and is moved to the end.

**Country-group object keys:**

| Key | Type | Notes |
|-----|------|-------|
| `CountryID` | string | Group entity ID |
| `name` | string | Country/group name |
| `country_email` | string | Contact email (passthrough) |
| `app_name` | string | App display name (passthrough) |
| `content_toggle` | string | Default-language content toggle (overridden by transform from Group entity) |
| `all_logos` | string | Raw 2.0 branding field (passthrough) |
| `country_national_partner` | {url,name,alt} | National partner logo (parsed from view embed) |
| `country_sponsor_logo` | {url,name,alt} | Sponsor logo (parsed from view embed) |
| `unicef_logo` | {url,name,alt} | UNICEF logo (parsed from view embed) |
| `field_2_0_branding` | {url,name,alt} | 2.0 branding image (parsed from view embed) |
| `languages` | array | Language objects — see below |
| `displayName` | string | **Only on CountryID 126** ("Rest of the world"); absent on other groups |

**Language object** (each element in `languages[]`, weight-sorted, weight removed):

| Key | Type | Notes |
|-----|------|-------|
| `name` | string | Country name |
| `displayName` | string | Language display name (from `custom_language_data.custom_language_name_local`) |
| `languageCode` | string | BCP-47 code (`en`, `bn`, etc.) |
| `locale` | string | POSIX locale (`en_US`, `bn_BD`, etc.) |
| `luxonLocale` | string | Luxon locale string |
| `pluralShow` | string | Plural display flag |
| `content_toggle` | string | Per-language content toggle (present on regular groups only, absent on CountryID 126) |

### 5.6 ETag / conditional requests

`BebboSerializer::checkEtag()` enables conditional requests on **5 specific V2 displays only**: `articles_rest_export`, `video_article_rest_export`, `activities_rest_export`, `faq_rest_export`, `basic_page_rest_export`. All other V2 displays skip ETag processing.

1. Computes an ETag from a **lightweight SQL fingerprint**: `MD5(bundle + MAX(changed) + COUNT(*) + queryString)` queried against `node_field_data` — **not** a hash of the response body.
2. Stores the ETag on the request attributes (`bebbo_etag`).
3. If `If-None-Match` request header matches → sets `bebbo_etag_match = true`.
4. `EtagResponseSubscriber` (priority 0, `KernelEvents::RESPONSE`) reads those attributes: on match, replaces the response with **304 Not Modified** (empty body); otherwise sets the `ETag` response header.

The mobile app should send `If-None-Match: "<etag>"` to skip re-downloading unchanged content.

### 5.7 Body image processing (V2)

`BodyImageProcessor` (`bebbo_serializer.body_image_processor`) populates embedded images at **presave time** (not request time):

1. Renders the body HTML through Drupal's text format pipeline (resolves `<drupal-media>` → `<img>`).
2. Extracts all image URLs from the rendered body.
3. Converts internal image URLs to WebP via image style with `itok` security tokens.
4. Returns a `string[]` of extracted URLs; the calling code stores this into `field_embedded_images`.

V1 did equivalent work at request time via DOMDocument parsing per row (N+1 entity loads). V2 front-loads this to content save, so the API serializer reads a pre-computed field.

---

## 6. Query Parameters

Configured as **Views exposed filters** with `query_param` keys (verified in the view configs). They apply to the article-family endpoints (V1 and V2):

| Param | Type | Meaning | V1 displays | V2 displays |
|-------|------|---------|-------------|-------------|
| `datetime` | ISO timestamp | Return rows changed after this time (`changed` filter) | 7 | 5 |
| `childAge` | int (term id) | Filter by child-age term | 2 | 1 |
| `childGender` | int (term id) | Filter by child gender | 2 | 1 |
| `parentGender` | int (term id) | Filter by parent gender | 1 | 1 |
| `category` | int (term id) | Filter by content category | 1 | 1 |
| `typeArticle` | int (term id) | Filter by type-of-article | 1 | 1 |
| `pre_populated` | `0`/`1` | Filter pre-populated content | 3 | 3 |
| `pregnancy` | `true` | Include Pregnancy child-age term (serializer/`hook_views_query_alter`, not an exposed filter) | articles/taxonomies | `articles_rest_export` + child_age taxonomy |

> `pregnancy` is **not** a Views exposed filter — V2 injects the Pregnancy term id into the `field_child_age` IN-condition via `bebbo_serializer`'s `hook_views_query_alter()` (only on `bebbo_v2_apis` / `articles_rest_export`), and `queryChildAgeTerms()` excludes the Pregnancy term unless `pregnancy=true`.

---

## 7. Taxonomy & Vocabulary

Both versions return **keyed objects**, not flat arrays.

- **Vocabularies** (`/api/vocabularies/%`, `/v2/api/vocabularies/%`): `{ "<machine_name>": {"name": "Label"} }`. V2 prefers the translated label and **skips `keywords`**.
- **Taxonomies** (`/api/taxonomies/%/%`, `/v2/api/taxonomies/%/%`): `{ "<vocab>": [ term, … ] }`. V2 per-vocab term shapes (`transformTaxonomies`):
  - `growth_period`: `{id,name,vaccination_opens(int),short_name,unique_name}`
  - `child_age`: `{id,name,days_from,days_to,buffers_days,age_bracket(int[])}`
  - `growth_introductory`: `{id,name,body,days_from,days_to}`
  - `chatbot_subcategory`: `{id,name,parent_category_id(int),unique_name}`
  - `category`: `{id,name,unique_name,field_type_of_article}`
  - unique-name vocabs: `{id,name,unique_name}`
  - basic vocabs: `{id,name}`
  - `keywords` is always skipped.

V1 (`custom_serialization`) produces an equivalent keyed map; V2 envelope omits `total`.

---

## 8. Standard Deviation

Two distinct paths — **do not conflate**:

| Path | View / style | Output |
|------|--------------|--------|
| `/api/standard_deviation/%` (V1) | `views.view.articles.yml` / **`pb_custom_standard_deviation`** | `{status:200, [langcode], data:{…}}` |
| `/v2/api/standard_deviation/%` (V2) | `views.view.bebbo_v2_apis.yml` / `bebbo_serializer` | `{status,langcode,data}` |

Both build nested growth-type structures keyed `height_for_age` and `weight_for_height` (the latter renamed from the internal `height_for_weight`), bucketed by child-age ranges, with SD-label keys such as `goodText`, `warrningSmallLengthText`, `emergencySmallLengthText`, `warrningBigLengthText` (height_for_age) and `goodText`, `warrningSmallHeightText`, `emergencySmallHeightText`, `warrningBigHeightText`, `emergencyBigHeightText` (weight_for_height). Each SD entry carries `child_age` (int[]) and per-label objects `{articleID:int[], name, text}`. Empty results → `{status:204,"message":"No Records Found"}`; bad langcode → `{status:400}`.

> Not part of the mobile API: the admin `standard-deviation` page and the `taxonomy-*-export-standard-deviation/%` CSV exports are separate views.

---

## 9. Force-Update REST Resource

**`GET /api/check-update/{country}`** — plugin `custom_rest_resource` (`pb_custom_rest_api`), config `rest.resource.custom_rest_resource.yml`.

| Property | Value |
|----------|-------|
| Method | `GET` only |
| Format | `json` only |
| **Authentication** | **`basic_auth` only** (no `cookie` provider) — a logged-in browser session will be rejected; use basic-auth or anonymous basic-auth client |
| `{country}` | country **group entity ID** (matched against `countries_id`) |
| Source | latest row per `(countries_id, update_type)` in `forcefull_check_update_api` |

Response (both record types present):
```json
{
  "status": 200,
  "flag": 1,
  "updated_at": "1609459200",
  "content_update": { "flag": 1, "updated_at": "1609459200" },
  "app_update": {
    "flag": 0,
    "updated_at": "1712345678",
    "google_play_url": "https://play.google.com/store/apps/details?id=...",
    "app_store_url": "https://apps.apple.com/app/..."
  }
}
```
- Top-level `flag`/`updated_at` mirror `content_update` for **backward compatibility**; both are `null` if no content-update record.
- `app_update` adds `google_play_url`/`app_store_url` (empty string coerced to `null`).
- When **both** record types are missing: body `{status:204,"message":"No Records Found"}` (HTTP status remains 200 — `204` is a body field, not the HTTP code).

### 9.1 `forcefull_check_update_api` table schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | serial PK | Auto-increment |
| `uuid` | varchar(255) | UUID of admin user who triggered the update |
| `created_at` | varchar(255) | Timestamp of creation |
| `countries_id` | varchar(255) | Country group entity ID |
| `flag_status` | varchar(255) | `0` or `1` |
| `update_type` | varchar(50) | `content_update` or `app_update` |
| `google_play_url` | varchar(512), nullable | Play Store URL (app_update only) |
| `app_store_url` | varchar(512), nullable | App Store URL (app_update only) |

Populated via admin form at `/admin/config/parent-buddy/forcefull-update-check` (`pb_custom_form` module, `CustomForm`). Each form submission inserts a new row.

> This path is also in the `bebbo_api_security` default protected set (`/api/check-update/`), so JWT enforcement can apply on top of basic-auth when enabled.

---

## 10. JSON:API

Core `jsonapi` + `jsonapi_extras`, both enabled.

| Setting | Value | Source |
|---------|-------|--------|
| Path prefix | `jsonapi` (→ `/jsonapi/*`) | `jsonapi_extras.settings.yml` |
| **Read-only** | **`true`** | `jsonapi.settings.yml` (`read_only: true`) |
| `include_count` | `true` | `jsonapi_extras.settings.yml` |
| `default_disabled` | `false` (resources exposed by default) | `jsonapi_extras.settings.yml` |
| `validate_configuration_integrity` | `false` | `jsonapi_extras.settings.yml` |
| Resource overrides | **none** (`0` `jsonapi_resource_config.*` files) | repo scan |

JSON:API exposes Drupal entities at `/jsonapi/{entity_type}/{bundle}` for **reads only** (write methods are disabled globally). No per-resource overrides are configured, so behavior is core defaults plus the global settings above.

---

## 11. Event Subscriber Pipeline (request/response lifecycle)

API requests pass through these event subscribers in priority order:

### Request phase (`KernelEvents::REQUEST`)

| Priority | Subscriber | Module | Effect |
|----------|-----------|--------|--------|
| **1000** | `RequestFormatSubscriber` | `bebbo_serializer` | Registers `bebbo_json` format → `application/json` MIME type. Prevents 406 errors on V2 routes. |
| **300** | `ApiSecuritySubscriber` | `bebbo_api_security` | JWT enforcement on protected paths (`/v2/api/*`, `/api/check-update/*`). Mode: disabled/grace_period/enforced. |

### Response phase (`KernelEvents::RESPONSE`)

| Priority | Subscriber | Module | Effect |
|----------|-----------|--------|--------|
| **0** | `EtagResponseSubscriber` | `bebbo_serializer` | Sets ETag header; converts to 304 on matching `If-None-Match`. |
| **-10** | `ApiResponseSubscriber` | `language_visibility_control` | Filters languages in **V1** `/api/country-groups` responses only. V2 handles filtering inside `BebboSerializer::transformCountryGroups()`. |

---

## 12. Caching Layers

### V2 (`BebboSerializer` + `CustomSerializerHelper`)

| Layer | Scope | Details |
|-------|-------|---------|
| **ETag / 304** | Per-endpoint | Avoids re-transmitting unchanged data (see §5.6) |
| **Request-level static cache** | Per HTTP request | Media entities, file entities, ConfigurableLanguage, Group entities, image style, country groups, taxonomy terms (keyed by `{vocabulary}:{langcode}`) — all loaded once per request, not persisted |
| **Batch entity loading** | Per request | `loadMediaBatch`, `loadFileBatch`, `loadTaxonomyTermsBatch`, `loadGroupsBatch` — single query for multiple IDs, eliminates N+1 |
| **Direct DB queries** | Per request | `getFileUrisBatch`, `getMediaAltTextBatch`, `getNodeTitlesBatch`, `getTaxonomyTermsBatch`, `getLanguageDataBatch` — skip entity API overhead |
| **Persistent cache** | Across requests | Vimeo API responses: 24h (`custom_serialization:vimeo:{id}`); failed Vimeo: 5min; Pregnancy term ID: permanent (tag: `taxonomy_term_list:child_age`) |

### V1 (`CustomSerializer`)

Same `CustomSerializerHelper` service, same batch loading and caching. Media resolution via `customMediaFormatter()` is per-row (no batching), which is the main V1→V2 performance improvement.

---

## 13. Other Public Routes

These are not APIs but are publicly accessible routes served by custom modules:

| Path | Module | Purpose |
|------|--------|---------|
| `/downloadapp.html` | `pb_custom_form` | App store redirect page (`_access: TRUE`) |
| `/share/{param1}/{param2}/{param3}` | `pb_custom_form` | Mobile app deep-link share controller (permission: `manage mobile javascript`) |

---

## 14. Related Documentation

| Topic | Document |
|-------|----------|
| Device attestation / JWT enforcement on the API | `API_SECURITY.md` |
| System architecture & where APIs sit | `ARCHITECTURE.md` |
| Serializer modules & custom-module detail | `MODULES.md` |
| Engagement counts (`field_like_count`/`field_read_count`) sync | `MODULES.md` (pb_content_analytics) |
