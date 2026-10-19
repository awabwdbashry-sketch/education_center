<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_perm('attendance.view', 'attendance.mark');

$canMark = can('attendance.mark');
$sid = get_int('session_id') ?: post_int('session_id');
$date = valid_date(get('date')) ? get('date') : today();
$gid = get_int('group_id');
[$gsSql, $gsP] = group_scope_sql('g.id');
$groups = col_opts(rows("SELECT g.id, g.name FROM class_groups g WHERE g.status <> 'finished' $gsSql ORDER BY g.name", $gsP), 'id', 'name');

if (is_post()) {
    require_perm('attendance.mark');
    $info = session_info($sid);
    if (!$info) {
        abort(404, 'الحصة غير موجودة');
    }
    require_group_access((int)$info['group_id']);
    try {
        $r = save_attendance_batch($sid, post_arr('status'), post_arr('note'), uid(), can('attendance.edit'));
        $msg = "تم حفظ الكشف: {$r['saved']} جديد، {$r['changed']} معدّل.";
        if ($r['denied']) {
            $msg .= " ({$r['denied']} تعديل مرفوض لعدم وجود صلاحية تعديل الحضور.)";
        }
        flash($r['denied'] ? 'warning' : 'success', $msg);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/attendance/mark.php?session_id=' . $sid);
}
$session = $sid ? session_info($sid) : null;
if ($sid && !$session) {
    abort(404, 'الحصة غير موجودة');
}
if ($session) {
    require_group_access((int)$session['group_id']);
}
$sessionsOfDay = [];
if (!$session) {
    $w = 's.session_date = ?';
    $p = [$date];
    if ($gid) {
        $w .= ' AND s.group_id = ?';
        $p[] = $gid;
    }
    [$ss, $sp] = group_scope_sql('s.group_id');
    $sessionsOfDay = rows("SELECT s.*, g.name AS gname, (SELECT COUNT(*) FROM attendance a WHERE a.session_id = s.id) AS att, (SELECT COUNT(*) FROM group_students x WHERE x.group_id = s.group_id AND x.status = 'active') AS n
        FROM class_sessions s JOIN class_groups g ON g.id = s.group_id WHERE $w AND s.status <> 'cancelled' $ss ORDER BY s.start_time", array_merge($p, $sp));
}
render_header('تسجيل الحضور');
if (!$session): ?>
<form class="filter-bar row g-2 align-items-end" method="get">
  <div class="col-md-3"><label class="form-label small mb-0">التاريخ</label><input class="form-control" type="date" name="date" value="<?= e($date) ?>" max="<?= e(today()) ?>"></div>
  <div class="col-md-5"><label class="form-label small mb-0">المجموعة</label><select class="form-select" name="group_id"><?= opt_list($groups, $gid, true, 'كل المجموعات') ?></select></div>
  <div class="col-md-4"><button class="btn btn-primary"><i class="bi bi-search"></i> عرض الحصص</button></div></form>
<?php if (!$sessionsOfDay): echo '<div class="ecm-card">' . empty_state('لا توجد حصص في هذا اليوم', 'الحضور يُسجَّل لحصص فعلية فقط. أنشئ الحصة من «الحصص والتقويم».', can('sessions.manage') ? '<a class="btn btn-primary btn-sm" href="' . e(url('modules/sessions/calendar.php')) . '">الحصص والتقويم</a>' : '', 'calendar-x') . '</div>'; else: ?>
<div class="row g-3"><?php foreach ($sessionsOfDay as $s): ?><div class="col-md-6 col-xl-4"><div class="ecm-card h-100"><div class="d-flex justify-content-between"><strong><?= e($s['gname']) ?></strong><?= status_badge($s['status'], session_statuses()) ?></div>
  <div class="text-muted small mb-2"><?= e(ftime($s['start_time'])) ?> – <?= e(ftime($s['end_time'])) ?> · <?= e($s['room'] ?: '—') ?></div><div class="small mb-2">سُجِّل <?= (int)$s['att'] ?> من <?= (int)$s['n'] ?></div>
  <?php if ($canMark && $s['session_date'] <= today()): ?><a class="btn btn-primary btn-sm" href="?session_id=<?= (int)$s['id'] ?>">كشف الحضور</a> <a class="btn btn-outline-secondary btn-sm" href="scan.php?session_id=<?= (int)$s['id'] ?>"><i class="bi bi-qr-code-scan"></i> QR</a><?php else: ?><a class="btn btn-outline-primary btn-sm" href="?session_id=<?= (int)$s['id'] ?>">عرض</a><?php endif; ?></div></div><?php endforeach; ?></div>
<?php endif; else:
    $students = rows("SELECT s.id, s.student_no, s.full_name, s.status, a.status AS astatus, a.method, a.notes, a.marked_at FROM group_students gs JOIN students s ON s.id = gs.student_id LEFT JOIN attendance a ON a.session_id = ? AND a.student_id = s.id WHERE gs.group_id = ? AND gs.status = 'active' ORDER BY s.full_name", [$sid, $session['group_id']]);
    $editable = $canMark && $session['status'] !== 'cancelled' && $session['session_date'] <= today();
    $canEditExisting = can('attendance.edit');
    $showFin = can('billing.view');
?>
<div class="ecm-card"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><div><h5 class="mb-0"><?= e($session['group_name']) ?></h5><div class="text-muted"><?= e(fdate($session['session_date'])) ?> · <?= e(ftime($session['start_time'])) ?>–<?= e(ftime($session['end_time'])) ?> · <?= e($session['teacher_name']) ?></div></div>
  <div class="d-flex gap-2"><?= status_badge($session['status'], session_statuses()) ?><a class="btn btn-sm btn-outline-secondary" href="mark.php?date=<?= e($session['session_date']) ?>">حصص اليوم</a><?php if ($editable): ?><a class="btn btn-sm btn-outline-success" href="scan.php?session_id=<?= $sid ?>"><i class="bi bi-qr-code-scan"></i> مسح QR</a><?php endif; ?></div></div></div>
<?php if ($session['status'] === 'cancelled'): ?><div class="alert alert-warning">الحصة ملغاة: <?= e($session['cancel_reason']) ?></div><?php endif; ?>
<?php if (!$students): echo '<div class="ecm-card">' . empty_state('لا يوجد طلاب مسجلون في المجموعة', '', '', 'people') . '</div>'; else: ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="session_id" value="<?= $sid ?>">
<div class="table-responsive"><table class="table ecm-table att-row"><thead><tr><th>الطالب</th><?php if ($showFin): ?><th>الاشتراك</th><?php endif; ?><th>الحالة</th><th>ملاحظة</th></tr></thead><tbody>
<?php foreach ($students as $st): $locked = $st['astatus'] !== null && !$canEditExisting; $dis = (!$editable || $locked) ? 'disabled' : '';
    if ($showFin) { $ss = subscription_state((int)$st['id'], (int)$session['group_id']); [$lbl, $cls] = subscription_state_label($ss['state']); } ?>
<tr><td><a href="../students/view.php?id=<?= (int)$st['id'] ?>"><?= e($st['full_name']) ?></a><div class="small text-muted" dir="ltr"><?= e($st['student_no']) ?><?= $st['method'] === 'qr' ? ' · QR ' . e(date('H:i', strtotime($st['marked_at']))) : '' ?></div></td>
<?php if ($showFin): ?><td><?= badge($lbl, $cls) ?><?= $ss['overdue'] > 0 ? ' ' . badge('متأخر ' . money_c($ss['overdue']), 'danger') : ($ss['due'] > 0 ? ' ' . badge('متبقي ' . money_c($ss['due']), 'warning') : '') ?></td><?php endif; ?>
<td><div class="btn-group btn-group-sm" role="group"><?php foreach (attendance_statuses() as $k => $l): $col = ['present' => 'success', 'absent' => 'danger', 'late' => 'warning', 'excused' => 'info'][$k]; $cur = $st['astatus'] ?? ''; ?>
  <input type="radio" class="btn-check" name="status[<?= (int)$st['id'] ?>]" id="s<?= (int)$st['id'] . $k ?>" value="<?= $k ?>" <?= $cur === $k ? 'checked' : '' ?> <?= $dis ?>><label class="btn btn-outline-<?= $col ?>" for="s<?= (int)$st['id'] . $k ?>"><?= e($l) ?></label><?php endforeach; ?></div>
</td>
<td><input class="form-control form-control-sm" name="note[<?= (int)$st['id'] ?>]" value="<?= e($st['notes']) ?>" maxlength="255" <?= $dis ?>></td></tr><?php endforeach; ?></tbody></table></div>
<?php if ($editable): ?><div class="d-flex gap-2 mt-3"><button class="btn btn-primary"><i class="bi bi-save"></i> حفظ الكشف</button><button type="button" class="btn btn-outline-success" id="allPresent">تحديد الكل «حاضر» لغير المسجلين</button><span class="form-text align-self-center">الطالب الذي لم تحدد حالته لا يُنشأ له سجل.</span></div>
<?php elseif (!$canMark): ?><div class="alert alert-info mt-3">عرض فقط — لا تملك صلاحية تسجيل الحضور.</div><?php endif; ?></form>
<?php inline_script('document.getElementById("allPresent")&&document.getElementById("allPresent").addEventListener("click",function(){document.querySelectorAll("input[type=radio][value=present]:not(:disabled)").forEach(function(r){var n=r.name;if(!document.querySelector("input[name=\""+n+"\"]:checked"))r.checked=true;});});'); endif; endif; render_footer();
