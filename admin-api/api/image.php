<?php
// Public: one product photo. Reached as /img/<key>.jpg (see apache.conf).
ini_set('display_errors', '0');
error_reporting(0);
require '/opt/app/db.php';

header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
if (!is_string($path) || !preg_match('~/img/([A-Za-z0-9_-]{1,40})\.jpg$~', $path, $m)) {
    http_response_code(404);
    exit;
}
$key = $m[1];

// 1) Cheap check first: when was this photo stored? If the browser already has that version, answer 304
//    without reading the photo itself out of Supabase.
$ver = null;
try {
    $ver = image_version($key);
} catch (Throwable $e) {
    error_log('[macos-api] image ' . $key . ' version failed: ' . db_err($e));
}
$etag = null;
if ($ver !== null) {
    $etag = '"img-' . $key . '-' . $ver . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=86400');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
}

// 2) Read the photo from Supabase.
$bytes = null;
if ($ver !== null) {
    try {
        $bytes = get_image($key);
    } catch (Throwable $e) {
        error_log('[macos-api] image ' . $key . ' failed: ' . db_err($e));
    }
}
if ($bytes === null) {
    // Database down or photo missing: fall back to the original photo shipped with the app, if there is one.
    $f = SEED_IMG . '/' . $key . '.jpg';
    if (is_file($f)) { $bytes = file_get_contents($f); }
}
if ($bytes === null || $bytes === false) {
    http_response_code(404);
    header_remove('ETag');
    header('Cache-Control: no-store');
    header('Content-Type: text/plain');
    echo 'Not found';
    exit;
}

if ($etag === null) {
    $etag = '"' . md5($bytes) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=86400');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
