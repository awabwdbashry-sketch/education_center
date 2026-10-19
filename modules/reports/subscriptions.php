<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/report.php';
require_perm('reports.finance');

$st = get('state');
[$gs, $gp] = group_scope_sql('sb.group_id');
$where = "1=1 $gs";
$p = $gp;
$days = (int)setting('subscription_alert_days', 7);
if ($st === 'active') {
    $where .= " AND sb.status = 'active' AND (sb.end_date IS NULL OR sb.end_date >= CURDATE())";
} elseif ($st === 'expired') {
    $where .= " AND sb.status = 'active' AND sb.end_date < CURDATE()";
} elseif ($st === 'expiring') {
    $where .= " AND sb.status = 'active' AND sb.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)";
} elseif ($st === 'cancelled') {
    $where .= " AND sb.status = 'cancelled'";
}
$d = rows("SELECT s.student_no, s.full_name, g.name AS gname, sb.mode, sb.unit_price, sb.discount_amount, sb.start_date, sb.end_date, sb.status FROM subscriptions sb JOIN students s ON s.id = sb.student_id JOIN class_groups g ON g.id = sb.group_id WHERE $where ORDER BY g.name, s.full_name", $p);
$lbl = function ($r) use ($days) {
    if ($r['status'] === 'cancelled') return 'ملغى';
    if ($r['end_date'] && $r['end_date'] < today()) return 'منتهٍ';
    if ($r['end_date'] && $r['end_date'] <= date('Y-m-d', strtotime("+$days day"))) return 'قريب الانتهاء';
    return 'نشط';
};
$f = '<div class="col-md-3"><label class="form-label small mb-0">الحالة</label><select class="form-select" name="state">' . opt_list(['active' => 'نشطة', 'expiring' => 'قريبة الانتهاء', 'expired' => 'منتهية', 'cancelled' => 'ملغاة'], $st, true, 'الكل') . '</select></div>';
report_page('الاشتراكات النشطة والمنتهية', ['الرقم', 'الطالب', 'المجموعة', 'النظام', 'السعر', 'الخصم', 'البداية', 'النهاية', 'الحالة'],
    array_map(fn($r) => [$r['student_no'], $r['full_name'], $r['gname'], mode_labels()[$r['mode']], $r['unit_price'], $r['discount_amount'], $r['start_date'], $r['end_date'] ?: '—', $lbl($r)], $d), $f, 'subscriptions', ['العدد' => count($d)]);
