<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
// الخروج عبر POST مع رمز CSRF فقط (يتحقق منه bootstrap) لمنع الخروج القسري عبر روابط خارجية
if (!is_post()) {
    redirect('index.php');
}
logout_user(true);
header('Location: ' . url('login.php'));
exit;
