<?php
declare(strict_types=1);
define('ECM_JSON', true);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/attendance.php';
$u = require_perm('attendance.mark');
if (!is_post()) {
    abort(400, 'POST only');
}
$sid = post_int('session_id');
$info = session_info($sid);
if (!$info) {
    json_out(['ok' => false, 'code' => 'session_invalid', 'message' => 'الحصة غير موجودة.'], 404);
}
require_group_access((int)$info['group_id']);
$raw = (string)($_POST['payload'] ?? '');
if (strlen($raw) > 200 || $raw === '') {
    json_out(['ok' => false, 'code' => 'invalid', 'message' => 'محتوى غير صالح.']);
}
json_out(scan_attendance($sid, $raw, (int)$u['id'], post('by_number') === '1'));
