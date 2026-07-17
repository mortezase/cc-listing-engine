<?php
/**
 * CC Listing Engine v0.1.4 — Central Commercial Realty
 * Single-file listing API + AMPRE sync service (runs on EasyPanel, PHP built-in server).
 *
 * ENV: DB_HOST, DB_NAME, DB_USER, DB_PASS, IDX_TOKEN, API_KEY, SYNC_KEY
 *
 * Endpoints (all require header  X-Api-Key: <API_KEY>  except /health):
 *   GET /health                          -> {ok, listings, last_sync}
 *   GET /v1/listings?cat=&txn=&city=&q=&ind=&sqmin=&sqmax=&sort=&page=&pp=
 *   GET /v1/listing/{listingKey}
 *   GET /v1/cities                       -> distinct cities with counts
 *   POST /v1/sync?key=<SYNC_KEY>         -> incremental IDX sync (call from cron)
 *
 * Compliance: PropTx/AMPRE data is served ONLY to Central Commercial's own
 * authorized website(s) via API key. No AI systems. Clause 1.e respected.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');

const API_BASE = 'https://query.ampre.ca/odata/';
const VERSION  = '0.1.4';

function env($k, $d = null) { $v = getenv($k); return $v === false ? $d : $v; }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . env('DB_HOST', 'mysql') . ';dbname=' . env('DB_NAME', 'listings') . ';charset=utf8mb4',
        env('DB_USER', 'listings'), env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function ensure_schema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS listings (
        listing_key VARCHAR(64) PRIMARY KEY,
        feed VARCHAR(8) DEFAULT 'idx',
        list_price DECIMAL(14,2) NULL,
        price_unit VARCHAR(60) DEFAULT '',
        address VARCHAR(300) DEFAULT '',
        city VARCHAR(120) DEFAULT '',
        province VARCHAR(60) DEFAULT 'ON',
        postal VARCHAR(20) DEFAULT '',
        property_type VARCHAR(120) DEFAULT '',
        property_subtype VARCHAR(120) DEFAULT '',
        transaction_type VARCHAR(60) DEFAULT '',
        business_type VARCHAR(300) DEFAULT '',
        status VARCHAR(60) DEFAULT '',
        mls_number VARCHAR(40) DEFAULT '',
        remarks MEDIUMTEXT NULL,
        list_office VARCHAR(200) DEFAULT '',
        perm_adv CHAR(1) DEFAULT 'Y',
        disp_addr CHAR(1) DEFAULT 'Y',
        sqft VARCHAR(40) DEFAULT '',
        list_date DATE NULL,
        modified DATETIME NULL,
        first_seen DATETIME NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        photos MEDIUMTEXT NULL,
        raw MEDIUMBLOB NULL,
        KEY city (city), KEY status (status), KEY modified (modified), KEY price (list_price)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS meta (k VARCHAR(60) PRIMARY KEY, v TEXT)");
}

function meta_get($k, $d = null) {
    $st = db()->prepare("SELECT v FROM meta WHERE k = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $d : $v;
}
function meta_set($k, $v): void {
    db()->prepare("REPLACE INTO meta (k, v) VALUES (?, ?)")->execute([$k, $v]);
}

function jout($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function require_api_key(): void {
    $want = env('API_KEY');
    if (!$want) jout(['error' => 'engine misconfigured: API_KEY not set'], 500);
    $got = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
    if (!hash_equals($want, $got)) jout(['error' => 'invalid api key'], 401);
}

function http_get_json(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) throw new RuntimeException("AMPRE HTTP $code");
    return json_decode($body, true) ?: [];
}

function pack_raw(array $l): string {
    $clean = array_filter($l, fn($v) => $v !== null && $v !== '' && $v !== []);
    return 'gz:' . base64_encode(gzdeflate(json_encode($clean), 6));
}
function unpack_raw(?string $s): array {
    if (!$s) return [];
    if (str_starts_with($s, 'gz:')) {
        $j = gzinflate(base64_decode(substr($s, 3)));
        return $j ? (json_decode($j, true) ?: []) : [];
    }
    return json_decode($s, true) ?: [];
}

/* ---------------- SYNC ---------------- */

