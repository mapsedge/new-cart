<?php
require_access(ACCESS_EDIT);
$smarty->assign('page',       'pages');
$smarty->assign('page_title', 'Pages');
$smarty->display('pages/list.html');
