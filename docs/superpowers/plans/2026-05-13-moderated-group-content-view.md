# Moderated Group Content View — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the broken "Moderated group content" View at `/group/{gid}/moderated` so it shows content revisions filtered by the group's languages, with all moderation states and an "Updated by" filter.

**Architecture:** Rebuild the View config to remove the broken `group_content_to_entity_reverse` relationship and add new columns/filters. Implement `hook_views_query_alter` and `hook_form_views_exposed_form_alter` in `group_country_field.module` to filter nodes by the group's languages, enforce latest-revision-per-node-per-language, and populate the "Updated by" dropdown with group members.

**Tech Stack:** Drupal 10, Views API, Group 1.x, Content Moderation, YAML config

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Modify | `config/sync/views.view.duplicate_of_moderated_group_content.yml` | View config: fields, filters, relationships, argument, display |
| Modify | `docroot/modules/custom/group_country_field/group_country_field.module` | Query alter (language filter, latest revision, Updated by) + form alter (Updated by dropdown) |

---

### Task 1: Rebuild the View Config YAML

**Files:**
- Modify: `config/sync/views.view.duplicate_of_moderated_group_content.yml`

This is the largest task. We rebuild the entire View config to:
- Remove broken `group_content` relationship, broken `gid` argument, broken `latest_translation_affected_revision` filter
- Keep `nid` relationship (node_field_revision → node_field_data) and `uid` relationship (revision author)
- Add `revision_uid` relationship for "Updated by" column
- Add Language column, Updated by column
- Add `published` to moderation state filter values
- Add `revision_uid` exposed filter (textfield in config, converted to select by form alter hook)
- Use a raw numeric argument from URL position 1 for group ID (0-indexed: `/group/{1}/moderated`)

- [ ] **Step 1: Replace the entire View config YAML**

Replace the full contents of `config/sync/views.view.duplicate_of_moderated_group_content.yml` with:

