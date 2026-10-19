<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('teachers.view');

$qs = get('q');
$st = get('status');
$where = '1=1';
$p = [];
if ($qs !== '') {
    $where .= ' AND (t.full_name LIKE ? OR t.phone LIKE ? OR t.specialization LIKE ?)';
    $like = '%' . $qs . '%';
    $p = [$like, $like, $like];
}
if (in_array($st, ['active', 'inactive'], true)) {
    $where .= ' AND t.status = ?';
    $p[] = $st;
}
$pg = paginate("SELECT COUNT(*) FROM teachers t WHERE $where", "SELECT t.*, (SELECT COUNT(*) FROM class_groups g WHERE g.teacher_id = t.id AND g.status = 'active') AS gc,
  (SELECT GROUP_CONCAT(s.name SEPARATOR '، ') FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id WHERE ts.teacher_id = t.id) AS subs
  FROM teachers t WHERE $where ORDER BY t.status, t.full_name", $p);
render_header('المدرسون');
?>
<form class="filter-bar row g-2 align-items-end" method="get">
  <div class="col-md-5"><input class="form-control" name="q" value="<?= e($qs) ?>" placeholder="بحث بالاسم أو الهاتف أو التخصص"></div>
  <div class="col-md-3"><select class="form-select" name="status"><?= opt_list(['active' => 'نشط', 'inactive' => 'غير نشط'], $st, true, 'كل الحالات') ?></select></div>
  <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-search"></i> بحث</button><a class="btn btn-light" href="index.php">مسح</a>
  <?php if (can('teachers.manage')): ?><a class="btn btn-accent ms-auto" href="form.php"><i class="bi bi-plus-lg"></i> مدرس جديد</a><?php endif; ?></div>
</form>
<?php if (!$pg['rows']): echo '<div class="ecm-card">' . empty_state('لا يوجد مدرسون', $qs !== '' ? 'لا نتائج مطابقة للبحث.' : 'ابدأ بإضافة أول مدرس.', can('teachers.manage') ? '<a class="btn btn-primary btn-sm" href="form.php">إضافة مدرس</a>' : '', 'person-workspace') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table table-hover"><thead><tr><th>المدرس</th><th>الهاتف</th><th>المواد</th><th>مجموعات نشطة</th><th>نظام الأجر</th><?php if (can('teacher_pay.view')): ?><th>المتبقي له</th><?php endif; ?><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($pg['rows'] as $t): ?><tr><td><a href="view.php?id=<?= (int)$t['id'] ?>"><?= e($t['full_name']) ?></a><div class="small text-muted"><?= e($t['specialization']) ?></div></td><td dir="ltr" class="text-end"><?= e($t['phone']) ?></td><td><?= e($t['subs']) ?></td><td><?= (int)$t['gc'] ?></td>
<td><?= e(comp_type_labels()[$t['comp_type']]) ?><?= $t['comp_type'] !== 'none' ? ' · ' . e(money($t['comp_amount'])) : '' ?></td>
<?php if (can('teacher_pay.view')): ?><td class="money"><?= e(money_c(teacher_balance((int)$t['id'])['balance'])) ?></td><?php endif; ?>
<td><?= status_badge($t['status'], ['active' => 'نشط', 'inactive' => 'غير نشط']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="view.php?id=<?= (int)$t['id'] ?>">عرض</a><?php if (can('teachers.manage')): ?> <a class="btn btn-sm btn-outline-secondary" href="form.php?id=<?= (int)$t['id'] ?>">تعديل</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?= pager($pg); endif; ?>
<?php render_footer();
