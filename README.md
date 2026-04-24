# new-cart

## Vendor JS/CSS (required)
All vendor files live under `js/vendor/`. Current structure:

```
js/vendor/
  delete-in-place.js
  ios-toggle.js
  maps-edge-date.js
  roller-select.js
  SimpleNotification/
    simpleNotification.min.css
    simpleNotification.min.js
  trumbowyg/
    trumbowyg.css
    trumbowyg.js
    src/...
```

## Database Migrations
If upgrading from an earlier build, run this SQL:

```sql
-- Categories table
ALTER TABLE `nc_categories`
    ADD COLUMN `seo_title`   VARCHAR(300) NOT NULL DEFAULT '' AFTER `slug`,
    ADD COLUMN `html_short`  TEXT                             AFTER `seo_title`,
    ADD COLUMN `html_long`   MEDIUMTEXT                       AFTER `html_short`,
    ADD COLUMN `featured`    TINYINT(1)   NOT NULL DEFAULT 0  AFTER `html_long`,
    DROP COLUMN `description`,
    DROP COLUMN IF EXISTS `browse_only`;

-- Admin table
ALTER TABLE `nc_admin`
    ADD COLUMN `access_level` SMALLINT UNSIGNED NOT NULL DEFAULT 254 AFTER `password`,
    ADD COLUMN `avatar`       VARCHAR(255)       NOT NULL DEFAULT '' AFTER `access_level`;
```

Status values: 0 = Not Active, 1 = Active, 2 = Browse Only.

Access level flags: ADD=2, DELETE=4, EDIT=8, PRICING=16, REPORTS=32, REVIEWS=64, PAGES=128.
Named levels: Admin=254, Super Editor=234, Editor=10, User=0.

```sql
-- Add nc_incomplete table
CREATE TABLE IF NOT EXISTS `nc_incomplete` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity`     VARCHAR(64)  NOT NULL,
    `entity_id`  INT UNSIGNED NOT NULL,
    `label`      VARCHAR(255) NOT NULL,
    `message`    VARCHAR(500) NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `entity_item` (`entity`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add sku to products
ALTER TABLE `nc_products`
    ADD COLUMN `sku` VARCHAR(128) NOT NULL DEFAULT '' AFTER `slug`;

-- Related products
CREATE TABLE IF NOT EXISTS `nc_product_related` (
    `product_id`         INT UNSIGNED NOT NULL,
    `related_product_id` INT UNSIGNED NOT NULL,
    `display_order`      INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`product_id`, `related_product_id`),
    KEY `related_product_id` (`related_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- System pages: correct page_type for cart/checkout and add product page
UPDATE nc_pages SET page_type='cart'     WHERE slug='cart'     AND page_type='page';
UPDATE nc_pages SET page_type='checkout' WHERE slug='checkout' AND page_type='page';
INSERT IGNORE INTO nc_pages (slug, title, page_type, status, display_order)
    VALUES ('product', 'Product', 'product', 1, 999);
INSERT IGNORE INTO nc_page_blocks
    (page_id, block_type, settings, display_order, enabled, cols, col_start, col_span, `row`, row_span)
    SELECT id, 'product_view', '{"is_core":true}', 1, 1, 4, 1, 4, 0, 1
    FROM nc_pages WHERE slug='product';
```

## Requirements
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+
- Apache with mod_rewrite

## Dependencies
new-cart does not use Composer. Dependencies are installed manually.

### Smarty 4 (required)
Download and install Smarty before running the install wizard:

```bash
cd /path/to/new-cart/lib
wget https://github.com/smarty-php/smarty/archive/refs/tags/v4.5.4.zip
unzip v4.5.4.zip
mkdir -p vendor/smarty/smarty
mv smarty-4.5.4/* vendor/smarty/smarty/
rmdir smarty-4.5.4
rm v4.5.4.zip
```

## Installation
1. Install Smarty (above)
2. Set directory permissions so your web server can write to `cfg/`, `cache/`, `logs/`, and `install/`
3. Visit `http://yoursite.com/install/` and follow the wizard
