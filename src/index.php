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

// ── Route ──────────────────────────────────────────────────────────────────────
$route = preg_replace('/[^a-z0-9_\/\-]/', '', strtolower(get('route', 'home')));

// Support nested routes: "category/list" → ctl/category/list.php
$ctl_file = DIR_CTL . $route . '.php';

if (!file_exists($ctl_file)) {
	$ctl_file = DIR_CTL . '404.php';
}

require $ctl_file;
