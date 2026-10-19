<?php
declare(strict_types=1);

const LOGIN_MAX_FAILS   = 5;
const LOGIN_WINDOW_MIN  = 15;

function current_user(): ?array
{
    static $cache = [];
    $uid = (int)($_SESSION['uid'] ?? 0);
    if (array_key_exists($uid, $cache)) {
        return $cache[$uid];
    }
    if ($uid === 0) {
        return $cache[0] = null;
    }
    // انتهاء الجلسة بعد فترة خمول
    $idle = (int)setting('session_timeout_minutes', 120) * 60;
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > $idle) {
        logout_user(false);
        return $cache[$uid] = null;
    }
    $_SESSION['last_seen'] = time();
    $u = row("SELECT id, username, full_name, email, status, teacher_id FROM users WHERE id = ? AND status = 'active'", [$uid]);
    if (!$u) {
        logout_user(false);
        return $cache[$uid] = null;
    }
    $u['perms'] = array_column(rows('SELECT DISTINCT p.code FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ?', [$uid]), 'code');
    $u['roles'] = array_column(rows('SELECT r.label FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$uid]), 'label');
    return $cache[$uid] = $u;
}

function uid(): int
{
    return (int)(current_user()['id'] ?? 0);
}

function can(string $perm): bool
{
    $u = current_user();
    return $u !== null && in_array($perm, $u['perms'], true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        if (wants_json()) {
            abort(401, 'انتهت الجلسة. سجّل الدخول مجدداً.');
        }
        redirect('login.php');
    }
    return $u;
}

/** التحقق الخادمي من الصلاحية — يُستدعى في أول كل صفحة/عملية */
function require_perm(string ...$perms): array
{
    $u = require_login();
    foreach ($perms as $p) {
        if (in_array($p, $u['perms'], true)) {
            return $u;
        }
    }
    audit('access_denied', null, null, ['page' => $_SERVER['SCRIPT_NAME'] ?? '', 'need' => $perms]);
    abort(403, 'لا تملك صلاحية الوصول إلى هذه الصفحة أو العملية.');
}

function login_blocked(string $username, string $ip): bool
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MIN * 60);
    $byUser = (int)val('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at >= ?', [$username, $since]);
    $byIp   = (int)val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at >= ?', [$ip, $since]);
    return $byUser >= LOGIN_MAX_FAILS || $byIp >= LOGIN_MAX_FAILS * 4;
}

/** @return array{ok:bool,msg:string} */
function attempt_login(string $username, string $password): array
{
    $ip = client_ip();
    $username = mb_substr($username, 0, 60);
    if (login_blocked($username, $ip)) {
        return ['ok' => false, 'msg' => 'تم تجاوز عدد المحاولات المسموح. حاول بعد ' . LOGIN_WINDOW_MIN . ' دقيقة.'];
    }
    $u = row('SELECT id, password_hash, status FROM users WHERE username = ?', [$username]);
    // نفّذ التحقق دائماً لتقليل الفروق الزمنية بين وجود المستخدم وعدمه
    $hash = $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuG3Hh3wVd9QmJ1b1lq3kq8d1b7v8m1xe';
    $ok = password_verify($password, $hash) && $u && $u['status'] === 'active';
    q('INSERT INTO login_attempts (username, ip, success) VALUES (?,?,?)', [$username, $ip, $ok ? 1 : 0]);
    if (!$ok) {
        audit('login_failed', 'user', null, ['username' => $username]);
        return ['ok' => false, 'msg' => 'اسم المستخدم أو كلمة المرور غير صحيحة.'];
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$u['id']]);
    q('DELETE FROM login_attempts WHERE username = ? AND success = 0', [$username]);
    audit('login', 'user', (int)$u['id']);
    return ['ok' => true, 'msg' => ''];
}

function logout_user(bool $log = true): void
{
    if ($log && !empty($_SESSION['uid'])) {
        audit('logout', 'user', (int)$_SESSION['uid']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function password_policy_error(string $pw): ?string
{
    if (mb_strlen($pw) < 10) {
        return 'كلمة المرور يجب ألا تقل عن 10 أحرف.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'كلمة المرور يجب أن تحتوي على أحرف وأرقام.';
    }
    return null;
}

// ---------------------------------------------------------------- نطاق المجموعات (المدرس يرى مجموعاته فقط)
/** null = كل المجموعات، وإلا مصفوفة معرفات */
function group_scope(): ?array
{
    static $cache = false;
    if ($cache !== false && ($cache['uid'] ?? 0) === uid()) {
        return $cache['v'];
    }
    $u = current_user();
    if (!$u || can('groups.view_all')) {
        $v = null;
    } elseif ($u['teacher_id']) {
        $v = array_map('intval', array_column(rows('SELECT id FROM class_groups WHERE teacher_id = ?', [$u['teacher_id']]), 'id'));
    } else {
        $v = [];
    }
    $cache = ['uid' => uid(), 'v' => $v];
    return $v;
}

/** شرط SQL يقيّد عمود معرّف المجموعة بحسب النطاق: [sql, params] */
function group_scope_sql(string $col): array
{
    $s = group_scope();
    if ($s === null) {
        return ['', []];
    }
    if (!$s) {
        return [' AND 1=0', []];
    }
    return [" AND $col IN (" . implode(',', array_fill(0, count($s), '?')) . ')', $s];
}

function student_scope_sql(string $alias = 's'): array
{
    $s = group_scope();
    if ($s === null) {
        return ['', []];
    }
    if (!$s) {
        return [' AND 1=0', []];
    }
    return [" AND EXISTS (SELECT 1 FROM group_students gsx WHERE gsx.student_id = $alias.id AND gsx.group_id IN (" . implode(',', array_fill(0, count($s), '?')) . '))', $s];
}

function require_group_access(int $groupId): void
{
    $s = group_scope();
    if ($s !== null && !in_array($groupId, $s, true)) {
        audit('access_denied', 'group', $groupId);
        abort(403, 'هذه المجموعة ليست ضمن صلاحياتك.');
    }
}

function require_student_access(int $studentId): void
{
    [$sql, $p] = student_scope_sql('s');
    if (!row("SELECT s.id FROM students s WHERE s.id = ? $sql", array_merge([$studentId], $p))) {
        audit('access_denied', 'student', $studentId);
        abort(403, 'هذا الطالب ليس ضمن صلاحياتك.');
    }
}
