<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_once __DIR__ . '/../../includes/report.php';
require_perm('reports.view');

$from = valid_date(get('from')) ? get('from') : today();
$to = valid_date(get('to')) ? get('to') : date('Y-m-d', strtotime('+7 day'));
[$gs, $gp] = group_scope_sql('s.group_id');
$where = "s.session_date BETWEEN ? AND ? $gs";
$p = array_merge([$from, $to], $gp);
foreach (['group_id' => 's.group_id', 'teacher_id' => 's.teacher_id'] as $k => $c) {
    if (get_int($k)) {
        $where .= " AND $c = ?";
        $p[] = get_int($k);
    }
}
$d = rows("SELECT s.*, g.name AS gname, t.full_name AS tname FROM class_sessions s JOIN class_groups g ON g.id = s.group_id JOIN teachers t ON t.id = s.teacher_id WHERE $where ORDER BY s.session_date, s.start_time", $p);
[$gg, $ggp] = group_scope_sql('g.id');
$groups = col_opts(rows("SELECT g.id, g.name FROM class_groups g WHERE 1=1 $gg ORDER BY g.name", $ggp), 'id', 'name');
$f = report_date_filters($from, $to) . '<div class="col-md-4"><label class="form-label small mb-0">المجموعة</label><select class="form-select" name="group_id">' . opt_list($groups, get('group_id'), true, 'الكل') . '</select></div>';
report_page('جدول الحصص', ['التاريخ', 'اليوم', 'من', 'إلى', 'المجموعة', 'المدرس', 'القاعة', 'الحالة'],
    array_map(fn($r) => [$r['session_date'], weekday_name((int)date('N', strtotime($r['session_date']))), ftime($r['start_time']), ftime($r['end_time']), $r['gname'], $r['tname'], $r['room'], session_statuses()[$r['status']]], $d), $f, 'schedule', ['عدد الحصص' => count($d)]);
