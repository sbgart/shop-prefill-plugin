# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Project Overview

This is a **Webasyst Shop-Script plugin** (`shop/plugins/prefill`) that prefills checkout form fields based on the user's previous orders. It supports both authenticated users (by `contact_id`) and guests (via `prefill_guest_hash` cookie linked to `shop_order_params` DB records).

- **Plugin ID:** `prefill`
- **App:** `shop` (Shop-Script)
- **Vendor:** 1059969
- **Framework:** Webasyst (PHP + Smarty templates)
- **Locales:** `ru_RU`, `en_US` (domain: `shop_prefill`)

## Key Commands

All commands run from the Webasyst root (`/Users/artem/Project/wa-dev`):

```bash
# Create release archive (validates PHP syntax, config, DB structure, excludes docs/)
php wa.php compress shop/plugins/prefill -style false
```

For locale compilation and cache clearing — use `/compile-plugin-mo`.

## Architecture

### PHP Backend

The main plugin class `shopPrefillPlugin` (`lib/shopPrefill.plugin.php`) acts as a service locator — it lazily instantiates all providers and hooks via getter methods. It registers Webasyst hooks in `lib/config/plugin.php`.

**Active hooks:**
- `frontend_head` — runs on every shop page; manages cookies and debug, and attaches CSS/JS **only on the checkout page** (`CheckoutPageDetector`, see `docs/codereview/issue-64-*.md`). Does NOT prefill — see `docs/codereview/issue-63-*.md`
- `checkout_before_auth` — fires on every AJAX calculate/create call during checkout
- `checkout_render_*` — multiple hooks injecting HTML into checkout sections (auth, region, shipping, details, payment, confirm)
- `order_action.create` — saves `shipping_type` to order params after order creation

**Key class groups in `lib/classes/`:**

| Group | Purpose |
|-------|---------|
| `hooks/` | `FrontendHooks`, `CheckoutHooks`, `OrderHooks` — delegate plugin hook handling |
| `fillparams/` | `FillParamsProvider` — fetches prefill data from DB; `FillParams` — data object; `FillParamsStorage` — writes to PHP session (`shop/checkout`); `GuestHashStorage` — manages `prefill_guest_hash` HTTP-only cookie + DB linkage |
| `sessionstorage/` | `SessionStorageProvider` — reads/writes checkout params in Webasyst session |
| `storefronts/` | `StorefrontProvider`, `Storefront`, `StorefrontCollection` — per-storefront settings |
| `settings/providers/` | `SettingProvider`, `StorefrontSettingProvider` — read/write plugin settings from `shop_prefill_settings` table |
| `zenmode/` | `ZenMode`, `ZenData` — collapsible checkout section logic |
| `view/` | `AssetsManager` — generates CSS variables file and JS initializer file dynamically into `wa-data/` |
| `checkout/` | `CheckoutState` — reads the hook `$params`; `CheckoutPageDetector` — answers whether this request renders the order form, so assets stay off the catalog |
| `consent/` | `ConsentStorage` — manages `prefill_consent` HTTP-only cookie for guests |

**Settings storage:** `shop_prefill_settings` table (one row per logical key: `storefront_code` + `name` + `groups`, where `groups` stores the leaf's path in the settings tree). Defaults defined in `lib/config/storefront.settings.php` (per-storefront) and `lib/config/settings.php` (global).

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

After editing `.po` files — run `/compile-plugin-mo`. The skill handles all cache levels including PHP-FPM restart if needed.

## Release Process

Use the skills — they handle all steps including edge cases:

- `/release-start` — bump version, create branch, update CHANGELOG
- `/compile-plugin-mo` — recompile locale after any `.po` changes
- `/release-pack` — create archive (`prefill.tar.gz`, excludes `docs/` and `*.md` via `lib/config/exclude.php`)
- `/release-publish` — git tag, push, `gh release create`

Archive output: `wa-apps/shop/plugins/prefill/prefill.tar.gz`. Must be `.tar.gz` — Webasyst store rejects other formats.

## Important Notes

- **No test suite** exists for this plugin — test manually in browser against a running Webasyst instance
- CSS variables and JS initializer are generated dynamically into `wa-data/public/shop/plugins/prefill/` (cached by hash, not versioned)
- `shopPrefillPlugin::$instance` is a static singleton — use `shopPrefillPlugin::getInstance()` to access it
- **Effective storefront** — the single place where the fallback to the global `'*'` storefront lives. `getEffectiveStorefront()` returns the current storefront, or the global one when there is no current storefront (backend/API/CLI) or it is inactive (`active = false` is the default). Always take both settings and storefront code from that one object — taking the code elsewhere produced a per-storefront CSS file with global content that never refreshed
- `self::$effective_storefront` / `self::$effective_storefront_settings` are request-scoped caches; call `shopPrefillPlugin::clearEffectiveStorefrontCache()` after saving settings
- Storefront lookups are nullable by name: `findCurrentStorefront()` / `findStorefront($code)` return `null` (use them in backend actions and report a clear error), while `getGlobalStorefront()` and `getEffectiveStorefront()` always return an object
- `order_action.create` fires outside the storefront too (backend, API, CLI, import). The hook exits early via `isStorefrontRequest()`: there is no checkout session there, and the admin's guest cookie would otherwise be attached to a customer's order
- Debug mode is tied to `waSystemConfig::isDebug()` (Webasyst global debug flag)

## Imported Claude Cowork project instructions

Я занимаюсь разработкой плагинов для Webasyst Shop Script. Данный плагин заполняет чекаут страницы корзины на основе данных прошлого заказа, так же скрывает уже заполненные поля и формирует сводку - Дзен режим.
