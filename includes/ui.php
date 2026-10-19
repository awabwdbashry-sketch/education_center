<?php
declare(strict_types=1);

/** عناصر القائمة الجانبية: [label, url, icon, permissions(any), match-dir] */
function nav_items(): array
{
    $exams = setting('exams_enabled', '0') === '1';
    $groups = [
        'الرئيسية' => [
            ['لوحة التحكم', 'index.php', 'speedometer2', ['dashboard.view'], 'index.php'],
        ],
        'الأكاديمي' => [
            ['الطلاب', 'modules/students/index.php', 'people', ['students.view'], 'modules/students'],
            ['المدرسون', 'modules/teachers/index.php', 'person-workspace', ['teachers.view'], 'modules/teachers'],
            ['المجموعات', 'modules/groups/index.php', 'collection', ['academics.view'], 'modules/groups'],
            ['المواد والمراحل', 'modules/academics/subjects.php', 'journal-bookmark', ['academics.manage'], 'modules/academics'],
            ['الحصص والتقويم', 'modules/sessions/calendar.php', 'calendar3', ['academics.view'], 'modules/sessions'],
            ...($exams ? [['الاختبارات', 'modules/exams/index.php', 'clipboard-check', ['exams.view'], 'modules/exams']] : []),
        ],
        'الحضور' => [
            ['تسجيل الحضور', 'modules/attendance/mark.php', 'check2-square', ['attendance.view', 'attendance.mark'], 'modules/attendance/mark'],
            ['مسح QR', 'modules/attendance/scan.php', 'qr-code-scan', ['attendance.mark'], 'modules/attendance/scan'],
        ],
        'المالية' => [
            ['التحصيل', 'modules/cashier/collect.php', 'cash-coin', ['payments.collect'], 'modules/cashier/collect'],
            ['الدفعات والإيصالات', 'modules/cashier/payments.php', 'receipt', ['billing.view', 'payments.collect'], 'modules/cashier/payments'],
            ['الاشتراكات', 'modules/billing/subscriptions.php', 'card-checklist', ['billing.view'], 'modules/billing/subscriptions'],
            ['الفواتير', 'modules/billing/invoices.php', 'file-earmark-text', ['billing.view'], 'modules/billing/invoice'],
            ['توليد الاستحقاقات', 'modules/billing/generate.php', 'arrow-repeat', ['billing.manage'], 'modules/billing/generate'],
            ['المصروفات', 'modules/finance/expenses.php', 'wallet2', ['expenses.view'], 'modules/finance/expenses'],
            ['مستحقات المدرسين', 'modules/finance/teacher_pay.php', 'cash-stack', ['teacher_pay.view'], 'modules/finance/teacher_pay'],
        ],
        'المتابعة' => [
            ['التواصل مع أولياء الأمور', 'modules/comms/index.php', 'chat-dots', ['comms.view'], 'modules/comms'],
            ['التقارير', 'modules/reports/index.php', 'bar-chart-line', ['reports.view', 'reports.finance'], 'modules/reports'],
            ['التنبيهات', 'modules/notifications/index.php', 'bell', ['notifications.view'], 'modules/notifications'],
        ],
        'الإدارة' => [
            ['المستخدمون والصلاحيات', 'modules/users/index.php', 'shield-lock', ['users.manage'], 'modules/users'],
            ['الإعدادات', 'modules/settings/index.php', 'gear', ['settings.manage'], 'modules/settings/index'],
            ['النسخ الاحتياطي', 'modules/settings/backup.php', 'database-down', ['backup.manage'], 'modules/settings/backup'],
            ['سجل العمليات', 'modules/settings/audit.php', 'journal-text', ['audit.view'], 'modules/settings/audit'],
        ],
    ];
    return $groups;
}

function unread_notifications_count(): int
{
    static $n = null;
    if ($n === null) {
        try {
            $n = (int)val('SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND resolved_at IS NULL');
        } catch (Throwable $e) {
            $n = 0;
        }
    }
    return $n;
}

