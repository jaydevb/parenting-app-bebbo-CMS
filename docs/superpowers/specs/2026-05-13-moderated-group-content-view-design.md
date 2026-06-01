# Moderated Group Content View — Design Spec

**Date**: 2026-05-13
**View ID**: `duplicate_of_moderated_group_content`
**Path**: `/group/{group_id}/moderated`
**Branch**: `feature/moderated-group-content-view`

## Problem

The existing "Moderated group content" View at `/group/{gid}/moderated` produces no results. Root cause: the View uses a `group_content_to_entity_reverse` relationship to join nodes to groups, but nodes are NOT stored as group content in this project. Only user memberships exist in the `group_content` table (type `country-group_membership`). The JOIN returns zero rows.

Additionally:
- "Published" moderation state is excluded from the filter
- No "Updated by" filter exists
- No "Updated by" or Language visible columns

## Data Model

Nodes relate to groups indirectly through language:

```
Group entity
  └── field_language (list_string, multi-value)
        e.g., Albania → ['al-sq'], Kosovo → ['xk-sq', 'xk-rs']

Node revisions
  └── langcode (matches group's field_language values)
```

Group members are linked via `group_content` (type `country-group_membership`).

## Solution

Rebuild the View config and add `hook_views_query_alter` + `hook_form_views_exposed_form_alter` in `group_country_field.module` to:
1. Filter content by the group's languages (replacing broken group_content relationship)
2. Ensure only the latest revision per node per language is shown
3. Populate "Updated by" dropdown with group members

## View Config Changes

### Remove
- `group_content` relationship (`group_content_to_entity_reverse` plugin)
- `gid` contextual argument (depended on broken relationship)
- `latest_translation_affected_revision` filter (replaced by query alter logic)

### Modify
- **Moderation state filter**: add `group_workflow-published` to values
- **`nid` relationship**: simplify — join `node_field_revision.nid` → `node_field_data.nid` (standard relationship, no group_content dependency)

### Add
- **Raw URL argument**: extract group ID from URL position (path component index for `{group_id}` in `/group/{group_id}/moderated`)
- **Language column**: `node_field_revision.langcode` — visible in results table
- **"Updated by" column**: `users_field_data.name` via `revision_uid` relationship — visible in results table
- **"Updated by" exposed filter**: identifier `revision_uid`, select dropdown (populated via form alter hook)
- **`revision_uid` relationship**: `node_field_revision.revision_uid` → `users_field_data` (for Updated by column)

### Keep
- Title field (linked to node)
- Content type field (via nid relationship)
- Author field (via uid relationship — original creator)
- Moderation state field
- Updated (changed) field — default sort DESC
- Operations field
- Title, Content type, Language, Moderation state exposed filters
- Table style, pager (50 items/page)
- Group permission access (`view latest version`)
- Tab menu placement at `/group/%group/moderated`
- View machine name: `duplicate_of_moderated_group_content`

## Result Table Columns

| # | Column | Source | Sortable |
|---|--------|--------|----------|
| 1 | Title | `node_field_revision.title` (linked to node) | Yes |
| 2 | Content type | `node_field_data.type` | Yes |
| 3 | Language | `node_field_revision.langcode` | Yes |
| 4 | Author | `users_field_data.name` via uid | No |
| 5 | Updated by | `users_field_data.name` via revision_uid | No |
| 6 | Moderation state | `node_field_revision.moderation_state` | Yes |
| 7 | Updated | `node_field_revision.changed` (default DESC) | Yes |
| 8 | Operations | `node_revision.operations` | No |

## Exposed Filters

| Filter | Type | Notes |
|--------|------|-------|
| Title | Text (contains) | Existing |
| Content type | Select (bundle) | Existing |
| Language | Select (language) | Existing |
| Moderation state | Select (moderation_state_filter) | Add `published` to values |
| Updated by | Select (user) | New — populated with group members via form alter |

## Hook Implementation

### File: `docroot/modules/custom/group_country_field/group_country_field.module`

### `group_country_field_views_query_alter()`

Targets view ID `duplicate_of_moderated_group_content`:

1. **Get group from route**: `\Drupal::routeMatch()->getParameter('group')` — returns Group entity or ID
2. **Get group languages**: load `field_language` values from group entity
3. **Filter by language**: add `WHERE node_field_revision.langcode IN (:group_languages[])` condition
4. **Latest revision per node per language**: add subquery condition ensuring each row is the latest `vid` for its `nid + langcode` combination:
   ```sql
   node_field_revision.vid = (
     SELECT MAX(nfr2.vid) FROM node_field_revision nfr2
     WHERE nfr2.nid = node_field_revision.nid
     AND nfr2.langcode = node_field_revision.langcode
   )
   ```
5. **Updated by filter**: if `revision_uid` exposed filter value is present, add `WHERE node_field_revision.revision_uid = :uid` condition

### `group_country_field_form_views_exposed_form_alter()`

Targets form ID containing `duplicate-of-moderated-group-content`:

1. **Get group from route**
2. **Load group memberships**: `\Drupal::service('group.membership_loader')->loadByGroup($group)`
3. **Build user options**: map membership entities to `uid => username` pairs
4. **Populate dropdown**: set `$form['revision_uid']['#options']` with "- Any -" + member list
5. **Change widget**: ensure it's a select element (not textfield)

## Access Control

- View-level: Group permission `view latest version` (unchanged)
- Tab visibility: hidden for `reviewer` role via `pb_custom_field_local_tasks_alter()` (unchanged, line 997)

## Files Changed

1. `config/sync/views.view.duplicate_of_moderated_group_content.yml` — rebuilt View config
2. `docroot/modules/custom/group_country_field/group_country_field.module` — add two hooks

## Acceptance Criteria Mapping

| Criteria | How Addressed |
|----------|---------------|
| Shows revisions in all languages linked to country group | Query alter filters by group's `field_language` values |
| Filter by Title | Existing exposed filter (kept) |
| Filter by Content type | Existing exposed filter (kept) |
| Filter by Language | Existing exposed filter (kept) |
| Filter by Moderation state (all states) | Add `published` to filter values |
| Filter by username (Updated by) | New exposed filter populated with group members |
| Username filter shows revisions by that user | Query alter adds `revision_uid` condition |
| Combination of filters works | All filters use AND logic in same filter group |
| Results sorted by most recent | Default sort `changed` DESC (kept) |
