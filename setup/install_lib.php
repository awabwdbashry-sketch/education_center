<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/sql.php';

/**
 * التثبيت الأولي: ينشئ قاعدة البيانات والجداول وأول مدير ويكتب config.php.
 * @return array{ok:bool,errors:string[],notes:string[]}
 */
function ecm_install(array $p): array
{
    $root = dirname(__DIR__);
    $cfgPath  = $p['config_path'] ?? $root . '/config/config.php';
    $lockPath = $p['lock_path'] ?? $root . '/config/installed.lock';
    $errors = [];
    $notes = [];

    if (is_file($lockPath) || is_file($cfgPath)) {
        return ['ok' => false, 'errors' => ['النظام مثبّت مسبقاً. لإعادة التهيئة يدوياً احذف config/config.php وconfig/installed.lock بعد أخذ نسخة احتياطية.'], 'notes' => []];
    }
    $host = trim((string)($p['host'] ?? '127.0.0.1'));
    $port = (int)($p['port'] ?? 3306);
    $name = trim((string)($p['name'] ?? 'education_center_management'));
    $user = (string)($p['user'] ?? 'root');
    $pass = (string)($p['pass'] ?? '');
    $center = trim((string)($p['center_name'] ?? ''));
    $adminUser = trim((string)($p['admin_username'] ?? ''));
    $adminName = trim((string)($p['admin_fullname'] ?? ''));
    $adminPass = (string)($p['admin_password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        $errors[] = 'اسم قاعدة البيانات يجب أن يحتوي على أحرف إنجليزية وأرقام وشرطة سفلية فقط.';
    }
    if ($host === '' || $port < 1 || $port > 65535) {
        $errors[] = 'عنوان الخادم أو المنفذ غير صالح.';
    }
    if ($center === '') {
        $errors[] = 'اسم السنتر مطلوب.';
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $adminUser)) {
        $errors[] = 'اسم مستخدم المدير: 3–40 حرفاً إنجليزياً أو أرقاماً.';
    }
    if ($adminName === '') {
        $errors[] = 'اسم المدير الكامل مطلوب.';
    }
    if (mb_strlen($adminPass) < 10 || !preg_match('/[A-Za-z]/', $adminPass) || !preg_match('/\d/', $adminPass)) {
        $errors[] = 'كلمة مرور المدير: 10 أحرف على الأقل وتحتوي على أحرف وأرقام.';
    }
    if (!empty($p['admin_password2']) && $p['admin_password2'] !== $adminPass) {
        $errors[] = 'تأكيد كلمة المرور غير مطابق.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'notes' => []];
    }

    try {
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $srv = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $opts);
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['تعذر الاتصال بالخادم: تحقق من تشغيل MySQL في WAMP ومن بيانات الدخول.'], 'notes' => []];
    }
    try {
        $srv->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opts);
        $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $notes[] = 'الاتصال بقاعدة البيانات ناجح (' . $pdo->query('SELECT VERSION()')->fetchColumn() . ').';

        $hasUsers = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
        if ($hasUsers && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            return ['ok' => false, 'errors' => ['قاعدة البيانات تحتوي على مستخدمين بالفعل. لن تتم إعادة التهيئة حفاظاً على البيانات.'], 'notes' => $notes];
        }
        if (!$hasUsers) {
            foreach (sql_split((string)file_get_contents($root . '/database/schema.sql')) as $stmt) {
                if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) {
                    continue; // نستخدم اسم القاعدة المختار
                }
                $pdo->exec($stmt);
            }
            $notes[] = 'تم إنشاء الجداول والبيانات الابتدائية.';
        } else {
            $notes[] = 'الجداول موجودة (مستوردة مسبقاً) — سيُكتفى بإنشاء المدير.';
        }

        $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();
        if (!$roleId) {
            throw new RuntimeException('دور المدير غير موجود في قاعدة البيانات.');
        }
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO users (username, full_name, password_hash, status) VALUES (?,?,?,?)');
        $st->execute([$adminUser, $adminName, password_hash($adminPass, PASSWORD_DEFAULT), 'active']);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$uid, $roleId]);
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute(['center_name', $center]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, username, action, entity_type, entity_id, details, ip) VALUES (?,?,?,?,?,?,?)')
            ->execute([$uid, $adminUser, 'system_installed', 'user', $uid, null, $_SERVER['REMOTE_ADDR'] ?? 'cli']);
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ECM setup] ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['فشل التثبيت أثناء إعداد قاعدة البيانات: ' . $e->getMessage()], 'notes' => $notes];
    }

    $cfg = [
        'db' => ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4'],
        'timezone' => (string)($p['timezone'] ?? 'Africa/Khartoum'),
        'debug' => false,
    ];
    $code = "<?php\n// أُنشئ تلقائياً بواسطة setup — لا ترفعه إلى أي مستودع.\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents($cfgPath, $code, LOCK_EX) === false) {
        return ['ok' => false, 'errors' => ['تم إنشاء قاعدة البيانات والمدير لكن تعذرت كتابة config/config.php. امنح المجلد صلاحية الكتابة ثم أنشئ الملف يدوياً من config.sample.php.'], 'notes' => $notes];
    }
    @file_put_contents($lockPath, date('c') . "\n", LOCK_EX);
    $notes[] = 'تم إنشاء ملف الإعدادات وقفل التثبيت.';
    return ['ok' => true, 'errors' => [], 'notes' => $notes];
}
