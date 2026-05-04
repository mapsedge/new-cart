<?php
/**
 * new-cart admin — settings ajax handler
 * route=settings/ajax
 */

require_admin();
header('Content-Type: application/json');

function out(bool $ok, string $message = '', array $extra = []): never {
	echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
	exit;
}

$p      = DB_PREFIX;
$action = post('action');

// ── Load all settings ──────────────────────────────────────────────────────────
if ($action === 'load') {
	$rows     = DB::rows("SELECT `key`, `value` FROM `{$p}settings`");
	$settings = array_column($rows, 'value', 'key');

	// Read robots.txt
	$robots_path = DIR_ROOT . 'robots.txt';
	$robots_txt  = file_exists($robots_path) ? file_get_contents($robots_path) : '';

	$users = DB::rows(
		"SELECT id, username, email, access_level, status, avatar
		 FROM `{$p}admin`
		 ORDER BY username ASC"
	);

	// Read error log
	$log_path = defined('ERROR_LOG') ? ERROR_LOG : rtrim(DIR_ROOT, '/') . '/logs/error.log';
	$log_content = file_exists($log_path) ? file_get_contents($log_path) : '';

	out(true, '', [
		'settings'    => $settings,
		'robots_txt'  => $robots_txt,
		'users'       => $users,
		'log_content' => $log_content,
	]);
}

// ── Save all settings (single call) ───────────────────────────────────────────
if ($action === 'save_all') {
	$all_fields = [
		// Store
		'site_name', 'site_email', 'site_currency', 'store_phone', 'store_logo_url',
		'img_retain_names', 'img_resize_on_upload', 'img_orig_max',
		'img_admin_size', 'img_admin_quality', 'img_fm_size', 'img_fm_quality',
		'img_product_width', 'img_product_quality',
		'img_cart_size', 'img_related_size', 'img_second_level_size', 'img_sidebar_size', 'related_max_items',
		'seo_title_default', 'seo_description_default', 'seo_keywords_default',
		// Local
		'address', 'phone', 'timezone', 'date_format', 'currency_position',
		// Mail
		'smtp_host', 'smtp_user', 'smtp_pass', 'smtp_port', 'mail_alert',
		// Server
		'maintenance_mode', 'use_seo_urls',
		'pw_min_length', 'pw_require_upper', 'pw_require_number', 'pw_require_symbol',
		'display_errors', 'log_errors', 'smarty_debug',
		// Options — Images (additional; core image keys already listed above)
		'img_orig_max', 'img_admin_size', 'img_admin_quality', 'img_fm_size', 'img_fm_quality',
		// Options — AI
		'deepai_key',
	];

	foreach ($all_fields as $key) {
		DB::exec(
			"INSERT INTO `{$p}settings` (`key`, `value`) VALUES (?, ?)
			 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
			[$key, trim(post($key))]
		);
	}

	// Handle logo upload
	if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
		$result = saveImage($_FILES['logo'], 'logo', 300, 100);
		if (!$result['ok']) out(false, $result['message']);
		DB::exec(
			"INSERT INTO `{$p}settings` (`key`, `value`) VALUES ('site_logo', ?)
			 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
			[$result['path']]
		);
	}

	// Handle favicon upload
	if (!empty($_FILES['favicon']['name']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
		$result = saveImage($_FILES['favicon'], 'favicon', 32, 32);
		if (!$result['ok']) out(false, $result['message']);
		DB::exec(
			"INSERT INTO `{$p}settings` (`key`, `value`) VALUES ('site_favicon', ?)
			 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
			[$result['path']]
		);
	}

	// Handle admin path change
	$new_path = preg_replace('/[^a-z0-9_\-]/i', '', trim(post('admin_path')));
	if ($new_path) {
		$current = DB::val("SELECT `value` FROM `{$p}settings` WHERE `key`='admin_path'");
		if ($new_path !== $current) {
			DB::exec(
				"INSERT INTO `{$p}settings` (`key`, `value`) VALUES ('admin_path', ?)
				 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
				[$new_path]
			);
			rewriteHtaccess($new_path);
		}
	}

	// Write robots.txt
	$robots_content = post('robots_txt', '');
	if ($robots_content !== '') {
		@file_put_contents(DIR_ROOT . 'robots.txt', $robots_content);
	}

	$name = DB::val("SELECT `value` FROM `{$p}settings` WHERE `key`='site_name'");
	out(true, 'Settings saved.', ['site_name' => $name]);
}

