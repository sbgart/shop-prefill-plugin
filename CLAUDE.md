# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Webasyst Shop-Script plugin** (`shop/plugins/prefill`) that prefills checkout form fields based on the user's previous orders. It supports both authenticated users (by `contact_id` via `shop_customer.last_order_id`) and guests (via a random `prefill_guest_token` cookie whose derived lookup id is stored in the indexed `shop_order_params.name` column).

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
- `frontend_head` — runs on every shop page; manages cookies, assets and debug. **Does NOT prefill** — writing the session from the layout cannot affect the current page, and prefilled sections are not read outside checkout (see `docs/codereview/issue-63-*.md`)
- `checkout_before_auth` — fires on every AJAX calculate/create call during checkout
- `checkout_render_*` — multiple hooks injecting HTML into checkout sections (auth, region, shipping, details, payment, confirm)
- `order_action.create` — saves `shipping_type` to order params after order creation

**Key class groups in `lib/classes/`:**

| Group | Purpose |
|-------|---------|
| `hooks/` | `FrontendHooks`, `CheckoutHooks`, `OrderHooks` — delegate plugin hook handling |
| `fillparams/` | `FillParamsProvider` — fetches prefill data from DB, memoizes statically, computes the source key; `FillParams` — data object; `GuestTokenStorage` — manages the `prefill_guest_token` HTTP-only cookie, derives the lookup id and links orders |
| `sessionstorage/` | `SessionStorageProvider` — reads/writes checkout params in Webasyst session |
| `storefronts/` | `StorefrontProvider`, `Storefront`, `StorefrontCollection` — per-storefront settings |
| `settings/providers/` | `SettingProvider`, `StorefrontSettingProvider` — read/write plugin settings from `shop_prefill_settings` table |
| `zenmode/` | `ZenMode`, `ZenData` — collapsible checkout section logic |
| `view/` | `AssetsManager` — generates CSS variables file and JS initializer file dynamically into `wa-data/` |
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
- `FrontendFillCheckoutParams` — applies prefill params to session
- `FrontendParamsChoice` — returns available delivery options for selection UI
- `FrontendApplyDelivery` — applies selected delivery option
- `FrontendTogglePrefill`, `FrontendToggleZen` — toggle modes
- `FrontendConsent` — saves guest consent
- `FrontendLogs`, `FrontendRefreshDebug` — debug endpoints

### Data Flow

Prefill runs **only on the checkout path** — `checkout_before_auth`, which fires on every
`calculate`/`create` and on the `/order/` form render via `formVars()` → `processAll()`.

1. `CheckoutHooks` computes the source key (no DB) and passes a **lazy loader** into
   `SessionStorageProvider::preFillCheckoutParamsFromSource()`
2. That method: collects prefillable sections → fills what it can from `shop/prefill_snapshot`
   → only if gaps remain **and** the session marker `shop/prefill_source` does not match,
   calls the loader → writes `shop/checkout`, snapshot and the marker
3. `AssetsManager` generates a unique JS initializer file passing params to `PrefillFrontendController`
4. JS modules manipulate the checkout DOM for Zen Mode and delivery variant cards

Two rules the marker must obey (breaking either causes silent regressions):

- it gates **only** the loader call, never the snapshot restore — snapshot works every request
- it is **not written** for a guest without a cookie, otherwise every anonymous visitor and bot
  gets a PHP session and `Set-Cookie: PHPSESSID`

### Guest Data Flow

- Browsing the catalog creates **nothing** — no cookie, no queries
- On order create: if consent is not required OR granted, a random token
  `bin2hex(random_bytes(32))` is issued into the `prefill_guest_token` cookie, and the order
  gets a param `name = 'prefill_guest_' . substr(sha256(token), 0, 48)`, `value = '1'`
- Next visit: token → lookup id → `WHERE name = ?` on the **existing index** of
  `shop_order_params` → last order → prefill
- The raw token never reaches the DB, so leaking order params cannot restore someone's cookie
- Revoke/clear delete the DB links **before** clearing the cookie — afterwards they are unreachable

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