/** @param array{print?:bool,charts?:bool,qr?:bool,scanner?:bool,body?:string} $o */
function render_header(string $title, array $o = []): void
{
    $u = current_user();
    $print = !empty($o['print']);
    $center = (string)setting('center_name', 'السنتر التعليمي');
    $logo = (string)setting('center_logo', '');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $rel = ltrim(substr($script, strlen(base_path())), '/');
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
    echo '<title>' . e($title) . ' — ' . e($center) . '</title>';
    echo '<link rel="stylesheet" href="' . e(asset('vendor/bootstrap/bootstrap.rtl.min.css')) . '">';
    echo '<link rel="stylesheet" href="' . e(asset('vendor/icons/bootstrap-icons.min.css')) . '">';
    echo '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">';
    echo '</head><body class="' . ($print ? 'print-layout' : 'app-layout') . '">';
    $GLOBALS['__ecm_opts'] = $o;
    if ($print) {
        $GLOBALS['__ecm_print'] = true;
        echo '<main class="print-wrap">' . flash_render();
        return;
    }
    echo '<div class="ecm-shell"><aside class="ecm-sidebar" id="sidebar"><div class="ecm-brand">';
    echo $logo ? '<img src="' . e(url('file.php?f=' . rawurlencode($logo))) . '" alt="" class="ecm-logo">' : '<span class="ecm-logo-ph"><i class="bi bi-mortarboard-fill"></i></span>';
    echo '<div><div class="ecm-brand-name">' . e($center) . '</div><div class="ecm-brand-sub">نظام الإدارة</div></div></div><nav class="ecm-nav">';
    foreach (nav_items() as $group => $items) {
        $vis = [];
        foreach ($items as $it) {
            foreach ($it[3] as $p) {
                if (can($p)) {
                    $vis[] = $it;
                    break;
                }
            }
        }
        if (!$vis) {
            continue;
        }
        echo '<div class="ecm-nav-group">' . e($group) . '</div>';
        foreach ($vis as [$label, $href, $icon, , $match]) {
            $active = $rel === $match || strpos($rel, $match) === 0;
            $extra = ($href === 'modules/notifications/index.php' && unread_notifications_count() > 0) ? ' <span class="badge rounded-pill bg-danger ms-auto">' . unread_notifications_count() . '</span>' : '';
            echo '<a class="ecm-nav-link' . ($active ? ' active' : '') . '" href="' . e(url($href)) . '"><i class="bi bi-' . e($icon) . '"></i><span>' . e($label) . '</span>' . $extra . '</a>';
        }
    }
    echo '</nav></aside><div class="ecm-main"><header class="ecm-topbar no-print"><button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="القائمة"><i class="bi bi-list fs-4"></i></button>';
    echo '<h1 class="ecm-title">' . e($title) . '</h1><div class="ms-auto d-flex align-items-center gap-2">';
    if (can('notifications.view')) {
        $n = unread_notifications_count();
        echo '<a class="btn btn-light position-relative" href="' . e(url('modules/notifications/index.php')) . '" title="التنبيهات"><i class="bi bi-bell"></i>' . ($n ? '<span class="position-absolute top-0 start-0 translate-middle badge rounded-pill bg-danger">' . $n . '</span>' : '') . '</a>';
    }
    echo '<div class="dropdown"><button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown" type="button"><i class="bi bi-person-circle"></i> ' . e($u['full_name'] ?? '') . '</button><ul class="dropdown-menu dropdown-menu-end">';
    echo '<li><span class="dropdown-item-text small text-muted">' . e(implode('، ', $u['roles'] ?? [])) . '</span></li><li><a class="dropdown-item" href="' . e(url('modules/users/profile.php')) . '"><i class="bi bi-key"></i> كلمة المرور</a></li>';
    echo '<li><form method="post" action="' . e(url('logout.php')) . '">' . csrf_field() . '<button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right"></i> تسجيل الخروج</button></form></li></ul></div></div></header>';
    echo '<main class="ecm-content">' . flash_render();
}

function render_footer(): void
{
    $o = $GLOBALS['__ecm_opts'] ?? [];
    $print = !empty($GLOBALS['__ecm_print']);
    echo '</main>';
    if (!$print) {
        echo '<footer class="ecm-footer no-print">' . e((string)setting('center_name', '')) . ' · ' . date('Y') . '</footer></div></div>';
    }
    echo '<div class="modal fade" id="confirmModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">تأكيد العملية</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button></div><div class="modal-body" id="confirmBody"></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button type="button" class="btn btn-danger" id="confirmOk">تأكيد</button></div></div></div></div>';
    echo '<script src="' . e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) . '"></script>';
    if (!empty($o['charts'])) {
        echo '<script src="' . e(asset('vendor/chartjs/chart.umd.min.js')) . '"></script>';
    }
    if (!empty($o['qr'])) {
        echo '<script src="' . e(asset('vendor/qrcode/qrcode.js')) . '"></script>';
    }
    if (!empty($o['scanner'])) {
        echo '<script src="' . e(asset('vendor/jsqr/jsQR.js')) . '"></script>';
    }
    echo '<script src="' . e(asset('js/app.js')) . '"></script>';
    foreach ($GLOBALS['__ecm_inline'] ?? [] as $js) {
        echo '<script nonce="' . e(csp_nonce()) . '">' . $js . '</script>';
    }
    echo '</body></html>';
}

/** يضيف سكربتاً مضمّناً (بـ nonce) يُطبع في التذييل. استخدم json_encode للبيانات */
function inline_script(string $js): void
{
    $GLOBALS['__ecm_inline'][] = $js;
}

