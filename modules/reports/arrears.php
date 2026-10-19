<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/report.php';
require_perm('reports.finance');

[$gs, $gp] = group_scope_sql('i.group_id');
$where = "i.status IN ('open','partial') $gs";
$p = $gp;
if (get_int('group_id')) {
    $where .= ' AND i.group_id = ?';
    $p[] = get_int('group_id');
}
if (get('overdue') === '1') {
    $where .= ' AND i.due_date < CURDATE()';
}
$d = rows("SELECT s.id, s.student_no, s.full_name, s.phone, COUNT(*) AS n, SUM(i.total - i.paid_amount) AS rest, SUM(CASE WHEN i.due_date < CURDATE() THEN i.total - i.paid_amount ELSE 0 END) AS late, MIN(i.due_date) AS oldest
  FROM invoices i JOIN students s ON s.id = i.student_id WHERE $where GROUP BY s.id, s.student_no, s.full_name, s.phone ORDER BY rest DESC", $p);
$sum = array_sum(array_map(fn($r) => to_cents($r['rest']), $d));
[$gg, $ggp] = group_scope_sql('g.id');
$groups = col_opts(rows("SELECT g.id, g.name FROM class_groups g WHERE 1=1 $gg ORDER BY g.name", $ggp), 'id', 'name');
$f = '<div class="col-md-4"><label class="form-label small mb-0">المجموعة</label><select class="form-select" name="group_id">' . opt_list($groups, get('group_id'), true, 'الكل') . '</select></div><div class="col-md-3"><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="overdue" value="1" ' . (get('overdue') === '1' ? 'checked' : '') . '> <span class="form-check-label">المتأخر فقط</span></label></div>';
report_page('المتأخرات والمستحقات على الطلاب', ['الرقم', 'الطالب', 'الهاتف', 'عدد الفواتير', 'المتبقي', 'منه متأخر', 'أقدم استحقاق'],
    array_map(fn($r) => [$r['student_no'], $r['full_name'], $r['phone'], (int)$r['n'], $r['rest'], $r['late'], $r['oldest']], $d), $f, 'arrears', ['إجمالي المتبقي' => money_c($sum), 'عدد الطلاب' => count($d)], 'الأرصدة محسوبة من الفواتير المستحقة فقط. كشف حساب الطالب متاح من ملفه.');
