<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/report.php';
require_perm('reports.finance', 'teacher_pay.view');

$d = [];
$tot = 0;
foreach (rows('SELECT * FROM teachers ORDER BY full_name') as $t) {
    $b = teacher_balance((int)$t['id']);
    if ($b['accrued'] === 0 && $b['paid'] === 0) {
        continue;
    }
    $tot += $b['balance'];
    $d[] = [$t['full_name'], comp_type_labels()[$t['comp_type']], cents_str($b['accrued']), cents_str($b['paid']), cents_str($b['balance'])];
}
report_page('مستحقات المدرسين', ['المدرس', 'نظام الأجر', 'المحتسب', 'المصروف', 'المتبقي'], $d, '', 'teacher_dues', ['إجمالي المتبقي' => money_c($tot)], 'المبلغ المصروف يُسجَّل مرة واحدة كمصروف عند الصرف الفعلي، فلا يتكرر قيد المستحق والمصروف.');
