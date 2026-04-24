<?php
require_admin();

$p    = DB_PREFIX;
$text = '';
try {
    $row  = DB::row("SELECT `value` FROM `{$p}settings` WHERE `key`='lipsum_text'");
    $text = $row['value'] ?? '';
} catch (Exception $e) {}
if (!$text) {
    $txt = DIR_ROOT . 'plugins/lipsum/lipsum.txt';
    if (file_exists($txt)) $text = trim(file_get_contents($txt));
}

$smarty->assign('page',        'plugins');
$smarty->assign('page_title',  'Lorem Ipsum — Settings');
$smarty->assign('lipsum_text', $text);
$smarty->display('lipsum-settings.html');