// ── Keep old individual actions for compatibility ──────────────────────────────
if ($action === 'save_store' || $action === 'save_local' ||
    $action === 'save_mail'  || $action === 'save_server') {
	// Redirect to save_all
	$_POST['action'] = 'save_all';
	// Re-run — include self
	require __FILE__;
	exit;
}

// ── Clear error log ────────────────────────────────────────────────────────────
if ($action === 'clear_log') {
	$log_path = defined('ERROR_LOG') ? ERROR_LOG : rtrim(DIR_ROOT, '/') . '/logs/error.log';
	file_put_contents($log_path, '');
	out(true, 'Error log cleared.');
}

// ── Download error log ─────────────────────────────────────────────────────────
if ($action === 'download_log') {
	$log_path = defined('ERROR_LOG') ? ERROR_LOG : rtrim(DIR_ROOT, '/') . '/logs/error.log';
	header('Content-Type: text/plain');
	header('Content-Disposition: attachment; filename="error.log"');
	readfile($log_path);
	exit;
}

// ── Save user ──────────────────────────────────────────────────────────────────
if ($action === 'save_user') {
	$id           = (int)post('id');
	$username     = trim(post('username'));
	$email        = trim(post('email'));
	$access_level = (int)post('access_level');
	$status       = (int)(bool)post('status');
	$password     = post('password');

	if (!$username || strlen($username) < 3) out(false, 'Username must be at least 3 characters.');
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, 'Invalid email address.');

	if ($id) {
		// Prevent demoting last admin
		if ($access_level !== ACCESS_ADMIN) {
			$admin_count = (int)DB::val(
				"SELECT COUNT(*) FROM `{$p}admin` WHERE access_level = ? AND status = 1 AND id != ?",
				[ACCESS_ADMIN, $id]
			);
			if ($admin_count === 0) out(false, 'Cannot remove Admin access from the only active admin account.');
		}

		$sql    = "UPDATE `{$p}admin` SET username=?, email=?, access_level=?, status=?";
		$params = [$username, $email, $access_level, $status];

		if ($password) {
			if (strlen($password) < 8) out(false, 'Password must be at least 8 characters.');
			$sql    .= ', password=?';
			$params[] = password_hash($password, PASSWORD_BCRYPT);
		}
		$params[] = $id;
		DB::exec($sql . ' WHERE id=?', $params);
	} else {
		if (!$password || strlen($password) < 8) out(false, 'Password must be at least 8 characters.');
		// Check username/email uniqueness
		if (DB::val("SELECT id FROM `{$p}admin` WHERE username=?", [$username])) {
			out(false, 'Username already in use.');
		}
		if (DB::val("SELECT id FROM `{$p}admin` WHERE email=?", [$email])) {
			out(false, 'Email already in use.');
		}
		$id = DB::insert(
			"INSERT INTO `{$p}admin` (username, email, password, access_level, status)
			 VALUES (?, ?, ?, ?, ?)",
			[$username, $email, password_hash($password, PASSWORD_BCRYPT), $access_level, $status]
		);
	}

	// Handle avatar delete request
	if ((int)post('delete_avatar') && !isset($_FILES['avatar']['name'])) {
		$current = DB::val("SELECT avatar FROM `{$p}admin` WHERE id=?", [$id]);
		if ($current) {
			$file = rtrim(DIR_ROOT, '/') . '/' . ltrim($current, '/');
			if (file_exists($file)) @unlink($file);
		}
		DB::exec("UPDATE `{$p}admin` SET avatar='' WHERE id=?", [$id]);
	}

	// Handle avatar upload
	if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
		$result = saveImage($_FILES['avatar'], 'avatar_' . $id, 128, 128, true);
		if (!$result['ok']) out(false, $result['message']);
		DB::exec("UPDATE `{$p}admin` SET avatar=? WHERE id=?", [$result['path'], $id]);
	}

	$user = DB::row(
		"SELECT id, username, email, access_level, status, avatar FROM `{$p}admin` WHERE id=?",
		[$id]
	);
	out(true, 'User saved.', ['user' => $user]);
}

