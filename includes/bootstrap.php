<?php
declare(strict_types=1);

/**
 * نقطة البداية المشتركة لكل الصفحات: الأخطاء، الإعدادات، الجلسة، الحماية.
 * قبل التثبيت (لا يوجد config.php) يُحوَّل الزائر إلى setup/index.php
 */
define('ECM_ROOT', str_replace('\\', '/', dirname(__DIR__)));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
@mkdir(ECM_ROOT . '/storage/logs', 0775, true);
ini_set('error_log', ECM_ROOT . '/storage/logs/php_errors.log');

$__cfgFile = getenv('ECM_CONFIG_FILE') ?: ECM_ROOT . '/config/config.php';
$GLOBALS['ECM_CONFIG'] = is_file($__cfgFile) ? (require $__cfgFile) : null;
date_default_timezone_set($GLOBALS['ECM_CONFIG']['timezone'] ?? 'Africa/Khartoum');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

/**
 * تسجيل استثناء داخلياً (بدون كلمات مرور أو قيم النماذج) وإرجاع رمز مرجعي قصير للمستخدم.
 * يُسجَّل: النوع، الرسالة، رمز SQLSTATE/الخطأ إن وُجد، الموضع، المسار والطريقة، وأول إطارات التتبع.
 */
function ecm_log_exception(Throwable $e, string $context = ''): string
{
    $ref = 'E' . strtoupper(bin2hex(random_bytes(3)));
    $info = '';
    if ($e instanceof PDOException && is_array($e->errorInfo ?? null)) {
        $info = ' [sqlstate=' . ($e->errorInfo[0] ?? '') . ' code=' . ($e->errorInfo[1] ?? '') . ']';
    }
    $frames = [];
    foreach (array_slice($e->getTrace(), 0, 6) as $f) {
        $frames[] = basename((string)($f['file'] ?? '?')) . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? '');
    }
    $req = (PHP_SAPI === 'cli') ? 'cli' : (($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'));
    error_log('[ECM ' . $ref . '] ' . $context . ' ' . get_class($e) . $info . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . ' | ' . $req . ' | ' . implode(' < ', $frames));
    return $ref;
}

/** المسار الأساسي للمشروع على الخادم (يدعم التشغيل داخل مجلد فرعي في WAMP) */
function base_path(): string
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $script = str_replace('\\', '/', $script);
    $rel    = (strpos($script, ECM_ROOT) === 0) ? substr($script, strlen(ECM_ROOT)) : '';
    $name   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $b      = ($rel !== '' && substr($name, -strlen($rel)) === $rel) ? substr($name, 0, -strlen($rel)) : rtrim(dirname($name), '/');
    return $b;
}

function csp_nonce(): string
{
    static $n = null;
    return $n ??= bin2hex(random_bytes(12));
}

if (PHP_SAPI !== 'cli') {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "'; media-src 'self' blob:; font-src 'self'; frame-ancestors 'self'; form-action 'self'");

    set_exception_handler(function (Throwable $e) {
        $ref = ecm_log_exception($e, 'uncaught');
        if (!headers_sent()) {
            http_response_code(500);
        }
        $debug = !empty($GLOBALS['ECM_CONFIG']['debug']);
        echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>خطأ</title>'
            . '<body style="font-family:Tahoma,Arial;background:#fbf7ef;padding:3rem;text-align:center">'
            . '<h2>حدث خطأ غير متوقع</h2><p>تم تسجيل الخطأ ولن تُفقد بياناتك. حاول مرة أخرى أو راجع مدير النظام.</p>'
            . '<p>رمز الخطأ للمدير: <b dir="ltr">' . htmlspecialchars($ref) . '</b> (ابحث عنه في storage/logs/php_errors.log)</p>'
            . ($debug ? '<pre style="text-align:left;direction:ltr">' . htmlspecialchars($e->getMessage()) . '</pre>' : '')
            . '<p><a href="javascript:history.back()">رجوع</a></p></body></html>';
        exit;
    });

    if (!defined('ECM_SETUP')) {
        if ($GLOBALS['ECM_CONFIG'] === null) {
            header('Location: ' . base_path() . '/setup/index.php');
            exit;
        }
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('ECMSESSID');
    session_set_cookie_params(['lifetime' => 0, 'path' => (base_path() ?: '/'), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !defined('ECM_NO_CSRF')) {
        csrf_check();
    }
}
