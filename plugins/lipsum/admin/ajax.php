<?php
require_admin();
header('Content-Type: application/json');

$action = post('action');

if ($action === 'save') {
    $text = post('text');
    $p    = DB_PREFIX;
    DB::exec(
        "INSERT INTO `{$p}settings` (`key`, `value`) VALUES ('lipsum_text', ?)
         ON DUPLICATE KEY UPDATE `value` = ?",
        [$text, $text]
    );
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
