<?php
require_access(ACCESS_EDIT);
$p = DB_PREFIX;
$smarty->assign('stub_title', 'Discounts');
$smarty->display('stub.html');
