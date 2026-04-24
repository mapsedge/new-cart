# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

- PHP 8.1+, Smarty 4 templating, MySQL/MariaDB, Apache mod_rewrite
- No Composer, no npm, no build step — all dependencies are manually installed
- Smarty lives at `lib/vendor/smarty/smarty/`

## Architecture

new-cart is a lightweight custom e-commerce framework with a single front controller (`index.php`) and a parallel admin front controller (`admin/index.php`).

**Request flow:** `.htaccess` rewrites all requests → `index.php` parses the URI, maps it to a route string, then `require`s `ctl/{route}.php`. Admin requests route to `admin/ctl/{route}.php`.

**Key directories:**
- `ctl/` — storefront controllers (one file per route: home, category, product, cart, checkout, wishlist, page, account, etc.)
- `admin/ctl/` — admin controllers (categories, products, orders, pages, settings, reports, etc.)
- `tpl/` — Smarty templates for the storefront
- `admin/tpl/` — Smarty templates for admin
- `lib/` — core libraries: `db.php`, `functions.php`, `hook.php`, `cart.php`, `wishlist.php`, `plugin-loader.php`, `page_block_helper.php`
- `cfg/config.php` — all constants (DB credentials, paths, URLs, session, Smarty flags)
- `plugins/` — plugin packages (dot-prefixed folder = disabled)
- `usermods/` — per-deployment CSS/JS overrides

**Database:** all tables use the `nc_` prefix (defined as `DB_PREFIX`). Core tables: `nc_categories`, `nc_products`, `nc_orders`, `nc_admin`, `nc_settings`, `nc_incomplete`.

## Plugin System

Plugins live in `plugins/{code}/`. A folder prefixed with `.` (e.g., `.stripe`) is disabled — rename to enable/disable.

Each plugin requires a `plugin.xml` manifest. Optional `hooks.php` is auto-loaded at bootstrap via `PluginLoader::boot()`. Plugins can also add catalog routes at `plugins/.{code}/catalog/{route}.php` and override templates via `plugins/{code}/tpl/`.

**Hook variants** (registered in a plugin's `hooks.php`):
```php
Hook::on('catalog.product.view.before', function(&$data) { /* modify $data */ });
Hook::on('catalog.product.price.instead', function($data) { return $price; }); // replaces core logic; only one plugin may register :instead per hook point
$filtered = Hook::filter('catalog.checkout.shipping_rates', $rates, $context);
```

All defined hook points are listed in `lib/hook.php` → `Hook::defined()`.

## Admin Path

The admin URL is obfuscated. `URL_ADMIN` (e.g., `/Cc7DZbWFVDSh/`) is the public-facing path; `URL_ADMIN_REAL` (`/admin/`) is the filesystem path. Both constants are in `cfg/config.php`.

## Admin Access Levels

Bitmask stored in `nc_admin.access_level`. Flags: ADD=2, DELETE=4, EDIT=8, PRICING=16, REPORTS=32, REVIEWS=64, PAGES=128. Named levels: Admin=254, Super Editor=234, Editor=10, User=0.

## Installation & Setup

No automated install. To set up a fresh instance:
1. Place Smarty 4 at `lib/vendor/smarty/smarty/` (see README.md for the exact download/unzip steps)
2. Make `cfg/`, `cache/`, `logs/`, and `install/` writable by the web server
3. Visit `/install/` to run the wizard — it writes `cfg/config.php` and creates `install/.installed`

## Database Migrations

Schema changes between builds are tracked as raw SQL in `README.md`. There is no migration runner — execute them manually against the `new_cart` database.

## Development Notes

- `SMARTY_FORCE_COMPILE` and `SMARTY_CACHING` are constants in `cfg/config.php`; set `SMARTY_FORCE_COMPILE=true` locally to skip template caching during development
- `display_errors` is hard-coded on in `index.php` during active development (the constants `DISPLAY_ERRORS` / `LOG_ERRORS` in config control the custom error handler, which is currently commented out)
- Always validate, sanitize, and parameterize user inputs to prevent SQL injection. Use prepared statements and avoid dynamic query construction with raw user input.
- Take all available steps to harden against Cross-Site Scripting (XSS), Cross-Site Request Forgery (CSRF), File Inclusion Attacks (LFI/RFI), Remote Code Execution (RCE), Insecure Authentication & Session Management, Broken Access Control, Information Disclosure, Insecure Direct Object References (IDOR), Security Misconfigurations, Denial of Service (DoS), Insecure File Uploads, Clickjacking
- There are no automated tests or a linter configured for this project
