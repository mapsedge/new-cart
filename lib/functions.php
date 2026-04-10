<?php
/**
 * CandyCart — shared utility functions
 */

// ── Output helpers ─────────────────────────────────────────────────────────────

function h(mixed $val): string {
	return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float $amount): string {
	return SITE_CURRENCY . number_format($amount, 2);
}

function json_out(mixed $data, int $status = 200): never {
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($data);
	exit;
}

// ── Request helpers ────────────────────────────────────────────────────────────

function get(string $key, mixed $default = ''): mixed {
	return $_GET[$key] ?? $default;
}

function post(string $key, mixed $default = ''): mixed {
	return $_POST[$key] ?? $default;
}

function is_post(): bool {
	return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function is_ajax(): bool {
	return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
		&& strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ── Session helpers ────────────────────────────────────────────────────────────

function session_get(string $key, mixed $default = null): mixed {
	return $_SESSION[$key] ?? $default;
}

function session_set(string $key, mixed $val): void {
	$_SESSION[$key] = $val;
}

function session_del(string $key): void {
	unset($_SESSION[$key]);
}

function flash_set(string $type, string $message): void {
	$_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array {
	$flash = $_SESSION['_flash'] ?? null;
	unset($_SESSION['_flash']);
	return $flash;
}

// ── String helpers ─────────────────────────────────────────────────────────────

function slug(string $str): string {
	$str = strtolower(trim($str));
	$str = preg_replace('/[^a-z0-9]+/', '-', $str);
	return trim($str, '-');
}

function truncate(string $str, int $len = 100, string $suffix = '…'): string {
	return mb_strlen($str) > $len
		? mb_substr($str, 0, $len) . $suffix
		: $str;
}

// ── Redirect ───────────────────────────────────────────────────────────────────

function redirect(string $url): never {
	header('Location: ' . $url);
	exit;
}

// ── Auth ───────────────────────────────────────────────────────────────────────

// Access level flags
const ACCESS_ADD     =   2;
const ACCESS_DELETE  =   4;
const ACCESS_EDIT    =   8;
const ACCESS_PRICING =  16;
const ACCESS_REPORTS =  32;
const ACCESS_REVIEWS =  64;
const ACCESS_PAGES   = 128;

// Named shortcut levels
const ACCESS_ADMIN        = 254; // all
const ACCESS_SUPER_EDITOR = 234; // no delete, no pricing
const ACCESS_EDITOR       =  10; // add + edit
const ACCESS_USER         =   0; // read only

function is_logged_in(): bool {
	return !empty($_SESSION['customer_id']);
}

function is_admin(): bool {
	return !empty($_SESSION['admin_id']);
}

function admin_can(int $flag): bool {
	$level = (int)($_SESSION['admin_access'] ?? 0);
	return ($level & $flag) === $flag;
}

function require_admin(): void {
	if (!is_admin()) redirect(URL_ADMIN . '?route=login');
}

function require_access(int $flag): void {
	if (!is_admin())        redirect(URL_ADMIN . '?route=login');
	if (!admin_can($flag))  redirect(URL_ADMIN . '?route=dashboard');
}

function require_login(): void {
	if (!is_logged_in()) redirect(URL_ROOT . '?route=account/login');
}

// ── Incomplete item reminders ──────────────────────────────────────────────────

function reminder_add(string $entity, int $entity_id, string $label, string $message): void {
	$p = DB_PREFIX;
	DB::exec(
		"INSERT INTO `{$p}incomplete` (entity, entity_id, label, message)
		 VALUES (?, ?, ?, ?)
		 ON DUPLICATE KEY UPDATE label=VALUES(label), message=VALUES(message)",
		[$entity, $entity_id, $label, $message]
	);
}

function reminder_clear(string $entity, int $entity_id): void {
	$p = DB_PREFIX;
	DB::exec(
		"DELETE FROM `{$p}incomplete` WHERE entity=? AND entity_id=?",
		[$entity, $entity_id]
	);
}

function reminder_list(): array {
	$p = DB_PREFIX;
	try {
		return DB::rows("SELECT * FROM `{$p}incomplete` ORDER BY created_at ASC");
	} catch (Exception $e) {
		return [];
	}
}

function reminder_ids(string $entity): array {
	$p = DB_PREFIX;
	try {
		$rows = DB::rows(
			"SELECT entity_id FROM `{$p}incomplete` WHERE entity=?",
			[$entity]
		);
		return array_column($rows, 'entity_id');
	} catch (Exception $e) {
		return [];
	}
}

// ── Error handling ─────────────────────────────────────────────────────────────

function cc_error_handler(int $code, string $message, string $file, int $line): bool {
	if (!($code & error_reporting())) return false;
	$entry = date('[Y-m-d H:i:s]') . " PHP Error [{$code}]: {$message} in {$file} on line {$line}" . PHP_EOL;
	if (LOG_ERRORS) {
		@file_put_contents(ERROR_LOG, $entry, FILE_APPEND);
	}
	if (DISPLAY_ERRORS) {
		echo "<pre style='color:red'>{$entry}</pre>";
	}
	return true;
}

function cc_exception_handler(Throwable $e): void {
	$entry = date('[Y-m-d H:i:s]') . " Exception: " . $e->getMessage()
		. " in " . $e->getFile() . " on line " . $e->getLine()
		. PHP_EOL . $e->getTraceAsString() . PHP_EOL;
	if (LOG_ERRORS) {
		@file_put_contents(ERROR_LOG, $entry, FILE_APPEND);
	}
	if (DISPLAY_ERRORS) {
		echo "<pre style='color:red'>" . h($entry) . "</pre>";
	} else {
		http_response_code(500);
		echo "An error occurred.";
	}
}
