<?php
require_access(ACCESS_EDIT);
$smarty->assign('page',       'options');
$smarty->assign('page_title', 'Options');
$smarty->display('options/list.html');
