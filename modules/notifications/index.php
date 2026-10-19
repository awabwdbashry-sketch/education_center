<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_perm('notifications.view');

if (is_post()) {
    $act = post('action');
    if ($act === 'read') {
        q('UPDATE notifications SET is_read = 1, read_at = NOW(), read_by = ? WHERE id = ?', [uid(), post_int('id')]);
    } elseif ($act === 'read_all') {
        q('UPDATE notifications SET is_read = 1, read_at = NOW(), read_by = ? WHERE is_read = 0', [uid()]);
        flash('success', 'تم تعليم كل التنبيهات كمقروءة.');
    } elseif ($act === 'refresh') {
        $n = refresh_notifications();
        $_SESSION['notif_at'] = time();
        flash('success', $n ? "أُضيف $n تنبيه جديد." : 'لا توجد تنبيهات جديدة.');
    }
    redirect('modules/notifications/index.php' . (get('show') ? '?show=' . urlencode(get('show')) : ''));
}
$show = get('show') === 'all' ? 'all' : 'unread';
$type = get('type');
$where = $show === 'unread' ? 'is_read = 0 AND resolved_at IS NULL' : '1=1';
$p = [];
if ($type !== '') {
    $where .= ' AND type = ?';
    $p[] = $type;
}
$pg = paginate("SELECT COUNT(*) FROM notifications WHERE $where", "SELECT * FROM notifications WHERE $where ORDER BY FIELD(severity,'danger','warning','info','success'), id DESC", $p, 25);
$types = ['overdue' => 'متأخرات', 'expiring' => 'اشتراك قريب الانتهاء', 'expired' => 'اشتراك منتهٍ', 'open_dues' => 'مستحقات مفتوحة', 'absence' => 'غياب متكرر', 'upcoming' => 'حصص قادمة', 'duplicate_scan' => 'حضور مكرر', 'task' => 'مهام إدارية', 'backup' => 'نسخ احتياطي'];
render_header('التنبيهات');
?>
<form class="filter-bar row g-2 align-items-end" method="get"><div class="col-md-3"><select class="form-select" name="show"><?= opt_list(['unread' => 'غير المقروءة', 'all' => 'الكل'], $show) ?></select></div><div class="col-md-4"><select class="form-select" name="type"><?= opt_list($types, $type, true, 'كل الأنواع') ?></select></div><div class="col-md-2"><button class="btn btn-primary">عرض</button></div>
</form>
<div class="page-actions"><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> تحديث التنبيهات</button></form>
<form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn btn-outline-secondary">تعليم الكل كمقروء</button></form></div>
<?php if (!$pg['rows']): echo '<div class="ecm-card">' . empty_state('لا توجد تنبيهات', 'كل شيء على ما يرام.', '', 'bell-slash') . '</div>'; else: foreach ($pg['rows'] as $n): $cls = $n['severity']; ?>
<div class="ecm-card border-start border-4 border-<?= e($cls) ?> d-flex justify-content-between align-items-start gap-2 <?= $n['is_read'] ? 'opacity-75' : '' ?>"><div><div class="fw-semibold"><?= e($n['title']) ?> <?= $n['resolved_at'] ? badge('زال السبب', 'success') : '' ?></div><div class="small text-muted"><?= e($n['body']) ?> · <?= e(fdatetime($n['created_at'])) ?></div><?php if ($n['link']): ?><a class="small" href="<?= e(url($n['link'])) ?>">فتح</a><?php endif; ?></div>
<?php if (!$n['is_read']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-sm btn-light">مقروء</button></form><?php endif; ?></div><?php endforeach; ?><?= pager($pg); endif; ?>
<?php render_footer();