```yaml
uuid: c3eee3e3-019b-4fc1-9d10-6e0a96ddc896
langcode: en
status: true
dependencies:
  config:
    - workflows.workflow.group_workflow
  module:
    - content_moderation
    - group
    - node
    - user
_core:
  default_config_hash: meD9AMTsOqOQ86qYobfOYg-RiIlqtvZkmW791P-G3Bw
id: duplicate_of_moderated_group_content
label: 'Moderated group content'
module: views
description: 'Find and moderate group content.'
tag: ''
base_table: node_field_revision
base_field: vid
display:
  default:
    id: default
    display_title: Master
    display_plugin: default
    position: 0
    display_options:
      title: 'Moderated content'
      fields:
        nid:
          id: nid
          table: node_field_data
          field: nid
          relationship: nid
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: nid
          plugin_id: field
          label: ''
          exclude: true
          alter:
            alter_text: false
            text: ''
            make_link: false
            path: ''
            absolute: false
            external: false
            replace_spaces: false
            path_case: none
            trim_whitespace: false
            alt: ''
            rel: ''
            link_class: ''
            prefix: ''
            suffix: ''
            target: ''
            nl2br: false
            max_length: 0
            word_boundary: true
            ellipsis: true
            more_link: false
            more_link_text: ''
            more_link_path: ''
            strip_tags: false
            trim: false
            preserve_tags: ''
            html: false
          element_type: ''
          element_class: ''
          element_label_type: ''
          element_label_class: ''
          element_label_colon: false
          element_wrapper_type: ''
          element_wrapper_class: ''
          element_default_classes: true
          empty: ''
          hide_empty: false
          empty_zero: false
          hide_alter_empty: true
          click_sort_column: value
          type: number_integer
          settings:
            thousand_separator: ''
            prefix_suffix: false
          group_column: value
          group_columns: {  }
          group_rows: true
          delta_limit: 0
          delta_offset: 0
          delta_reversed: false
          delta_first_last: false
          multi_type: separator
          separator: ', '
          field_api_classes: false
        title:
          id: title
          table: node_field_revision
          field: title
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: title
          plugin_id: field
          label: Title
          exclude: false
          alter:
            alter_text: false
            text: ''
            make_link: true
            path: '/node/{{ nid }}'
            absolute: false
            external: false
            replace_spaces: false
            path_case: none
            trim_whitespace: false
            alt: ''
            rel: ''
            link_class: ''
            prefix: ''
            suffix: ''
            target: ''
            nl2br: false
            max_length: 0
            word_boundary: false
            ellipsis: false
            more_link: false
            more_link_text: ''
            more_link_path: ''
            strip_tags: false
            trim: false
            preserve_tags: ''
            html: false
          element_type: ''
          element_class: ''
          element_label_type: ''
          element_label_class: ''
          element_label_colon: true
          element_wrapper_type: ''
          element_wrapper_class: ''
          element_default_classes: true
          empty: ''
          hide_empty: false
          empty_zero: false
          hide_alter_empty: true
          click_sort_column: value
          type: string
          settings:
            link_to_entity: false
          group_column: value
          group_columns: {  }
          group_rows: true
          delta_limit: 0
          delta_offset: 0
          delta_reversed: false
          delta_first_last: false
          multi_type: separator
          separator: ', '
          field_api_classes: false
        type:
          id: type
          table: node_field_data
          field: type
          relationship: nid
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: type
          plugin_id: field
          label: 'Content type'
          exclude: false
          alter:
            alter_text: false
            text: ''
            make_link: false
            path: ''
            absolute: false
            external: false
            replace_spaces: false
            path_case: none
            trim_whitespace: false
            alt: ''
            rel: ''
            link_class: ''
            prefix: ''
            suffix: ''
            target: ''
            nl2br: false
            max_length: 0
            word_boundary: true
            ellipsis: true
            more_link: false
            more_link_text: ''
            more_link_path: ''
            strip_tags: false
            trim: false
            preserve_tags: ''
            html: false
          element_type: ''
          element_class: ''
          element_label_type: ''
          element_label_class: ''
          element_label_colon: true
          element_wrapper_type: ''
          element_wrapper_class: ''
          element_default_classes: true
          empty: ''
          hide_empty: false
          empty_zero: false
          hide_alter_empty: true
          click_sort_column: target_id
          type: entity_reference_label
          settings:
            link: false
          group_column: target_id
          group_columns: {  }
          group_rows: true
          delta_limit: 0
          delta_offset: 0
          delta_reversed: false
          delta_first_last: false
          multi_type: separator
          separator: ', '
          field_api_classes: false
        langcode:
          id: langcode
          table: node_field_revision
          field: langcode
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: langcode
          plugin_id: field
          label: Language
          exclude: false
          alter:
            alter_text: false
            text: ''
            make_link: false
            path: ''
            absolute: false
            external: false
            replace_spaces: false
            path_case: none
            trim_whitespace: false
            alt: ''
            rel: ''
            link_class: ''
            prefix: ''
            suffix: ''
            target: ''
            nl2br: false
            max_length: 0
            word_boundary: true
            ellipsis: true
            more_link: false
            more_link_text: ''
            more_link_path: ''
            strip_tags: false
            trim: false
            preserve_tags: ''
            html: false
          element_type: ''
          element_class: ''
          element_label_type: ''
          element_label_class: ''
          element_label_colon: true
          element_wrapper_type: ''
          element_wrapper_class: ''
          element_default_classes: true
          empty: ''
          hide_empty: false
          empty_zero: false
          hide_alter_empty: true
          click_sort_column: value
          type: language
          settings:
            link_to_entity: false
            native_language: false
          group_column: value
          group_columns: {  }
          group_rows: true
          delta_limit: 0
          delta_offset: 0
          delta_reversed: false
          delta_first_last: false
          multi_type: separator
          separator: ', '
          field_api_classes: false
        name:
          id: name
          table: users_field_data
          field: name
          relationship: uid
          group_type: group
          admin_label: ''
          entity_type: user
          entity_field: name
          plugin_id: field
          label: Author
          exclude: false
          alter:
            alter_text: false
            text: ''
            make_link: false
            path: ''
            absolute: false
            external: false
            replace_spaces: false
            path_case: none
            trim_whitespace: false
            alt: ''
            rel: ''
            link_class: ''
            prefix: ''
            suffix: ''
            target: ''
            nl2br: false
            max_length: 0
            word_boundary: true
            ellipsis: true
            more_link: false
            more_link_text: ''
            more_link_path: ''
            strip_tags: false
            trim: false
            preserve_tags: ''
            html: false
          element_type: ''
          element_class: ''
          element_label_type: ''
          element_label_class: ''
          element_label_colon: true
          element_wrapper_type: ''
          element_wrapper_class: ''
          element_default_classes: true
          empty: ''
          hide_empty: false
          empty_zero: false
          hide_alter_empty: true
          click_sort_column: value
          type: user_name
          settings:
            link_to_entity: true
          group_column: value
          group_columns: {  }
          group_rows: true
          delta_limit: 0
          delta_offset: 0
          delta_reversed: false
          delta_first_last: false
          multi_type: separator
          separator: ', '
          field_api_classes: false
        revision_uid_name:
          id: revision_uid_name
          table: users_field_data
          field: name
          relationship: revision_uid
          group_type: group
          admin_label: ''
          entity_type: user
          entity_field: name
          plugin_id: field
          label: 'Updated by'
          exclude: false
          alter:
            alter_text: false
            text: ''
            make_link: false
            path: ''
            absolute: false
            external: false
            replace_spaces: false
            path_case: none
            trim_whitespace: false
            alt: ''
            rel: ''
            link_class: ''
            prefix: ''
            suffix: ''
            target: ''
            nl2br: false
            max_length: 0
            word_boundary: true
            ellipsis: true
            more_link: false
            more_link_text: ''
            more_link_path: ''
            strip_tags: false
            trim: false
            preserve_tags: ''
            html: false
          element_type: ''
          element_class: ''
          element_label_type: ''
          element_label_class: ''
          element_label_colon: true
          element_wrapper_type: ''
          element_wrapper_class: ''
          element_default_classes: true
          empty: ''
          hide_empty: false
          empty_zero: false
          hide_alter_empty: true
          click_sort_column: value
          type: user_name
          settings:
            link_to_entity: true
          group_column: value
          group_columns: {  }
          group_rows: true
          delta_limit: 0
          delta_offset: 0
          delta_reversed: false
          delta_first_last: false
          multi_type: separator
          separator: ', '
          field_api_classes: false
        moderation_state:
          id: moderation_state
          table: node_field_revision
          field: moderation_state
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          plugin_id: field
          label: 'Moderation state'
          exclude: false
          alter:
            alter_text: false
            text: ''
            make_link: false
            path: ''
            absolute: false
            external: false
            replace_spaces: false
            path_case: none
            trim_whitespace: false
            alt: ''
            rel: ''
            link_class: ''
            prefix: ''
            suffix: ''
            target: ''
            nl2br: false
            max_length: 0
            word_boundary: true
            ellipsis: true
            more_link: false
            more_link_text: ''
            more_link_path: ''
            strip_tags: false
            trim: false
            preserve_tags: ''
            html: false
          element_type: ''
          element_class: ''
          element_label_type: ''
          element_label_class: ''
          element_label_colon: true
          element_wrapper_type: ''
          element_wrapper_class: ''
          element_default_classes: true
          empty: ''
          hide_empty: false
          empty_zero: false
          hide_alter_empty: true
          click_sort_column: value
          type: content_moderation_state
          settings: {  }
          group_column: value
          group_columns: {  }
          group_rows: true
          delta_limit: 0
          delta_offset: 0
          delta_reversed: false
          delta_first_last: false
          multi_type: separator
          separator: ', '
          field_api_classes: false
        changed:
          id: changed
          table: node_field_revision
          field: changed
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: changed
          plugin_id: field
          label: Updated
          exclude: false
          alter:
            alter_text: false
            text: ''
            make_link: false
            path: ''
            absolute: false
            external: false
            replace_spaces: false
            path_case: none
            trim_whitespace: false
            alt: ''
            rel: ''
            link_class: ''
            prefix: ''
            suffix: ''
            target: ''
            nl2br: false
            max_length: 0
            word_boundary: false
            ellipsis: false
            more_link: false
            more_link_text: ''
            more_link_path: ''
            strip_tags: false
            trim: false
            preserve_tags: ''
            html: false
          element_type: ''
          element_class: ''
          element_label_type: ''
          element_label_class: ''
          element_label_colon: true
          element_wrapper_type: ''
          element_wrapper_class: ''
          element_default_classes: true
          empty: ''
          hide_empty: false
          empty_zero: false
          hide_alter_empty: true
          click_sort_column: value
          type: timestamp
          settings:
            date_format: short
            custom_date_format: ''
            timezone: ''
            tooltip:
              date_format: ''
              custom_date_format: ''
            time_diff:
              enabled: false
              future_format: '@interval hence'
              past_format: '@interval ago'
              granularity: 2
              refresh: 60
          group_column: value
          group_columns: {  }
          group_rows: true
          delta_limit: 0
          delta_offset: 0
          delta_reversed: false
          delta_first_last: false
          multi_type: separator
          separator: ', '
          field_api_classes: false
        operations:
          id: operations
          table: node_revision
          field: operations
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          plugin_id: entity_operations
          label: Operations
          exclude: false
          alter:
            alter_text: false
            text: ''
            make_link: false
            path: ''
            absolute: false
            external: false
            replace_spaces: false
            path_case: none
            trim_whitespace: false
            alt: ''
            rel: ''
            link_class: ''
            prefix: ''
            suffix: ''
            target: ''
            nl2br: false
            max_length: 0
            word_boundary: true
            ellipsis: true
            more_link: false
            more_link_text: ''
            more_link_path: ''
            strip_tags: false
            trim: false
            preserve_tags: ''
            html: false
          element_type: ''
          element_class: ''
          element_label_type: ''
          element_label_class: ''
          element_label_colon: true
          element_wrapper_type: ''
          element_wrapper_class: ''
          element_default_classes: true
          empty: ''
          hide_empty: false
          empty_zero: false
          hide_alter_empty: true
          destination: true
      pager:
        type: full
        options:
          offset: 0
          pagination_heading_level: h4
          items_per_page: 50
          total_pages: null
          id: 0
          tags:
            next: 'Next ›'
            previous: '‹ Previous'
            first: '« First'
            last: 'Last »'
          expose:
            items_per_page: false
            items_per_page_label: 'Items per page'
            items_per_page_options: '5, 10, 25, 50'
            items_per_page_options_all: false
            items_per_page_options_all_label: '- All -'
            offset: false
            offset_label: Offset
          quantity: 9
      exposed_form:
        type: basic
        options:
          submit_button: Filter
          reset_button: true
          reset_button_label: Reset
          exposed_sorts_label: 'Sort by'
          expose_sort_order: true
          sort_asc_label: Asc
          sort_desc_label: Desc
      access:
        type: group_permission
        options:
          group_permission: 'view latest version'
      cache:
        type: none
        options: {  }
      empty:
        area_text_custom:
          id: area_text_custom
          table: views
          field: area_text_custom
          relationship: none
          group_type: group
          admin_label: ''
          plugin_id: text_custom
          empty: true
          content: 'No moderated content available.'
          tokenize: false
      sorts: {  }
      arguments:
        group_id:
          id: group_id
          table: views
          field: null_argument
          relationship: none
          group_type: group
          admin_label: 'Group ID from URL'
          plugin_id: 'null'
          default_action: ignore
          exception:
            value: all
            title_enable: false
            title: All
          title_enable: true
          title: '{{ arguments.group_id|placeholder }} moderated content'
          default_argument_type: fixed
          default_argument_options:
            argument: ''
          summary_options:
            base_path: ''
            count: true
            override: false
            items_per_page: 25
          summary:
            sort_order: asc
            number_of_records: 0
            format: default_summary
          specify_validation: false
          validate:
            type: none
            fail: 'not found'
          validate_options: {  }
      filters:
        title:
          id: title
          table: node_field_revision
          field: title
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: title
          plugin_id: string
          operator: contains
          value: ''
          group: 1
          exposed: true
          expose:
            operator_id: title_op
            label: Title
            description: ''
            use_operator: false
            operator: title_op
            operator_limit_selection: false
            operator_list: {  }
            identifier: title
            required: false
            remember: false
            multiple: false
            remember_roles:
              authenticated: authenticated
              anonymous: '0'
              administrator: '0'
          is_grouped: false
          group_info:
            label: ''
            description: ''
            identifier: ''
            optional: true
            widget: select
            multiple: false
            remember: false
            default_group: All
            default_group_multiple: {  }
            group_items: {  }
        type:
          id: type
          table: node_field_data
          field: type
          relationship: nid
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: type
          plugin_id: bundle
          operator: in
          value: {  }
          group: 1
          exposed: true
          expose:
            operator_id: type_op
            label: 'Content type'
            description: ''
            use_operator: false
            operator: type_op
            operator_limit_selection: false
            operator_list: {  }
            identifier: type
            required: false
            remember: false
            multiple: false
            remember_roles:
              authenticated: authenticated
              anonymous: '0'
              administrator: '0'
            reduce: false
          is_grouped: false
          group_info:
            label: ''
            description: ''
            identifier: ''
            optional: true
            widget: select
            multiple: false
            remember: false
            default_group: All
            default_group_multiple: {  }
            group_items: {  }
        langcode:
          id: langcode
          table: node_field_revision
          field: langcode
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: langcode
          plugin_id: language
          operator: in
          value: {  }
          group: 1
          exposed: true
          expose:
            operator_id: langcode_op
            label: Language
            description: ''
            use_operator: false
            operator: langcode_op
            operator_limit_selection: false
            operator_list: {  }
            identifier: langcode
            required: false
            remember: false
            multiple: false
            remember_roles:
              authenticated: authenticated
              anonymous: '0'
              administrator: '0'
            reduce: false
          is_grouped: false
          group_info:
            label: ''
            description: ''
            identifier: ''
            optional: true
            widget: select
            multiple: false
            remember: false
            default_group: All
            default_group_multiple: {  }
            group_items: {  }
        moderation_state_1:
          id: moderation_state_1
          table: node_field_revision
          field: moderation_state
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          plugin_id: moderation_state_filter
          operator: in
          value:
            group_workflow-draft: group_workflow-draft
            group_workflow-sme_review: group_workflow-sme_review
            group_workflow-senior_editor_review: group_workflow-senior_editor_review
            group_workflow-reject: group_workflow-reject
            group_workflow-archive: group_workflow-archive
            group_workflow-review_after_translation: group_workflow-review_after_translation
            group_workflow-published: group_workflow-published
          group: 1
          exposed: true
          expose:
            operator_id: moderation_state_1_op
            label: 'Moderation state'
            description: ''
            use_operator: false
            operator: moderation_state_1_op
            operator_limit_selection: false
            operator_list: {  }
            identifier: moderation_state_1
            required: false
            remember: false
            multiple: false
            remember_roles:
              authenticated: authenticated
              anonymous: '0'
              administrator: '0'
              global_admin: '0'
              editor: '0'
              sme: '0'
              reviewer: '0'
              se: '0'
            reduce: true
          is_grouped: false
          group_info:
            label: 'Moderation state'
            description: null
            identifier: moderation_state_1
            optional: true
            widget: select
            multiple: false
            remember: false
            default_group: All
            default_group_multiple: {  }
            group_items:
              1: {  }
              2: {  }
              3: {  }
        revision_uid:
          id: revision_uid
          table: node_field_revision
          field: revision_uid
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: revision_uid
          plugin_id: standard
          operator: '='
          value: ''
          group: 1
          exposed: true
          expose:
            operator_id: revision_uid_op
            label: 'Updated by'
            description: ''
            use_operator: false
            operator: revision_uid_op
            operator_limit_selection: false
            operator_list: {  }
            identifier: revision_uid
            required: false
            remember: false
            multiple: false
            remember_roles:
              authenticated: authenticated
              anonymous: '0'
              administrator: '0'
          is_grouped: false
          group_info:
            label: ''
            description: ''
            identifier: ''
            optional: true
            widget: select
            multiple: false
            remember: false
            default_group: All
            default_group_multiple: {  }
            group_items: {  }
      filter_groups:
        operator: AND
        groups:
          1: AND
      style:
        type: table
        options:
          grouping: {  }
          row_class: ''
          default_row_class: true
          columns:
            title: title
            type: type
            langcode: langcode
            name: name
            revision_uid_name: revision_uid_name
            moderation_state: moderation_state
            changed: changed
            operations: operations
          default: changed
          info:
            title:
              sortable: true
              default_sort_order: asc
              align: ''
              separator: ''
              empty_column: false
              responsive: ''
            type:
              sortable: true
              default_sort_order: asc
              align: ''
              separator: ''
              empty_column: false
              responsive: ''
            langcode:
              sortable: true
              default_sort_order: asc
              align: ''
              separator: ''
              empty_column: false
              responsive: ''
            name:
              sortable: false
              default_sort_order: asc
              align: ''
              separator: ''
              empty_column: false
              responsive: ''
            revision_uid_name:
              sortable: false
              default_sort_order: asc
              align: ''
              separator: ''
              empty_column: false
              responsive: ''
            moderation_state:
              sortable: true
              default_sort_order: asc
              align: ''
              separator: ''
              empty_column: false
              responsive: ''
            changed:
              sortable: true
              default_sort_order: desc
              align: ''
              separator: ''
              empty_column: false
              responsive: ''
            operations:
              sortable: false
              default_sort_order: asc
              align: ''
              separator: ''
              empty_column: false
              responsive: ''
          override: true
          sticky: true
          summary: ''
          empty_table: true
          caption: ''
          description: ''
      row:
        type: fields
      query:
        type: views_query
        options:
          query_comment: ''
          disable_sql_rewrite: false
          distinct: false
          replica: false
          query_tags: {  }
      relationships:
        nid:
          id: nid
          table: node_field_revision
          field: nid
          relationship: none
          group_type: group
          admin_label: 'Content'
          entity_type: node
          entity_field: nid
          plugin_id: standard
          required: false
        uid:
          id: uid
          table: node_field_revision
          field: uid
          relationship: none
          group_type: group
          admin_label: 'Author'
          entity_type: node
          entity_field: uid
          plugin_id: standard
          required: false
        revision_uid:
          id: revision_uid
          table: node_field_revision
          field: revision_uid
          relationship: none
          group_type: group
          admin_label: 'Revision author'
          entity_type: node
          entity_field: revision_uid
          plugin_id: standard
          required: false
      header: {  }
      footer: {  }
      display_extenders: {  }
    cache_metadata:
      max-age: -1
      contexts:
        - 'languages:language_content'
        - 'languages:language_interface'
        - url
        - url.query_args
        - user.group_permissions
        - 'user.node_grants:view'
      tags:
        - 'config:workflow_list'
  moderated_content:
    id: moderated_content
    display_title: 'Moderated content'
    display_plugin: page
    position: 1
    display_options:
      display_description: ''
      display_extenders: {  }
      path: group/%group/moderated
      menu:
        type: tab
        title: 'Moderated content'
        description: ''
        weight: 26
        expanded: false
        menu_name: main
        parent: ''
        context: '0'
    cache_metadata:
      max-age: -1
      contexts:
        - 'languages:language_content'
        - 'languages:language_interface'
        - url
        - url.query_args
        - user.group_permissions
        - 'user.node_grants:view'
      tags:
        - 'config:workflow_list'
```

