<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/report.php';
require_perm('reports.finance');

$from = valid_date(get('from')) ? get('from') : date('Y-m-01', strtotime('-5 month'));
$to = valid_date(get('to')) ? get('to') : today();
$in = [];
foreach (rows("SELECT DATE_FORMAT(paid_at,'%Y-%m') AS ym, SUM(amount) AS t FROM payments WHERE status = 'valid' AND DATE(paid_at) BETWEEN ? AND ? GROUP BY ym", [$from, $to]) as $r) {
    $in[$r['ym']] = to_cents($r['t']);
}
$out = [];
foreach (rows("SELECT DATE_FORMAT(expense_date,'%Y-%m') AS ym, SUM(amount) AS t FROM expenses WHERE status = 'valid' AND expense_date BETWEEN ? AND ? GROUP BY ym", [$from, $to]) as $r) {
    $out[$r['ym']] = to_cents($r['t']);
}
$months = array_unique(array_merge(array_keys($in), array_keys($out)));
sort($months);
$rowsOut = [];
$ti = $to_ = 0;
foreach ($months as $m) {
    $a = $in[$m] ?? 0;
    $b = $out[$m] ?? 0;
    $ti += $a;
    $to_ += $b;
    $rowsOut[] = [$m, cents_str($a), cents_str($b), cents_str($a - $b)];
}
$due = to_cents(val("SELECT COALESCE(SUM(total - paid_amount),0) FROM invoices WHERE status IN ('open','partial')"));
$teacherDue = 0;
foreach (rows('SELECT id FROM teachers') as $t) {
    $teacherDue += max(0, teacher_balance((int)$t['id'])['balance']);
}
$f = report_date_filters($from, $to);
report_page('الملخص المالي والمقارنة الشهرية', ['الشهر', 'المحصّل فعلياً', 'المصروف المسجّل', 'صافي التدفق النقدي'], $rowsOut, $f, 'finance',
    ['المحصّل' => money_c($ti), 'المصروفات' => money_c($to_), 'صافي التدفق النقدي' => money_c($ti - $to_), 'مستحق على الطلاب (الآن)' => money_c($due), 'مستحق للمدرسين (الآن)' => money_c($teacherDue)],
    'صافي التدفق النقدي = المحصّل − المصروف المسجّل، وهو ليس ربحاً محاسبياً دقيقاً لأن النظام لا يعتمد الاستحقاق المحاسبي الكامل. المستحقات تُعرض منفصلة ولا تدخل في الصافي.');
