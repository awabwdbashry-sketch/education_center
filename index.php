<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/finance.php';
require_once __DIR__ . '/includes/attendance.php';
require_once __DIR__ . '/includes/notifications.php';
require_perm('dashboard.view');

$fin = can('dashboard.finance');
$from = valid_date(get('from')) ? get('from') : date('Y-m-01');
$to = valid_date(get('to')) ? get('to') : today();
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$gid = get_int('group_id');
[$gsSql, $gsP] = group_scope_sql('g.id');
$groups = rows("SELECT g.id, g.name FROM class_groups g WHERE 1=1 $gsSql ORDER BY g.name", $gsP);
if ($gid && !in_array($gid, array_map('intval', array_column($groups, 'id')), true)) {
    $gid = 0;
}
if (can('notifications.view') && (!isset($_SESSION['notif_at']) || time() - $_SESSION['notif_at'] > 300)) {
    refresh_notifications();
    $_SESSION['notif_at'] = time();
}
$scopeIds = group_scope();
$gsCond = function (string $col) use ($scopeIds, $gid): array {
    $sql = '';
    $p = [];
    if ($gid) {
        $sql .= " AND $col = ?";
        $p[] = $gid;
    } elseif ($scopeIds !== null) {
        $sql .= $scopeIds ? " AND $col IN (" . implode(',', array_fill(0, count($scopeIds), '?')) . ')' : ' AND 1=0';
        $p = array_merge($p, $scopeIds);
    }
    return [$sql, $p];
};
[$c1, $p1] = $gsCond('gs.group_id');
$students = (int)val("SELECT COUNT(DISTINCT s.id) FROM students s JOIN group_students gs ON gs.student_id = s.id AND gs.status = 'active' WHERE s.status = 'active' $c1", $p1);
if (!$gid && $scopeIds === null) {
    $students = (int)val("SELECT COUNT(*) FROM students WHERE status = 'active'");
}
$teachers = (int)val("SELECT COUNT(*) FROM teachers WHERE status = 'active'");
[$c2, $p2] = $gsCond('g.id');
$groupsN = (int)val("SELECT COUNT(*) FROM class_groups g WHERE g.status = 'active' $c2", $p2);
$subjectsN = (int)val('SELECT COUNT(*) FROM subjects WHERE is_active = 1');
[$c3, $p3] = $gsCond('s.group_id');
$sessionsToday = (int)val("SELECT COUNT(*) FROM class_sessions s WHERE s.session_date = CURDATE() AND s.status <> 'cancelled' $c3", $p3);
$att = row("SELECT COALESCE(SUM(a.status IN ('present','late')),0) AS pres, COALESCE(SUM(a.status = 'absent'),0) AS abs_ FROM attendance a JOIN class_sessions s ON s.id = a.session_id WHERE s.session_date = CURDATE() $c3", $p3);
$subs = row("SELECT COALESCE(SUM(sb.status='active' AND (sb.end_date IS NULL OR sb.end_date >= CURDATE())),0) AS act,
    COALESCE(SUM(sb.status='active' AND sb.end_date < CURDATE()),0) AS exp_,
    COALESCE(SUM(sb.status='active' AND sb.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)),0) AS soon FROM subscriptions sb WHERE 1=1 " . str_replace('s.group_id', 'sb.group_id', $c3), array_merge([(int)setting('subscription_alert_days', 7)], $p3));

