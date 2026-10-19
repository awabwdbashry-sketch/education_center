<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('payments.collect', 'billing.view');

$from = valid_date(get('from')) ? get('from') : date('Y-m-01');
$to = valid_date(get('to')) ? get('to') : today();
$where = 'DATE(p.paid_at) BETWEEN ? AND ?';
$p = [$from, $to];
if (get('q') !== '') {
    $where .= ' AND (s.full_name LIKE ? OR s.student_no LIKE ? OR r.receipt_no LIKE ?)';
    array_push($p, '%' . get('q') . '%', '%' . get('q') . '%', '%' . get('q') . '%');
}
if (get('method') !== '') {
    $where .= ' AND p.method = ?';
    $p[] = get('method');
}
if (in_array(get('status'), ['valid', 'voided'], true)) {
    $where .= ' AND p.status = ?';
    $p[] = get('status');
}
[$scSql, $scP] = student_scope_sql('s');
$where .= $scSql;
$p = array_merge($p, $scP);
$from_sql = 'FROM payments p JOIN students s ON s.id = p.student_id LEFT JOIN receipts r ON r.payment_id = p.id WHERE ' . $where;
$tot = row("SELECT COALESCE(SUM(CASE WHEN p.status='valid' THEN p.amount END),0) AS t, COALESCE(SUM(p.status='voided'),0) AS v $from_sql", $p);
$pg = paginate("SELECT COUNT(*) $from_sql", "SELECT p.*, s.full_name, s.student_no, r.receipt_no $from_sql ORDER BY p.id DESC", $p, 25);
render_header('الدفعات والإيصالات');
?>
<form class="filter-bar row g-2 align-items-end" method="get">
  <div class="col-6 col-md-2"><label class="form-label small mb-0">من</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div><div class="col-6 col-md-2"><label class="form-label small mb-0">إلى</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-3"><label class="form-label small mb-0">بحث</label><input class="form-control" name="q" value="<?= e(get('q')) ?>" placeholder="طالب / رقم إيصال"></div>
  <div class="col-md-2"><label class="form-label small mb-0">الوسيلة</label><select class="form-select" name="method"><?= opt_list(array_map(fn($m) => $m['label'], payment_methods(false)), get('method'), true, 'الكل') ?></select></div>
  <div class="col-md-1"><label class="form-label small mb-0">الحالة</label><select class="form-select" name="status"><?= opt_list(['valid' => 'معتمدة', 'voided' => 'ملغاة'], get('status'), true, 'الكل') ?></select></div>
  <div class="col-md-2 d-flex gap-1"><button class="btn btn-primary"><i class="bi bi-search"></i></button><a class="btn btn-light" href="payments.php">مسح</a></div></form>
<div class="alert alert-light border">إجمالي المحصّل المعتمد في الفترة: <strong class="money text-success"><?= e(money($tot['t'])) ?></strong> · دفعات ملغاة: <?= (int)$tot['v'] ?></div>
<?php if (!$pg['rows']): echo '<div class="ecm-card">' . empty_state('لا توجد دفعات في هذه الفترة', '', '', 'receipt') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table table-hover"><thead><tr><th>الإيصال</th><th>الطالب</th><th>التاريخ</th><th>الوسيلة</th><th>المبلغ</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($pg['rows'] as $r): ?><tr class="<?= $r['status'] === 'voided' ? 'text-muted' : '' ?>"><td dir="ltr" class="text-end"><?= e($r['receipt_no']) ?></td><td><a href="../students/view.php?id=<?= (int)$r['student_id'] ?>&tab=receipts"><?= e($r['full_name']) ?></a></td><td><?= e(fdatetime($r['paid_at'])) ?></td><td><?= e(payment_method_label($r['method'])) ?></td><td class="money <?= $r['status'] === 'voided' ? 'text-decoration-line-through' : '' ?>"><?= e(money($r['amount'])) ?></td><td><?= status_badge($r['status'], ['valid' => 'معتمدة', 'voided' => 'ملغاة']) ?></td><td><a class="btn btn-sm btn-outline-secondary" href="receipt.php?id=<?= (int)$r['id'] ?>">عرض / إعادة طباعة</a></td></tr><?php endforeach; ?></tbody></table></div><?= pager($pg); endif; ?>
<?php render_footer();
