<?php
require_access(ACCESS_EDIT);
$smarty->assign('page',       'menus');
$smarty->assign('page_title', 'Menus');
$smarty->display('menus/list.html');
