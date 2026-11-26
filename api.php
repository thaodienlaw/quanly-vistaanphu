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
try { $pdo->exec('ALTER TABLE users ADD COLUMN must_change INTEGER NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE users ADD COLUMN totp_on INTEGER NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE users ADD COLUMN totp_last INTEGER NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE users ADD COLUMN grp TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE users ADD COLUMN code TEXT'); } catch (Throwable $e) {}
$pdo->exec('CREATE TABLE IF NOT EXISTS ugroups (id TEXT PRIMARY KEY, abbr TEXT NOT NULL UNIQUE, code TEXT NOT NULL, name TEXT NOT NULL, caps TEXT NOT NULL, note TEXT, created INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, col TEXT, doc TEXT, summary TEXT, before TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS files (id TEXT PRIMARY KEY, name TEXT NOT NULL, mime TEXT NOT NULL, size INTEGER NOT NULL, ref_col TEXT NOT NULL, ref_id TEXT NOT NULL, ts INTEGER NOT NULL, actor TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY AUTOINCREMENT, asset_id TEXT NOT NULL, name TEXT NOT NULL, text TEXT NOT NULL, uid TEXT, ts INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE IF NOT EXISTS units (id TEXT PRIMARY KEY, code TEXT NOT NULL, block TEXT, area REAL, owner TEXT, phone TEXT, note TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS vehicles (id TEXT PRIMARY KEY, unit TEXT NOT NULL, type TEXT NOT NULL, plate TEXT NOT NULL, owner TEXT, status TEXT NOT NULL, note TEXT, created INTEGER NOT NULL, by_name TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS polls (id TEXT PRIMARY KEY, title TEXT NOT NULL, body TEXT, options TEXT NOT NULL, deadline TEXT, closed INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL, by_name TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS ballots (poll TEXT NOT NULL, unit TEXT NOT NULL, choice TEXT NOT NULL, ts INTEGER NOT NULL, by_name TEXT, PRIMARY KEY (poll, unit))');
try { $pdo->exec('ALTER TABLE users ADD COLUMN unit TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE polls ADD COLUMN impl TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE polls ADD COLUMN impl_note TEXT'); } catch (Throwable $e) {}
$pdo->exec('CREATE TABLE IF NOT EXISTS facilities (id TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT, rules TEXT, open_from TEXT, open_to TEXT, auto INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE TABLE IF NOT EXISTS bookings (id TEXT PRIMARY KEY, facility TEXT NOT NULL, unit TEXT NOT NULL, date TEXT NOT NULL, start TEXT NOT NULL, end TEXT NOT NULL, status TEXT NOT NULL, note TEXT, created INTEGER NOT NULL, by_name TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
$pdo->exec('CREATE TABLE IF NOT EXISTS gate_events (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, lane TEXT, dir TEXT NOT NULL, plate TEXT NOT NULL, result TEXT NOT NULL, vehicle_id TEXT, unit TEXT, vtype TEXT)');
$pdo->exec('CREATE INDEX IF NOT EXISTS gate_ts ON gate_events (ts)');

$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$role = $_SESSION['role'] ?? null;
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$RANK = ['viewer' => 1, 'auditor' => 1, 'accountant' => 2, 'manager' => 2, 'board' => 2, 'editor' => 2, 'staff' => 2, 'admin' => 3];
// Quyền theo từng việc. editor = vai trò cũ (Kế toán/Ban quản lý), giữ nguyên quyền như trước.
$CAPS = [
    'fund'     => ['viewer', 'editor', 'admin', 'accountant', 'manager', 'board', 'auditor'], // xem thu chi, số dư quỹ, báo cáo quỹ
    'resident' => ['viewer', 'editor', 'admin', 'accountant', 'manager', 'board', 'auditor'], // hiện menu Căn hộ, Gửi xe, Biểu quyết, Tiện ích (chỉ ẩn hiện menu)
    'seeall'   => ['editor', 'admin', 'accountant', 'manager', 'board', 'auditor'], // xem hồ sơ nhà thầu, căn hộ, xe, phiếu, tệp hợp đồng
    'manage'   => ['editor', 'admin', 'manager', 'board'],                           // tài sản, bảo trì, nhà thầu, thông báo, báo cáo, duyệt xe và tiện ích
    'txn'      => ['editor', 'admin', 'accountant', 'manager'],                      // ghi, sửa, hủy thu chi và chứng từ thu chi
    'approve'  => ['admin', 'board'],                                                // duyệt chi lớn (không tự duyệt khoản mình ghi)
    'govern'   => ['admin', 'board'],                                                // biểu quyết, danh sách căn hộ
    'facility' => ['admin', 'board', 'manager'],                                     // tiện ích
    'audit'    => ['admin', 'board', 'auditor'],                                     // nhật ký thay đổi
    'system'   => ['admin'],                                                         // tài khoản, cài đặt, sao lưu, khôi phục
];
$COLS = ['txns', 'assets', 'maints', 'notices', 'vendors', 'vratings', 'settings', 'budgets', 'bank'];
$ID_RE = '/^[A-Za-z0-9_.:@+~-]{1,100}$/';

/* Vai trò và nhóm lấy từ CSDL ở mỗi yêu cầu: đổi nhóm, khóa tài khoản có hiệu lực ngay. */
const CAP_NAMES = ['fund', 'txn', 'approve', 'manage', 'seeall', 'facility', 'govern', 'audit', 'resident'];
$MYCAPS = null; $MYGROUP = null; $MYCODE = '';
function resolveIdentity(PDO $pdo): void {
    global $role, $MYCAPS, $MYGROUP, $MYCODE;
    $MYCAPS = null; $MYGROUP = null; $MYCODE = '';
    if (!$role || empty($_SESSION['uid'])) return;
    $q = $pdo->prepare('SELECT u.role, u.active, u.grp, u.code, g.caps, g.code AS gcode, g.name AS gname FROM users u LEFT JOIN ugroups g ON g.id = u.grp WHERE u.id = ?');
    $q->execute([$_SESSION['uid']]);
    $ur = $q->fetch(PDO::FETCH_ASSOC);
    if (!$ur || !(int)$ur['active']) { $role = null; $_SESSION = []; return; }
    $role = $ur['role'];
    if ($role === 'staff') {
        $MYCAPS = $ur['gname'] !== null ? array_values(array_intersect(CAP_NAMES, (array)json_decode((string)$ur['caps'], true))) : [];
        $MYGROUP = ['code' => $ur['gcode'], 'name' => $ur['gname'], 'id' => $ur['grp']];
    }
    $MYCODE = (string)$ur['code'];
}
function capList(?string $role): array {
    global $CAPS, $MYCAPS;
    if ($role === 'staff') return $MYCAPS ?? [];
    $o = [];
    foreach ($CAPS as $c => $rs) if ($role && in_array($role, $rs, true)) $o[] = $c;
    return $o;
}
function identity(?string $role): array {
    global $MYGROUP, $MYCODE;
    return ['caps' => capList($role), 'group' => $MYGROUP['name'] ?? null, 'groupCode' => $MYGROUP['code'] ?? null, 'groupId' => $MYGROUP['id'] ?? null, 'code' => $MYCODE];
}

/* ---------- tiện ích ---------- */
function need(?string $role, int $min): void {
    global $RANK;
    if (!$role) out(401, ['error' => 'login']);
    if ($RANK[$role] < $min) out(403, ['error' => 'forbidden']);
}
function can(?string $role, string $cap): bool {
    global $CAPS, $MYCAPS;
    if ($role === 'staff') return is_array($MYCAPS) && in_array($cap, $MYCAPS, true);
    return $role !== null && in_array($role, $CAPS[$cap] ?? [], true);
}
function needCap(?string $role, string $cap): void {
    if (!$role) out(401, ['error' => 'login']);
    if (!can($role, $cap)) out(403, ['error' => 'forbidden']);
}
const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
function b32enc(string $bin): string {
    $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $o = ''; foreach (str_split($bits, 5) as $ch) $o .= B32[bindec(str_pad($ch, 5, '0'))];
    return $o;
}
function b32dec(string $s): string {
    $bits = ''; foreach (str_split(strtoupper($s)) as $c) { $p = strpos(B32, $c); if ($p !== false) $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
    $o = ''; foreach (str_split($bits, 8) as $by) if (strlen($by) === 8) $o .= chr(bindec($by));
    return $o;
}
function totpAt(string $secret, int $step): string {
    $h = hash_hmac('sha1', pack('N2', 0, $step), b32dec($secret), true);
    $o = ord($h[19]) & 15;
    $n = ((ord($h[$o]) & 127) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Trả về bước thời gian khớp (hoặc 0), cho phép lệch ±1 bước (30 giây). */
function totpCheck(string $secret, string $code, int $last): int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return 0;
    $now = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $st = $now + $d;
        if ($st > $last && hash_equals(totpAt($secret, $st), $code)) return $st;
    }
    return 0;
}
function secInfo(PDO $pdo): array {
    if (empty($_SESSION['uid'])) return ['mustChange' => false, 'totp' => false];
    $s = $pdo->prepare('SELECT must_change, totp_on FROM users WHERE id = ?'); $s->execute([$_SESSION['uid']]);
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['mustChange' => !empty($r['must_change']), 'totp' => !empty($r['totp_on'])];
}
function dailyBackup(PDO $pdo, string $dir): void {
    $day = date('Y-m-d');
    if (metaGet($pdo, 'last_backup') === $day) return;
    metaSet($pdo, 'last_backup', $day);
    $bd = $dir . '/backups';
    if (!is_dir($bd)) @mkdir($bd, 0750, true);
    $f = $bd . '/' . $day . '.sqlite';
    if (is_file($f)) return;
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $f) . "'");
    $all = glob($bd . '/*.sqlite') ?: [];
    sort($all);
    foreach (array_slice($all, 0, max(0, count($all) - 14)) as $old) @unlink($old);
}
function actor(): array {
    return ['id' => $_SESSION['uid'] ?? 'legacy', 'name' => $_SESSION['name'] ?? '(mật khẩu chung)'];
}
function myUnit(PDO $pdo): string {
    if (empty($_SESSION['uid'])) return '';
    $s = $pdo->prepare('SELECT unit FROM users WHERE id = ? AND active = 1'); $s->execute([$_SESSION['uid']]);
    return (string)($s->fetchColumn() ?: '');
}
function normUnit(string $c): string { return strtoupper(preg_replace('/\s+/', '', trim($c))); }
function pollOpen(array $p): bool {
    if (!empty($p['closed'])) return false;
    return $p['deadline'] === null || $p['deadline'] === '' || $p['deadline'] >= date('Y-m-d');
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
        case 'vendors': return 'Nhà thầu: ' . ($d['name'] ?? '');
        case 'vratings': return 'Đánh giá nhà thầu (' . ($d['date'] ?? '') . ')';
        case 'notices': return ($d['type'] ?? 'Thông báo') . ': ' . ($d['title'] ?? '');
        case 'settings': return 'Cài đặt chung';
        case 'tasks': return trim(($d['code'] ?? '') . ' ' . ($d['title'] ?? '')) . ' · ' . ($d['status'] ?? '');
        case 'bank': return 'Sao kê ' . fundName((string)($d['fund'] ?? '')) . ' tháng ' . ($d['month'] ?? '') . ' (' . count((array)($d['lines'] ?? [])) . ' dòng)';
        case 'budgets': return 'Dự toán năm ' . ($d['year'] ?? '');
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
function githubIssue(array $cfg, string $kind, string $msg): void {
    $tok = (string)($cfg['github_token'] ?? ''); $repo = (string)($cfg['github_repo'] ?? '');
    if ($tok === '' || !preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) return;
    // Chỉ đăng nội dung góp ý: không có tên, liên hệ hay IP (repo công khai).
    $title = $kind . ': ' . mb_substr(preg_replace('/\s+/u', ' ', $msg), 0, 70);
    $payload = json_encode(['title' => $title, 'body' => "**Loại:** $kind\n**Ngày:** " . date('d/m/Y H:i') . "\n\n" . $msg . "\n\n_(Gửi tự động từ trang quản lý; đã lược tên và liên hệ.)_", 'labels' => ['góp ý']], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 6, 'ignore_errors' => true,
        'header' => "Authorization: Bearer $tok\r\nAccept: application/vnd.github+json\r\nUser-Agent: quanly-vistaanphu\r\nContent-Type: application/json\r\n", 'content' => $payload]]);
    @file_get_contents("https://api.github.com/repos/$repo/issues", false, $ctx);
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
/* ---------- nhóm và quy tắc mã ---------- */
function codeRulesDefault(): array {
    return [
        'group'        => ['label' => 'Nhóm',                 'prefix' => 'NH', 'year' => false, 'width' => 0, 'eg' => 'NH-KT'],
        'userAdmin'    => ['label' => 'Người dùng: Trưởng ban', 'prefix' => 'AD', 'year' => false, 'width' => 3, 'eg' => 'AD-001'],
        'userStaff'    => ['label' => 'Người dùng: nhân viên (tiền tố là mã nhóm)', 'prefix' => '', 'year' => false, 'width' => 3, 'eg' => 'KT-001'],
        'userResident' => ['label' => 'Người dùng: cư dân',     'prefix' => 'CD', 'year' => false, 'width' => 3, 'eg' => 'CD-001'],
        'userOther'    => ['label' => 'Người dùng: khác',       'prefix' => 'NV', 'year' => false, 'width' => 3, 'eg' => 'NV-001'],
        'asset'        => ['label' => 'Tài sản',                'prefix' => 'TS', 'year' => false, 'width' => 4, 'eg' => 'TS-0012'],
        'maint'        => ['label' => 'Bảo trì, sửa chữa',      'prefix' => 'BT', 'year' => true,  'width' => 4, 'eg' => 'BT-2026-0007'],
        'txnIn'        => ['label' => 'Phiếu thu',              'prefix' => 'PT', 'year' => true,  'width' => 4, 'eg' => 'PT-2026-0015'],
        'txnOut'       => ['label' => 'Phiếu chi',              'prefix' => 'PC', 'year' => true,  'width' => 4, 'eg' => 'PC-2026-0031'],
        'task'         => ['label' => 'Phiếu công việc',        'prefix' => 'CV', 'year' => true,  'width' => 4, 'eg' => 'CV-2026-0003'],
        'vendor'       => ['label' => 'Nhà thầu',               'prefix' => 'NT', 'year' => false, 'width' => 3, 'eg' => 'NT-004'],
    ];
}
function codeRules(PDO $pdo): array {
    $r = codeRulesDefault();
    $c = json_decode((string)(metaGet($pdo, 'code_rules') ?? ''), true);
    if (is_array($c)) foreach ($r as $k => &$v) if (isset($c[$k]) && is_array($c[$k])) {
        if (isset($c[$k]['prefix']) && $k !== 'userStaff') $v['prefix'] = (string)$c[$k]['prefix'];
        if (isset($c[$k]['year']) && $k !== 'group') $v['year'] = (bool)$c[$k]['year'];
        if (isset($c[$k]['width']) && $v['width'] > 0) $v['width'] = (int)$c[$k]['width'];
    }
    return $r;
}
function codeExists(PDO $pdo, string $code): bool {
    $s = $pdo->prepare("SELECT 1 FROM docs WHERE data LIKE ? LIMIT 1");
    $s->execute(['%"code":' . json_encode($code, JSON_UNESCAPED_UNICODE) . '%']);
    if ($s->fetchColumn()) return true;
    $u = $pdo->prepare('SELECT 1 FROM users WHERE code = ? LIMIT 1'); $u->execute([$code]);
    return (bool)$u->fetchColumn();
}
/** Cấp mã kế tiếp theo quy tắc. $year dùng cho loại có năm; $abbr cho người dùng nhân viên (mã nhóm). */
function nextCode(PDO $pdo, string $key, ?string $year = null, string $abbr = ''): string {
    $r = codeRules($pdo)[$key];
    $prefix = $key === 'userStaff' ? $abbr : $r['prefix'];
    $y = !empty($r['year']) ? (preg_match('/^\d{4}$/', (string)$year) ? $year : date('Y')) : '';
    $sk = 'seq:' . $prefix . ($y !== '' ? '-' . $y : '');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        for ($i = 0; $i < 200; $i++) {
            $n = (int)(metaGet($pdo, $sk) ?? 0) + 1;
            metaSet($pdo, $sk, (string)$n);
            $code = $prefix . '-' . ($y !== '' ? $y . '-' : '') . str_pad((string)$n, (int)$r['width'], '0', STR_PAD_LEFT);
            if (!codeExists($pdo, $code)) { $pdo->exec('COMMIT'); return $code; }
        }
        $pdo->exec('ROLLBACK');
    } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {} throw $e; }
    throw new RuntimeException('code');
}
function docCodeKey(string $col, array $d): ?string {
    if ($col === 'assets') return 'asset';
    if ($col === 'maints') return 'maint';
    if ($col === 'vendors') return 'vendor';
    if ($col === 'tasks') return 'task';
    if ($col === 'txns') return ($d['type'] ?? '') === 'in' ? 'txnIn' : 'txnOut';
    return null;
}
function docYear(array $d): ?string {
    return preg_match('/^(\d{4})-/', (string)($d['date'] ?? ''), $m) ? $m[1] : null;
}
function userCodeKey(string $role): string {
    return $role === 'admin' ? 'userAdmin' : ($role === 'staff' ? 'userStaff' : ($role === 'viewer' ? 'userResident' : 'userOther'));
}
function backfillCodes(PDO $pdo): int {
    $n = 0;
    foreach (['assets', 'maints', 'vendors', 'txns'] as $col) {
        $docs = allDocs($pdo, $col);
        usort($docs, fn($a, $b) => [(string)($a['date'] ?? ''), (int)($a['at'] ?? 0), $a['id']] <=> [(string)($b['date'] ?? ''), (int)($b['at'] ?? 0), $b['id']]);
        foreach ($docs as $d) {
            if (!empty($d['code'])) continue;
            $id = $d['id']; unset($d['id']);
            $d['code'] = nextCode($pdo, docCodeKey($col, $d), docYear($d));
            setDoc($pdo, $col, $id, $d); $n++;
        }
    }
    $us = $pdo->query("SELECT u.id, u.role, g.abbr FROM users u LEFT JOIN ugroups g ON g.id = u.grp WHERE u.code IS NULL OR u.code = '' ORDER BY u.created")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($us as $u) {
        $k = userCodeKey($u['role']);
        if ($k === 'userStaff' && !$u['abbr']) $k = 'userOther';
        $pdo->prepare('UPDATE users SET code = ? WHERE id = ?')->execute([nextCode($pdo, $k, null, (string)$u['abbr']), $u['id']]); $n++;
    }
    return $n;
}
/** Lần đầu: tạo 5 nhóm mẫu, chuyển tài khoản cũ vào nhóm tương ứng, đánh mã cho hồ sơ đã có. */
function bootGroups(PDO $pdo): void {
    if (metaGet($pdo, 'groups_v1') !== '1') {
        $seed = [
            ['QLC', 'Quản lý chung', ['fund', 'txn', 'manage', 'seeall', 'facility', 'resident'], 'Điều hành chung: ghi thu chi, tài sản, bảo trì, nhà thầu, tiện ích.'],
            ['KT',  'Kỹ thuật',      ['manage', 'seeall'], 'Tài sản, bảo trì, nhà thầu. Không xem số dư quỹ và thu chi.'],
            ['KE',  'Kế toán',       ['fund', 'txn', 'seeall'], 'Ghi thu chi, xem quỹ và báo cáo quỹ.'],
            ['BQT', 'Ban quản trị',  ['fund', 'seeall', 'manage', 'approve', 'govern', 'facility', 'audit', 'resident'], 'Duyệt chi lớn, biểu quyết, xem nhật ký.'],
            ['KS',  'Kiểm soát',     ['fund', 'seeall', 'audit', 'resident'], 'Chỉ xem, kể cả nhật ký thay đổi.'],
        ];
        $ids = [];
        foreach ($seed as [$ab, $nm, $caps, $note]) {
            $e = $pdo->prepare('SELECT id FROM ugroups WHERE abbr = ?'); $e->execute([$ab]);
            $gid = $e->fetchColumn();
            if (!$gid) {
                $gid = 'g' . bin2hex(random_bytes(5));
                $pdo->prepare('INSERT INTO ugroups (id, abbr, code, name, caps, note, created) VALUES (?,?,?,?,?,?,?)')->execute([$gid, $ab, codeRules($pdo)['group']['prefix'] . '-' . $ab, $nm, json_encode($caps), $note, time()]);
            }
            $ids[$ab] = $gid;
        }
        foreach (['manager' => 'QLC', 'accountant' => 'KE', 'board' => 'BQT', 'auditor' => 'KS'] as $old => $ab) {
            $pdo->prepare("UPDATE users SET role = 'staff', grp = ? WHERE role = ?")->execute([$ids[$ab], $old]);
        }
        metaSet($pdo, 'groups_v1', '1');
    }
    if (metaGet($pdo, 'codes_v1') !== '1') {
        backfillCodes($pdo);
        metaSet($pdo, 'codes_v1', '1');
    }
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


/* ---------- cổng bãi xe: thiết bị nhận diện biển số dùng khóa riêng, không dùng tài khoản người ---------- */
function plateKey(string $p): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $p)); }
function gateAuth(PDO $pdo): void {
    $h = (string)metaGet($pdo, 'gate_key_hash');
    $k = (string)($_SERVER['HTTP_X_GATE_KEY'] ?? ($_GET['key'] ?? ''));
    if ($h === '' || $k === '' || !hash_equals($h, hash('sha256', $k))) { usleep(700000); out(401, ['error' => 'gate_key']); }
    metaSet($pdo, 'gate_seen', (string)time());
}
/** Xe được phép vào: đã duyệt, có biển số thật (xe đạp không tính). */
function gateVehicles(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, unit, type, plate FROM vehicles WHERE status = 'Đã duyệt' AND plate <> 'XE-DAP' AND type <> 'Xe đạp' ORDER BY plate")->fetchAll(PDO::FETCH_ASSOC);
    $res = [];
    foreach ($rows as $r) { $k = plateKey((string)$r['plate']); if ($k !== '') $res[] = ['id' => $r['id'], 'key' => $k, 'plate' => $r['plate'], 'type' => $r['type'], 'unit' => $r['unit']]; }
    return $res;
}
function gateFind(PDO $pdo, string $plate): ?array {
    $k = plateKey($plate); if ($k === '') return null;
    foreach (gateVehicles($pdo) as $v) if ($v['key'] === $k) return $v;
    return null;
}
function gateKeepDays(PDO $pdo): int { $d = (int)metaGet($pdo, 'gate_keep'); return $d >= 7 && $d <= 365 ? $d : 90; }

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
bootGroups($pdo);
resolveIdentity($pdo);
if ($role && !empty($_SESSION['mc']) && !in_array($a, ['me', 'logout', 'mypasswd'], true)) out(403, ['error' => 'must_change']);

