<?php
require_access(ACCESS_EDIT);
$smarty->assign('page',       'products');
$smarty->assign('page_title', 'Products');
$smarty->display('products/list.html');