// ---------------------------------------------------------------- مكوّنات الواجهة
function empty_state(string $title, string $text = '', string $actionHtml = '', string $icon = 'inbox'): string
{
    return '<div class="ecm-empty"><i class="bi bi-' . e($icon) . '"></i><h6>' . e($title) . '</h6>' . ($text ? '<p>' . e($text) . '</p>' : '') . $actionHtml . '</div>';
}

function stat_card(string $label, $value, string $icon = 'graph-up', string $tone = 'emerald', string $hint = '', ?string $href = null): string
{
    $inner = '<div class="ecm-stat tone-' . e($tone) . '"><div class="ecm-stat-icon"><i class="bi bi-' . e($icon) . '"></i></div><div><div class="ecm-stat-label">' . e($label) . '</div><div class="ecm-stat-value">' . $value . '</div>' . ($hint ? '<div class="ecm-stat-hint">' . e($hint) . '</div>' : '') . '</div></div>';
    return $href ? '<a class="text-decoration-none d-block h-100" href="' . e($href) . '">' . $inner . '</a>' : $inner;
}

/** نموذج صغير لعملية حساسة مع نافذة تأكيد */
function confirm_button(string $action, array $hidden, string $label, string $message, string $class = 'btn-outline-danger btn-sm', string $extraFields = ''): string
{
    $h = csrf_field();
    foreach ($hidden as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    }
    return '<form method="post" action="' . e($action) . '" class="d-inline" data-confirm="' . e($message) . '">' . $h . $extraFields . '<button type="submit" class="btn ' . e($class) . '">' . $label . '</button></form>';
}

function opt_list(array $options, $selected = null, bool $blank = false, string $blankLabel = '— اختر —'): string
{
    $h = $blank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($options as $v => $l) {
        $h .= '<option value="' . e((string)$v) . '"' . ((string)$selected === (string)$v ? ' selected' : '') . '>' . e((string)$l) . '</option>';
    }
    return $h;
}

function col_opts(array $rows, string $valueCol, string $labelCol): array
{
    $o = [];
    foreach ($rows as $r) {
        $o[$r[$valueCol]] = $r[$labelCol];
    }
    return $o;
}

/** حقل إدخال بسيط. $o: col, required, attrs, help, readonly */
function field(string $type, string $name, string $label, $value = '', array $o = []): string
{
    $col = $o['col'] ?? 'col-md-6';
    $id = 'f_' . preg_replace('/\W+/', '_', $name);
    $req = !empty($o['required']);
    $h = '<div class="' . e($col) . '"><label class="form-label" for="' . e($id) . '">' . e($label) . ($req ? ' <span class="text-danger">*</span>' : '') . '</label>';
    $h .= '<input class="form-control" type="' . e($type) . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e((string)$value) . '"' . ($req ? ' required' : '') . (!empty($o['readonly']) ? ' readonly' : '') . ' ' . ($o['attrs'] ?? '') . '>';
    if (!empty($o['help'])) {
        $h .= '<div class="form-text">' . e($o['help']) . '</div>';
    }
    return $h . '</div>';
}

function select_field(string $name, string $label, array $options, $selected = null, array $o = []): string
{
    $col = $o['col'] ?? 'col-md-6';
    $id = 'f_' . preg_replace('/\W+/', '_', $name);
    $req = !empty($o['required']);
    $h = '<div class="' . e($col) . '"><label class="form-label" for="' . e($id) . '">' . e($label) . ($req ? ' <span class="text-danger">*</span>' : '') . '</label>';
    $h .= '<select class="form-select" id="' . e($id) . '" name="' . e($name) . '"' . ($req ? ' required' : '') . ' ' . ($o['attrs'] ?? '') . '>' . opt_list($options, $selected, array_key_exists('blank', $o) ? (bool)$o['blank'] : true, $o['blank_label'] ?? '— اختر —') . '</select>';
    if (!empty($o['help'])) {
        $h .= '<div class="form-text">' . e($o['help']) . '</div>';
    }
    return $h . '</div>';
}

function textarea_field(string $name, string $label, $value = '', array $o = []): string
{
    $col = $o['col'] ?? 'col-12';
    $id = 'f_' . preg_replace('/\W+/', '_', $name);
    return '<div class="' . e($col) . '"><label class="form-label" for="' . e($id) . '">' . e($label) . '</label><textarea class="form-control" id="' . e($id) . '" name="' . e($name) . '" rows="' . (int)($o['rows'] ?? 3) . '">' . e((string)$value) . '</textarea></div>';
}

/** عنصر لإظهار أخطاء التحقق */
function errors_box(array $errors): string
{
    if (!$errors) {
        return '';
    }
    return '<div class="alert alert-danger"><i class="bi bi-x-octagon"></i> يرجى تصحيح ما يلي:<ul class="mb-0 mt-1">' . implode('', array_map(fn($m) => '<li>' . e($m) . '</li>', $errors)) . '</ul></div>';
}
