<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_once __DIR__ . '/../../includes/report.php';
require_perm('reports.view');

$from = valid_date(get('from')) ? get('from') : date('Y-m-01');
$to = valid_date(get('to')) ? get('to') : today();
$mode = in_array(get('mode'), ['log', 'rates'], true) ? get('mode') : 'rates';
$by = in_array(get('by'), ['group', 'student', 'teacher'], true) ? get('by') : 'group';
[$gs, $gp] = group_scope_sql('se.group_id');
$where = "se.session_date BETWEEN ? AND ? $gs";
$p = array_merge([$from, $to], $gp);
foreach (['group_id' => 'se.group_id', 'teacher_id' => 'se.teacher_id', 'student_id' => 'a.student_id'] as $k => $col) {
    if (get_int($k)) {
        $where .= " AND $col = ?";
        $p[] = get_int($k);
    }
}
if ($mode === 'log') {
    $d = rows("SELECT se.session_date, se.start_time, g.name AS gname, s.full_name, s.student_no, a.status, a.method, a.marked_at FROM attendance a JOIN class_sessions se ON se.id = a.session_id JOIN class_groups g ON g.id = se.group_id JOIN students s ON s.id = a.student_id WHERE $where ORDER BY se.session_date DESC, se.start_time, s.full_name", $p);
    $rowsOut = array_map(fn($r) => [$r['session_date'], ftime($r['start_time']), $r['gname'], $r['full_name'], $r['student_no'], attendance_statuses()[$r['status']], $r['method'] === 'qr' ? 'QR' : 'يدوي', $r['marked_at']], $d);
    $headers = ['التاريخ', 'الوقت', 'المجموعة', 'الطالب', 'الرقم', 'الحالة', 'الطريقة', 'وقت التسجيل'];
    $title = 'سجل الحضور والغياب';
} else {
    $sel = ['group' => ['g.name', 'GROUP BY g.id, g.name', 'المجموعة'], 'student' => ['s.full_name', 'GROUP BY s.id, s.full_name', 'الطالب'], 'teacher' => ['t.full_name', 'GROUP BY t.id, t.full_name', 'المدرس']][$by];
    $d = rows("SELECT {$sel[0]} AS label, COUNT(*) AS total, SUM(a.status = 'present') AS pr, SUM(a.status = 'late') AS lt, SUM(a.status = 'excused') AS ex, SUM(a.status = 'absent') AS ab FROM attendance a JOIN class_sessions se ON se.id = a.session_id JOIN class_groups g ON g.id = se.group_id JOIN students s ON s.id = a.student_id JOIN teachers t ON t.id = se.teacher_id WHERE $where {$sel[1]} ORDER BY label", $p);
    $rowsOut = array_map(fn($r) => [$r['label'], (int)$r['total'], (int)$r['pr'], (int)$r['lt'], (int)$r['ex'], (int)$r['ab'], attendance_rate((int)$r['pr'] + (int)$r['lt'], (int)$r['total'])], $d);
    $headers = [$sel[2], 'السجلات', 'حاضر', 'متأخر', 'غياب بعذر', 'غائب', 'نسبة الحضور'];
    $title = 'نسب الحضور حسب ' . $sel[2];
}
[$gg, $ggp] = group_scope_sql('g.id');
$groups = col_opts(rows("SELECT g.id, g.name FROM class_groups g WHERE 1=1 $gg ORDER BY g.name", $ggp), 'id', 'name');
$teachers = col_opts(rows('SELECT id, full_name FROM teachers ORDER BY full_name'), 'id', 'full_name');
$f = '<input type="hidden" name="mode" value="' . e($mode) . '">' . report_date_filters($from, $to) . '<div class="col-md-3"><label class="form-label small mb-0">المجموعة</label><select class="form-select" name="group_id">' . opt_list($groups, get('group_id'), true, 'الكل') . '</select></div>'
    . (can('groups.view_all') ? '<div class="col-md-2"><label class="form-label small mb-0">المدرس</label><select class="form-select" name="teacher_id">' . opt_list($teachers, get('teacher_id'), true, 'الكل') . '</select></div>' : '')
    . ($mode === 'rates' ? '<div class="col-md-2"><label class="form-label small mb-0">التجميع</label><select class="form-select" name="by">' . opt_list(['group' => 'المجموعة', 'student' => 'الطالب', 'teacher' => 'المدرس'], $by) . '</select></div>' : '');
report_page($title, $headers, $rowsOut, $f, 'attendance_' . $mode, [], 'نسبة الحضور = (حاضر + متأخر) ÷ إجمالي السجلات المسجلة. لا تُحتسب حصص لم يُسجَّل لها حضور.');
