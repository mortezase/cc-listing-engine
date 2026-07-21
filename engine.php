<?php
/**
 * CC Listing Engine v0.4.13 — Central Commercial Realty
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
const VERSION  = '0.4.13';

function env($k, $d = null) { $v = getenv($k); return $v === false ? $d : $v; }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . env('DB_HOST', 'mysql') . ';dbname=' . env('DB_NAME', 'listings') . ';charset=utf8mb4',
        env('DB_USER', 'listings'), env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]
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
    db()->exec("CREATE TABLE IF NOT EXISTS geocache (
        addr_hash CHAR(32) PRIMARY KEY,
        lat DECIMAL(10,7) NOT NULL,
        lng DECIMAL(10,7) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { db()->exec("UPDATE listings SET close_date = NULL WHERE close_date > DATE_ADD(CURDATE(), INTERVAL 1 YEAR) OR close_date < '2000-01-01'"); } catch (Throwable $e) {}
    foreach (["ALTER TABLE listings ADD COLUMN close_price DECIMAL(14,2) NULL",
              "ALTER TABLE listings ADD COLUMN close_date DATE NULL",
              "ALTER TABLE listings ADD INDEX close_date (close_date)",
              "ALTER TABLE listings ADD COLUMN sort_date DATE GENERATED ALWAYS AS (COALESCE(close_date, DATE(modified))) STORED",
              "ALTER TABLE listings ADD INDEX feed_sort (feed, sort_date)",
              "ALTER TABLE listings ADD INDEX feed_status (feed, status)",
              "ALTER TABLE listings ADD INDEX feed_status_city (feed, status, city)",
              "ALTER TABLE listings ADD INDEX feed_status_modified (feed, status, modified)",
              "ALTER TABLE listings ADD INDEX feed_status_price (feed, status, list_price)",
              "ALTER TABLE listings ADD INDEX feed_txn_city (feed, transaction_type(15), city)",
              "ALTER TABLE listings ADD INDEX feed_ptype (feed, property_type(30))",
              "ALTER TABLE listings ADD INDEX feed_sort_status (feed, sort_date, status)",
              "ALTER TABLE listings ADD INDEX first_seen_idx (first_seen)"] as $ddl) {
    // ANALYZE moved off the boot path (was blocking startup) — call /v1/optimize?key=SYNC_KEY once after
    // indexes finish building. Boot is now clean & fast; the optimizer settles as syncs run anyway.
        try { db()->exec($ddl); } catch (Throwable $e) { /* already applied */ }
    }
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

