<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_perm('teachers.manage');

$id = get_int('id') ?: post_int('id');
$t = $id ? row('SELECT * FROM teachers WHERE id = ?', [$id]) : null;
if ($id && !$t) {
    abort(404, 'المدرس غير موجود');
}
$allSubjects = rows('SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY name');
$mine = $t ? array_map('intval', array_column(rows('SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?', [$id]), 'subject_id')) : [];
$errors = [];
if (is_post()) {
    $d = ['full_name' => mb_substr(post('full_name'), 0, 150), 'phone' => mb_substr(post('phone'), 0, 30) ?: null, 'email' => mb_substr(post('email'), 0, 150) ?: null,
        'address' => mb_substr(post('address'), 0, 255) ?: null, 'specialization' => mb_substr(post('specialization'), 0, 150) ?: null,
        'hire_date' => post('hire_date') ?: null, 'status' => post('status'), 'comp_type' => post('comp_type'), 'notes' => post('notes') ?: null];
    $amt = parse_money(post('comp_amount') ?: '0');
    if ($d['full_name'] === '') {
        $errors[] = 'اسم المدرس مطلوب.';
    }
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني غير صالح.';
    }
    if ($d['hire_date'] && !valid_date($d['hire_date'])) {
        $errors[] = 'تاريخ التعيين غير صالح.';
    }
    if (!in_array($d['status'], ['active', 'inactive'], true)) {
        $errors[] = 'الحالة غير صالحة.';
    }
    if (!isset(comp_type_labels()[$d['comp_type']])) {
        $errors[] = 'نظام الأجر غير صالح.';
    }
    if ($amt === null || $amt < 0) {
        $errors[] = 'قيمة الأجر غير صالحة.';
    } elseif ($d['comp_type'] !== 'none' && $amt === 0) {
        $errors[] = 'حدد قيمة الأجر أو اختر «غير محدد».';
    }
    $subs = array_values(array_intersect(array_map('intval', post_arr('subjects')), array_map('intval', array_column($allSubjects, 'id'))));
    if (!$errors) {
        $d['comp_amount'] = cents_str($d['comp_type'] === 'none' ? 0 : (int)$amt);
        $isNew = !$id;
        tx(function () use (&$id, $d, $subs, $isNew) {
            $id ? update('teachers', $d, 'id = ?', [$id]) : $id = insert('teachers', $d);
            q('DELETE FROM teacher_subjects WHERE teacher_id = ?', [$id]);
            foreach ($subs as $s) {
                insert('teacher_subjects', ['teacher_id' => $id, 'subject_id' => $s]);
            }
            audit($isNew ? 'teacher_created' : 'teacher_updated', 'teacher', $id, $d);
        });
        flash('success', 'تم حفظ بيانات المدرس.');
        redirect('modules/teachers/view.php?id=' . $id);
    }
    $t = array_merge($t ?? [], $d, ['comp_amount' => post('comp_amount')]);
    $mine = $subs;
}
render_header($t && $id ? 'تعديل مدرس' : 'مدرس جديد');
echo errors_box($errors);
?>
<form method="post" class="ecm-card row g-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <?= field('text', 'full_name', 'الاسم الكامل', $t['full_name'] ?? '', ['required' => true]) ?>
  <?= field('text', 'phone', 'الهاتف', $t['phone'] ?? '', ['attrs' => 'dir="ltr"']) ?>
  <?= field('email', 'email', 'البريد الإلكتروني', $t['email'] ?? '', ['attrs' => 'dir="ltr"']) ?>
  <?= field('text', 'specialization', 'التخصص', $t['specialization'] ?? '') ?>
  <?= field('text', 'address', 'العنوان', $t['address'] ?? '') ?>
  <?= field('date', 'hire_date', 'تاريخ التعيين', $t['hire_date'] ?? '') ?>
  <?= select_field('status', 'الحالة', ['active' => 'نشط', 'inactive' => 'غير نشط'], $t['status'] ?? 'active', ['blank' => false]) ?>
  <?= select_field('comp_type', 'نظام الأجر', comp_type_labels(), $t['comp_type'] ?? 'none', ['blank' => false]) ?>
  <?= field('text', 'comp_amount', 'قيمة الأجر (شهرياً أو للحصة الواحدة)', isset($t['comp_amount']) ? ($t['comp_amount'] == 0 ? '' : $t['comp_amount']) : '', ['attrs' => 'inputmode="decimal" dir="ltr"', 'help' => 'تُحتسب المستحقات من صفحة «مستحقات المدرسين» بلا تكرار.']) ?>
  <div class="col-12"><label class="form-label">المواد التي يدرّسها</label><div class="d-flex flex-wrap gap-3"><?php foreach ($allSubjects as $s): ?>
    <label class="form-check"><input class="form-check-input" type="checkbox" name="subjects[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $mine, true) ? 'checked' : '' ?>> <span class="form-check-label"><?= e($s['name']) ?></span></label><?php endforeach; if (!$allSubjects) echo '<span class="text-muted small">أضف مواد أولاً من «المواد والمراحل».</span>'; ?></div></div>
  <?= textarea_field('notes', 'ملاحظات', $t['notes'] ?? '') ?>
  <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> حفظ</button><a class="btn btn-light" href="index.php">إلغاء</a></div>
</form>
<?php render_footer();
