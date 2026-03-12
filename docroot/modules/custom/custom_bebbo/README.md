# Custom Bebbo Module

A general-purpose custom module that houses all common custom code for the Bebbo multi-site platform.

## Purpose

This module acts as the central home for shared hooks and common custom logic that do not belong to a more specific feature module.

## Structure

```
custom_bebbo/
├── custom_bebbo.info.yml   # Module metadata
└── custom_bebbo.module     # All custom hook implementations
```

## Adding New Functionality

All custom hooks and procedural code go directly in `custom_bebbo.module`.

As the module grows, additional files can be introduced:

| Concern | File to add |
|---|---|
| Shared utility / business logic | `src/Service/` + `custom_bebbo.services.yml` |
| New admin page | `src/Controller/` + `custom_bebbo.routing.yml` |
| Symfony/Drupal event listeners | `src/EventSubscriber/` |
| Custom permissions | `custom_bebbo.permissions.yml` |

## Installation

Enable via Drush:

```bash
drush en custom_bebbo -y
drush cr
```