function sync_photos(array $keys, string $token, int $cap = 30): void {
    if (!$keys) return;
    $flt = implode(' or ', array_map(fn($k) => "ResourceRecordKey eq '" . $k . "'", $keys));
    $url = API_BASE . 'Media?' . http_build_query([
        '$filter' => "($flt) and ResourceName eq 'Property' and MediaCategory eq 'Photo'",
        '$select' => 'ResourceRecordKey,MediaURL,Order,ImageSizeDescription',
        '$orderby' => 'Order', '$top' => 4000,
    ], '', '&', PHP_QUERY_RFC3986);
    try { $body = http_get_json($url, $token); } catch (Throwable) { return; }
    // Dedupe by BASE IMAGE identity (the trailing base64 path segment in TRREB URLs is stable across size variants).
    // Keep the largest variant per base image; preserve original Order for gallery sequence.
    $rank = ['Largest' => 3, 'Large' => 2, 'Medium' => 1, 'Thumbnail' => 0];
    $grouped = [];
    foreach (($body['value'] ?? []) as $m) {
        $k = $m['ResourceRecordKey'] ?? '';
        $u = $m['MediaURL'] ?? '';
        if (!$k || !$u) continue;
        $o = isset($m['Order']) ? (int)$m['Order'] : 0;
        $sz = $rank[$m['ImageSizeDescription'] ?? ''] ?? 1;
        // Base image key = the final path segment before .jpg/.jpeg/.png (that's the stable image identifier)
        $bid = preg_match('#/([^/.?]+)\.(?:jpe?g|png|webp)(?:\?|$)#i', $u, $mm) ? $mm[1] : md5($u);
        // If we've seen this base image already, keep the LARGER variant; if same size, keep the one with the lower Order (earlier in the gallery)
        if (isset($grouped[$k][$bid])) {
            if ($sz > $grouped[$k][$bid]['sz']) { $grouped[$k][$bid] = ['url' => $u, 'sz' => $sz, 'order' => $o]; }
            elseif ($sz === $grouped[$k][$bid]['sz'] && $o < $grouped[$k][$bid]['order']) { $grouped[$k][$bid]['order'] = $o; }
        } else {
            $grouped[$k][$bid] = ['url' => $u, 'sz' => $sz, 'order' => $o];
        }
    }
    $st = db()->prepare("UPDATE listings SET photos = ? WHERE listing_key = ?");
    foreach ($grouped as $k => $imgs) {
        // Sort by Order — preserves the listing agent's intended photo sequence
        uasort($imgs, fn($a, $b) => $a['order'] <=> $b['order']);
        $urls = array_slice(array_column($imgs, 'url'), 0, $cap);
        $st->execute([json_encode(array_values($urls)), $k]);
    }
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
        VALUES (?, 'idx', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

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
    if (!$err) meta_set('last_error', '');
    return ['upserted' => $count, 'pages' => $pages, 'more' => $more || (bool)$err, 'error' => $err];
}

/** VOW sync: residential + all closed/off-market records (2y window). Slim: 3 photos. */
function run_vow_sync(int $max_pages = 15): array {
    $token = env('VOW_TOKEN');
    if (!$token) return ['error' => 'VOW_TOKEN not set'];
    if (meta_get('vow_lock') && time() - (int)meta_get('vow_lock') < 300) return ['skipped' => 'lock'];
    meta_set('vow_lock', (string)time());
    set_time_limit(0);
    ignore_user_abort(true);

    $lookback = max(30, (int)env('VOW_DAYS', '730'));
    $last = meta_get('vow_last');
    $filter = "StandardStatus ne 'Active'";
    $filter .= ' and ModificationTimestamp gt ' . gmdate('Y-m-d\TH:i:s\Z', $last ? strtotime($last) : strtotime("-$lookback days"));

    $url = API_BASE . 'Property?' . http_build_query([
        '$filter' => $filter, '$top' => 100, '$orderby' => 'ModificationTimestamp',
    ], '', '&', PHP_QUERY_RFC3986);

    $count = 0; $pages = 0; $maxmod = ''; $batch = []; $err = null;
    $sel = db()->prepare("SELECT first_seen, lat, lng, photos FROM listings WHERE listing_key = ?");
    $up = db()->prepare("REPLACE INTO listings
        (listing_key, feed, list_price, price_unit, close_price, close_date, address, city, province, postal,
         property_type, property_subtype, transaction_type, business_type, status, mls_number, remarks, list_office,
         perm_adv, disp_addr, sqft, list_date, modified, first_seen, lat, lng, photos, raw)
        VALUES (?, 'vow', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Y', ?, ?, ?, ?, ?, ?, ?, ?, ?)");

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
                isset($l['ClosePrice']) ? (float)$l['ClosePrice'] : null,
                (!empty($l['CloseDate']) && ($cd = strtotime($l['CloseDate'])) && $cd < strtotime('+1 year') && $cd > strtotime('2000-01-01')) ? gmdate('Y-m-d', $cd) : null,
                (string)($l['UnparsedAddress'] ?? ''),
                (string)($l['City'] ?? ''),
                (string)($l['StateOrProvince'] ?? 'ON'),
                (string)($l['PostalCode'] ?? ''),
                (string)($l['PropertyType'] ?? ''),
                (string)($l['PropertySubType'] ?? ''),
                (string)($l['TransactionType'] ?? ''),
                is_array($l['BusinessType'] ?? null) ? implode(', ', $l['BusinessType']) : (string)($l['BusinessType'] ?? ''),
                (string)($l['MlsStatus'] ?? ($l['StandardStatus'] ?? '')),
                (string)($l['ListingId'] ?? $key),
                (string)($l['PublicRemarks'] ?? ''),
                (string)($l['ListOfficeName'] ?? ''),
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
        foreach (array_chunk($batch, 20) as $chunk) sync_photos($chunk, $token, 3);
        $batch = [];
        if ($maxmod) meta_set('vow_last', gmdate('Y-m-d H:i:s', strtotime($maxmod)));
        $url = $body['@odata.nextLink'] ?? null;
        $pages++;
    }
    // Retention: keep VOW records forever by default (the archive grows past the feed's
    // 2-year window over time). Set VOW_RETAIN_DAYS>0 to enforce a purge window instead.
    $retain = (int)env('VOW_RETAIN_DAYS', '0');
    if ($retain > 0) {
        $cut = gmdate('Y-m-d', strtotime("-$retain days"));
        $pg = db()->prepare("DELETE FROM listings WHERE feed = 'vow' AND ((close_date IS NOT NULL AND close_date < ?) OR (close_date IS NULL AND modified < ?))");
        $pg->execute([$cut, $cut . ' 00:00:00']);
    }
    $more = ($url !== null);
    meta_set('vow_lock', '0');
    meta_set('vow_last_result', "$count upserted, $pages pages" . ($err ? ", ERROR: $err" : '') . ', ' . gmdate('Y-m-d H:i:s'));
    if (!$err) meta_set('last_error', '');
    return ['upserted' => $count, 'pages' => $pages, 'more' => $more || (bool)$err, 'error' => $err];
}

/* ---------------- GEOCODING ---------------- */

function geo_lookup(string $q): ?array {
    // Photon (komoot) primary
    $url = 'https://photon.komoot.io/api/?' . http_build_query(['q' => $q, 'limit' => 1, 'lang' => 'en']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'CCListingEngine/1.0 (info@centralcommercial.ca)']);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($body !== false && $code === 200) {
        $j = json_decode($body, true);
        $c = $j['features'][0]['geometry']['coordinates'] ?? null;
        if ($c && isset($c[0], $c[1])) return ['lat' => (float)$c[1], 'lng' => (float)$c[0]];
    }
    // Nominatim fallback
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query(['q' => $q, 'format' => 'json', 'limit' => 1]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'CCListingEngine/1.0 (info@centralcommercial.ca)']);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($body !== false && $code === 200) {
        $j = json_decode($body, true);
        if (!empty($j[0]['lat'])) return ['lat' => (float)$j[0]['lat'], 'lng' => (float)$j[0]['lon']];
    }
    return null;
}

