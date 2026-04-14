<?php
require_admin();
header('Content-Type: application/json');

function out(bool $ok, string $msg='', array $extra=[]): never {
	echo json_encode(['ok'=>$ok,'message'=>$msg]+$extra); exit;
}

$p      = DB_PREFIX;
$action = post('action');

if ($action === 'list') {
	$rows = DB::rows(
		"SELECT m.*, f.name AS form_name
		 FROM `{$p}messages` m
		 LEFT JOIN `{$p}contact_forms` f ON f.id=m.form_id
		 ORDER BY m.created_at DESC LIMIT 200"
	);
	foreach ($rows as &$r) {
		$r['data'] = $r['data'] ? json_decode($r['data'], true) : [];
	}
	unset($r);
	out(true, '', ['rows' => $rows]);
}

if ($action === 'mark_read') {
	$id = (int)post('id');
	DB::exec("UPDATE `{$p}messages` SET read_at=NOW() WHERE id=?", [$id]);
	out(true, '');
}

if ($action === 'delete') {
	require_access(ACCESS_DELETE);
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}messages` WHERE id=?", [$id]);
	out(true, '');
}

out(false, 'Unknown action.');
