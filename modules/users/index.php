<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_perm('users.manage');

$roles = col_opts(rows('SELECT id, label FROM roles ORDER BY id'), 'id', 'label');
$teachers = col_opts(rows("SELECT id, full_name FROM teachers WHERE status = 'active' ORDER BY full_name"), 'id', 'full_name');
$adminRoleId = (int)val("SELECT id FROM roles WHERE name = 'admin'");
function active_admin_count(int $adminRole, int $excludeUser = 0): int
{
    return (int)val("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id AND ur.role_id = ? WHERE u.status = 'active' AND u.id <> ?", [$adminRole, $excludeUser]);
}
$errors = [];
if (is_post()) {
    $act = post('action');
    try {
        if ($act === 'create' || $act === 'update') {
            $id = post_int('id');
            $username = post('username');
            $full = mb_substr(post('full_name'), 0, 150);
            $email = mb_substr(post('email'), 0, 150) ?: null;
            $status = post('status') === 'disabled' ? 'disabled' : 'active';
            $rids = array_values(array_intersect(array_map('intval', post_arr('roles')), array_map('intval', array_keys($roles))));
            $teacher = post_int('teacher_id') ?: null;
            $pw = (string)($_POST['password'] ?? '');
            if ($full === '') {
                $errors[] = 'الاسم الكامل مطلوب.';
            }
            if ($act === 'create' && !preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username)) {
                $errors[] = 'اسم المستخدم: 3–40 حرفاً إنجليزياً/أرقاماً.';
            }
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'البريد غير صالح.';
            }
            if (!$rids) {
                $errors[] = 'اختر دوراً واحداً على الأقل.';
            }
            if ($teacher && !isset($teachers[$teacher]) && !($id && (int)val('SELECT teacher_id FROM users WHERE id = ?', [$id]) === $teacher)) {
                $errors[] = 'المدرس المرتبط غير صالح.';
            }
            if (($act === 'create' || $pw !== '') && ($e = password_policy_error($pw))) {
                $errors[] = $e;
            }
            if ($act === 'update') {
                if (!row('SELECT id FROM users WHERE id = ?', [$id])) {
                    throw new RuntimeException('المستخدم غير موجود.');
                }
                $losesAdmin = !in_array($adminRoleId, $rids, true) || $status === 'disabled';
                if ($losesAdmin && row('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?', [$id, $adminRoleId]) && active_admin_count($adminRoleId, $id) === 0) {
                    $errors[] = 'لا يمكن إزالة أو تعطيل آخر مدير نشط في النظام.';
                }
                if ($id === uid() && $status === 'disabled') {
                    $errors[] = 'لا يمكنك تعطيل حسابك.';
                }
            }
            if (!$errors) {
                tx(function () use ($act, &$id, $username, $full, $email, $status, $rids, $teacher, $pw) {
                    $d = ['full_name' => $full, 'email' => $email, 'status' => $status, 'teacher_id' => $teacher];
                    if ($act === 'create') {
                        $id = insert('users', $d + ['username' => $username, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
                    } else {
                        if ($pw !== '') {
                            $d['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
                        }
                        update('users', $d, 'id = ?', [$id]);
                    }
                    q('DELETE FROM user_roles WHERE user_id = ?', [$id]);
                    foreach ($rids as $r) {
                        insert('user_roles', ['user_id' => $id, 'role_id' => $r]);
                    }
                    audit($act === 'create' ? 'user_created' : 'user_updated', 'user', $id, ['roles' => $rids, 'status' => $status, 'password_changed' => $pw !== '']);
                });
                flash('success', 'تم حفظ المستخدم.');
                redirect('modules/users/index.php');
            }
        }
    } catch (PDOException $e) {
        $errors[] = is_duplicate_error($e) ? 'اسم المستخدم مستخدم مسبقاً.' : 'تعذر الحفظ.';
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}
$edit = get_int('edit') ? row('SELECT * FROM users WHERE id = ?', [get_int('edit')]) : null;
$editRoles = $edit ? array_map('intval', array_column(rows('SELECT role_id FROM user_roles WHERE user_id = ?', [$edit['id']]), 'role_id')) : array_map('intval', post_arr('roles'));
$users = rows("SELECT u.*, (SELECT GROUP_CONCAT(r.label SEPARATOR '، ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles_, t.full_name AS tname FROM users u LEFT JOIN teachers t ON t.id = u.teacher_id ORDER BY u.status, u.full_name");
render_header('المستخدمون');
echo errors_box($errors);
?>
<div class="page-actions"><a class="btn btn-outline-primary" href="roles.php"><i class="bi bi-shield-check"></i> الأدوار والصلاحيات</a></div>
<div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-person-plus"></i> <?= $edit ? 'تعديل: ' . e($edit['username']) : 'مستخدم جديد' ?></div>
<form method="post" class="row g-3" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <?= field('text', 'username', 'اسم المستخدم', $edit['username'] ?? post('username'), ['col' => 'col-md-3', 'required' => !$edit, 'readonly' => (bool)$edit, 'attrs' => 'dir="ltr"']) ?>
  <?= field('text', 'full_name', 'الاسم الكامل', $edit['full_name'] ?? post('full_name'), ['col' => 'col-md-3', 'required' => true]) ?>
  <?= field('email', 'email', 'البريد', $edit['email'] ?? post('email'), ['col' => 'col-md-3', 'attrs' => 'dir="ltr"']) ?>
  <?= field('password', 'password', $edit ? 'كلمة مرور جديدة (اتركها فارغة للإبقاء)' : 'كلمة المرور', '', ['col' => 'col-md-3', 'required' => !$edit, 'attrs' => 'autocomplete="new-password" dir="ltr"', 'help' => '10 أحرف على الأقل، أحرف وأرقام']) ?>
  <?= select_field('status', 'الحالة', ['active' => 'نشط', 'disabled' => 'معطّل'], $edit['status'] ?? 'active', ['col' => 'col-md-3', 'blank' => false]) ?>
  <?= select_field('teacher_id', 'ربط بملف مدرس (لحساب المدرس)', $teachers, $edit['teacher_id'] ?? '', ['col' => 'col-md-3', 'help' => 'يقيّد المدرس بمجموعاته فقط']) ?>
  <div class="col-md-6"><label class="form-label">الأدوار</label><div class="d-flex flex-wrap gap-3"><?php foreach ($roles as $rid => $rl): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="<?= (int)$rid ?>" <?= in_array((int)$rid, $editRoles, true) ? 'checked' : '' ?>> <span class="form-check-label"><?= e($rl) ?></span></label><?php endforeach; ?></div></div>
  <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check2"></i> حفظ</button><?php if ($edit): ?><a class="btn btn-light" href="index.php">إلغاء</a><?php endif; ?></div></form></div>
<div class="table-responsive"><table class="table ecm-table"><thead><tr><th>المستخدم</th><th>الأدوار</th><th>مدرس مرتبط</th><th>آخر دخول</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr><td><?= e($u['full_name']) ?><div class="small text-muted" dir="ltr"><?= e($u['username']) ?></div></td><td><?= e($u['roles_']) ?></td><td><?= e($u['tname'] ?: '—') ?></td><td><?= e(fdatetime($u['last_login_at'])) ?></td><td><?= status_badge($u['status'], ['active' => 'نشط', 'disabled' => 'معطّل']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$u['id'] ?>">تعديل</a></td></tr><?php endforeach; ?></tbody></table></div>
<?php render_footer();
