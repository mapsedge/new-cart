<?php
require_access(ACCESS_EDIT);
$smarty->assign('page',       'slideshows');
$smarty->assign('page_title', 'Slideshows');
$smarty->display('slideshows/list.html');
