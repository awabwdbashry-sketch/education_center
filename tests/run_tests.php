<?php
declare(strict_types=1);
/**
 * اختبارات آلية لمنطق النظام (مالية، حضور، QR، صلاحيات، تثبيت) على قاعدة بيانات مؤقتة تُنشأ وتُحذف تلقائياً.
 * لا تلمس قاعدة بيانات النظام الحقيقية.
 *
 * التشغيل:   php tests/run_tests.php
 * بيانات اتصال MySQL (اختياري):  ECM_TEST_DB_HOST, ECM_TEST_DB_PORT, ECM_TEST_DB_USER, ECM_TEST_DB_PASS
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$root = dirname(__DIR__);
require_once $root . '/setup/install_lib.php';

$dbName = 'ecm_test_' . bin2hex(random_bytes(4));
$tmp = sys_get_temp_dir() . '/ecm_test_' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
$cfg = $tmp . '/config.php';
$lock = $tmp . '/installed.lock';
$dbUser = getenv('ECM_TEST_DB_USER') ?: 'root';
$dbPass = getenv('ECM_TEST_DB_PASS') ?: '';
$dbHost = getenv('ECM_TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int)(getenv('ECM_TEST_DB_PORT') ?: 3306);

$tPass = 0;
$tFail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $tPass, $tFail;
    if ($cond) {
        $tPass++;
        echo "  [PASS] $name\n";
    } else {
        $tFail++;
        echo "  [FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}
function throws(callable $fn, string $class = Throwable::class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}
function cleanup(): void
{
    global $dbName, $dbUser, $dbPass, $dbHost, $dbPort, $tmp;
    try {
        $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
    } catch (Throwable $e) {
    }
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
}
register_shutdown_function('cleanup');

echo "== 1) التثبيت ==\n";
$adminPass = 'Tst-' . bin2hex(random_bytes(6)) . '9';
$base = ['host' => $dbHost, 'port' => $dbPort, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass, 'center_name' => 'سنتر اختبار',
    'admin_username' => 'tadmin', 'admin_fullname' => 'مدير الاختبار', 'admin_password' => $adminPass, 'admin_password2' => $adminPass,
    'config_path' => $cfg, 'lock_path' => $lock];
$weak = ecm_install(array_merge($base, ['admin_password' => '123', 'admin_password2' => '123']));
ok('كلمة مرور ضعيفة مرفوضة ولا يُنشأ config', !$weak['ok'] && !is_file($cfg));
$r = ecm_install($base);
ok('التثبيت ينجح', $r['ok'], implode(' | ', $r['errors']));
ok('تُكتب config وقفل التثبيت', is_file($cfg) && is_file($lock));
$again = ecm_install($base);
ok('إعادة التثبيت مرفوضة بعد القفل', !$again['ok']);
if (!$r['ok']) {
    echo "توقف: فشل التثبيت.\n";
    exit(1);
}

putenv('ECM_CONFIG_FILE=' . $cfg);
$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/finance.php';
require_once $root . '/includes/attendance.php';

$adminId = (int)val("SELECT id FROM users WHERE username = 'tadmin'");
ok('أول مدير أُنشئ بكلمة مرور مشفّرة (ليست نصاً صريحاً)', $adminId > 0 && !str_contains((string)val('SELECT password_hash FROM users WHERE id = ?', [$adminId]), $adminPass) && password_verify($adminPass, (string)val('SELECT password_hash FROM users WHERE id = ?', [$adminId])));
ok('لا يوجد مستخدمون آخرون افتراضيون', (int)val('SELECT COUNT(*) FROM users') === 1);

echo "== 2) تسجيل الدخول والتقييد ==\n";
$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
$res = attempt_login('tadmin', 'wrong-password-1');
ok('كلمة مرور خاطئة ترفض', empty($res['ok']));
for ($i = 0; $i < 6; $i++) {
    attempt_login('tadmin', 'wrong-' . $i);
}
$res = attempt_login('tadmin', $adminPass);
ok('بعد المحاولات الفاشلة يُحجب الدخول حتى بكلمة صحيحة', empty($res['ok']));
q('DELETE FROM login_attempts');
$res = attempt_login('tadmin', $adminPass);
ok('الدخول الصحيح ينجح بعد زوال الحجب', !empty($res['ok']));
ok('سياسة كلمة المرور: قصيرة مرفوضة', password_policy_error('abc123') !== null);
ok('سياسة كلمة المرور: قوية مقبولة', password_policy_error('Abcdef-12345') === null);

echo "== 3) بيانات أساسية ==\n";
$subj = insert('subjects', ['name' => 'رياضيات', 'code' => 'MATH', 'is_active' => 1]);
$tch = insert('teachers', ['full_name' => 'مدرس أ', 'status' => 'active', 'comp_type' => 'per_session', 'comp_amount' => '50.00']);
$mkGroup = function (string $code, string $mode, string $fee, int $max = 2) use ($subj, $tch) {
    $g = insert('class_groups', ['code' => $code, 'name' => $code, 'subject_id' => $subj, 'teacher_id' => $tch, 'max_students' => $max, 'status' => 'active']);
    insert('group_payment_settings', ['group_id' => $g, 'mode' => $mode, 'monthly_fee' => $mode === 'monthly' ? $fee : '0.00', 'session_price' => $mode === 'per_session' ? $fee : '0.00', 'due_days' => 7]);
    return $g;
};
$mkStudent = function (string $name) {
    return insert('students', ['student_no' => next_seq('student', 'ST-'), 'full_name' => $name, 'status' => 'active', 'qr_token' => new_qr_token(), 'qr_status' => 'active', 'registered_at' => today(), 'created_by' => 1]);
};
$gM = $mkGroup('G-M', 'monthly', '300.00', 2);
$gS = $mkGroup('G-S', 'per_session', '40.00', 5);
$s1 = $mkStudent('طالب 1');
$s2 = $mkStudent('طالب 2');
$s3 = $mkStudent('طالب 3');
ok('رقم الطالب فريد وتلقائي', (int)val('SELECT COUNT(DISTINCT student_no) FROM students') === 3);
ok('رمز المجموعة فريد', throws(fn() => insert('class_groups', ['code' => 'G-M', 'name' => 'dup', 'subject_id' => $subj, 'max_students' => 1, 'status' => 'active'])));

echo "== 4) التسجيل والسعة ==\n";
enroll_student($s1, $gM, true, $adminId);
enroll_student($s2, $gM, true, $adminId);
ok('التسجيل لا يُنشئ أي دفعة تلقائياً', (int)val('SELECT COUNT(*) FROM payments') === 0);
ok('التسجيل لا يُنشئ فاتورة تلقائياً', (int)val('SELECT COUNT(*) FROM invoices') === 0);
ok('تجاوز السعة مرفوض', throws(fn() => enroll_student($s3, $gM, true, $adminId)));
ok('تسجيل مكرر في المجموعة نفسها مرفوض', throws(fn() => enroll_student($s1, $gM, true, $adminId)));
enroll_student($s1, $gS, true, $adminId);
enroll_student($s3, $gS, true, $adminId);

echo "== 5) الاستحقاق الشهري (بلا تكرار) ==\n";
$ym = date('Y-m');
$a = generate_monthly_invoices($ym, $adminId);
$b = generate_monthly_invoices($ym, $adminId);
$cnt = (int)val("SELECT COUNT(*) FROM invoices WHERE kind = 'monthly'");
ok('توليد شهري: فاتورتان (طالبان في مجموعة شهرية)', $cnt === 2, "count=$cnt");
ok('إعادة التوليد لا تكرر الفواتير', (int)val("SELECT COUNT(*) FROM invoices WHERE kind = 'monthly'") === 2);
ok('شهر مستقبلي مرفوض', throws(fn() => generate_monthly_invoices(date('Y-m', strtotime('+2 month')), $adminId), FinanceException::class));
$inv1 = (int)val('SELECT id FROM invoices WHERE student_id = ? AND group_id = ?', [$s1, $gM]);
ok('مبلغ الفاتورة 300.00 كاملاً', (string)val('SELECT total FROM invoices WHERE id = ?', [$inv1]) === '300.00');

echo "== 6) الدفعات ==\n";
$pay = function (int $student, array $allocs, string $amountKey, int $advance = 0, string $method = 'cash') use ($adminId) {
    return record_payment(['student_id' => $student, 'allocations' => $allocs, 'advance_cents' => $advance, 'method' => $method, 'reference' => '', 'paid_date' => today(), 'idem_key' => $amountKey, 'user_id' => $adminId, 'notes' => '']);
};
$k1 = sha1('k1');
$p1 = $pay($s1, [$inv1 => 10000], $k1);
ok('دفعة جزئية 100 تُنشئ إيصالاً', !empty($p1['receipt_no']) && empty($p1['duplicate']));
ok('الفاتورة partial والمدفوع 100', (string)val('SELECT status FROM invoices WHERE id = ?', [$inv1]) === 'partial' && (string)val('SELECT paid_amount FROM invoices WHERE id = ?', [$inv1]) === '100.00');
$dup = $pay($s1, [$inv1 => 10000], $k1);
ok('إعادة إرسال نفس العملية (idempotency) لا تكرر الدفعة', !empty($dup['duplicate']) && (int)val('SELECT COUNT(*) FROM payments') === 1);
ok('دفع أكثر من المتبقي مرفوض', throws(fn() => $pay($s1, [$inv1 => 20001], sha1('k2')), FinanceException::class));
ok('دفعة صفرية مرفوضة', throws(fn() => $pay($s1, [$inv1 => 0], sha1('k3')), FinanceException::class));
$inv2 = (int)val('SELECT id FROM invoices WHERE student_id = ? AND group_id = ?', [$s2, $gM]);
ok('دفع فاتورة طالب آخر مرفوض', throws(fn() => $pay($s1, [$inv2 => 1000], sha1('k4')), FinanceException::class));
$p2 = $pay($s1, [$inv1 => 20000], sha1('k5'));
ok('إكمال السداد يجعل الفاتورة paid', (string)val('SELECT status FROM invoices WHERE id = ?', [$inv1]) === 'paid');
ok('أرقام الإيصالات فريدة', (int)val('SELECT COUNT(DISTINCT receipt_no) FROM receipts') === 2);
void_payment((int)$p2['payment_id'], 'خطأ إدخال', $adminId);
ok('إلغاء الدفعة يعيد الفاتورة partial ولا يحذف السجل', (string)val('SELECT status FROM invoices WHERE id = ?', [$inv1]) === 'partial' && (int)val('SELECT COUNT(*) FROM payments') === 2 && (string)val('SELECT status FROM payments WHERE id = ?', [$p2['payment_id']]) === 'voided');
ok('إلغاء دفعة ملغاة مسبقاً مرفوض', throws(fn() => void_payment((int)$p2['payment_id'], 'x', $adminId), FinanceException::class));
ok('إلغاء بدون سبب مرفوض', throws(fn() => void_payment((int)$p1['payment_id'], '', $adminId), FinanceException::class));

echo "== 6b) فشل قاعدة البيانات أثناء الدفعة لا يترك بيانات جزئية ==\n";
$cntP = (int)val('SELECT COUNT(*) FROM payments');
$cntA = (int)val('SELECT COUNT(*) FROM payment_allocations');
$paidBefore = (string)val('SELECT paid_amount FROM invoices WHERE id = ?', [$inv2]);
$seqBefore = (int)val("SELECT `last_value` FROM sequences WHERE name = 'receipt'");
q('ALTER TABLE receipts CHANGE issued_by issued_by_x INT UNSIGNED NULL');   // يحاكي مخططاً ناقصاً: فشل بعد إدخال الدفعة
$failed = false;
try {
    $pay($s2, [$inv2 => 1000], sha1('fail-1'));
} catch (PDOException $e) {
    $failed = true;
}
q('ALTER TABLE receipts CHANGE issued_by_x issued_by INT UNSIGNED NULL');
ok('الفشل يظهر كاستثناء PDO (لا يُبتلع)', $failed);
ok('تُراجَع الدفعة والتخصيص كلياً', (int)val('SELECT COUNT(*) FROM payments') === $cntP && (int)val('SELECT COUNT(*) FROM payment_allocations') === $cntA);
ok('لا يتغير مبلغ الفاتورة المدفوع', (string)val('SELECT paid_amount FROM invoices WHERE id = ?', [$inv2]) === $paidBefore);
ok('لا يُستهلك رقم إيصال', (int)val("SELECT `last_value` FROM sequences WHERE name = 'receipt'") === $seqBefore);
$retry = $pay($s2, [$inv2 => 1000], sha1('fail-1'));
ok('إعادة المحاولة بالمفتاح نفسه بعد إصلاح الخلل تنجح مرة واحدة', empty($retry['duplicate']) && (int)val('SELECT COUNT(*) FROM payments WHERE idempotency_key = ?', [sha1('fail-1')]) === 1);
$retry2 = $pay($s2, [$inv2 => 1000], sha1('fail-1'));
ok('وإعادتها مرة أخرى لا تكرر الدفعة ولا الإيصال', !empty($retry2['duplicate']) && (int)val('SELECT COUNT(*) FROM receipts WHERE payment_id = ?', [$retry['payment_id']]) === 1);
ok('طالب غير موجود مرفوض', throws(fn() => $pay(999999, [$inv2 => 100], sha1('nostu')), FinanceException::class));
ok('مبلغ غير صالح (سالب) مرفوض', throws(fn() => $pay($s2, [$inv2 => -5], sha1('neg')), FinanceException::class));

echo "== 7) دفعة مقدّمة ورصيد دائن ==\n";
$adv = $pay($s2, [], sha1('k6'), 5000);
ok('الدفعة المقدمة تُنشئ رصيداً دائناً 50', student_credit($s2) === 5000, 'credit=' . student_credit($s2));
apply_credit($s2, [$inv2 => 3000], $adminId);
ok('تطبيق الرصيد على فاتورة يخفض الرصيد', student_credit($s2) === 2000);
ok('تطبيق أكثر من الرصيد مرفوض', throws(fn() => apply_credit($s2, [$inv2 => 9000], $adminId), FinanceException::class));

echo "== 8) تسوية الفواتير وإلغاؤها ==\n";
adjust_invoice($inv2, -2000, 'خصم أخ', $adminId);
ok('الخصم يخفض إجمالي الفاتورة', (string)val('SELECT total FROM invoices WHERE id = ?', [$inv2]) === '280.00');
ok('خصم يتجاوز المدفوع مرفوض', throws(fn() => adjust_invoice($inv2, -90000, 'x', $adminId), FinanceException::class));
ok('إلغاء فاتورة عليها دفعات مرفوض', throws(fn() => void_invoice($inv1, 'x', $adminId), FinanceException::class));

echo "== 9) الحضور اليدوي ==\n";
$sess = insert('class_sessions', ['group_id' => $gS, 'session_date' => today(), 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'teacher_id' => $tch, 'status' => 'scheduled', 'created_by' => $adminId]);
$sess2 = insert('class_sessions', ['group_id' => $gS, 'session_date' => today(), 'start_time' => '12:00:00', 'end_time' => '13:00:00', 'teacher_id' => $tch, 'status' => 'scheduled', 'created_by' => $adminId]);
$r1 = save_attendance_batch($sess, [$s1 => 'present', $s3 => 'absent'], [], $adminId, true);
ok('حفظ حضور يدوي', (int)val('SELECT COUNT(*) FROM attendance WHERE session_id = ?', [$sess]) === 2);
$r2 = save_attendance_batch($sess, [$s1 => 'present', $s3 => 'absent'], [], $adminId, true);
ok('إعادة الحفظ لا تكرر السجلات', (int)val('SELECT COUNT(*) FROM attendance WHERE session_id = ?', [$sess]) === 2);
ok('الدليل: قيد unique(session_id, student_id) يمنع التكرار مباشرة', throws(fn() => insert('attendance', ['session_id' => $sess, 'student_id' => $s1, 'status' => 'present', 'method' => 'manual', 'marked_by' => $adminId])));
ok('تسجيل حضور طالب غير مسجّل بالمجموعة مرفوض', (function () use ($sess, $s2, $adminId) {
    try {
        $r = save_attendance_batch($sess, [$s2 => 'present'], [], $adminId, true);
        return (int)val('SELECT COUNT(*) FROM attendance WHERE session_id = ? AND student_id = ?', [$sess, $s2]) === 0;
    } catch (Throwable $e) {
        return true;
    }
})());

echo "== 10) حضور QR ==\n";
$tok1 = (string)val('SELECT qr_token FROM students WHERE id = ?', [$s1]);
$tok3 = (string)val('SELECT qr_token FROM students WHERE id = ?', [$s3]);
$x = scan_attendance($sess2, qr_payload($tok1), $adminId);
ok('مسح صالح يسجل الحضور', $x['ok'] && $x['code'] === 'recorded', json_encode($x, JSON_UNESCAPED_UNICODE));
$x = scan_attendance($sess2, qr_payload($tok1), $adminId);
ok('مسح مكرر يُرفض بلا سجل ثانٍ', !$x['ok'] && $x['code'] === 'duplicate' && (int)val('SELECT COUNT(*) FROM attendance WHERE session_id = ? AND student_id = ?', [$sess2, $s1]) === 1);
$x = scan_attendance($sess2, 'ECM1.' . str_repeat('0', 48), $adminId);
ok('رمز غير موجود يُرفض', !$x['ok'] && $x['code'] === 'invalid');
$tok2 = (string)val('SELECT qr_token FROM students WHERE id = ?', [$s2]);
$x = scan_attendance($sess2, qr_payload($tok2), $adminId);
ok('طالب غير مسجّل في المجموعة يُرفض', !$x['ok'] && $x['code'] === 'not_enrolled');
q("UPDATE students SET qr_status = 'revoked' WHERE id = ?", [$s3]);
$x = scan_attendance($sess2, qr_payload($tok3), $adminId);
ok('رمز موقوف يُرفض', !$x['ok'] && $x['code'] === 'revoked');
q("UPDATE students SET qr_status = 'active' WHERE id = ?", [$s3]);
$x = scan_attendance($sess2, strtolower((string)val('SELECT student_no FROM students WHERE id = ?', [$s3])), $adminId, true);
ok('تسجيل بالرقم بديلاً للكاميرا', $x['ok'], json_encode($x, JSON_UNESCAPED_UNICODE));
$m = (string)val('SELECT method FROM attendance WHERE session_id = ? AND student_id = ?', [$sess2, $s1]);
ok('تُسجَّل طريقة الحضور qr', $m === 'qr', "method=$m");
ok('تسجيل المستخدم ووقت الحضور', (int)val('SELECT marked_by FROM attendance WHERE session_id = ? AND student_id = ?', [$sess2, $s1]) === $adminId);

echo "== 11) الدفع حسب الحصة ==\n";
$until = today();
$g1 = generate_session_invoices($gS, $until, $adminId);
$n1 = (int)val("SELECT COUNT(*) FROM invoice_items WHERE attendance_id IS NOT NULL");
$g2 = generate_session_invoices($gS, $until, $adminId);
$n2 = (int)val("SELECT COUNT(*) FROM invoice_items WHERE attendance_id IS NOT NULL");
ok('توليد حسب الحصة يعمل ولا يُكرر عند إعادته', $n1 > 0 && $n1 === $n2, "n1=$n1 n2=$n2");
ok('الغياب لا يُحتسب كحصة مدفوعة', (int)val("SELECT COUNT(*) FROM invoice_items ii JOIN attendance a ON a.id = ii.attendance_id WHERE a.status = 'absent'") === 0);

echo "== 12) مستحقات المدرسين ==\n";
q("UPDATE class_sessions SET status = 'held' WHERE id IN (?, ?)", [$sess, $sess2]);
$t1 = accrue_teacher_compensations($ym, $adminId);
$c1 = (int)val('SELECT COUNT(*) FROM teacher_compensations');
$t2 = accrue_teacher_compensations($ym, $adminId);
ok('الاحتساب يعمل ولا يتكرر', $c1 > 0 && $c1 === (int)val('SELECT COUNT(*) FROM teacher_compensations'), "c1=$c1");
ok('المستحق لا يُعد مصروفاً قبل الصرف', (int)val("SELECT COUNT(*) FROM expenses") === 0);
$bal = teacher_balance($tch);
$due = (int)($bal['balance'] ?? 0);
ok('رصيد المدرس موجب', $due > 0, json_encode($bal));
$po = record_teacher_payout($tch, 5000, 'cash', '', today(), '', $adminId);
ok('الصرف يُنشئ مصروفاً واحداً', (int)val("SELECT COUNT(*) FROM expenses WHERE status = 'valid'") === 1);
ok('صرف أكثر من المتبقي مرفوض', throws(fn() => record_teacher_payout($tch, 99999999, 'cash', '', today(), '', $adminId), FinanceException::class));
void_teacher_payout($po, 'خطأ', $adminId);
ok('إلغاء الصرف يلغي المصروف المرتبط', (int)val("SELECT COUNT(*) FROM expenses WHERE status = 'valid'") === 0);
ok('إلغاء الصرف مرتين مرفوض', throws(fn() => void_teacher_payout($po, 'x', $adminId), FinanceException::class));

echo "== 13) الصلاحيات ==\n";
$roles = [];
foreach (rows('SELECT id, name FROM roles') as $r) {
    $roles[$r['name']] = (int)$r['id'];
}
$hasPerm = fn(string $role, string $perm) => (bool)val('SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.code = ?', [$roles[$role], $perm]);
ok('الكاشير يملك التحصيل', $hasPerm('cashier', 'payments.collect'));
ok('الكاشير لا يملك إلغاء الدفعات', !$hasPerm('cashier', 'payments.void'));
ok('الكاشير لا يدير المستخدمين', !$hasPerm('cashier', 'users.manage'));
ok('الاستقبال لا يملك التحصيل', !$hasPerm('reception', 'payments.collect'));
ok('المدرس لا يملك الإعدادات ولا المالية', !$hasPerm('teacher', 'settings.manage') && !$hasPerm('teacher', 'payments.collect'));
ok('المدير يملك إلغاء الدفعات', $hasPerm('admin', 'payments.void'));

echo "== 14) تكامل المبالغ ==\n";
$sumInv = (int)round((float)val("SELECT COALESCE(SUM(paid_amount),0) FROM invoices WHERE status != 'void'") * 100);
$sumAlloc = (int)round((float)val("SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a JOIN payments p ON p.id = a.payment_id WHERE p.status = 'valid'") * 100);
ok('مجموع المدفوع على الفواتير = مجموع التخصيصات المعتمدة', $sumInv === $sumAlloc, "inv=$sumInv alloc=$sumAlloc");
$neg = (int)val('SELECT COUNT(*) FROM invoices WHERE total < 0 OR paid_amount < 0 OR paid_amount > total');
ok('لا فواتير بمبالغ سالبة أو مدفوع أكبر من الإجمالي', $neg === 0, "bad=$neg");
ok('سجل التدقيق يحتوي عمليات مالية', (int)val("SELECT COUNT(*) FROM audit_logs") > 0);

echo "== 15) الأرقام المتسلسلة (sequences) والتزامن ==\n";
$ver = (string)val('SELECT VERSION()');
echo "  (قاعدة الاختبار: $ver)\n";
ok('العمود `last_value` موجود بالاسم المحجوز ويُقرأ بالاقتباس', val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sequences' AND COLUMN_NAME = 'last_value'") == 1);
$seen = [];
for ($i = 0; $i < 50; $i++) {
    $seen[] = next_seq('receipt', 'R-');
}
ok('50 رقماً متتالياً فريدة', count(array_unique($seen)) === 50);
$nums = array_map(fn($x) => (int)substr($x, 2), $seen);
ok('الترقيم متزايد دائماً بخطوة 1', $nums === range($nums[0], $nums[0] + 49));
$before = (int)val("SELECT `last_value` FROM sequences WHERE name = 'receipt'");
$procs = [];
$php = PHP_BINARY;
for ($w = 0; $w < 6; $w++) {
    $p = proc_open([$php, __DIR__ . '/_seq_worker.php', '30'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['ECM_CONFIG_FILE' => $cfg] + getenv());
    $procs[] = [$p, $pipes];
}
$all = [];
foreach ($procs as [$p, $pipes]) {
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($p);
    foreach (array_filter(explode("\n", trim($out))) as $l) {
        $all[] = $l;
    }
    if ($err !== '') {
        echo "  stderr: " . trim($err) . "\n";
    }
}
ok('6 عمليات متوازية × 30 = 180 رقماً', count($all) === 180, 'count=' . count($all));
ok('لا تكرار بين العمليات المتزامنة', count(array_unique($all)) === count($all));
ok('العداد زاد بالضبط 180', (int)val("SELECT `last_value` FROM sequences WHERE name = 'receipt'") === $before + 180);

echo "\n------------------------------\n";
echo "النتيجة: نجح {$tPass} — فشل {$tFail}\n";
exit($tFail === 0 ? 0 : 1);
