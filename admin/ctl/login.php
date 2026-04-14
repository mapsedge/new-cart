<?php
if (is_admin()) {
	redirect(URL_ADMIN . '?route=dashboard');
}

$error = '';

if (is_post()) {
	$username = trim(post('username'));
	$password = post('password');

	$admin = DB::row(
		"SELECT * FROM `" . DB_PREFIX . "admin` WHERE username = ? AND status = 1",
		[$username]
	);

	if ($admin && password_verify($password, $admin['password'])) {
		session_regenerate_id(true);
		session_set('admin_id',       $admin['id']);
		session_set('admin_username', $admin['username']);
		session_set('admin_access',   (int)($admin['access_level'] ?? ACCESS_ADMIN));
		session_set('admin_avatar',   $admin['avatar'] ?? '');
		redirect(URL_ADMIN . '?route=dashboard');
	}

	$error = 'Invalid username or password.';
}

$smarty->assign('error',    $error);
$smarty->assign('page',     'login');
$smarty->display('login.html');
