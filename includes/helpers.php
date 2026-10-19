<?php
declare(strict_types=1);

// ---------------------------------------------------------------- مخرجات وروابط
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $f = ECM_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($f) ? filemtime($f) : 1;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $path): never
{
    if (!preg_match('#^https?://#', $path)) {
        $path = url($path);
    }
    header('Location: ' . $path);
    exit;
}

function abort(int $code, string $msg = ''): never
{
    http_response_code($code);
    $titles = [403 => 'غير مصرح', 404 => 'غير موجود', 419 => 'انتهت صلاحية الطلب', 429 => 'محاولات كثيرة', 400 => 'طلب غير صالح'];
    $title  = $titles[$code] ?? 'خطأ';
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg ?: $title], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && current_user()) {
        render_header($title);
        echo '<div class="ecm-card text-center py-5"><div class="display-5 mb-2 text-warning"><i class="bi bi-shield-exclamation"></i></div><h4>' . e($title) . '</h4><p class="text-muted">' . e($msg ?: 'لا تملك صلاحية تنفيذ هذه العملية.') . '</p><a class="btn btn-outline-secondary" href="javascript:history.back()">رجوع</a></div>';
        render_footer();
        exit;
    }
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>' . e($title) . '</title><body style="font-family:Tahoma,Arial;background:#fbf7ef;padding:3rem;text-align:center"><h2>' . e($title) . '</h2><p>' . e($msg) . '</p><a href="' . e(url('login.php')) . '">تسجيل الدخول</a></body></html>';
    exit;
}

function wants_json(): bool
{
    return stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || defined('ECM_JSON');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------- مدخلات
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post(string $k, $d = ''): string
{
    $v = $_POST[$k] ?? $d;
    return is_array($v) ? '' : trim((string)$v);
}

function get(string $k, $d = ''): string
{
    $v = $_GET[$k] ?? $d;
    return is_array($v) ? '' : trim((string)$v);
}

function post_int(string $k): int
{
    return (int)post($k, '0');
}

function get_int(string $k): int
{
    return (int)get($k, '0');
}

function post_arr(string $k): array
{
    return is_array($_POST[$k] ?? null) ? $_POST[$k] : [];
}

function old(string $k, $default = '')
{
    return $_POST[$k] ?? $default;
}

function valid_date(string $s): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4));
}

function valid_time(string $s): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s);
}

function norm_digits(string $s): string
{
    return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.', '،' => ',']);
}

// ---------------------------------------------------------------- المبالغ (كل الحسابات بالهللات/القروش كأعداد صحيحة)
function parse_money(string $s): ?int
{
    $s = str_replace([',', ' '], '', norm_digits(trim($s)));
    if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return (int)round(((float)$s) * 100);
}

function to_cents($v): int
{
    return (int)round(((float)($v ?? 0)) * 100);
}

function cents_str(int $c): string
{
    return ($c < 0 ? '-' : '') . intdiv(abs($c), 100) . '.' . str_pad((string)(abs($c) % 100), 2, '0', STR_PAD_LEFT);
}

function money($v): string
{
    $n = is_int($v) ? $v / 100 : (float)$v;
    return number_format($n, 2) . ' ' . setting('currency', 'جنيه');
}

function money_c(int $c): string
{
    return money($c);
}

