<?php
/**
 * CandyCart — shared utility functions
 */

// ── Output helpers ─────────────────────────────────────────────────────────────

function h(mixed $val): string {
	return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float $amount): string {
	return SITE_CURRENCY . number_format($amount, 2);
}

function json_out(mixed $data, int $status = 200): never {
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($data);
	exit;
}

// ── Request helpers ────────────────────────────────────────────────────────────

function get(string $key, mixed $default = ''): mixed {
	return $_GET[$key] ?? $default;
}

function post(string $key, mixed $default = ''): mixed {
	return $_POST[$key] ?? $default;
}

function is_post(): bool {
	return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function is_ajax(): bool {
	return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
		&& strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ── Session helpers ────────────────────────────────────────────────────────────

function session_get(string $key, mixed $default = null): mixed {
	return $_SESSION[$key] ?? $default;
}

function session_set(string $key, mixed $val): void {
	$_SESSION[$key] = $val;
}

function session_del(string $key): void {
	unset($_SESSION[$key]);
}

function flash_set(string $type, string $message): void {
	$_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array {
	$flash = $_SESSION['_flash'] ?? null;
	unset($_SESSION['_flash']);
	return $flash;
}

// ── String helpers ─────────────────────────────────────────────────────────────

function slug(string $str): string {
	$str = strtolower(trim($str));
	$str = preg_replace('/[^a-z0-9]+/', '-', $str);
	return trim($str, '-');
}

function truncate(string $str, int $len = 100, string $suffix = '…'): string {
	return mb_strlen($str) > $len
		? mb_substr($str, 0, $len) . $suffix
		: $str;
}

// ── Redirect ───────────────────────────────────────────────────────────────────

function redirect(string $url): never {
	header('Location: ' . $url);
	exit;
}

// ── Auth ───────────────────────────────────────────────────────────────────────

// Access level flags
const ACCESS_ADD     =   2;
const ACCESS_DELETE  =   4;
const ACCESS_EDIT    =   8;
const ACCESS_PRICING =  16;
const ACCESS_REPORTS =  32;
const ACCESS_REVIEWS =  64;
const ACCESS_PAGES   = 128;

// Named shortcut levels
const ACCESS_ADMIN        = 254; // all
const ACCESS_SUPER_EDITOR = 234; // no delete, no pricing
const ACCESS_EDITOR       =  10; // add + edit
const ACCESS_USER         =   0; // read only

function is_logged_in(): bool {
	return !empty($_SESSION['customer_id']);
}

function is_admin(): bool {
	return !empty($_SESSION['admin_id']);
}

function admin_can(int $flag): bool {
	$level = (int)($_SESSION['admin_access'] ?? 0);
	return ($level & $flag) === $flag;
}

function require_admin(): void {
	if (!is_admin()) redirect(URL_ADMIN . '?route=login');
}

function require_access(int $flag): void {
	if (!is_admin())        redirect(URL_ADMIN . '?route=login');
	if (!admin_can($flag))  redirect(URL_ADMIN . '?route=dashboard');
}

function require_login(): void {
	if (!is_logged_in()) redirect(URL_ROOT . '?route=account/login');
}

// ── Incomplete item reminders ──────────────────────────────────────────────────

function reminder_add(string $entity, int $entity_id, string $label, string $message): void {
	$p = DB_PREFIX;
	DB::exec(
		"INSERT INTO `{$p}incomplete` (entity, entity_id, label, message)
		 VALUES (?, ?, ?, ?)
		 ON DUPLICATE KEY UPDATE label=VALUES(label), message=VALUES(message)",
		[$entity, $entity_id, $label, $message]
	);
}

function reminder_clear(string $entity, int $entity_id): void {
	$p = DB_PREFIX;
	DB::exec(
		"DELETE FROM `{$p}incomplete` WHERE entity=? AND entity_id=?",
		[$entity, $entity_id]
	);
}

function reminder_list(): array {
	$p = DB_PREFIX;
	try {
		return DB::rows("SELECT * FROM `{$p}incomplete` ORDER BY created_at ASC");
	} catch (Exception $e) {
		return [];
	}
}

function reminder_ids(string $entity): array {
	$p = DB_PREFIX;
	try {
		$rows = DB::rows(
			"SELECT entity_id FROM `{$p}incomplete` WHERE entity=?",
			[$entity]
		);
		return array_column($rows, 'entity_id');
	} catch (Exception $e) {
		return [];
	}
}

// ── Error handling ─────────────────────────────────────────────────────────────

function cc_error_handler(int $code, string $message, string $file, int $line): bool {
	if (!($code & error_reporting())) return false;
	$entry = date('[Y-m-d H:i:s]') . " PHP Error [{$code}]: {$message} in {$file} on line {$line}" . PHP_EOL;
	if (LOG_ERRORS) {
		@file_put_contents(ERROR_LOG, $entry, FILE_APPEND);
	}
	if (DISPLAY_ERRORS) {
		echo "<pre style='color:red'>{$entry}</pre>";
	}
	return true;
}

function cc_exception_handler(Throwable $e): void {
	$entry = date('[Y-m-d H:i:s]') . " Exception: " . $e->getMessage()
		. " in " . $e->getFile() . " on line " . $e->getLine()
		. PHP_EOL . $e->getTraceAsString() . PHP_EOL;
	if (LOG_ERRORS) {
		@file_put_contents(ERROR_LOG, $entry, FILE_APPEND);
	}
	if (DISPLAY_ERRORS) {
		echo "<pre style='color:red'>" . h($entry) . "</pre>";
	} else {
		http_response_code(500);
		echo "An error occurred.";
	}
}

// ── Catalog sidebar data ───────────────────────────────────────────────────────
function load_menu(int $menu_id, string $p): array {
	if (!$menu_id) return [];
	$items = DB::rows(
		"SELECT mi.*, p.slug AS page_slug, c.slug AS category_slug,
		        c.name AS category_name
		 FROM `{$p}menu_items` mi
		 LEFT JOIN `{$p}pages` p ON p.id = mi.page_id
		 LEFT JOIN `{$p}categories` c ON c.id = mi.category_id
		 WHERE mi.menu_id=? AND mi.enabled=1
		 ORDER BY mi.display_order ASC",
		[$menu_id]
	);
	foreach ($items as &$item) {
		if ($item['item_type'] === 'category_tree') {
			// Expand all active categories
			$cats = DB::rows(
				"SELECT slug, name,
				 (SELECT COUNT(*) FROM `{$p}categories_products` cp
				  JOIN `{$p}products` pp ON pp.id=cp.product_id
				  WHERE cp.category_id=c.id AND pp.status>0) AS cnt
				 FROM `{$p}categories` c WHERE status>0 ORDER BY display_order ASC, name ASC"
			);
			$item['expanded_categories'] = $cats;
		}
		if ($item['show_count'] && $item['category_id']) {
			$item['product_count'] = (int)DB::val(
				"SELECT COUNT(*) FROM `{$p}categories_products` cp
				 JOIN `{$p}products` p ON p.id=cp.product_id
				 WHERE cp.category_id=? AND p.status>0",
				[$item['category_id']]
			);
		}
		if ($item['submenu_id']) {
			$item['submenu_items'] = load_menu((int)$item['submenu_id'], $p);
		}
	}
	unset($item);
	return $items;
}

function catalog_sidebar(Smarty $smarty): void {
	$p = DB_PREFIX;

	// Active categories
	$cats = DB::rows(
		"SELECT id, name, slug FROM `{$p}categories`
		 WHERE status > 0 ORDER BY display_order ASC, name ASC"
	);
	$smarty->assign('sidebar_categories', $cats);

	// What's New (last 6 products)
	$new = DB::rows(
		"SELECT id, name, slug FROM `{$p}products`
		 WHERE status > 0 ORDER BY id DESC LIMIT 6"
	);
	$smarty->assign('sidebar_new', $new);

	// Top sellers (by order count)
	$top = DB::rows(
		"SELECT p.id, p.name, p.slug, COUNT(oi.id) AS sold
		 FROM `{$p}products` p
		 LEFT JOIN `{$p}order_items` oi ON oi.product_id = p.id
		 WHERE p.status > 0
		 GROUP BY p.id ORDER BY sold DESC, p.name ASC LIMIT 6"
	);
	$smarty->assign('sidebar_top', $top);

	// Global menus
	$menu1_row = DB::row("SELECT id FROM `{$p}menus` WHERE menu_role='menu1' LIMIT 1");
	$menu2_row = DB::row("SELECT id FROM `{$p}menus` WHERE menu_role='menu2' LIMIT 1");
	$smarty->assign('menu1', $menu1_row ? load_menu((int)$menu1_row['id'], $p) : []);
	$smarty->assign('menu2', $menu2_row ? load_menu((int)$menu2_row['id'], $p) : []);

	// Store settings for layout
	$s = [];
	$rows = DB::rows("SELECT `key`,`value` FROM `{$p}settings` WHERE `key` IN ('store_phone','store_logo_url')");
	foreach ($rows as $row) $s[$row['key']] = $row['value'];
	$smarty->assign('store_phone',    $s['store_phone']    ?? '');
	$smarty->assign('store_logo_url', $s['store_logo_url'] ?? '');
	$smarty->assign('cart_subtotal',  Cart::subtotal());
	$smarty->assign('wl_count',       count(WishList::guestLists()));
}
