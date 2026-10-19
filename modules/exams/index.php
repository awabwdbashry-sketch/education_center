<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/exams_guard.php';
require_perm('exams.view');
require_exams_enabled();

[$gs, $gp] = group_scope_sql('g.id');
$groups = rows("SELECT g.id, g.name, g.subject_id FROM class_groups g WHERE g.status <> 'finished' $gs ORDER BY g.name", $gp);
if (is_post()) {
    require_perm('exams.manage');
    $gid = post_int('group_id');
    require_group_access($gid);
    $g = row('SELECT * FROM class_groups WHERE id = ?', [$gid]);
    $max = parse_money(post('max_score'));
    $title = mb_substr(post('title'), 0, 150);
    if (!$g || $title === '' || !valid_date(post('exam_date')) || $max === null || $max <= 0 || $max > 1000000) {
        flash('error', 'تحقق من العنوان والتاريخ والدرجة النهائية (> 0).');
    } else {
        $id = insert('exams', ['title' => $title, 'group_id' => $gid, 'subject_id' => $g['subject_id'], 'exam_date' => post('exam_date'), 'max_score' => cents_str($max), 'notes' => post('notes') ?: null, 'created_by' => uid()]);
        audit('exam_created', 'exam', $id, ['group' => $gid]);
        flash('success', 'تم إنشاء الاختبار. أدخل الدرجات الآن.');
        redirect('modules/exams/results.php?exam_id=' . $id);
    }
    redirect('modules/exams/index.php');
}
[$gs2, $gp2] = group_scope_sql('e.group_id');
$list = rows("SELECT e.*, g.name AS gname, s.name AS sname, (SELECT COUNT(*) FROM exam_results r WHERE r.exam_id = e.id) AS n, (SELECT AVG(r.score) FROM exam_results r WHERE r.exam_id = e.id) AS avg_
  FROM exams e JOIN class_groups g ON g.id = e.group_id JOIN subjects s ON s.id = e.subject_id WHERE 1=1 $gs2 ORDER BY e.exam_date DESC, e.id DESC LIMIT 100", $gp2);
render_header('الاختبارات');
?>
<?php if (can('exams.manage')): ?><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-plus-circle"></i> اختبار جديد</div><form method="post" class="row g-2"><?= csrf_field() ?>
  <div class="col-md-3"><input class="form-control" name="title" placeholder="عنوان الاختبار" required maxlength="150"></div><div class="col-md-3"><select class="form-select" name="group_id" required><?= opt_list(col_opts($groups, 'id', 'name'), '', true, '— المجموعة —') ?></select></div>
  <div class="col-md-2"><input class="form-control" type="date" name="exam_date" value="<?= e(today()) ?>" required></div><div class="col-md-2"><input class="form-control" name="max_score" placeholder="الدرجة النهائية" required inputmode="decimal" dir="ltr"></div><div class="col-md-2"><button class="btn btn-primary w-100">إنشاء</button></div></form></div><?php endif; ?>
<?php if (!$list): echo '<div class="ecm-card">' . empty_state('لا توجد اختبارات', '', '', 'clipboard-check') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table"><thead><tr><th>الاختبار</th><th>المجموعة</th><th>المادة</th><th>التاريخ</th><th>النهائية</th><th>الدرجات المسجلة</th><th>المتوسط</th><th></th></tr></thead><tbody>
<?php foreach ($list as $e): ?><tr><td><?= e($e['title']) ?></td><td><?= e($e['gname']) ?></td><td><?= e($e['sname']) ?></td><td><?= e(fdate($e['exam_date'])) ?></td><td class="money"><?= e($e['max_score']) ?></td><td><?= (int)$e['n'] ?></td><td class="money"><?= $e['avg_'] !== null ? e(number_format((float)$e['avg_'], 2)) . ' (' . e(round((float)$e['avg_'] * 100 / (float)$e['max_score'], 1)) . '%)' : '—' ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="results.php?exam_id=<?= (int)$e['id'] ?>"><?= can('exams.manage') ? 'الدرجات' : 'عرض' ?></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php render_footer();
