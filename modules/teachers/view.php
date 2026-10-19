<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_perm('teachers.view');

$id = get_int('id');
$t = row('SELECT * FROM teachers WHERE id = ?', [$id]);
if (!$t) {
    abort(404, 'المدرس غير موجود');
}
$me = current_user();
if (!can('groups.view_all') && (int)$me['teacher_id'] !== $id) {
    abort(403, 'لا يمكنك عرض ملف مدرس آخر.');
}
$subs = rows('SELECT s.name FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id WHERE ts.teacher_id = ?', [$id]);
$groups = rows("SELECT g.*, s.name AS sname, (SELECT COUNT(*) FROM group_students x WHERE x.group_id = g.id AND x.status = 'active') AS n FROM class_groups g JOIN subjects s ON s.id = g.subject_id WHERE g.teacher_id = ? ORDER BY g.status, g.name", [$id]);
$held = (int)val("SELECT COUNT(*) FROM class_sessions WHERE teacher_id = ? AND status = 'held'", [$id]);
$att = row("SELECT COUNT(*) AS total, COALESCE(SUM(a.status IN ('present','late')),0) AS ok FROM attendance a JOIN class_sessions s ON s.id = a.session_id WHERE s.teacher_id = ?", [$id]);
$sched = rows("SELECT sc.*, g.name FROM schedules sc JOIN class_groups g ON g.id = sc.group_id WHERE g.teacher_id = ? AND sc.is_active = 1 AND g.status = 'active' ORDER BY FIELD(sc.weekday,6,7,1,2,3,4,5), sc.start_time", [$id]);
$canPay = can('teacher_pay.view');
if ($canPay) {
    $bal = teacher_balance($id);
    $comps = rows("SELECT * FROM teacher_compensations WHERE teacher_id = ? AND status = 'valid' ORDER BY id DESC LIMIT 15", [$id]);
    $payouts = rows('SELECT * FROM teacher_payouts WHERE teacher_id = ? ORDER BY id DESC LIMIT 15', [$id]);
}
render_header('ملف المدرس: ' . $t['full_name']);
?>
<div class="page-actions"><?php if (can('teachers.manage')): ?><a class="btn btn-outline-primary" href="form.php?id=<?= $id ?>"><i class="bi bi-pencil"></i> تعديل</a><?php endif; ?><a class="btn btn-light" href="index.php">القائمة</a></div>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-person-badge"></i> البيانات</div>
    <dl class="mb-0"><dt>الهاتف</dt><dd dir="ltr" class="text-end"><?= e($t['phone'] ?: '—') ?></dd><dt>البريد</dt><dd><?= e($t['email'] ?: '—') ?></dd><dt>التخصص</dt><dd><?= e($t['specialization'] ?: '—') ?></dd>
    <dt>المواد</dt><dd><?= e(implode('، ', array_column($subs, 'name')) ?: '—') ?></dd><dt>تاريخ التعيين</dt><dd><?= e(fdate($t['hire_date'])) ?></dd><dt>الحالة</dt><dd><?= status_badge($t['status'], ['active' => 'نشط', 'inactive' => 'غير نشط']) ?></dd>
    <dt>نظام الأجر</dt><dd><?= e(comp_type_labels()[$t['comp_type']]) ?><?= $t['comp_type'] !== 'none' ? ' — ' . e(money($t['comp_amount'])) : '' ?></dd></dl></div></div>
  <div class="col-md-8"><div class="row g-3">
    <div class="col-6 col-lg-3"><?= stat_card('مجموعات', count($groups), 'collection', 'teal') ?></div>
    <div class="col-6 col-lg-3"><?= stat_card('حصص منفذة', $held, 'calendar-check', 'emerald') ?></div>
    <div class="col-6 col-lg-3"><?= stat_card('نسبة حضور طلابه', e(attendance_rate((int)$att['ok'], (int)$att['total'])), 'graph-up', 'violet') ?></div>
    <?php if ($canPay): ?><div class="col-6 col-lg-3"><?= stat_card('المتبقي له', '<span class="money">' . e(money_c($bal['balance'])) . '</span>', 'cash-stack', 'amber') ?></div><?php endif; ?>
  </div>
  <div class="ecm-card mt-3"><div class="ecm-card-title"><i class="bi bi-calendar-week"></i> الجدول الأسبوعي</div>
  <?php if (!$sched): echo empty_state('لا جدول أسبوعي', 'أضف جدول الحصص من صفحة المجموعة.', '', 'calendar'); else: ?><div class="d-flex flex-wrap gap-2"><?php foreach ($sched as $s): ?><span class="badge text-bg-light border p-2"><?= e(weekday_name((int)$s['weekday'])) ?> <?= e(ftime($s['start_time'])) ?>–<?= e(ftime($s['end_time'])) ?> · <?= e($s['name']) ?></span><?php endforeach; ?></div><?php endif; ?></div></div>
</div>
<div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-collection"></i> المجموعات المسندة</div>
<?php if (!$groups): echo empty_state('لا توجد مجموعات مسندة'); else: ?><div class="table-responsive"><table class="table ecm-table"><thead><tr><th>المجموعة</th><th>المادة</th><th>الطلاب</th><th>الحالة</th></tr></thead><tbody>
<?php foreach ($groups as $g): ?><tr><td><a href="../groups/view.php?id=<?= (int)$g['id'] ?>"><?= e($g['name']) ?></a> <small class="text-muted"><?= e($g['code']) ?></small></td><td><?= e($g['sname']) ?></td><td><?= (int)$g['n'] ?>/<?= (int)$g['max_students'] ?></td><td><?= status_badge($g['status'], group_statuses()) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php if ($canPay): ?>
<div class="row g-3"><div class="col-lg-6"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-journal-plus"></i> المستحقات (آخر 15)</div>
  <?php if (!$comps): echo empty_state('لا مستحقات محتسبة'); else: ?><table class="table ecm-table"><tbody><?php foreach ($comps as $c): ?><tr><td><?= e($c['description']) ?></td><td class="money"><?= e(money($c['amount'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></div>
<div class="col-lg-6"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-cash-stack"></i> المصروف له</div>
  <?php if (!$payouts): echo empty_state('لا دفعات مصروفة'); else: ?><table class="table ecm-table"><tbody><?php foreach ($payouts as $p): ?><tr><td><?= e(fdate($p['paid_date'])) ?> · <?= e(payment_method_label($p['method'])) ?></td><td class="money"><?= e(money($p['amount'])) ?></td><td><?= status_badge($p['status'], ['valid' => 'معتمد', 'voided' => 'ملغى']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <div class="mt-2 small text-muted">المحتسب: <?= e(money_c($bal['accrued'])) ?> · المصروف: <?= e(money_c($bal['paid'])) ?> · المتبقي: <?= e(money_c($bal['balance'])) ?></div>
  <?php if (can('teacher_pay.manage')): ?><a class="btn btn-sm btn-primary mt-2" href="../finance/teacher_pay.php?teacher_id=<?= $id ?>">إدارة المستحقات والصرف</a><?php endif; ?></div></div></div>
<?php endif; ?>
<?php render_footer();