function sync_photos(array $keys, string $token): void {
    if (!$keys) return;
    $flt = implode(' or ', array_map(fn($k) => "ResourceRecordKey eq '" . $k . "'", $keys));
    $url = API_BASE . 'Media?' . http_build_query([
        '$filter' => "($flt) and ResourceName eq 'Property' and MediaCategory eq 'Photo'",
        '$select' => 'ResourceRecordKey,MediaURL,Order',
        '$orderby' => 'Order', '$top' => 500,
    ], '', '&', PHP_QUERY_RFC3986);
    try { $body = http_get_json($url, $token); } catch (Throwable) { return; }
    $byKey = [];
    foreach (($body['value'] ?? []) as $m) {
        $k = $m['ResourceRecordKey'] ?? '';
        $u = $m['MediaURL'] ?? '';
        if (!$k || !$u) continue;
        if (count($byKey[$k] ?? []) < 12) $byKey[$k][] = $u;
    }
    $st = db()->prepare("UPDATE listings SET photos = ? WHERE listing_key = ?");
    foreach ($byKey as $k => $urls) $st->execute([json_encode($urls), $k]);
}

function run_sync(int $max_pages = 15): array {
    $token = env('IDX_TOKEN');
    if (!$token) return ['error' => 'IDX_TOKEN not set'];
    if (meta_get('sync_lock') && time() - (int)meta_get('sync_lock') < 300) return ['skipped' => 'lock'];
    meta_set('sync_lock', (string)time());
    set_time_limit(0);
    ignore_user_abort(true);

    $last = meta_get('last_sync');
    $filter = "StandardStatus eq 'Active'";
    if ($last) $filter .= " and ModificationTimestamp gt " . gmdate('Y-m-d\TH:i:s\Z', strtotime($last));

    $url = API_BASE . 'Property?' . http_build_query([
        '$filter' => $filter, '$top' => 100, '$orderby' => 'ModificationTimestamp',
    ], '', '&', PHP_QUERY_RFC3986);

    $count = 0; $pages = 0; $maxmod = ''; $batch = [];
    $sel = db()->prepare("SELECT first_seen, lat, lng, photos FROM listings WHERE listing_key = ?");
    $up = db()->prepare("REPLACE INTO listings
        (listing_key, feed, list_price, price_unit, address, city, province, postal, property_type, property_subtype,
         transaction_type, business_type, status, mls_number, remarks, list_office, perm_adv, disp_addr, sqft,
         list_date, modified, first_seen, lat, lng, photos, raw)
        VALUES (?, 'idx', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $err = null;
    while ($url && $pages < $max_pages) {
        try { $body = http_get_json($url, $token); }
        catch (Throwable $e) { $err = $e->getMessage(); break; }
        foreach (($body['value'] ?? []) as $l) {
            $key = $l['ListingKey'] ?? '';
            if (!$key) continue;
            if (($l['ModificationTimestamp'] ?? '') > $maxmod) $maxmod = $l['ModificationTimestamp'];
            $yn = fn($v) => ($v === true || $v === 'Y' || $v === 'Yes' || $v === 'true' || $v === 1) ? 'Y' : (($v === null || $v === '') ? 'Y' : 'N');
            $unit = '';
            foreach (['ListPriceUnit', 'LeaseAmountFrequency'] as $uf) if (!empty($l[$uf]) && is_string($l[$uf])) { $unit = $l[$uf]; break; }
            $sel->execute([$key]);
            $prev = $sel->fetch() ?: ['first_seen' => gmdate('Y-m-d H:i:s'), 'lat' => null, 'lng' => null, 'photos' => null];
            $up->execute([
                $key,
                isset($l['ListPrice']) ? (float)$l['ListPrice'] : null,
                $unit,
                (string)($l['UnparsedAddress'] ?? ''),
                (string)($l['City'] ?? ''),
                (string)($l['StateOrProvince'] ?? 'ON'),
                (string)($l['PostalCode'] ?? ''),
                (string)($l['PropertyType'] ?? ''),
                (string)($l['PropertySubType'] ?? ''),
                (string)($l['TransactionType'] ?? ''),
                is_array($l['BusinessType'] ?? null) ? implode(', ', $l['BusinessType']) : (string)($l['BusinessType'] ?? ''),
                (string)($l['StandardStatus'] ?? 'Active'),
                (string)($l['ListingId'] ?? $key),
                (string)($l['PublicRemarks'] ?? ''),
                (string)($l['ListOfficeName'] ?? ''),
                $yn($l['InternetEntireListingDisplayYN'] ?? null),
                $yn($l['InternetAddressDisplayYN'] ?? null),
                (string)($l['BuildingAreaTotal'] ?? ''),
                !empty($l['OriginalEntryTimestamp']) ? gmdate('Y-m-d', strtotime($l['OriginalEntryTimestamp'])) : null,
                isset($l['ModificationTimestamp']) ? gmdate('Y-m-d H:i:s', strtotime($l['ModificationTimestamp'])) : null,
                $prev['first_seen'] ?: gmdate('Y-m-d H:i:s'),
                $prev['lat'], $prev['lng'], $prev['photos'],
                pack_raw($l),
            ]);
            $batch[] = $key;
            $count++;
        }
        foreach (array_chunk($batch, 20) as $chunk) sync_photos($chunk, $token);
        $batch = [];
        // Save progress EVERY page — a crash or restart never loses ground
        if ($maxmod) meta_set('last_sync', gmdate('Y-m-d H:i:s', strtotime($maxmod)));
        $url = $body['@odata.nextLink'] ?? null;
        $pages++;
    }
    $more = ($url !== null);
    if (!$more && !$err && !meta_get('last_sync')) meta_set('last_sync', gmdate('Y-m-d H:i:s'));
    meta_set('sync_lock', '0');
    meta_set('last_sync_result', "$count upserted, $pages pages" . ($err ? ", ERROR: $err" : '') . ', ' . gmdate('Y-m-d H:i:s'));
    return ['upserted' => $count, 'pages' => $pages, 'more' => $more || (bool)$err, 'error' => $err];
}

/* ---------------- QUERY API ---------------- */

function q_listings(): array {
    $where = ["feed = 'idx'", "perm_adv = 'Y'", "status = 'Active'"];
    $args = [];
    $txn = $_GET['txn'] ?? 'sale';
    $cat = $_GET['cat'] ?? '';
    if ($cat === '') $cat = ($txn === 'lease') ? 'property' : 'business';
    if ($txn === 'lease' && $cat === 'business') $cat = 'property';
    $where[] = "transaction_type LIKE ?";
    $args[] = $txn === 'lease' ? '%Lease%' : '%Sale%';
    if ($cat === 'business')    $where[] = "(business_type <> '' OR property_subtype LIKE '%Business%')";
    if ($cat === 'property')    $where[] = "(property_type LIKE '%Commercial%' AND business_type = '')";
    if ($cat === 'residential') $where[] = "property_type NOT LIKE '%Commercial%'";
    foreach ((array)($_GET['city'] ?? []) as $c) { /* multi city */ }
    $cities = array_filter(is_array($_GET['city'] ?? null) ? $_GET['city'] : (isset($_GET['city']) ? [$_GET['city']] : []));
    if ($cities) {
        $where[] = 'city IN (' . implode(',', array_fill(0, count($cities), '?')) . ')';
        $args = array_merge($args, $cities);
    }
    if (!empty($_GET['ind'])) { $where[] = 'business_type LIKE ?'; $args[] = '%' . $_GET['ind'] . '%'; }
    if (!empty($_GET['q'])) {
        $where[] = '(remarks LIKE ? OR address LIKE ? OR business_type LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($args, $like, $like, $like);
    }
    if (!empty($_GET['sqmin'])) { $where[] = 'CAST(sqft AS UNSIGNED) >= ?'; $args[] = (int)$_GET['sqmin']; }
    if (!empty($_GET['sqmax'])) { $where[] = 'CAST(sqft AS UNSIGNED) <= ?'; $args[] = (int)$_GET['sqmax']; }
    $order = match ($_GET['sort'] ?? '') {
        'newest' => 'list_date DESC',
        'price_asc' => 'list_price ASC',
        'price_desc' => 'list_price DESC',
        default => 'modified DESC',
    };
    $pp = min(50, max(1, (int)($_GET['pp'] ?? 12)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $wsql = implode(' AND ', $where);
    $st = db()->prepare("SELECT COUNT(*) FROM listings WHERE $wsql");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT listing_key, list_price, price_unit, address, city, province, property_type, property_subtype,
        transaction_type, business_type, mls_number, sqft, list_date, lat, lng, photos, disp_addr
        FROM listings WHERE $wsql ORDER BY $order LIMIT " . (($page - 1) * $pp) . ", $pp");
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['photos'] = json_decode($r['photos'] ?? '[]', true) ?: [];
        if ($r['disp_addr'] !== 'Y') $r['address'] = '';
        unset($r['disp_addr']);
        $rows[] = $r;
    }
    return ['total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $pp), 'listings' => $rows];
}

function q_listing(string $key): array {
    $st = db()->prepare("SELECT * FROM listings WHERE listing_key = ? AND feed = 'idx' AND perm_adv = 'Y' AND status = 'Active'");
    $st->execute([$key]);
    $r = $st->fetch();
    if (!$r) jout(['error' => 'not found'], 404);
    $r['photos'] = json_decode($r['photos'] ?? '[]', true) ?: [];
    $r['fields'] = unpack_raw($r['raw']);
    unset($r['raw']);
    if ($r['disp_addr'] !== 'Y') $r['address'] = '';
    return $r;
}

/* ---------------- ROUTER ---------------- */

ensure_schema();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    $n = (int)db()->query("SELECT COUNT(*) FROM listings WHERE status = 'Active'")->fetchColumn();
    $t = (int)db()->query('SELECT COUNT(*) FROM listings')->fetchColumn();
    jout(['ok' => true, 'engine' => VERSION, 'active_listings' => $n, 'total_rows' => $t, 'last_sync' => meta_get('last_sync'), 'last_result' => meta_get('last_sync_result'), 'last_error' => meta_get('last_error')]);
}

if ($path === '/v1/sync') {
    if (($_GET['key'] ?? '') !== env('SYNC_KEY', '')) jout(['error' => 'invalid sync key'], 401);
    try {
        jout(run_sync(min(50, max(1, (int)($_GET['pages'] ?? 15)))));
    } catch (Throwable $e) {
        meta_set('sync_lock', '0');
        meta_set('last_error', get_class($e) . ': ' . $e->getMessage() . ' @ line ' . $e->getLine());
        jout(['error' => $e->getMessage(), 'line' => $e->getLine()], 500);
    }
}

require_api_key();

if ($path === '/v1/probe') {
    $vt = env('VOW_TOKEN');
    if (!$vt) jout(['error' => 'VOW_TOKEN env not set on the engine'], 500);
    set_time_limit(0);
    $since = gmdate('Y-m-d\TH:i:s\Z', strtotime('-730 days'));
    $count = function (string $filter) use ($vt) {
        try {
            $b = http_get_json(API_BASE . 'Property?' . http_build_query(['$filter' => $filter, '$top' => 0, '$count' => 'true'], '', '&', PHP_QUERY_RFC3986), $vt);
            return $b['@odata.count'] ?? 'no-count';
        } catch (Throwable $e) { return 'ERR ' . $e->getMessage(); }
    };
    $out = [];
    $out['Sold (all types, 2y)']   = $count("MlsStatus eq 'Sold' and ModificationTimestamp gt $since");
    $out['Leased (all types, 2y)'] = $count("MlsStatus eq 'Leased' and ModificationTimestamp gt $since");
    // Discover PropertyType labels among Sold records
    $types = [];
    try {
        $b = http_get_json(API_BASE . 'Property?' . http_build_query([
            '$filter' => "MlsStatus eq 'Sold' and ModificationTimestamp gt $since",
            '$top' => 300, '$select' => 'PropertyType',
        ], '', '&', PHP_QUERY_RFC3986), $vt);
        foreach (($b['value'] ?? []) as $v) { $pt = $v['PropertyType'] ?? '(null)'; $types[$pt] = ($types[$pt] ?? 0) + 1; }
    } catch (Throwable $e) { $out['sample error'] = $e->getMessage(); }
    arsort($types);
    $out['PropertyType labels in 300 Sold samples'] = $types;
    foreach (array_keys($types) as $pt) {
        if (stripos($pt, 'residential') === false && stripos($pt, 'condo') === false) {
            $out["Sold, PropertyType='$pt' (2y)"]   = $count("MlsStatus eq 'Sold' and PropertyType eq '$pt' and ModificationTimestamp gt $since");
            $out["Leased, PropertyType='$pt' (2y)"] = $count("MlsStatus eq 'Leased' and PropertyType eq '$pt' and ModificationTimestamp gt $since");
        }
    }
    try {
        $b = http_get_json(API_BASE . 'Property?' . http_build_query(['$filter' => "MlsStatus eq 'Sold' and ModificationTimestamp gt $since", '$top' => 2, '$select' => 'MlsStatus,StandardStatus,PropertyType,PropertySubType,TransactionType,ClosePrice,CloseDate,BusinessType'], '', '&', PHP_QUERY_RFC3986), $vt);
        $out['sample_sold_records'] = $b['value'] ?? [];
    } catch (Throwable $e) {}
    jout(['probe' => $out]);
}

if ($path === '/v1/listings') jout(q_listings());
if (preg_match('#^/v1/listing/([A-Za-z0-9]+)$#', $path, $m)) jout(q_listing($m[1]));
if ($path === '/v1/cities') {
    $rows = db()->query("SELECT city, COUNT(*) n FROM listings WHERE status = 'Active' AND city <> '' GROUP BY city ORDER BY n DESC LIMIT 100")->fetchAll();
    jout(['cities' => $rows]);
}

jout(['error' => 'unknown endpoint', 'endpoints' => ['/health', '/v1/listings', '/v1/listing/{key}', '/v1/cities', 'POST /v1/sync?key=…']], 404);
