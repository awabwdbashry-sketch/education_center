<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_perm('academics.view');

$manage = can('sessions.manage');
$scope = group_scope();
if (is_post()) {
    require_perm('sessions.manage');
    $act = post('action');
    try {
        if ($act === 'generate') {
            $gid = post_int('group_id');
            if ($gid) {
                require_group_access($gid);
            }
            $ids = $gid ? [$gid] : $scope;
            $r = generate_sessions($ids, post('gen_from'), post('gen_to'), uid());
            flash('success', "تم إنشاء {$r['created']} حصة جديدة (الموجودة مسبقاً: {$r['existing']}). لم يُنشأ أي سجل حضور.");
        } elseif ($act === 'add' || $act === 'update') {
            $gid = post_int('group_id');
            require_group_access($gid);
            $grp = row('SELECT * FROM class_groups WHERE id = ?', [$gid]);
            $date = post('session_date');
            $st = post('start_time');
            $en = post('end_time');
            $room = mb_substr(post('room'), 0, 100);
            if (!$grp || !valid_date($date) || !valid_time($st) || !valid_time($en) || $en <= $st) {
                throw new RuntimeException('بيانات الحصة غير صحيحة.');
            }
            $teacher = post_int('teacher_id') ?: (int)$grp['teacher_id'];
            $room = $room !== '' ? $room : (string)$grp['room'];
            $sid = post_int('id');
            $c = session_conflicts($teacher, $room, $date, $st, $en, $sid ?: null);
            if ($c) {
                throw new RuntimeException('تعارض: ' . implode(' | ', $c));
            }
            if ($act === 'add') {
                $nid = insert('class_sessions', ['group_id' => $gid, 'session_date' => $date, 'start_time' => $st, 'end_time' => $en, 'teacher_id' => $teacher, 'room' => $room ?: null, 'status' => 'scheduled', 'notes' => post('notes') ?: null, 'created_by' => uid()]);
                audit('session_created', 'class_session', $nid, ['date' => $date]);
            } else {
                $cur = row('SELECT * FROM class_sessions WHERE id = ? AND group_id = ?', [$sid, $gid]);
                if (!$cur) {
                    throw new RuntimeException('الحصة غير موجودة.');
                }
                update('class_sessions', ['session_date' => $date, 'start_time' => $st, 'end_time' => $en, 'teacher_id' => $teacher, 'room' => $room ?: null, 'notes' => post('notes') ?: null], 'id = ?', [$sid]);
                audit('session_updated', 'class_session', $sid, ['date' => $date, 'from' => $cur['session_date']]);
            }
            flash('success', 'تم حفظ الحصة.');
        } elseif ($act === 'cancel') {
            $sid = post_int('id');
            $reason = trim(post('reason'));
            $cur = row('SELECT * FROM class_sessions WHERE id = ?', [$sid]);
            if (!$cur) {
                throw new RuntimeException('الحصة غير موجودة.');
            }
            require_group_access((int)$cur['group_id']);
            if ($reason === '') {
                throw new RuntimeException('سبب الإلغاء مطلوب.');
            }
            if ((int)val('SELECT COUNT(*) FROM attendance WHERE session_id = ?', [$sid]) > 0) {
                throw new RuntimeException('لا يمكن إلغاء حصة لها سجلات حضور.');
            }
            q("UPDATE class_sessions SET status = 'cancelled', cancel_reason = ? WHERE id = ?", [mb_substr($reason, 0, 255), $sid]);
            audit('session_cancelled', 'class_session', $sid, ['reason' => $reason]);
            flash('success', 'تم إلغاء الحصة وتسجيل السبب.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        flash('error', is_duplicate_error($e) ? 'توجد حصة لنفس المجموعة في نفس التاريخ والوقت.' : 'تعذر الحفظ.');
        if (!is_duplicate_error($e)) {
            error_log($e->getMessage());
        }
    }
    redirect('modules/sessions/calendar.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
}
$from = valid_date(get('from')) ? get('from') : date('Y-m-d', strtotime('monday this week -2 day'));
$to = valid_date(get('to')) ? get('to') : date('Y-m-d', strtotime($from . ' +13 days'));
$gid = get_int('group_id');
[$gsSql, $gsP] = group_scope_sql('s.group_id');
$where = "s.session_date BETWEEN ? AND ? $gsSql";
$p = array_merge([$from, $to], $gsP);
if ($gid) {
    $where .= ' AND s.group_id = ?';
    $p[] = $gid;
}
$list = rows("SELECT s.*, g.name AS gname, t.full_name AS tname, (SELECT COUNT(*) FROM attendance a WHERE a.session_id = s.id) AS att FROM class_sessions s JOIN class_groups g ON g.id = s.group_id JOIN teachers t ON t.id = s.teacher_id WHERE $where ORDER BY s.session_date, s.start_time", $p);
[$gg, $ggp] = group_scope_sql('g.id');
$groups = rows("SELECT g.id, g.name, g.teacher_id, g.room FROM class_groups g WHERE g.status <> 'finished' $gg ORDER BY g.name", $ggp);
$teachers = col_opts(rows("SELECT id, full_name FROM teachers WHERE status = 'active' ORDER BY full_name"), 'id', 'full_name');
$edit = $manage && get_int('edit') ? row('SELECT * FROM class_sessions WHERE id = ?', [get_int('edit')]) : null;
if ($edit) {
    require_group_access((int)$edit['group_id']);
}
$byDate = [];
foreach ($list as $r) {
    $byDate[$r['session_date']][] = $r;
}
render_header('الحصص والتقويم');
?>
<form class="filter-bar row g-2 align-items-end" method="get">
  <div class="col-6 col-md-2"><label class="form-label small mb-0">من</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">إلى</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-4"><label class="form-label small mb-0">المجموعة</label><select class="form-select" name="group_id"><?= opt_list(col_opts($groups, 'id', 'name'), $gid, true, 'كل المجموعات') ?></select></div>
  <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-funnel"></i> عرض</button><a class="btn btn-light" href="calendar.php">اليوم</a></div>
</form>
<?php if ($manage): ?>
<div class="row g-3 mb-3">
<div class="col-lg-6"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-magic"></i> إنشاء الحصص من الجدول الأسبوعي</div>
 <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="generate">
  <div class="col-12"><select class="form-select" name="group_id"><?= opt_list(col_opts($groups, 'id', 'name'), $gid, true, 'كل المجموعات النشطة') ?></select></div>
  <div class="col-6"><input class="form-control" type="date" name="gen_from" value="<?= e(today()) ?>" required></div><div class="col-6"><input class="form-control" type="date" name="gen_to" value="<?= e(date('Y-m-d', strtotime('+13 days'))) ?>" required></div>
  <div class="col-12"><button class="btn btn-outline-primary">إنشاء الحصص</button> <span class="form-text">آمن لإعادة التشغيل — لا يكرر الحصص ولا ينشئ حضوراً.</span></div></form></div></div>
<div class="col-lg-6"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-plus-circle"></i> <?= $edit ? 'تعديل حصة' : 'حصة منفردة / تعويضية' ?></div>
 <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="col-md-6"><select class="form-select" name="group_id" required <?= $edit ? 'style="pointer-events:none;background:#eee"' : '' ?>><?= opt_list(col_opts($groups, 'id', 'name'), $edit['group_id'] ?? $gid, true, '— المجموعة —') ?></select></div>
  <div class="col-md-6"><select class="form-select" name="teacher_id"><?= opt_list($teachers, $edit['teacher_id'] ?? '', true, 'مدرس المجموعة') ?></select></div>
  <div class="col-md-4"><input class="form-control" type="date" name="session_date" value="<?= e($edit['session_date'] ?? today()) ?>" required></div>
  <div class="col-6 col-md-4"><input class="form-control" type="time" name="start_time" value="<?= e(ftime($edit['start_time'] ?? '')) ?>" required></div><div class="col-6 col-md-4"><input class="form-control" type="time" name="end_time" value="<?= e(ftime($edit['end_time'] ?? '')) ?>" required></div>
  <div class="col-md-6"><input class="form-control" name="room" placeholder="القاعة" value="<?= e($edit['room'] ?? '') ?>"></div><div class="col-md-6"><input class="form-control" name="notes" placeholder="ملاحظة" value="<?= e($edit['notes'] ?? '') ?>"></div>
  <div class="col-12"><button class="btn btn-primary"><?= $edit ? 'حفظ التعديل' : 'إضافة' ?></button><?php if ($edit): ?> <a class="btn btn-light" href="calendar.php">إلغاء</a><?php endif; ?> <span class="form-text">يُمنع تعارض المدرس والقاعة.</span></div></form></div></div></div>
<?php endif; ?>
<?php if (!$byDate): echo '<div class="ecm-card">' . empty_state('لا توجد حصص في هذه الفترة', $manage ? 'أنشئ الحصص من الجدول الأسبوعي أو أضف حصة منفردة.' : '', '', 'calendar3') . '</div>'; else: foreach ($byDate as $d => $items): ?>
<div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-calendar-day"></i> <?= e(weekday_name((int)date('N', strtotime($d)))) ?> — <?= e(fdate($d)) ?> <?= $d === today() ? badge('اليوم', 'success') : '' ?></div>
<div class="table-responsive"><table class="table ecm-table"><tbody>
<?php foreach ($items as $s): ?><tr class="<?= $s['status'] === 'cancelled' ? 'text-decoration-line-through text-muted' : '' ?>"><td style="width:140px"><?= e(ftime($s['start_time'])) ?> – <?= e(ftime($s['end_time'])) ?></td><td><?= e($s['gname']) ?><div class="small text-muted"><?= e($s['tname']) ?> · <?= e($s['room'] ?: '—') ?><?= $s['cancel_reason'] ? ' · سبب الإلغاء: ' . e($s['cancel_reason']) : '' ?></div></td>
<td><?= status_badge($s['status'], session_statuses()) ?> <?= (int)$s['att'] ? '<span class="small text-muted">' . (int)$s['att'] . ' سجل</span>' : '' ?></td>
<td class="text-end text-nowrap"><?php if (can('attendance.mark') && $s['status'] !== 'cancelled' && $s['session_date'] <= today()): ?><a class="btn btn-sm btn-outline-success" href="../attendance/mark.php?session_id=<?= (int)$s['id'] ?>">حضور</a> <?php endif; ?>
<?php if ($manage && $s['status'] !== 'cancelled'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(query_with(['edit' => $s['id']])) ?>">تعديل</a>
<?php if (!(int)$s['att']): ?><form method="post" class="d-inline-flex gap-1" data-confirm="إلغاء هذه الحصة؟ سيُسجَّل السبب ولا يمكن التراجع."><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input class="form-control form-control-sm" style="width:130px" name="reason" placeholder="سبب الإلغاء" required><button class="btn btn-sm btn-outline-danger">إلغاء</button></form><?php endif; endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div><?php endforeach; endif; ?>
<?php render_footer();
