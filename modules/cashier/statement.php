<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('billing.view');

$sid = get_int('student_id');
$s = row('SELECT * FROM students WHERE id = ?', [$sid]);
if (!$s) {
    abort(404, 'الطالب غير موجود');
}
require_student_access($sid);
$from = valid_date(get('from')) ? get('from') : '';
$to = valid_date(get('to')) ? get('to') : '';
$ev = [];
foreach (rows("SELECT id, invoice_no, issue_date, total, status FROM invoices WHERE student_id = ? AND status <> 'void'", [$sid]) as $i) {
    $ev[] = ['d' => $i['issue_date'], 'k' => 0, 'desc' => 'فاتورة ' . $i['invoice_no'], 'debit' => to_cents($i['total']), 'credit' => 0, 'id' => (int)$i['id']];
}
foreach (rows("SELECT p.id, p.amount, p.paid_at, p.method, r.receipt_no FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.student_id = ? AND p.status = 'valid'", [$sid]) as $p) {
    $ev[] = ['d' => substr($p['paid_at'], 0, 10), 'k' => 1, 'desc' => 'دفعة — إيصال ' . $p['receipt_no'] . ' (' . payment_method_label($p['method']) . ')', 'debit' => 0, 'credit' => to_cents($p['amount']), 'id' => (int)$p['id']];
}
usort($ev, fn($a, $b) => [$a['d'], $a['k'], $a['id']] <=> [$b['d'], $b['k'], $b['id']]);
$run = 0;
foreach ($ev as &$e) {
    $run += $e['debit'] - $e['credit'];
    $e['bal'] = $run;
}
unset($e);
$ev = array_values(array_filter($ev, fn($e) => (!$from || $e['d'] >= $from) && (!$to || $e['d'] <= $to)));
if (get('export') === 'csv') {
    require_perm('reports.export');
    audit('export_statement', 'student', $sid);
    csv_download('statement_' . $s['student_no'] . '.csv', ['التاريخ', 'البيان', 'مدين', 'دائن', 'الرصيد'], array_map(fn($e) => [$e['d'], $e['desc'], cents_str($e['debit']), cents_str($e['credit']), cents_str($e['bal'])], $ev));
}
$bal = student_balance($sid);
render_header('كشف حساب', get('print') === '1' ? ['print' => true] : []);
?>
<div class="no-print page-actions"><a class="btn btn-primary" target="_blank" href="<?= e(query_with(['print' => '1'])) ?>"><i class="bi bi-printer"></i> نسخة للطباعة</a><?php if (can('reports.export')): ?><a class="btn btn-outline-secondary" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a><?php endif; ?><?php if (get('print') !== '1'): ?><a class="btn btn-light" href="../students/view.php?id=<?= $sid ?>">ملف الطالب</a><?php else: ?><button class="btn btn-primary" data-print>طباعة</button><?php endif; ?></div>
<?php if (get('print') !== '1'): ?><form class="filter-bar row g-2 no-print" method="get"><input type="hidden" name="student_id" value="<?= $sid ?>"><div class="col-md-3"><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div><div class="col-md-3"><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div><div class="col-md-2"><button class="btn btn-primary">تصفية</button></div></form><?php endif; ?>
<div class="ecm-card"><div class="text-center mb-3"><h4 class="mb-0"><?= e(setting('center_name', '')) ?></h4><div>كشف حساب الطالب</div></div>
<dl class="row"><dt class="col-3">الطالب</dt><dd class="col-9"><?= e($s['full_name']) ?> (<span dir="ltr"><?= e($s['student_no']) ?></span>)</dd><dt class="col-3">تاريخ الكشف</dt><dd class="col-9"><?= e(fdate(today())) ?></dd></dl>
<?php if (!$ev): echo empty_state('لا توجد حركات', '', '', 'file-earmark'); else: ?><table class="table ecm-table"><thead><tr><th>التاريخ</th><th>البيان</th><th class="text-end">مدين (مستحق)</th><th class="text-end">دائن (مدفوع)</th><th class="text-end">الرصيد</th></tr></thead><tbody>
<?php foreach ($ev as $e): ?><tr><td><?= e(fdate($e['d'])) ?></td><td><?= e($e['desc']) ?></td><td class="text-end money"><?= $e['debit'] ? e(money_c($e['debit'])) : '' ?></td><td class="text-end money"><?= $e['credit'] ? e(money_c($e['credit'])) : '' ?></td><td class="text-end money"><?= e(money_c($e['bal'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<div class="d-flex flex-wrap gap-4 mt-2"><div>المتبقي المستحق الآن: <strong class="money"><?= e(money_c($bal['due'])) ?></strong></div><div>منه متأخر: <strong class="money"><?= e(money_c($bal['overdue'])) ?></strong></div><div>رصيد مقدم غير مخصص: <strong class="money"><?= e(money_c($bal['credit'])) ?></strong></div></div>
<div class="form-text mt-2">الرصيد الجاري = مجموع الفواتير غير الملغاة − مجموع الدفعات المعتمدة؛ الدفعات المقدمة تظهر كرصيد دائن سالب.</div></div>
<?php render_footer();
