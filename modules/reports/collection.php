<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/report.php';
require_perm('reports.finance');

$from = valid_date(get('from')) ? get('from') : date('Y-m-01');
$to = valid_date(get('to')) ? get('to') : today();
$by = get('by') === 'month' ? 'month' : (get('by') === 'method' ? 'method' : 'day');
$fmt = $by === 'month' ? '%Y-%m' : '%Y-%m-%d';
$col = $by === 'method' ? 'p.method' : "DATE_FORMAT(p.paid_at, '$fmt')";
$d = rows("SELECT $col AS k, COUNT(*) AS n, SUM(p.amount) AS total FROM payments p WHERE p.status = 'valid' AND DATE(p.paid_at) BETWEEN ? AND ? GROUP BY k ORDER BY k", [$from, $to]);
$voided = row("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS t FROM payments WHERE status = 'voided' AND DATE(paid_at) BETWEEN ? AND ?", [$from, $to]);
$sum = array_sum(array_map(fn($r) => to_cents($r['total']), $d));
$f = report_date_filters($from, $to) . '<div class="col-md-3"><label class="form-label small mb-0">التجميع</label><select class="form-select" name="by">' . opt_list(['day' => 'يومي', 'month' => 'شهري', 'method' => 'حسب وسيلة الدفع'], $by) . '</select></div>';
report_page('تقرير التحصيل', [$by === 'method' ? 'الوسيلة' : ($by === 'month' ? 'الشهر' : 'اليوم'), 'عدد الدفعات', 'المحصّل'],
    array_map(fn($r) => [$by === 'method' ? payment_method_label($r['k']) : $r['k'], (int)$r['n'], $r['total']], $d), $f, 'collection', ['إجمالي المحصّل الفعلي' => money_c($sum), 'دفعات ملغاة (مستبعدة)' => (int)$voided['n'] . ' / ' . money($voided['t'])], 'يشمل الدفعات المعتمدة فقط (الإيراد المحصّل فعلياً)، ولا يُحسب من إجمالي الاشتراكات.');
