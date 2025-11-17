# ICMS Core Logic Module

This module contains update hooks and cross-bundle logic for ICMS core functionality.

## Purpose

- Handle structural changes via `hook_update_N()`
- Provide shared functionality needed across multiple bundles

## Hook Types

### Update Hooks
Run before config import. Use for schema changes, complex migrations.

```php
function icms_core_logic_update_9001() {
  \Drupal::service('module_installer')->install(['new_module']);
  return new TranslatableMarkup('Enabled new_module.');
}
```

Start numbering from 9001.

## Deployment Workflow

Client projects update via:

```bash
composer update iqual/icms_core
drush updb          # Runs update hooks
drush cex           # Export config
drush cr            # Clear cache
```

## Bundle Logic Modules

Each bundle has a paired logic module (e.g., `icms_bundle_news_logic`) following the same pattern.

## Client Projects

Client projects should create feature-specific modules (e.g., `clientname_sso`, `clientname_newsletter`) rather than a single `*_logic` module. Use update numbers below 9000 for client-specific hooks.