/** Geocode a batch: cache-first (instant), then live lookups at ~1/sec. Commercial IDX first. */
function run_geocode(int $limit = 60): array {
    if (meta_get('geo_lock') && time() - (int)meta_get('geo_lock') < 240) return ['skipped' => 'lock'];
    meta_set('geo_lock', (string)time());
    set_time_limit(0);
    ignore_user_abort(true);
    $upd = db()->prepare("UPDATE listings SET lat = ?, lng = ? WHERE listing_key = ?");
    $cget = db()->prepare("SELECT lat, lng FROM geocache WHERE addr_hash = ?");
    $cput = db()->prepare("REPLACE INTO geocache (addr_hash, lat, lng) VALUES (?, ?, ?)");
    $done = 0; $cachehits = 0; $live = 0; $first_err = '';
    // PASS 1 — full cache sweep with hashes computed in PHP (MySQL 9 removed MD5()).
    // Load the whole cache into memory (45k x 32 bytes — trivial), then stream the
    // missing listings through it in chunks and batch the updates.
    $cache = [];
    foreach (db()->query("SELECT addr_hash, lat, lng FROM geocache") as $g) {
        $cache[$g['addr_hash']] = [$g['lat'], $g['lng']];
    }
    if ($cache) {
        $lastkey = '';
        while (true) {
            $st = db()->prepare("SELECT listing_key, address, city, province, postal FROM listings
                WHERE lat IS NULL AND address <> '' AND listing_key > ?
                ORDER BY listing_key LIMIT 10000");
            $st->execute([$lastkey]);
            $chunk = $st->fetchAll();
            if (!$chunk) break;
            db()->beginTransaction();
            foreach ($chunk as $r) {
                $lastkey = $r['listing_key'];
                $q = implode(', ', array_filter([$r['address'], $r['city'], $r['province'] ?: 'Ontario', $r['postal'], 'Canada']));
                $h = md5(strtolower($q));
                if (isset($cache[$h])) {
                    $upd->execute([$cache[$h][0], $cache[$h][1], $r['listing_key']]);
                    $done++; $cachehits++;
                }
            }
            db()->commit();
            if (count($chunk) < 10000) break;
        }
    }
    // PASS 2 — live lookups: commercial & business first, rate-limited
    $rows = db()->query("SELECT listing_key, address, city, province, postal FROM listings
        WHERE feed = 'idx' AND status = 'Active' AND lat IS NULL AND disp_addr = 'Y' AND address <> ''
        ORDER BY (CASE WHEN property_type LIKE '%Commercial%' OR business_type <> '' THEN 0 ELSE 1 END), modified DESC
        LIMIT " . max(1, min(60, $limit)))->fetchAll();
    foreach ($rows as $r) {
        if ($live >= 45) break;
        // Cache key: WP-compatible construction. Lookup query: de-duplicated (feed addresses
        // already contain city/province/postal — appending them again breaks the geocoders).
        $q = implode(', ', array_filter([$r['address'], $r['city'], $r['province'] ?: 'Ontario', $r['postal'], 'Canada']));
        $h = md5(strtolower($q));
        // Feed addresses ending in ", ON <postal>" are already complete; TRREB district
        // cities ("Toronto W06") never match the address string, so test for the province.
        $lookup = preg_match('/,\s*ON\b/i', $r['address'])
            ? $r['address'] . ', Canada'
            : $q;
        $cget->execute([$h]);
        if ($cget->fetch()) continue; // pass 1 may have raced it
        try {
            $c = geo_lookup($lookup);
        } catch (Throwable $e) {
            $c = null;
            if (!$first_err) $first_err = $e->getMessage();
        }
        $live++;
        if ($c) {
            $upd->execute([$c['lat'], $c['lng'], $r['listing_key']]);
            $cput->execute([$h, $c['lat'], $c['lng']]);
            $done++;
        } elseif (!$first_err) {
            $first_err = 'no result for: ' . mb_substr($lookup, 0, 80);
        }
        usleep(1100000);
    }
    $missing = (int)db()->query("SELECT COUNT(*) FROM listings WHERE feed = 'idx' AND status = 'Active' AND lat IS NULL AND disp_addr = 'Y' AND address <> ''")->fetchColumn();
    meta_set('geo_lock', '0');
    meta_set('geo_last_result', "$done geocoded ($cachehits cache, $live live), $missing missing" . ($first_err ? ", first issue: $first_err" : '') . ', ' . gmdate('Y-m-d H:i:s'));
    return ['geocoded' => $done, 'from_cache' => $cachehits, 'live_attempts' => $live, 'missing' => $missing, 'first_issue' => $first_err ?: null];
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
        default => 'modified DESC', // uses feed_status_modified index
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
    $v = (int)db()->query("SELECT COUNT(*) FROM listings WHERE feed = 'vow'")->fetchColumn();
    $vs = (int)db()->query("SELECT COUNT(*) FROM listings WHERE feed = 'vow' AND status = 'Sold'")->fetchColumn();
    jout(['ok' => true, 'engine' => VERSION, 'active_listings' => $n, 'vow_rows' => $v, 'vow_sold' => $vs, 'total_rows' => $t,
        'last_sync' => meta_get('last_sync'), 'last_result' => meta_get('last_sync_result'),
        'vow_last' => meta_get('vow_last'), 'vow_result' => meta_get('vow_last_result'),
        'geocache' => (int)db()->query('SELECT COUNT(*) FROM geocache')->fetchColumn(),
        'geo_result' => meta_get('geo_last_result'),
        'last_error' => meta_get('last_error') ?: null]);
}

if ($path === '/v1/geocode') {
    if (($_GET['key'] ?? '') !== env('SYNC_KEY', '')) jout(['error' => 'invalid sync key'], 401);
    try { jout(run_geocode(max(1, min(200, (int)($_GET['limit'] ?? 60))))); }
    catch (Throwable $e) { meta_set('geo_lock', '0'); jout(['error' => $e->getMessage(), 'line' => $e->getLine()], 500); }
}

if ($path === '/v1/rephoto') {
    if (($_GET['key'] ?? '') !== env('SYNC_KEY', '')) jout(['error' => 'invalid sync key'], 401);
    $token = env('IDX_TOKEN');
    $vow_token = env('VOW_TOKEN');
    if (!$token && !$vow_token) jout(['error' => 'no tokens available'], 500);
    if (meta_get('rephoto_lock') && time() - (int)meta_get('rephoto_lock') < 240) jout(['skipped' => 'lock']);
    meta_set('rephoto_lock', (string)time());
    set_time_limit(0);
    ignore_user_abort(true);
    $feed = in_array($_GET['feed'] ?? '', ['idx', 'vow'], true) ? $_GET['feed'] : 'idx';
    $tok  = ($feed === 'vow') ? ($vow_token ?: $token) : ($token ?: $vow_token);
    $batch = max(20, min(200, (int)($_GET['batch'] ?? 100)));
    // Cursor: highest listing_key already rephoto'd for this feed
    $cursor_key = 'rephoto_' . $feed . '_cursor';
    $cur = meta_get($cursor_key, '');
    $st = db()->prepare("SELECT listing_key FROM listings
        WHERE feed = ? AND (photos IS NULL OR photos = '' OR photos = '[]' OR JSON_LENGTH(photos) <= 3) AND listing_key > ?
        ORDER BY listing_key LIMIT ?");
    $st->bindValue(1, $feed);
    $st->bindValue(2, $cur);
    $st->bindValue(3, $batch, PDO::PARAM_INT);
    $st->execute();
    $keys = array_column($st->fetchAll(), 'listing_key');
    $processed = 0;
    if ($keys) {
        foreach (array_chunk($keys, 20) as $chunk) {
            sync_photos($chunk, $tok, $feed === 'vow' ? 3 : 30);
            $processed += count($chunk);
        }
        meta_set($cursor_key, end($keys));
    } else {
        meta_set($cursor_key, ''); // wrap around
    }
    $remaining = (int)db()->query("SELECT COUNT(*) FROM listings WHERE feed = '$feed' AND (photos IS NULL OR photos = '' OR photos = '[]' OR JSON_LENGTH(photos) <= 3)")->fetchColumn();
    meta_set('rephoto_lock', '0');
    meta_set('rephoto_last_result', "feed=$feed processed=$processed remaining=$remaining " . gmdate('Y-m-d H:i:s'));
    jout(['processed' => $processed, 'more' => count($keys) === $batch, 'remaining' => $remaining, 'cursor' => end($keys) ?: null, 'feed' => $feed]);
}

if ($path === '/v1/optimize') {
    if (($_GET['key'] ?? '') !== env('SYNC_KEY', '')) jout(['error' => 'invalid sync key'], 401);
    set_time_limit(0);
    try {
        db()->exec("ANALYZE TABLE listings");
        db()->exec("ANALYZE TABLE geocache");
        jout(['ok' => true, 'analyzed' => ['listings', 'geocache']]);
    } catch (Throwable $e) { jout(['error' => $e->getMessage()], 500); }
}

if ($path === '/v1/geocache/import') {
    if (($_GET['key'] ?? '') !== env('SYNC_KEY', '')) jout(['error' => 'invalid sync key'], 401);
    // Body: CSV lines "addr_hash,lat,lng" (export of the WordPress geocache table)
    $body = file_get_contents('php://input');
    if (!$body) jout(['error' => 'empty body — POST CSV lines: addr_hash,lat,lng'], 400);
    $cput = db()->prepare("REPLACE INTO geocache (addr_hash, lat, lng) VALUES (?, ?, ?)");
    $n = 0;
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $p = str_getcsv(trim($line));
        if (count($p) < 3 || strlen($p[0]) !== 32 || !is_numeric($p[1]) || !is_numeric($p[2])) continue;
        $cput->execute([$p[0], (float)$p[1], (float)$p[2]]);
        $n++;
    }
    jout(['imported' => $n]);
}

if ($path === '/v1/vow/sync') {
    if (($_GET['key'] ?? '') !== env('SYNC_KEY', '')) jout(['error' => 'invalid sync key'], 401);
    try { jout(run_vow_sync(min(50, max(1, (int)($_GET['pages'] ?? 15))))); }
    catch (Throwable $e) {
        meta_set('vow_lock', '0');
        meta_set('last_error', get_class($e) . ': ' . $e->getMessage() . ' @ line ' . $e->getLine());
        jout(['error' => $e->getMessage(), 'line' => $e->getLine()], 500);
    }
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

if ($path === '/v1/vow/listings') {
    $where = ["feed = 'vow'"]; $args = [];
    if (!empty($_GET['status'])) { $where[] = 'status = ?'; $args[] = $_GET['status']; }
    $cities = array_filter(is_array($_GET['city'] ?? null) ? $_GET['city'] : (isset($_GET['city']) ? [$_GET['city']] : []));
    if ($cities) { $where[] = 'city IN (' . implode(',', array_fill(0, count($cities), '?')) . ')'; $args = array_merge($args, $cities); }
    if (!empty($_GET['q'])) { $where[] = '(address LIKE ? OR remarks LIKE ?)'; $l = '%' . $_GET['q'] . '%'; array_push($args, $l, $l); }
    if (!empty($_GET['type'])) { $where[] = 'property_type LIKE ?'; $args[] = '%' . $_GET['type'] . '%'; }
    if (!empty($_GET['pmin'])) { $where[] = 'COALESCE(close_price, list_price) >= ?'; $args[] = (float)$_GET['pmin']; }
    if (!empty($_GET['pmax'])) { $where[] = 'COALESCE(close_price, list_price) <= ?'; $args[] = (float)$_GET['pmax']; }
    $order = match ($_GET['sort'] ?? '') {
        'price_asc' => 'COALESCE(close_price, list_price) ASC',
        'price_desc' => 'COALESCE(close_price, list_price) DESC',
        default => 'sort_date DESC',
    };
    $pp = min(50, max(1, (int)($_GET['pp'] ?? 12)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $wsql = implode(' AND ', $where);
    $st = db()->prepare("SELECT COUNT(*) FROM listings WHERE $wsql");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT listing_key, list_price, price_unit, close_price, close_date, status, address, city, province,
        property_type, property_subtype, transaction_type, mls_number, sqft, list_date, photos, disp_addr
        FROM listings WHERE $wsql ORDER BY $order LIMIT " . (($page - 1) * $pp) . ", $pp");
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['photos'] = json_decode($r['photos'] ?? '[]', true) ?: [];
        if ($r['disp_addr'] !== 'Y') $r['address'] = '';
        unset($r['disp_addr']);
        $rows[] = $r;
    }
    jout(['total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $pp), 'listings' => $rows]);
}
if (preg_match('#^/v1/vow/listing/([A-Za-z0-9]+)$#', $path, $mv)) {
    $st = db()->prepare("SELECT * FROM listings WHERE listing_key = ? AND feed = 'vow'");
    $st->execute([$mv[1]]);
    $r = $st->fetch();
    if (!$r) jout(['error' => 'not found'], 404);
    $r['photos'] = json_decode($r['photos'] ?? '[]', true) ?: [];
    $r['fields'] = unpack_raw($r['raw']);
    unset($r['raw']);
    if ($r['disp_addr'] !== 'Y') $r['address'] = '';
    jout($r);
}

if ($path === '/v1/listings') jout(q_listings());
if ($path === '/v1/landing') {
    $out = [];
    // Fresh IDX (few, small, uses first_seen_idx)
    $st = db()->prepare("SELECT listing_key, list_price, price_unit, address, city, province, property_type, property_subtype,
        transaction_type, business_type, mls_number, list_date, photos, disp_addr, lat, lng
        FROM listings WHERE feed = 'idx' AND status = 'Active' AND perm_adv = 'Y' ORDER BY first_seen DESC LIMIT 8");
    $st->execute();
    $fresh = [];
    foreach ($st->fetchAll() as $r) {
        $r['photos'] = json_decode($r['photos'] ?? '[]', true) ?: [];
        if ($r['disp_addr'] !== 'Y') $r['address'] = '';
        unset($r['disp_addr']);
        $fresh[] = $r;
    }
    $out['fresh'] = $fresh;
    // Recent VOW sales (small, indexed)
    $st = db()->prepare("SELECT listing_key, close_price, list_price, price_unit, close_date, status, address, city, province,
        property_type, property_subtype, transaction_type, mls_number, photos, disp_addr
        FROM listings WHERE feed = 'vow' AND status = 'Sold' AND close_date IS NOT NULL
        ORDER BY close_date DESC LIMIT 8");
    $st->execute();
    $recent_sales = [];
    foreach ($st->fetchAll() as $r) {
        $r['photos'] = json_decode($r['photos'] ?? '[]', true) ?: [];
        if ($r['disp_addr'] !== 'Y') $r['address'] = '';
        unset($r['disp_addr']);
        $recent_sales[] = $r;
    }
    $out['recent_sales'] = $recent_sales;
    // Counts for pills
    $out['counts'] = [
        'business_sale' => (int)db()->query("SELECT COUNT(*) FROM listings WHERE feed = 'idx' AND status = 'Active' AND perm_adv = 'Y' AND (business_type <> '' OR property_subtype LIKE '%Business%')")->fetchColumn(),
        'commercial_lease' => (int)db()->query("SELECT COUNT(*) FROM listings WHERE feed = 'idx' AND status = 'Active' AND perm_adv = 'Y' AND transaction_type LIKE '%Lease%' AND property_type LIKE '%Commercial%'")->fetchColumn(),
    ];
    jout($out);
}

if (preg_match('#^/v1/similar/([A-Za-z0-9]+)$#', $path, $ms)) {
    $key = $ms[1];
    // Load source listing's key attributes
    $src = db()->prepare("SELECT feed, property_type, business_type, city, list_price, list_price+0 as p FROM listings WHERE listing_key = ?");
    $src->execute([$key]);
    $sr = $src->fetch();
    if (!$sr) jout(['listings' => []]);
    $where = ['listing_key <> ?', 'photos <> "[]" AND photos IS NOT NULL'];
    $args = [$key];
    // Match feed + same broad category
    $where[] = 'feed = ?'; $args[] = $sr['feed'];
    if ($sr['business_type']) {
        // Business: match businesses in same city or nearby
        $where[] = "business_type <> ''";
    } elseif (stripos($sr['property_type'], 'Commercial') !== false) {
        $where[] = "property_type LIKE '%Commercial%'";
    } else {
        $where[] = "property_type NOT LIKE '%Commercial%'";
    }
    // Same city (highest similarity)
    if ($sr['city']) { $where[] = 'city = ?'; $args[] = $sr['city']; }
    // Only active for IDX, or same status for VOW
    if ($sr['feed'] === 'idx') $where[] = "status = 'Active'";
    // Price within ±30% when available
    if ($sr['p'] > 0) {
        $where[] = 'list_price BETWEEN ? AND ?';
        $args[] = $sr['p'] * 0.7;
        $args[] = $sr['p'] * 1.3;
    }
    $wsql = implode(' AND ', $where);
    $st = db()->prepare("SELECT listing_key, list_price, price_unit, close_price, close_date, address, city, province,
        property_type, property_subtype, business_type, sqft, photos, disp_addr
        FROM listings WHERE $wsql ORDER BY " . ($sr['feed'] === 'vow' ? 'close_date DESC' : 'modified DESC') . " LIMIT 4");
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['photos'] = json_decode($r['photos'] ?? '[]', true) ?: [];
        if ($r['disp_addr'] !== 'Y') $r['address'] = '';
        unset($r['disp_addr']);
        $rows[] = $r;
    }
    jout(['listings' => $rows]);
}

if (preg_match('#^/v1/listing/([A-Za-z0-9]+)$#', $path, $m)) jout(q_listing($m[1]));
if ($path === '/v1/cities') {
    $rows = db()->query("SELECT city, COUNT(*) n FROM listings WHERE status = 'Active' AND city <> '' GROUP BY city ORDER BY n DESC LIMIT 100")->fetchAll();
    jout(['cities' => $rows]);
}

jout(['error' => 'unknown endpoint', 'endpoints' => ['/health', '/v1/listings', '/v1/listing/{key}', '/v1/cities', 'POST /v1/sync?key=…']], 404);
