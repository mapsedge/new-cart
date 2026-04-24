<?php
require_access(ACCESS_EDIT);
$p          = DB_PREFIX;
$id         = (int)get('id');
$page       = $id ? DB::row("SELECT * FROM `{$p}pages` WHERE id=?", [$id]) : null;
$slideshows = DB::rows("SELECT id, name FROM `{$p}slideshows` ORDER BY name ASC");
$categories = DB::rows("SELECT id, name FROM `{$p}categories` WHERE status>0 ORDER BY name ASC");
$menus      = DB::rows("SELECT id, name FROM `{$p}menus` ORDER BY name ASC");
$all_pages  = DB::rows("SELECT id, title FROM `{$p}pages` ORDER BY title ASC");
$has_forms  = false;

$home_page_id = (int)(DB::val("SELECT `value` FROM `{$p}settings` WHERE `key`='home_page_id'") ?? 0);

$smarty->assign('edit_page',    $page);
$smarty->assign('edit_page_id', $id);
$smarty->assign('slideshows',   $slideshows);
$smarty->assign('categories',   $categories);
$smarty->assign('menus',        $menus);
$smarty->assign('all_pages',    $all_pages);
$smarty->assign('has_forms',    $has_forms);
$smarty->assign('home_page_id', $home_page_id);
$smarty->assign('admin_page_scripts', Hook::filter('admin.page.head', '', ['route' => 'page-edit']));
$smarty->display('page-edit.html');
