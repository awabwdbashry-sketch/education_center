<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('payments.collect', 'billing.view');

$id = get_int('id') ?: post_int('id');
$p = row('SELECT p.*, s.full_name, s.student_no, r.receipt_no, r.issued_at, r.print_count, u.full_name AS cashier FROM payments p JOIN students s ON s.id = p.student_id LEFT JOIN receipts r ON r.payment_id = p.id LEFT JOIN users u ON u.id = p.received_by WHERE p.id = ?', [$id]);
if (!$p) {
    abort(404, 'الإيصال غير موجود');
}
require_student_access((int)$p['student_id']);
if (is_post() && post('action') === 'void') {
    require_perm('payments.void');
    try {
        void_payment($id, post('reason'), uid());
        flash('success', 'تم إلغاء الدفعة والإيصال، وأُعيد احتساب أرصدة الفواتير.');
    } catch (FinanceException $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/cashier/receipt.php?id=' . $id);
}
$allocs = rows('SELECT a.amount, i.invoice_no, i.id AS iid, i.total, i.paid_amount, i.status, g.name AS gname FROM payment_allocations a JOIN invoices i ON i.id = a.invoice_id LEFT JOIN class_groups g ON g.id = i.group_id WHERE a.payment_id = ?', [$id]);
$allocSum = array_sum(array_map(fn($a) => to_cents($a['amount']), $allocs));
$unalloc = to_cents($p['amount']) - $allocSum;
if (get('print') === '1' && $p['status'] === 'valid') {
    q('UPDATE receipts SET print_count = print_count + 1 WHERE payment_id = ?', [$id]);
}
$print = get('print') === '1';
render_header('إيصال ' . $p['receipt_no'], $print ? ['print' => true] : []);
?>
<div class="no-print page-actions"><?php if (!$print): ?><a class="btn btn-primary" href="?id=<?= $id ?>&print=1" target="_blank"><i class="bi bi-printer"></i> طباعة الإيصال</a><a class="btn btn-light" href="payments.php">الدفعات</a><a class="btn btn-outline-secondary" href="collect.php?student_id=<?= (int)$p['student_id'] ?>">تحصيل آخر</a><?php else: ?><button class="btn btn-primary" data-print><i class="bi bi-printer"></i> طباعة</button><?php endif; ?></div>
<div class="receipt position-relative"><?php if ($p['status'] === 'voided'): ?><div class="position-absolute top-50 start-50 translate-middle text-danger fw-bold" style="font-size:4rem;opacity:.25;transform:translate(-50%,-50%) rotate(-15deg)">ملغى</div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-3"><div><h2 class="mb-0"><?= e(setting('center_name', '')) ?></h2><div class="small text-muted"><?= e(setting('center_address', '')) ?> <?= e(setting('center_phone', '')) ?></div></div><div class="text-end"><div class="fw-bold">إيصال استلام</div><div dir="ltr" class="fs-5"><?= e($p['receipt_no']) ?></div></div></div>
  <dl class="row mb-2"><dt class="col-3">الطالب</dt><dd class="col-9"><?= e($p['full_name']) ?> <span dir="ltr" class="text-muted">(<?= e($p['student_no']) ?>)</span></dd><dt class="col-3">التاريخ</dt><dd class="col-9"><?= e(fdatetime($p['paid_at'])) ?></dd>
  <dt class="col-3">وسيلة الدفع</dt><dd class="col-9"><?= e(payment_method_label($p['method'])) ?> <?= $p['reference'] ? '— مرجع: <span dir="ltr">' . e($p['reference']) . '</span>' : '' ?></dd><dt class="col-3">المستلِم</dt><dd class="col-9"><?= e($p['cashier']) ?></dd></dl>
  <table class="table table-sm"><thead><tr><th>البند</th><th class="text-end">المبلغ</th><th class="text-end">متبقي الفاتورة بعد الدفع</th></tr></thead><tbody>
  <?php foreach ($allocs as $a): ?><tr><td>فاتورة <?= e($a['invoice_no']) ?> <?= e($a['gname']) ?></td><td class="text-end money"><?= e(money($a['amount'])) ?></td><td class="text-end money"><?= e(money_c(max(0, to_cents($a['total']) - to_cents($a['paid_amount'])))) ?></td></tr><?php endforeach; ?>
  <?php if ($unalloc > 0): ?><tr><td>دفعة مقدمة (رصيد للطالب)</td><td class="text-end money"><?= e(money_c($unalloc)) ?></td><td></td></tr><?php endif; ?></tbody>
  <tfoot><tr><th>الإجمالي المستلم</th><th class="text-end money fs-5"><?= e(money($p['amount'])) ?></th><th></th></tr></tfoot></table>
  <?php if ($p['notes']): ?><div class="small">ملاحظات: <?= e($p['notes']) ?></div><?php endif; ?><?php if ($p['status'] === 'voided'): ?><div class="alert alert-danger mt-2">دفعة ملغاة — السبب: <?= e($p['void_reason']) ?> (<?= e(fdatetime($p['voided_at'])) ?>)</div><?php endif; ?>
  <div class="d-flex justify-content-between mt-4 small text-muted"><span><?= e(setting('receipt_footer', '')) ?></span><span>طُبع <?= (int)$p['print_count'] ?> مرة</span></div></div>
<?php if (!$print && $p['status'] === 'valid' && can('payments.void')): ?><div class="ecm-card mt-3 no-print"><div class="ecm-card-title text-danger"><i class="bi bi-x-octagon"></i> إلغاء الدفعة</div>
  <form method="post" class="row g-2" data-confirm="إلغاء هذه الدفعة سيُعيد المبلغ كمستحق على الطالب ويُبطل الإيصال. متابعة؟"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= $id ?>"><div class="col-md-9"><input class="form-control" name="reason" placeholder="سبب الإلغاء (إلزامي)" required></div><div class="col-md-3"><button class="btn btn-outline-danger w-100">إلغاء الدفعة</button></div></form></div><?php endif; ?>
<?php render_footer();
