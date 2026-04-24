<?php
Hook::on('admin.page.head', function ($html, $ctx) {
    $js_url = rtrim(URL_ROOT, '/') . '/plugins/lipsum/js/trumbowyg.lipsum.js';

    $text = '';
    try {
        $row  = DB::row("SELECT `value` FROM `" . DB_PREFIX . "settings` WHERE `key`='lipsum_text'");
        $text = $row['value'] ?? '';
    } catch (Exception $e) {}
    if (!$text) {
        $txt = __DIR__ . '/lipsum.txt';
        if (file_exists($txt)) $text = trim(file_get_contents($txt));
    }

    $html .= '<script src="' . htmlspecialchars($js_url, ENT_QUOTES) . '"></script>' . "\n";
    $html .= '<script>window.ncLipsumText=' . json_encode($text) . ';'
           . 'window.ncTrumbowygExtraBtns=(window.ncTrumbowygExtraBtns||[]).concat([[\'lipsum\']]);</script>' . "\n";
    return $html;
});
