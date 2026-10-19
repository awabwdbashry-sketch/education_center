<?php
declare(strict_types=1);
/**
 * تشخيص مسار تسجيل الدفعات على قاعدة بياناتك الفعلية — بلا أي تغيير دائم.
 *
 * التشغيل (من مجلد المشروع، مثال WAMP):
 *   C:\wamp64\bin\php\php8.x.x\php.exe tests\diagnose_payment.php
 *
 * ماذا يفعل:
 *  1) يعرض نسخة PHP وقاعدة البيانات والإضافات ووضع SQL.
 *  2) يقارن جداول وأعمدة قاعدتك بملف database/schema.sql (يكشف مخططاً قديماً أو ناقصاً).
 *  3) ينفّذ دفعة تجريبية صغيرة داخل معاملة ثم يتراجع عنها (ROLLBACK) دائماً،
 *     ويطبع الخطأ الفعلي كاملاً إن وُجد. لا تُحفظ دفعة ولا إيصال ولا رقم تسلسلي.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/finance.php';

function line(string $s = ''): void
{
    echo $s . PHP_EOL;
}
$problems = 0;

line('== 1) البيئة ==');
line('PHP: ' . PHP_VERSION . ' (' . PHP_OS_FAMILY . ')');
foreach (['pdo_mysql', 'mbstring', 'fileinfo'] as $ext) {
    $has = extension_loaded($ext);
    line(sprintf('  إضافة %-10s %s', $ext, $has ? 'موجودة' : 'مفقودة  <== مشكلة'));
    $problems += $has ? 0 : 1;
}
try {
    line('قاعدة البيانات: ' . db()->query('SELECT VERSION()')->fetchColumn() . ' | الاسم: ' . db()->query('SELECT DATABASE()')->fetchColumn());
    line('sql_mode: ' . db()->query('SELECT @@SESSION.sql_mode')->fetchColumn());
} catch (Throwable $e) {
    line('تعذر الاتصال: ' . $e->getMessage());
    exit(2);
}

line();
line('== 2) مطابقة المخطط مع database/schema.sql ==');
$sql = (string)file_get_contents(__DIR__ . '/../database/schema.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?\s*\((.*?)\)\s*ENGINE=/s', $sql, $m, PREG_SET_ORDER);
$schemaOk = true;
foreach ($m as $t) {
    $table = $t[1];
    $cols = [];
    foreach (preg_split('/\R/', $t[2]) as $ln) {
        if (preg_match('/^\s*`?([a-z_][a-z0-9_]*)`?\s+[A-Za-z]+/i', $ln, $c) && !in_array(strtolower($c[1]), ['key', 'unique', 'primary', 'constraint', 'foreign', 'index', 'check', 'fulltext'], true)) {
            $cols[] = strtolower($c[1]);
        }
    }
    $have = array_map('strtolower', array_column(rows('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]), 'c'));
    if (!$have) {
        line("  جدول مفقود: $table  <== مشكلة");
        $schemaOk = false;
        $problems++;
        continue;
    }
    $missing = array_diff($cols, $have);
    if ($missing) {
        line("  جدول $table ينقصه أعمدة: " . implode(', ', $missing) . '  <== مشكلة');
        $schemaOk = false;
        $problems++;
    }
}
if ($schemaOk) {
    line('  كل الجداول والأعمدة المتوقعة موجودة (' . count($m) . ' جدولاً).');
}
$engines = rows("SELECT TABLE_NAME t, ENGINE e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> 'InnoDB'");
foreach ($engines as $en) {
    line('  الجدول ' . $en['t'] . ' ليس InnoDB (' . $en['e'] . ') — لا تعمل المعاملات عليه  <== مشكلة');
    $problems++;
}

line();
line('== 3) دفعة تجريبية داخل معاملة تُلغى دائماً ==');
$user = row("SELECT id FROM users WHERE status = 'active' ORDER BY id LIMIT 1") ?? row('SELECT id FROM users ORDER BY id LIMIT 1');
$stu = row("SELECT s.id FROM students s JOIN invoices i ON i.student_id = s.id WHERE i.status IN ('open','partial') ORDER BY i.id LIMIT 1");
$pm = payment_methods(true);
$method = array_key_first($pm);
if (!$user || !$method) {
    line('  لا يوجد مستخدم أو وسيلة دفع مفعّلة — تعذّر الاختبار.');
    exit($problems ? 1 : 0);
}
if (!$stu) {
    $stu = row('SELECT id FROM students ORDER BY id LIMIT 1');
    line('  لا توجد فاتورة مفتوحة؛ سيُختبر مسار الدفعة المقدمة.');
}
if (!$stu) {
    line('  لا يوجد طلاب — أضف طالباً ثم أعد التشغيل.');
    exit($problems ? 1 : 0);
}
$alloc = [];
$adv = 0;
$inv = row("SELECT id FROM invoices WHERE student_id = ? AND status IN ('open','partial') ORDER BY id LIMIT 1", [$stu['id']]);
if ($inv) {
    $alloc[(int)$inv['id']] = 1;   // 0.01 فقط
} else {
    $adv = 1;
}
$pdo = db();
$pdo->beginTransaction();
try {
    $res = record_payment(['student_id' => (int)$stu['id'], 'allocations' => $alloc, 'advance_cents' => $adv, 'method' => $method,
        'reference' => isset($pm[$method]['requires_ref']) && $pm[$method]['requires_ref'] ? 'DIAG' : '', 'paid_date' => today(), 'idem_key' => sha1('diag' . microtime(true)),
        'user_id' => (int)$user['id'], 'notes' => 'تشخيص — يُتراجع عنه']);
    line('  نجح مسار الدفعة. إيصال تجريبي: ' . $res['receipt_no'] . ' (سيُتراجع عنه الآن).');
    $dupe = record_payment(['student_id' => (int)$stu['id'], 'allocations' => $alloc, 'advance_cents' => $adv, 'method' => $method, 'reference' => 'DIAG', 'paid_date' => today(), 'idem_key' => sha1('diag' . microtime(true)) , 'user_id' => (int)$user['id'], 'notes' => '']);
    line('  دفعة ثانية بمفتاح مختلف نجحت أيضاً (متوقع).');
} catch (Throwable $e) {
    $problems++;
    line('  <== فشل. هذا هو الخطأ الفعلي:');
    line('  النوع   : ' . get_class($e));
    if ($e instanceof PDOException && is_array($e->errorInfo ?? null)) {
        line('  SQLSTATE: ' . ($e->errorInfo[0] ?? '') . '  رمز MySQL: ' . ($e->errorInfo[1] ?? ''));
    }
    line('  الرسالة : ' . $e->getMessage());
    line('  الموضع  : ' . basename($e->getFile()) . ':' . $e->getLine());
    foreach (array_slice($e->getTrace(), 0, 6) as $f) {
        line('     ← ' . basename((string)($f['file'] ?? '?')) . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''));
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
$left = (int)val("SELECT COUNT(*) FROM payments WHERE notes LIKE 'تشخيص%'");
line('  بعد التراجع: دفعات التشخيص المتبقية في القاعدة = ' . $left . ($left === 0 ? ' (سليم)' : '  <== غير متوقع'));

line();
line($problems ? "انتهى: وُجدت $problems مشكلة/مشاكل — أرسل لي هذا المخرج كاملاً." : 'انتهى: لا مشاكل ظاهرة في مسار الدفعات على هذه القاعدة.');
exit($problems ? 1 : 0);
