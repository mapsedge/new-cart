<?php
require_access(ACCESS_EDIT);
$p = DB_PREFIX;
$smarty->assign('stub_title', 'Reviews');
$smarty->display('stub.html');
