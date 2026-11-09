<?php
// MacOs Technology: Supabase (Postgres) helpers.
// Lives OUTSIDE the web root (/opt/app/db.php), so it can never be downloaded.
// The connection string is read from the SUPABASE_DB_URL environment variable (Render > Environment).

const SEED_DATA = '/opt/seed-data/products.json';
const SEED_IMG  = '/opt/seed-img';

function db_url() {
    $v = getenv('SUPABASE_DB_URL');
    if (is_string($v) && trim($v) !== '') { return trim($v); }
    if (isset($_SERVER['SUPABASE_DB_URL']) && trim($_SERVER['SUPABASE_DB_URL']) !== '') { return trim($_SERVER['SUPABASE_DB_URL']); }
    return '';
}

// One connection per request. Works with Supabase's pooler (transaction or session mode).
function db() {
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    $url = db_url();
    if ($url === '') { throw new RuntimeException('SUPABASE_DB_URL is not set (Render > Environment).'); }
    if (!preg_match('~^postgres(?:ql)?://([^:/@]+):(.*)@([^@/:]+)(?::(\d+))?/([^?]+)(\?.*)?$~s', $url, $m)) {
        throw new RuntimeException('SUPABASE_DB_URL is not a valid connection string. It must look like postgresql://USER:PASSWORD@HOST:PORT/postgres');
    }
    $user = rawurldecode($m[1]);
    $pass = rawurldecode($m[2]);
    $host = $m[3];
    $port = $m[4] !== '' ? $m[4] : '5432';
    $name = rawurldecode($m[5]);
    if (stripos($pass, 'YOUR-PASSWORD') !== false) {
        throw new RuntimeException('SUPABASE_DB_URL still contains the placeholder [YOUR-PASSWORD]. Put your real database password there (without the brackets).');
    }
    $ssl = 'require';
    if (isset($m[6]) && preg_match('/[?&]sslmode=([a-z-]+)/', $m[6], $q)) { $ssl = $q[1]; }
    $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';sslmode=' . $ssl . ';connect_timeout=10';
    try {
        $pdo = new PDO($dsn, $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,   // required for Supabase's transaction pooler (port 6543)
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    } catch (PDOException $e) {
        $pdo = null;
        $hint = '';
        if (stripos($host, 'db.') === 0 && substr($host, -12) === '.supabase.co') {
            $hint = 'The direct Supabase host (db.xxx.supabase.co) is IPv6-only and Render cannot reach it: use the Session pooler string from Supabase > Connect. ';
        }
        throw new RuntimeException($hint . $e->getMessage());
    }
    return $pdo;
}

// Short, safe-to-show text for an error.
function db_err($e) {
    $m = trim(preg_replace('/\s+/', ' ', $e->getMessage()));
    return strlen($m) > 360 ? substr($m, 0, 360) . '...' : $m;
}

function ensure_schema() {
    $pdo = db();
    $pdo->exec('create table if not exists public.products (
        id integer primary key,
        pos integer not null default 0,
        n text not null,
        d text not null default \'\',
        c text not null,
        p integer not null default 0,
        u text not null default \'\',
        i text,
        s text,
        b text,
        o integer,
        h boolean not null default false,
        w text
    )');
    $pdo->exec('create table if not exists public.product_images (
        key text primary key,
        data bytea not null,
        updated_at timestamptz not null default now()
    )');
    $pdo->exec('create table if not exists public.product_backups (
        id bigserial primary key,
        saved_at timestamptz not null default now(),
        data jsonb not null
    )');
    // Nobody can read or change these through Supabase's public API; only this server (direct connection) can.
    foreach (array('products', 'product_images', 'product_backups') as $t) {
        $pdo->exec('alter table public.' . $t . ' enable row level security');
    }
}

// The product list exactly as the shop and admin page expect it.
function products_list() {
    $rows = db()->query('select id,n,d,c,p,u,i,s,b,o,h,w from public.products order by pos, id')->fetchAll();
    $out = array();
    foreach ($rows as $r) {
        $item = array('id' => (int) $r['id'], 'n' => $r['n'], 'd' => $r['d'], 'c' => $r['c'], 'p' => (int) $r['p'], 'u' => $r['u']);
        foreach (array('i', 's', 'b', 'w') as $k) {
            if ($r[$k] !== null && $r[$k] !== '') { $item[$k] = $r[$k]; }
        }
        if ($r['o'] !== null) { $item['o'] = (int) $r['o']; }
        if ($r['h'] === true || $r['h'] === 't' || $r['h'] === 1 || $r['h'] === '1') { $item['h'] = 1; }
        $out[] = $item;
    }
    return $out;
}

// Replaces the whole list in one transaction and keeps the newest 30 backups.
// $clean is already validated (see admin/save.php).
function save_products(array $clean) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->query('select pg_advisory_xact_lock(730114)')->fetchAll();
        $pdo->exec('insert into public.product_backups(data)
                    select jsonb_agg(to_jsonb(p) order by pos, id) from public.products p having count(*) > 0');
        $pdo->exec('delete from public.product_backups
                    where id not in (select id from public.product_backups order by id desc limit 30)');
        $rows = array();
        $args = array();
        $ids  = array();
        $pos  = 0;
        foreach ($clean as $x) {
            $rows[] = '(?::int,?::int,?,?,?,?::int,?,?,?,?,?::int,?::boolean,?)';
            $ids[]  = (int) $x['id'];
            array_push($args,
                (int) $x['id'], $pos++, $x['n'], $x['d'], $x['c'], (int) $x['p'],
                isset($x['u']) ? $x['u'] : '',
                isset($x['i']) ? $x['i'] : null,
                isset($x['s']) ? $x['s'] : null,
                isset($x['b']) ? $x['b'] : null,
                isset($x['o']) ? (int) $x['o'] : null,
                !empty($x['h']) ? 'true' : 'false',
                isset($x['w']) ? $x['w'] : null
            );
        }
        if (count($rows) > 0) {
            $st = $pdo->prepare('insert into public.products (id,pos,n,d,c,p,u,i,s,b,o,h,w) values ' . implode(',', $rows) . '
                on conflict (id) do update set pos=excluded.pos, n=excluded.n, d=excluded.d, c=excluded.c, p=excluded.p,
                    u=excluded.u, i=excluded.i, s=excluded.s, b=excluded.b, o=excluded.o, h=excluded.h, w=excluded.w');
            $st->execute($args);
            $pdo->exec('delete from public.products where id not in (' . implode(',', $ids) . ')');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function save_image($key, $bytes) {
    $st = db()->prepare("insert into public.product_images (key, data) values (?, decode(?, 'base64'))
        on conflict (key) do update set data = excluded.data, updated_at = now()");
    $st->execute(array($key, base64_encode($bytes)));
}

function get_image($key) {
    $st = db()->prepare("select encode(data, 'base64') as b from public.product_images where key = ?");
    $st->execute(array($key));
    $b = $st->fetchColumn();
    return $b === false ? null : base64_decode($b);
}

// Cheap health check used when the admin page opens: ONE query, no schema changes.
// Only if the tables are missing (very first run) or the product table is empty does it run seed_all().
function db_status() {
    $sql = 'select (select count(*) from public.products) as p,
                   (select count(*) from public.product_images) as i,
                   (select count(*) from public.product_backups) as b';
    $pdo = db();
    try {
        $r = $pdo->query($sql)->fetch();
        if ((int) $r['p'] === 0) { seed_all(); $r = $pdo->query($sql)->fetch(); }
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== '42P01') { throw $e; }   // 42P01 = table does not exist yet
        seed_all();
        $r = $pdo->query($sql)->fetch();
    }
    return array('products' => (int) $r['p'], 'images' => (int) $r['i'], 'backups' => (int) $r['b']);
}

// When a photo was last stored (unix seconds), or null. Reads no image data, so it is very light.
function image_version($key) {
    $st = db()->prepare('select floor(extract(epoch from updated_at))::bigint from public.product_images where key = ?');
    $st->execute(array($key));
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

// First start only: fill the empty tables from the files in the repo.
// Never overwrites anything that already exists in Supabase.
function seed_all() {
    ensure_schema();
    $pdo = db();
    $res = array('products_added' => 0, 'images_added' => 0);

    $count = (int) $pdo->query('select count(*) from public.products')->fetchColumn();
    if ($count === 0 && is_file(SEED_DATA)) {
        $list = json_decode((string) file_get_contents(SEED_DATA), true);
        $clean = array();
        if (is_array($list)) {
            foreach ($list as $x) {
                if (!is_array($x) || !isset($x['id'], $x['n'], $x['c']) || !is_int($x['id'])) { continue; }
                $it = array('id' => $x['id'], 'n' => (string) $x['n'], 'd' => isset($x['d']) ? (string) $x['d'] : '',
                            'c' => (string) $x['c'], 'p' => isset($x['p']) ? (int) round($x['p']) : 0,
                            'u' => isset($x['u']) ? (string) $x['u'] : '');
                foreach (array('i', 's', 'b', 'w') as $k) { if (isset($x[$k]) && is_string($x[$k]) && $x[$k] !== '') { $it[$k] = $x[$k]; } }
                if (isset($x['o']) && is_numeric($x['o']) && $x['o'] > 0) { $it['o'] = (int) round($x['o']); }
                if (!empty($x['h'])) { $it['h'] = 1; }
                $clean[] = $it;
            }
        }
        if (count($clean) > 0) { save_products($clean); $res['products_added'] = count($clean); }
    }

    $have = array_flip($pdo->query('select key from public.product_images')->fetchAll(PDO::FETCH_COLUMN));
    foreach ((array) glob(SEED_IMG . '/*.jpg') as $f) {
        $key = basename($f, '.jpg');
        if (isset($have[$key]) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $key)) { continue; }
        $bytes = file_get_contents($f);
        if ($bytes !== false && strlen($bytes) > 0) { save_image($key, $bytes); $res['images_added']++; }
    }
    return $res;
}
