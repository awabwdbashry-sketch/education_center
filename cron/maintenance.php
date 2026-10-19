<?php
declare(strict_types=1);
/** مهام صيانة اختيارية من سطر الأوامر: تحديث التنبيهات وتنظيف سجلات الدخول القديمة. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/notifications.php';
$n = refresh_notifications();
q('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
echo "notifications created: $n\n";
