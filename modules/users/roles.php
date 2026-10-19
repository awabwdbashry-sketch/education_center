<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_perm('users.manage');

$roles = rows('SELECT * FROM roles ORDER BY id');
$perms = rows('SELECT * FROM permissions ORDER BY sort_order, id');
$rid = get_int('role_id') ?: (int)$roles[0]['id'];
$role = row('SELECT * FROM roles WHERE id = ?', [$rid]);
if (!$role) {
    abort(404, 'الدور غير موجود');
}
if (is_post()) {
    $rid = post_int('role_id');
    $role = row('SELECT * FROM roles WHERE id = ?', [$rid]);
    if (!$role) {
        abort(404);
    }
    if ($role['name'] === 'admin') {
        flash('error', 'صلاحيات دور مدير النظام ثابتة (كاملة) لحماية النظام من القفل.');
    } else {
        $valid = array_map('intval', array_column($perms, 'id'));
        $sel = array_values(array_intersect(array_map('intval', post_arr('perms')), $valid));
        tx(function () use ($rid, $sel) {
            q('DELETE FROM role_permissions WHERE role_id = ?', [$rid]);
            foreach ($sel as $p) {
                insert('role_permissions', ['role_id' => $rid, 'permission_id' => $p]);
            }
            audit('role_permissions_updated', 'role', $rid, ['count' => count($sel)]);
        });
        flash('success', 'تم تحديث صلاحيات الدور. تسري عند الطلب التالي لكل مستخدم.');
    }
    redirect('modules/users/roles.php?role_id=' . $rid);
}
$have = array_map('intval', array_column(rows('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$rid]), 'permission_id'));
$grouped = [];
foreach ($perms as $p) {
    $grouped[$p['group_name']][] = $p;
}
render_header('الأدوار والصلاحيات');
?>
<div class="page-actions"><a class="btn btn-light" href="index.php">المستخدمون</a></div>
<ul class="nav nav-pills mb-3"><?php foreach ($roles as $r): ?><li class="nav-item"><a class="nav-link <?= (int)$r['id'] === $rid ? 'active' : '' ?>" href="?role_id=<?= (int)$r['id'] ?>"><?= e($r['label']) ?></a></li><?php endforeach; ?></ul>
<form method="post" class="ecm-card"><?= csrf_field() ?><input type="hidden" name="role_id" value="<?= $rid ?>"><p class="text-muted"><?= e($role['description']) ?></p>
<?php if ($role['name'] === 'admin'): ?><div class="alert alert-info">دور المدير يملك كل الصلاحيات ولا يمكن تقليصه.</div><?php endif; ?>
<div class="row g-3"><?php foreach ($grouped as $g => $items): ?><div class="col-md-6 col-xl-4"><div class="border rounded p-2 h-100"><div class="fw-bold text-success mb-1"><?= e($g) ?></div><?php foreach ($items as $p): ?><label class="form-check d-block"><input class="form-check-input" type="checkbox" name="perms[]" value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'], $have, true) ? 'checked' : '' ?> <?= $role['name'] === 'admin' ? 'disabled' : '' ?>> <span class="form-check-label small"><?= e($p['label']) ?></span></label><?php endforeach; ?></div></div><?php endforeach; ?></div>
<?php if ($role['name'] !== 'admin'): ?><button class="btn btn-primary mt-3">حفظ الصلاحيات</button><?php endif; ?></form>
<?php render_footer();
