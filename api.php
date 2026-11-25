<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Ho_Chi_Minh');

/**
 * API cho quanly.vistaanphu.com — PHP 8 + SQLite, một tệp.
 * Dữ liệu dạng tài liệu JSON trong bảng docs(col, id, data).
 * Vai trò: viewer (chỉ xem) < editor (nhập liệu, kế toán) < admin (Trưởng ban: tài khoản, duyệt chi, cài đặt, sao lưu).
 */

function out(int $code, array $d): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
$pdo->exec('CREATE TABLE IF NOT EXISTS auth (role TEXT PRIMARY KEY, hash TEXT NOT NULL)');
$pdo->exec('CREATE TABLE IF NOT EXISTS users (id TEXT PRIMARY KEY, username TEXT NOT NULL UNIQUE, name TEXT NOT NULL, role TEXT NOT NULL, hash TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, col TEXT, doc TEXT, summary TEXT, before TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS files (id TEXT PRIMARY KEY, name TEXT NOT NULL, mime TEXT NOT NULL, size INTEGER NOT NULL, ref_col TEXT NOT NULL, ref_id TEXT NOT NULL, ts INTEGER NOT NULL, actor TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');

$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$role = $_SESSION['role'] ?? null;
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$RANK = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
$COLS = ['txns', 'assets', 'maints', 'settings'];
$ID_RE = '/^[A-Za-z0-9_.:@+~-]{1,100}$/';

/* ---------- tiện ích ---------- */
function need(?string $role, int $min): void {
    global $RANK;
    if (!$role) out(401, ['error' => 'login']);
    if ($RANK[$role] < $min) out(403, ['error' => 'forbidden']);
}
function actor(): array {
    return ['id' => $_SESSION['uid'] ?? 'legacy', 'name' => $_SESSION['name'] ?? '(mật khẩu chung)'];
}
function str(array $b, string $k, int $max): string {
    $v = $b[$k] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}
