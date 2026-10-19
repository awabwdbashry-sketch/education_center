<?php
declare(strict_types=1);

require_once __DIR__ . '/sql.php';

function backup_dir(): string
{
    $d = ECM_ROOT . '/storage/backups';
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

/** تصدير قاعدة البيانات إلى ملف SQL (بلا اعتماد على mysqldump) داخل storage/backups المحمي. */
function do_backup(?int $userId, string $note = ''): array
{
    $pdo = db();
    $name = 'backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql';
    $path = backup_dir() . '/' . $name;
    try {
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new RuntimeException('تعذر إنشاء ملف النسخة في storage/backups. تحقق من صلاحيات الكتابة.');
        }
        fwrite($fh, "-- ECM backup " . date('c') . "\n-- " . str_replace(["\n", "\r"], ' ', $note) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET sql_mode='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
            $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`', PDO::FETCH_NUM);
            $batch = [];
            $cols = null;
            while ($r = $st->fetch(PDO::FETCH_NUM)) {
                if ($cols === null) {
                    $cols = $st->columnCount();
                }
                $vals = [];
                foreach ($r as $v) {
                    $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 200) {
                    fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- END OF BACKUP\n");
        fclose($fh);
        @chmod($path, 0640);
        $size = (int)filesize($path);
        $hash = hash_file('sha256', $path);
        $id = insert('backup_logs', ['kind' => 'backup', 'filename' => $name, 'size_bytes' => $size, 'sha256' => $hash, 'status' => 'success', 'message' => $note ?: 'نسخة احتياطية كاملة', 'created_by' => $userId]);
        return ['ok' => true, 'id' => $id, 'file' => $name, 'size' => $size];
    } catch (Throwable $e) {
        if (isset($fh) && is_resource($fh)) {
            fclose($fh);
        }
        @unlink($path);
        error_log('[ECM backup] ' . $e->getMessage());
        try {
            insert('backup_logs', ['kind' => 'backup', 'filename' => null, 'size_bytes' => 0, 'status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 450), 'created_by' => $userId]);
        } catch (Throwable $e2) {
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * استعادة نسخة أنشأها النظام نفسه فقط (يُتحقق من وجودها في backup_logs ومن بصمة SHA-256).
 * تُؤخذ نسخة أمان تلقائية قبل الاستعادة. لا تُقبل ملفات SQL مرفوعة.
 */
function do_restore(int $backupLogId, int $userId): array
{
    $log = row("SELECT * FROM backup_logs WHERE id = ? AND kind = 'backup' AND status = 'success'", [$backupLogId]);
    if (!$log || !$log['filename'] || !preg_match('/^backup_\d{8}_\d{6}_[a-f0-9]{6}\.sql$/', $log['filename'])) {
        return ['ok' => false, 'error' => 'النسخة غير موجودة في سجل النسخ.'];
    }
    $path = backup_dir() . '/' . $log['filename'];
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'ملف النسخة غير موجود على القرص.'];
    }
    if (!hash_equals((string)$log['sha256'], hash_file('sha256', $path))) {
        return ['ok' => false, 'error' => 'بصمة الملف لا تطابق السجل (الملف تغيّر أو تلف). أُلغيت الاستعادة.'];
    }
    $sql = (string)file_get_contents($path);
    if (strpos($sql, '-- END OF BACKUP') === false) {
        return ['ok' => false, 'error' => 'ملف النسخة غير مكتمل.'];
    }
    $safety = do_backup($userId, 'نسخة أمان تلقائية قبل الاستعادة #' . $backupLogId);
    if (!$safety['ok']) {
        return ['ok' => false, 'error' => 'تعذر أخذ نسخة الأمان قبل الاستعادة: ' . $safety['error']];
    }
    $pdo = db();
    try {
        foreach (sql_split($sql) as $stmt) {
            $pdo->exec($stmt);
        }
    } catch (Throwable $e) {
        error_log('[ECM restore] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'فشلت الاستعادة أثناء التنفيذ. نسخة الأمان محفوظة: ' . $safety['file']];
    }
    // سجل الاستعادة يُكتب بعد انتهائها (الجداول أُعيد إنشاؤها)
    try {
        // الجداول استُبدلت بحالة وقت النسخة: أعد تسجيل ملفات النسخ الموجودة على القرص كي تبقى قابلة للاستعادة.
        foreach ([$log['filename'], $safety['file']] as $fn) {
            $fp = backup_dir() . '/' . $fn;
            if (is_file($fp) && !row("SELECT id FROM backup_logs WHERE filename = ? AND kind = 'backup'", [$fn])) {
                insert('backup_logs', ['kind' => 'backup', 'filename' => $fn, 'size_bytes' => filesize($fp), 'sha256' => hash_file('sha256', $fp), 'status' => 'success', 'message' => 'أُعيد تسجيلها بعد الاستعادة', 'created_by' => $userId]);
            }
        }
        insert('backup_logs', ['kind' => 'restore', 'filename' => $log['filename'], 'size_bytes' => (int)$log['size_bytes'], 'sha256' => $log['sha256'], 'status' => 'success', 'message' => 'استعادة من النسخة #' . $backupLogId . ' (نسخة الأمان: ' . $safety['file'] . ')', 'created_by' => $userId]);
        audit('backup_restored', 'backup_log', $backupLogId, ['safety' => $safety['file']]);
    } catch (Throwable $e) {
        error_log('[ECM restore log] ' . $e->getMessage());
    }
    return ['ok' => true, 'safety' => $safety['file']];
}
