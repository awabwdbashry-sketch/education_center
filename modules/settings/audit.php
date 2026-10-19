<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_perm('audit.view');

$where = '1=1';
$p = [];
foreach (['action' => 'a.action', 'username' => 'a.username', 'entity_type' => 'a.entity_type'] as $k => $col) {
    if (get($k) !== '') {
        $where .= " AND $col = ?";
        $p[] = get($k);
    }
}
if (valid_date(get('from'))) {
    $where .= ' AND a.created_at >= ?';
    $p[] = get('from') . ' 00:00:00';
}
if (valid_date(get('to'))) {
    $where .= ' AND a.created_at <= ?';
    $p[] = get('to') . ' 23:59:59';
}
$pg = paginate("SELECT COUNT(*) FROM audit_logs a WHERE $where", "SELECT a.* FROM audit_logs a WHERE $where ORDER BY a.id DESC", $p, 40);
$actions = col_opts(rows('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action', 'action');
render_header('سجل العمليات');
?>
<form class="filter-bar row g-2 align-items-end" method="get"><div class="col-md-3"><select class="form-select" name="action"><?= opt_list($actions, get('action'), true, 'كل العمليات') ?></select></div><div class="col-md-2"><input class="form-control" name="username" value="<?= e(get('username')) ?>" placeholder="المستخدم" dir="ltr"></div>
<div class="col-6 col-md-2"><input class="form-control" type="date" name="from" value="<?= e(get('from')) ?>"></div><div class="col-6 col-md-2"><input class="form-control" type="date" name="to" value="<?= e(get('to')) ?>"></div><div class="col-md-3"><button class="btn btn-primary"><i class="bi bi-funnel"></i></button> <a class="btn btn-light" href="audit.php">مسح</a></div></form>
<?php if (!$pg['rows']): echo '<div class="ecm-card">' . empty_state('لا توجد سجلات', '', '', 'journal-text') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table table-sm"><thead><tr><th>الوقت</th><th>المستخدم</th><th>العملية</th><th>الكيان</th><th>التفاصيل</th><th>IP</th></tr></thead><tbody>
<?php foreach ($pg['rows'] as $l): ?><tr><td class="text-nowrap"><?= e(fdatetime($l['created_at'])) ?></td><td dir="ltr"><?= e($l['username']) ?></td><td><?= e($l['action']) ?></td><td><?= e($l['entity_type']) ?> <?= $l['entity_id'] ? '#' . (int)$l['entity_id'] : '' ?></td><td class="small text-muted" style="max-width:420px;word-break:break-word"><?= e(mb_substr((string)$l['details'], 0, 300)) ?></td><td dir="ltr"><?= e($l['ip']) ?></td></tr><?php endforeach; ?></tbody></table></div><?= pager($pg); endif; ?>
<?php render_footer();
