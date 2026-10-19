<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

/** تقديم الملفات المرفوعة بعد التحقق من الصلاحية (مجلد uploads محجوب عن الويب). */
$f = get('f');
if (!preg_match('#^(students|expenses|branding)/[A-Za-z0-9_.-]+$#', $f)) {
    abort(404, 'ملف غير موجود');
}
[$folder] = explode('/', $f, 2);
if ($folder === 'students') {
    require_perm('students.view');
} elseif ($folder === 'expenses') {
    require_perm('expenses.view');
} // branding: الشعار يظهر في صفحة الدخول فهو عام عمداً

$base = realpath(ECM_ROOT . '/uploads');
$path = realpath(ECM_ROOT . '/uploads/' . $f);
if (!$base || !$path || strpos(str_replace('\\', '/', $path), str_replace('\\', '/', $base) . '/') !== 0 || !is_file($path)) {
    abort(404, 'ملف غير موجود');
}
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (!isset($types[$ext])) {
    abort(404, 'نوع ملف غير مسموح');
}
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
readfile($path);
