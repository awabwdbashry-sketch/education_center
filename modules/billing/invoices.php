<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('billing.view');

[$gsSql, $gsP] = group_scope_sql('i.group_id');
$where = "1=1 $gsSql";
$p = $gsP;
$qs = get('q');
$status = get('status');
if ($qs !== '') {
    $where .= ' AND (s.full_name LIKE ? OR s.student_no LIKE ? OR i.invoice_no LIKE ?)';
    array_push($p, "%$qs%", "%$qs%", "%$qs%");
}
if ($status === 'due') {
    $where .= " AND i.status IN ('open','partial')";
} elseif ($status === 'overdue') {
    $where .= " AND i.status IN ('open','partial') AND i.due_date < CURDATE()";
} elseif (isset(invoice_statuses()[$status])) {
    $where .= ' AND i.status = ?';
    $p[] = $status;
}
if (get_int('group_id')) {
    $where .= ' AND i.group_id = ?';
    $p[] = get_int('group_id');
}
if (get_int('student_id')) {
    $where .= ' AND i.student_id = ?';
    $p[] = get_int('student_id');
}
$pg = paginate("SELECT COUNT(*) FROM invoices i JOIN students s ON s.id = i.student_id WHERE $where", "SELECT i.*, s.full_name, s.student_no, g.name AS gname FROM invoices i JOIN students s ON s.id = i.student_id LEFT JOIN class_groups g ON g.id = i.group_id WHERE $where ORDER BY i.id DESC", $p);
$sum = row("SELECT COALESCE(SUM(i.total - i.paid_amount),0) AS rest FROM invoices i JOIN students s ON s.id = i.student_id WHERE $where AND i.status IN ('open','partial')", $p);
[$gg, $ggp] = group_scope_sql('g.id');
$groups = col_opts(rows("SELECT g.id, g.name FROM class_groups g WHERE 1=1 $gg ORDER BY g.name", $ggp), 'id', 'name');
render_header('الفواتير والمستحقات');
?>
<form class="filter-bar row g-2 align-items-end" method="get"><input type="hidden" name="student_id" value="<?= get_int('student_id') ?: '' ?>">
  <div class="col-md-3"><input class="form-control" name="q" value="<?= e($qs) ?>" placeholder="طالب / رقم فاتورة"></div>
  <div class="col-md-3"><select class="form-select" name="group_id"><?= opt_list($groups, get('group_id'), true, 'كل المجموعات') ?></select></div>
  <div class="col-md-3"><select class="form-select" name="status"><?= opt_list(['due' => 'كل المستحق', 'overdue' => 'المتأخر'] + invoice_statuses(), $status, true, 'كل الحالات') ?></select></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-search"></i></button><a class="btn btn-light" href="invoices.php">مسح</a></div></form>
<div class="alert alert-light border">إجمالي المتبقي في النتائج المعروضة (المستحق فقط): <strong class="money"><?= e(money($sum['rest'])) ?></strong></div>
<?php if (!$pg['rows']): echo '<div class="ecm-card">' . empty_state('لا توجد فواتير', 'تُولَّد من «توليد الاستحقاقات».', '', 'file-earmark-text') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table table-hover"><thead><tr><th>الفاتورة</th><th>الطالب</th><th>المجموعة</th><th>الاستحقاق</th><th>الإجمالي</th><th>المدفوع</th><th>المتبقي</th><th>الحالة</th></tr></thead><tbody>
<?php foreach ($pg['rows'] as $i): $late = in_array($i['status'], ['open', 'partial'], true) && $i['due_date'] < today(); ?><tr><td><a href="invoice.php?id=<?= (int)$i['id'] ?>"><?= e($i['invoice_no']) ?></a></td><td><?= e($i['full_name']) ?></td><td><?= e($i['gname']) ?></td><td class="<?= $late ? 'text-danger fw-bold' : '' ?>"><?= e(fdate($i['due_date'])) ?></td>
<td class="money"><?= e(money($i['total'])) ?></td><td class="money"><?= e(money($i['paid_amount'])) ?></td><td class="money"><?= e(money_c(invoice_outstanding($i))) ?></td><td><?= status_badge($i['status'], invoice_statuses()) ?><?= $late ? ' ' . badge('متأخرة', 'danger') : '' ?></td></tr><?php endforeach; ?></tbody></table></div><?= pager($pg); endif; ?>
<?php render_footer();
