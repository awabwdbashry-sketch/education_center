<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_perm('students.view');

$id = get_int('id');
$s = row('SELECT s.*, l.name AS lname FROM students s LEFT JOIN academic_levels l ON l.id = s.level_id WHERE s.id = ?', [$id]);
if (!$s) {
    abort(404, 'الطالب غير موجود');
}
require_student_access($id);

if (is_post()) {
    $act = post('action');
    try {
        if ($act === 'qr_revoke' && can('students.qr')) {
            q("UPDATE students SET qr_status = 'revoked' WHERE id = ?", [$id]);
            audit('qr_revoked', 'student', $id);
            flash('success', 'تم إيقاف رمز QR. لن يُقبل حتى تعيد إصداره.');
        } elseif ($act === 'qr_reissue' && can('students.qr')) {
            q("UPDATE students SET qr_token = ?, qr_status = 'active', qr_issued_at = NOW() WHERE id = ?", [new_qr_token(), $id]);
            audit('qr_reissued', 'student', $id);
            flash('success', 'أُعيد إصدار QR. الرمز القديم لم يعد صالحاً — اطبع البطاقة الجديدة.');
        } elseif ($act === 'status' && can('students.manage')) {
            $ns = post('status');
            if (!isset(student_statuses()[$ns])) {
                throw new RuntimeException('حالة غير صالحة.');
            }
            q('UPDATE students SET status = ? WHERE id = ?', [$ns, $id]);
            if ($ns !== 'active') {
                q("UPDATE group_students SET status = 'left', left_at = CURDATE() WHERE student_id = ? AND status = 'active' AND ? = 'withdrawn'", [$id, $ns]);
            }
            audit('student_status', 'student', $id, ['to' => $ns]);
            flash('success', 'تم تحديث الحالة.');
        } elseif ($act === 'enroll' && can('students.manage')) {
            $gidE = post_int('group_id');
            enroll_student($id, $gidE, post('create_sub') === '1', uid());
            flash('success', 'تم التسجيل في المجموعة.');
        } elseif ($act === 'unenroll' && can('students.manage')) {
            unenroll_student($id, post_int('group_id'));
            flash('success', 'تم الإخراج من المجموعة.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/students/view.php?id=' . $id . (get('tab') ? '&tab=' . urlencode(get('tab')) : ''));
}
$tab = in_array(get('tab'), ['info', 'attendance', 'billing', 'receipts', 'log'], true) ? get('tab') : 'info';
$guardians = rows('SELECT g.*, sg.is_primary FROM guardians g JOIN student_guardians sg ON sg.guardian_id = g.id WHERE sg.student_id = ?', [$id]);
$groups = rows("SELECT g.id, g.name, g.code, gs.joined_at, gs.status FROM group_students gs JOIN class_groups g ON g.id = gs.group_id WHERE gs.student_id = ? ORDER BY gs.status, g.name", [$id]);
$finOk = can('billing.view');
$bal = $finOk ? student_balance($id) : null;
$att = row("SELECT COUNT(*) AS total, COALESCE(SUM(status IN ('present','late')),0) AS ok, COALESCE(SUM(status='absent'),0) AS ab FROM attendance WHERE student_id = ?", [$id]);
$openGroups = can('students.manage') ? rows("SELECT g.id, g.name FROM class_groups g WHERE g.status <> 'finished' AND g.id NOT IN (SELECT group_id FROM group_students WHERE student_id = ? AND status = 'active') ORDER BY g.name", [$id]) : [];
render_header('ملف الطالب: ' . $s['full_name']);
?>
<div class="page-actions">
  <?php if (can('students.manage')): ?><a class="btn btn-outline-primary" href="form.php?id=<?= $id ?>"><i class="bi bi-pencil"></i> تعديل</a><?php endif; ?>
  <a class="btn btn-outline-secondary" href="card.php?id=<?= $id ?>" target="_blank"><i class="bi bi-person-badge"></i> بطاقة الطالب + QR</a>
  <?php if (can('payments.collect')): ?><a class="btn btn-outline-success" href="../cashier/collect.php?student_id=<?= $id ?>"><i class="bi bi-cash-coin"></i> تحصيل</a><?php endif; ?>
  <?php if ($finOk): ?><a class="btn btn-outline-secondary" href="../cashier/statement.php?student_id=<?= $id ?>"><i class="bi bi-file-earmark-text"></i> كشف حساب</a><?php endif; ?>
  <?php if (can('comms.view')): ?><a class="btn btn-outline-secondary" href="../comms/index.php?student_id=<?= $id ?>"><i class="bi bi-whatsapp"></i> تواصل</a><?php endif; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="ecm-card h-100 text-center">
    <?php if ($s['photo_path']): ?><img class="rounded-circle mb-2" style="width:96px;height:96px;object-fit:cover" src="<?= e(url('file.php?f=' . rawurlencode($s['photo_path']))) ?>" alt=""><?php else: ?><span class="avatar mb-2" style="width:96px;height:96px;font-size:2.4rem"><?= e(mb_substr($s['full_name'], 0, 1)) ?></span><?php endif; ?>
    <h5 class="mb-0"><?= e($s['full_name']) ?></h5><div class="text-muted" dir="ltr"><?= e($s['student_no']) ?></div><div class="mt-1"><?= status_badge($s['status'], student_statuses()) ?> <?= $s['qr_status'] === 'active' ? badge('QR فعّال', 'success') : badge('QR موقوف', 'danger') ?></div>
    <?php if (can('students.qr')): ?><div class="mt-3 d-flex gap-1 justify-content-center">
      <?= $s['qr_status'] === 'active' ? confirm_button('', ['action' => 'qr_revoke'], 'إيقاف QR', 'إيقاف رمز QR لهذا الطالب؟ لن يُقبل المسح حتى إعادة الإصدار.') : '' ?>
      <?= confirm_button('', ['action' => 'qr_reissue'], 'إعادة إصدار QR', 'سيُبطل الرمز الحالي ويُنشأ رمز جديد، وستحتاج لطباعة بطاقة جديدة. متابعة؟', 'btn-outline-warning btn-sm') ?></div><?php endif; ?></div></div>
  <div class="col-lg-8"><div class="row g-3">
    <div class="col-6 col-md-3"><?= stat_card('نسبة الحضور', e(attendance_rate((int)$att['ok'], (int)$att['total'])), 'graph-up', 'emerald') ?></div>
    <div class="col-6 col-md-3"><?= stat_card('مرات الغياب', (int)$att['ab'], 'person-x', 'terra') ?></div>
    <?php if ($bal): ?><div class="col-6 col-md-3"><?= stat_card('المتبقي', '<span class="money">' . e(money_c($bal['due'])) . '</span>', 'hourglass-split', 'amber', $bal['overdue'] > 0 ? 'متأخر: ' . money_c($bal['overdue']) : '') ?></div>
    <div class="col-6 col-md-3"><?= stat_card('رصيد مقدم', '<span class="money">' . e(money_c($bal['credit'])) . '</span>', 'wallet', 'teal') ?></div><?php endif; ?></div>
  <ul class="nav nav-tabs mt-3"><?php foreach (['info' => 'البيانات والمجموعات', 'attendance' => 'الحضور', 'billing' => 'الاشتراكات والفواتير', 'receipts' => 'الدفعات والإيصالات', 'log' => 'سجل التغييرات'] as $k => $l): if (in_array($k, ['billing', 'receipts'], true) && !$finOk) continue; if ($k === 'log' && !can('audit.view')) continue; ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=<?= $k ?>"><?= e($l) ?></a></li><?php endforeach; ?></ul></div>
</div>
<?php if ($tab === 'info'): ?>
<div class="row g-3"><div class="col-lg-6"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-person-lines-fill"></i> البيانات</div><dl class="row mb-0">
  <dt class="col-5">الصف</dt><dd class="col-7"><?= e($s['lname'] ?: '—') ?></dd><dt class="col-5">الهاتف</dt><dd class="col-7" dir="ltr"><?= e($s['phone'] ?: '—') ?></dd><dt class="col-5">تاريخ الميلاد</dt><dd class="col-7"><?= e(fdate($s['birth_date'])) ?></dd>
  <dt class="col-5">العنوان</dt><dd class="col-7"><?= e($s['address'] ?: '—') ?></dd><dt class="col-5">تاريخ التسجيل</dt><dd class="col-7"><?= e(fdate($s['registered_at'])) ?></dd><dt class="col-5">ملاحظات</dt><dd class="col-7"><?= nl2br(e($s['notes'] ?: '—')) ?></dd>
  <?php foreach ($guardians as $g): ?><dt class="col-5">ولي الأمر</dt><dd class="col-7"><?= e($g['full_name']) ?> <?= $g['relation'] ? '(' . e($g['relation']) . ')' : '' ?><div dir="ltr" class="text-end small"><?= e($g['phone']) ?></div></dd><?php endforeach; ?></dl>
  <?php if (can('students.manage')): ?><form method="post" class="d-flex gap-2 mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="status"><select class="form-select form-select-sm" name="status"><?= opt_list(student_statuses(), $s['status']) ?></select><button class="btn btn-sm btn-outline-secondary">تغيير الحالة</button></form><div class="form-text">الانسحاب يُخرج الطالب من مجموعاته ويُبقي كل سجلاته المالية والحضور.</div><?php endif; ?></div></div>
<div class="col-lg-6"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-collection"></i> المجموعات</div>
  <?php if (!$groups): echo empty_state('غير مسجل في أي مجموعة', '', '', 'collection'); else: ?><table class="table ecm-table"><tbody><?php foreach ($groups as $g): ?><tr><td><a href="../groups/view.php?id=<?= (int)$g['id'] ?>"><?= e($g['name']) ?></a><div class="small text-muted"><?= e($g['code']) ?> · منذ <?= e(fdate($g['joined_at'])) ?></div></td><td><?= status_badge($g['status'], ['active' => 'مسجل', 'left' => 'خرج']) ?></td>
  <td class="text-end"><?php if ($g['status'] === 'active' && can('students.manage')) echo confirm_button('', ['action' => 'unenroll', 'group_id' => $g['id']], 'إخراج', 'إخراج الطالب من هذه المجموعة؟'); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <?php if ($openGroups && $s['status'] === 'active'): ?><form method="post" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="enroll"><div class="col-12 col-md-6"><select class="form-select form-select-sm" name="group_id" required><?= opt_list(col_opts($openGroups, 'id', 'name'), null, true, '— مجموعة —') ?></select></div>
    <div class="col-8 col-md-4"><label class="form-check small"><input class="form-check-input" type="checkbox" name="create_sub" value="1" checked> <span class="form-check-label">إنشاء اشتراك</span></label></div><div class="col-4 col-md-2"><button class="btn btn-sm btn-primary w-100">تسجيل</button></div></form><?php endif; ?></div></div></div>
<?php elseif ($tab === 'attendance'):
    $ar = rows("SELECT a.status, a.method, a.marked_at, a.notes, se.session_date, se.start_time, g.name FROM attendance a JOIN class_sessions se ON se.id = a.session_id JOIN class_groups g ON g.id = se.group_id WHERE a.student_id = ? ORDER BY se.session_date DESC, se.start_time DESC LIMIT 100", [$id]); ?>
<div class="ecm-card"><?php if (!$ar): echo empty_state('لا يوجد سجل حضور', '', '', 'person-check'); else: ?><div class="table-responsive"><table class="table ecm-table"><thead><tr><th>التاريخ</th><th>المجموعة</th><th>الحالة</th><th>الطريقة</th><th>وقت التسجيل</th><th>ملاحظة</th></tr></thead><tbody>
<?php foreach ($ar as $a): ?><tr><td><?= e(fdate($a['session_date'])) ?> <?= e(ftime($a['start_time'])) ?></td><td><?= e($a['name']) ?></td><td><?= status_badge($a['status'], attendance_statuses()) ?></td><td><?= $a['method'] === 'qr' ? 'QR' : 'يدوي' ?></td><td><?= e(fdatetime($a['marked_at'])) ?></td><td><?= e($a['notes']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php elseif ($tab === 'billing' && $finOk):
    $subsR = rows("SELECT sb.*, g.name FROM subscriptions sb JOIN class_groups g ON g.id = sb.group_id WHERE sb.student_id = ? ORDER BY sb.id DESC", [$id]);
    $invs = rows('SELECT * FROM invoices WHERE student_id = ? ORDER BY id DESC LIMIT 60', [$id]); ?>
<div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-card-checklist"></i> الاشتراكات</div><?php if (!$subsR): echo empty_state('لا توجد اشتراكات', 'تُنشأ عند التسجيل في مجموعة أو من صفحة الاشتراكات.'); else: ?><table class="table ecm-table"><thead><tr><th>المجموعة</th><th>النظام</th><th>السعر</th><th>الخصم</th><th>الفترة</th><th>الحالة</th></tr></thead><tbody><?php foreach ($subsR as $b): ?><tr><td><?= e($b['name']) ?></td><td><?= e(mode_labels()[$b['mode']]) ?></td><td class="money"><?= e(money($b['unit_price'])) ?></td><td class="money"><?= e(money($b['discount_amount'])) ?></td><td><?= e(fdate($b['start_date'])) ?> → <?= e(fdate($b['end_date'])) ?></td><td><?= status_badge($b['status'], ['active' => 'نشط', 'cancelled' => 'ملغى']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-file-earmark-text"></i> الفواتير</div><?php if (!$invs): echo empty_state('لا توجد فواتير'); else: ?><div class="table-responsive"><table class="table ecm-table"><thead><tr><th>الرقم</th><th>الاستحقاق</th><th>الإجمالي</th><th>المدفوع</th><th>المتبقي</th><th>الحالة</th></tr></thead><tbody><?php foreach ($invs as $i): ?><tr><td><a href="../billing/invoice.php?id=<?= (int)$i['id'] ?>"><?= e($i['invoice_no']) ?></a></td><td><?= e(fdate($i['due_date'])) ?></td><td class="money"><?= e(money($i['total'])) ?></td><td class="money"><?= e(money($i['paid_amount'])) ?></td><td class="money"><?= e(money_c(invoice_outstanding($i))) ?></td><td><?= status_badge($i['status'], invoice_statuses()) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php elseif ($tab === 'receipts' && $finOk):
    $pays = rows("SELECT p.*, r.receipt_no FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.student_id = ? ORDER BY p.id DESC LIMIT 100", [$id]); ?>
<div class="ecm-card"><?php if (!$pays): echo empty_state('لا توجد دفعات', '', '', 'receipt'); else: ?><div class="table-responsive"><table class="table ecm-table"><thead><tr><th>الإيصال</th><th>التاريخ</th><th>المبلغ</th><th>الوسيلة</th><th>الحالة</th><th></th></tr></thead><tbody><?php foreach ($pays as $p): ?><tr><td><?= e($p['receipt_no']) ?></td><td><?= e(fdatetime($p['paid_at'])) ?></td><td class="money"><?= e(money($p['amount'])) ?></td><td><?= e(payment_method_label($p['method'])) ?></td><td><?= status_badge($p['status'], ['valid' => 'معتمدة', 'voided' => 'ملغاة']) ?></td><td><a class="btn btn-sm btn-outline-secondary" href="../cashier/receipt.php?id=<?= (int)$p['id'] ?>">عرض</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php elseif ($tab === 'log' && can('audit.view')):
    $lg = rows("SELECT * FROM audit_logs WHERE (entity_type = 'student' AND entity_id = ?) ORDER BY id DESC LIMIT 100", [$id]); ?>
<div class="ecm-card"><?php if (!$lg): echo empty_state('لا توجد تغييرات مسجلة'); else: ?><table class="table ecm-table"><thead><tr><th>الوقت</th><th>العملية</th><th>المستخدم</th></tr></thead><tbody><?php foreach ($lg as $l): ?><tr><td><?= e(fdatetime($l['created_at'])) ?></td><td><?= e($l['action']) ?></td><td><?= e($l['username']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?php endif; render_footer();
