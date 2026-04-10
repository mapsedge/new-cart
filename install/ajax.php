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
			`seo_title`     VARCHAR(300) NOT NULL DEFAULT '',
			`html_short`    TEXT,
			`html_long`     MEDIUMTEXT,
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
			`status`        VARCHAR(32)   NOT NULL DEFAULT 'pending',
			`total`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`shipping`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`tax`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`ship_name`     VARCHAR(128),
			`ship_address1` VARCHAR(255),
			`ship_address2` VARCHAR(255),
			`ship_city`     VARCHAR(128),
			`ship_state`    VARCHAR(64),
			`ship_zip`      VARCHAR(20),
			`ship_country`  VARCHAR(64),
			`created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `customer_id` (`customer_id`),
			KEY `status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

		"CREATE TABLE IF NOT EXISTS `{$p}order_items` (
			`id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`order_id`   INT UNSIGNED  NOT NULL,
			`product_id` INT UNSIGNED  NOT NULL,
			`name`       VARCHAR(255)  NOT NULL,
			`price`      DECIMAL(10,2) NOT NULL,
			`qty`        INT UNSIGNED  NOT NULL DEFAULT 1,
			`options`    TEXT,
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
			'site_name'     => $site_name,
			'site_currency' => $site_currency,
			'site_email'    => $site_email,
			'admin_path'    => $admin_path,
		] as $k => $v) {
			$st->execute([$k, $v]);
		}
	} catch (PDOException $e) {
		out(false, 'Failed to save settings: ' . $e->getMessage());
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

# Block direct browser access to admin/
RewriteCond %{REQUEST_URI} ^/admin(/|$) [NC]
RewriteRule ^ - [F,L]

# Map public admin path to admin/index.php, passing query string through
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
