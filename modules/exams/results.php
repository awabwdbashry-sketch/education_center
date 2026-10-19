<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/exams_guard.php';
require_perm('exams.view');
require_exams_enabled();

$id = get_int('exam_id') ?: post_int('exam_id');
$ex = row('SELECT e.*, g.name AS gname, s.name AS sname FROM exams e JOIN class_groups g ON g.id = e.group_id JOIN subjects s ON s.id = e.subject_id WHERE e.id = ?', [$id]);
if (!$ex) {
    abort(404, 'الاختبار غير موجود');
}
require_group_access((int)$ex['group_id']);
$max = to_cents($ex['max_score']);
if (is_post()) {
    require_perm('exams.manage');
    $enrolled = array_map('intval', array_column(rows("SELECT student_id FROM group_students WHERE group_id = ? AND status = 'active'", [$ex['group_id']]), 'student_id'));
    $errs = [];
    $toSave = [];
    foreach (post_arr('score') as $sid => $v) {
        $v = trim((string)$v);
        if ($v === '') {
            continue;
        }
        $sid = (int)$sid;
        $c = parse_money($v);
        if (!in_array($sid, $enrolled, true)) {
            continue;
        }
        if ($c === null || $c < 0 || $c > $max) {
            $errs[] = 'درجة غير صالحة للطالب #' . $sid . ' (يجب أن تكون بين 0 و' . $ex['max_score'] . ').';
            continue;
        }
        $toSave[$sid] = $c;
    }
    if ($errs) {
        flash('error', implode(' ', array_slice($errs, 0, 3)) . ' لم يُحفظ شيء.');
    } else {
        tx(function () use ($toSave, $id) {
            foreach ($toSave as $sid => $c) {
                q('INSERT INTO exam_results (exam_id, student_id, score, notes, entered_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE score = VALUES(score), notes = VALUES(notes), entered_by = VALUES(entered_by)',
                    [$id, $sid, cents_str($c), mb_substr(trim((string)($_POST['note'][$sid] ?? '')), 0, 255) ?: null, uid()]);
            }
            audit('exam_scores_saved', 'exam', $id, ['count' => count($toSave)]);
        });
        flash('success', 'تم حفظ ' . count($toSave) . ' درجة.');
    }
    redirect('modules/exams/results.php?exam_id=' . $id);
}
$st = rows("SELECT s.id, s.full_name, s.student_no, r.score, r.notes FROM group_students gs JOIN students s ON s.id = gs.student_id LEFT JOIN exam_results r ON r.exam_id = ? AND r.student_id = s.id WHERE gs.group_id = ? AND gs.status = 'active' ORDER BY s.full_name", [$id, $ex['group_id']]);
render_header('نتائج: ' . $ex['title']);
?>
<div class="ecm-card"><strong><?= e($ex['title']) ?></strong> · <?= e($ex['gname']) ?> · <?= e($ex['sname']) ?> · <?= e(fdate($ex['exam_date'])) ?> · الدرجة النهائية <b><?= e($ex['max_score']) ?></b> <a class="btn btn-sm btn-light float-end" href="index.php">الاختبارات</a></div>
<?php if (!$st): echo '<div class="ecm-card">' . empty_state('لا يوجد طلاب في المجموعة', '', '', 'people') . '</div>'; else: ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="exam_id" value="<?= $id ?>"><div class="table-responsive"><table class="table ecm-table"><thead><tr><th>الطالب</th><th style="width:150px">الدرجة / <?= e($ex['max_score']) ?></th><th>النسبة</th><th>ملاحظة المدرس</th></tr></thead><tbody>
<?php foreach ($st as $s): ?><tr><td><?= e($s['full_name']) ?> <small class="text-muted" dir="ltr"><?= e($s['student_no']) ?></small></td><td><input class="form-control form-control-sm" name="score[<?= (int)$s['id'] ?>]" value="<?= e($s['score']) ?>" dir="ltr" inputmode="decimal" <?= can('exams.manage') ? '' : 'disabled' ?>></td><td><?= $s['score'] !== null ? e(round((float)$s['score'] * 100 / (float)$ex['max_score'], 1)) . '%' : '—' ?></td><td><input class="form-control form-control-sm" name="note[<?= (int)$s['id'] ?>]" value="<?= e($s['notes']) ?>" maxlength="255" <?= can('exams.manage') ? '' : 'disabled' ?>></td></tr><?php endforeach; ?></tbody></table></div>
<?php if (can('exams.manage')): ?><button class="btn btn-primary"><i class="bi bi-save"></i> حفظ الدرجات</button><?php endif; ?></form><?php endif; ?>
<?php render_footer();
