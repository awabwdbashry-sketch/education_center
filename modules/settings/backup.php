<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/backup.php';
require_perm('backup.manage');

if (is_post()) {
    $act = post('action');
    if ($act === 'backup') {
        $r = do_backup(uid(), 'نسخة يدوية');
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'تم إنشاء النسخة: ' . $r['file'] : 'فشل النسخ: ' . $r['error']);
    } elseif ($act === 'restore') {
        if (post('confirm_text') !== 'استعادة') {
            flash('error', 'اكتب كلمة «استعادة» للتأكيد.');
        } elseif (!password_verify((string)($_POST['password'] ?? ''), (string)val('SELECT password_hash FROM users WHERE id = ?', [uid()]))) {
            flash('error', 'كلمة مرور المدير غير صحيحة.');
            audit('restore_denied', 'backup_log', post_int('id'));
        } else {
            $r = do_restore(post_int('id'), uid());
            flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'تمت الاستعادة. نسخة الأمان قبلها: ' . $r['safety'] : $r['error']);
        }
    } elseif ($act === 'delete') {
        $log = row("SELECT * FROM backup_logs WHERE id = ? AND kind = 'backup' AND filename IS NOT NULL", [post_int('id')]);
        if ($log && preg_match('/^backup_\d{8}_\d{6}_[a-f0-9]{6}\.sql$/', $log['filename'])) {
            @unlink(backup_dir() . '/' . $log['filename']);
            q("UPDATE backup_logs SET filename = NULL, message = CONCAT(COALESCE(message,''), ' (حُذف الملف)') WHERE id = ?", [$log['id']]);
            audit('backup_file_deleted', 'backup_log', (int)$log['id']);
            flash('success', 'حُذف ملف النسخة (يبقى سجلها).');
        }
    }
    redirect('modules/settings/backup.php');
}
if (get('download') !== '') {
    $log = row("SELECT * FROM backup_logs WHERE id = ? AND kind = 'backup' AND status = 'success' AND filename IS NOT NULL", [get_int('download')]);
    $f = $log ? backup_dir() . '/' . $log['filename'] : '';
    if (!$log || !is_file($f) || !preg_match('/^backup_\d{8}_\d{6}_[a-f0-9]{6}\.sql$/', $log['filename'])) {
        abort(404, 'النسخة غير موجودة');
    }
    audit('backup_downloaded', 'backup_log', (int)$log['id']);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $log['filename'] . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}
$logs = rows('SELECT b.*, u.full_name FROM backup_logs b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 40');
$last = row("SELECT * FROM backup_logs WHERE kind = 'backup' ORDER BY id DESC LIMIT 1");
$lastOk = val("SELECT MAX(created_at) FROM backup_logs WHERE kind = 'backup' AND status = 'success'");
render_header('النسخ الاحتياطي والاستعادة');
?>
<div class="row g-3 mb-3"><div class="col-md-4"><?= stat_card('آخر نسخة ناجحة', $lastOk ? e(fdatetime((string)$lastOk)) : 'لا يوجد', 'database-check', $lastOk && strtotime((string)$lastOk) > strtotime('-' . (int)setting('backup_alert_days', 7) . ' days') ? 'emerald' : 'terra') ?></div>
<div class="col-md-4"><?= stat_card('نتيجة آخر محاولة', $last ? ($last['status'] === 'success' ? 'نجحت' : 'فشلت') : '—', 'activity', $last && $last['status'] === 'success' ? 'emerald' : 'terra', $last ? fdatetime($last['created_at']) : '') ?></div>
<div class="col-md-4"><div class="ecm-card h-100 d-flex align-items-center"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn btn-primary"><i class="bi bi-database-down"></i> إنشاء نسخة احتياطية الآن</button></form></div></div></div>
<div class="alert alert-info">النسخ تُحفظ في <code>storage/backups</code> خارج المسار العام ومحمية بـ .htaccess. الاستعادة تقتصر على نسخ أنشأها النظام نفسه (يُتحقق من بصمة SHA-256)، وتُؤخذ نسخة أمان تلقائية قبلها، ولا تُقبل ملفات SQL مرفوعة. جدولة النسخ التلقائي: انظر README (Windows Task Scheduler).</div>
<?php if (!$logs): echo '<div class="ecm-card">' . empty_state('لا توجد نسخ بعد', 'أنشئ أول نسخة احتياطية.', '', 'database') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table"><thead><tr><th>الوقت</th><th>النوع</th><th>الملف</th><th>الحجم</th><th>النتيجة</th><th>بواسطة</th><th></th></tr></thead><tbody>
<?php foreach ($logs as $l): ?><tr><td><?= e(fdatetime($l['created_at'])) ?></td><td><?= $l['kind'] === 'backup' ? 'نسخ' : 'استعادة' ?></td><td class="small" dir="ltr"><?= e($l['filename']) ?><div class="text-muted"><?= e($l['message']) ?></div></td><td><?= e(number_format($l['size_bytes'] / 1024, 1)) ?> KB</td><td><?= status_badge($l['status'], ['success' => 'نجحت', 'failed' => 'فشلت']) ?></td><td><?= e($l['full_name'] ?: 'مجدول') ?></td>
<td class="text-end text-nowrap"><?php if ($l['kind'] === 'backup' && $l['status'] === 'success' && $l['filename']): ?><a class="btn btn-sm btn-outline-secondary" href="?download=<?= (int)$l['id'] ?>">تنزيل</a>
<button class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#r<?= (int)$l['id'] ?>">استعادة…</button>
<div class="collapse mt-2 text-start" id="r<?= (int)$l['id'] ?>"><form method="post" class="border border-danger rounded p-2 bg-white" data-confirm="ستُستبدل كل بيانات النظام الحالية ببيانات هذه النسخة. هل أنت متأكد تماماً؟"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
<div class="small text-danger mb-1"><b>الأثر:</b> سيُحذف كل ما سُجّل بعد <?= e(fdatetime($l['created_at'])) ?> (طلاب/حضور/دفعات). تؤخذ نسخة أمان قبل التنفيذ.</div><input class="form-control form-control-sm mb-1" name="confirm_text" placeholder="اكتب: استعادة"><input class="form-control form-control-sm mb-1" type="password" name="password" placeholder="كلمة مرورك" autocomplete="current-password"><button class="btn btn-sm btn-danger">تنفيذ الاستعادة</button></form></div><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php render_footer();
