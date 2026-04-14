<?php
$smarty->assign('page',              'categories');
$smarty->assign('page_title',        'Categories');
$smarty->assign('url_admin',         URL_ADMIN);
$smarty->assign('incomplete_cat_ids', reminder_ids('category'));
$smarty->assign('deepai_key',        DB::val("SELECT `value` FROM `" . DB_PREFIX . "settings` WHERE `key` = 'deepai_key'") ?: '');
$smarty->display('categories/list.html');
