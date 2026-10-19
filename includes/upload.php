<?php
declare(strict_types=1);

/**
 * رفع ملف آمن: يتحقق من الحجم والنوع الفعلي (finfo) ويولّد اسماً عشوائياً ولا يقبل ملفات تنفيذية.
 * @return array{ok:bool,path?:string,error?:string}  path نسبي داخل uploads/
 */
function save_upload(string $field, string $folder, array $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], int $maxBytes = 2097152): array
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => ''];
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'تعذر رفع الملف (رمز الخطأ ' . (int)$f['error'] . ').'];
    }
    if ($f['size'] > $maxBytes || $f['size'] <= 0) {
        return ['ok' => false, 'error' => 'حجم الملف يتجاوز الحد المسموح (' . round($maxBytes / 1048576, 1) . ' ميغابايت).'];
    }
    if (!is_uploaded_file($f['tmp_name']) && !defined('ECM_TESTING')) {
        return ['ok' => false, 'error' => 'ملف غير صالح.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'نوع الملف غير مسموح.'];
    }
    // فحص إضافي: الصور يجب أن تُقرأ كصور فعلية، ولا تحتوي شيفرة PHP
    if (strpos($mime, 'image/') === 0 && @getimagesize($f['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'الصورة تالفة أو غير صالحة.'];
    }
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 4096);
    if (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false) {
        return ['ok' => false, 'error' => 'الملف يحتوي على محتوى غير مسموح.'];
    }
    $dir = ECM_ROOT . '/uploads/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'error' => 'تعذر إنشاء مجلد الرفع.'];
    }
    $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $name;
    $moved = defined('ECM_TESTING') ? @copy($f['tmp_name'], $dest) : @move_uploaded_file($f['tmp_name'], $dest);
    if (!$moved) {
        return ['ok' => false, 'error' => 'تعذر حفظ الملف.'];
    }
    @chmod($dest, 0644);
    return ['ok' => true, 'path' => $folder . '/' . $name];
}

function delete_upload(?string $rel): void
{
    if ($rel && preg_match('#^(students|expenses|branding)/[A-Za-z0-9_.-]+$#', $rel)) {
        @unlink(ECM_ROOT . '/uploads/' . $rel);
    }
}
