<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/exams_guard.php';
require_once __DIR__ . '/../../includes/report.php';
require_perm('exams.view');
require_exams_enabled();

[$gs, $gp] = group_scope_sql('e.group_id');
$where = "1=1 $gs";
$p = $gp;
foreach (['group_id' => 'e.group_id', 'student_id' => 'r.student_id', 'exam_id' => 'e.id'] as $k => $c) {
    if (get_int($k)) {
        $where .= " AND $c = ?";
        $p[] = get_int($k);
    }
}
if (get('by') === 'group') {
    $d = rows("SELECT g.name AS label, COUNT(DISTINCT e.id) AS exams, COUNT(r.id) AS n, AVG(r.score * 100 / e.max_score) AS pct FROM exam_results r JOIN exams e ON e.id = r.exam_id JOIN class_groups g ON g.id = e.group_id WHERE $where GROUP BY g.id, g.name ORDER BY pct DESC", $p);
    $out = array_map(fn($r) => [$r['label'], (int)$r['exams'], (int)$r['n'], round((float)$r['pct'], 1) . '%'], $d);
    $h = ['المجموعة', 'الاختبارات', 'الدرجات', 'متوسط النسبة'];
} else {
    $d = rows("SELECT e.exam_date, e.title, g.name AS gname, s.full_name, r.score, e.max_score, r.notes FROM exam_results r JOIN exams e ON e.id = r.exam_id JOIN class_groups g ON g.id = e.group_id JOIN students s ON s.id = r.student_id WHERE $where ORDER BY e.exam_date DESC, s.full_name", $p);
    $out = array_map(fn($r) => [$r['exam_date'], $r['title'], $r['gname'], $r['full_name'], $r['score'], $r['max_score'], round((float)$r['score'] * 100 / (float)$r['max_score'], 1) . '%', $r['notes']], $d);
    $h = ['التاريخ', 'الاختبار', 'المجموعة', 'الطالب', 'الدرجة', 'النهائية', 'النسبة', 'ملاحظة'];
}
[$gg, $ggp] = group_scope_sql('g.id');
$groups = col_opts(rows("SELECT g.id, g.name FROM class_groups g WHERE 1=1 $gg ORDER BY g.name", $ggp), 'id', 'name');
$f = '<div class="col-md-4"><label class="form-label small mb-0">المجموعة</label><select class="form-select" name="group_id">' . opt_list($groups, get('group_id'), true, 'الكل') . '</select></div><div class="col-md-3"><label class="form-label small mb-0">العرض</label><select class="form-select" name="by">' . opt_list(['' => 'تفصيلي بالطالب', 'group' => 'مقارنة المجموعات'], get('by')) . '</select></div>';
report_page('نتائج الاختبارات', $h, $out, $f, 'exams');
