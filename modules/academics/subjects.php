<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_perm('academics.manage');

$errors = [];
if (is_post()) {
    $act = post('action');
    try {
        if ($act === 'subject_save' || $act === 'level_save') {
            $isSub = $act === 'subject_save';
            $id = post_int('id');
            $name = mb_substr(post('name'), 0, 100);
            if ($name === '') {
                throw new RuntimeException('الاسم مطلوب.');
            }
            if ($isSub) {
                $code = mb_substr(post('code'), 0, 30);
                $data = ['name' => $name, 'code' => $code !== '' ? $code : null];
                $id ? update('subjects', $data, 'id = ?', [$id]) : $id = insert('subjects', $data);
                audit($act, 'subject', $id, $data);
            } else {
                $data = ['name' => $name, 'stage' => post('stage') !== '' ? mb_substr(post('stage'), 0, 60) : null, 'sort_order' => post_int('sort_order')];
                $id ? update('academic_levels', $data, 'id = ?', [$id]) : $id = insert('academic_levels', $data);
                audit($act, 'level', $id, $data);
            }
            flash('success', 'تم الحفظ.');
        } elseif ($act === 'toggle_subject' || $act === 'toggle_level') {
            $tbl = $act === 'toggle_subject' ? 'subjects' : 'academic_levels';
            q("UPDATE $tbl SET is_active = 1 - is_active WHERE id = ?", [post_int('id')]);
            audit($act, $tbl, post_int('id'));
            flash('success', 'تم تغيير الحالة (الأرشفة بدل الحذف للحفاظ على السجلات).');
        }
    } catch (PDOException $e) {
        flash('error', is_duplicate_error($e) ? 'الاسم أو الرمز مستخدم مسبقاً.' : 'تعذر الحفظ.');
        if (!is_duplicate_error($e)) {
            error_log($e->getMessage());
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/academics/subjects.php');
}
$subjects = rows('SELECT s.*, (SELECT COUNT(*) FROM class_groups g WHERE g.subject_id = s.id) AS gc FROM subjects s ORDER BY is_active DESC, name');
$levels = rows('SELECT l.*, (SELECT COUNT(*) FROM students st WHERE st.level_id = l.id) AS sc FROM academic_levels l ORDER BY is_active DESC, sort_order, name');
$editS = get_int('edit_subject') ? row('SELECT * FROM subjects WHERE id = ?', [get_int('edit_subject')]) : null;
$editL = get_int('edit_level') ? row('SELECT * FROM academic_levels WHERE id = ?', [get_int('edit_level')]) : null;
render_header('المواد والمراحل الدراسية');
?>
<div class="row g-3">
<div class="col-lg-6"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-journal-bookmark"></i> المواد الدراسية</div>
<form method="post" class="row g-2 mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="subject_save"><input type="hidden" name="id" value="<?= (int)($editS['id'] ?? 0) ?>">
  <div class="col-7"><input class="form-control" name="name" placeholder="اسم المادة" value="<?= e($editS['name'] ?? '') ?>" required maxlength="100"></div>
  <div class="col-5"><input class="form-control" name="code" placeholder="رمز (اختياري)" value="<?= e($editS['code'] ?? '') ?>" maxlength="30" dir="ltr"></div>
  <div class="col-12"><button class="btn btn-primary btn-sm"><?= $editS ? 'تحديث' : 'إضافة مادة' ?></button><?php if ($editS): ?> <a class="btn btn-light btn-sm" href="subjects.php">إلغاء</a><?php endif; ?></div></form>
<?php if (!$subjects): echo empty_state('لا توجد مواد', 'أضف أول مادة دراسية.'); else: ?>
<div class="table-responsive"><table class="table ecm-table"><thead><tr><th>المادة</th><th>الرمز</th><th>مجموعات</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($subjects as $s): ?><tr><td><?= e($s['name']) ?></td><td dir="ltr"><?= e($s['code']) ?></td><td><?= (int)$s['gc'] ?></td><td><?= $s['is_active'] ? badge('نشطة', 'success') : badge('مؤرشفة', 'secondary') ?></td>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?edit_subject=<?= (int)$s['id'] ?>">تعديل</a>
<form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_subject"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-outline-warning"><?= $s['is_active'] ? 'أرشفة' : 'تفعيل' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></div>
<div class="col-lg-6"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-layers"></i> الصفوف والمراحل</div>
<form method="post" class="row g-2 mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="level_save"><input type="hidden" name="id" value="<?= (int)($editL['id'] ?? 0) ?>">
  <div class="col-5"><input class="form-control" name="name" placeholder="الصف (مثال: الثالث الثانوي)" value="<?= e($editL['name'] ?? '') ?>" required maxlength="100"></div>
  <div class="col-4"><input class="form-control" name="stage" placeholder="المرحلة" value="<?= e($editL['stage'] ?? '') ?>" maxlength="60"></div>
  <div class="col-3"><input class="form-control" type="number" name="sort_order" placeholder="ترتيب" value="<?= e($editL['sort_order'] ?? 0) ?>"></div>
  <div class="col-12"><button class="btn btn-primary btn-sm"><?= $editL ? 'تحديث' : 'إضافة صف' ?></button><?php if ($editL): ?> <a class="btn btn-light btn-sm" href="subjects.php">إلغاء</a><?php endif; ?></div></form>
<?php if (!$levels): echo empty_state('لا توجد صفوف', 'أضف الصفوف الدراسية لتستخدمها في الطلاب والمجموعات.'); else: ?>
<div class="table-responsive"><table class="table ecm-table"><thead><tr><th>الصف</th><th>المرحلة</th><th>طلاب</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($levels as $l): ?><tr><td><?= e($l['name']) ?></td><td><?= e($l['stage']) ?></td><td><?= (int)$l['sc'] ?></td><td><?= $l['is_active'] ? badge('نشط', 'success') : badge('مؤرشف', 'secondary') ?></td>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?edit_level=<?= (int)$l['id'] ?>">تعديل</a>
<form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_level"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm btn-outline-warning"><?= $l['is_active'] ? 'أرشفة' : 'تفعيل' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div></div>
</div>
<?php render_footer();
