<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_perm('reports.view', 'reports.finance');

$items = [
    ['قائمة الطلاب', 'students.php', 'people', 'reports.view', 'الطلاب مع المجموعات والحالة وولي الأمر'],
    ['سجل الحضور والغياب', 'attendance.php?mode=log', 'person-check', 'reports.view', 'سجل تفصيلي حسب التاريخ والمجموعة والطالب والمدرس'],
    ['نسب الحضور', 'attendance.php?mode=rates', 'graph-up', 'reports.view', 'نسبة الحضور لكل مجموعة / طالب / مدرس'],
    ['الاشتراكات النشطة والمنتهية', 'subscriptions.php', 'card-checklist', 'reports.finance', 'حالة اشتراكات الطلاب'],
    ['المتأخرات والمستحقات', 'arrears.php', 'hourglass-split', 'reports.finance', 'أرصدة الطلاب المستحقة وكشوف الحساب'],
    ['التحصيل اليومي والشهري', 'collection.php', 'cash-coin', 'reports.finance', 'الدفعات الفعلية وملخص وسائل الدفع'],
    ['المصروفات', '../finance/expenses.php', 'wallet2', 'reports.finance', 'المصروفات الفعلية حسب الفترة والتصنيف'],
    ['الملخص المالي', 'finance.php', 'bar-chart-line', 'reports.finance', 'التحصيل، المصروف، صافي التدفق النقدي، مقارنة شهرية'],
    ['مستحقات المدرسين', 'teacher_dues.php', 'cash-stack', 'reports.finance', 'المحتسب والمصروف والمتبقي لكل مدرس'],
    ['جدول الحصص', 'schedule.php', 'calendar3', 'reports.view', 'الحصص حسب الفترة والمجموعة والمدرس'],
    ['نتائج الاختبارات', 'exams.php', 'clipboard-check', 'exams.view', 'نتائج الطلاب ومقارنة المجموعات (عند تفعيل الوحدة)'],
];
render_header('مركز التقارير');
?>
<div class="row g-3"><?php foreach ($items as [$t, $href, $ic, $perm, $desc]): if (!can($perm)) continue; if ($perm === 'exams.view' && setting('exams_enabled', '0') !== '1') continue; ?>
<div class="col-md-6 col-xl-4"><a class="text-decoration-none" href="<?= e($href) ?>"><div class="ecm-card h-100 d-flex gap-3 align-items-start"><div class="ecm-stat-icon tone-emerald" style="background:var(--emerald-l);color:var(--emerald);width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-size:1.3rem"><i class="bi bi-<?= e($ic) ?>"></i></div><div><div class="fw-bold text-dark"><?= e($t) ?></div><div class="small text-muted"><?= e($desc) ?></div></div></div></a></div><?php endforeach; ?></div>
<?php render_footer();
