<?php
require_access(ACCESS_EDIT);
$smarty->assign('page',       'products');
$smarty->assign('page_title', 'Products');
$smarty->assign('deepai_key', DB::val("SELECT `value` FROM `" . DB_PREFIX . "settings` WHERE `key` = 'deepai_key'") ?: '');
$smarty->display('products/list.html');
