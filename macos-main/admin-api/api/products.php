<?php
// Public, read-only: the product list for the shop and the admin page.
// Reached as /data/products.json (see apache.conf).
ini_set('display_errors', '0');
error_reporting(0);
require '/opt/app/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode(products_list(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    // Deliberately no fallback to old data: the admin page must never save an out-of-date list.
    error_log('[macos-api] products.json failed: ' . db_err($e));
    http_response_code(503);
    echo json_encode(array('error' => 'Database unavailable.'));
}