Key changes from the original:
- **Removed**: `group_content` relationship, `gid` argument, `latest_translation_affected_revision` filter
- **Added**: `revision_uid` relationship, `langcode` field (visible column), `revision_uid_name` field (Updated by column), `revision_uid` exposed filter, `group_workflow-published` in moderation state filter values
- **Modified**: `nid` relationship simplified (removed group_content dependency), table style columns updated, argument replaced with null_argument placeholder
- **Removed** from cache_metadata contexts: `route.group` (no longer using group_content relationship)

- [ ] **Step 2: Clear caches and verify View loads**

Run:
```bash
ddev drush cr
```

Visit `/group/6/moderated` in browser (Albania group). Expect: page loads with empty table (no results yet since query alter hook not implemented). The filters should appear: Title, Content type, Language, Moderation state, Updated by.

- [ ] **Step 3: Commit the View config**

```bash
git add config/sync/views.view.duplicate_of_moderated_group_content.yml
git commit -m "refactor: rebuild moderated group content View config

Remove broken group_content relationship (no group_node content exists).
Add Language and Updated by columns, revision_uid relationship,
Published state to moderation filter, and Updated by exposed filter.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 2: Implement `hook_views_query_alter` for Language Filtering and Latest Revision

**Files:**
- Modify: `docroot/modules/custom/group_country_field/group_country_field.module`

This hook does the heavy lifting: filters nodes by the group's languages and ensures only the latest revision per node per language is shown.

- [ ] **Step 1: Add the views_query_alter implementation**

Add this function to the end of `group_country_field.module` (before the closing `?>` if one exists, or at the end of the file):

```php
/**
 * Implements hook_views_query_alter() for moderated group content.
 *
 * Filters content by the group's languages and ensures latest revision
 * per node per language since nodes relate to groups via language, not
 * group_content.
 */
