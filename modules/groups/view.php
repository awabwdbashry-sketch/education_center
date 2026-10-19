<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_perm('academics.view');

$id = get_int('id');
$g = row('SELECT g.*, s.name AS sname, t.full_name AS tname, l.name AS lname, ps.mode, ps.monthly_fee, ps.session_price, ps.due_days, ps.settlement_sessions
    FROM class_groups g JOIN subjects s ON s.id = g.subject_id JOIN teachers t ON t.id = g.teacher_id LEFT JOIN academic_levels l ON l.id = g.level_id LEFT JOIN group_payment_settings ps ON ps.group_id = g.id WHERE g.id = ?', [$id]);
if (!$g) {
    abort(404, 'المجموعة غير موجودة');
}
require_group_access($id);

if (is_post()) {
    $act = post('action');
    try {
        if ($act === 'enroll' && can('students.manage')) {
            enroll_student(post_int('student_id'), $id, post('create_sub') === '1', uid());
            flash('success', 'تم تسجيل الطالب في المجموعة.');
        } elseif ($act === 'unenroll' && can('students.manage')) {
            unenroll_student(post_int('student_id'), $id);
            flash('success', 'تم إخراج الطالب من المجموعة (يُحفظ سجل حضوره ومدفوعاته).');
        } elseif ($act === 'sched_add' && can('academics.manage')) {
            $wd = post_int('weekday');
            $st = post('start_time');
            $en = post('end_time');
            $room = mb_substr(post('room'), 0, 100);
            if ($wd < 1 || $wd > 7 || !valid_time($st) || !valid_time($en) || $en <= $st) {
                throw new RuntimeException('بيانات الموعد غير صحيحة (تأكد أن النهاية بعد البداية).');
            }
            $c = schedule_conflicts($id, (int)$g['teacher_id'], $room !== '' ? $room : $g['room'], $wd, $st, $en);
            if ($c) {
                throw new RuntimeException('تعارض في الجدول: ' . implode(' | ', $c));
            }
            insert('schedules', ['group_id' => $id, 'weekday' => $wd, 'start_time' => $st, 'end_time' => $en, 'room' => $room !== '' ? $room : null]);
            audit('schedule_added', 'group', $id, ['weekday' => $wd, 'start' => $st, 'end' => $en]);
            flash('success', 'تمت إضافة الموعد الأسبوعي.');
        } elseif ($act === 'sched_del' && can('academics.manage')) {
            q('UPDATE schedules SET is_active = 0 WHERE id = ? AND group_id = ?', [post_int('schedule_id'), $id]);
            audit('schedule_removed', 'group', $id, ['schedule' => post_int('schedule_id')]);
            flash('success', 'تم إيقاف الموعد (الحصص السابقة محفوظة).');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        flash('error', is_duplicate_error($e) ? 'الموعد موجود مسبقاً.' : 'تعذر تنفيذ العملية.');
        if (!is_duplicate_error($e)) {
            error_log($e->getMessage());
        }
    }
    redirect('modules/groups/view.php?id=' . $id);
}
$members = rows("SELECT s.id, s.student_no, s.full_name, s.phone, s.status, gs.joined_at FROM group_students gs JOIN students s ON s.id = gs.student_id WHERE gs.group_id = ? AND gs.status = 'active' ORDER BY s.full_name", [$id]);
$sched = rows('SELECT * FROM schedules WHERE group_id = ? AND is_active = 1 ORDER BY FIELD(weekday,6,7,1,2,3,4,5), start_time', [$id]);
$cand = can('students.manage') ? rows("SELECT s.id, s.student_no, s.full_name FROM students s WHERE s.status = 'active' AND s.id NOT IN (SELECT student_id FROM group_students WHERE group_id = ? AND status = 'active') ORDER BY s.full_name LIMIT 500", [$id]) : [];
$rate = row("SELECT COUNT(*) AS total, COALESCE(SUM(a.status IN ('present','late')),0) AS ok FROM attendance a JOIN class_sessions s ON s.id = a.session_id WHERE s.group_id = ?", [$id]);
$sessCount = (int)val("SELECT COUNT(*) FROM class_sessions WHERE group_id = ? AND status = 'held'", [$id]);
render_header('المجموعة: ' . $g['name']);
?>
<div class="page-actions">
  <?php if (can('academics.manage')): ?><a class="btn btn-outline-primary" href="form.php?id=<?= $id ?>"><i class="bi bi-pencil"></i> تعديل</a><?php endif; ?>
  <?php if (can('attendance.mark')): ?><a class="btn btn-outline-success" href="../attendance/mark.php?group_id=<?= $id ?>"><i class="bi bi-check2-square"></i> تسجيل حضور</a><?php endif; ?>
  <a class="btn btn-outline-secondary" href="../sessions/calendar.php?group_id=<?= $id ?>"><i class="bi bi-calendar3"></i> حصص المجموعة</a>
  <?php if (can('reports.view')): ?><a class="btn btn-outline-secondary" href="../reports/attendance.php?group_id=<?= $id ?>"><i class="bi bi-bar-chart"></i> تقرير الحضور</a><?php endif; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-5"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-info-circle"></i> البيانات</div><dl class="mb-0 row">
    <dt class="col-5">الرمز</dt><dd class="col-7" dir="ltr"><?= e($g['code']) ?></dd><dt class="col-5">المادة</dt><dd class="col-7"><?= e($g['sname']) ?></dd><dt class="col-5">الصف</dt><dd class="col-7"><?= e($g['lname'] ?: '—') ?></dd>
    <dt class="col-5">المدرس</dt><dd class="col-7"><?= e($g['tname']) ?></dd><dt class="col-5">القاعة</dt><dd class="col-7"><?= e($g['room'] ?: '—') ?></dd><dt class="col-5">الفترة</dt><dd class="col-7"><?= e(fdate($g['start_date'])) ?> → <?= e(fdate($g['end_date'])) ?></dd>
    <dt class="col-5">الحالة</dt><dd class="col-7"><?= status_badge($g['status'], group_statuses()) ?></dd>
    <dt class="col-5">نظام الدفع</dt><dd class="col-7"><?= $g['mode'] ? e(mode_labels()[$g['mode']]) . ' — ' . e(money($g['mode'] === 'monthly' ? $g['monthly_fee'] : $g['session_price'])) : '—' ?></dd></dl></div></div>
  <div class="col-md-7"><div class="row g-3">
    <div class="col-4"><?= stat_card('الطلاب', count($members) . '/' . (int)$g['max_students'], 'people', 'emerald') ?></div>
    <div class="col-4"><?= stat_card('حصص عُقدت', $sessCount, 'calendar-check', 'teal') ?></div>
    <div class="col-4"><?= stat_card('نسبة الحضور', e(attendance_rate((int)$rate['ok'], (int)$rate['total'])), 'graph-up', 'violet') ?></div></div>
    <div class="ecm-card mt-3"><div class="ecm-card-title"><i class="bi bi-calendar-week"></i> الجدول الأسبوعي</div>
    <?php if (!$sched): echo empty_state('لا توجد مواعيد أسبوعية', 'أضف يوماً وساعات الحصة.', '', 'calendar'); else: ?><div class="table-responsive"><table class="table ecm-table"><tbody>
    <?php foreach ($sched as $s): ?><tr><td><?= e(weekday_name((int)$s['weekday'])) ?></td><td><?= e(ftime($s['start_time'])) ?> – <?= e(ftime($s['end_time'])) ?></td><td><?= e($s['room'] ?: ($g['room'] ?: '')) ?></td>
    <td class="text-end"><?php if (can('academics.manage')) echo confirm_button('', ['action' => 'sched_del', 'schedule_id' => $s['id']], 'إيقاف', 'إيقاف هذا الموعد الأسبوعي؟ الحصص التي أُنشئت سابقاً تبقى كما هي.'); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php if (can('academics.manage')): ?><form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="sched_add">
      <div class="col-6 col-md-3"><select class="form-select form-select-sm" name="weekday" required><?= opt_list(weekday_names(), null, true, 'اليوم') ?></select></div>
      <div class="col-3 col-md-2"><input class="form-control form-control-sm" type="time" name="start_time" required></div><div class="col-3 col-md-2"><input class="form-control form-control-sm" type="time" name="end_time" required></div>
      <div class="col-6 col-md-3"><input class="form-control form-control-sm" name="room" placeholder="قاعة مختلفة (اختياري)"></div><div class="col-6 col-md-2"><button class="btn btn-sm btn-primary w-100">إضافة</button></div></form><?php endif; ?></div></div>
</div>
<div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-people"></i> الطلاب المسجلون</div>
<?php if (can('students.manage') && $g['status'] !== 'finished'): ?>
<form method="post" class="row g-2 mb-3 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="enroll">
  <div class="col-md-5"><label class="form-label small mb-0">إضافة طالب</label><select class="form-select" name="student_id" required><?= opt_list(array_column(array_map(fn($c) => ['k' => $c['id'], 'v' => $c['full_name'] . ' — ' . $c['student_no']], $cand), 'v', 'k'), null, true, '— اختر طالباً —') ?></select></div>
  <div class="col-md-5"><label class="form-check"><input class="form-check-input" type="checkbox" name="create_sub" value="1" checked> <span class="form-check-label">إنشاء اشتراك بسعر المجموعة (لا تُسجَّل أي دفعة تلقائياً)</span></label></div>
  <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-plus"></i> تسجيل</button></div></form><?php endif; ?>
<?php if (!$members): echo empty_state('لا يوجد طلاب في المجموعة', '', '', 'people'); else: ?><div class="table-responsive"><table class="table ecm-table"><thead><tr><th>الرقم</th><th>الطالب</th><th>الهاتف</th><th>حالة الاشتراك</th><th>انضم</th><th></th></tr></thead><tbody>
<?php foreach ($members as $m): $ss = subscription_state((int)$m['id'], $id); [$lbl, $cls] = subscription_state_label($ss['state']); ?><tr><td dir="ltr"><?= e($m['student_no']) ?></td><td><a href="../students/view.php?id=<?= (int)$m['id'] ?>"><?= e($m['full_name']) ?></a></td><td dir="ltr" class="text-end"><?= e($m['phone']) ?></td>
<td><?= badge($lbl, $cls) ?><?= $ss['due'] > 0 ? ' ' . badge('متبقي ' . money_c($ss['due']), $ss['overdue'] > 0 ? 'danger' : 'warning') : '' ?></td><td><?= e(fdate($m['joined_at'])) ?></td>
<td class="text-end"><?php if (can('students.manage')) echo confirm_button('', ['action' => 'unenroll', 'student_id' => $m['id']], 'إخراج', 'إخراج الطالب من المجموعة؟ تبقى سجلاته المالية والحضور.'); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php render_footer();
