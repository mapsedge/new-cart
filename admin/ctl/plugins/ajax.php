<?php
/**
 * new-cart admin — plugins ajax handler
 * route=plugins/ajax
 */

require_admin();
header('Content-Type: application/json');

function out(bool $ok, string $message = '', array $extra = []): never {
	echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
	exit;
}

require_once DIR_LIB . 'plugin-loader.php';

$action = post('action');

// ── List installed plugins ─────────────────────────────────────────────────────
if ($action === 'list') {
	$plugins    = [];
	$plugin_dir = DIR_ROOT . 'plugins/';

	if (!is_dir($plugin_dir)) {
		out(true, '', ['plugins' => []]);
	}

	$seen = [];
	foreach (scandir($plugin_dir) as $entry) {
		if ($entry === '.' || $entry === '..') continue;
		$disabled = $entry[0] === '.';
		$code     = $disabled ? substr($entry, 1) : $entry;
		$path     = $plugin_dir . $entry;
		if (!is_dir($path)) continue;

		// If both enabled and disabled copies exist, skip the disabled one
		if ($disabled && is_dir($plugin_dir . $code)) continue;

		// Skip duplicate codes (shouldn't happen, but guard against it)
		if (isset($seen[$code])) continue;
		$seen[$code] = true;

		$manifest = PluginLoader::readManifest($path . '/plugin.xml');
		if (!$manifest) continue;

		$plugins[] = [
			'code'         => $code,
			'folder'       => $entry,
			'enabled'      => !$disabled,
			'name'         => $manifest['name']        ?: $code,
			'version'      => $manifest['version']     ?: '—',
			'author'       => $manifest['author']      ?: '—',
			'link'         => $manifest['link']        ?: '',
			'description'  => $manifest['description'] ?: '',
			'date'         => $manifest['date']        ?: '',
			'has_settings' => !empty($manifest['settings']),
			'has_admin'    => file_exists($plugin_dir . $code . '/admin/index.php'),
		];
	}

	out(true, '', ['plugins' => $plugins]);
}

// ── Upload and install ────────────────────────────────────────────────────────
if ($action === 'install') {
	if (empty($_FILES['file']['name'])) out(false, 'No file uploaded.');
	if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) out(false, 'Upload error.');

	$filename = basename($_FILES['file']['name']);
	if (!str_ends_with(strtolower($filename), '.zip')) {
		out(false, 'Plugin must be a .zip file.');
	}

	$tmp      = $_FILES['file']['tmp_name'];
	$zip      = new ZipArchive();
	if ($zip->open($tmp) !== true) out(false, 'Could not open zip file.');

	// Find plugin.xml to determine plugin code
	$manifest_raw = null;
	$zip_root     = '';
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = $zip->getNameIndex($i);
		if (basename($name) === 'plugin.xml') {
			$manifest_raw = $zip->getFromIndex($i);
			$zip_root     = dirname($name);
			if ($zip_root === '.') $zip_root = '';
			break;
		}
	}

	if (!$manifest_raw) out(false, 'plugin.xml not found in zip.');

	// Parse manifest
	libxml_use_internal_errors(true);
	$xml = simplexml_load_string($manifest_raw);
	if (!$xml) out(false, 'plugin.xml is invalid.');

	$code = preg_replace('/[^a-z0-9_\-]/i', '', (string)($xml->code ?? ''));
	if (!$code) out(false, 'Plugin manifest missing <code> field.');

	$plugin_dir = DIR_ROOT . 'plugins/' . $code;
	$disabled   = DIR_ROOT . 'plugins/.' . $code;

	// Remove existing if present
	if (is_dir($plugin_dir)) {
		self_rmdir($plugin_dir);
	}
	if (is_dir($disabled)) {
		self_rmdir($disabled);
	}

	// Extract to plugins/
	$dest = DIR_ROOT . 'plugins/';
	if (!is_dir($dest)) mkdir($dest, 0755, true);

	for ($i = 0; $i < $zip->numFiles; $i++) {
		$entry    = $zip->getNameIndex($i);
		$relative = $zip_root ? substr($entry, strlen($zip_root) + 1) : $entry;
		if (!$relative) continue;

		$target = $dest . $code . '/' . $relative;
		if (str_ends_with($entry, '/')) {
			@mkdir($target, 0755, true);
		} else {
			@mkdir(dirname($target), 0755, true);
			file_put_contents($target, $zip->getFromIndex($i));
		}
	}
	$zip->close();

	// Re-read manifest from extracted files
	$manifest = PluginLoader::readManifest($plugin_dir . '/plugin.xml');
	if (!$manifest) out(false, 'Could not read extracted plugin.xml.');

	// Create declared tables
	try {
		PluginLoader::createTables($manifest);
	} catch (Exception $e) {
		out(false, 'Table creation failed: ' . $e->getMessage());
	}

	// Run install.php if present
	$install_script = $plugin_dir . '/install.php';
	if (file_exists($install_script)) {
		try {
			require $install_script;
			rename($install_script, $plugin_dir . '/install.php.done');
		} catch (Exception $e) {
			out(false, 'install.php failed: ' . $e->getMessage());
		}
	}

	out(true, "'{$manifest['name']}' installed successfully.");
}

// ── Enable ────────────────────────────────────────────────────────────────────
if ($action === 'enable') {
	$code     = preg_replace('/[^a-z0-9_\-]/i', '', post('code'));
	$disabled = DIR_ROOT . 'plugins/.' . $code;
	$enabled  = DIR_ROOT . 'plugins/'  . $code;

	if (!is_dir($disabled)) out(false, 'Plugin not found.');
	if (is_dir($enabled))   out(false, 'An enabled plugin with this code already exists. Remove the duplicate first.');
	if (!rename($disabled, $enabled)) out(false, 'Could not enable plugin.');

	out(true, 'Plugin enabled.');
}

// ── Disable ───────────────────────────────────────────────────────────────────
if ($action === 'disable') {
	$code    = preg_replace('/[^a-z0-9_\-]/i', '', post('code'));
	$enabled  = DIR_ROOT . 'plugins/'  . $code;
	$disabled = DIR_ROOT . 'plugins/.' . $code;

	if (!is_dir($enabled)) out(false, 'Plugin not found.');
	if (!rename($enabled, $disabled)) out(false, 'Could not disable plugin.');

	out(true, 'Plugin disabled.');
}

// ── Remove ────────────────────────────────────────────────────────────────────
if ($action === 'remove') {
	$code     = preg_replace('/[^a-z0-9_\-]/i', '', post('code'));
	$path     = DIR_ROOT . 'plugins/'  . $code;
	$disabled = DIR_ROOT . 'plugins/.' . $code;
	$primary  = is_dir($path) ? $path : (is_dir($disabled) ? $disabled : null);

	if (!$primary) out(false, 'Plugin not found.');

	// Read manifest and run cleanup from the primary (enabled) copy
	$manifest = PluginLoader::readManifest($primary . '/plugin.xml');
	if ($manifest) PluginLoader::dropTables($manifest);

	$uninstall = $primary . '/uninstall.php';
	if (file_exists($uninstall)) {
		try { require $uninstall; } catch (Exception $e) { /* non-fatal */ }
	}

	// Remove both enabled and disabled copies so no orphan remains
	if (is_dir($path))     self_rmdir($path);
	if (is_dir($disabled)) self_rmdir($disabled);

	out(true, 'Plugin removed.');
}

out(false, 'Unknown action.');

// ── Helper: recursive directory removal ───────────────────────────────────────
function self_rmdir(string $dir): void {
	if (!is_dir($dir)) return;
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($items as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($dir);
}
