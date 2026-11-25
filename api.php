<?php
declare(strict_types=1);

/**
 * API cho quanly.vistaanphu.com — PHP + SQLite, một tệp.
 * Dữ liệu lưu dạng tài liệu JSON: bảng docs(col, id, data).
 * Vai trò: editor (ghi) và viewer (chỉ xem), đăng nhập bằng mật khẩu trong config.php.
 */

function out(int $code, array $d): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) out(500, ['error' => 'Chưa cấu hình: thiếu config.php (xem README).']);
$cfg = require $cfgFile;

session_name('vap_sid');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();

$dbFile = (string)$cfg['db'];
$dir = dirname($dbFile);
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('CREATE TABLE IF NOT EXISTS docs (col TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL, updated INTEGER NOT NULL, PRIMARY KEY (col, id))');
$pdo->exec('CREATE TABLE IF NOT EXISTS feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, message TEXT NOT NULL, name TEXT, contact TEXT, ip TEXT, created INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE IF NOT EXISTS hits (kind TEXT NOT NULL, ip TEXT NOT NULL, ts INTEGER NOT NULL)');

$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$role = $_SESSION['role'] ?? null;
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$COLS = ['txns', 'assets', 'maints', 'settings'];

$body = [];
if ($method === 'POST') {
    // Chỉ nhận JSON: trang web ở tên miền khác không gửi được kiểu này khi chưa được phép.
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) out(415, ['error' => 'content-type']);
    $raw = file_get_contents('php://input');
    if ($raw !== '' && strlen($raw) > 200000) out(413, ['error' => 'too_large']);
    $body = json_decode($raw ?: '{}', true);
    if (!is_array($body)) out(400, ['error' => 'bad_json']);
}

function hits(PDO $pdo, string $kind, string $ip, int $window): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE kind = ? AND ip = ? AND ts > ?');
    $s->execute([$kind, $ip, time() - $window]);
    return (int)$s->fetchColumn();
}
function addHit(PDO $pdo, string $kind, string $ip): void {
    $pdo->prepare('INSERT INTO hits (kind, ip, ts) VALUES (?,?,?)')->execute([$kind, $ip, time()]);
    $pdo->prepare('DELETE FROM hits WHERE ts < ?')->execute([time() - 86400]);
}
function needRole(?string $role, bool $editor): void {
    if (!$role) out(401, ['error' => 'login']);
    if ($editor && $role !== 'editor') out(403, ['error' => 'readonly']);
}
function str(array $b, string $k, int $max): string {
    $v = $b[$k] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

switch ($a) {
    case 'me':
        out(200, ['role' => $role]);

    case 'login':
        if ($method !== 'POST') out(405, ['error' => 'method']);
        if (hits($pdo, 'login', $ip, 600) >= 8) out(429, ['error' => 'too_many']);
        $pw = (string)($body['password'] ?? '');
        $found = null;
        if ($pw !== '' && !empty($cfg['editor_hash']) && password_verify($pw, $cfg['editor_hash'])) $found = 'editor';
        elseif ($pw !== '' && !empty($cfg['viewer_hash']) && password_verify($pw, $cfg['viewer_hash'])) $found = 'viewer';
        if (!$found) { addHit($pdo, 'login', $ip); out(401, ['error' => 'bad_password']); }
        session_regenerate_id(true);
        $_SESSION['role'] = $found;
        out(200, ['role' => $found]);

    case 'logout':
        if ($method !== 'POST') out(405, ['error' => 'method']);
        $_SESSION = [];
        session_destroy();
        out(200, ['ok' => true]);

    case 'all':
        needRole($role, false);
        $res = ['txns' => [], 'assets' => [], 'maints' => [], 'settings' => new stdClass()];
        foreach ($pdo->query('SELECT col, id, data FROM docs') as $r) {
            $d = json_decode($r['data'], true);
            if (!is_array($d)) continue;
            if ($r['col'] === 'settings') { if ($r['id'] === 'main') $res['settings'] = $d; continue; }
            if (isset($res[$r['col']])) { $d['id'] = $r['id']; $res[$r['col']][] = $d; }
        }
        out(200, $res);

    case 'put':
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needRole($role, true);
        $col = (string)($body['col'] ?? ''); $id = (string)($body['id'] ?? ''); $data = $body['data'] ?? null;
        if (!in_array($col, $COLS, true) || !preg_match('/^[A-Za-z0-9_.:@+~-]{1,100}$/', $id) || !is_array($data)) out(400, ['error' => 'bad_request']);
        unset($data['id']);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 60000) out(413, ['error' => 'too_large']);
        $pdo->prepare('INSERT INTO docs (col, id, data, updated) VALUES (?,?,?,?) ON CONFLICT(col, id) DO UPDATE SET data = excluded.data, updated = excluded.updated')->execute([$col, $id, $json, time()]);
        out(200, ['ok' => true]);

    case 'del':
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needRole($role, true);
        $col = (string)($body['col'] ?? ''); $id = (string)($body['id'] ?? '');
        if (!in_array($col, $COLS, true) || !preg_match('/^[A-Za-z0-9_.:@+~-]{1,100}$/', $id)) out(400, ['error' => 'bad_request']);
        $pdo->prepare('DELETE FROM docs WHERE col = ? AND id = ?')->execute([$col, $id]);
        out(200, ['ok' => true]);

    case 'feedback':
        if ($method !== 'POST') out(405, ['error' => 'method']);
        if (!empty($body['website'])) out(200, ['ok' => true]); // ô bẫy cho bot
        if (hits($pdo, 'feedback', $ip, 3600) >= 5) out(429, ['error' => 'too_many']);
        $msg = str($body, 'message', 2000);
        if (mb_strlen($msg) < 3) out(400, ['error' => 'empty']);
        $kind = in_array($body['kind'] ?? '', ['Góp ý tính năng', 'Báo lỗi', 'Khác'], true) ? $body['kind'] : 'Khác';
        $pdo->prepare('INSERT INTO feedback (kind, message, name, contact, ip, created) VALUES (?,?,?,?,?,?)')->execute([$kind, $msg, str($body, 'name', 100), str($body, 'contact', 120), $ip, time()]);
        addHit($pdo, 'feedback', $ip);
        out(200, ['ok' => true]);

    case 'feedback_list':
        needRole($role, true);
        $rows = $pdo->query('SELECT id, kind, message, name, contact, created FROM feedback ORDER BY id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
        out(200, ['items' => $rows]);

    case 'feedback_del':
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needRole($role, true);
        $pdo->prepare('DELETE FROM feedback WHERE id = ?')->execute([(int)($body['id'] ?? 0)]);
        out(200, ['ok' => true]);

    default:
        out(404, ['error' => 'unknown']);
}
