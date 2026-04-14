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
require DIR_LIB . 'cart.php';
require DIR_LIB . 'wishlist.php';

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

// ── Smarty ─────────────────────────────────────────────────────────────────────
require DIR_LIB . 'vendor/smarty/smarty/libs/Smarty.class.php';

$smarty = new Smarty();
$smarty->setTemplateDir(DIR_TPL);
$smarty->setCompileDir(DIR_CACHE . 'tpl/');
$smarty->setCacheDir(DIR_CACHE . 'smarty/');
$smarty->force_compile = SMARTY_FORCE_COMPILE;
$smarty->caching       = SMARTY_CACHING;

// ── Global template vars ───────────────────────────────────────────────────────
$smarty->assign('site_name',     SITE_NAME);
$smarty->assign('site_currency', SITE_CURRENCY);
$smarty->assign('url_root',      URL_ROOT);
$smarty->assign('url_admin',     URL_ADMIN);
$smarty->assign('url_img',       URL_IMG);
$smarty->assign('flash',         flash_get());
$smarty->assign('is_logged_in',  is_logged_in());
$smarty->assign('cart_count',    Cart::count());

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
} elseif ($uri_parts[0] === 'wishlist') {
	$_GET['route'] = 'wishlist';
	if (!empty($uri_parts[1])) $_GET['slug'] = $uri_parts[1];
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
