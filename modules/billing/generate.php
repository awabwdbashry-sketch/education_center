<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('billing.manage');

$result = null;
if (is_post()) {
    try {
        if (post('action') === 'monthly') {
            $result = ['kind' => 'شهرية'] + generate_monthly_invoices(post('ym'), uid());
        } elseif (post('action') === 'sessions') {
            $result = ['kind' => 'حصص'] + generate_session_invoices(post_int('group_id') ?: null, post('until'), uid()) + ['notes' => []];
        }
        if ($result) {
            flash('success', "اكتمل التوليد ({$result['kind']}): أُنشئت {$result['created']} فاتورة، وتم تخطي {$result['skipped']} (موجودة مسبقاً أو غير مستحقة).");
        }
    } catch (FinanceException $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/billing/generate.php');
}
$periods = rows('SELECT bp.*, (SELECT COUNT(*) FROM invoices i WHERE i.billing_period_id = bp.id) AS n FROM billing_periods bp ORDER BY period_ym DESC LIMIT 12');
$sessGroups = col_opts(rows("SELECT g.id, g.name FROM class_groups g JOIN group_payment_settings ps ON ps.group_id = g.id AND ps.mode = 'per_session' ORDER BY g.name"), 'id', 'name');
$pending = (int)val("SELECT COUNT(*) FROM attendance a JOIN class_sessions s ON s.id = a.session_id JOIN subscriptions sb ON sb.student_id = a.student_id AND sb.group_id = s.group_id AND sb.mode = 'per_session' AND sb.status = 'active' LEFT JOIN invoice_items ii ON ii.attendance_id = a.id WHERE ii.id IS NULL AND a.status IN ('present','late') AND s.status <> 'cancelled' AND s.session_date >= sb.start_date");
render_header('توليد الاستحقاقات');
?>
<div class="row g-3">
<div class="col-lg-6"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-calendar-month"></i> استحقاقات الاشتراكات الشهرية</div>
  <p class="text-muted small">تُنشأ فاتورة لكل اشتراك شهري نشط ولطالب مسجّل في المجموعة، مرة واحدة لكل شهر. إعادة التشغيل آمنة ولا تكرر شيئاً.</p>
  <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="monthly"><div class="col-8"><input class="form-control" type="month" name="ym" value="<?= e(date('Y-m')) ?>" max="<?= e(date('Y-m')) ?>" required></div><div class="col-4"><button class="btn btn-primary w-100">توليد</button></div></form>
  <?php if ($periods): ?><table class="table ecm-table mt-3"><thead><tr><th>الشهر</th><th>الفواتير</th><th>وقت التوليد</th></tr></thead><tbody><?php foreach ($periods as $pr): ?><tr><td><?= e($pr['period_ym']) ?></td><td><?= (int)$pr['n'] ?></td><td><?= e(fdatetime($pr['generated_at'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></div>
<div class="col-lg-6"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-calendar2-check"></i> تسوية الدفع حسب الحصص</div>
  <p class="text-muted small">تُفوتر الحصص التي حضرها الطالب (حاضر/متأخر بحسب الإعداد) ولم تُفوتر من قبل. كل حصة تُحتسب مرة واحدة فقط. حصص بانتظار الفوترة الآن: <strong><?= $pending ?></strong></p>
  <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="sessions"><div class="col-12"><select class="form-select" name="group_id"><?= opt_list($sessGroups, '', true, 'كل مجموعات الدفع بالحصة') ?></select></div>
  <div class="col-8"><input class="form-control" type="date" name="until" value="<?= e(today()) ?>" max="<?= e(today()) ?>" required></div><div class="col-4"><button class="btn btn-primary w-100">تسوية</button></div></form></div></div>
</div>
<?php render_footer();