$collected = $expenses = $outstanding = 0;
$recentPay = $methodsRows = $monthly = [];
if ($fin) {
    $collected = to_cents(val("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.status = 'valid' AND DATE(p.paid_at) BETWEEN ? AND ?", [$from, $to]));
    $expenses = to_cents(val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'valid' AND expense_date BETWEEN ? AND ?", [$from, $to]));
    [$c4, $p4] = $gsCond('i.group_id');
    $outstanding = to_cents(val("SELECT COALESCE(SUM(i.total - i.paid_amount),0) FROM invoices i WHERE i.status IN ('open','partial') $c4", $p4));
    $recentPay = rows("SELECT p.id, p.amount, p.paid_at, p.method, s.full_name, r.receipt_no FROM payments p JOIN students s ON s.id = p.student_id LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.status = 'valid' ORDER BY p.id DESC LIMIT 6");
    for ($i = 5; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("first day of -$i month"));
        $monthly[] = [
            'ym' => $ym,
            'in' => (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'valid' AND DATE_FORMAT(paid_at,'%Y-%m') = ?", [$ym]),
            'out' => (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'valid' AND DATE_FORMAT(expense_date,'%Y-%m') = ?", [$ym]),
        ];
    }
}
$recentAtt = rows("SELECT a.status, a.method, a.marked_at, s.full_name, g.name AS gname FROM attendance a JOIN students s ON s.id = a.student_id JOIN class_sessions cs ON cs.id = a.session_id JOIN class_groups g ON g.id = cs.group_id WHERE 1=1 " . str_replace('s.group_id', 'cs.group_id', $c3) . ' ORDER BY a.id DESC LIMIT 6', $p3);
$alerts = can('notifications.view') ? rows("SELECT * FROM notifications WHERE is_read = 0 AND resolved_at IS NULL ORDER BY FIELD(severity,'danger','warning','info','success'), id DESC LIMIT 6") : [];
$perf = rows("SELECT g.id, g.name, t.full_name AS teacher,
    (SELECT COUNT(*) FROM group_students x WHERE x.group_id = g.id AND x.status = 'active') AS n,
    (SELECT COUNT(*) FROM attendance a JOIN class_sessions s ON s.id = a.session_id WHERE s.group_id = g.id AND s.session_date BETWEEN ? AND ?) AS att_total,
    (SELECT COUNT(*) FROM attendance a JOIN class_sessions s ON s.id = a.session_id WHERE s.group_id = g.id AND s.session_date BETWEEN ? AND ? AND a.status IN ('present','late')) AS att_ok
    FROM class_groups g JOIN teachers t ON t.id = g.teacher_id WHERE g.status = 'active' " . ($gid ? ' AND g.id = ?' : $gsSql) . ' ORDER BY n DESC LIMIT 8',
    array_merge([$from, $to, $from, $to], $gid ? [$gid] : $gsP));
$todaySessions = rows("SELECT s.id, s.start_time, s.end_time, s.status, g.name FROM class_sessions s JOIN class_groups g ON g.id = s.group_id WHERE s.session_date = CURDATE() AND s.status <> 'cancelled' $c3 ORDER BY s.start_time LIMIT 8", $p3);

render_header('لوحة التحكم', ['charts' => $fin]);
?>
<form class="filter-bar row g-2 align-items-end no-print" method="get">
  <div class="col-6 col-md-3"><label class="form-label small mb-0">من</label><input class="form-control form-control-sm" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-3"><label class="form-label small mb-0">إلى</label><input class="form-control form-control-sm" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-8 col-md-4"><label class="form-label small mb-0">المجموعة</label><select class="form-select form-select-sm" name="group_id"><?= opt_list(col_opts($groups, 'id', 'name'), $gid, true, 'كل المجموعات') ?></select></div>
  <div class="col-4 col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i> تطبيق</button></div>
</form>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?= stat_card('الطلاب النشطون', $students, 'people', 'emerald', '', can('students.view') ? url('modules/students/index.php?status=active') : null) ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('المدرسون', $teachers, 'person-workspace', 'violet') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('المجموعات النشطة', $groupsN, 'collection', 'teal', 'المواد: ' . $subjectsN) ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('حصص اليوم', $sessionsToday, 'calendar-event', 'amber', '', can('academics.view') ? url('modules/sessions/calendar.php') : null) ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('الحاضرون اليوم', (int)$att['pres'], 'person-check', 'emerald') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('الغائبون اليوم', (int)$att['abs_'], 'person-x', 'terra') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('اشتراكات نشطة', (int)$subs['act'], 'card-checklist', 'emerald', 'قريبة الانتهاء: ' . (int)$subs['soon']) ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('اشتراكات منتهية', (int)$subs['exp_'], 'calendar-x', 'terra') ?></div>
</div>
<?php if ($fin): ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?= stat_card('المحصّل (الفترة)', '<span class="money">' . e(money_c($collected)) . '</span>', 'cash-coin', 'emerald', 'دفعات فعلية معتمدة') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('المصروفات (الفترة)', '<span class="money">' . e(money_c($expenses)) . '</span>', 'wallet2', 'terra', 'مصروف مسجّل غير ملغى') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('صافي التدفق النقدي', '<span class="money">' . e(money_c($collected - $expenses)) . '</span>', 'arrow-left-right', $collected - $expenses >= 0 ? 'teal' : 'terra', 'محصّل − مصروف (ليس ربحاً محاسبياً)') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('المتبقي على الطلاب', '<span class="money">' . e(money_c($outstanding)) . '</span>', 'hourglass-split', 'amber', 'فواتير مستحقة غير مسددة', can('billing.view') ? url('modules/billing/invoices.php?status=due') : null) ?></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-7"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-bar-chart"></i> التحصيل والمصروفات — آخر 6 أشهر</div><div style="height:260px"><canvas id="chMonthly"></canvas></div></div></div>
  <div class="col-lg-5"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-receipt"></i> آخر عمليات التحصيل</div>
  <?php if (!$recentPay): echo empty_state('لا توجد دفعات بعد', 'ستظهر هنا آخر الدفعات المسجلة.', '', 'cash-coin'); else: ?>
    <ul class="list-group list-group-flush"><?php foreach ($recentPay as $p): ?>
      <li class="list-group-item px-0 d-flex justify-content-between"><div><a href="<?= e(url('modules/cashier/receipt.php?id=' . $p['id'])) ?>"><?= e($p['full_name']) ?></a><div class="small text-muted"><?= e($p['receipt_no']) ?> · <?= e(payment_method_label($p['method'])) ?> · <?= e(fdatetime($p['paid_at'])) ?></div></div><strong class="money text-success"><?= e(money($p['amount'])) ?></strong></li>
    <?php endforeach; ?></ul>
  <?php endif; ?></div></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-bell"></i> التنبيهات</div>
    <?php if (!$alerts): echo empty_state('لا توجد تنبيهات غير مقروءة', '', '', 'bell-slash'); else: ?>
      <?php foreach ($alerts as $a): $cls = ['danger' => 'danger', 'warning' => 'warning', 'info' => 'info', 'success' => 'success'][$a['severity']]; ?>
        <a class="d-block text-decoration-none mb-2 p-2 rounded border-start border-4 border-<?= e($cls) ?> bg-<?= e($cls) ?>-subtle text-dark" href="<?= e($a['link'] ? url($a['link']) : url('modules/notifications/index.php')) ?>"><div class="fw-semibold small"><?= e($a['title']) ?></div><div class="small text-muted"><?= e($a['body']) ?></div></a>
      <?php endforeach; ?><a class="small" href="<?= e(url('modules/notifications/index.php')) ?>">كل التنبيهات</a>
    <?php endif; ?></div></div>
  <div class="col-lg-4"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-calendar-event"></i> حصص اليوم</div>
    <?php if (!$todaySessions): echo empty_state('لا توجد حصص اليوم', '', can('sessions.manage') ? '<a class="btn btn-sm btn-outline-primary" href="' . e(url('modules/sessions/calendar.php')) . '">إنشاء حصص من الجدول</a>' : '', 'calendar'); else: ?>
      <ul class="list-group list-group-flush"><?php foreach ($todaySessions as $s): ?><li class="list-group-item px-0 d-flex justify-content-between align-items-center"><div><?= e($s['name']) ?><div class="small text-muted"><?= e(ftime($s['start_time'])) ?> – <?= e(ftime($s['end_time'])) ?></div></div><div><?= status_badge($s['status'], session_statuses()) ?><?php if (can('attendance.mark')): ?> <a class="btn btn-sm btn-outline-primary" href="<?= e(url('modules/attendance/mark.php?session_id=' . $s['id'])) ?>">حضور</a><?php endif; ?></div></li><?php endforeach; ?></ul>
    <?php endif; ?></div></div>
  <div class="col-lg-4"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-person-check"></i> آخر عمليات الحضور</div>
    <?php if (!$recentAtt): echo empty_state('لا توجد سجلات حضور', '', '', 'person-check'); else: ?>
      <ul class="list-group list-group-flush"><?php foreach ($recentAtt as $a): ?><li class="list-group-item px-0 d-flex justify-content-between"><div><?= e($a['full_name']) ?><div class="small text-muted"><?= e($a['gname']) ?> · <?= e(fdatetime($a['marked_at'])) ?> · <?= $a['method'] === 'qr' ? 'QR' : 'يدوي' ?></div></div><?= status_badge($a['status'], attendance_statuses()) ?></li><?php endforeach; ?></ul>
    <?php endif; ?></div></div>
</div>

<div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-trophy"></i> ملخص أداء المجموعات (الحضور خلال الفترة)</div>
<?php if (!$perf): echo empty_state('لا توجد مجموعات', 'أنشئ مجموعة لتظهر هنا.', can('academics.manage') ? '<a class="btn btn-sm btn-primary" href="' . e(url('modules/groups/form.php')) . '">مجموعة جديدة</a>' : '', 'collection'); else: ?>
<div class="table-responsive"><table class="table ecm-table"><thead><tr><th>المجموعة</th><th>المدرس</th><th>الطلاب</th><th>سجلات الحضور</th><th>نسبة الحضور</th></tr></thead><tbody>
<?php foreach ($perf as $r): ?><tr><td><a href="<?= e(url('modules/groups/view.php?id=' . $r['id'])) ?>"><?= e($r['name']) ?></a></td><td><?= e($r['teacher']) ?></td><td><?= (int)$r['n'] ?></td><td><?= (int)$r['att_total'] ?></td><td><?= e(attendance_rate((int)$r['att_ok'], (int)$r['att_total'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
<?php
if ($fin) {
    inline_script('(function(){var d=' . json_encode($monthly, JSON_UNESCAPED_UNICODE) . ';var c=document.getElementById("chMonthly");if(!c||!window.Chart)return;new Chart(c,{type:"bar",data:{labels:d.map(function(x){return x.ym}),datasets:[{label:"التحصيل",data:d.map(function(x){return x.in}),backgroundColor:"#1f7a5c"},{label:"المصروفات",data:d.map(function(x){return x.out}),backgroundColor:"#c2603a"}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{font:{family:"Cairo"}}}},scales:{y:{beginAtZero:true}}}});})();');
}
render_footer();
