<?php
declare(strict_types=1);
/** الوحدة اختيارية: تُفعَّل من الإعدادات */
function require_exams_enabled(): void
{
    if (setting('exams_enabled', '0') !== '1') {
        abort(404, 'وحدة الاختبارات غير مفعّلة. فعّلها من الإعدادات.');
    }
}
