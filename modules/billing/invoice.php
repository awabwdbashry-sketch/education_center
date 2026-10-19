<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('billing.view');

$id = get_int('id');
$inv = row('SELECT i.*, s.full_name, s.student_no, g.name AS gname FROM invoices i JOIN students s ON s.id = i.student_id LEFT JOIN class_groups g ON g.id = i.group_id WHERE i.id = ?', [$id]);
if (!$inv) {
    abort(404, 'الفاتورة غير موجودة');
}
if ($inv['group_id']) {
    require_group_access((int)$inv['group_id']);
}
if (is_post()) {
    try {
        if (post('action') === 'adjust') {
            require_perm('invoices.adjust');
            $amt = parse_money(post('amount'));
            if ($amt === null) {
                throw new FinanceException('أدخل مبلغاً صحيحاً.');
            }
            adjust_invoice($id, post('sign') === 'discount' ? -abs($amt) : abs($amt), post('reason'), uid());
            flash('success', 'تم تسجيل التسوية بقيد موثق.');
        } elseif (post('action') === 'void') {
            require_perm('invoices.adjust');
            void_invoice($id, post('reason'), uid());
            flash('success', 'تم إلغاء الفاتورة.');
        }
    } catch (FinanceException $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/billing/invoice.php?id=' . $id);
}
$items = rows('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id', [$id]);
$allocs = rows("SELECT a.amount, p.id AS pid, p.paid_at, p.method, p.status, r.receipt_no FROM payment_allocations a JOIN payments p ON p.id = a.payment_id LEFT JOIN receipts r ON r.payment_id = p.id WHERE a.invoice_id = ? ORDER BY a.id", [$id]);
$out = invoice_outstanding($inv);
render_header('فاتورة ' . $inv['invoice_no']);
?>
<div class="page-actions"><?php if ($out > 0 && can('payments.collect')): ?><a class="btn btn-success" href="../cashier/collect.php?student_id=<?= (int)$inv['student_id'] ?>&invoice_id=<?= $id ?>"><i class="bi bi-cash-coin"></i> تحصيل</a><?php endif; ?><button class="btn btn-outline-secondary" data-print><i class="bi bi-printer"></i> طباعة</button><a class="btn btn-light" href="invoices.php">القائمة</a></div>
<div class="ecm-card"><div class="d-flex justify-content-between flex-wrap gap-2"><div><h5 class="mb-0">فاتورة <?= e($inv['invoice_no']) ?></h5><div class="text-muted"><?= e($inv['full_name']) ?> (<span dir="ltr"><?= e($inv['student_no']) ?></span>) · <?= e($inv['gname']) ?></div></div><div><?= status_badge($inv['status'], invoice_statuses()) ?></div></div>
<dl class="row mt-3 mb-0"><dt class="col-3 col-md-2">الإصدار</dt><dd class="col-3 col-md-4"><?= e(fdate($inv['issue_date'])) ?></dd><dt class="col-3 col-md-2">الاستحقاق</dt><dd class="col-3 col-md-4"><?= e(fdate($inv['due_date'])) ?></dd><?php if ($inv['notes']): ?><dt class="col-3 col-md-2">ملاحظات</dt><dd class="col-9 col-md-10"><?= e($inv['notes']) ?></dd><?php endif; ?><?php if ($inv['status'] === 'void'): ?><dt class="col-3 col-md-2">سبب الإلغاء</dt><dd class="col-9 col-md-10 text-danger"><?= e($inv['void_reason']) ?></dd><?php endif; ?></dl></div>
<div class="ecm-card"><table class="table ecm-table"><thead><tr><th>البند</th><th>الكمية</th><th>السعر</th><th>المبلغ</th></tr></thead><tbody><?php foreach ($items as $it): ?><tr><td><?= e($it['description']) ?> <?= $it['item_type'] === 'adjustment' ? badge('تسوية', 'info') : '' ?></td><td><?= e(rtrim(rtrim((string)$it['quantity'], '0'), '.')) ?></td><td class="money"><?= e(money($it['unit_price'])) ?></td><td class="money"><?= e(money($it['amount'])) ?></td></tr><?php endforeach; ?></tbody>
<tfoot><tr><th colspan="3">الإجمالي</th><th class="money"><?= e(money($inv['total'])) ?></th></tr><tr><th colspan="3">المدفوع</th><th class="money text-success"><?= e(money($inv['paid_amount'])) ?></th></tr><tr><th colspan="3">المتبقي</th><th class="money text-danger"><?= e(money_c($out)) ?></th></tr></tfoot></table></div>
<?php if ($allocs): ?><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-receipt"></i> الدفعات المخصصة</div><table class="table ecm-table"><tbody><?php foreach ($allocs as $a): ?><tr class="<?= $a['status'] === 'voided' ? 'text-decoration-line-through text-muted' : '' ?>"><td><a href="../cashier/receipt.php?id=<?= (int)$a['pid'] ?>"><?= e($a['receipt_no']) ?></a></td><td><?= e(fdatetime($a['paid_at'])) ?></td><td><?= e(payment_method_label($a['method'])) ?></td><td class="money"><?= e(money($a['amount'])) ?></td><td><?= $a['status'] === 'voided' ? badge('ملغاة', 'danger') : '' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if (can('invoices.adjust') && $inv['status'] !== 'void'): ?><div class="row g-3 no-print"><div class="col-lg-6"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-sliders"></i> خصم / تسوية موثقة</div>
<form method="post" class="row g-2" data-confirm="تسجيل هذه التسوية على الفاتورة؟"><?= csrf_field() ?><input type="hidden" name="action" value="adjust"><div class="col-4"><select class="form-select" name="sign"><option value="discount">خصم</option><option value="charge">إضافة رسم</option></select></div><div class="col-8"><input class="form-control" name="amount" placeholder="المبلغ" required inputmode="decimal" dir="ltr"></div><div class="col-12"><input class="form-control" name="reason" placeholder="السبب (إلزامي)" required></div><div class="col-12"><button class="btn btn-outline-primary btn-sm">تسجيل</button></div></form></div></div>
<?php if (!$allocs || !array_filter($allocs, fn($a) => $a['status'] === 'valid')): ?><div class="col-lg-6"><div class="ecm-card"><div class="ecm-card-title text-danger"><i class="bi bi-x-octagon"></i> إلغاء الفاتورة</div><form method="post" class="row g-2" data-confirm="إلغاء الفاتورة نهائياً؟ سيُحفظ السجل والسبب."><?= csrf_field() ?><input type="hidden" name="action" value="void"><div class="col-8"><input class="form-control" name="reason" placeholder="السبب (إلزامي)" required></div><div class="col-4"><button class="btn btn-outline-danger w-100">إلغاء</button></div></form></div></div><?php endif; ?></div><?php endif; ?>
<?php render_footer();
