<?php
/**
 * new-cart Plugin Loader
 *
 * Discovers enabled plugins (non-dotfile folders in /plugins/)
 * and loads their hooks.php if present.
 *
 * Called once during bootstrap, after DB and config are ready.
 */
class PluginLoader {

	private static array $loaded   = [];
	private static array $manifests = [];

	// ── Load all enabled plugins ───────────────────────────────────────────────
	public static function boot(): void {
		$dir = DIR_ROOT . 'plugins/';
		if (!is_dir($dir)) return;

		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			if ($entry[0] === '.') continue; // disabled (dot-prefixed)
			$path = $dir . $entry;
			if (!is_dir($path)) continue;

			$manifest = self::readManifest($path . '/plugin.xml');
			if (!$manifest) continue;

			self::$manifests[$entry] = $manifest;

			// Load hooks
			$hooks = $path . '/hooks.php';
			if (file_exists($hooks)) {
				require_once $hooks;
			}

			self::$loaded[] = $entry;
		}
	}

	// ── Get all plugin template dirs (for Smarty priority loading) ─────────────
	public static function templateDirs(): array {
		$dirs = [];
		$dir  = DIR_ROOT . 'plugins/';
		foreach (self::$loaded as $code) {
			$tpl = $dir . $code . '/tpl/';
			if (is_dir($tpl)) $dirs[] = $tpl;
		}
		return $dirs;
	}

	// ── Get all plugin admin template dirs ────────────────────────────────────
	public static function adminTemplateDirs(): array {
		$dirs = [];
		$dir  = DIR_ROOT . 'plugins/';
		foreach (self::$loaded as $code) {
			$tpl = $dir . $code . '/admin/tpl/';
			if (is_dir($tpl)) $dirs[] = $tpl;
		}
		return $dirs;
	}

	// ── Get loaded plugin codes ────────────────────────────────────────────────
	public static function loaded(): array {
		return self::$loaded;
	}

	// ── Get manifest for a plugin ──────────────────────────────────────────────
	public static function manifest(string $code): ?array {
		return self::$manifests[$code] ?? null;
	}

	// ── Get all manifests ──────────────────────────────────────────────────────
	public static function manifests(): array {
		return self::$manifests;
	}

	// ── Parse plugin.xml manifest ──────────────────────────────────────────────
	public static function readManifest(string $path): ?array {
		if (!file_exists($path)) return null;

		libxml_use_internal_errors(true);
		$xml = simplexml_load_file($path);
		if (!$xml) return null;

		$manifest = [
			'name'        => (string)($xml->name        ?? ''),
			'code'        => (string)($xml->code        ?? ''),
			'version'     => (string)($xml->version     ?? ''),
			'author'      => (string)($xml->author      ?? ''),
			'link'        => (string)($xml->link        ?? ''),
			'description' => (string)($xml->description ?? ''),
			'date'        => (string)($xml->date        ?? ''),
			'tables'      => [],
		];

		// Parse table declarations
		if (isset($xml->tables->table)) {
			foreach ($xml->tables->table as $table) {
				$tbl = [
					'name'   => (string)$table['name'],
					'fields' => [],
					'indexes'=> [],
				];
				foreach ($table->field as $field) {
					$tbl['fields'][] = [
						'name'           => (string)$field['name'],
						'type'           => (string)$field['type'],
						'null'           => ((string)$field['null']) !== 'false',
						'default'        => isset($field['default']) ? (string)$field['default'] : null,
						'auto_increment' => ((string)$field['auto_increment']) === 'true',
						'primary'        => ((string)$field['primary'])        === 'true',
					];
				}
				foreach ($table->index as $index) {
					$tbl['indexes'][] = [
						'columns' => explode(',', (string)$index['columns']),
						'unique'  => ((string)$index['unique']) === 'true',
					];
				}
				$manifest['tables'][] = $tbl;
			}
		}

		return $manifest;
	}

	// ── Generate and execute CREATE TABLE from manifest ────────────────────────
	public static function createTables(array $manifest): void {
		foreach ($manifest['tables'] as $tbl) {
			$sql = self::buildCreateTable($tbl);
			DB::exec($sql);
		}
	}

	// ── Drop tables declared in manifest ──────────────────────────────────────
	public static function dropTables(array $manifest): void {
		foreach ($manifest['tables'] as $tbl) {
			DB::exec("DROP TABLE IF EXISTS `{$tbl['name']}`");
		}
	}

	// ── Build CREATE TABLE SQL from manifest table definition ──────────────────
	private static function buildCreateTable(array $tbl): string {
		$cols    = [];
		$primary = null;

		foreach ($tbl['fields'] as $f) {
			$col = "`{$f['name']}` {$f['type']}";
			if (!$f['null']) $col .= ' NOT NULL';
			if ($f['auto_increment']) $col .= ' AUTO_INCREMENT';
			if ($f['default'] !== null) {
				$col .= " DEFAULT " . ($f['default'] === 'CURRENT_TIMESTAMP'
					? 'CURRENT_TIMESTAMP'
					: "'" . addslashes($f['default']) . "'");
			}
			$cols[] = $col;
			if ($f['primary']) $primary = $f['name'];
		}

		if ($primary) {
			$cols[] = "PRIMARY KEY (`{$primary}`)";
		}

		foreach ($tbl['indexes'] as $idx) {
			$idxCols = implode('`, `', $idx['columns']);
			$type    = $idx['unique'] ? 'UNIQUE KEY' : 'KEY';
			$name    = implode('_', $idx['columns']);
			$cols[]  = "{$type} `{$name}` (`{$idxCols}`)";
		}

		$colSql = implode(",\n\t\t\t", $cols);
		return "CREATE TABLE IF NOT EXISTS `{$tbl['name']}` (\n\t\t\t{$colSql}\n\t\t) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
	}
}
