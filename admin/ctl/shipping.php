<?php
require_access(ACCESS_EDIT);
$p = DB_PREFIX;
$smarty->assign('stub_title', 'Shipping');
$smarty->display('stub.html');
