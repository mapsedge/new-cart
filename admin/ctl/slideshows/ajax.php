<?php
require_admin();
header('Content-Type: application/json');
function out(bool $ok,string $msg='',array $extra=[]): never { echo json_encode(['ok'=>$ok,'message'=>$msg]+$extra); exit; }

$p      = DB_PREFIX;
$action = post('action');

if ($action === 'list') {
	$rows = DB::rows("SELECT *, (SELECT COUNT(*) FROM `{$p}slideshow_slides` WHERE slideshow_id=s.id) AS slide_count FROM `{$p}slideshows` s ORDER BY name ASC");
	out(true,'',['rows'=>$rows]);
}

if ($action === 'get') {
	$id  = (int)post('id');
	$ss  = DB::row("SELECT * FROM `{$p}slideshows` WHERE id=?",[$id]);
	if (!$ss) out(false,'Not found.');
	$slides = DB::rows("SELECT * FROM `{$p}slideshow_slides` WHERE slideshow_id=? ORDER BY display_order ASC",[$id]);
	out(true,'',['slideshow'=>$ss,'slides'=>$slides]);
}

if ($action === 'save') {
	$id         = (int)post('id');
	$name       = trim(post('name'));
	$transition = in_array(post('transition'),['fade','slide']) ? post('transition') : 'fade';
	$interval   = max(1000,(int)post('interval',5000));
	$status     = (int)post('status',1);
	if (!$name) out(false,'Name required.');
	if ($id) {
		DB::exec("UPDATE `{$p}slideshows` SET name=?,transition=?,`interval`=?,status=? WHERE id=?",[$name,$transition,$interval,$status,$id]);
	} else {
		$id = DB::insert("INSERT INTO `{$p}slideshows` (name,transition,`interval`,status) VALUES (?,?,?,?)",[$name,$transition,$interval,$status]);
	}
	out(true,'Slideshow saved.',['id'=>$id]);
}

if ($action === 'delete') {
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}slideshow_slides` WHERE slideshow_id=?",[$id]);
	DB::exec("DELETE FROM `{$p}slideshows` WHERE id=?",[$id]);
	out(true,'');
}

if ($action === 'save_slide') {
	$id           = (int)post('id');
	$slideshow_id = (int)post('slideshow_id');
	$image        = trim(post('image'));
	$heading      = trim(post('heading'));
	$subtext      = trim(post('subtext'));
	$btn_label    = trim(post('btn_label'));
	$btn_url      = trim(post('btn_url'));
	$enabled      = (int)post('enabled',1);
	if ($id) {
		DB::exec("UPDATE `{$p}slideshow_slides` SET image=?,heading=?,subtext=?,btn_label=?,btn_url=?,enabled=? WHERE id=?",
			[$image,$heading,$subtext,$btn_label,$btn_url,$enabled,$id]);
	} else {
		$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}slideshow_slides` WHERE slideshow_id=?",[$slideshow_id]);
		$id  = DB::insert("INSERT INTO `{$p}slideshow_slides` (slideshow_id,image,heading,subtext,btn_label,btn_url,display_order,enabled) VALUES (?,?,?,?,?,?,?,?)",
			[$slideshow_id,$image,$heading,$subtext,$btn_label,$btn_url,$max+1,$enabled]);
	}
	$slide = DB::row("SELECT * FROM `{$p}slideshow_slides` WHERE id=?",[$id]);
	out(true,'',['slide'=>$slide]);
}

if ($action === 'delete_slide') {
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}slideshow_slides` WHERE id=?",[$id]);
	out(true,'');
}

if ($action === 'reorder_slides') {
	$ids = json_decode(post('ids'),true);
	foreach ($ids as $i=>$sid) DB::exec("UPDATE `{$p}slideshow_slides` SET display_order=? WHERE id=?",[$i,(int)$sid]);
	out(true,'');
}

out(false,'Unknown action.');
