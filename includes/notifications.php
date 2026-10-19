<?php
declare(strict_types=1);

/** يولّد التنبيهات من الحالة الفعلية للبيانات. dedupe_key فريد فلا يتكرر التنبيه لنفس الحدث. */
function refresh_notifications(): int
{
    $new = 0;
    $add = function (string $type, string $sev, string $title, string $body, ?string $et, ?int $eid, ?string $link, string $key) use (&$new) {
        $new += q('INSERT IGNORE INTO notifications (type, severity, title, body, entity_type, entity_id, link, dedupe_key) VALUES (?,?,?,?,?,?,?,?)',
            [$type, $sev, mb_substr($title, 0, 200), mb_substr($body, 0, 500), $et, $eid, $link, mb_substr($key, 0, 120)])->rowCount();
    };
    $alertDays = (int)setting('subscription_alert_days', 7);

    // فواتير متأخرة
    foreach (rows("SELECT i.id, i.invoice_no, i.due_date, i.total - i.paid_amount AS rest, s.id AS sid, s.full_name FROM invoices i JOIN students s ON s.id = i.student_id
        WHERE i.status IN ('open','partial') AND i.due_date < CURDATE() ORDER BY i.due_date LIMIT 500") as $r) {
        $add('overdue', 'danger', 'استحقاق متأخر: ' . $r['full_name'], 'الفاتورة ' . $r['invoice_no'] . ' متأخرة منذ ' . fdate($r['due_date']) . '، المتبقي ' . money($r['rest']), 'invoice', (int)$r['id'], 'modules/billing/invoice.php?id=' . $r['id'], 'overdue:inv:' . $r['id']);
    }
    // تنتهي الاشتراكات قريباً / انتهت
    foreach (rows("SELECT sb.id, sb.end_date, s.full_name, g.name AS gname FROM subscriptions sb JOIN students s ON s.id = sb.student_id JOIN class_groups g ON g.id = sb.group_id
        WHERE sb.status = 'active' AND sb.end_date IS NOT NULL AND sb.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)", [$alertDays]) as $r) {
        $add('expiring', 'warning', 'اشتراك قريب الانتهاء: ' . $r['full_name'], $r['gname'] . ' — ينتهي في ' . fdate($r['end_date']), 'subscription', (int)$r['id'], 'modules/billing/subscriptions.php?q=' . urlencode($r['full_name']), 'expiring:sub:' . $r['id'] . ':' . $r['end_date']);
    }
    foreach (rows("SELECT sb.id, sb.end_date, s.full_name, g.name AS gname FROM subscriptions sb JOIN students s ON s.id = sb.student_id JOIN class_groups g ON g.id = sb.group_id
        WHERE sb.status = 'active' AND sb.end_date IS NOT NULL AND sb.end_date < CURDATE() AND sb.end_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)") as $r) {
        $add('expired', 'danger', 'اشتراك منتهٍ: ' . $r['full_name'], $r['gname'] . ' — انتهى في ' . fdate($r['end_date']), 'subscription', (int)$r['id'], 'modules/billing/subscriptions.php?q=' . urlencode($r['full_name']), 'expired:sub:' . $r['id'] . ':' . $r['end_date']);
    }
    // طلاب لديهم مستحقات مفتوحة (تنبيه شهري واحد لكل طالب)
    foreach (rows("SELECT s.id, s.full_name, SUM(i.total - i.paid_amount) AS rest FROM invoices i JOIN students s ON s.id = i.student_id WHERE i.status IN ('open','partial') GROUP BY s.id, s.full_name HAVING rest > 0 LIMIT 500") as $r) {
        $add('open_dues', 'info', 'مستحقات مفتوحة: ' . $r['full_name'], 'إجمالي المتبقي ' . money($r['rest']), 'student', (int)$r['id'], 'modules/cashier/collect.php?student_id=' . $r['id'], 'dues:student:' . $r['id'] . ':' . date('Y-m'));
    }
    // غياب متكرر خلال 30 يوماً
    $th = max(2, (int)setting('absence_alert_count', 3));
    foreach (rows("SELECT a.student_id, s.full_name, se.group_id, g.name AS gname, COUNT(*) AS c FROM attendance a JOIN class_sessions se ON se.id = a.session_id JOIN students s ON s.id = a.student_id JOIN class_groups g ON g.id = se.group_id
        WHERE a.status = 'absent' AND se.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY a.student_id, se.group_id, s.full_name, g.name HAVING c >= ?", [$th]) as $r) {
        $add('absence', 'warning', 'غياب متكرر: ' . $r['full_name'], 'غاب ' . $r['c'] . ' حصص من ' . $r['gname'] . ' خلال 30 يوماً', 'student', (int)$r['student_id'], 'modules/students/view.php?id=' . $r['student_id'], 'absence:' . $r['student_id'] . ':' . $r['group_id'] . ':' . date('Y-m'));
    }
    // حصص الغد
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $cnt = (int)val("SELECT COUNT(*) FROM class_sessions WHERE session_date = ? AND status <> 'cancelled'", [$tomorrow]);
    if ($cnt > 0) {
        $add('upcoming', 'info', 'حصص الغد: ' . $cnt, 'لديك ' . $cnt . ' حصة مجدولة بتاريخ ' . fdate($tomorrow), null, null, 'modules/sessions/calendar.php?from=' . $tomorrow . '&to=' . $tomorrow, 'upcoming:' . $tomorrow);
    }
    // مهام إدارية
    $ym = date('Y-m');
    if ((int)val("SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND mode = 'monthly'") > 0 && !val('SELECT id FROM billing_periods WHERE period_ym = ?', [$ym])) {
        $add('task', 'info', 'لم تُولَّد استحقاقات شهر ' . $ym, 'توجد اشتراكات شهرية نشطة ولم يتم توليد استحقاقات هذا الشهر.', null, null, 'modules/billing/generate.php', 'task:billing:' . $ym);
    }
    if ((int)val("SELECT COUNT(*) FROM teachers WHERE status = 'active' AND comp_type <> 'none'") > 0 && !val("SELECT id FROM teacher_compensations WHERE period_ym = ? LIMIT 1", [$ym])) {
        $add('task', 'info', 'لم تُحتسب مستحقات المدرسين لشهر ' . $ym, 'يوجد مدرسون لهم نظام أجر ولم تُحتسب مستحقاتهم.', null, null, 'modules/finance/teacher_pay.php', 'task:comp:' . $ym);
    }
    // النسخ الاحتياطي
    $last = row("SELECT id, status, created_at, message FROM backup_logs WHERE kind = 'backup' ORDER BY id DESC LIMIT 1");
    if ($last && $last['status'] === 'failed') {
        $add('backup', 'danger', 'فشل النسخ الاحتياطي الأخير', (string)$last['message'], 'backup_log', (int)$last['id'], 'modules/settings/backup.php', 'backup:fail:' . $last['id']);
    }
    $lastOk = val("SELECT MAX(created_at) FROM backup_logs WHERE kind = 'backup' AND status = 'success'");
    $days = (int)setting('backup_alert_days', 7);
    if ($lastOk) {
        if (strtotime((string)$lastOk) < strtotime("-$days days")) {
            $add('backup', 'warning', 'نسخة احتياطية قديمة', 'آخر نسخة ناجحة بتاريخ ' . fdatetime((string)$lastOk), null, null, 'modules/settings/backup.php', 'backup:stale:' . substr((string)$lastOk, 0, 10));
        }
    } elseif ((int)val('SELECT COUNT(*) FROM students') > 0) {
        $add('backup', 'warning', 'لا توجد نسخة احتياطية', 'لم يتم أخذ أي نسخة احتياطية بعد.', null, null, 'modules/settings/backup.php', 'backup:none');
    }

    // إغلاق التنبيهات التي زال سببها
    q("UPDATE notifications n JOIN invoices i ON n.entity_id = i.id SET n.resolved_at = NOW() WHERE n.type = 'overdue' AND n.resolved_at IS NULL AND (i.status NOT IN ('open','partial') OR i.due_date >= CURDATE())");
    q("UPDATE notifications n JOIN subscriptions sb ON n.entity_id = sb.id SET n.resolved_at = NOW() WHERE n.type IN ('expiring','expired') AND n.resolved_at IS NULL AND sb.status = 'cancelled'");
    q("UPDATE notifications n SET n.resolved_at = NOW() WHERE n.type = 'open_dues' AND n.resolved_at IS NULL AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.student_id = n.entity_id AND i.status IN ('open','partial'))");
    q("UPDATE notifications SET resolved_at = NOW() WHERE type = 'upcoming' AND resolved_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    return $new;
}
