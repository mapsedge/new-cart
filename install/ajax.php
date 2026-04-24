<?php
/**
 * new-cart install wizard — ajax handler
 */

header('Content-Type: application/json');

function out(bool $ok, string $message = '', array $extra = []): never {
	echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
	exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Check directory permissions ────────────────────────────────────────────────
if ($action === 'check_permissions') {
	$base = realpath(dirname(__DIR__));
	if (!$base) {
		out(false, 'Could not resolve base directory from: ' . dirname(__DIR__));
	}
	$base .= '/';

	$dirs = [
		'.'                  => $base,
		'cfg'                => $base . 'cfg',
		'cache/tpl'          => $base . 'cache/tpl',
		'cache/tpl/admin'    => $base . 'cache/tpl/admin',
		'cache/smarty'       => $base . 'cache/smarty',
		'cache/smarty/admin' => $base . 'cache/smarty/admin',
		'logs'               => $base . 'logs',
		'img/avatars'        => $base . 'img/avatars',
		'img/products'       => $base . 'img/products',
		'img/products/.admin'       => $base . 'img/products/.admin',
		'img/products/.fm' => $base . 'img/products/.fm',
		'img/options'        => $base . 'img/options',
		'install'            => $base . 'install',
	];

	$failed  = [];
	$details = [];
	foreach ($dirs as $label => $path) {
		if (!is_dir($path)) {
			$made = @mkdir($path, 0755, true);
			$details[] = $label . ': mkdir ' . ($made ? 'OK' : 'FAILED');
		}
		// Use actual write test — is_writable() can lie about effective user
		$testFile = $path . '/.nc_write_test';
		$wrote = @file_put_contents($testFile, '1');
		if ($wrote === false) {
			$failed[]  = $label . '/';
			$details[] = $label . ': NOT writable (path: ' . $path . ')';
		} else {
			@unlink($testFile);
			$details[] = $label . ': OK';
		}
	}

	if ($failed) {
		$list = implode(', ', $failed);
		$user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
		out(false,
			"Not writable: {$list}\n" .
			"Fix: sudo chown -R {$user}:www-data {$base} && sudo chmod -R 775 {$base}",
			['details' => $details, 'base' => $base]
		);
	}

	out(true, 'All required directories are writable.', ['details' => $details]);
}

// ── Test root credentials ──────────────────────────────────────────────────────
if ($action === 'test_root') {
	$host   = trim($_POST['db_host']  ?? 'localhost');
	$root   = trim($_POST['db_root']  ?? '');
	$rootpw = $_POST['db_rootpw']     ?? '';

	if (!$host || !$root) out(false, 'Host and root username are required.');

	try {
		new PDO("mysql:host={$host};charset=utf8mb4", $root, $rootpw, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		]);
		out(true, 'Root credentials verified.');
	} catch (PDOException $e) {
		out(false, $e->getMessage());
	}
}

// ── Create database ────────────────────────────────────────────────────────────
if ($action === 'create_db') {
	$host   = trim($_POST['db_host']  ?? 'localhost');
	$name   = trim($_POST['db_name']  ?? '');
	$root   = trim($_POST['db_root']  ?? '');
	$rootpw = $_POST['db_rootpw']     ?? '';

	if (!$host || !$name || !$root) {
		out(false, 'Host, database name and root username are required.');
	}

	try {
		$pdo  = new PDO("mysql:host={$host};charset=utf8mb4", $root, $rootpw, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		]);
		$safe = str_replace('`', '', $name);
		$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
		out(true, "Database '{$name}' created (or already exists).");
	} catch (PDOException $e) {
		out(false, $e->getMessage());
	}
}