function metaGet(PDO $pdo, string $k): ?string {
    $s = $pdo->prepare('SELECT v FROM meta WHERE k = ?'); $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string)$v;
}
function metaSet(PDO $pdo, string $k, string $v): void {
    $pdo->prepare('INSERT INTO meta (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}
function hits(PDO $pdo, string $kind, string $key, int $window): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE kind = ? AND ip = ? AND ts > ?');
    $s->execute([$kind, $key, time() - $window]);
    return (int)$s->fetchColumn();
}
function addHit(PDO $pdo, string $kind, string $key): void {
    $pdo->prepare('INSERT INTO hits (kind, ip, ts) VALUES (?,?,?)')->execute([$kind, $key, time()]);
    $pdo->prepare('DELETE FROM hits WHERE ts < ?')->execute([time() - 86400]);
}
function getDoc(PDO $pdo, string $col, string $id): ?array {
    $s = $pdo->prepare('SELECT data FROM docs WHERE col = ? AND id = ?'); $s->execute([$col, $id]);
    $r = $s->fetchColumn();
    if ($r === false) return null;
    $d = json_decode((string)$r, true);
    return is_array($d) ? $d : null;
}
function setDoc(PDO $pdo, string $col, string $id, array $d): void {
    $pdo->prepare('INSERT INTO docs (col, id, data, updated) VALUES (?,?,?,?) ON CONFLICT(col, id) DO UPDATE SET data = excluded.data, updated = excluded.updated')
        ->execute([$col, $id, json_encode($d, JSON_UNESCAPED_UNICODE), time()]);
}
function vnd($n): string { return number_format((float)$n, 0, ',', '.') . ' ₫'; }
function fundName(string $f): string { return $f === 'maint' ? 'Quỹ bảo trì' : 'Quỹ vận hành'; }
function summ(string $col, array $d): string {
    switch ($col) {
        case 'txns': return (($d['type'] ?? '') === 'in' ? 'Thu ' : 'Chi ') . vnd($d['amount'] ?? 0) . ' · ' . ($d['category'] ?? '') . ' (' . fundName((string)($d['fund'] ?? '')) . ')';
        case 'assets': return trim(($d['code'] ?? '') . ' ' . ($d['name'] ?? ''));
        case 'maints': return ($d['kind'] ?? 'Bảo trì') . ': ' . ($d['title'] ?? '') . ' (' . ($d['date'] ?? '') . ')';
        case 'settings': return 'Cài đặt chung';
    }
    return '';
}
function audit(PDO $pdo, string $action, string $col = '', string $doc = '', string $summary = '', ?array $before = null): void {
    $who = actor();
    $b = $before ? mb_substr(json_encode($before, JSON_UNESCAPED_UNICODE), 0, 4000) : null;
    $pdo->prepare('INSERT INTO audit (ts, actor, action, col, doc, summary, before) VALUES (?,?,?,?,?,?,?)')->execute([time(), $who['name'], $action, $col, $doc, $summary, $b]);
    $pdo->exec('DELETE FROM audit WHERE id < (SELECT MAX(id) - 20000 FROM audit)');
}
function settingsDoc(PDO $pdo): array {
    return getDoc($pdo, 'settings', 'main') ?? [];
}
function emails(array $settings): array {
    $list = preg_split('/[\s,;]+/', (string)($settings['notifyEmails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter($list, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
}
function sendMail(array $to, string $subject, string $body): void {
    if (!$to) return;
    $host = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $headers = "From: The Vista An Phu <noreply@$host>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
    @mail(implode(',', $to), mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers);
}
function addMonthsStr(string $base, int $months): string {
    $d = new DateTime($base);
    $day = (int)$d->format('j');
    $d->modify('first day of this month');
    $d->modify(($months >= 0 ? '+' : '') . $months . ' month');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
    return $d->format('Y-m-d');
}
function allDocs(PDO $pdo, string $col): array {
    $s = $pdo->prepare('SELECT id, data FROM docs WHERE col = ?'); $s->execute([$col]);
    $res = [];
    foreach ($s as $r) { $d = json_decode($r['data'], true); if (is_array($d)) { $d['id'] = $r['id']; $res[] = $d; } }
    return $res;
}
/** Danh sách mốc bảo trì, kiểm định, bảo hành đã quá hạn hoặc còn ≤ 30 ngày. */
function dueSoon(PDO $pdo): array {
    $assets = allDocs($pdo, 'assets'); $maints = allDocs($pdo, 'maints');
    $today = new DateTime('today'); $res = [];
    foreach ($assets as $as) {
        if (($as['status'] ?? '') === 'Ngưng sử dụng') continue;
        $mine = array_values(array_filter($maints, fn($m) => ($m['assetId'] ?? '') === $as['id']));
        $lastOf = function (bool $inspect) use ($mine, $as) {
            $dates = array_map(fn($m) => (string)$m['date'], array_filter($mine, fn($m) => ((($m['kind'] ?? 'Bảo trì') === 'Kiểm định') === $inspect)));
            rsort($dates);
            return $dates[0] ?? ($as['buyDate'] ?? '');
        };
        $items = [];
        if ((int)($as['cycle'] ?? 0) > 0 && $lastOf(false)) $items[] = ['Bảo trì', addMonthsStr($lastOf(false), (int)$as['cycle'])];
        if ((int)($as['inspectCycle'] ?? 0) > 0 && $lastOf(true)) $items[] = ['Kiểm định', addMonthsStr($lastOf(true), (int)$as['inspectCycle'])];
        if (!empty($as['warrantyEnd'])) $items[] = ['Hết bảo hành', (string)$as['warrantyEnd']];
        foreach ($items as [$kind, $due]) {
            $days = (int)$today->diff(new DateTime($due))->format('%r%a');
            if ($days <= 30) $res[] = ['text' => $kind . ' · ' . ($as['name'] ?? '') . ' · hạn ' . (new DateTime($due))->format('d/m/Y') . ($days < 0 ? ' (quá hạn ' . (-$days) . ' ngày)' : ($days === 0 ? ' (hôm nay)' : " (còn $days ngày)")), 'days' => $days];
        }
    }
    usort($res, fn($x, $y) => $x['days'] <=> $y['days']);
    return $res;
}
/** Gửi email nhắc mỗi ngày một lần, khi có người của Ban quản trị mở trang. */
function maybeRemind(PDO $pdo): void {
    $todayKey = date('Y-m-d');
    if (metaGet($pdo, 'last_remind') === $todayKey) return;
    $to = emails(settingsDoc($pdo));
    if (!$to) return;
    metaSet($pdo, 'last_remind', $todayKey);
    $due = dueSoon($pdo);
    $pend = array_filter(allDocs($pdo, 'txns'), fn($t) => ($t['status'] ?? '') === 'pending' && empty($t['voided']));
    if (!$due && !$pend) return;
    $lines = array_map(fn($d) => '- ' . $d['text'], $due);
    $body = "Nhắc việc ngày " . date('d/m/Y') . " cho The Vista An Phú:\r\n\r\n";
    if ($lines) $body .= "Mốc sắp đến hạn hoặc quá hạn:\r\n" . implode("\r\n", $lines) . "\r\n\r\n";
    if ($pend) $body .= count($pend) . " khoản chi đang chờ Trưởng ban duyệt.\r\n\r\n";
    $body .= "Mở trang quản lý để xử lý.";
    sendMail($to, '[The Vista An Phú] Nhắc việc ' . date('d/m/Y'), $body);
}

/* ---------- đọc yêu cầu ---------- */
$body = [];
if ($method === 'POST') {
    if ($a === 'upload') {
        if (empty($_SERVER['HTTP_X_UPLOAD'])) out(415, ['error' => 'header']);
    } else {
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) out(415, ['error' => 'content-type']);
        $raw = file_get_contents('php://input');
        $limit = $a === 'import' ? 8000000 : 200000;
        if (strlen((string)$raw) > $limit) out(413, ['error' => 'too_large']);
        $body = json_decode($raw ?: '{}', true);
        if (!is_array($body)) out(400, ['error' => 'bad_json']);
    }
}

/* ---------- xử lý ---------- */
switch ($a) {

    case 'me': {
        $hasUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn() > 0;
        out(200, ['role' => $role, 'uid' => $_SESSION['uid'] ?? null, 'name' => $_SESSION['name'] ?? null, 'legacy' => $role && !isset($_SESSION['uid']), 'hasUsers' => $hasUsers]);
    }

    case 'login': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        $uname = strtolower(str($body, 'username', 40));
        $pw = (string)($body['password'] ?? '');
        $key = $uname !== '' ? 'u:' . $uname : $ip;
        if (hits($pdo, 'login', $ip, 600) >= 8 || ($uname !== '' && hits($pdo, 'login', $key, 600) >= 8)) out(429, ['error' => 'too_many']);
        $session = null;
        if ($uname !== '') {
            $s = $pdo->prepare('SELECT * FROM users WHERE username = ? AND active = 1'); $s->execute([$uname]);
            $u = $s->fetch(PDO::FETCH_ASSOC);
            if ($u && $pw !== '' && password_verify($pw, $u['hash'])) $session = ['role' => $u['role'], 'uid' => $u['id'], 'name' => $u['name']];
        } else {
            $hashes = ['editor' => (string)($cfg['editor_hash'] ?? ''), 'viewer' => (string)($cfg['viewer_hash'] ?? '')];
            foreach ($pdo->query('SELECT role, hash FROM auth') as $r) $hashes[$r['role']] = $r['hash'];
            $legacyOff = metaGet($pdo, 'legacy_off') === '1';
            if ($pw !== '' && !$legacyOff && $hashes['editor'] !== '' && password_verify($pw, $hashes['editor'])) $session = ['role' => 'admin'];
            elseif ($pw !== '' && $hashes['viewer'] !== '' && password_verify($pw, $hashes['viewer'])) $session = ['role' => 'viewer'];
        }
        if (!$session) { addHit($pdo, 'login', $ip); if ($uname !== '') addHit($pdo, 'login', $key); out(401, ['error' => 'bad_password']); }
        session_regenerate_id(true);
        $_SESSION = [];
        foreach ($session as $k => $v) $_SESSION[$k] = $v;
        $hasUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn() > 0;
        out(200, ['role' => $session['role'], 'uid' => $session['uid'] ?? null, 'name' => $session['name'] ?? null, 'legacy' => !isset($session['uid']), 'hasUsers' => $hasUsers]);
    }

    case 'logout': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        $_SESSION = [];
        session_destroy();
        out(200, ['ok' => true]);
    }

    case 'all': {
        need($role, 1);
        $res = ['txns' => [], 'assets' => [], 'maints' => [], 'settings' => new stdClass(), 'files' => [], 'meta' => new stdClass()];
        foreach ($pdo->query('SELECT col, id, data FROM docs') as $r) {
            $d = json_decode($r['data'], true);
            if (!is_array($d)) continue;
            if ($r['col'] === 'settings') { if ($r['id'] === 'main') $res['settings'] = $d; continue; }
            if (isset($res[$r['col']])) { $d['id'] = $r['id']; $res[$r['col']][] = $d; }
        }
        $res['files'] = $pdo->query('SELECT id, name, mime, size, ref_col AS col, ref_id AS ref, ts FROM files ORDER BY ts')->fetchAll(PDO::FETCH_ASSOC);
        $res['meta'] = ['lastExport' => metaGet($pdo, 'last_export'), 'legacyOff' => metaGet($pdo, 'legacy_off') === '1'];
        if ($RANK[$role] >= 2) { try { maybeRemind($pdo); } catch (Throwable $e) {} }
        out(200, $res);
    }

    case 'put': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 2);
        $col = (string)($body['col'] ?? ''); $id = (string)($body['id'] ?? ''); $data = $body['data'] ?? null;
        if (!in_array($col, $COLS, true) || !preg_match($ID_RE, $id) || !is_array($data)) out(400, ['error' => 'bad_request']);
        unset($data['id']);
        $old = getDoc($pdo, $col, $id);
        if ($col === 'settings') {
            need($role, 3);
            if ($id !== 'main') out(400, ['error' => 'bad_request']);
            $data = [
                'name' => mb_substr((string)($data['name'] ?? ''), 0, 120),
                'openMaint' => (float)($data['openMaint'] ?? 0),
                'openOps' => (float)($data['openOps'] ?? 0),
                'approvalLimit' => max(0, (float)($data['approvalLimit'] ?? 0)),
                'notifyEmails' => mb_substr((string)($data['notifyEmails'] ?? ''), 0, 400),
            ];
        } elseif ($col === 'txns') {
            $who = actor();
            if ($old && !empty($old['voided'])) out(409, ['error' => 'voided']);
            $type = (string)($data['type'] ?? ''); $fund = (string)($data['fund'] ?? ''); $amount = (float)($data['amount'] ?? 0);
            if (!in_array($type, ['in', 'out'], true) || !in_array($fund, ['maint', 'ops'], true) || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($data['date'] ?? ''))) out(400, ['error' => 'bad_request']);
            foreach (['status', 'by', 'byName', 'at', 'approvedBy', 'approvedByName', 'approvedAt', 'voided', 'voidedBy', 'voidedByName', 'voidedAt', 'voidReason'] as $k) unset($data[$k]);
            $data['by'] = $old['by'] ?? $who['id'];
            $data['byName'] = $old['byName'] ?? $who['name'];
            $data['at'] = $old['at'] ?? time();
            $limit = (float)(settingsDoc($pdo)['approvalLimit'] ?? 0);
            $needs = $type === 'out' && $limit > 0 && $amount >= $limit;
            $same = $old && ($old['type'] ?? '') === $type && ($old['fund'] ?? '') === $fund && (float)($old['amount'] ?? 0) === $amount;
            if ($needs && !($same && ($old['status'] ?? '') === 'approved')) {
                $data['status'] = 'pending';
            } else {
                $data['status'] = 'approved';
                if ($same && !empty($old['approvedBy'])) { $data['approvedBy'] = $old['approvedBy']; $data['approvedByName'] = $old['approvedByName'] ?? ''; $data['approvedAt'] = $old['approvedAt'] ?? 0; }
            }
        } elseif ($col === 'maints') {
            if (trim((string)($data['assetId'] ?? '')) === '') out(400, ['error' => 'bad_request']);
            if (!in_array($data['kind'] ?? 'Bảo trì', ['Bảo trì', 'Sửa chữa', 'Kiểm định'], true)) $data['kind'] = 'Bảo trì';
        } elseif ($col === 'assets') {
            if (trim((string)($data['name'] ?? '')) === '') out(400, ['error' => 'bad_request']);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 60000) out(413, ['error' => 'too_large']);
        setDoc($pdo, $col, $id, $data);
        audit($pdo, $old ? 'sửa' : 'tạo', $col, $id, summ($col, $data), $old);
        $data['id'] = $id;
        out(200, ['ok' => true, 'doc' => $data]);
    }

    case 'del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        $col = (string)($body['col'] ?? ''); $id = (string)($body['id'] ?? '');
        if (!in_array($col, $COLS, true) || !preg_match($ID_RE, $id)) out(400, ['error' => 'bad_request']);
        need($role, in_array($col, ['assets', 'maints'], true) ? 2 : 3);
        $old = getDoc($pdo, $col, $id);
        $pdo->prepare('DELETE FROM docs WHERE col = ? AND id = ?')->execute([$col, $id]);
        if ($old) audit($pdo, 'xóa', $col, $id, summ($col, $old), $old);
        out(200, ['ok' => true]);
    }

    case 'void': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 2);
        $id = (string)($body['id'] ?? '');
        $d = preg_match($ID_RE, $id) ? getDoc($pdo, 'txns', $id) : null;
        if (!$d) out(404, ['error' => 'not_found']);
        if (!empty($d['voided'])) out(409, ['error' => 'voided']);
        $reason = str($body, 'reason', 300);
        if ($reason === '') out(400, ['error' => 'reason']);
        $who = actor();
        $d['voided'] = true; $d['voidedBy'] = $who['id']; $d['voidedByName'] = $who['name']; $d['voidedAt'] = time(); $d['voidReason'] = $reason;
        setDoc($pdo, 'txns', $id, $d);
        audit($pdo, 'hủy khoản', 'txns', $id, summ('txns', $d) . ' · lý do: ' . $reason);
        $d['id'] = $id;
        out(200, ['ok' => true, 'doc' => $d]);
    }

    case 'approve': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 3);
        $id = (string)($body['id'] ?? '');
        $d = preg_match($ID_RE, $id) ? getDoc($pdo, 'txns', $id) : null;
        if (!$d || !empty($d['voided']) || ($d['status'] ?? '') !== 'pending') out(404, ['error' => 'not_pending']);
        $who = actor();
        if ($who['id'] !== 'legacy' && ($d['by'] ?? '') === $who['id']) out(403, ['error' => 'self_approve']);
        $d['status'] = 'approved'; $d['approvedBy'] = $who['id']; $d['approvedByName'] = $who['name']; $d['approvedAt'] = time();
        setDoc($pdo, 'txns', $id, $d);
        audit($pdo, 'duyệt chi', 'txns', $id, summ('txns', $d));
        $d['id'] = $id;
        out(200, ['ok' => true, 'doc' => $d]);
    }

    case 'passwd': {   // mật khẩu chung (không dùng tài khoản riêng)
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 3);
        $rl = (string)($body['role'] ?? ''); $np = (string)($body['password'] ?? '');
        if (!in_array($rl, ['editor', 'viewer'], true) || mb_strlen($np) < 8 || mb_strlen($np) > 100) out(400, ['error' => 'bad_password_format']);
        $pdo->prepare('INSERT INTO auth (role, hash) VALUES (?,?) ON CONFLICT(role) DO UPDATE SET hash = excluded.hash')->execute([$rl, password_hash($np, PASSWORD_DEFAULT)]);
        audit($pdo, 'đổi mật khẩu chung', '', '', $rl === 'viewer' ? 'Mật khẩu cư dân' : 'Mật khẩu Ban quản trị (dùng chung)');
        out(200, ['ok' => true]);
    }

    case 'mypasswd': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 2);
        $uid = $_SESSION['uid'] ?? null;
        if (!$uid) out(400, ['error' => 'no_account']);
        $s = $pdo->prepare('SELECT hash FROM users WHERE id = ?'); $s->execute([$uid]);
        $h = $s->fetchColumn();
        $np = (string)($body['new'] ?? '');
        if (!$h || !password_verify((string)($body['current'] ?? ''), (string)$h)) out(401, ['error' => 'bad_password']);
        if (mb_strlen($np) < 8 || mb_strlen($np) > 100) out(400, ['error' => 'bad_password_format']);
        $pdo->prepare('UPDATE users SET hash = ? WHERE id = ?')->execute([password_hash($np, PASSWORD_DEFAULT), $uid]);
        audit($pdo, 'đổi mật khẩu cá nhân');
        out(200, ['ok' => true]);
    }

    case 'users_list': {
        need($role, 3);
        out(200, ['items' => $pdo->query('SELECT id, username, name, role, active, created FROM users ORDER BY created')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'user_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 3);
        $id = (string)($body['id'] ?? '');
        $name = str($body, 'name', 80);
        $rl = (string)($body['role'] ?? 'editor');
        $active = !empty($body['active']) ? 1 : 0;
        $pw = (string)($body['password'] ?? '');
        if ($name === '' || !in_array($rl, ['admin', 'editor'], true)) out(400, ['error' => 'bad_request']);
        if ($pw !== '' && (mb_strlen($pw) < 8 || mb_strlen($pw) > 100)) out(400, ['error' => 'bad_password_format']);
        if ($id === '') {
            $un = strtolower(str($body, 'username', 30));
            if (!preg_match('/^[a-z0-9._-]{3,30}$/', $un) || $pw === '') out(400, ['error' => 'bad_request']);
            $ex = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?'); $ex->execute([$un]);
            if ((int)$ex->fetchColumn() > 0) out(409, ['error' => 'username_taken']);
            $nid = 'u' . bin2hex(random_bytes(6));
            $pdo->prepare('INSERT INTO users (id, username, name, role, hash, active, created) VALUES (?,?,?,?,?,?,?)')->execute([$nid, $un, $name, $rl, password_hash($pw, PASSWORD_DEFAULT), 1, time()]);
            audit($pdo, 'tạo tài khoản', 'users', $nid, "$un ($rl)");
        } else {
            $s = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([$id]);
            $u = $s->fetch(PDO::FETCH_ASSOC);
            if (!$u) out(404, ['error' => 'not_found']);
            $pdo->prepare('UPDATE users SET name = ?, role = ?, active = ? WHERE id = ?')->execute([$name, $rl, $active, $id]);
            if ($pw !== '') $pdo->prepare('UPDATE users SET hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
            $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'admin'")->fetchColumn();
            if (metaGet($pdo, 'legacy_off') === '1' && $admins < 1) {   // tránh tự khóa mình ra ngoài
                $pdo->prepare('UPDATE users SET name = ?, role = ?, active = ? WHERE id = ?')->execute([$u['name'], $u['role'], $u['active'], $id]);
                out(409, ['error' => 'last_admin']);
            }
            audit($pdo, 'sửa tài khoản', 'users', $id, $u['username'] . ($pw !== '' ? ' (đặt lại mật khẩu)' : '') . ($active ? '' : ' (đã khóa)'));
        }
        out(200, ['ok' => true]);
    }

    case 'legacy_off': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 3);
        $off = !empty($body['off']);
        if ($off && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'admin'")->fetchColumn() < 1) out(409, ['error' => 'no_admin']);
        metaSet($pdo, 'legacy_off', $off ? '1' : '0');
        audit($pdo, $off ? 'tắt mật khẩu chung Ban quản trị' : 'bật mật khẩu chung Ban quản trị');
        out(200, ['ok' => true]);
    }

    case 'audit_list': {
        need($role, 3);
        out(200, ['items' => $pdo->query('SELECT id, ts, actor, action, col, doc, summary FROM audit ORDER BY id DESC LIMIT 400')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'export': {
        need($role, 3);
        $docs = [];
        foreach ($pdo->query('SELECT col, id, data FROM docs') as $r) $docs[] = ['col' => $r['col'], 'id' => $r['id'], 'data' => json_decode($r['data'], true)];
        $payload = [
            'app' => 'quanly-vistaanphu', 'version' => 2, 'exported' => date('c'),
            'docs' => $docs,
            'files' => $pdo->query('SELECT id, name, mime, size, ref_col, ref_id, ts FROM files')->fetchAll(PDO::FETCH_ASSOC),
            'feedback' => $pdo->query('SELECT kind, message, name, contact, created FROM feedback')->fetchAll(PDO::FETCH_ASSOC),
            'users' => $pdo->query('SELECT username, name, role, active FROM users')->fetchAll(PDO::FETCH_ASSOC),
        ];
        metaSet($pdo, 'last_export', (string)time());
        audit($pdo, 'tải bản sao lưu');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="sao-luu-quanly-' . date('Y-m-d') . '.json"');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    case 'export_files': {
        need($role, 3);
        if (!class_exists('ZipArchive')) out(501, ['error' => 'no_zip']);
        $tmp = tempnam(sys_get_temp_dir(), 'vap');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $fdir = $dir . '/files';
        foreach ($pdo->query('SELECT id, name, ref_col, ref_id FROM files') as $r) {
            $p = $fdir . '/' . $r['id'];
            if (is_file($p)) $zip->addFile($p, $r['ref_col'] . '-' . $r['ref_id'] . '/' . preg_replace('/[\\\\\/:*?"<>|]/', '_', $r['name']));
        }
        if ($zip->numFiles === 0) $zip->addFromString('README.txt', 'Chưa có tệp đính kèm.');
        $zip->close();
        audit($pdo, 'tải tệp đính kèm (zip)');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="chung-tu-' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp); @unlink($tmp);
        exit;
    }

    case 'import': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 3);
        $docs = $body['docs'] ?? null;
        if (!is_array($docs) || count($docs) > 20000 || ($body['app'] ?? '') !== 'quanly-vistaanphu') out(400, ['error' => 'bad_file']);
        $clean = [];
        foreach ($docs as $d) {
            if (!is_array($d) || !in_array($d['col'] ?? '', $COLS, true) || !preg_match($ID_RE, (string)($d['id'] ?? '')) || !is_array($d['data'] ?? null)) out(400, ['error' => 'bad_file']);
            $clean[] = $d;
        }
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM docs WHERE col IN ('txns','assets','maints','settings')");
        foreach ($clean as $d) setDoc($pdo, $d['col'], (string)$d['id'], $d['data']);
        $pdo->commit();
        audit($pdo, 'khôi phục từ bản sao lưu', '', '', count($clean) . ' dòng dữ liệu');
        out(200, ['ok' => true, 'count' => count($clean)]);
    }

    case 'feedback': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        if (!empty($body['website'])) out(200, ['ok' => true]); // ô bẫy cho bot
        if (hits($pdo, 'feedback', $ip, 3600) >= 5) out(429, ['error' => 'too_many']);
        $msg = str($body, 'message', 2000);
        if (mb_strlen($msg) < 3) out(400, ['error' => 'empty']);
        $kind = in_array($body['kind'] ?? '', ['Góp ý tính năng', 'Báo lỗi', 'Khác'], true) ? $body['kind'] : 'Khác';
        $pdo->prepare('INSERT INTO feedback (kind, message, name, contact, ip, created) VALUES (?,?,?,?,?,?)')->execute([$kind, $msg, str($body, 'name', 100), str($body, 'contact', 120), $ip, time()]);
        addHit($pdo, 'feedback', $ip);
        try {
            sendMail(emails(settingsDoc($pdo)), '[The Vista An Phú] Góp ý mới: ' . $kind, "Loại: $kind\r\nTừ: " . (str($body, 'name', 100) ?: '(ẩn danh)') . ' ' . str($body, 'contact', 120) . "\r\n\r\n$msg\r\n\r\nXem tại tab \"Góp ý nhận được\" trên trang quản lý.");
        } catch (Throwable $e) {}
        out(200, ['ok' => true]);
    }

    case 'feedback_list': {
        need($role, 2);
        out(200, ['items' => $pdo->query('SELECT id, kind, message, name, contact, created FROM feedback ORDER BY id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'feedback_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 2);
        $pdo->prepare('DELETE FROM feedback WHERE id = ?')->execute([(int)($body['id'] ?? 0)]);
        out(200, ['ok' => true]);
    }

    case 'upload': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 2);
        $col = (string)($_POST['col'] ?? ''); $ref = (string)($_POST['id'] ?? '');
        if (!in_array($col, ['txns', 'maints', 'assets'], true) || !preg_match($ID_RE, $ref) || !getDoc($pdo, $col, $ref)) out(400, ['error' => 'bad_request']);
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) out(400, ['error' => 'no_file']);
        if ($f['size'] > 6000000) out(413, ['error' => 'too_large']);
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM files WHERE ref_col = ? AND ref_id = ?'); $cnt->execute([$col, $ref]);
        if ((int)$cnt->fetchColumn() >= 10) out(409, ['error' => 'too_many_files']);
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) out(415, ['error' => 'bad_type']);
        $fdir = $dir . '/files';
        if (!is_dir($fdir)) @mkdir($fdir, 0750, true);
        $fid = bin2hex(random_bytes(16));
        if (!move_uploaded_file($f['tmp_name'], $fdir . '/' . $fid)) out(500, ['error' => 'save_failed']);
        $name = mb_substr(preg_replace('/[\x00-\x1f\\\\\/]/u', '_', (string)$f['name']), 0, 120) ?: 'tep';
        $pdo->prepare('INSERT INTO files (id, name, mime, size, ref_col, ref_id, ts, actor) VALUES (?,?,?,?,?,?,?,?)')->execute([$fid, $name, $mime, (int)$f['size'], $col, $ref, time(), actor()['name']]);
        audit($pdo, 'đính kèm chứng từ', $col, $ref, $name);
        out(200, ['ok' => true, 'file' => ['id' => $fid, 'name' => $name, 'mime' => $mime, 'size' => (int)$f['size'], 'col' => $col, 'ref' => $ref, 'ts' => time()]]);
    }

    case 'file': {
        need($role, 1);
        $s = $pdo->prepare('SELECT * FROM files WHERE id = ?'); $s->execute([(string)($_GET['id'] ?? '')]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $p = $r ? $dir . '/files/' . $r['id'] : '';
        if (!$r || !is_file($p)) out(404, ['error' => 'not_found']);
        header('Content-Type: ' . $r['mime']);
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($r['name']));
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Content-Length: ' . filesize($p));
        header('Cache-Control: private, max-age=300');
        readfile($p);
        exit;
    }

    case 'file_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 2);
        $s = $pdo->prepare('SELECT * FROM files WHERE id = ?'); $s->execute([(string)($body['id'] ?? '')]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) out(404, ['error' => 'not_found']);
        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$r['id']]);
        @unlink($dir . '/files/' . $r['id']);
        audit($pdo, 'xóa chứng từ', $r['ref_col'], $r['ref_id'], $r['name']);
        out(200, ['ok' => true]);
    }

    default:
        out(404, ['error' => 'unknown']);
}