// ── Delete user ────────────────────────────────────────────────────────────────
if ($action === 'delete_user') {
	$id = (int)post('id');

	if ($id === (int)($_SESSION['admin_id'] ?? 0)) {
		out(false, 'You cannot delete your own account.');
	}

	$user = DB::row("SELECT access_level, status, avatar FROM `{$p}admin` WHERE id=?", [$id]);
	if (!$user) out(false, 'User not found.');
	if ($user['status'] == 1) out(false, 'Deactivate the user before deleting.');

	// Prevent deleting last admin
	$admin_count = (int)DB::val(
		"SELECT COUNT(*) FROM `{$p}admin` WHERE access_level = ? AND status = 1 AND id != ?",
		[ACCESS_ADMIN, $id]
	);
	if ($admin_count === 0 && $user['access_level'] == ACCESS_ADMIN) {
		out(false, 'Cannot delete the only active admin account.');
	}

	// Remove avatar file
	if ($user['avatar']) {
		$file = DIR_ROOT . ltrim($user['avatar'], '/');
		if (file_exists($file)) @unlink($file);
	}

	DB::exec("DELETE FROM `{$p}admin` WHERE id=?", [$id]);
	out(true, 'User deleted.');
}

out(false, 'Unknown action.');

// ── Image save helper ──────────────────────────────────────────────────────────
function saveImage(array $file, string $name, int $maxW, int $maxH, bool $square = false): array {
	$allowed = ['image/jpeg', 'image/png', 'image/webp'];
	$mime    = mime_content_type($file['tmp_name']);

	if (!in_array($mime, $allowed, true)) {
		return ['ok' => false, 'message' => 'Only JPG, PNG, and WebP images are allowed.'];
	}

	$ext = match($mime) {
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	};

	$dest_dir = rtrim(DIR_ROOT, '/') . '/img/avatars/';
	if (!is_dir($dest_dir)) @mkdir($dest_dir, 0755, true);

	$filename = $name . '.' . $ext;
	$dest     = $dest_dir . $filename;

	// Load source
	$src = match($mime) {
		'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
		'image/png'  => imagecreatefrompng($file['tmp_name']),
		'image/webp' => imagecreatefromwebp($file['tmp_name']),
	};

	if (!$src) return ['ok' => false, 'message' => 'Could not read image file.'];

	// Fix EXIF rotation for JPEGs
	if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
		$exif = @exif_read_data($file['tmp_name']);
		$orientation = $exif['Orientation'] ?? 1;
		$src = match($orientation) {
			3 => imagerotate($src, 180, 0),
			6 => imagerotate($src, -90, 0),
			8 => imagerotate($src,  90, 0),
			default => $src,
		};
	}

	$srcW = imagesx($src);
	$srcH = imagesy($src);

	if ($square) {
		$srcMin  = min($srcW, $srcH);
		$cropX   = (int)(($srcW - $srcMin) / 2);
		$cropY   = (int)(($srcH - $srcMin) / 2);
		$out_img = imagecreatetruecolor($maxW, $maxH);
		imagecopyresampled($out_img, $src, 0, 0, $cropX, $cropY, $maxW, $maxH, $srcMin, $srcMin);
	} else {
		$ratio   = min($maxW / $srcW, $maxH / $srcH, 1.0);
		$newW    = (int)($srcW * $ratio);
		$newH    = (int)($srcH * $ratio);
		$out_img = imagecreatetruecolor($newW, $newH);
		imagealphablending($out_img, false);
		imagesavealpha($out_img, true);
		imagecopyresampled($out_img, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
	}

	$saved = match($mime) {
		'image/jpeg' => imagejpeg($out_img, $dest, 85),
		'image/png'  => imagepng($out_img, $dest, 6),
		'image/webp' => imagewebp($out_img, $dest, 85),
	};

	imagedestroy($src);
	imagedestroy($out_img);

	if (!$saved) return ['ok' => false, 'message' => 'Could not save image to ' . $dest];

	return ['ok' => true, 'path' => '/img/avatars/' . $filename];
}

// ── Rewrite .htaccess with new admin path ──────────────────────────────────────
function rewriteHtaccess(string $admin_path): void {
	$htaccess_path = DIR_ROOT . '.htaccess';
	$htaccess = <<<HTACCESS
Options -Indexes
DirectoryIndex index.php

RewriteEngine On

# Block direct browser access to admin/
RewriteCond %{REQUEST_URI} ^/admin(/|$) [NC]
RewriteRule ^ - [F,L]

# Map public admin path to admin/index.php
RewriteRule ^{$admin_path}/?$ /admin/index.php [L,QSA]
RewriteRule ^{$admin_path}/(.*)$ /admin/index.php [L,QSA]

# Route everything else through index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /index.php [L,QSA]
HTACCESS;

	file_put_contents($htaccess_path, $htaccess);
}