function group_country_field_views_query_alter_moderated_group(ViewExecutable $view, QueryPluginBase $query) {
  $route_match = \Drupal::routeMatch();
  $group = $route_match->getParameter('group');
  if (!$group) {
    return;
  }

  if (is_numeric($group)) {
    $group = \Drupal\group\Entity\Group::load($group);
  }
  if (!$group || !$group->hasField('field_language')) {
    return;
  }

  $languages = array_column($group->get('field_language')->getValue(), 'value');
  if (empty($languages)) {
    return;
  }

  // Filter by group's languages.
  $query->addWhere(1, 'node_field_revision.langcode', $languages, 'IN');

  // Latest revision per node per language: subquery for max vid.
  $database = \Drupal::database();
  $subquery = $database->select('node_field_revision', 'nfr2');
  $subquery->addExpression('MAX(nfr2.vid)', 'max_vid');
  $subquery->where('nfr2.nid = node_field_revision.nid');
  $subquery->where('nfr2.langcode = node_field_revision.langcode');
  $query->addWhere(1, 'node_field_revision.vid', $subquery, 'IN');
}
```

Then modify the **existing** `group_country_field_views_query_alter()` function to dispatch to the new function. The existing function at line 53 currently only handles `recent_logged_in_users`. Add a call at the top:

```php
function group_country_field_views_query_alter(ViewExecutable $view, QueryPluginBase $query) {
  // Moderated group content: filter by group languages, latest revision.
  if ($view->id() == 'duplicate_of_moderated_group_content') {
    group_country_field_views_query_alter_moderated_group($view, $query);
    return;
  }

  if ($view->id() == "recent_logged_in_users") {
    // ... existing code unchanged ...
```

- [ ] **Step 2: Clear caches and test query**

Run:
```bash
ddev drush cr
```

Visit `/group/6/moderated` (Albania — language `al-sq`). Expect: content in Albanian language appears. No content from other languages.

Visit `/group/21/moderated` (Kosovo — languages `xk-sq`, `xk-rs`). Expect: content in both Kosovo-Albanian and Kosovo-Serbian.

- [ ] **Step 3: Commit**

```bash
git add docroot/modules/custom/group_country_field/group_country_field.module
git commit -m "feat: add views_query_alter for language-based group content filtering

Filter moderated group content View by group's field_language values.
Ensure only latest revision per node per language is shown using
MAX(vid) subquery.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 3: Implement `hook_form_views_exposed_form_alter` for Updated by Dropdown

**Files:**
- Modify: `docroot/modules/custom/group_country_field/group_country_field.module`

Populates the "Updated by" exposed filter dropdown with group members.

- [ ] **Step 1: Add the form alter implementation**

Add this function to `group_country_field.module`. Place it after the existing `group_country_field_form_views_exposed_form_alter()` function (which currently handles tmgmt and reports forms):

Actually, modify the **existing** `group_country_field_form_views_exposed_form_alter()` function to add a new condition block. Currently it's at line 83 and handles two form IDs. Add a block at the top of the function:

```php
function group_country_field_form_views_exposed_form_alter(&$form, $form_state) {
  // Moderated group content: populate "Updated by" with group members.
  if (isset($form['#id']) && strpos($form['#id'], 'duplicate-of-moderated-group-content') !== FALSE) {
    $route_match = \Drupal::routeMatch();
    $group = $route_match->getParameter('group');
    if ($group) {
      if (is_numeric($group)) {
        $group = \Drupal\group\Entity\Group::load($group);
      }
      if ($group) {
        $membership_loader = \Drupal::service('group.membership_loader');
        $memberships = $membership_loader->loadByGroup($group);
        $options = ['' => t('- Any -')];
        foreach ($memberships as $membership) {
          $user = $membership->getUser();
          $options[$user->id()] = $user->getDisplayName();
        }
        asort($options);
        // Keep "- Any -" at top after sort.
        $any = ['' => $options['']];
        unset($options['']);
        $options = $any + $options;

        $form['revision_uid'] = [
          '#type' => 'select',
          '#title' => t('Updated by'),
          '#options' => $options,
          '#default_value' => '',
        ];
      }
    }
  }

  // Existing form alter code below (unchanged).
  if ($form['#id'] == 'views-exposed-form-tmgmt-translation-all-job-items-page-1') {
```

- [ ] **Step 2: Clear caches and test the dropdown**

Run:
```bash
ddev drush cr
```

Visit `/group/126/moderated` (Global - English group). Expect: "Updated by" dropdown shows "- Any -" plus all member usernames of the Global English group. Select a user and click Filter. Results should filter to show only revisions by that user.

- [ ] **Step 3: Test filter combinations**

On `/group/6/moderated` (Albania):
1. Filter by Title "vitamin" → only content with "vitamin" in title
2. Filter by Content type "article" → only articles
3. Filter by Language → should show only group's languages in dropdown
4. Filter by Moderation state "Published" → published content appears
5. Filter by Updated by → revisions by that user
6. Combine: Content type "article" + Moderation state "Draft" + Updated by "username" → intersection of all

- [ ] **Step 4: Commit**

```bash
git add docroot/modules/custom/group_country_field/group_country_field.module
git commit -m "feat: populate Updated by dropdown with group members

Add form_views_exposed_form_alter to convert the Updated by textfield
into a select dropdown populated with the current group's members.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 4: Run Code Quality Checks

**Files:**
- Check: `docroot/modules/custom/group_country_field/group_country_field.module`

- [ ] **Step 1: Run PHPCS**

```bash
vendor/bin/phpcs docroot/modules/custom/group_country_field/group_country_field.module --standard=Drupal,DrupalPractice
```

Expected: 0 errors, 0 warnings. Fix any issues found.

- [ ] **Step 2: Run drupal-check**

```bash
vendor/bin/drupal-check -d docroot/modules/custom/group_country_field/group_country_field.module
```

Expected: no deprecation errors.

- [ ] **Step 3: Run phplint**

```bash
vendor/bin/phplint docroot/modules/custom/group_country_field/group_country_field.module
```

Expected: no syntax errors.

- [ ] **Step 4: Fix and commit if needed**

If any issues found, fix them and commit:
```bash
git add docroot/modules/custom/group_country_field/group_country_field.module
git commit -m "fix: code quality fixes for group_country_field module

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 5: Final Verification Against Acceptance Criteria

- [ ] **Step 1: Verify all acceptance criteria**

Test each criterion on `/group/6/moderated` (Albania, language `al-sq`):

| # | Criteria | How to verify |
|---|----------|---------------|
| 1 | Shows revisions in all languages linked to group | Unfiltered view shows content in `al-sq` only |
| 2 | Filter by Title | Enter text, click Filter, results filtered |
| 3 | Filter by Content type | Select type, click Filter |
| 4 | Filter by Language | Select language from dropdown |
| 5 | Filter by Moderation state (all states) | Verify "Published" appears in dropdown. Filter by each state. |
| 6 | Filter by Updated by (username) | Select user from dropdown, see their revisions |
| 7 | Combination of filters | Apply 2+ filters simultaneously |
| 8 | Results sorted most recent first | Check "Updated" column is DESC by default |

Also test on `/group/21/moderated` (Kosovo) to verify multi-language group shows content in both `xk-sq` and `xk-rs`.

- [ ] **Step 2: Verify tab visibility**

Log in as a user with `reviewer` role. Navigate to a group page. Verify the "Moderated content" tab is NOT visible (hidden per existing code in `pb_custom_field.module:997`).

Log in as `global_admin` or `editor`. Verify the tab IS visible.

- [ ] **Step 3: Export config if any View changes were made via UI**

If you adjusted anything via the Drupal admin UI during testing:
```bash
ddev drush cex -y
git diff config/sync/views.view.duplicate_of_moderated_group_content.yml
```

Review changes. If legitimate, commit:
```bash
git add config/sync/views.view.duplicate_of_moderated_group_content.yml
git commit -m "fix: update View config from UI adjustments

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```
