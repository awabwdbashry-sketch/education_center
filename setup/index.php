<?php
declare(strict_types=1);
define('ECM_SETUP', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/install_lib.php';

$installed = is_file(ECM_ROOT . '/config/installed.lock') || $GLOBALS['ECM_CONFIG'] !== null;
$result = null;
if ($installed) {
    http_response_code(403);
} elseif (is_post()) {
    $result = ecm_install([
        'host' => post('host'), 'port' => post('port'), 'name' => post('name'), 'user' => post('user'), 'pass' => (string)($_POST['pass'] ?? ''),
        'center_name' => post('center_name'), 'admin_username' => post('admin_username'), 'admin_fullname' => post('admin_fullname'),
        'admin_password' => (string)($_POST['admin_password'] ?? ''), 'admin_password2' => (string)($_POST['admin_password2'] ?? ''),
    ]);
}
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تثبيت النظام</title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.rtl.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('vendor/icons/bootstrap-icons.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"></head>
<body><div class="login-wrap"><div class="login-card" style="max-width:640px">
<div class="login-logo"><i class="bi bi-mortarboard-fill"></i></div>
<h4 class="text-center mb-1">تثبيت نظام إدارة السنتر التعليمي</h4>
<?php if ($installed): ?>
  <div class="alert alert-warning mt-3"><i class="bi bi-lock"></i> النظام مثبّت بالفعل، وتم قفل صفحة الإعداد.
  لإعادة التهيئة عن قصد: خذ نسخة احتياطية ثم احذف <code>config/config.php</code> و<code>config/installed.lock</code> يدوياً.</div>
  <a class="btn btn-primary w-100" href="<?= e(url('login.php')) ?>">الانتقال إلى تسجيل الدخول</a>
<?php elseif ($result && $result['ok']): ?>
  <div class="alert alert-success mt-3"><i class="bi bi-check-circle"></i> تم التثبيت بنجاح.</div>
  <ul><?php foreach ($result['notes'] as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
  <p class="text-muted small">يُنصح بحذف مجلد <code>setup/</code> بعد التثبيت. سجّل الدخول بحساب المدير الذي أنشأته.</p>
  <a class="btn btn-primary w-100" href="<?= e(url('login.php')) ?>">تسجيل الدخول</a>
<?php else: ?>
  <p class="text-center text-muted">أدخل بيانات الاتصال بقاعدة البيانات وبيانات المدير الأول. لن تُنشأ بيانات وهمية.</p>
  <?= $result ? errors_box($result['errors']) : '' ?>
  <form method="post" class="row g-3" autocomplete="off"><?= csrf_field() ?>
    <div class="col-12"><h6 class="text-muted border-bottom pb-1">قاعدة البيانات</h6></div>
    <?= field('text', 'host', 'الخادم', old('host', '127.0.0.1'), ['col' => 'col-md-6', 'required' => true, 'attrs' => 'dir="ltr"']) ?>
    <?= field('number', 'port', 'المنفذ', old('port', '3306'), ['col' => 'col-md-6', 'required' => true, 'attrs' => 'dir="ltr"']) ?>
    <?= field('text', 'name', 'اسم قاعدة البيانات', old('name', 'education_center_management'), ['col' => 'col-md-6', 'required' => true, 'attrs' => 'dir="ltr"']) ?>
    <?= field('text', 'user', 'مستخدم MySQL', old('user', 'root'), ['col' => 'col-md-6', 'required' => true, 'attrs' => 'dir="ltr"']) ?>
    <?= field('password', 'pass', 'كلمة مرور MySQL', '', ['col' => 'col-12', 'help' => 'في WAMP الافتراضي تكون فارغة لحساب root.', 'attrs' => 'dir="ltr" autocomplete="off"']) ?>
    <div class="col-12"><h6 class="text-muted border-bottom pb-1 mt-2">السنتر والمدير الأول</h6></div>
    <?= field('text', 'center_name', 'اسم السنتر', old('center_name'), ['col' => 'col-12', 'required' => true]) ?>
    <?= field('text', 'admin_fullname', 'اسم المدير', old('admin_fullname'), ['required' => true]) ?>
    <?= field('text', 'admin_username', 'اسم المستخدم', old('admin_username'), ['required' => true, 'attrs' => 'dir="ltr"']) ?>
    <?= field('password', 'admin_password', 'كلمة المرور', '', ['required' => true, 'help' => '10 أحرف على الأقل، أحرف وأرقام.', 'attrs' => 'autocomplete="new-password" dir="ltr"']) ?>
    <?= field('password', 'admin_password2', 'تأكيد كلمة المرور', '', ['required' => true, 'attrs' => 'autocomplete="new-password" dir="ltr"']) ?>
    <div class="col-12"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-download"></i> تثبيت النظام</button></div>
  </form>
<?php endif; ?>
</div></div><script src="<?= e(asset('js/app.js')) ?>"></script></body></html>
