<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}
$error = '';
if (is_post()) {
    $r = attempt_login(post('username'), (string)($_POST['password'] ?? ''));
    if ($r['ok']) {
        $next = post('next');
        if ($next !== '' && preg_match('#^/[A-Za-z0-9_/\.\-?=&%]*$#', $next) && strpos($next, '//') === false && strpos($next, base_path() . '/') === 0) {
            redirect($next);
        }
        redirect('index.php');
    }
    $error = $r['msg'];
}
$center = (string)setting('center_name', 'السنتر التعليمي');
$logo = (string)setting('center_logo', '');
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — <?= e($center) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.rtl.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('vendor/icons/bootstrap-icons.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"></head>
<body><div class="login-wrap"><div class="login-card">
<?php if ($logo): ?><img src="<?= e(url('file.php?f=' . rawurlencode($logo))) ?>" class="ecm-logo d-block mx-auto mb-3" style="width:64px;height:64px" alt=""><?php else: ?><div class="login-logo"><i class="bi bi-mortarboard-fill"></i></div><?php endif; ?>
<h4 class="text-center mb-1"><?= e($center) ?></h4><p class="text-center text-muted mb-4">تسجيل الدخول إلى لوحة الإدارة</p>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-x-octagon"></i> <?= e($error) ?></div><?php endif; ?>
<form method="post" autocomplete="on"><?= csrf_field() ?><input type="hidden" name="next" value="<?= e(get('next')) ?>">
  <div class="mb-3"><label class="form-label" for="u">اسم المستخدم</label><input class="form-control" id="u" name="username" value="<?= e(post('username')) ?>" required autofocus dir="ltr" autocomplete="username"></div>
  <div class="mb-3"><label class="form-label" for="p">كلمة المرور</label><input class="form-control" type="password" id="p" name="password" required dir="ltr" autocomplete="current-password"></div>
  <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-left"></i> دخول</button>
</form></div></div><script src="<?= e(asset('js/app.js')) ?>"></script></body></html>
