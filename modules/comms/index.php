<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('comms.view');

$sid = get_int('student_id') ?: post_int('student_id');
if (is_post() && $sid) {
    require_student_access($sid);
    if (post('action') === 'log') {
        $msg = mb_substr(post('message'), 0, 2000);
        if ($msg !== '') {
            insert('communication_notes', ['student_id' => $sid, 'guardian_id' => post_int('guardian_id') ?: null, 'channel' => in_array(post('channel'), ['whatsapp', 'phone', 'in_person', 'other'], true) ? post('channel') : 'other', 'template_key' => post('template') ?: null, 'message' => $msg, 'created_by' => uid()]);
            audit('comm_logged', 'student', $sid, ['channel' => post('channel')]);
            flash('success', 'تم تسجيل ملاحظة التواصل.');
        }
    }
    redirect('modules/comms/index.php?student_id=' . $sid . (get('tpl') ? '&tpl=' . urlencode(get('tpl')) : ''));
}
$qs = get('q');
$found = [];
if (!$sid && $qs !== '') {
    [$scSql, $scP] = student_scope_sql('s');
    $found = rows("SELECT s.id, s.full_name, s.student_no, s.phone FROM students s WHERE (s.full_name LIKE ? OR s.student_no LIKE ? OR s.phone LIKE ?) $scSql ORDER BY s.full_name LIMIT 30", array_merge(["%$qs%", "%$qs%", "%$qs%"], $scP));
}
$student = $sid ? row('SELECT * FROM students WHERE id = ?', [$sid]) : null;
if ($sid && !$student) {
    abort(404, 'الطالب غير موجود');
}
if ($student) {
    require_student_access($sid);
    $guardians = rows('SELECT g.* FROM guardians g JOIN student_guardians sg ON sg.guardian_id = g.id WHERE sg.student_id = ? ORDER BY sg.is_primary DESC', [$sid]);
    $groups = rows("SELECT g.id, g.name FROM group_students gs JOIN class_groups g ON g.id = gs.group_id WHERE gs.student_id = ? AND gs.status = 'active'", [$sid]);
    $bal = can('billing.view') ? student_balance($sid) : ['due' => 0];
    $lastAbs = row("SELECT se.session_date, g.name FROM attendance a JOIN class_sessions se ON se.id = a.session_id JOIN class_groups g ON g.id = se.group_id WHERE a.student_id = ? AND a.status = 'absent' ORDER BY se.session_date DESC LIMIT 1", [$sid]);
    $tpls = ['subscription' => ['تذكير بالاشتراك', setting('msg_tpl_subscription')], 'dues' => ['تذكير بالمتأخرات', setting('msg_tpl_dues')], 'absence' => ['إشعار غياب', setting('msg_tpl_absence')]];
    $tplKey = isset($tpls[get('tpl')]) ? get('tpl') : 'dues';
    $text = fill_template((string)$tpls[$tplKey][1], ['guardian' => $guardians[0]['full_name'] ?? 'ولي الأمر', 'student' => $student['full_name'], 'group' => $groups ? implode('، ', array_column($groups, 'name')) : ($lastAbs['name'] ?? ''), 'amount' => money_c($bal['due']), 'date' => fdate($lastAbs['session_date'] ?? today()), 'center' => setting('center_name', '')]);
    $text = $_POST['message'] ?? $text;
    $log = rows('SELECT c.*, u.full_name AS uname FROM communication_notes c LEFT JOIN users u ON u.id = c.created_by WHERE c.student_id = ? ORDER BY c.id DESC LIMIT 20', [$sid]);
}
render_header('التواصل مع أولياء الأمور');
?>
<form class="filter-bar row g-2" method="get"><div class="col-md-8"><input class="form-control" name="q" value="<?= e($qs) ?>" placeholder="ابحث عن طالب لمراسلة ولي أمره"></div><div class="col-md-4"><button class="btn btn-primary"><i class="bi bi-search"></i> بحث</button></div></form>
<?php if ($found): ?><div class="list-group mb-3"><?php foreach ($found as $f): ?><a class="list-group-item list-group-item-action" href="?student_id=<?= (int)$f['id'] ?>"><?= e($f['full_name']) ?> <small class="text-muted" dir="ltr"><?= e($f['student_no']) ?></small></a><?php endforeach; ?></div><?php elseif ($qs !== '' && !$student): echo '<div class="ecm-card">' . empty_state('لا نتائج', '', '', 'search') . '</div>'; endif; ?>
<?php if ($student): ?>
<div class="row g-3"><div class="col-lg-7"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-whatsapp"></i> رسالة إلى ولي أمر <?= e($student['full_name']) ?></div>
  <div class="alert alert-warning small">النظام <b>لا يرسل</b> أي رسالة تلقائياً. راجع النص ثم اضغط «فتح واتساب» وسيُفتح التطبيق بالنص جاهزاً لتضغط الإرسال بنفسك.</div>
  <div class="mb-2 d-flex gap-2 flex-wrap"><?php foreach ($tpls as $k => [$lbl]): ?><a class="btn btn-sm <?= $k === $tplKey ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?student_id=<?= $sid ?>&tpl=<?= e($k) ?>"><?= e($lbl) ?></a><?php endforeach; ?></div>
  <?php if (!$guardians): echo empty_state('لا يوجد ولي أمر مسجل', 'أضف بيانات ولي الأمر من ملف الطالب.', '', 'person-x'); else: ?>
  <label class="form-label">نص الرسالة (قابل للتعديل)</label><textarea class="form-control mb-2" id="msg" rows="5"><?= e($text) ?></textarea>
  <?php foreach ($guardians as $g): $n = wa_number($g['phone']); ?><div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2"><div><?= e($g['full_name']) ?> <small class="text-muted" dir="ltr"><?= e($g['phone']) ?></small></div>
    <?php if ($n): ?><a class="btn btn-success btn-sm wa-open" data-phone="<?= e($n) ?>" data-gid="<?= (int)$g['id'] ?>" target="_blank" rel="noopener" href="https://wa.me/<?= e($n) ?>"><i class="bi bi-whatsapp"></i> فتح واتساب</a><?php else: ?><span class="badge text-bg-secondary">رقم غير صالح للواتساب</span><?php endif; ?></div><?php endforeach; ?>
  <form method="post" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="log"><input type="hidden" name="student_id" value="<?= $sid ?>"><input type="hidden" name="template" value="<?= e($tplKey) ?>"><input type="hidden" name="channel" value="whatsapp"><input type="hidden" name="guardian_id" value="<?= (int)($guardians[0]['id'] ?? 0) ?>"><input type="hidden" name="message" id="msgCopy"><div class="col-12"><button class="btn btn-outline-primary btn-sm" id="logBtn">تسجيل أن الرسالة أُرسلت يدوياً</button></div></form>
  <?php inline_script('(function(){var t=document.getElementById("msg"),c=document.getElementById("msgCopy");function sync(){c.value=t.value;document.querySelectorAll(".wa-open").forEach(function(a){a.href="https://wa.me/"+a.dataset.phone+"?text="+encodeURIComponent(t.value);});}t.addEventListener("input",sync);sync();})();'); endif; ?></div></div>
<div class="col-lg-5"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-clock-history"></i> سجل التواصل</div><?php if (!$log): echo empty_state('لا توجد ملاحظات'); else: foreach ($log as $l): ?><div class="border-bottom py-2"><div class="small text-muted"><?= e(fdatetime($l['created_at'])) ?> · <?= e($l['uname']) ?></div><div><?= nl2br(e(mb_substr($l['message'], 0, 300))) ?></div></div><?php endforeach; endif; ?></div></div></div>
<?php endif; render_footer();
