<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('students.view');

[$scSql, $scP] = student_scope_sql('s');
$qs = get('q');
$where = "1=1 $scSql";
$p = $scP;
if ($qs !== '') {
    $where .= ' AND (s.full_name LIKE ? OR s.student_no LIKE ? OR s.phone LIKE ? OR EXISTS (SELECT 1 FROM student_guardians sg JOIN guardians gd ON gd.id = sg.guardian_id WHERE sg.student_id = s.id AND gd.phone LIKE ?))';
    $like = '%' . $qs . '%';
    array_push($p, $like, $like, $like, $like);
}
if (isset(student_statuses()[get('status')])) {
    $where .= ' AND s.status = ?';
    $p[] = get('status');
}
if (get_int('level_id')) {
    $where .= ' AND s.level_id = ?';
    $p[] = get_int('level_id');
}
if (get_int('group_id')) {
    $where .= " AND EXISTS (SELECT 1 FROM group_students x WHERE x.student_id = s.id AND x.group_id = ? AND x.status = 'active')";
    $p[] = get_int('group_id');
}
if (get('export') === 'csv') {
    require_perm('reports.export');
    $all = rows("SELECT s.student_no, s.full_name, s.phone, l.name AS lvl, s.status, s.registered_at,
      (SELECT GROUP_CONCAT(gd.full_name SEPARATOR ' / ') FROM student_guardians sg JOIN guardians gd ON gd.id = sg.guardian_id WHERE sg.student_id = s.id) AS gname,
      (SELECT GROUP_CONCAT(gd.phone SEPARATOR ' / ') FROM student_guardians sg JOIN guardians gd ON gd.id = sg.guardian_id WHERE sg.student_id = s.id) AS gphone
      FROM students s LEFT JOIN academic_levels l ON l.id = s.level_id WHERE $where ORDER BY s.full_name", $p);
    audit('export_students', 'student', null, ['rows' => count($all)]);
    csv_download('students_' . date('Ymd') . '.csv', ['رقم الطالب', 'الاسم', 'الهاتف', 'الصف', 'الحالة', 'تاريخ التسجيل', 'ولي الأمر', 'هاتف ولي الأمر'],
        array_map(fn($r) => [$r['student_no'], $r['full_name'], $r['phone'], $r['lvl'], student_statuses()[$r['status']], $r['registered_at'], $r['gname'], $r['gphone']], $all));
}
$pg = paginate("SELECT COUNT(*) FROM students s WHERE $where", "SELECT s.*, l.name AS lname,
  (SELECT GROUP_CONCAT(g.name SEPARATOR '، ') FROM group_students gs JOIN class_groups g ON g.id = gs.group_id WHERE gs.student_id = s.id AND gs.status = 'active') AS groups_
  FROM students s LEFT JOIN academic_levels l ON l.id = s.level_id WHERE $where ORDER BY s.id DESC", $p, 20);
[$gsSql, $gsP] = group_scope_sql('g.id');
$groups = col_opts(rows("SELECT g.id, g.name FROM class_groups g WHERE 1=1 $gsSql ORDER BY g.name", $gsP), 'id', 'name');
$levels = col_opts(rows('SELECT id, name FROM academic_levels ORDER BY sort_order, name'), 'id', 'name');
$showFin = can('billing.view');
render_header('الطلاب');
?>
<form class="filter-bar row g-2 align-items-end" method="get">
  <div class="col-md-3"><input class="form-control" name="q" value="<?= e($qs) ?>" placeholder="اسم / رقم / هاتف الطالب أو ولي الأمر"></div>
  <div class="col-md-2"><select class="form-select" name="group_id"><?= opt_list($groups, get('group_id'), true, 'كل المجموعات') ?></select></div>
  <div class="col-md-2"><select class="form-select" name="level_id"><?= opt_list($levels, get('level_id'), true, 'كل الصفوف') ?></select></div>
  <div class="col-md-2"><select class="form-select" name="status"><?= opt_list(student_statuses(), get('status'), true, 'كل الحالات') ?></select></div>
  <div class="col-md-3 d-flex gap-1"><button class="btn btn-primary"><i class="bi bi-search"></i></button><a class="btn btn-light" href="index.php">مسح</a>
    <?php if (can('reports.export')): ?><a class="btn btn-outline-secondary" href="<?= e(query_with(['export' => 'csv'])) ?>" title="تصدير CSV"><i class="bi bi-filetype-csv"></i></a><?php endif; ?>
    <?php if (can('students.manage')): ?><a class="btn btn-accent ms-auto" href="form.php"><i class="bi bi-plus-lg"></i> طالب جديد</a><?php endif; ?></div>
</form>
<?php if (!$pg['rows']): echo '<div class="ecm-card">' . empty_state('لا يوجد طلاب', $qs !== '' || get('group_id') ? 'لا نتائج مطابقة للتصفية.' : 'ابدأ بتسجيل أول طالب.', can('students.manage') ? '<a class="btn btn-primary btn-sm" href="form.php">طالب جديد</a>' : '', 'people') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table table-hover"><thead><tr><th>الرقم</th><th>الطالب</th><th>الصف</th><th>المجموعات</th><th>الهاتف</th><?php if ($showFin): ?><th>المتبقي</th><?php endif; ?><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($pg['rows'] as $s): ?><tr><td dir="ltr"><?= e($s['student_no']) ?></td>
<td><div class="d-flex align-items-center gap-2"><?php if ($s['photo_path']): ?><img class="avatar" src="<?= e(url('file.php?f=' . rawurlencode($s['photo_path']))) ?>" alt=""><?php else: ?><span class="avatar"><?= e(mb_substr($s['full_name'], 0, 1)) ?></span><?php endif; ?><a href="view.php?id=<?= (int)$s['id'] ?>"><?= e($s['full_name']) ?></a></div></td>
<td><?= e($s['lname']) ?></td><td class="small"><?= e($s['groups_']) ?></td><td dir="ltr" class="text-end"><?= e($s['phone']) ?></td>
<?php if ($showFin): $b = student_balance((int)$s['id']); ?><td class="money"><?= $b['due'] > 0 ? '<span class="' . ($b['overdue'] > 0 ? 'text-danger' : 'text-warning-emphasis') . '">' . e(money_c($b['due'])) . '</span>' : '—' ?></td><?php endif; ?>
<td><?= status_badge($s['status'], student_statuses()) ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="view.php?id=<?= (int)$s['id'] ?>">عرض</a><?php if (can('students.manage')): ?> <a class="btn btn-sm btn-outline-secondary" href="form.php?id=<?= (int)$s['id'] ?>">تعديل</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?= pager($pg); endif; ?>
<?php render_footer();
