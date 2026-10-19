<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_perm('academics.manage');

$id = get_int('id') ?: post_int('id');
$g = $id ? row('SELECT g.*, ps.mode, ps.monthly_fee, ps.session_price, ps.due_days, ps.settlement_sessions, ps.allow_advance FROM class_groups g LEFT JOIN group_payment_settings ps ON ps.group_id = g.id WHERE g.id = ?', [$id]) : null;
if ($id && !$g) {
    abort(404, 'المجموعة غير موجودة');
}
$subjects = col_opts(rows('SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY name'), 'id', 'name');
$levels = col_opts(rows('SELECT id, name FROM academic_levels WHERE is_active = 1 ORDER BY sort_order, name'), 'id', 'name');
$teachers = col_opts(rows("SELECT id, full_name FROM teachers WHERE status = 'active' ORDER BY full_name"), 'id', 'full_name');
$errors = [];
if (is_post()) {
    $d = ['code' => strtoupper(mb_substr(post('code'), 0, 30)), 'name' => mb_substr(post('name'), 0, 150), 'level_id' => post_int('level_id') ?: null, 'subject_id' => post_int('subject_id'),
        'teacher_id' => post_int('teacher_id'), 'room' => mb_substr(post('room'), 0, 100) ?: null, 'max_students' => post_int('max_students'),
        'start_date' => post('start_date') ?: null, 'end_date' => post('end_date') ?: null, 'status' => post('status'), 'notes' => post('notes') ?: null];
    $mode = post('mode');
    $fee = parse_money(post('monthly_fee') ?: '0');
    $price = parse_money(post('session_price') ?: '0');
    $due = post_int('due_days');
    $settle = post_int('settlement_sessions');
    if ($d['code'] === '' || !preg_match('/^[A-Z0-9_\-]{2,30}$/', $d['code'])) {
        $errors[] = 'رمز المجموعة: حروف إنجليزية/أرقام/شرطات (2–30).';
    }
    if ($d['name'] === '') {
        $errors[] = 'اسم المجموعة مطلوب.';
    }
    if (!isset($subjects[$d['subject_id']])) {
        $errors[] = 'اختر مادة صالحة.';
    }
    if (!isset($teachers[$d['teacher_id']]) && !($g && (int)$g['teacher_id'] === $d['teacher_id'])) {
        $errors[] = 'اختر مدرساً نشطاً.';
    }
    if ($d['max_students'] < 1 || $d['max_students'] > 500) {
        $errors[] = 'الحد الأقصى للطلاب بين 1 و500.';
    }
    foreach (['start_date', 'end_date'] as $k) {
        if ($d[$k] && !valid_date($d[$k])) {
            $errors[] = 'تاريخ غير صالح.';
        }
    }
    if ($d['start_date'] && $d['end_date'] && $d['end_date'] < $d['start_date']) {
        $errors[] = 'تاريخ النهاية قبل البداية.';
    }
    if (!isset(group_statuses()[$d['status']])) {
        $errors[] = 'حالة غير صالحة.';
    }
    if (!isset(mode_labels()[$mode])) {
        $errors[] = 'اختر نظام الدفع.';
    }
    if ($fee === null || $price === null || $fee < 0 || $price < 0) {
        $errors[] = 'قيم الدفع غير صالحة.';
    } elseif ($mode === 'monthly' && $fee <= 0) {
        $errors[] = 'حدد قيمة الاشتراك الشهري.';
    } elseif ($mode === 'per_session' && $price <= 0) {
        $errors[] = 'حدد سعر الحصة.';
    }
    if ($d['max_students'] && $id) {
        $n = (int)val("SELECT COUNT(*) FROM group_students WHERE group_id = ? AND status = 'active'", [$id]);
        if ($d['max_students'] < $n) {
            $errors[] = "لا يمكن تقليل السعة عن عدد المسجلين حالياً ($n).";
        }
    }
    if (!$errors) {
        try {
            $isNew = !$id;
            tx(function () use (&$id, $d, $mode, $fee, $price, $due, $settle, $isNew) {
                $id ? update('class_groups', $d, 'id = ?', [$id]) : $id = insert('class_groups', $d);
                q('INSERT INTO group_payment_settings (group_id, mode, monthly_fee, session_price, due_days, settlement_sessions, allow_advance) VALUES (?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE mode = VALUES(mode), monthly_fee = VALUES(monthly_fee), session_price = VALUES(session_price), due_days = VALUES(due_days), settlement_sessions = VALUES(settlement_sessions), allow_advance = VALUES(allow_advance)',
                    [$id, $mode, cents_str((int)$fee), cents_str((int)$price), max(0, min(90, $due)), max(0, min(50, $settle)), post('allow_advance') === '1' ? 1 : 0]);
                // اسناد المدرس يغيّر مدرس الحصص المجدولة مستقبلاً فقط
                q("UPDATE class_sessions SET teacher_id = ? WHERE group_id = ? AND status = 'scheduled' AND session_date >= CURDATE() AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.session_id = class_sessions.id)", [$d['teacher_id'], $id]);
                audit($isNew ? 'group_created' : 'group_updated', 'group', $id, $d + ['mode' => $mode]);
            });
            flash('success', 'تم حفظ المجموعة.');
            redirect('modules/groups/view.php?id=' . $id);
        } catch (PDOException $e) {
            if (is_duplicate_error($e)) {
                $errors[] = 'رمز المجموعة مستخدم مسبقاً.';
            } else {
                throw $e;
            }
        }
    }
    $g = array_merge($g ?? [], $d, ['mode' => $mode, 'monthly_fee' => post('monthly_fee'), 'session_price' => post('session_price'), 'due_days' => $due, 'settlement_sessions' => $settle, 'allow_advance' => post('allow_advance')]);
}
render_header($id ? 'تعديل مجموعة' : 'مجموعة جديدة');
echo errors_box($errors);
$mv = fn($k) => isset($g[$k]) && (float)$g[$k] == 0 ? '' : ($g[$k] ?? '');
?>
<form method="post" class="ecm-card row g-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <?= field('text', 'code', 'رمز المجموعة (فريد)', $g['code'] ?? '', ['required' => true, 'attrs' => 'dir="ltr" maxlength="30" style="text-transform:uppercase"']) ?>
  <?= field('text', 'name', 'اسم المجموعة', $g['name'] ?? '', ['required' => true]) ?>
  <?= select_field('subject_id', 'المادة', $subjects, $g['subject_id'] ?? '', ['required' => true]) ?>
  <?= select_field('level_id', 'الصف / المرحلة', $levels, $g['level_id'] ?? '') ?>
  <?= select_field('teacher_id', 'المدرس المسؤول', $teachers + ($g && !isset($teachers[$g['teacher_id']]) ? [$g['teacher_id'] => (string)val('SELECT full_name FROM teachers WHERE id = ?', [$g['teacher_id']]) . ' (غير نشط)'] : []), $g['teacher_id'] ?? '', ['required' => true]) ?>
  <?= field('text', 'room', 'القاعة / المكان', $g['room'] ?? '') ?>
  <?= field('number', 'max_students', 'الحد الأقصى للطلاب', $g['max_students'] ?? 30, ['required' => true, 'attrs' => 'min="1" max="500"']) ?>
  <?= select_field('status', 'الحالة', group_statuses(), $g['status'] ?? 'active', ['blank' => false]) ?>
  <?= field('date', 'start_date', 'تاريخ البداية', $g['start_date'] ?? '') ?>
  <?= field('date', 'end_date', 'تاريخ النهاية', $g['end_date'] ?? '') ?>
  <div class="col-12"><h6 class="border-bottom pb-1 mt-2 text-muted">نظام الدفع لهذه المجموعة</h6></div>
  <?= select_field('mode', 'النظام', mode_labels(), $g['mode'] ?? 'monthly', ['blank' => false, 'attrs' => 'id="modeSel"']) ?>
  <?= field('text', 'monthly_fee', 'قيمة الاشتراك الشهري', $mv('monthly_fee'), ['attrs' => 'inputmode="decimal" dir="ltr"', 'col' => 'col-md-3 mode-monthly']) ?>
  <?= field('text', 'session_price', 'سعر الحصة', $mv('session_price'), ['attrs' => 'inputmode="decimal" dir="ltr"', 'col' => 'col-md-3 mode-session']) ?>
  <?= field('number', 'due_days', 'مهلة السداد (أيام)', $g['due_days'] ?? setting('default_due_days', 7), ['attrs' => 'min="0" max="90"', 'col' => 'col-md-3']) ?>
  <?= field('number', 'settlement_sessions', 'تسوية كل N حصة (0 = عند الطلب)', $g['settlement_sessions'] ?? 0, ['attrs' => 'min="0" max="50"', 'col' => 'col-md-3 mode-session', 'help' => 'مثال: 8 يفوتر كل 8 حصص مكتملة.']) ?>
  <div class="col-md-6 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="allow_advance" value="1" <?= ($g['allow_advance'] ?? 1) ? 'checked' : '' ?>> <span class="form-check-label">السماح بالدفعات المقدمة لهذه المجموعة</span></label></div>
  <?= textarea_field('notes', 'ملاحظات', $g['notes'] ?? '') ?>
  <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> حفظ</button><a class="btn btn-light" href="index.php">إلغاء</a></div>
</form>
<?php inline_script('(function(){var s=document.getElementById("modeSel");function f(){document.querySelectorAll(".mode-monthly").forEach(function(e){e.style.display=s.value==="monthly"?"":"none"});document.querySelectorAll(".mode-session").forEach(function(e){e.style.display=s.value==="per_session"?"":"none"});}s.addEventListener("change",f);f();})();'); render_footer();