// ---------------------------------------------------------------- التواريخ
function today(): string
{
    return date('Y-m-d');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function fdate(?string $d): string
{
    return $d ? date('Y/m/d', strtotime($d)) : '—';
}

function fdatetime(?string $d): string
{
    return $d ? date('Y/m/d H:i', strtotime($d)) : '—';
}

function ftime(?string $t): string
{
    return $t ? substr($t, 0, 5) : '';
}

function weekday_names(): array
{
    return [6 => 'السبت', 7 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة'];
}

function weekday_name(int $iso): string
{
    return weekday_names()[$iso] ?? '';
}

function month_start(string $ym): string
{
    return $ym . '-01';
}

function month_end(string $ym): string
{
    return date('Y-m-t', strtotime($ym . '-01'));
}

// ---------------------------------------------------------------- الإعدادات
function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        try {
            foreach (rows('SELECT setting_key, setting_value FROM settings') as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

function setting(string $k, $default = '')
{
    $a = settings_all();
    return array_key_exists($k, $a) && $a[$k] !== null && $a[$k] !== '' ? $a[$k] : $default;
}

function save_setting(string $k, string $v): void
{
    q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]);
    settings_all(true);
}

function payment_methods(bool $onlyEnabled = true): array
{
    $list = json_decode((string)setting('payment_methods', '[]'), true) ?: [];
    $out = [];
    foreach ($list as $m) {
        if (!$onlyEnabled || !empty($m['enabled'])) {
            $out[$m['code']] = $m;
        }
    }
    return $out;
}

function payment_method_label(string $code): string
{
    return payment_methods(false)[$code]['label'] ?? $code;
}

// ---------------------------------------------------------------- رسائل الجلسة
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

function flash_render(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $cls = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'][$f['t']] ?? 'info';
        $ico = ['success' => 'check-circle', 'error' => 'x-octagon', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'][$f['t']] ?? 'info-circle';
        $out .= '<div class="alert alert-' . $cls . ' ecm-alert d-flex align-items-center gap-2" role="alert"><i class="bi bi-' . $ico . '"></i><div>' . e($f['m']) . '</div></div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

// ---------------------------------------------------------------- التدقيق
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

function audit(string $action, ?string $entityType = null, ?int $entityId = null, $details = null): void
{
    try {
        if (is_array($details)) {
            foreach ($details as $k => $v) {
                if (preg_match('/pass|token|secret/i', (string)$k)) {
                    unset($details[$k]);
                }
            }
            $details = json_encode($details, JSON_UNESCAPED_UNICODE);
        }
        $u = function_exists('current_user') ? current_user() : null;
        q('INSERT INTO audit_logs (user_id, username, action, entity_type, entity_id, details, ip) VALUES (?,?,?,?,?,?,?)',
            [$u['id'] ?? null, $u['username'] ?? null, $action, $entityType, $entityId, $details, client_ip()]);
    } catch (Throwable $e) {
        error_log('[ECM] audit failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------- هاتف / واتساب
function phone_digits(?string $p): string
{
    return preg_replace('/\D+/', '', norm_digits((string)$p)) ?? '';
}

function wa_number(?string $phone): ?string
{
    $d = phone_digits($phone);
    if ($d === '') {
        return null;
    }
    if (strpos($d, '00') === 0) {
        $d = substr($d, 2);
    } elseif ($d[0] === '0') {
        $cc = phone_digits((string)setting('phone_country_code', ''));
        if ($cc === '') {
            return null;
        }
        $d = $cc . substr($d, 1);
    }
    return (strlen($d) >= 9 && strlen($d) <= 15) ? $d : null;
}

function wa_link(?string $phone, string $text): ?string
{
    $n = wa_number($phone);
    return $n ? 'https://wa.me/' . $n . '?text=' . rawurlencode($text) : null;
}

// ---------------------------------------------------------------- قوائم الحالات
function student_statuses(): array
{
    return ['active' => 'نشط', 'suspended' => 'موقوف', 'withdrawn' => 'منسحب'];
}

function group_statuses(): array
{
    return ['active' => 'نشطة', 'paused' => 'متوقفة مؤقتاً', 'finished' => 'منتهية'];
}

function attendance_statuses(): array
{
    return ['present' => 'حاضر', 'absent' => 'غائب', 'late' => 'متأخر', 'excused' => 'غياب بعذر'];
}

function session_statuses(): array
{
    return ['scheduled' => 'مجدولة', 'held' => 'عُقدت', 'cancelled' => 'ملغاة'];
}

function invoice_statuses(): array
{
    return ['open' => 'مستحقة', 'partial' => 'مدفوعة جزئياً', 'paid' => 'مدفوعة', 'void' => 'ملغاة'];
}

function mode_labels(): array
{
    return ['monthly' => 'شهري ثابت', 'per_session' => 'حسب الحصص'];
}

function comp_type_labels(): array
{
    return ['none' => 'غير محدد', 'fixed_monthly' => 'مبلغ شهري ثابت', 'per_session' => 'حسب الحصة'];
}

function status_class(string $s): string
{
    return ['active' => 'success', 'present' => 'success', 'paid' => 'success', 'held' => 'success', 'valid' => 'success',
        'suspended' => 'warning', 'late' => 'warning', 'partial' => 'warning', 'paused' => 'warning', 'excused' => 'info', 'scheduled' => 'info', 'open' => 'warning',
        'withdrawn' => 'secondary', 'finished' => 'secondary', 'inactive' => 'secondary', 'left' => 'secondary',
        'absent' => 'danger', 'cancelled' => 'danger', 'void' => 'danger', 'voided' => 'danger', 'revoked' => 'danger', 'disabled' => 'danger'][$s] ?? 'secondary';
}

function badge(string $text, string $class = 'secondary'): string
{
    return '<span class="badge ecm-badge bg-' . e($class) . '-subtle text-' . e($class) . '-emphasis">' . e($text) . '</span>';
}

function status_badge(string $status, array $labels): string
{
    return badge($labels[$status] ?? $status, status_class($status));
}

// ---------------------------------------------------------------- ترقيم الصفحات
function paginate(string $countSql, string $sql, array $params, int $perPage = 20): array
{
    $total = (int)val($countSql, $params);
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, get_int('page')), $pages);
    $off   = ($page - 1) * $perPage;
    $data  = rows($sql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$off, $params);
    return ['rows' => $data, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $perPage];
}

function query_with(array $override = []): string
{
    $q = array_merge($_GET, $override);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return '?' . http_build_query($q);
}

function pager(array $p): string
{
    if ($p['pages'] <= 1) {
        return '<div class="text-muted small mt-2">الإجمالي: ' . (int)$p['total'] . '</div>';
    }
    $h = '<nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3"><div class="text-muted small">الإجمالي: ' . (int)$p['total'] . ' — صفحة ' . $p['page'] . ' من ' . $p['pages'] . '</div><ul class="pagination pagination-sm mb-0">';
    $from = max(1, $p['page'] - 2);
    $to   = min($p['pages'], $p['page'] + 2);
    if ($p['page'] > 1) {
        $h .= '<li class="page-item"><a class="page-link" href="' . e(query_with(['page' => $p['page'] - 1])) . '">السابق</a></li>';
    }
    for ($i = $from; $i <= $to; $i++) {
        $h .= '<li class="page-item' . ($i === $p['page'] ? ' active' : '') . '"><a class="page-link" href="' . e(query_with(['page' => $i])) . '">' . $i . '</a></li>';
    }
    if ($p['page'] < $p['pages']) {
        $h .= '<li class="page-item"><a class="page-link" href="' . e(query_with(['page' => $p['page'] + 1])) . '">التالي</a></li>';
    }
    return $h . '</ul></nav>';
}

// ---------------------------------------------------------------- CSV (UTF-8 مع BOM ليتوافق مع Excel)
function csv_download(string $filename, array $headers, array $rows): never
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ',', '"', '\\');
    foreach ($rows as $r) {
        // حماية من حقن الصيغ في Excel
        $r = array_map(fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) ? "'" . $v : $v, array_values($r));
        fputcsv($out, $r, ',', '"', '\\');
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------- قوالب الرسائل
function fill_template(string $tpl, array $vars): string
{
    $map = [];
    foreach ($vars as $k => $v) {
        $map['{' . $k . '}'] = (string)$v;
    }
    return strtr($tpl, $map);
}
