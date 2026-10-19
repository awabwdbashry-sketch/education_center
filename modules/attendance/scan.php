<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_perm('attendance.mark');

[$gsSql, $gsP] = group_scope_sql('s.group_id');
$today = rows("SELECT s.id, s.start_time, s.end_time, g.name FROM class_sessions s JOIN class_groups g ON g.id = s.group_id WHERE s.session_date = CURDATE() AND s.status <> 'cancelled' $gsSql ORDER BY s.start_time", $gsP);
$sid = get_int('session_id');
if (!in_array($sid, array_map('intval', array_column($today, 'id')), true)) {
    $sid = $today ? (int)$today[0]['id'] : 0;
}
render_header('مسح QR وتسجيل الحضور', ['scanner' => true]);
if (!$today): echo '<div class="ecm-card">' . empty_state('لا توجد حصص اليوم', 'مسح QR متاح لحصص اليوم فقط. أنشئ حصة اليوم أولاً.', can('sessions.manage') ? '<a class="btn btn-primary btn-sm" href="' . e(url('modules/sessions/calendar.php')) . '">الحصص والتقويم</a>' : '', 'qr-code-scan') . '</div>'; else: ?>
<div class="row g-3">
<div class="col-lg-6"><div class="ecm-card">
  <label class="form-label">الحصة النشطة</label>
  <select class="form-select mb-3" id="sessionSel"><?php foreach ($today as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $sid ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e(ftime($s['start_time'])) ?>–<?= e(ftime($s['end_time'])) ?>)</option><?php endforeach; ?></select>
  <div class="scan-box mb-2" id="scanBox"><video id="video" playsinline muted></video><div class="frame"></div></div>
  <div class="d-flex gap-2 mb-2"><button class="btn btn-primary" id="startBtn"><i class="bi bi-camera-video"></i> تشغيل الكاميرا</button><button class="btn btn-light" id="stopBtn" disabled>إيقاف</button></div>
  <div class="alert alert-warning d-none" id="camErr"></div>
  <div class="small text-muted">الكاميرا تعمل عبر <b>localhost</b> أو <b>HTTPS</b> فقط. إن لم تتوفر استخدم الإدخال اليدوي أدناه.</div>
  <hr><form id="manualForm" class="row g-2"><div class="col-8"><input class="form-control" id="manualInput" placeholder="رقم الطالب (مثل ST-000001) أو محتوى QR" dir="ltr" autocomplete="off"></div><div class="col-4"><button class="btn btn-outline-primary w-100" type="submit">تسجيل</button></div></form>
  <div class="form-text">مسح QR وحده لا يثبت هوية حامل البطاقة — تحقق بصرياً عند الحاجة.</div>
</div></div>
<div class="col-lg-6"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-check2-circle"></i> نتيجة آخر عملية</div><div class="scan-result" id="result"><span class="text-muted">بانتظار المسح…</span></div>
  <div class="ecm-card-title mt-3"><i class="bi bi-clock-history"></i> سجل هذه الجلسة</div><ul class="list-group list-group-flush" id="log"></ul></div></div></div>
<canvas id="cv" class="d-none"></canvas>
<div id="scanCfg" data-url="<?= e(url('modules/attendance/scan_api.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" hidden></div>
<?php echo '<script src="' . e(asset('js/scan.js')) . '"></script>'; endif; render_footer();