// ── Create database user ───────────────────────────────────────────────────────
if ($action === 'create_user') {
	$host    = trim($_POST['db_host']   ?? 'localhost');
	$db_name = trim($_POST['db_name']   ?? '');
	$root    = trim($_POST['db_root']   ?? '');
	$rootpw  = $_POST['db_rootpw']      ?? '';
	$newuser = trim($_POST['db_user']   ?? '');
	$newpass = $_POST['db_pass']        ?? '';
	$prefix  = preg_replace('/[^a-z0-9_]/i', '', trim($_POST['db_prefix'] ?? 'nc_'));

	if (!$root || !$newuser || !$newpass || !$db_name) {
		out(false, 'Root credentials, username, password and database name are all required.');
	}

	try {
		$pdo = new PDO("mysql:host={$host};charset=utf8mb4", $root, $rootpw, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		]);

		$safeUser = str_replace("'", '', $newuser);
		$safePass = str_replace("'", '', $newpass);
		$safeDb   = str_replace('`', '', $db_name);

		$pdo->exec("CREATE USER IF NOT EXISTS '{$safeUser}'@'localhost' IDENTIFIED BY '{$safePass}'");
		$pdo->exec("GRANT ALL PRIVILEGES ON `{$safeDb}`.* TO '{$safeUser}'@'localhost'");
		$pdo->exec("FLUSH PRIVILEGES");

		// Verify the new user can actually connect
		$dsn = "mysql:host={$host};dbname={$safeDb};charset=utf8mb4";
		$appPdo = new PDO($dsn, $safeUser, $newpass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

		// Check if prefix is already in use
		$safePrefix  = str_replace('`', '', $prefix);
		$tableCheck  = $appPdo->query(
			"SELECT COUNT(*) FROM information_schema.tables
			 WHERE table_schema = DATABASE()
			 AND table_name LIKE '{$safePrefix}%'"
		)->fetchColumn();

		if ((int)$tableCheck > 0) {
			out(false,
				"The table prefix '{$prefix}' is already in use in this database " .
				"({$tableCheck} table(s) found). Choose a different prefix to avoid conflicts.",
				['prefix_conflict' => true]
			);
		}

		out(true, "User '{$newuser}' created and connection verified.");
	} catch (PDOException $e) {
		out(false, $e->getMessage());
	}
}

// ── Validate admin account ─────────────────────────────────────────────────────
if ($action === 'validate_admin') {
	$username = trim($_POST['admin_user']  ?? '');
	$email    = trim($_POST['admin_email'] ?? '');
	$pass     = $_POST['admin_pass']       ?? '';
	$confirm  = $_POST['admin_confirm']    ?? '';

	if (strlen($username) < 3)                        out(false, 'Username must be at least 3 characters.');
	if (!filter_var($email, FILTER_VALIDATE_EMAIL))   out(false, 'Invalid email address.');
	if (strlen($pass) < 8)                            out(false, 'Password must be at least 8 characters.');
	if ($pass !== $confirm)                           out(false, 'Passwords do not match.');

	out(true, 'Admin details look good.');
}

