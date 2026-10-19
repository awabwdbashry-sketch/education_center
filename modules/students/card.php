<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_perm('students.view');

$ids = array_filter(array_map('intval', explode(',', get('ids') ?: (string)get_int('id'))));
if (!$ids) {
    abort(400, 'لم يتم تحديد طالب.');
}
$cards = [];
foreach (array_slice($ids, 0, 60) as $sid) {
    $s = row('SELECT s.*, l.name AS lname FROM students s LEFT JOIN academic_levels l ON l.id = s.level_id WHERE s.id = ?', [$sid]);
    if ($s) {
        require_student_access((int)$sid);
        $cards[] = $s;
    }
}
audit('student_card_printed', 'student', $cards ? (int)$cards[0]['id'] : null, ['count' => count($cards)]);
render_header('بطاقات الطلاب', ['print' => true, 'qr' => true]);
?>
<div class="no-print mb-3 d-flex gap-2"><button class="btn btn-primary" data-print><i class="bi bi-printer"></i> طباعة</button><a class="btn btn-light" href="javascript:history.back()">رجوع</a></div>
<?php foreach ($cards as $s): ?>
<div class="student-card"><div class="band"><?= e(setting('center_name', '')) ?></div>
  <div class="d-flex gap-2 align-items-center"><div style="flex:1"><div class="fw-bold fs-6"><?= e($s['full_name']) ?></div><div class="small text-muted"><?= e($s['lname']) ?></div><div class="mt-1" dir="ltr"><b><?= e($s['student_no']) ?></b></div><div class="small text-muted mt-1"><?= e(setting('center_phone', '')) ?></div></div>
  <div style="width:120px" <?= $s['qr_status'] === 'active' ? 'data-qr="' . e(qr_payload($s['qr_token'])) . '" data-qr-size="4"' : '' ?>><?= $s['qr_status'] !== 'active' ? '<div class="small text-danger text-center">QR موقوف</div>' : '' ?></div></div>
  <div class="small text-muted text-center mt-2" style="font-size:.7rem">البطاقة للتعريف بالطالب فقط — يُنصح بالتحقق من هوية حاملها عند المسح</div></div>
<?php endforeach; render_footer();
