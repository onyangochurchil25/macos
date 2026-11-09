<?php
// MacOs Technology admin: stores one product photo in the "img" folder.
// This file lives inside the password-protected "admin" folder.
// Photos are stored in Supabase (see /opt/app/db.php).
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require '/opt/app/db.php';

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$user = '';
foreach (array('REMOTE_USER', 'REDIRECT_REMOTE_USER', 'PHP_AUTH_USER') as $key) {
    if (!empty($_SERVER[$key])) { $user = $_SERVER[$key]; break; }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, array('ok' => false, 'error' => 'Method not allowed.'));
}
if ($user === '') {
    respond(403, array('ok' => false, 'error' => 'Photo upload is switched off because the admin folder is not password protected.'));
}
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'macos-admin') {
    respond(400, array('ok' => false, 'error' => 'Request not accepted.'));
}
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
    $thisHost   = preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    if (!$originHost || strcasecmp($originHost, $thisHost) !== 0) {
        respond(403, array('ok' => false, 'error' => 'The request came from another website.'));
    }
}

$key = isset($_SERVER['HTTP_X_PHOTO_KEY']) ? $_SERVER['HTTP_X_PHOTO_KEY'] : '';
if (!preg_match('/^p[0-9]{1,11}$/', $key)) {
    respond(400, array('ok' => false, 'error' => 'Bad photo name.'));
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) < 200 || strlen($raw) > 1500000) {
    respond(413, array('ok' => false, 'error' => 'The photo is missing or too large.'));
}
$info = @getimagesizefromstring($raw);
if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] > 4000 || $info[1] > 4000) {
    respond(400, array('ok' => false, 'error' => 'Please send a JPEG photo.'));
}

try {
    save_image($key, $raw);
} catch (Throwable $e) {
    error_log('[macos-admin] upload.php database write failed: ' . db_err($e));
    respond(500, array('ok' => false, 'error' => 'The photo could not be stored (' . db_err($e) . ').'));
}
respond(200, array('ok' => true, 'key' => $key));
