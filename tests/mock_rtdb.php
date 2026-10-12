<?php
/**
 * Mock sederhana Firebase Realtime Database REST API, KHUSUS untuk pengujian lokal.
 * Mendukung: GET/PUT/PATCH/DELETE, ETag + if-match (transaksi), query orderBy/equalTo/startAt/endAt,
 * dan memeriksa ".indexOn" dari database.rules.json seperti server Firebase asli.
 *
 * Jalankan:  php -S 127.0.0.1:9000 tests/mock_rtdb.php
 */

declare(strict_types=1);

$dbFile = __DIR__ . '/.mockdb/db.json';
@mkdir(dirname($dbFile), 0777, true);
$lock = fopen($dbFile . '.lock', 'c');
flock($lock, LOCK_EX); // satu request pada satu waktu = perilaku atomik

$data = is_file($dbFile) ? json_decode((string) file_get_contents($dbFile), true) : null;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('/\.json$/', '', $path);
$segments = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
$segments = array_map('rawurldecode', $segments);
$method = $_SERVER['REQUEST_METHOD'];

function get_at(mixed $data, array $segs): mixed
{
    foreach ($segs as $s) {
        if (!is_array($data) || !array_key_exists($s, $data)) {
            return null;
        }
        $data = $data[$s];
    }
    return $data;
}

function clean(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    $out = [];
    foreach ($v as $k => $item) {
        $item = clean($item);
        if ($item !== null && $item !== []) {
            $out[(string) $k] = $item;
        }
    }
    return $out === [] ? null : $out;
}

function set_at(mixed $data, array $segs, mixed $value): mixed
{
    if ($segs === []) {
        return clean($value);
    }
    $data = is_array($data) ? $data : [];
    $key = array_shift($segs);
    $data[$key] = set_at($data[$key] ?? null, $segs, $value);
    return clean($data);
}

function etag(mixed $v): string
{
    return md5(json_encode($v));
}

function respond(int $code, mixed $body, array $headers = []): never
{
    global $lock;
    http_response_code($code);
    header('Content-Type: application/json');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($body);
    flock($lock, LOCK_UN);
    exit;
}

$current = get_at($data, $segments);
$ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? null;
if ($ifMatch !== null && $ifMatch !== etag($current)) {
    respond(412, ['error' => 'ETag mismatch'], ['ETag: ' . etag($current)]);
}

$save = function (mixed $newData) use ($dbFile): void {
    file_put_contents($dbFile, json_encode($newData));
};

switch ($method) {
    case 'GET':
        $value = $current;
        if (isset($_GET['orderBy'])) {
            $orderBy = json_decode($_GET['orderBy'], true);
            // Seperti Firebase asli: query butuh .indexOn di rules
            $rules = json_decode((string) file_get_contents(__DIR__ . '/../database.rules.json'), true);
            $ruleNode = get_at($rules['rules'], $segments);
            if ($orderBy !== '$key' && !in_array($orderBy, $ruleNode['.indexOn'] ?? [], true)) {
                respond(400, ['error' => "Index not defined, add \".indexOn\": \"$orderBy\""]);
            }
            $value = is_array($value) ? $value : [];
            $value = array_filter($value, function ($child, $key) use ($orderBy) {
                $v = $orderBy === '$key' ? $key : (is_array($child) ? ($child[$orderBy] ?? null) : null);
                if (isset($_GET['equalTo']) && $v !== json_decode($_GET['equalTo'], true)) {
                    return false;
                }
                if (isset($_GET['startAt']) && ($v === null || $v < json_decode($_GET['startAt'], true))) {
                    return false;
                }
                if (isset($_GET['endAt']) && ($v === null || $v > json_decode($_GET['endAt'], true))) {
                    return false;
                }
                return true;
            }, ARRAY_FILTER_USE_BOTH);
            $value = $value === [] ? (object) [] : $value;
        }
        $headers = ($_SERVER['HTTP_X_FIREBASE_ETAG'] ?? '') === 'true' ? ['ETag: ' . etag($current)] : [];
        respond(200, $value, $headers);

    case 'PUT':
        $value = json_decode((string) file_get_contents('php://input'), true);
        $data = set_at($data, $segments, $value);
        $save($data);
        respond(200, $value);

    case 'PATCH':
        $updates = json_decode((string) file_get_contents('php://input'), true);
        foreach ($updates as $p => $v) {
            $data = set_at($data, array_merge($segments, array_values(array_filter(explode('/', $p)))), $v);
        }
        $save($data);
        respond(200, $updates);

    case 'DELETE':
        $data = set_at($data, $segments, null);
        $save($data);
        respond(200, null);
}
respond(405, ['error' => 'method']);
