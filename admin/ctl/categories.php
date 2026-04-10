<?php
$smarty->assign('page',              'categories');
$smarty->assign('page_title',        'Categories');
$smarty->assign('url_admin',         URL_ADMIN);
$smarty->assign('incomplete_cat_ids', reminder_ids('category'));
$smarty->display('categories/list.html');
