<?php
/**
 * new-cart admin — front controller
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────────
require __DIR__ . '/../cfg/config.php';
require DIR_LIB . 'db.php';
require DIR_LIB . 'functions.php';
require DIR_LIB . 'hook.php';
require DIR_LIB . 'plugin-loader.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// set_error_handler('cc_error_handler');
// set_exception_handler('cc_exception_handler');

ini_set('session.name', SESSION_NAME);
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
session_start();

DB::connect();

// ── Load live settings from DB (overrides config.php constants) ────────────────
$_nc_settings = [];
try {
	$_nc_settings_rows = DB::rows("SELECT `key`, `value` FROM `" . DB_PREFIX . "settings`");
	foreach ($_nc_settings_rows as $_r) {
		$_nc_settings[$_r['key']] = $_r['value'];
	}
} catch (Exception $e) { /* DB may not be ready on first install */ }

$_nc_site_name = $_nc_settings['site_name'] ?? SITE_NAME;

// ── Boot plugins ───────────────────────────────────────────────────────────────
PluginLoader::boot();
Hook::fire('admin.bootstrap.before');

// ── Smarty ─────────────────────────────────────────────────────────────────────
require DIR_LIB . 'vendor/smarty/smarty/libs/Smarty.class.php';

$smarty = new Smarty();

// Plugin template dirs take priority over default
$tpl_dirs = array_merge(
	PluginLoader::adminTemplateDirs(),
	[DIR_ADMIN . 'tpl/']
);
$smarty->setTemplateDir($tpl_dirs);
$smarty->setCompileDir(DIR_CACHE . 'tpl/admin/');
$smarty->setCacheDir(DIR_CACHE . 'smarty/admin/');
$smarty->force_compile = SMARTY_FORCE_COMPILE;
$smarty->caching       = SMARTY_CACHING;

$smarty->assign('site_name',   $_nc_site_name);
$smarty->assign('url_root',    URL_ROOT);
$smarty->assign('url_admin',   URL_ADMIN);
$smarty->assign('flash',       flash_get());
$smarty->assign('admin_user',  $_SESSION['admin_username'] ?? '');
$smarty->assign('admin_avatar',$_SESSION['admin_avatar']   ?? '');
$smarty->assign('access',      (int)($_SESSION['admin_access'] ?? 0));
$smarty->assign('reminders',   reminder_list());

// ── Route ──────────────────────────────────────────────────────────────────────
$route = preg_replace('/[^a-z0-9_\/\-]/', '', strtolower(get('route', 'dashboard')));

// Login doesn't require auth
if ($route !== 'login') {
	require_admin();
}

// Support nested routes: "categories/ajax" → ctl/categories/ajax.php
$ctl_file = DIR_ADMIN . 'ctl/' . $route . '.php';

// Fallback: try route as directory with index
if (!file_exists($ctl_file)) {
	$ctl_file = DIR_ADMIN . 'ctl/' . $route . '/index.php';
}

if (!file_exists($ctl_file)) {
	$ctl_file = DIR_ADMIN . 'ctl/404.php';
}

require $ctl_file;
