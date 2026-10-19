<?php
declare(strict_types=1);
/**
 * نسخ احتياطي من سطر الأوامر — يُجدول عبر Windows Task Scheduler (انظر README.md).
 * php C:\wamp64\www\education_center\cron\backup.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/notifications.php';

$r = do_backup(null, 'نسخة مجدولة');
// الاحتفاظ بآخر 30 نسخة ناجحة فقط
$keep = rows("SELECT id, filename FROM backup_logs WHERE kind = 'backup' AND status = 'success' AND filename IS NOT NULL ORDER BY id DESC LIMIT 1000 OFFSET 30");
foreach ($keep as $old) {
    $f = backup_dir() . '/' . $old['filename'];
    if (is_file($f)) {
        @unlink($f);
    }
    q("UPDATE backup_logs SET message = CONCAT(COALESCE(message,''), ' (حُذف الملف بسياسة الاحتفاظ)'), filename = NULL WHERE id = ?", [$old['id']]);
}
refresh_notifications();
echo $r['ok'] ? "OK {$r['file']} ({$r['size']} bytes)\n" : "FAILED {$r['error']}\n";
exit($r['ok'] ? 0 : 1);
