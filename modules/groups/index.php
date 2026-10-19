<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_perm('academics.view');

[$gsSql, $gsP] = group_scope_sql('g.id');
$qs = get('q');
$st = get('status');
$where = "1=1 $gsSql";
$p = $gsP;
if ($qs !== '') {
    $where .= ' AND (g.name LIKE ? OR g.code LIKE ?)';
    $p[] = "%$qs%";
    $p[] = "%$qs%";
}
if (isset(group_statuses()[$st])) {
    $where .= ' AND g.status = ?';
    $p[] = $st;
}
if (get_int('teacher_id')) {
    $where .= ' AND g.teacher_id = ?';
    $p[] = get_int('teacher_id');
}
$pg = paginate("SELECT COUNT(*) FROM class_groups g WHERE $where", "SELECT g.*, s.name AS sname, t.full_name AS tname, l.name AS lname, ps.mode, ps.monthly_fee, ps.session_price,
  (SELECT COUNT(*) FROM group_students x WHERE x.group_id = g.id AND x.status = 'active') AS n
  FROM class_groups g JOIN subjects s ON s.id = g.subject_id JOIN teachers t ON t.id = g.teacher_id LEFT JOIN academic_levels l ON l.id = g.level_id LEFT JOIN group_payment_settings ps ON ps.group_id = g.id
  WHERE $where ORDER BY g.status, g.name", $p);
render_header('المجموعات');
?>
<form class="filter-bar row g-2 align-items-end" method="get">
  <div class="col-md-5"><input class="form-control" name="q" value="<?= e($qs) ?>" placeholder="بحث بالاسم أو الرمز"></div>
  <div class="col-md-3"><select class="form-select" name="status"><?= opt_list(group_statuses(), $st, true, 'كل الحالات') ?></select></div>
  <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-search"></i> بحث</button><a class="btn btn-light" href="index.php">مسح</a>
  <?php if (can('academics.manage')): ?><a class="btn btn-accent ms-auto" href="form.php"><i class="bi bi-plus-lg"></i> مجموعة جديدة</a><?php endif; ?></div>
</form>
<?php if (!$pg['rows']): echo '<div class="ecm-card">' . empty_state('لا توجد مجموعات', 'أنشئ مجموعة بعد إضافة مادة ومدرس.', can('academics.manage') ? '<a class="btn btn-primary btn-sm" href="form.php">مجموعة جديدة</a>' : '', 'collection') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table table-hover"><thead><tr><th>المجموعة</th><th>المادة / الصف</th><th>المدرس</th><th>الطلاب</th><th>نظام الدفع</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($pg['rows'] as $g): ?><tr><td><a href="view.php?id=<?= (int)$g['id'] ?>"><?= e($g['name']) ?></a><div class="small text-muted" dir="ltr"><?= e($g['code']) ?></div></td>
<td><?= e($g['sname']) ?><div class="small text-muted"><?= e($g['lname']) ?></div></td><td><?= e($g['tname']) ?></td>
<td><span class="<?= (int)$g['n'] >= (int)$g['max_students'] ? 'text-danger fw-bold' : '' ?>"><?= (int)$g['n'] ?>/<?= (int)$g['max_students'] ?></span></td>
<td><?= $g['mode'] ? e(mode_labels()[$g['mode']]) . ' · ' . e(money($g['mode'] === 'monthly' ? $g['monthly_fee'] : $g['session_price'])) : '<span class="text-muted">غير محدد</span>' ?></td>
<td><?= status_badge($g['status'], group_statuses()) ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="view.php?id=<?= (int)$g['id'] ?>">عرض</a><?php if (can('academics.manage')): ?> <a class="btn btn-sm btn-outline-secondary" href="form.php?id=<?= (int)$g['id'] ?>">تعديل</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?= pager($pg); endif; ?>
<?php render_footer();
