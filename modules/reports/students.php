<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/report.php';
require_perm('reports.view');

[$sc, $scp] = student_scope_sql('s');
$where = "1=1 $sc";
$p = $scp;
if (isset(student_statuses()[get('status')])) {
    $where .= ' AND s.status = ?';
    $p[] = get('status');
}
if (get_int('group_id')) {
    require_group_access(get_int('group_id'));
    $where .= " AND EXISTS (SELECT 1 FROM group_students x WHERE x.student_id = s.id AND x.group_id = ? AND x.status = 'active')";
    $p[] = get_int('group_id');
}
$data = rows("SELECT s.student_no, s.full_name, s.phone, l.name AS lvl, s.status, s.registered_at,
 (SELECT GROUP_CONCAT(g.name SEPARATOR ' / ') FROM group_students gs JOIN class_groups g ON g.id = gs.group_id WHERE gs.student_id = s.id AND gs.status = 'active') AS grp,
 (SELECT GROUP_CONCAT(gd.full_name SEPARATOR ' / ') FROM student_guardians sg JOIN guardians gd ON gd.id = sg.guardian_id WHERE sg.student_id = s.id) AS gname,
 (SELECT GROUP_CONCAT(gd.phone SEPARATOR ' / ') FROM student_guardians sg JOIN guardians gd ON gd.id = sg.guardian_id WHERE sg.student_id = s.id) AS gphone
 FROM students s LEFT JOIN academic_levels l ON l.id = s.level_id WHERE $where ORDER BY s.full_name", $p);
[$gg, $ggp] = group_scope_sql('g.id');
$groups = col_opts(rows("SELECT g.id, g.name FROM class_groups g WHERE 1=1 $gg ORDER BY g.name", $ggp), 'id', 'name');
$f = '<div class="col-md-4"><label class="form-label small mb-0">المجموعة</label><select class="form-select" name="group_id">' . opt_list($groups, get('group_id'), true, 'الكل') . '</select></div><div class="col-md-3"><label class="form-label small mb-0">الحالة</label><select class="form-select" name="status">' . opt_list(student_statuses(), get('status'), true, 'الكل') . '</select></div>';
report_page('قائمة الطلاب', ['الرقم', 'الاسم', 'الهاتف', 'الصف', 'الحالة', 'تاريخ التسجيل', 'المجموعات', 'ولي الأمر', 'هاتف ولي الأمر'],
    array_map(fn($r) => [$r['student_no'], $r['full_name'], $r['phone'], $r['lvl'], student_statuses()[$r['status']], $r['registered_at'], $r['grp'], $r['gname'], $r['gphone']], $data), $f, 'students', ['العدد' => count($data)]);
