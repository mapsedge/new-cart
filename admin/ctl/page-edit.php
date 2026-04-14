<?php
require_access(ACCESS_EDIT);
$p      = DB_PREFIX;
$id     = (int)get('id');
$page   = $id ? DB::row("SELECT * FROM `{$p}pages` WHERE id=?", [$id]) : null;

// Picker data for block settings
$slideshows = DB::rows("SELECT id, name FROM `{$p}slideshows` ORDER BY name ASC");
$categories = DB::rows("SELECT id, name FROM `{$p}categories` WHERE status>0 ORDER BY name ASC");

$smarty->assign('edit_page',    $page);
$smarty->assign('edit_page_id', $id);
$smarty->assign('slideshows',   $slideshows);
$smarty->assign('categories',   $categories);
$smarty->display('page-edit.html');
