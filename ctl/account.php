<?php
// My Account — requires login
if (!is_logged_in()) {
	$_SESSION['login_redirect'] = URL_ROOT . 'page/my-account';
	header('Location: ' . URL_ROOT . '?route=login');
	exit;
}

catalog_sidebar($smarty);
$smarty->assign('page_type', 'account');
$smarty->display('account.html');
