<?php
http_response_code(404);
$smarty->assign('page_title', 'Page Not Found');
$smarty->display('404.html');
