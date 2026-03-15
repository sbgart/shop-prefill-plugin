# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Webasyst Shop-Script plugin** (`shop/plugins/prefill`) that prefills checkout form fields based on the user's previous orders. It supports both authenticated users (by `contact_id`) and guests (via `prefill_guest_hash` cookie linked to `shop_order_params` DB records).

- **Plugin ID:** `prefill`
- **App:** `shop` (Shop-Script)
- **Vendor:** 1059969
- **Framework:** Webasyst (PHP + Smarty templates)
- **Locales:** `ru_RU`, `en_US` (domain: `shop_prefill`)

## Key Commands

All commands run from the Webasyst root (`/Users/user/Project/wa-dev`):

```bash
# Compile .po → .mo locale files (required after any .po change)
XDEBUG_MODE=off php wa.php locale shop/plugins/prefill

# Create release archive (validates PHP syntax, config, DB structure, excludes docs/)
php wa.php compress shop/plugins/prefill -style false

# Clear plugin cache
rm -rf wa-cache/*/apps/shop_prefill/
```

## Architecture

### PHP Backend

The main plugin class `shopPrefillPlugin` (`lib/shopPrefill.plugin.php`) acts as a service locator — it lazily instantiates all providers and hooks via getter methods. It registers Webasyst hooks in `lib/config/plugin.php`.

**Active hooks:**
- `frontend_head` — runs on every shop page; manages cookies and prefills checkout params into PHP session
- `checkout_before_auth` — fires on every AJAX calculate/create call during checkout
- `checkout_render_*` — multiple hooks injecting HTML into checkout sections (auth, region, shipping, details, payment, confirm)
- `order_action.create` — saves `shipping_type` to order params after order creation

**Key class groups in `lib/classes/`:**

| Group | Purpose |
|-------|---------|
| `hooks/` | `FrontendHooks`, `CheckoutHooks`, `OrderHooks` — delegate plugin hook handling |
| `fillparams/` | `FillParamsProvider` — fetches prefill data from DB; `FillParams` — data object; `FillParamsStorage` — writes to PHP session (`shop/checkout`) |
| `sessionstorage/` | `SessionStorageProvider` — reads/writes checkout params in Webasyst session |
| `storefronts/` | `StorefrontProvider`, `Storefront`, `StorefrontCollection` — per-storefront settings |
| `settings/providers/` | `SettingProvider`, `StorefrontSettingProvider` — read/write plugin settings from `shop_prefill_settings` table |
| `zenmode/` | `ZenMode`, `ZenData` — collapsible checkout section logic |
| `view/` | `AssetsManager` — generates CSS variables file and JS initializer file dynamically into `wa-data/` |
| `consent/` | `ConsentStorage` — manages `prefill_consent` HTTP-only cookie for guests |
| `fillparams/` | `GuestHashStorage` — manages `prefill_guest_hash` HTTP-only cookie + DB linkage |

**Settings storage:** `shop_prefill_settings` table (one row per `storefront_code` + `name`). Defaults defined in `lib/config/storefront.settings.php` (per-storefront) and `lib/config/settings.php` (global).

### Frontend JavaScript

JS modules in `js/modules/` are loaded in dependency order by `AssetsManager`:

- `HttpClient.js` — AJAX wrapper
- `DialogManager.js` — dialog UI
- `Logger.js` — debug logging (depends on HttpClient)
- `ConsentManager.js` — guest consent checkbox
- `ParamsChoiceManager.js` — delivery variant selection UI
- `OrderFormManager.js` — fills checkout form fields
- `ZenModeToggle.js` — collapse/expand checkout sections

Main controller: `js/prefill.frontend.js` (minified: `prefill.frontend.min.js`) — `PrefillFrontendController` class, initialized via a generated `wa-data` JS file.

### Frontend AJAX Controllers

Located in `lib/actions/frontend/`:
- `FrontendFillCheckoutParams` — applies prefill params to session
- `FrontendParamsChoice` — returns available delivery options for selection UI
- `FrontendApplyDelivery` — applies selected delivery option
- `FrontendTogglePrefill`, `FrontendToggleZen` — toggle modes
- `FrontendConsent` — saves guest consent
- `FrontendLogs`, `FrontendRefreshDebug` — debug endpoints

### Data Flow

1. `frontendHead` hook fires → `FillParamsProvider::getFillParams()` queries last order from DB (by `contact_id` for auth users, by `prefill_guest_hash` cookie for guests)
2. If `prefill.on_entry = true`, data is written into PHP session (`shop/checkout`) via `SessionStorageProvider::preFillCheckoutParams()`
3. `checkout_before_auth` hook fires on each AJAX call — reruns prefill logic
4. `AssetsManager` generates a unique JS initializer file passing params (including translated messages) to `PrefillFrontendController`
5. JS modules manipulate the checkout DOM to show Zen Mode collapsed sections and delivery variant cards

### Guest Data Flow

- First visit: `prefill_guest_hash` cookie (SHA256, HTTP-only, 1 year) is created
- On order create: if `guest/consent_required = true` and consent cookie set, hash is saved to `shop_order_params` table
- Next visit: hash read from cookie → last order found by hash in DB → prefill applied

## Localization

Locale files: `locale/{ru_RU,en_US}/LC_MESSAGES/shop_prefill.{po,mo}`

Key conventions:
- Dot-notation keys: `error.*`, `setting.*`, `hint.*`, `tab.*`, `zen.*`, `dialog.*`
- JS translations passed via `$_locale` object in `templates/actions/settings/blocks/Head.html`
- Always call `waLocale::loadByDomain(['shop', 'prefill'])` and `waSystem::pushActivePlugin('prefill', 'shop')` in action `execute()` methods

## Release Process

1. Update version in `lib/config/plugin.php`
2. Update `CHANGELOG.md` and `README.md`
3. Recompile locale: `XDEBUG_MODE=off php wa.php locale shop/plugins/prefill`
4. Create archive: `php wa.php compress shop/plugins/prefill -style false`
   - Archive output: `wa-apps/shop/plugins/prefill/prefill.tar.gz`
   - Must be `.tar.gz` — Webasyst store rejects other formats
   - `docs/` and all `*.md` files are excluded via `lib/config/exclude.php`
5. Verify: ~92 KB, ~117 files, no `.md` files, no `docs/`

## Important Notes

- **No test suite** exists for this plugin — test manually in browser against a running Webasyst instance
- CSS variables and JS initializer are generated dynamically into `wa-data/public/shop/plugins/prefill/` (cached by hash, not versioned)
- `shopPrefillPlugin::$instance` is a static singleton — use `shopPrefillPlugin::getInstance()` to access it
- `self::$storefront_settings` is request-scoped cache; call `shopPrefillPlugin::clearStorefrontSettingsCache()` after saving settings
- Debug mode is tied to `waSystemConfig::isDebug()` (Webasyst global debug flag)
