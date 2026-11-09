<?php
// MacOs Technology admin: saves the product list.
// This file lives inside the password-protected "admin" folder.
ini_set('display_errors', '0');
error_reporting(0);
date_default_timezone_set('Africa/Nairobi');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function bad($msg) {
    respond(400, array('ok' => false, 'error' => $msg));
}
function clean_text($v, $max) {
    if (!is_string($v)) { return null; }
    $v = trim($v);
    if (strlen($v) > $max) { return null; }
    if (preg_match('/[<>"`\x00-\x1f]/', $v)) { return null; }
    return $v;
}

// Did the web server ask for and accept a password before running this file?
$user = '';
foreach (array('REMOTE_USER', 'REDIRECT_REMOTE_USER', 'PHP_AUTH_USER') as $key) {
    if (!empty($_SERVER[$key])) { $user = $_SERVER[$key]; break; }
}

$dataFile  = dirname(__DIR__) . '/data/products.json';
$dataDir   = dirname($dataFile);
$imgDir    = dirname(__DIR__) . '/img';
$backupDir = __DIR__ . '/backups';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $writable = is_dir($dataDir) && is_writable($dataDir) && (!file_exists($dataFile) || is_writable($dataFile));
    $imgWritable = is_dir($imgDir) && is_writable($imgDir);
    respond(200, array('auth' => ($user !== ''), 'user' => $user, 'writable' => $writable, 'imgWritable' => $imgWritable));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, array('ok' => false, 'error' => 'Method not allowed.'));
}

// Fail closed: never save unless a password was checked.
if ($user === '') {
    respond(403, array('ok' => false, 'error' => 'Saving is switched off because the admin folder is not password protected. Turn on Directory Privacy for the admin folder, then try again.'));
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

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > 700000) {
    bad('Nothing to save, or the list is too large.');
}
$list = json_decode($raw, true);
if (!is_array($list) || count($list) < 1 || count($list) > 2000) {
    bad('The product list is not valid.');
}

$cats = array('HP laptops', 'Dell laptops', 'Lenovo laptops', 'Other laptops', 'MacBooks and iMac', 'All-in-one PCs', 'Tablets', 'Printers', 'Mice and keyboards', 'Flash disks', 'Accessories');
$clean = array();
$seen  = array();
foreach ($list as $x) {
    if (!is_array($x)) { bad('The product list is not valid.'); }
    $id = isset($x['id']) ? $x['id'] : null;
    if (!is_int($id) || $id < 1 || isset($seen[$id])) { bad('An item has a missing or repeated number.'); }
    $seen[$id] = true;
    $n = clean_text(isset($x['n']) ? $x['n'] : '', 200);
    $d = clean_text(isset($x['d']) ? $x['d'] : '', 400);
    if ($n === null || $n === '' || $d === null) {
        bad('Item ' . $id . ': the name or specifications are empty, too long, or use a character that is not allowed ( < > " ` ).');
    }
    $c = isset($x['c']) ? $x['c'] : '';
    if (!in_array($c, $cats, true)) { bad('Item ' . $id . ': unknown category.'); }
    $p = isset($x['p']) ? $x['p'] : 0;
    if (!is_int($p) && !is_float($p)) { bad('Item ' . $id . ': the price must be a number.'); }
    $p = (int) round($p);
    if ($p < 0 || $p > 100000000) { bad('Item ' . $id . ': the price is out of range.'); }

    $item = array('id' => $id, 'n' => $n, 'd' => $d, 'c' => $c, 'p' => $p);
    $item['u'] = (isset($x['u']) && is_string($x['u']) && preg_match('/^[a-z ]{0,30}$/', $x['u'])) ? $x['u'] : '';
    if (isset($x['i']) && is_string($x['i']) && preg_match('/^[a-z0-9]{1,12}$/', $x['i'])) { $item['i'] = $x['i']; }
    if (isset($x['s']) && in_array($x['s'], array('in', 'low', 'out'), true)) { $item['s'] = $x['s']; }
    if (isset($x['b']) && in_array($x['b'], array('deal', 'hot', 'new'), true)) { $item['b'] = $x['b']; }
    if (isset($x['o']) && (is_int($x['o']) || is_float($x['o'])) && $x['o'] > 0 && $x['o'] <= 100000000) { $item['o'] = (int) round($x['o']); }
    if (!empty($x['h'])) { $item['h'] = 1; }
    if (isset($x['w'])) {
        $w = clean_text($x['w'], 40);
        if ($w !== null && $w !== '') { $item['w'] = $w; }
    }
    $clean[] = $item;
}

$json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) { bad('The list could not be saved because of an unusual character.'); }

// Keep the previous version (newest 30 are kept) so a mistake can be undone.
if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }
if (is_file($dataFile) && is_dir($backupDir)) {
    @copy($dataFile, $backupDir . '/products-' . date('Ymd-His') . '.json');
}
$old = glob($backupDir . '/products-*.json');
if (is_array($old) && count($old) > 30) {
    sort($old);
    foreach (array_slice($old, 0, count($old) - 30) as $f) { @unlink($f); }
}

$tmp = $dataFile . '.tmp';
if (file_put_contents($tmp, $json, LOCK_EX) === false) {
    respond(500, array('ok' => false, 'error' => 'Could not write the file. Ask your host to make the data folder writable.'));
}
if (!@rename($tmp, $dataFile)) {
    @unlink($tmp);
    respond(500, array('ok' => false, 'error' => 'Could not replace the products file. Ask your host to make the data folder writable.'));
}
respond(200, array('ok' => true, 'count' => count($clean)));
