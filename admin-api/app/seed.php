<?php
// Runs from entrypoint.sh on every start. Safe to run again and again:
// it only fills things that are missing in Supabase and never overwrites your edits.
require '/opt/app/db.php';
try {
    $r = seed_all();
    $pdo = db();
    $p = (int) $pdo->query('select count(*) from public.products')->fetchColumn();
    $i = (int) $pdo->query('select count(*) from public.product_images')->fetchColumn();
    echo "[seed] Supabase OK. products in database: $p (added now: {$r['products_added']}), images in database: $i (added now: {$r['images_added']})\n";
    exit(0);
} catch (Throwable $e) {
    echo '[seed] FAILED: ' . db_err($e) . "\n";
    exit(1);
}