switch ($a) {

    case 'me': {
        $hasUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn() > 0;
        out(200, ['role' => $role, 'uid' => $_SESSION['uid'] ?? null, 'name' => $_SESSION['name'] ?? null, 'unit' => $role ? myUnit($pdo) : '', 'legacy' => $role && !isset($_SESSION['uid']), 'hasUsers' => $hasUsers] + ($role ? secInfo($pdo) + identity($role) : []));
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
            if ($u && $pw !== '' && password_verify($pw, $u['hash'])) {
                if (!empty($u['totp_on'])) {
                    $code = (string)($body['code'] ?? '');
                    if ($code === '') out(401, ['error' => 'need_totp']);
                    $st = totpCheck((string)$u['totp_secret'], $code, (int)$u['totp_last']);
                    if (!$st) { addHit($pdo, 'login', $ip); addHit($pdo, 'login', $key); out(401, ['error' => 'bad_code']); }
                    $pdo->prepare('UPDATE users SET totp_last = ? WHERE id = ?')->execute([$st, $u['id']]);
                }
                $session = ['role' => $u['role'], 'uid' => $u['id'], 'name' => $u['name']];
                if (!empty($u['must_change'])) $session['mc'] = 1;
            }
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
        $role = $session['role']; resolveIdentity($pdo);
        out(200, ['role' => $role, 'uid' => $session['uid'] ?? null, 'name' => $session['name'] ?? null, 'unit' => myUnit($pdo), 'legacy' => !isset($session['uid']), 'hasUsers' => $hasUsers] + secInfo($pdo) + identity($role));
    }

    case 'totp_setup': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $uid = $_SESSION['uid'] ?? null; if (!$uid) out(400, ['error' => 'no_account']);
        $s = $pdo->prepare('SELECT username, totp_on FROM users WHERE id = ?'); $s->execute([$uid]); $u = $s->fetch(PDO::FETCH_ASSOC);
        if (!$u) out(404, ['error' => 'not_found']);
        if (!empty($u['totp_on'])) out(409, ['error' => 'totp_already']);
        $sec = b32enc(random_bytes(20));
        $pdo->prepare('UPDATE users SET totp_secret = ?, totp_last = 0 WHERE id = ?')->execute([$sec, $uid]);
        out(200, ['secret' => $sec, 'uri' => 'otpauth://totp/' . rawurlencode('The Vista An Phu:' . $u['username']) . '?secret=' . $sec . '&issuer=' . rawurlencode('The Vista An Phu')]);
    }

    case 'totp_enable': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $uid = $_SESSION['uid'] ?? null; if (!$uid) out(400, ['error' => 'no_account']);
        if (hits($pdo, 'login', 't:' . $uid, 600) >= 8) out(429, ['error' => 'too_many']);
        $s = $pdo->prepare('SELECT username, totp_secret FROM users WHERE id = ?'); $s->execute([$uid]); $u = $s->fetch(PDO::FETCH_ASSOC);
        if (!$u || empty($u['totp_secret'])) out(400, ['error' => 'bad_request']);
        $st = totpCheck((string)$u['totp_secret'], (string)($body['code'] ?? ''), 0);
        if (!$st) { addHit($pdo, 'login', 't:' . $uid); out(401, ['error' => 'bad_code']); }
        $pdo->prepare('UPDATE users SET totp_on = 1, totp_last = ? WHERE id = ?')->execute([$st, $uid]);
        audit($pdo, 'bật xác thực 2 bước', 'users', $uid, $u['username']);
        out(200, ['ok' => true]);
    }

    case 'totp_disable': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $uid = $_SESSION['uid'] ?? null; if (!$uid) out(400, ['error' => 'no_account']);
        $s = $pdo->prepare('SELECT username, hash FROM users WHERE id = ?'); $s->execute([$uid]); $u = $s->fetch(PDO::FETCH_ASSOC);
        if (!$u || !password_verify((string)($body['password'] ?? ''), (string)$u['hash'])) out(401, ['error' => 'bad_password']);
        $pdo->prepare('UPDATE users SET totp_on = 0, totp_secret = NULL, totp_last = 0 WHERE id = ?')->execute([$uid]);
        audit($pdo, 'tắt xác thực 2 bước', 'users', $uid, $u['username']);
        out(200, ['ok' => true]);
    }

    case 'backup_list': {
        needCap($role, 'system');
        $o = [];
        foreach (glob($dir . '/backups/*.sqlite') ?: [] as $f) $o[] = ['day' => basename($f, '.sqlite'), 'size' => filesize($f)];
        usort($o, fn($x, $y) => strcmp($y['day'], $x['day']));
        out(200, ['items' => $o]);
    }

    case 'backup_get': {
        needCap($role, 'system');
        $day = (string)($_GET['day'] ?? '');
        $f = $dir . '/backups/' . $day . '.sqlite';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !is_file($f)) out(404, ['error' => 'not_found']);
        audit($pdo, 'tải bản sao lưu', 'backup', $day, $day);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="vistaanphu-' . $day . '.sqlite"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    case 'logout': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        $_SESSION = [];
        session_destroy();
        out(200, ['ok' => true]);
    }

    case 'all': {
        need($role, 1);
        $res = ['txns' => [], 'assets' => [], 'maints' => [], 'notices' => [], 'vendors' => [], 'vratings' => [], 'tasks' => [], 'budgets' => [], 'groups' => [], 'comments' => [], 'settings' => new stdClass(), 'files' => [], 'meta' => new stdClass()];
        foreach ($pdo->query('SELECT col, id, data FROM docs') as $r) {
            $d = json_decode($r['data'], true);
            if (!is_array($d)) continue;
            if ($r['col'] === 'settings') { if ($r['id'] === 'main') $res['settings'] = $d; continue; }
            if (isset($res[$r['col']])) { $d['id'] = $r['id']; $res[$r['col']][] = $d; }
        }
        if (!can($role, 'seeall')) {   // cư dân: ẩn giá trị hợp đồng, ghi chú đánh giá, tệp hợp đồng
            foreach ($res['vendors'] as &$vv) { unset($vv['contractValue'], $vv['phone'], $vv['email'], $vv['note']); } unset($vv);
            foreach ($res['vratings'] as &$vr) { unset($vr['note']); } unset($vr);
        }
        $res['comments'] = $pdo->query('SELECT id, asset_id AS assetId, name, text, uid, ts FROM comments ORDER BY id DESC LIMIT 2000')->fetchAll(PDO::FETCH_ASSOC);
        $res['files'] = $pdo->query('SELECT id, name, mime, size, ref_col AS col, ref_id AS ref, ts FROM files ORDER BY ts')->fetchAll(PDO::FETCH_ASSOC);
        if (!can($role, 'seeall')) $res['files'] = array_values(array_filter($res['files'], fn($f) => $f['col'] !== 'vendors'));
        if (can($role, 'seeall')) $res['groups'] = $pdo->query('SELECT id, code, name FROM ugroups ORDER BY created')->fetchAll(PDO::FETCH_ASSOC);
        else $res['tasks'] = [];
        usort($res['tasks'], fn($a, $b) => (int)($b['at'] ?? 0) <=> (int)($a['at'] ?? 0));
        if (!can($role, 'fund')) {   // nhóm không được xem quỹ: bỏ thu chi, số dư đầu kỳ, hạn mức duyệt và chứng từ thu chi
            $res['txns'] = []; $res['budgets'] = [];
            $res['files'] = array_values(array_filter($res['files'], fn($f) => $f['col'] !== 'txns'));
            if (is_array($res['settings'])) unset($res['settings']['openMaint'], $res['settings']['openOps'], $res['settings']['approvalLimit']);
        }
        $res['meta'] = ['lastExport' => metaGet($pdo, 'last_export'), 'legacyOff' => metaGet($pdo, 'legacy_off') === '1'];
        // Trả dữ liệu trước, việc nền (sao lưu, gửi mail nhắc) làm sau để trang không phải chờ.
        $canRemind = can($role, 'manage');
        http_response_code(200);
        $json = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
        echo $json;
        session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { @ob_end_flush(); @flush(); }
        ignore_user_abort(true);
        try { dailyBackup($pdo, $dir); } catch (Throwable $e) {}
        if ($canRemind) { try { maybeRemind($pdo); } catch (Throwable $e) {} }
        exit;
    }

    case 'put': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $col = (string)($body['col'] ?? ''); $id = (string)($body['id'] ?? ''); $data = $body['data'] ?? null;
        if (!in_array($col, $COLS, true) || !preg_match($ID_RE, $id) || !is_array($data)) out(400, ['error' => 'bad_request']);
        needCap($role, $col === 'settings' ? 'system' : ($col === 'txns' ? 'txn' : ($col === 'budgets' ? 'approve' : ($col === 'bank' ? 'txn' : 'manage'))));
        unset($data['id']);
        $old = getDoc($pdo, $col, $id);
        if ($col === 'settings') {
            needCap($role, 'system');
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
        } elseif ($col === 'budgets') {
            $yr = (int)($data['year'] ?? 0);
            if ($yr < 2000 || $yr > 2100 || $id !== 'y' . $yr) out(400, ['error' => 'bad_request']);
            $lines = [];
            foreach (array_slice((array)($data['lines'] ?? []), 0, 100) as $ln) {
                $ln = (array)$ln; $amt = (float)($ln['amount'] ?? 0); $cat = mb_substr(trim((string)($ln['category'] ?? '')), 0, 60);
                if ($cat === '' || $amt <= 0 || !in_array($ln['fund'] ?? '', ['maint', 'ops'], true) || !in_array($ln['type'] ?? '', ['in', 'out'], true)) continue;
                $lines[] = ['fund' => $ln['fund'], 'type' => $ln['type'], 'category' => $cat, 'amount' => $amt];
            }
            $data = ['year' => $yr, 'lines' => $lines];
        } elseif ($col === 'bank') {   // sao kê ngân hàng theo quỹ và tháng, dùng để đối chiếu với sổ thu chi
            $mo = (string)($data['month'] ?? ''); $fd = (string)($data['fund'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}$/', $mo) || !in_array($fd, ['maint', 'ops'], true) || $id !== $mo . '-' . $fd) out(400, ['error' => 'bad_request']);
            $lines = [];
            foreach (array_slice((array)($data['lines'] ?? []), 0, 500) as $ln) {
                $ln = (array)$ln; $amt = round((float)($ln['amount'] ?? 0), 2);
                if ($amt == 0.0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($ln['date'] ?? ''))) continue;
                $lines[] = ['id' => mb_substr((string)($ln['id'] ?? ''), 0, 24), 'date' => $ln['date'], 'desc' => mb_substr(trim((string)($ln['desc'] ?? '')), 0, 120), 'amount' => $amt, 'txnId' => mb_substr((string)($ln['txnId'] ?? ''), 0, 100), 'ign' => !empty($ln['ign'])];
            }
            $data = ['month' => $mo, 'fund' => $fd, 'closing' => isset($data['closing']) && $data['closing'] !== '' && $data['closing'] !== null ? (float)$data['closing'] : null, 'lines' => $lines];
        } elseif ($col === 'maints') {
            if (trim((string)($data['assetId'] ?? '')) === '') out(400, ['error' => 'bad_request']);
            if (!in_array($data['kind'] ?? 'Bảo trì', ['Bảo trì', 'Sửa chữa', 'Kiểm định', 'Sử dụng', 'Đề xuất'], true)) $data['kind'] = 'Bảo trì';
        } elseif ($col === 'vendors') {
            if (trim((string)($data['name'] ?? '')) === '') out(400, ['error' => 'bad_request']);
            $data['name'] = mb_substr(trim((string)$data['name']), 0, 120);
            if (!in_array($data['status'] ?? '', ['Đang hợp đồng', 'Hết hạn', 'Ngừng hợp tác'], true)) $data['status'] = 'Đang hợp đồng';
        } elseif ($col === 'vratings') {
            if (trim((string)($data['vendorId'] ?? '')) === '') out(400, ['error' => 'bad_request']);
            foreach (['quality', 'timeliness', 'price', 'attitude'] as $k) $data[$k] = max(1, min(5, (int)($data[$k] ?? 3)));
            $data['note'] = mb_substr((string)($data['note'] ?? ''), 0, 1000);
            $who = actor(); $data['byName'] = $old['byName'] ?? $who['name'];
        } elseif ($col === 'notices') {
            if (trim((string)($data['title'] ?? '')) === '') out(400, ['error' => 'bad_request']);
            if (!in_array($data['type'] ?? '', ['Thông báo', 'Kế hoạch ngày', 'Kế hoạch tháng', 'Kế hoạch quý', 'Kế hoạch năm', 'Báo cáo tuần', 'Báo cáo tháng', 'Báo cáo quý', 'Báo cáo năm'], true)) $data['type'] = 'Thông báo';
            if (($data['org'] ?? '') === 'Đơn vị quản lý vận hành') $data['org'] = 'Ban quản lý';
            if (!in_array($data['org'] ?? '', ['Ban quản lý', 'Ban quản trị'], true)) $data['org'] = '';
            if (!in_array($data['status'] ?? '', ['Dự kiến', 'Đang thực hiện', 'Hoàn thành', ''], true)) $data['status'] = '';
            $data['title'] = mb_substr((string)$data['title'], 0, 160); $data['body'] = mb_substr((string)($data['body'] ?? ''), 0, 4000);
            $who = actor(); $data['byName'] = $old['byName'] ?? $who['name'];
        } elseif ($col === 'assets') {
            if (trim((string)($data['name'] ?? '')) === '') out(400, ['error' => 'bad_request']);
        }
        if (($k = docCodeKey($col, $data)) !== null) {   // mã tự sinh theo quy tắc, không sửa tay
            $data['code'] = !empty($old['code']) ? $old['code'] : nextCode($pdo, $k, docYear($data));
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > ($col === 'bank' ? 190000 : 60000)) out(413, ['error' => 'too_large']);
        setDoc($pdo, $col, $id, $data);
        if ($col === 'txns' && !empty($data['maintId']) && is_string($data['maintId'])) {   // khoản chi từ bảo trì: nối ngược vào lần bảo trì
            $mt = getDoc($pdo, 'maints', $data['maintId']);
            if ($mt && empty($mt['txnId'])) { $mt['txnId'] = $id; setDoc($pdo, 'maints', $data['maintId'], $mt); }
        }
        audit($pdo, $old ? 'sửa' : 'tạo', $col, $id, summ($col, $data), $old);
        $data['id'] = $id;
        out(200, ['ok' => true, 'doc' => $data]);
    }

    case 'del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        $col = (string)($body['col'] ?? ''); $id = (string)($body['id'] ?? '');
        if (!in_array($col, $COLS, true) || !preg_match($ID_RE, $id)) out(400, ['error' => 'bad_request']);
        needCap($role, in_array($col, ['assets', 'maints', 'notices', 'vendors', 'vratings'], true) ? 'manage' : ($col === 'budgets' ? 'approve' : ($col === 'bank' ? 'txn' : 'system')));
        $old = getDoc($pdo, $col, $id);
        $pdo->prepare('DELETE FROM docs WHERE col = ? AND id = ?')->execute([$col, $id]);
        if ($old) audit($pdo, 'xóa', $col, $id, summ($col, $old), $old);
        out(200, ['ok' => true]);
    }

    case 'void': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'txn');
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
        needCap($role, 'approve');
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
        needCap($role, 'system');
        $rl = (string)($body['role'] ?? ''); $np = (string)($body['password'] ?? '');
        if (!in_array($rl, ['editor', 'viewer'], true) || mb_strlen($np) < 8 || mb_strlen($np) > 100) out(400, ['error' => 'bad_password_format']);
        $pdo->prepare('INSERT INTO auth (role, hash) VALUES (?,?) ON CONFLICT(role) DO UPDATE SET hash = excluded.hash')->execute([$rl, password_hash($np, PASSWORD_DEFAULT)]);
        audit($pdo, 'đổi mật khẩu chung', '', '', $rl === 'viewer' ? 'Mật khẩu cư dân' : 'Mật khẩu Ban quản trị (dùng chung)');
        out(200, ['ok' => true]);
    }

    case 'mypasswd': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $uid = $_SESSION['uid'] ?? null;
        if (!$uid) out(400, ['error' => 'no_account']);
        $s = $pdo->prepare('SELECT hash FROM users WHERE id = ?'); $s->execute([$uid]);
        $h = $s->fetchColumn();
        $np = (string)($body['new'] ?? '');
        if (!$h || !password_verify((string)($body['current'] ?? ''), (string)$h)) out(401, ['error' => 'bad_password']);
        if (mb_strlen($np) < 8 || mb_strlen($np) > 100) out(400, ['error' => 'bad_password_format']);
        if (password_verify($np, (string)$h)) out(400, ['error' => 'same_password']);
        $pdo->prepare('UPDATE users SET hash = ?, must_change = 0 WHERE id = ?')->execute([password_hash($np, PASSWORD_DEFAULT), $uid]);
        unset($_SESSION['mc']);
        audit($pdo, 'đổi mật khẩu cá nhân');
        out(200, ['ok' => true]);
    }

    case 'users_list': {
        needCap($role, 'system');
        out(200, ['items' => $pdo->query('SELECT u.id, u.username, u.name, u.role, u.active, u.created, u.unit, u.totp_on AS totp, u.must_change AS mustChange, u.grp, u.code, g.name AS gname, g.code AS gcode FROM users u LEFT JOIN ugroups g ON g.id = u.grp ORDER BY u.created')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'user_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'system');
        $id = (string)($body['id'] ?? '');
        $name = str($body, 'name', 80);
        $rl = (string)($body['role'] ?? 'staff');
        $grp = (string)($body['grp'] ?? '');
        $active = !empty($body['active']) ? 1 : 0;
        $pw = (string)($body['password'] ?? '');
        $unit = normUnit((string)($body['unit'] ?? ''));
        if ($unit !== '') { $q = $pdo->prepare('SELECT COUNT(*) FROM units WHERE id = ?'); $q->execute([$unit]); if (!(int)$q->fetchColumn()) out(400, ['error' => 'bad_unit']); }
        if ($name === '' || !in_array($rl, ['admin', 'staff', 'editor', 'viewer'], true)) out(400, ['error' => 'bad_request']);
        $abbr = '';
        if ($rl === 'staff') {
            $g = $pdo->prepare('SELECT abbr FROM ugroups WHERE id = ?'); $g->execute([$grp]);
            $abbr = (string)$g->fetchColumn();
            if ($abbr === '') out(400, ['error' => 'bad_group']);
        } else { $grp = ''; }
        $grpv = $grp !== '' ? $grp : null;
        if ($pw !== '' && (mb_strlen($pw) < 8 || mb_strlen($pw) > 100)) out(400, ['error' => 'bad_password_format']);
        if ($id === '') {
            $un = strtolower(str($body, 'username', 30));
            if (!preg_match('/^[a-z0-9._-]{3,30}$/', $un) || $pw === '') out(400, ['error' => 'bad_request']);
            $ex = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?'); $ex->execute([$un]);
            if ((int)$ex->fetchColumn() > 0) out(409, ['error' => 'username_taken']);
            $nid = 'u' . bin2hex(random_bytes(6));
            $ucode = nextCode($pdo, userCodeKey($rl), null, $abbr);
            $pdo->prepare('INSERT INTO users (id, username, name, role, hash, active, created, unit, must_change, grp, code) VALUES (?,?,?,?,?,?,?,?,1,?,?)')->execute([$nid, $un, $name, $rl, password_hash($pw, PASSWORD_DEFAULT), 1, time(), $unit, $grpv, $ucode]);
            audit($pdo, 'tạo tài khoản', 'users', $nid, "$ucode · $un ($rl" . ($abbr !== '' ? ", nhóm $abbr" : '') . ')');
        } else {
            $s = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([$id]);
            $u = $s->fetch(PDO::FETCH_ASSOC);
            if (!$u) out(404, ['error' => 'not_found']);
            if ($id === ($_SESSION['uid'] ?? '') && ($u['role'] === 'admin' && ($rl !== 'admin' || !$active))) out(409, ['error' => 'self_lock']);
            $pdo->prepare('UPDATE users SET name = ?, role = ?, active = ?, unit = ?, grp = ? WHERE id = ?')->execute([$name, $rl, $active, $unit, $grpv, $id]);
            if (empty($u['code'])) $pdo->prepare('UPDATE users SET code = ? WHERE id = ?')->execute([nextCode($pdo, userCodeKey($rl), null, $abbr), $id]);
            if ($pw !== '') $pdo->prepare('UPDATE users SET hash = ?, must_change = 1 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
            if (!empty($body['reset2fa'])) { $pdo->prepare('UPDATE users SET totp_on = 0, totp_secret = NULL, totp_last = 0 WHERE id = ?')->execute([$id]); audit($pdo, 'tắt xác thực 2 bước', 'users', $id, $u['username']); }
            $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'admin'")->fetchColumn();
            if (metaGet($pdo, 'legacy_off') === '1' && $admins < 1) {   // tránh tự khóa mình ra ngoài
                $pdo->prepare('UPDATE users SET name = ?, role = ?, active = ?, unit = ?, grp = ? WHERE id = ?')->execute([$u['name'], $u['role'], $u['active'], $u['unit'], $u['grp'], $id]);
                out(409, ['error' => 'last_admin']);
            }
            audit($pdo, 'sửa tài khoản', 'users', $id, $u['username'] . ($abbr !== '' ? " (nhóm $abbr)" : '') . ($pw !== '' ? ' (đặt lại mật khẩu)' : '') . ($active ? '' : ' (đã khóa)'));
        }
        out(200, ['ok' => true]);
    }

    case 'bank_list': {
        needCap($role, 'txn');
        $docs = allDocs($pdo, 'bank');
        usort($docs, fn($a, $b) => strcmp((string)($b['month'] ?? ''), (string)($a['month'] ?? '')));
        out(200, ['items' => $docs]);
    }

    case 'task_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'manage');
        $id = (string)($body['id'] ?? '');
        $old = $id !== '' ? getDoc($pdo, 'tasks', $id) : null;
        if ($id !== '' && !$old) out(404, ['error' => 'not_found']);
        if ($old && !in_array($old['status'], ['Đề xuất', 'Đã duyệt'], true)) out(409, ['error' => 'task_locked']);
        $title = str($body, 'title', 160);
        if ($title === '') out(400, ['error' => 'bad_request']);
        $asset = (string)($body['assetId'] ?? ''); if ($asset !== '' && !getDoc($pdo, 'assets', $asset)) out(400, ['error' => 'bad_request']);
        $grp = (string)($body['groupId'] ?? '');
        if ($grp !== '') { $g = $pdo->prepare('SELECT COUNT(*) FROM ugroups WHERE id = ?'); $g->execute([$grp]); if (!(int)$g->fetchColumn()) out(400, ['error' => 'bad_group']); }
        $due = (string)($body['due'] ?? ''); if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) out(400, ['error' => 'bad_request']);
        $kind = in_array($body['kind'] ?? '', ['Bảo trì', 'Sửa chữa', 'Kiểm định'], true) ? $body['kind'] : 'Bảo trì';
        $who = actor();
        $d = [
            'title' => $title, 'assetId' => $asset, 'groupId' => $grp, 'due' => $due, 'kind' => $kind,
            'priority' => ($body['priority'] ?? '') === 'Gấp' ? 'Gấp' : 'Thường',
            'estCost' => max(0, (float)($body['estCost'] ?? 0)), 'note' => mb_substr((string)($body['note'] ?? ''), 0, 2000),
        ];
        if ($old) {
            $d = array_merge($old, ['title' => $title, 'assetId' => $asset, 'groupId' => $grp, 'due' => $due, 'kind' => $kind, 'priority' => ($body['priority'] ?? '') === 'Gấp' ? 'Gấp' : 'Thường', 'estCost' => max(0, (float)($body['estCost'] ?? 0)), 'note' => mb_substr((string)($body['note'] ?? ''), 0, 2000)]);
            // sửa dự toán sau khi đã duyệt: quay về chờ duyệt
            if ($old['status'] === 'Đã duyệt' && (float)$old['estCost'] !== (float)$d['estCost']) { $d['status'] = 'Đề xuất'; $d['trail'][] = ['at' => time(), 'by' => $who['id'], 'name' => $who['name'], 'to' => 'Đề xuất', 'note' => 'Sửa dự toán, cần duyệt lại']; }
        } else {
            $id = 't' . bin2hex(random_bytes(6));
            $d['status'] = 'Đề xuất'; $d['by'] = $who['id']; $d['byName'] = $who['name']; $d['at'] = time();
            $d['code'] = nextCode($pdo, 'task');
            $d['trail'] = [['at' => time(), 'by' => $who['id'], 'name' => $who['name'], 'to' => 'Đề xuất', 'note' => '']];
        }
        setDoc($pdo, 'tasks', $id, $d);
        audit($pdo, $old ? 'sửa phiếu việc' : 'tạo phiếu việc', 'tasks', $id, summ('tasks', $d));
        $d['id'] = $id;
        out(200, ['ok' => true, 'doc' => $d]);
    }

    case 'task_move': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'manage');
        $id = (string)($body['id'] ?? ''); $to = (string)($body['to'] ?? ''); $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 500);
        $d = getDoc($pdo, 'tasks', $id);
        if (!$d) out(404, ['error' => 'not_found']);
        $flow = [
            'Đề xuất' => ['Đã duyệt', 'Từ chối', 'Đã hủy'],
            'Đã duyệt' => ['Đang thực hiện', 'Đã hủy'],
            'Đang thực hiện' => ['Chờ nghiệm thu', 'Đã hủy'],
            'Chờ nghiệm thu' => ['Hoàn thành', 'Đang thực hiện'],
        ];
        if (!in_array($to, $flow[$d['status']] ?? [], true)) out(409, ['error' => 'bad_move']);
        $who = actor();
        if (in_array($to, ['Từ chối', 'Đã hủy'], true) && $note === '') out(400, ['error' => 'reason']);
        if ($to === 'Đang thực hiện' && $d['status'] === 'Chờ nghiệm thu' && $note === '') out(400, ['error' => 'reason']);
        if ($d['status'] === 'Đề xuất' && in_array($to, ['Đã duyệt', 'Từ chối'], true)) {
            $limit = (float)(settingsDoc($pdo)['approvalLimit'] ?? 0);
            if ($limit > 0 && (float)$d['estCost'] >= $limit) {
                needCap($role, 'approve');
                if ($who['id'] !== 'legacy' && ($d['by'] ?? '') === $who['id']) out(403, ['error' => 'self_approve']);
            }
        }
        $extra = '';
        if ($to === 'Hoàn thành') {
            $date = (string)($body['date'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) out(400, ['error' => 'bad_request']);
            $cost = max(0, (float)($body['cost'] ?? 0)); $vendor = mb_substr(trim((string)($body['vendor'] ?? '')), 0, 120);
            $mid = 'mw' . bin2hex(random_bytes(5));
            $m = ['date' => $date, 'assetId' => (string)$d['assetId'], 'kind' => $d['kind'] ?? 'Bảo trì', 'title' => $d['title'], 'vendor' => $vendor, 'cost' => $cost, 'fund' => 'maint', 'note' => trim('Từ phiếu ' . ($d['code'] ?? '') . ($note !== '' ? '. ' . $note : '')), 'txnId' => '', 'taskId' => $id];
            if ($m['assetId'] === '') out(400, ['error' => 'task_no_asset']);
            $m['code'] = nextCode($pdo, 'maint', docYear($m));
            setDoc($pdo, 'maints', $mid, $m);
            audit($pdo, 'tạo', 'maints', $mid, summ('maints', $m));
            $d['maintId'] = $mid; $d['actualCost'] = $cost; $d['doneAt'] = $date;
            $extra = ' · chi phí ' . vnd($cost);
        }
        $d['status'] = $to;
        $d['trail'][] = ['at' => time(), 'by' => $who['id'], 'name' => $who['name'], 'to' => $to, 'note' => $note . $extra];
        setDoc($pdo, 'tasks', $id, $d);
        audit($pdo, 'phiếu việc: ' . $to, 'tasks', $id, summ('tasks', $d) . ($note !== '' ? ' · ' . $note : ''));
        $d['id'] = $id;
        out(200, ['ok' => true, 'doc' => $d, 'maintId' => $d['maintId'] ?? null]);
    }

    case 'task_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'manage');
        $id = (string)($body['id'] ?? '');
        $d = getDoc($pdo, 'tasks', $id);
        if (!$d) out(404, ['error' => 'not_found']);
        if (!in_array($d['status'], ['Đề xuất', 'Từ chối', 'Đã hủy'], true)) out(409, ['error' => 'task_locked']);
        $pdo->prepare("DELETE FROM docs WHERE col = 'tasks' AND id = ?")->execute([$id]);
        audit($pdo, 'xóa phiếu việc', 'tasks', $id, summ('tasks', $d), $d);
        out(200, ['ok' => true]);
    }

    case 'groups_list': {
        needCap($role, 'system');
        $items = $pdo->query('SELECT g.id, g.abbr, g.code, g.name, g.caps, g.note, (SELECT COUNT(*) FROM users u WHERE u.grp = g.id AND u.active = 1) AS members FROM ugroups g ORDER BY g.created')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$it) { $it['caps'] = array_values((array)json_decode((string)$it['caps'], true)); $it['members'] = (int)$it['members']; } unset($it);
        $rules = codeRules($pdo);
        out(200, ['items' => $items, 'rules' => $rules, 'capNames' => CAP_NAMES]);
    }

    case 'group_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'system');
        $id = (string)($body['id'] ?? '');
        $name = str($body, 'name', 60); $note = str($body, 'note', 200);
        $caps = array_values(array_intersect(CAP_NAMES, array_map('strval', (array)($body['caps'] ?? []))));
        if (array_intersect(['txn', 'approve'], $caps) && !in_array('fund', $caps, true)) $caps[] = 'fund';
        $caps = array_values(array_intersect(CAP_NAMES, $caps));
        if ($name === '') out(400, ['error' => 'bad_request']);
        if ($id === '') {
            $ab = strtoupper(str($body, 'abbr', 6));
            if (!preg_match('/^[A-Z0-9]{2,6}$/', $ab)) out(400, ['error' => 'bad_abbr']);
            $e = $pdo->prepare('SELECT COUNT(*) FROM ugroups WHERE abbr = ?'); $e->execute([$ab]);
            if ((int)$e->fetchColumn() > 0) out(409, ['error' => 'abbr_taken']);
            $gid = 'g' . bin2hex(random_bytes(5));
            $code = codeRules($pdo)['group']['prefix'] . '-' . $ab;
            $pdo->prepare('INSERT INTO ugroups (id, abbr, code, name, caps, note, created) VALUES (?,?,?,?,?,?,?)')->execute([$gid, $ab, $code, $name, json_encode($caps), $note, time()]);
            audit($pdo, 'tạo nhóm', 'ugroups', $gid, "$code · $name · quyền: " . implode(',', $caps));
        } else {
            $g = $pdo->prepare('SELECT * FROM ugroups WHERE id = ?'); $g->execute([$id]);
            $old = $g->fetch(PDO::FETCH_ASSOC);
            if (!$old) out(404, ['error' => 'not_found']);
            $pdo->prepare('UPDATE ugroups SET name = ?, caps = ?, note = ? WHERE id = ?')->execute([$name, json_encode($caps), $note, $id]);
            audit($pdo, 'sửa nhóm', 'ugroups', $id, $old['code'] . ' · ' . $name . ' · quyền: ' . implode(',', $caps) . ' (trước: ' . implode(',', (array)json_decode((string)$old['caps'], true)) . ')');
        }
        out(200, ['ok' => true]);
    }

    case 'group_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'system');
        $id = (string)($body['id'] ?? '');
        $g = $pdo->prepare('SELECT * FROM ugroups WHERE id = ?'); $g->execute([$id]);
        $old = $g->fetch(PDO::FETCH_ASSOC);
        if (!$old) out(404, ['error' => 'not_found']);
        $m = $pdo->prepare('SELECT COUNT(*) FROM users WHERE grp = ?'); $m->execute([$id]);
        if ((int)$m->fetchColumn() > 0) out(409, ['error' => 'group_in_use']);
        $pdo->prepare('DELETE FROM ugroups WHERE id = ?')->execute([$id]);
        audit($pdo, 'xóa nhóm', 'ugroups', $id, $old['code'] . ' · ' . $old['name']);
        out(200, ['ok' => true]);
    }


    case 'gate_list': {   // thiết bị cổng tải danh sách xe được phép vào (JSON hoặc CSV)
        gateAuth($pdo);
        $list = gateVehicles($pdo);
        if (($_GET['format'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            echo "plate_key,plate,type,unit\r\n";
            foreach ($list as $v) echo implode(',', [$v['key'], '"' . str_replace('"', '""', (string)$v['plate']) . '"', $v['type'], $v['unit']]) . "\r\n";
            exit;
        }
        out(200, ['updated' => date('c'), 'count' => count($list), 'items' => array_map(fn($v) => ['key' => $v['key'], 'plate' => $v['plate'], 'type' => $v['type'], 'unit' => $v['unit']], $list)]);
    }

    case 'gate_check': {   // hỏi nhanh một biển số (thiết bị luôn nên giữ bản danh sách cục bộ để dùng khi mất mạng)
        gateAuth($pdo);
        $plate = (string)($_GET['plate'] ?? ($body['plate'] ?? ''));
        $v = gateFind($pdo, $plate);
        out(200, ['allow' => $v !== null, 'key' => plateKey($plate), 'type' => $v['type'] ?? null, 'unit' => $v['unit'] ?? null]);
    }

    case 'gate_log': {   // thiết bị đẩy nhật ký ra vào (tối đa 100 dòng mỗi lần)
        if ($method !== 'POST') out(405, ['error' => 'method']);
        gateAuth($pdo);
        $in = array_slice((array)($body['events'] ?? []), 0, 100); $n = 0;
        $ins = $pdo->prepare('INSERT INTO gate_events (ts, lane, dir, plate, result, vehicle_id, unit, vtype) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($in as $e) {
            if (!is_array($e)) continue;
            $k = plateKey((string)($e['plate'] ?? '')); if ($k === '') $k = '?';
            $ts = $e['ts'] ?? time(); $ts = is_numeric($ts) ? (int)$ts : (int)strtotime((string)$ts);
            if ($ts < 1500000000 || $ts > time() + 86400) $ts = time();
            $dir = ($e['dir'] ?? 'in') === 'out' ? 'out' : 'in';
            $res = in_array($e['result'] ?? '', ['allow', 'deny', 'manual'], true) ? $e['result'] : 'allow';
            $v = $k !== '?' ? gateFind($pdo, $k) : null;
            $ins->execute([$ts, mb_substr((string)($e['lane'] ?? ''), 0, 20), $dir, $k, $res, $v['id'] ?? null, $v['unit'] ?? null, $v['type'] ?? null]); $n++;
        }
        if (random_int(1, 20) === 1) $pdo->prepare('DELETE FROM gate_events WHERE ts < ?')->execute([time() - gateKeepDays($pdo) * 86400]);
        out(200, ['ok' => true, 'saved' => $n]);
    }

    case 'gate_events': {   // Ban quản lý xem nhật ký ra vào
        needCap($role, 'seeall');
        $w = ['1=1']; $p = [];
        $d = (string)($_GET['date'] ?? ''); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $t0 = strtotime($d . ' 00:00:00'); $w[] = 'ts >= ? AND ts < ?'; $p[] = $t0; $p[] = $t0 + 86400; }
        $q = plateKey((string)($_GET['plate'] ?? '')); if ($q !== '') { $w[] = 'plate LIKE ?'; $p[] = '%' . $q . '%'; }
        $r = (string)($_GET['result'] ?? ''); if (in_array($r, ['allow', 'deny', 'manual'], true)) { $w[] = 'result = ?'; $p[] = $r; }
        $s2 = $pdo->prepare('SELECT id, ts, lane, dir, plate, result, unit, vtype FROM gate_events WHERE ' . implode(' AND ', $w) . ' ORDER BY ts DESC, id DESC LIMIT 300');
        $s2->execute($p);
        out(200, ['items' => $s2->fetchAll(PDO::FETCH_ASSOC), 'keepDays' => gateKeepDays($pdo)]);
    }

    case 'gate_info': {
        needCap($role, 'system');
        $seen = (int)metaGet($pdo, 'gate_seen');
        out(200, ['on' => (string)metaGet($pdo, 'gate_key_hash') !== '', 'seen' => $seen ?: null, 'keepDays' => gateKeepDays($pdo), 'vehicles' => count(gateVehicles($pdo))]);
    }

    case 'gate_key_new': {   // tạo hoặc đổi khóa; khóa chỉ hiện đúng một lần
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'system');
        $k = bin2hex(random_bytes(24));
        metaSet($pdo, 'gate_key_hash', hash('sha256', $k));
        audit($pdo, 'tạo khóa cổng bãi xe', '', '', 'Khóa cũ (nếu có) hết hiệu lực.');
        out(200, ['ok' => true, 'key' => $k]);
    }

    case 'gate_key_off': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'system');
        metaSet($pdo, 'gate_key_hash', '');
        audit($pdo, 'tắt khóa cổng bãi xe');
        out(200, ['ok' => true]);
    }

    case 'gate_cfg': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'system');
        $d = (int)($body['keepDays'] ?? 90);
        if ($d < 7 || $d > 365) out(400, ['error' => 'bad_request']);
        metaSet($pdo, 'gate_keep', (string)$d);
        audit($pdo, 'đổi thời gian lưu nhật ký cổng', '', '', "$d ngày");
        out(200, ['ok' => true]);
    }

    case 'rules_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'system');
        $in = (array)($body['rules'] ?? []); $out = [];
        foreach (codeRulesDefault() as $k => $d) {
            $r = (array)($in[$k] ?? []);
            $pf = strtoupper(trim((string)($r['prefix'] ?? $d['prefix'])));
            if ($k !== 'userStaff' && !preg_match('/^[A-Z0-9]{1,6}$/', $pf)) out(400, ['error' => 'bad_prefix']);
            $w = (int)($r['width'] ?? $d['width']);
            if ($d['width'] > 0 && ($w < 2 || $w > 8)) out(400, ['error' => 'bad_prefix']);
            $out[$k] = ['prefix' => $k === 'userStaff' ? '' : $pf, 'year' => $k === 'group' ? false : !empty($r['year']), 'width' => $d['width'] > 0 ? $w : 0];
        }
        metaSet($pdo, 'code_rules', json_encode($out));
        audit($pdo, 'sửa quy tắc mã', '', '', 'Quy tắc mã mới áp dụng cho hồ sơ tạo từ nay; mã đã cấp giữ nguyên.');
        out(200, ['ok' => true]);
    }

    case 'codes_backfill': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'system');
        $n = backfillCodes($pdo);
        audit($pdo, 'đánh mã bù', '', '', "$n hồ sơ");
        out(200, ['ok' => true, 'count' => $n]);
    }

    case 'legacy_off': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'system');
        $off = !empty($body['off']);
        if ($off && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'admin'")->fetchColumn() < 1) out(409, ['error' => 'no_admin']);
        metaSet($pdo, 'legacy_off', $off ? '1' : '0');
        audit($pdo, $off ? 'tắt mật khẩu chung Ban quản trị' : 'bật mật khẩu chung Ban quản trị');
        out(200, ['ok' => true]);
    }

    case 'audit_list': {
        needCap($role, 'audit');
        out(200, ['items' => $pdo->query('SELECT id, ts, actor, action, col, doc, summary FROM audit ORDER BY id DESC LIMIT 400')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'export': {
        needCap($role, 'system');
        $docs = [];
        foreach ($pdo->query('SELECT col, id, data FROM docs') as $r) $docs[] = ['col' => $r['col'], 'id' => $r['id'], 'data' => json_decode($r['data'], true)];
        $payload = [
            'app' => 'quanly-vistaanphu', 'version' => 2, 'exported' => date('c'),
            'docs' => $docs,
            'files' => $pdo->query('SELECT id, name, mime, size, ref_col, ref_id, ts FROM files')->fetchAll(PDO::FETCH_ASSOC),
            'feedback' => $pdo->query('SELECT kind, message, name, contact, created FROM feedback')->fetchAll(PDO::FETCH_ASSOC),
            'comments' => $pdo->query('SELECT asset_id, name, text, ts FROM comments')->fetchAll(PDO::FETCH_ASSOC),
            'units' => $pdo->query('SELECT * FROM units')->fetchAll(PDO::FETCH_ASSOC),
            'vehicles' => $pdo->query('SELECT * FROM vehicles')->fetchAll(PDO::FETCH_ASSOC),
            'polls' => $pdo->query('SELECT * FROM polls')->fetchAll(PDO::FETCH_ASSOC),
            'facilities' => $pdo->query('SELECT * FROM facilities')->fetchAll(PDO::FETCH_ASSOC),
            'bookings' => $pdo->query('SELECT * FROM bookings')->fetchAll(PDO::FETCH_ASSOC),
            'ballots' => $pdo->query('SELECT * FROM ballots')->fetchAll(PDO::FETCH_ASSOC),
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
        needCap($role, 'system');
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
        needCap($role, 'system');
        $docs = $body['docs'] ?? null;
        if (!is_array($docs) || count($docs) > 20000 || ($body['app'] ?? '') !== 'quanly-vistaanphu') out(400, ['error' => 'bad_file']);
        $clean = [];
        foreach ($docs as $d) {
            if (!is_array($d) || !in_array($d['col'] ?? '', $COLS, true) || !preg_match($ID_RE, (string)($d['id'] ?? '')) || !is_array($d['data'] ?? null)) out(400, ['error' => 'bad_file']);
            $clean[] = $d;
        }
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM docs WHERE col IN ('txns','assets','maints','notices','vendors','vratings','settings')");
        foreach ($clean as $d) setDoc($pdo, $d['col'], (string)$d['id'], $d['data']);
        if (isset($body['units']) && is_array($body['units'])) {
            $pdo->exec('DELETE FROM units'); $pdo->exec('DELETE FROM vehicles'); $pdo->exec('DELETE FROM polls'); $pdo->exec('DELETE FROM ballots'); $pdo->exec('DELETE FROM facilities'); $pdo->exec('DELETE FROM bookings');
            foreach ($body['units'] as $r) if (is_array($r) && !empty($r['id'])) $pdo->prepare('INSERT OR REPLACE INTO units (id, code, block, area, owner, phone, note) VALUES (?,?,?,?,?,?,?)')->execute([(string)$r['id'], (string)($r['code'] ?? $r['id']), $r['block'] ?? '', (float)($r['area'] ?? 0), $r['owner'] ?? '', $r['phone'] ?? '', $r['note'] ?? '']);
            foreach (($body['vehicles'] ?? []) as $r) if (is_array($r) && !empty($r['id'])) $pdo->prepare('INSERT OR REPLACE INTO vehicles (id, unit, type, plate, owner, status, note, created, by_name) VALUES (?,?,?,?,?,?,?,?,?)')->execute([(string)$r['id'], (string)($r['unit'] ?? ''), (string)($r['type'] ?? 'Xe máy'), (string)($r['plate'] ?? ''), $r['owner'] ?? '', (string)($r['status'] ?? 'Đã duyệt'), $r['note'] ?? '', (int)($r['created'] ?? time()), $r['by_name'] ?? '']);
            foreach (($body['polls'] ?? []) as $r) if (is_array($r) && !empty($r['id'])) $pdo->prepare('INSERT OR REPLACE INTO polls (id, title, body, options, deadline, closed, created, by_name, impl, impl_note) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([(string)$r['id'], (string)($r['title'] ?? ''), $r['body'] ?? '', (string)($r['options'] ?? '[]'), $r['deadline'] ?? '', (int)($r['closed'] ?? 0), (int)($r['created'] ?? time()), $r['by_name'] ?? '', $r['impl'] ?? '', $r['impl_note'] ?? '']);
            foreach (($body['facilities'] ?? []) as $r) if (is_array($r) && !empty($r['id'])) $pdo->prepare('INSERT OR REPLACE INTO facilities (id, name, kind, rules, open_from, open_to, auto, active) VALUES (?,?,?,?,?,?,?,?)')->execute([(string)$r['id'], (string)($r['name'] ?? ''), $r['kind'] ?? '', $r['rules'] ?? '', $r['open_from'] ?? '06:00', $r['open_to'] ?? '22:00', (int)($r['auto'] ?? 0), (int)($r['active'] ?? 1)]);
            foreach (($body['bookings'] ?? []) as $r) if (is_array($r) && !empty($r['id'])) $pdo->prepare('INSERT OR REPLACE INTO bookings (id, facility, unit, date, start, end, status, note, created, by_name) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([(string)$r['id'], (string)($r['facility'] ?? ''), (string)($r['unit'] ?? ''), (string)($r['date'] ?? ''), (string)($r['start'] ?? ''), (string)($r['end'] ?? ''), (string)($r['status'] ?? 'Đã duyệt'), $r['note'] ?? '', (int)($r['created'] ?? time()), $r['by_name'] ?? '']);
            foreach (($body['ballots'] ?? []) as $r) if (is_array($r) && !empty($r['poll'])) $pdo->prepare('INSERT OR REPLACE INTO ballots (poll, unit, choice, ts, by_name) VALUES (?,?,?,?,?)')->execute([(string)$r['poll'], (string)($r['unit'] ?? ''), (string)($r['choice'] ?? ''), (int)($r['ts'] ?? time()), $r['by_name'] ?? '']);
        }
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
        $dup = $pdo->prepare('SELECT 1 FROM feedback WHERE message = ? AND ip = ? AND created > ?');
        $dup->execute([$msg, $ip, time() - 300]);
        if ($dup->fetchColumn()) out(200, ['ok' => true]); // bấm gửi 2 lần: bỏ qua bản trùng
        $pdo->prepare('INSERT INTO feedback (kind, message, name, contact, ip, created) VALUES (?,?,?,?,?,?)')->execute([$kind, $msg, str($body, 'name', 100), str($body, 'contact', 120), $ip, time()]);
        addHit($pdo, 'feedback', $ip);
        try {
            sendMail(emails(settingsDoc($pdo)), '[The Vista An Phú] Góp ý mới: ' . $kind, "Loại: $kind\r\nTừ: " . (str($body, 'name', 100) ?: '(ẩn danh)') . ' ' . str($body, 'contact', 120) . "\r\n\r\n$msg\r\n\r\nXem tại tab \"Góp ý nhận được\" trên trang quản lý.");
        } catch (Throwable $e) {}
        try { githubIssue($cfg, $kind, $msg); } catch (Throwable $e) {}
        out(200, ['ok' => true]);
    }

    case 'comment_add': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $aid = (string)($body['assetId'] ?? '');
        if (!preg_match($ID_RE, $aid) || !getDoc($pdo, 'assets', $aid)) out(400, ['error' => 'bad_request']);
        $txt = str($body, 'text', 1000);
        if (mb_strlen($txt) < 2) out(400, ['error' => 'empty']);
        $uidc = $_SESSION['uid'] ?? null;
        $nm = $_SESSION['name'] ?? str($body, 'name', 60);
        if ($nm === '') $nm = 'Cư dân';
        if (hits($pdo, 'comment', $ip, 3600) >= 20) out(429, ['error' => 'too_many']);
        $pdo->prepare('INSERT INTO comments (asset_id, name, text, uid, ts) VALUES (?,?,?,?,?)')->execute([$aid, $nm, $txt, $uidc, time()]);
        addHit($pdo, 'comment', $ip);
        $row = $pdo->query('SELECT id, asset_id AS assetId, name, text, uid, ts FROM comments WHERE id = ' . (int)$pdo->lastInsertId())->fetch(PDO::FETCH_ASSOC);
        out(200, ['ok' => true, 'comment' => $row]);
    }

    case 'comment_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'manage');
        $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([(int)($body['id'] ?? 0)]);
        audit($pdo, 'xóa bình luận', 'comments', (string)(int)($body['id'] ?? 0));
        out(200, ['ok' => true]);
    }


    /* ----- căn hộ, gửi xe, biểu quyết ----- */
    case 'units_list': {
        needCap($role, 'seeall');
        out(200, ['items' => $pdo->query('SELECT id, code, block, area, owner, phone, note FROM units ORDER BY code')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'unit_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'manage');
        $code = normUnit(str($body, 'code', 20));
        if (!preg_match('/^[A-Z0-9._\/-]{1,20}$/', $code)) out(400, ['error' => 'bad_request']);
        $pdo->prepare('INSERT INTO units (id, code, block, area, owner, phone, note) VALUES (?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET block = excluded.block, area = excluded.area, owner = excluded.owner, phone = excluded.phone, note = excluded.note')
            ->execute([$code, $code, str($body, 'block', 20), max(0, (float)($body['area'] ?? 0)), str($body, 'owner', 100), str($body, 'phone', 30), str($body, 'note', 300)]);
        audit($pdo, 'lưu căn hộ', 'units', $code, $code);
        out(200, ['ok' => true]);
    }

    case 'units_import': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'govern');
        $rows = $body['rows'] ?? null;
        if (!is_array($rows) || count($rows) > 2000) out(400, ['error' => 'bad_request']);
        $n = 0;
        $pdo->beginTransaction();
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $code = normUnit(mb_substr((string)($r['code'] ?? ''), 0, 20));
            if (!preg_match('/^[A-Z0-9._\/-]{1,20}$/', $code)) continue;
            $pdo->prepare('INSERT INTO units (id, code, block, area, owner, phone, note) VALUES (?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET area = excluded.area, owner = excluded.owner, phone = excluded.phone')
                ->execute([$code, $code, mb_substr((string)($r['block'] ?? ''), 0, 20), max(0, (float)($r['area'] ?? 0)), mb_substr((string)($r['owner'] ?? ''), 0, 100), mb_substr((string)($r['phone'] ?? ''), 0, 30), '']);
            $n++;
        }
        $pdo->commit();
        audit($pdo, 'nhập danh sách căn hộ', 'units', '', "$n căn");
        out(200, ['ok' => true, 'count' => $n]);
    }

    case 'unit_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'govern');
        $id = normUnit((string)($body['id'] ?? ''));
        $pdo->prepare('DELETE FROM units WHERE id = ?')->execute([$id]);
        $pdo->prepare("UPDATE users SET unit = '' WHERE unit = ?")->execute([$id]);
        audit($pdo, 'xóa căn hộ', 'units', $id, $id);
        out(200, ['ok' => true]);
    }

    case 'my_unit': {
        need($role, 1);
        $u = myUnit($pdo);
        if ($u === '') out(200, ['unit' => null]);
        $s = $pdo->prepare('SELECT id, code, block, area, owner FROM units WHERE id = ?'); $s->execute([$u]);
        out(200, ['unit' => $s->fetch(PDO::FETCH_ASSOC) ?: null]);
    }

    case 'vehicles_list': {
        need($role, 1);
        if (can($role, 'seeall')) { $rows = $pdo->query('SELECT * FROM vehicles ORDER BY unit, created')->fetchAll(PDO::FETCH_ASSOC); }
        else {
            $u = myUnit($pdo);
            if ($u === '') out(200, ['items' => []]);
            $s = $pdo->prepare('SELECT * FROM vehicles WHERE unit = ? ORDER BY created'); $s->execute([$u]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        }
        out(200, ['items' => $rows]);
    }

    case 'vehicle_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $editor = can($role, 'manage');
        $unit = $editor ? normUnit((string)($body['unit'] ?? '')) : myUnit($pdo);
        if ($unit === '') out(403, ['error' => 'no_unit']);
        $q = $pdo->prepare('SELECT COUNT(*) FROM units WHERE id = ?'); $q->execute([$unit]);
        if (!(int)$q->fetchColumn()) out(400, ['error' => 'bad_unit']);
        $type = in_array($body['type'] ?? '', ['Xe máy', 'Ô tô', 'Xe đạp', 'Xe điện'], true) ? $body['type'] : 'Xe máy';
        $plate = strtoupper(preg_replace('/\s+/', '', str($body, 'plate', 20)));
        if ($type !== 'Xe đạp' && !preg_match('/^[A-Z0-9.\-]{4,15}$/', $plate)) out(400, ['error' => 'bad_plate']);
        if ($type === 'Xe đạp' && $plate === '') $plate = 'XE-DAP';
        $id = (string)($body['id'] ?? '');
        $old = null;
        if ($id !== '') { $s = $pdo->prepare('SELECT * FROM vehicles WHERE id = ?'); $s->execute([$id]); $old = $s->fetch(PDO::FETCH_ASSOC); if (!$old) out(404, ['error' => 'not_found']); }
        if ($old && !$editor && ($old['unit'] !== $unit || $old['status'] !== 'Chờ duyệt')) out(403, ['error' => 'forbidden']);
        if ($plate !== 'XE-DAP') { $d = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE plate = ? AND status IN ('Chờ duyệt','Đã duyệt') AND id <> ?"); $d->execute([$plate, $id]); if ((int)$d->fetchColumn() > 0) out(409, ['error' => 'plate_taken']); }
        $who = actor();
        $status = $editor ? (in_array($body['status'] ?? '', ['Chờ duyệt', 'Đã duyệt', 'Từ chối', 'Ngừng'], true) ? $body['status'] : ($old['status'] ?? 'Đã duyệt')) : 'Chờ duyệt';
        if ($old) {
            $pdo->prepare('UPDATE vehicles SET unit = ?, type = ?, plate = ?, owner = ?, status = ?, note = ? WHERE id = ?')->execute([$unit, $type, $plate, str($body, 'owner', 100), $status, str($body, 'note', 200), $id]);
        } else {
            $id = 'v' . bin2hex(random_bytes(5));
            $pdo->prepare('INSERT INTO vehicles (id, unit, type, plate, owner, status, note, created, by_name) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$id, $unit, $type, $plate, str($body, 'owner', 100), $status, str($body, 'note', 200), time(), $who['name']]);
        }
        audit($pdo, $old ? 'sửa xe gửi' : 'đăng ký xe gửi', 'vehicles', $id, "$unit · $type $plate ($status)");
        out(200, ['ok' => true, 'id' => $id]);
    }

    case 'vehicle_status': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'manage');
        $st = (string)($body['status'] ?? '');
        if (!in_array($st, ['Chờ duyệt', 'Đã duyệt', 'Từ chối', 'Ngừng'], true)) out(400, ['error' => 'bad_request']);
        $id = (string)($body['id'] ?? '');
        $pdo->prepare('UPDATE vehicles SET status = ? WHERE id = ?')->execute([$st, $id]);
        audit($pdo, 'đổi trạng thái xe gửi', 'vehicles', $id, $st);
        out(200, ['ok' => true]);
    }

    case 'vehicle_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $id = (string)($body['id'] ?? '');
        $s = $pdo->prepare('SELECT * FROM vehicles WHERE id = ?'); $s->execute([$id]);
        $v = $s->fetch(PDO::FETCH_ASSOC);
        if (!$v) out(200, ['ok' => true]);
        if (!can($role, 'manage') && ($v['unit'] !== myUnit($pdo) || $v['status'] !== 'Chờ duyệt')) out(403, ['error' => 'forbidden']);
        $pdo->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$id]);
        audit($pdo, 'xóa xe gửi', 'vehicles', $id, $v['unit'] . ' · ' . $v['type'] . ' ' . $v['plate']);
        out(200, ['ok' => true]);
    }

    case 'polls_list': {
        need($role, 1);
        $editor = can($role, 'seeall');
        $mine = myUnit($pdo);
        $totUnits = (int)$pdo->query('SELECT COUNT(*) FROM units')->fetchColumn();
        $totArea = (float)$pdo->query('SELECT COALESCE(SUM(area),0) FROM units')->fetchColumn();
        $items = [];
        foreach ($pdo->query('SELECT * FROM polls ORDER BY created DESC')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $opts = json_decode($p['options'], true) ?: [];
            $open = pollOpen($p);
            $tally = array_fill_keys($opts, ['units' => 0, 'area' => 0.0]);
            $voted = 0; $votedArea = 0.0;
            foreach ($pdo->query("SELECT b.choice, COALESCE(u.area,0) AS area FROM ballots b LEFT JOIN units u ON u.id = b.unit WHERE b.poll = " . $pdo->quote($p['id'])) as $b) {
                $voted++; $votedArea += (float)$b['area'];
                if (isset($tally[$b['choice']])) { $tally[$b['choice']]['units']++; $tally[$b['choice']]['area'] += (float)$b['area']; }
            }
            $myChoice = null;
            if ($mine !== '') { $q = $pdo->prepare('SELECT choice FROM ballots WHERE poll = ? AND unit = ?'); $q->execute([$p['id'], $mine]); $myChoice = $q->fetchColumn() ?: null; }
            $show = $editor || !$open;
            $items[] = ['id' => $p['id'], 'title' => $p['title'], 'body' => $p['body'], 'options' => $opts, 'deadline' => $p['deadline'], 'closed' => (int)$p['closed'], 'impl' => (string)($p['impl'] ?? ''), 'implNote' => (string)($p['impl_note'] ?? ''), 'open' => $open, 'created' => (int)$p['created'], 'byName' => $p['by_name'],
                'voted' => $voted, 'votedArea' => $votedArea, 'tally' => $show ? $tally : null, 'myChoice' => $myChoice];
        }
        out(200, ['items' => $items, 'myUnit' => $mine, 'totalUnits' => $totUnits, 'totalArea' => $totArea]);
    }

    case 'poll_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'govern');
        $title = str($body, 'title', 200);
        $opts = [];
        foreach ((array)($body['options'] ?? []) as $o) { $o = mb_substr(trim((string)$o), 0, 100); if ($o !== '' && !in_array($o, $opts, true)) $opts[] = $o; }
        $dl = (string)($body['deadline'] ?? '');
        if ($title === '' || count($opts) < 2 || count($opts) > 8 || ($dl !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dl))) out(400, ['error' => 'bad_request']);
        $id = (string)($body['id'] ?? '');
        $who = actor();
        if ($id !== '') {
            $has = $pdo->prepare('SELECT COUNT(*) FROM ballots WHERE poll = ?'); $has->execute([$id]);
            if ((int)$has->fetchColumn() > 0) $pdo->prepare('UPDATE polls SET title = ?, body = ?, deadline = ? WHERE id = ?')->execute([$title, str($body, 'body', 3000), $dl, $id]);
            else $pdo->prepare('UPDATE polls SET title = ?, body = ?, options = ?, deadline = ? WHERE id = ?')->execute([$title, str($body, 'body', 3000), json_encode($opts, JSON_UNESCAPED_UNICODE), $dl, $id]);
            audit($pdo, 'sửa nội dung biểu quyết', 'polls', $id, $title);
        } else {
            $id = 'p' . bin2hex(random_bytes(5));
            $pdo->prepare('INSERT INTO polls (id, title, body, options, deadline, closed, created, by_name) VALUES (?,?,?,?,?,?,?,?)')->execute([$id, $title, str($body, 'body', 3000), json_encode($opts, JSON_UNESCAPED_UNICODE), $dl, 0, time(), $who['name']]);
            audit($pdo, 'tạo biểu quyết', 'polls', $id, $title);
        }
        out(200, ['ok' => true, 'id' => $id]);
    }

    case 'poll_close': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'govern');
        $id = (string)($body['id'] ?? '');
        $pdo->prepare('UPDATE polls SET closed = ? WHERE id = ?')->execute([!empty($body['closed']) ? 1 : 0, $id]);
        audit($pdo, !empty($body['closed']) ? 'chốt biểu quyết' : 'mở lại biểu quyết', 'polls', $id);
        out(200, ['ok' => true]);
    }

    case 'poll_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'govern');
        $id = (string)($body['id'] ?? '');
        $pdo->prepare('DELETE FROM ballots WHERE poll = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM polls WHERE id = ?')->execute([$id]);
        audit($pdo, 'xóa biểu quyết', 'polls', $id);
        out(200, ['ok' => true]);
    }

    case 'ballots_list': {
        needCap($role, 'seeall');
        $s = $pdo->prepare('SELECT b.unit, b.choice, b.ts, b.by_name, u.area FROM ballots b LEFT JOIN units u ON u.id = b.unit WHERE b.poll = ? ORDER BY b.unit');
        $s->execute([(string)($_GET['poll'] ?? '')]);
        out(200, ['items' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'ballot_cast': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $editor = can($role, 'manage');
        $unit = $editor ? normUnit((string)($body['unit'] ?? '')) : myUnit($pdo);
        if ($unit === '') out(403, ['error' => 'no_unit']);
        $q = $pdo->prepare('SELECT COUNT(*) FROM units WHERE id = ?'); $q->execute([$unit]);
        if (!(int)$q->fetchColumn()) out(400, ['error' => 'bad_unit']);
        $s = $pdo->prepare('SELECT * FROM polls WHERE id = ?'); $s->execute([(string)($body['poll'] ?? '')]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) out(404, ['error' => 'not_found']);
        if (!pollOpen($p)) out(409, ['error' => 'poll_closed']);
        $choice = (string)($body['choice'] ?? '');
        if (!in_array($choice, json_decode($p['options'], true) ?: [], true)) out(400, ['error' => 'bad_request']);
        $who = actor();
        $pdo->prepare('INSERT INTO ballots (poll, unit, choice, ts, by_name) VALUES (?,?,?,?,?) ON CONFLICT(poll, unit) DO UPDATE SET choice = excluded.choice, ts = excluded.ts, by_name = excluded.by_name')->execute([$p['id'], $unit, $choice, time(), $who['name']]);
        audit($pdo, $editor ? 'nhập phiếu biểu quyết' : 'biểu quyết', 'polls', $p['id'], $p['title'] . ' · căn ' . $unit . ' · ' . $choice);
        out(200, ['ok' => true]);
    }


    case 'poll_impl': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'govern');
        $st = (string)($body['impl'] ?? '');
        if (!in_array($st, ['', 'Chưa thực hiện', 'Đang thực hiện', 'Đã thực hiện'], true)) out(400, ['error' => 'bad_request']);
        $id = (string)($body['id'] ?? '');
        $pdo->prepare('UPDATE polls SET impl = ?, impl_note = ? WHERE id = ?')->execute([$st, str($body, 'note', 1000), $id]);
        audit($pdo, 'cập nhật tiến độ thực hiện nghị quyết', 'polls', $id, $st);
        out(200, ['ok' => true]);
    }

    /* ----- tiện ích và đặt chỗ ----- */
    case 'facilities_list': {
        need($role, 1);
        out(200, ['items' => $pdo->query('SELECT id, name, kind, rules, open_from AS openFrom, open_to AS openTo, auto, active FROM facilities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'facility_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'facility');
        $name = str($body, 'name', 100);
        $of = (string)($body['openFrom'] ?? '06:00'); $ot = (string)($body['openTo'] ?? '22:00');
        if ($name === '' || !preg_match('/^\d{2}:\d{2}$/', $of) || !preg_match('/^\d{2}:\d{2}$/', $ot) || $of >= $ot) out(400, ['error' => 'bad_request']);
        $id = (string)($body['id'] ?? '');
        $vals = [$name, str($body, 'kind', 40), str($body, 'rules', 1000), $of, $ot, !empty($body['auto']) ? 1 : 0, array_key_exists('active', $body) && empty($body['active']) ? 0 : 1];
        if ($id !== '') $pdo->prepare('UPDATE facilities SET name = ?, kind = ?, rules = ?, open_from = ?, open_to = ?, auto = ?, active = ? WHERE id = ?')->execute(array_merge($vals, [$id]));
        else { $id = 'f' . bin2hex(random_bytes(4)); $pdo->prepare('INSERT INTO facilities (name, kind, rules, open_from, open_to, auto, active, id) VALUES (?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$id])); }
        audit($pdo, 'lưu tiện ích', 'facilities', $id, $name);
        out(200, ['ok' => true, 'id' => $id]);
    }

    case 'facility_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'facility');
        $id = (string)($body['id'] ?? '');
        $pdo->prepare('DELETE FROM bookings WHERE facility = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM facilities WHERE id = ?')->execute([$id]);
        audit($pdo, 'xóa tiện ích', 'facilities', $id);
        out(200, ['ok' => true]);
    }

    case 'bookings_list': {
        need($role, 1);
        $editor = can($role, 'seeall'); $mine = myUnit($pdo);
        $from = date('Y-m-d', strtotime('-30 days'));
        $s = $pdo->prepare('SELECT id, facility, unit, date, start, end, status, note, created, by_name AS byName FROM bookings WHERE date >= ? ORDER BY date, start'); $s->execute([$from]);
        $items = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $b) {
            if ($editor || ($mine !== '' && $b['unit'] === $mine)) { $b['mine'] = $mine !== '' && $b['unit'] === $mine; $items[] = $b; }
            elseif (in_array($b['status'], ['Chờ duyệt', 'Đã duyệt'], true)) $items[] = ['id' => $b['id'], 'facility' => $b['facility'], 'date' => $b['date'], 'start' => $b['start'], 'end' => $b['end'], 'status' => $b['status'], 'unit' => '', 'mine' => false];
        }
        out(200, ['items' => $items, 'myUnit' => $mine]);
    }

    case 'booking_save': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $editor = can($role, 'manage');
        $unit = $editor ? normUnit((string)($body['unit'] ?? '')) : myUnit($pdo);
        if ($unit === '') out(403, ['error' => 'no_unit']);
        $q = $pdo->prepare('SELECT COUNT(*) FROM units WHERE id = ?'); $q->execute([$unit]);
        if (!(int)$q->fetchColumn()) out(400, ['error' => 'bad_unit']);
        $fs = $pdo->prepare('SELECT * FROM facilities WHERE id = ? AND active = 1'); $fs->execute([(string)($body['facility'] ?? '')]);
        $f = $fs->fetch(PDO::FETCH_ASSOC);
        if (!$f) out(404, ['error' => 'not_found']);
        $date = (string)($body['date'] ?? ''); $st = (string)($body['start'] ?? ''); $en = (string)($body['end'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $en) || $st >= $en) out(400, ['error' => 'bad_request']);
        if (!$editor && ($date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+60 days')))) out(400, ['error' => 'bad_date']);
        if ($st < $f['open_from'] || $en > $f['open_to']) out(400, ['error' => 'out_of_hours']);
        $c = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE facility = ? AND date = ? AND status IN ('Chờ duyệt','Đã duyệt') AND start < ? AND end > ?");
        $c->execute([$f['id'], $date, $en, $st]);
        if ((int)$c->fetchColumn() > 0) out(409, ['error' => 'slot_taken']);
        if (!$editor && hits($pdo, 'booking', $ip, 3600) >= 15) out(429, ['error' => 'too_many']);
        $id = 'b' . bin2hex(random_bytes(5));
        $status = ($editor || (int)$f['auto'] === 1) ? 'Đã duyệt' : 'Chờ duyệt';
        $who = actor();
        $pdo->prepare('INSERT INTO bookings (id, facility, unit, date, start, end, status, note, created, by_name) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id, $f['id'], $unit, $date, $st, $en, $status, str($body, 'note', 200), time(), $who['name']]);
        addHit($pdo, 'booking', $ip);
        audit($pdo, 'đặt tiện ích', 'bookings', $id, $f['name'] . " · căn $unit · $date $st-$en ($status)");
        out(200, ['ok' => true, 'status' => $status]);
    }

    case 'booking_status': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'manage');
        $stt = (string)($body['status'] ?? '');
        if (!in_array($stt, ['Chờ duyệt', 'Đã duyệt', 'Từ chối', 'Đã hủy', 'Hoàn thành'], true)) out(400, ['error' => 'bad_request']);
        $id = (string)($body['id'] ?? '');
        $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$stt, $id]);
        audit($pdo, 'đổi trạng thái đặt tiện ích', 'bookings', $id, $stt);
        out(200, ['ok' => true]);
    }

    case 'booking_cancel': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $id = (string)($body['id'] ?? '');
        $s = $pdo->prepare('SELECT * FROM bookings WHERE id = ?'); $s->execute([$id]);
        $b = $s->fetch(PDO::FETCH_ASSOC);
        if (!$b) out(404, ['error' => 'not_found']);
        if (!can($role, 'manage') && $b['unit'] !== myUnit($pdo)) out(403, ['error' => 'forbidden']);
        $pdo->prepare("UPDATE bookings SET status = 'Đã hủy' WHERE id = ?")->execute([$id]);
        audit($pdo, 'hủy đặt tiện ích', 'bookings', $id, $b['date'] . ' ' . $b['start']);
        out(200, ['ok' => true]);
    }

    case 'feedback_list': {
        needCap($role, 'manage');
        out(200, ['items' => $pdo->query('SELECT id, kind, message, name, contact, created FROM feedback ORDER BY id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'feedback_del': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        needCap($role, 'manage');
        $pdo->prepare('DELETE FROM feedback WHERE id = ?')->execute([(int)($body['id'] ?? 0)]);
        out(200, ['ok' => true]);
    }

    case 'upload': {
        if ($method !== 'POST') out(405, ['error' => 'method']);
        need($role, 1);
        $col = (string)($_POST['col'] ?? ''); $ref = (string)($_POST['id'] ?? '');
        needCap($role, $col === 'txns' ? 'txn' : 'manage');
        if (!in_array($col, ['txns', 'maints', 'assets', 'vendors', 'notices'], true) || !preg_match($ID_RE, $ref) || !getDoc($pdo, $col, $ref)) out(400, ['error' => 'bad_request']);
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
        if ($r['ref_col'] === 'vendors') needCap($role, 'seeall');
        if ($r['ref_col'] === 'txns') needCap($role, 'fund');
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
        need($role, 1);
        $s = $pdo->prepare('SELECT * FROM files WHERE id = ?'); $s->execute([(string)($body['id'] ?? '')]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) out(404, ['error' => 'not_found']);
        needCap($role, $r['ref_col'] === 'txns' ? 'txn' : 'manage');
        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$r['id']]);
        @unlink($dir . '/files/' . $r['id']);
        audit($pdo, 'xóa chứng từ', $r['ref_col'], $r['ref_id'], $r['name']);
        out(200, ['ok' => true]);
    }

    default:
        out(404, ['error' => 'unknown']);
}