// ── Full install ───────────────────────────────────────────────────────────────
if ($action === 'install') {
	$host      = trim($_POST['db_host']      ?? '');
	$name      = trim($_POST['db_name']      ?? '');
	$user      = trim($_POST['db_user']      ?? '');
	$pass      = $_POST['db_pass']           ?? '';
	$prefix    = preg_replace('/[^a-z0-9_]/i', '', trim($_POST['db_prefix'] ?? 'nc_'));

	$admin_user  = trim($_POST['admin_user']  ?? '');
	$admin_email = trim($_POST['admin_email'] ?? '');
	$admin_pass  = $_POST['admin_pass']       ?? '';

	$site_name     = trim($_POST['site_name']     ?? 'My Store');
	$site_currency = trim($_POST['site_currency'] ?? '$');
	$site_email    = trim($_POST['site_email']    ?? '');
	$admin_path    = preg_replace('/[^a-z0-9_\-]/i', '', trim($_POST['admin_path'] ?? 'admin'));

	if (!$host || !$name || !$user) out(false, 'Missing database credentials.');
	if (!$admin_user || !$admin_email || !$admin_pass) out(false, 'Missing admin details.');

	// Connect as app user
	try {
		$dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
		$pdo = new PDO($dsn, $user, $pass, [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	} catch (PDOException $e) {
		out(false, 'DB connection failed: ' . $e->getMessage());
	}

	// Create tables
	$p      = $prefix;
	$tables = [
		"CREATE TABLE IF NOT EXISTS `{$p}admin` (
			`id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`username`     VARCHAR(64)  NOT NULL,
			`email`        VARCHAR(128) NOT NULL,
			`password`     VARCHAR(255) NOT NULL,
			`access_level` SMALLINT UNSIGNED NOT NULL DEFAULT 254,
			`avatar`       VARCHAR(255) NOT NULL DEFAULT '',
			`status`       TINYINT(1)   NOT NULL DEFAULT 1,
			`created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `username` (`username`),
			UNIQUE KEY `email` (`email`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}settings` (
			`key`   VARCHAR(128) NOT NULL,
			`value` TEXT,
			PRIMARY KEY (`key`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}categories` (
			`id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`parent_id`     INT UNSIGNED NOT NULL DEFAULT 0,
			`name`          VARCHAR(255) NOT NULL,
			`slug`          VARCHAR(255) NOT NULL,
			`seo_title`       VARCHAR(300) NOT NULL DEFAULT '',
			`seo_keywords`    VARCHAR(500) NOT NULL DEFAULT '',
			`seo_description` VARCHAR(500) NOT NULL DEFAULT '',
			`html_short`      TEXT,
			`html_long`       MEDIUMTEXT,
			`featured`      TINYINT(1)   NOT NULL DEFAULT 0,
			`status`        TINYINT(1)   NOT NULL DEFAULT 1,
			`display_order` INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `parent_id` (`parent_id`),
			KEY `slug` (`slug`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}products` (
			`id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`name`             VARCHAR(255)  NOT NULL,
			`slug`             VARCHAR(255)  NOT NULL,
			`sku`              VARCHAR(128)  NOT NULL DEFAULT '',
			`description`      TEXT,
			`description_long` MEDIUMTEXT,
			`seo_title`        VARCHAR(300) NOT NULL DEFAULT '',
			`seo_keywords`     VARCHAR(500) NOT NULL DEFAULT '',
			`seo_description`  VARCHAR(500) NOT NULL DEFAULT '',
			`price`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`list_price`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`stock`            INT           NOT NULL DEFAULT 0,
			`status`           TINYINT(1)    NOT NULL DEFAULT 1,
			`featured`         TINYINT(1)    NOT NULL DEFAULT 0,
			`free_shipping`    TINYINT(1)    NOT NULL DEFAULT 0,
			`display_order`    INT UNSIGNED  NOT NULL DEFAULT 0,
			`created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `slug` (`slug`),
			KEY `sku` (`sku`),
			KEY `status` (`status`),
			KEY `featured` (`featured`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}product_images` (
			`id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`product_id`    INT UNSIGNED NOT NULL,
			`filename`      VARCHAR(255) NOT NULL,
			`is_primary`    TINYINT(1)   NOT NULL DEFAULT 0,
			`display_order` INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `product_id` (`product_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}categories_products` (
			`category_id` INT UNSIGNED NOT NULL,
			`product_id`  INT UNSIGNED NOT NULL,
			PRIMARY KEY (`category_id`, `product_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}product_related` (
			`product_id`         INT UNSIGNED NOT NULL,
			`related_product_id` INT UNSIGNED NOT NULL,
			`display_order`      INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (`product_id`, `related_product_id`),
			KEY `related_product_id` (`related_product_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}customers` (
			`id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`email`      VARCHAR(128) NOT NULL,
			`password`   VARCHAR(255) NOT NULL,
			`first_name` VARCHAR(64),
			`last_name`  VARCHAR(64),
			`status`     TINYINT(1)   NOT NULL DEFAULT 1,
			`created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `email` (`email`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}orders` (
			`id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`customer_id`   INT UNSIGNED,
			`email`         VARCHAR(255)  NOT NULL DEFAULT '',
			`first_name`    VARCHAR(128)  NOT NULL DEFAULT '',
			`last_name`     VARCHAR(128)  NOT NULL DEFAULT '',
			`status`        VARCHAR(32)   NOT NULL DEFAULT 'pending',
			`subtotal`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`total`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`shipping`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`tax`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`ship_name`     VARCHAR(128),
			`ship_address1` VARCHAR(255),
			`ship_address2` VARCHAR(255),
			`ship_city`     VARCHAR(128),
			`ship_state`    VARCHAR(64),
			`ship_zip`      VARCHAR(20),
			`ship_country`       VARCHAR(64),
			`ship_method`        VARCHAR(128)  NOT NULL DEFAULT '',
			`shippo_rate_token`  VARCHAR(255)  NOT NULL DEFAULT '',
			`payment_ref`      VARCHAR(255) NOT NULL DEFAULT '',
			`tracking_number`  VARCHAR(128) NOT NULL DEFAULT '',
			`tracking_url`     VARCHAR(512) NOT NULL DEFAULT '',
			`label_url`        VARCHAR(512) NOT NULL DEFAULT '',
			`created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `customer_id` (`customer_id`),
			KEY `status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}order_items` (
			`id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`order_id`        INT UNSIGNED  NOT NULL,
			`product_id`      INT UNSIGNED  NOT NULL,
			`name`            VARCHAR(255)  NOT NULL,
			`price`           DECIMAL(10,2) NOT NULL,
			`qty`             INT UNSIGNED  NOT NULL DEFAULT 1,
			`options`         TEXT,
			`options_summary` TEXT,
			PRIMARY KEY (`id`),
			KEY `order_id` (`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}incomplete` (
			`id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`entity`     VARCHAR(64)   NOT NULL,
			`entity_id`  INT UNSIGNED  NOT NULL,
			`label`      VARCHAR(255)  NOT NULL,
			`message`    VARCHAR(500)  NOT NULL,
			`created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `entity_item` (`entity`, `entity_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}options` (
			`id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`name`          VARCHAR(255)  NOT NULL,
			`type`          VARCHAR(32)   NOT NULL DEFAULT 'select',
			`placeholder`   VARCHAR(255)  NOT NULL DEFAULT '',
			`display_order` INT UNSIGNED  NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}option_values` (
			`id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`option_id`     INT UNSIGNED  NOT NULL,
			`text`          VARCHAR(255)  NOT NULL,
			`image`         VARCHAR(255)  NOT NULL DEFAULT '',
			`display_order` INT UNSIGNED  NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `option_id` (`option_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}product_options` (
			`id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`product_id`    INT UNSIGNED  NOT NULL,
			`option_id`     INT UNSIGNED  NOT NULL,
			`label`         VARCHAR(255)  NOT NULL DEFAULT '',
			`required`      TINYINT(1)    NOT NULL DEFAULT 0,
			`display_order` INT UNSIGNED  NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `product_id` (`product_id`),
			KEY `option_id`  (`option_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}product_option_values` (
			`id`                INT UNSIGNED   NOT NULL AUTO_INCREMENT,
			`product_option_id` INT UNSIGNED   NOT NULL,
			`option_value_id`   INT UNSIGNED   NOT NULL,
			`label`             VARCHAR(255)   NOT NULL DEFAULT '',
			`price_modifier`    DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
			`price_prefix`      CHAR(1)        NOT NULL DEFAULT '+',
			`weight_modifier`   DECIMAL(10,4)  NOT NULL DEFAULT 0.0000,
			`weight_prefix`     CHAR(1)        NOT NULL DEFAULT '+',
			`stock`             INT            NOT NULL DEFAULT 0,
			`subtract_stock`    TINYINT(1)     NOT NULL DEFAULT 0,
			`enabled`           TINYINT(1)     NOT NULL DEFAULT 1,
			PRIMARY KEY (`id`),
			KEY `product_option_id` (`product_option_id`),
			KEY `option_value_id`   (`option_value_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		// ── Pages ──────────────────────────────────────────────────────────────
		"CREATE TABLE IF NOT EXISTS `{$p}pages` (
			`id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`title`           VARCHAR(255)  NOT NULL,
			`slug`            VARCHAR(255)  NOT NULL,
			`page_type`       VARCHAR(32)   NOT NULL DEFAULT 'page',
			`status`          TINYINT(1)    NOT NULL DEFAULT 1,
			`seo_title`       VARCHAR(300)  NOT NULL DEFAULT '',
			`seo_keywords`    VARCHAR(500)  NOT NULL DEFAULT '',
			`seo_description` VARCHAR(500)  NOT NULL DEFAULT '',
			`display_order`   INT UNSIGNED  NOT NULL DEFAULT 0,
			`created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `slug` (`slug`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}page_blocks` (
			`id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`page_id`       INT UNSIGNED  NOT NULL,
			`block_type`    VARCHAR(64)   NOT NULL,
			`settings`      JSON,
			`display_order` INT UNSIGNED  NOT NULL DEFAULT 0,
			`enabled`       TINYINT(1)    NOT NULL DEFAULT 1,
			`cols`          TINYINT(1)    NOT NULL DEFAULT 4,
			`col_start`     TINYINT(1)    NOT NULL DEFAULT 1,
			`col_span`      TINYINT(1)    NOT NULL DEFAULT 4,
			`row`           SMALLINT      NOT NULL DEFAULT 0,
			`row_span`      TINYINT(1)    NOT NULL DEFAULT 1,
			`name`          VARCHAR(255)  NOT NULL DEFAULT '',
			PRIMARY KEY (`id`),
			KEY `page_id` (`page_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		// ── Block library ──────────────────────────────────────────────────────
		"CREATE TABLE IF NOT EXISTS `{$p}block_library` (
			`id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`block_id`   INT UNSIGNED NOT NULL,
			`name`       VARCHAR(255) NOT NULL,
			`created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `block_id` (`block_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		// ── Slideshows ─────────────────────────────────────────────────────────
		"CREATE TABLE IF NOT EXISTS `{$p}slideshows` (
			`id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`name`          VARCHAR(255)  NOT NULL,
			`transition`    VARCHAR(16)   NOT NULL DEFAULT 'fade',
			`interval`      INT UNSIGNED  NOT NULL DEFAULT 5000,
			`status`        TINYINT(1)    NOT NULL DEFAULT 1,
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}slideshow_slides` (
			`id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`slideshow_id`  INT UNSIGNED  NOT NULL,
			`image`         VARCHAR(255)  NOT NULL DEFAULT '',
			`heading`       VARCHAR(255)  NOT NULL DEFAULT '',
			`subtext`       TEXT,
			`btn_label`     VARCHAR(100)  NOT NULL DEFAULT '',
			`btn_url`       VARCHAR(500)  NOT NULL DEFAULT '',
			`display_order` INT UNSIGNED  NOT NULL DEFAULT 0,
			`enabled`       TINYINT(1)    NOT NULL DEFAULT 1,
			PRIMARY KEY (`id`),
			KEY `slideshow_id` (`slideshow_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		// ── Contact forms ──────────────────────────────────────────────────────
		"CREATE TABLE IF NOT EXISTS `{$p}contact_forms` (
			`id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`name`    VARCHAR(255) NOT NULL,
			`fields`  JSON,
			`email_to` VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}messages` (
			`id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`form_id`    INT UNSIGNED,
			`data`       JSON,
			`ip`         VARCHAR(45)  NOT NULL DEFAULT '',
			`read_at`    DATETIME,
			`created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `form_id` (`form_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		// ── Menus ──────────────────────────────────────────────────────────────
		"CREATE TABLE IF NOT EXISTS `{$p}menus` (
			`id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`name`         VARCHAR(255) NOT NULL,
			`menu_role`    VARCHAR(32)  NOT NULL DEFAULT '',
			`menu_type`    VARCHAR(32)  NOT NULL DEFAULT 'links_pages',
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}menu_items` (
			`id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`menu_id`       INT UNSIGNED NOT NULL,
			`parent_id`     INT UNSIGNED NOT NULL DEFAULT 0,
			`label`         VARCHAR(255) NOT NULL DEFAULT '',
			`item_type`     VARCHAR(32)  NOT NULL DEFAULT 'url',
			`url`           VARCHAR(500) NOT NULL DEFAULT '',
			`page_id`       INT UNSIGNED,
			`category_id`   INT UNSIGNED,
			`submenu_id`    INT UNSIGNED,
			`show_count`    TINYINT(1)   NOT NULL DEFAULT 0,
			`target`        VARCHAR(16)  NOT NULL DEFAULT '',
			`js_code`       TEXT,
			`settings`      JSON,
			`display_order` INT UNSIGNED NOT NULL DEFAULT 0,
			`enabled`       TINYINT(1)   NOT NULL DEFAULT 1,
			PRIMARY KEY (`id`),
			KEY `menu_id` (`menu_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
	];

	try {
		foreach ($tables as $sql) {
			$pdo->exec($sql);
		}
	} catch (PDOException $e) {
		out(false, 'Failed to create tables: ' . $e->getMessage());
	}

	// Insert admin user
	try {
		$hash = password_hash($admin_pass, PASSWORD_BCRYPT);
		$st   = $pdo->prepare("INSERT INTO `{$p}admin` (username, email, password) VALUES (?, ?, ?)
			ON DUPLICATE KEY UPDATE password = VALUES(password)");
		$st->execute([$admin_user, $admin_email, $hash]);
	} catch (PDOException $e) {
		out(false, 'Failed to create admin account: ' . $e->getMessage());
	}

	// Insert site settings
	try {
		$st = $pdo->prepare("INSERT INTO `{$p}settings` (`key`, `value`) VALUES (?, ?)
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
		foreach ([
			'site_name'           => $site_name,
			'site_currency'       => $site_currency,
			'site_email'          => $site_email,
			'admin_path'          => $admin_path,
			'store_phone'         => '',
			'store_logo_url'      => '',
			'img_retain_names'    => '0',
			'img_resize_on_upload'=> '1',
			'img_orig_max'        => '600',
			'img_admin_size'      => '160',
			'img_admin_quality'   => '75',
			'img_fm_size'         => '50',
			'img_fm_quality'      => '60',
			'img_product_width'   => '600',
			'img_product_quality' => '80',
			'img_orig_max'        => '600',
			'img_admin_size'      => '160',
			'img_admin_quality'   => '75',
			'img_fm_size'         => '50',
			'img_fm_quality'      => '60',
			'deepai_key'          => '',
			'img_related_size'    => '200',
			'related_max_items'   => '0',
			'stripe_mode'         => 'test',
		] as $k => $v) {
			$st->execute([$k, $v]);
		}
	} catch (PDOException $e) {
		out(false, 'Failed to save settings: ' . $e->getMessage());
	}

	// Seed default pages
	try {
		$pages = [
			['home',            'Home',             'home',           1],
			['about-us',        'About Us',         'page',           1],
			['contact-us',      'Contact Us',       'page',           1],
			['returns',         'Returns',          'page',           1],
			['privacy-policy',  'Privacy Policy',   'page',           1],
			['terms',           'Terms & Conditions','page',          1],
			['sitemap',         'Site Map',         'page',           1],
			['my-account',      'My Account',       'account',        1],
			['cart',            'Cart',             'cart',           1],
			['checkout',        'Checkout',         'checkout',       1],
			['product',         'Product',          'product',        1],
		];
		$sp = $pdo->prepare(
			"INSERT IGNORE INTO `{$p}pages` (slug, title, page_type, status, display_order)
			 VALUES (?, ?, ?, ?, ?)"
		);
		foreach ($pages as $i => $pg) {
			$sp->execute([$pg[0], $pg[1], $pg[2], $pg[3], $i]);
		}

		// Seed sitemap block on sitemap page
		$sm_id = $pdo->query("SELECT id FROM `{$p}pages` WHERE slug='sitemap' LIMIT 1")->fetchColumn();
		if ($sm_id) {
			$pdo->prepare(
				"INSERT IGNORE INTO `{$p}page_blocks` (page_id, block_type, settings, display_order, enabled)
				 VALUES (?, 'sitemap', '{}', 0, 1)"
			)->execute([$sm_id]);
		}

		// Seed core blocks for cart, checkout, and product pages
		$core_settings = json_encode(['is_core' => true]);
		$cart_id = $pdo->query("SELECT id FROM `{$p}pages` WHERE slug='cart' LIMIT 1")->fetchColumn();
		if ($cart_id) {
			$pdo->prepare(
				"INSERT IGNORE INTO `{$p}page_blocks`
				 (page_id, block_type, settings, display_order, enabled, cols, col_start, col_span, `row`, row_span)
				 VALUES (?, 'cart_contents', ?, 1, 1, 4, 1, 4, 0, 1)"
			)->execute([$cart_id, $core_settings]);
		}
		$checkout_id = $pdo->query("SELECT id FROM `{$p}pages` WHERE slug='checkout' LIMIT 1")->fetchColumn();
		if ($checkout_id) {
			$pdo->prepare(
				"INSERT IGNORE INTO `{$p}page_blocks`
				 (page_id, block_type, settings, display_order, enabled, cols, col_start, col_span, `row`, row_span)
				 VALUES (?, 'checkout_form', ?, 1, 1, 4, 1, 4, 0, 1)"
			)->execute([$checkout_id, $core_settings]);
		}
		$product_id = $pdo->query("SELECT id FROM `{$p}pages` WHERE slug='product' LIMIT 1")->fetchColumn();
		if ($product_id) {
			$pdo->prepare(
				"INSERT IGNORE INTO `{$p}page_blocks`
				 (page_id, block_type, settings, display_order, enabled, cols, col_start, col_span, `row`, row_span)
				 VALUES (?, 'product_view', ?, 1, 1, 4, 1, 4, 0, 1)"
			)->execute([$product_id, $core_settings]);
		}

		// Seed default contact form
		$cf_id = $pdo->query("SELECT id FROM `{$p}contact_forms` LIMIT 1")->fetchColumn();
		if (!$cf_id) {
			$fields = json_encode([
				['name'=>'name',    'label'=>'Name',    'type'=>'text',     'required'=>true],
				['name'=>'email',   'label'=>'Email',   'type'=>'email',    'required'=>true],
				['name'=>'subject', 'label'=>'Subject', 'type'=>'text',     'required'=>false],
				['name'=>'message', 'label'=>'Message', 'type'=>'textarea', 'required'=>true],
			]);
			$pdo->prepare(
				"INSERT INTO `{$p}contact_forms` (name, fields, email_to) VALUES ('Contact Us', ?, ?)"
			)->execute([$fields, $site_email]);
		}
	} catch (PDOException $e) {
		// Non-fatal — pages can be created manually
	}

	// Write config.php
	$config_path = dirname(__DIR__) . '/cfg/config.php';
	$ts     = date('Y-m-d H:i:s');
	$config = <<<PHP
<?php
/**
 * new-cart Configuration
 * Generated by install wizard on {$ts}.
 */

// Database
define('DB_HOST',    '{$host}');
define('DB_NAME',    '{$name}');
define('DB_USER',    '{$user}');
define('DB_PASS',    '{$pass}');
define('DB_PREFIX',  '{$prefix}');
define('DB_CHARSET', 'utf8mb4');

// Paths
define('DIR_ROOT',     __DIR__ . '/../');
define('DIR_CTL',      DIR_ROOT . 'ctl/');
define('DIR_TPL',      DIR_ROOT . 'tpl/');
define('DIR_LIB',      DIR_ROOT . 'lib/');
define('DIR_CACHE',    DIR_ROOT . 'cache/');
define('DIR_IMG',      DIR_ROOT . 'img/');
define('DIR_USERMODS', DIR_ROOT . 'usermods/');
define('DIR_ADMIN',    DIR_ROOT . 'admin/');
define('DIR_INSTALL',  DIR_ROOT . 'install/');

// URLs
define('URL_ROOT',       '/');
define('URL_ADMIN',      '/{$admin_path}/');
define('URL_ADMIN_REAL', '/admin/');
define('URL_IMG',        '/img/');

// Admin path (public-facing URL segment)
define('ADMIN_PATH', '{$admin_path}');

// Site
define('SITE_NAME',     '{$site_name}');
define('SITE_CURRENCY', '{$site_currency}');
define('SITE_EMAIL',    '{$site_email}');

// Error handling
define('DISPLAY_ERRORS', false);
define('LOG_ERRORS',     true);
define('ERROR_LOG',      DIR_ROOT . 'logs/error.log');

// Session
define('SESSION_NAME',     'newcart');
define('SESSION_LIFETIME', 86400);

// Smarty
define('SMARTY_FORCE_COMPILE', false);
define('SMARTY_CACHING',       false);
PHP;

	if (file_put_contents($config_path, $config) === false) {
		out(false, 'Could not write cfg/config.php — check directory permissions.');
	}

	// Write .htaccess
	$htaccess_path = dirname(__DIR__) . '/.htaccess';
	$htaccess = <<<HTACCESS
Options -Indexes
DirectoryIndex index.php

RewriteEngine On

# Map public admin path to admin/index.php
RewriteRule ^{$admin_path}/?$ /admin/index.php [L,QSA]
RewriteRule ^{$admin_path}/(.*)$ /admin/index.php [L,QSA]

# Route everything else through index.php (skip real files/dirs)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /index.php [L,QSA]
HTACCESS;

	if (file_put_contents($htaccess_path, $htaccess) === false) {
		out(false, 'Could not write .htaccess — check directory permissions.');
	}

	file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

	out(true, 'Installation complete.', ['redirect' => '/' . $admin_path . '/']);
}

out(false, 'Unknown action.');
