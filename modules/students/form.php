<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_perm('students.manage');

$id = get_int('id') ?: post_int('id');
$s = $id ? row('SELECT * FROM students WHERE id = ?', [$id]) : null;
if ($id && !$s) {
    abort(404, 'الطالب غير موجود');
}
if ($id) {
    require_student_access($id);
}
$gd = $id ? row('SELECT g.* FROM guardians g JOIN student_guardians sg ON sg.guardian_id = g.id WHERE sg.student_id = ? ORDER BY sg.is_primary DESC LIMIT 1', [$id]) : null;
$levels = col_opts(rows('SELECT id, name FROM academic_levels WHERE is_active = 1 ORDER BY sort_order, name'), 'id', 'name');
$errors = [];
if (is_post()) {
    $d = ['full_name' => mb_substr(post('full_name'), 0, 150), 'phone' => mb_substr(post('phone'), 0, 30) ?: null, 'address' => mb_substr(post('address'), 0, 255) ?: null,
        'birth_date' => post('birth_date') ?: null, 'level_id' => post_int('level_id') ?: null, 'status' => post('status') ?: 'active', 'registered_at' => post('registered_at') ?: today(), 'notes' => post('notes') ?: null];
    $g = ['full_name' => mb_substr(post('g_name'), 0, 150), 'phone' => mb_substr(post('g_phone'), 0, 30), 'relation' => mb_substr(post('g_relation'), 0, 50)];
    if ($d['full_name'] === '') {
        $errors[] = 'اسم الطالب مطلوب.';
    }
    if ($d['birth_date'] && (!valid_date($d['birth_date']) || $d['birth_date'] > today())) {
        $errors[] = 'تاريخ الميلاد غير صالح.';
    }
    if (!valid_date($d['registered_at'])) {
        $errors[] = 'تاريخ التسجيل غير صالح.';
    }
    if (!isset(student_statuses()[$d['status']])) {
        $errors[] = 'الحالة غير صالحة.';
    }
    if ($d['level_id'] && !isset($levels[$d['level_id']]) && !($s && (int)$s['level_id'] === $d['level_id'])) {
        $errors[] = 'الصف غير صالح.';
    }
    foreach (['phone' => $d['phone'], 'هاتف ولي الأمر' => $g['phone']] as $lbl => $ph) {
        if ($ph && !preg_match('/^[0-9+\-\s()]{6,30}$/', norm_digits($ph))) {
            $errors[] = 'رقم الهاتف غير صالح (' . ($lbl === 'phone' ? 'الطالب' : $lbl) . ').';
        }
    }
    $d['phone'] = $d['phone'] ? norm_digits($d['phone']) : null;
    $g['phone'] = norm_digits($g['phone']);
    // تحذير من تكرار محتمل (نفس الاسم والهاتف) — لا يمنع
    if (!$errors && !$id && $d['phone'] && row('SELECT id FROM students WHERE full_name = ? AND phone = ?', [$d['full_name'], $d['phone']]) && post('confirm_dup') !== '1') {
        $errors[] = 'يوجد طالب بنفس الاسم والهاتف. إن كان طالباً مختلفاً فعلاً فعّل خيار «تأكيد الإضافة» وأعد الحفظ.';
        $dupWarn = true;
    }
    $photo = null;
    if (!$errors) {
        $up = save_upload('photo', 'students');
        if (!$up['ok'] && $up['error'] !== '') {
            $errors[] = $up['error'];
        } elseif ($up['ok']) {
            $photo = $up['path'];
        }
    }
    if (!$errors) {
        $isNew = !$id;
        $old = $s['photo_path'] ?? null;
        tx(function () use (&$id, $d, $g, $photo, $isNew, $gd) {
            if ($photo) {
                $d['photo_path'] = $photo;
            }
            if ($isNew) {
                $d['student_no'] = next_seq('student', 'ST-');
                $d['qr_token'] = new_qr_token();
                $d['created_by'] = uid();
                $id = insert('students', $d);
            } else {
                update('students', $d, 'id = ?', [$id]);
            }
            if ($g['full_name'] !== '') {
                if ($gd) {
                    update('guardians', ['full_name' => $g['full_name'], 'phone' => $g['phone'] ?: null, 'relation' => $g['relation'] ?: null], 'id = ?', [$gd['id']]);
                } else {
                    $gid = insert('guardians', ['full_name' => $g['full_name'], 'phone' => $g['phone'] ?: null, 'relation' => $g['relation'] ?: null]);
                    insert('student_guardians', ['student_id' => $id, 'guardian_id' => $gid, 'is_primary' => 1]);
                }
            }
            audit($isNew ? 'student_created' : 'student_updated', 'student', $id, $d);
        });
        if ($photo && $old) {
            delete_upload($old);
        }
        flash('success', $isNew ? 'تم تسجيل الطالب برقم ' . val('SELECT student_no FROM students WHERE id = ?', [$id]) . '.' : 'تم تحديث بيانات الطالب.');
        redirect('modules/students/view.php?id=' . $id);
    }
    $s = array_merge($s ?? [], $d);
    $gd = array_merge($gd ?? [], ['full_name' => $g['full_name'], 'phone' => $g['phone'], 'relation' => $g['relation']]);
}
render_header($id ? 'تعديل طالب' : 'طالب جديد');
echo errors_box($errors);
?>
<form method="post" enctype="multipart/form-data" class="ecm-card row g-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="col-12"><h6 class="border-bottom pb-1 text-muted">بيانات الطالب <?= $s && !empty($s['student_no']) ? '· ' . e($s['student_no']) : '(يُولَّد الرقم تلقائياً)' ?></h6></div>
  <?= field('text', 'full_name', 'الاسم الكامل', $s['full_name'] ?? '', ['required' => true]) ?>
  <?= field('text', 'phone', 'هاتف الطالب', $s['phone'] ?? '', ['attrs' => 'dir="ltr"']) ?>
  <?= select_field('level_id', 'الصف الدراسي', $levels, $s['level_id'] ?? '') ?>
  <?= field('date', 'birth_date', 'تاريخ الميلاد', $s['birth_date'] ?? '') ?>
  <?= field('text', 'address', 'العنوان', $s['address'] ?? '') ?>
  <?= field('date', 'registered_at', 'تاريخ التسجيل', $s['registered_at'] ?? today(), ['attrs' => 'max="' . today() . '"']) ?>
  <?= select_field('status', 'الحالة', student_statuses(), $s['status'] ?? 'active', ['blank' => false]) ?>
  <div class="col-md-6"><label class="form-label">صورة الطالب (اختيارية، حتى 2 ميغابايت)</label><input class="form-control" type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div>
  <div class="col-12"><h6 class="border-bottom pb-1 text-muted mt-2">ولي الأمر</h6></div>
  <?= field('text', 'g_name', 'اسم ولي الأمر', $gd['full_name'] ?? '') ?>
  <?= field('text', 'g_phone', 'هاتف ولي الأمر', $gd['phone'] ?? '', ['attrs' => 'dir="ltr"', 'col' => 'col-md-4']) ?>
  <?= field('text', 'g_relation', 'صلة القرابة', $gd['relation'] ?? '', ['col' => 'col-md-2']) ?>
  <?= textarea_field('notes', 'ملاحظات إدارية', $s['notes'] ?? '') ?>
  <?php if (!empty($dupWarn)): ?><div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="confirm_dup" value="1"> <span class="form-check-label">تأكيد الإضافة رغم وجود تشابه</span></label></div><?php endif; ?>
  <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> حفظ</button><a class="btn btn-light" href="index.php">إلغاء</a></div>
</form>
<?php render_footer();
