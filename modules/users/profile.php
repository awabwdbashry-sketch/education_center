<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$u = require_login();
$errors = [];
if (is_post()) {
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $hash = (string)val('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
    if (!password_verify($cur, $hash)) {
        $errors[] = 'كلمة المرور الحالية غير صحيحة.';
    }
    if ($new !== (string)($_POST['confirm'] ?? '')) {
        $errors[] = 'تأكيد كلمة المرور غير مطابق.';
    }
    if ($e = password_policy_error($new)) {
        $errors[] = $e;
    }
    if (!$errors) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        audit('password_changed', 'user', (int)$u['id']);
        flash('success', 'تم تغيير كلمة المرور.');
        redirect('index.php');
    }
}
render_header('تغيير كلمة المرور');
echo errors_box($errors);
?>
<form method="post" class="ecm-card row g-3" style="max-width:520px" autocomplete="off"><?= csrf_field() ?>
  <?= field('password', 'current', 'كلمة المرور الحالية', '', ['col' => 'col-12', 'required' => true, 'attrs' => 'autocomplete="current-password" dir="ltr"']) ?>
  <?= field('password', 'new', 'كلمة المرور الجديدة', '', ['col' => 'col-12', 'required' => true, 'attrs' => 'autocomplete="new-password" dir="ltr"', 'help' => '10 أحرف على الأقل، أحرف وأرقام']) ?>
  <?= field('password', 'confirm', 'تأكيد كلمة المرور', '', ['col' => 'col-12', 'required' => true, 'attrs' => 'autocomplete="new-password" dir="ltr"']) ?>
  <div class="col-12"><button class="btn btn-primary">حفظ</button></div></form>
<?php render_footer();
