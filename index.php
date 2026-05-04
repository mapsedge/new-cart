<?php
/**
 * new-cart — front controller
 */

// ── First run: redirect to install wizard ──────────────────────────────────────
if (!file_exists(__DIR__ . '/install/.installed') || !file_exists(__DIR__ . '/cfg/config.php')) {
	header('Location: /install/');
	exit;
}

// ── Config ─────────────────────────────────────────────────────────────────────
require __DIR__ . '/cfg/config.php';

// ── Core libs ──────────────────────────────────────────────────────────────────
require DIR_LIB . 'db.php';
require DIR_LIB . 'functions.php';
require DIR_LIB . 'hook.php';
require DIR_LIB . 'page_block_helper.php';
require DIR_LIB . 'cart.php';

// ── Error handlers ─────────────────────────────────────────────────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);

// set_error_handler('cc_error_handler');
// set_exception_handler('cc_exception_handler');

// ── Session ────────────────────────────────────────────────────────────────────
ini_set('session.name', SESSION_NAME);
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
session_start();

// ── DB ─────────────────────────────────────────────────────────────────────────
DB::connect();

// ── Plugins ────────────────────────────────────────────────────────────────────
require DIR_LIB . 'plugin-loader.php';
PluginLoader::boot();

// ── Smarty ─────────────────────────────────────────────────────────────────────
require DIR_LIB . 'vendor/smarty/smarty/libs/Smarty.class.php';

$smarty = new Smarty();
$smarty->setTemplateDir(DIR_TPL);
$smarty->setCompileDir(DIR_CACHE . 'tpl/');
$smarty->setCacheDir(DIR_CACHE . 'smarty/');
$smarty->force_compile = SMARTY_FORCE_COMPILE;
$smarty->caching       = SMARTY_CACHING;
$smarty->registerClass('Smarty', 'Smarty');

// ── Global template vars ───────────────────────────────────────────────────────
// Read live values from settings table; fall back to install-time constants
$_nc_site_settings = [];
try {
	$_nc_site_rows = DB::rows("SELECT `key`, `value` FROM `" . DB_PREFIX . "settings` WHERE `key` IN ('site_name','site_currency','img_cart_size','smarty_debug','store_phone','store_logo_url')");
	foreach ($_nc_site_rows as $_r) $_nc_site_settings[$_r['key']] = $_r['value'];
} catch (Exception $e) {}

$smarty->debugging = !empty($_nc_site_settings['smarty_debug']);

$smarty->assign('site_name',     $_nc_site_settings['site_name']     ?? SITE_NAME);
$smarty->assign('site_currency', $_nc_site_settings['site_currency'] ?? SITE_CURRENCY);
$smarty->assign('img_cart_size', max(40, (int)($_nc_site_settings['img_cart_size'] ?? 100)));
$smarty->assign('store_phone',    $_nc_site_settings['store_phone']    ?? '');
$smarty->assign('store_logo_url', $_nc_site_settings['store_logo_url'] ?? '');
$smarty->assign('url_root',      URL_ROOT);
$smarty->assign('url_admin',     URL_ADMIN);
$smarty->assign('url_img',       URL_IMG);
$smarty->assign('flash',         flash_get());
$smarty->assign('is_logged_in',  is_logged_in());
$smarty->assign('cart_count',    Cart::count());
$smarty->assign('cart_subtotal', Cart::subtotal());

// ── Global layout data ─────────────────────────────────────────────────────────
try {
	$_p = DB_PREFIX;
	$smarty->assign('cat_nav', DB::rows(
		"SELECT id, name, slug FROM `{$_p}categories` WHERE parent_id=0 AND status=1 ORDER BY display_order ASC, name ASC"
	));

	// Sidebar blocks — from the 'sidebar' system page
	$_sidebar_page = DB::row("SELECT id FROM `{$_p}pages` WHERE slug='sidebar' AND page_type='sidebar' LIMIT 1");
	if ($_sidebar_page) {
		require_once DIR_LIB . 'page_block_helper.php';
		$smarty->assign('sidebar_blocks', hydrate_page_blocks((int)$_sidebar_page['id'], $_p, $smarty));
	}

	// Second-level thumbnail size (featured products blocks)
	$_sl = DB::row("SELECT `value` FROM `{$_p}settings` WHERE `key`='img_second_level_size' LIMIT 1");
	$smarty->assign('second_level_size', max(40, (int)(($_sl['value'] ?? null) ?: 200)));
} catch (Exception $_e) {}

// ── Route ──────────────────────────────────────────────────────────────────────
// Detect SEO-friendly paths: category/slug, product/slug, cart, checkout, order-complete
$request_uri  = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$uri_parts    = explode('/', $request_uri, 2);

// Map URI patterns to routes
if ($uri_parts[0] === 'category' && !empty($uri_parts[1])) {
	$_GET['route'] = 'category';
	$_GET['slug']  = $uri_parts[1];
} elseif ($uri_parts[0] === 'product' && !empty($uri_parts[1])) {
	$_GET['route'] = 'product';
	$_GET['slug']  = $uri_parts[1];
} elseif ($uri_parts[0] === 'cart') {
	$_GET['route'] = 'cart';
} elseif ($uri_parts[0] === 'checkout') {
	$_GET['route'] = 'checkout';
} elseif ($uri_parts[0] === 'order-complete') {
	$_GET['route'] = 'order-complete';
} elseif ($uri_parts[0] === 'page' && !empty($uri_parts[1])) {
	$_GET['route'] = 'page';
	$_GET['slug']  = $uri_parts[1];
} elseif ($uri_parts[0] === 'account') {
	$_GET['route'] = 'account';
} elseif ($uri_parts[0] === 'page-preview' && !empty($uri_parts[1])) {
	$_GET['route'] = 'page-preview';
	$_GET['token'] = $uri_parts[1];
} elseif ($request_uri === 'sitemap.xml') {
	$_GET['route'] = 'sitemap-xml';
}

$route = preg_replace('/[^a-z0-9_\/\-]/', '', strtolower(get('route', 'home')));

$ctl_file = DIR_CTL . $route . '.php';

if (!file_exists($ctl_file)) {
	$parts      = explode('/', $route, 2);
	$plugin_ctl = DIR_ROOT . 'plugins/.' . ($parts[0] ?? '') . '/catalog/' . ($parts[1] ?? 'index') . '.php';
	if (file_exists($plugin_ctl)) {
		$ctl_file = $plugin_ctl;
	} else {
		$ctl_file = DIR_CTL . '404.php';
	}
}

require $ctl_file;
