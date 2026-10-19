<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('billing.view');

$manage = can('billing.manage');
if (is_post()) {
    require_perm('billing.manage');
    $act = post('action');
    try {
        if ($act === 'create') {
            $stu = post_int('student_id');
            $grp = post_int('group_id');
            $g = row('SELECT g.*, ps.mode, ps.monthly_fee, ps.session_price FROM class_groups g LEFT JOIN group_payment_settings ps ON ps.group_id = g.id WHERE g.id = ?', [$grp]);
            if (!$g || !$g['mode']) {
                throw new FinanceException('المجموعة غير موجودة أو بلا نظام دفع.');
            }
            if (!row("SELECT id FROM group_students WHERE student_id = ? AND group_id = ? AND status = 'active'", [$stu, $grp])) {
                throw new FinanceException('الطالب غير مسجّل في هذه المجموعة.');
            }
            if (row("SELECT id FROM subscriptions WHERE student_id = ? AND group_id = ? AND status = 'active'", [$stu, $grp])) {
                throw new FinanceException('يوجد اشتراك نشط لهذا الطالب في المجموعة. عدّله أو ألغه أولاً.');
            }
            $base = to_cents($g['mode'] === 'monthly' ? $g['monthly_fee'] : $g['session_price']);
            $price = post('unit_price') !== '' ? parse_money(post('unit_price')) : $base;
            $disc = parse_money(post('discount') ?: '0');
            $start = post('start_date') ?: today();
            $end = post('end_date') ?: null;
            if ($price === null || $price <= 0 || $disc === null || $disc < 0 || $disc >= $price) {
                throw new FinanceException('السعر أو الخصم غير صحيح (الخصم يجب أن يكون أقل من السعر).');
            }
            if (!valid_date($start) || ($end && (!valid_date($end) || $end < $start))) {
                throw new FinanceException('التواريخ غير صحيحة.');
            }
            $id = insert('subscriptions', ['student_id' => $stu, 'group_id' => $grp, 'mode' => $g['mode'], 'unit_price' => cents_str($price), 'discount_amount' => cents_str($disc),
                'start_date' => $start, 'end_date' => $end, 'status' => 'active', 'notes' => post('notes') ?: null, 'created_by' => uid()]);
            audit('subscription_created', 'subscription', $id, ['student' => $stu, 'group' => $grp, 'price' => cents_str($price), 'discount' => cents_str($disc)]);
            flash('success', 'تم إنشاء الاشتراك. لا تُنشأ أي دفعة تلقائياً؛ الاستحقاق من «توليد الاستحقاقات».');
        } elseif ($act === 'update') {
            $id = post_int('id');
            $sb = row('SELECT * FROM subscriptions WHERE id = ?', [$id]);
            if (!$sb) {
                throw new FinanceException('الاشتراك غير موجود.');
            }
            $disc = parse_money(post('discount') ?: '0');
            $end = post('end_date') ?: null;
            if ($disc === null || $disc < 0 || $disc >= to_cents($sb['unit_price']) || ($end && (!valid_date($end) || $end < $sb['start_date']))) {
                throw new FinanceException('الخصم أو التاريخ غير صحيح.');
            }
            update('subscriptions', ['discount_amount' => cents_str($disc), 'end_date' => $end, 'notes' => post('notes') ?: null], 'id = ?', [$id]);
            audit('subscription_updated', 'subscription', $id, ['discount' => cents_str($disc), 'end' => $end]);
            flash('success', 'تم التحديث (لا يؤثر على فواتير سبق توليدها).');
        } elseif ($act === 'cancel') {
            $id = post_int('id');
            if (trim(post('reason')) === '') {
                throw new FinanceException('سبب الإلغاء مطلوب.');
            }
            q("UPDATE subscriptions SET status = 'cancelled', end_date = COALESCE(end_date, CURDATE()), notes = CONCAT(COALESCE(notes,''), ' | إلغاء: ', ?) WHERE id = ? AND status = 'active'", [mb_substr(post('reason'), 0, 150), $id]);
            audit('subscription_cancelled', 'subscription', $id, ['reason' => post('reason')]);
            flash('success', 'تم إلغاء الاشتراك. الفواتير القائمة تبقى مستحقة حتى تُسدَّد أو تُلغى.');
        }
    } catch (FinanceException $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/billing/subscriptions.php');
}
$qs = get('q');
$status = get('status');
$where = '1=1';
$p = [];
[$gsSql, $gsP] = group_scope_sql('sb.group_id');
$where .= $gsSql;
$p = array_merge($p, $gsP);
if ($qs !== '') {
    $where .= ' AND (s.full_name LIKE ? OR s.student_no LIKE ?)';
    array_push($p, "%$qs%", "%$qs%");
}
if ($status === 'active') {
    $where .= " AND sb.status = 'active' AND (sb.end_date IS NULL OR sb.end_date >= CURDATE())";
} elseif ($status === 'expired') {
    $where .= " AND sb.status = 'active' AND sb.end_date < CURDATE()";
} elseif ($status === 'expiring') {
    $where .= " AND sb.status = 'active' AND sb.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . (int)setting('subscription_alert_days', 7) . ' DAY)';
} elseif ($status === 'cancelled') {
    $where .= " AND sb.status = 'cancelled'";
}
if (get_int('group_id')) {
    $where .= ' AND sb.group_id = ?';
    $p[] = get_int('group_id');
}
$pg = paginate("SELECT COUNT(*) FROM subscriptions sb JOIN students s ON s.id = sb.student_id WHERE $where", "SELECT sb.*, s.full_name, s.student_no, g.name AS gname FROM subscriptions sb JOIN students s ON s.id = sb.student_id JOIN class_groups g ON g.id = sb.group_id WHERE $where ORDER BY sb.id DESC", $p);
[$gg, $ggp] = group_scope_sql('g.id');
$groups = col_opts(rows("SELECT g.id, g.name FROM class_groups g WHERE 1=1 $gg ORDER BY g.name", $ggp), 'id', 'name');
$members = $manage ? rows("SELECT s.id, s.full_name, s.student_no, g.id AS gid, g.name AS gname FROM group_students gs JOIN students s ON s.id = gs.student_id JOIN class_groups g ON g.id = gs.group_id
   WHERE gs.status = 'active' AND s.status = 'active' AND g.status <> 'finished' AND NOT EXISTS (SELECT 1 FROM subscriptions x WHERE x.student_id = s.id AND x.group_id = g.id AND x.status = 'active') ORDER BY s.full_name LIMIT 500") : [];
render_header('الاشتراكات');
?>
<form class="filter-bar row g-2 align-items-end" method="get">
  <div class="col-md-3"><input class="form-control" name="q" value="<?= e($qs) ?>" placeholder="اسم أو رقم الطالب"></div>
  <div class="col-md-3"><select class="form-select" name="group_id"><?= opt_list($groups, get('group_id'), true, 'كل المجموعات') ?></select></div>
  <div class="col-md-3"><select class="form-select" name="status"><?= opt_list(['active' => 'نشطة', 'expiring' => 'قريبة الانتهاء', 'expired' => 'منتهية', 'cancelled' => 'ملغاة'], $status, true, 'كل الحالات') ?></select></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-search"></i></button><a class="btn btn-light" href="subscriptions.php">مسح</a></div></form>
<?php if ($manage && $members): ?><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-plus-circle"></i> اشتراك جديد لطالب مسجّل بلا اشتراك</div>
<form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="col-md-4"><select class="form-select" id="pair" required><option value="">— الطالب / المجموعة —</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>|<?= (int)$m['gid'] ?>"><?= e($m['full_name'] . ' — ' . $m['gname']) ?></option><?php endforeach; ?></select><input type="hidden" name="student_id" id="sidF"><input type="hidden" name="group_id" id="gidF"></div>
  <div class="col-6 col-md-2"><input class="form-control" name="unit_price" placeholder="السعر (افتراضي المجموعة)" inputmode="decimal" dir="ltr"></div><div class="col-6 col-md-2"><input class="form-control" name="discount" placeholder="خصم" inputmode="decimal" dir="ltr"></div>
  <div class="col-6 col-md-2"><input class="form-control" type="date" name="start_date" value="<?= e(today()) ?>" title="بداية"></div><div class="col-6 col-md-2"><input class="form-control" type="date" name="end_date" title="نهاية (اختياري)"></div>
  <div class="col-12"><button class="btn btn-primary btn-sm">إنشاء الاشتراك</button></div></form></div>
<?php inline_script('(function(){var p=document.getElementById("pair");p.addEventListener("change",function(){var a=p.value.split("|");document.getElementById("sidF").value=a[0]||"";document.getElementById("gidF").value=a[1]||"";});})();'); endif; ?>
<?php if (!$pg['rows']): echo '<div class="ecm-card">' . empty_state('لا توجد اشتراكات', 'تُنشأ عند تسجيل الطالب في مجموعة أو من النموذج أعلاه.', '', 'card-checklist') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table"><thead><tr><th>الطالب</th><th>المجموعة</th><th>النظام</th><th>السعر</th><th>الخصم</th><th>الفترة</th><th>الحالة</th><th></th></tr></thead><tbody>
<?php foreach ($pg['rows'] as $b): $exp = $b['status'] === 'active' && $b['end_date'] && $b['end_date'] < today(); ?><tr><td><a href="../students/view.php?id=<?= (int)$b['student_id'] ?>&tab=billing"><?= e($b['full_name']) ?></a><div class="small text-muted" dir="ltr"><?= e($b['student_no']) ?></div></td><td><?= e($b['gname']) ?></td><td><?= e(mode_labels()[$b['mode']]) ?></td>
<td class="money"><?= e(money($b['unit_price'])) ?></td><td class="money"><?= e(money($b['discount_amount'])) ?></td><td><?= e(fdate($b['start_date'])) ?> → <?= e(fdate($b['end_date'])) ?></td><td><?= $exp ? badge('منتهٍ', 'danger') : status_badge($b['status'], ['active' => 'نشط', 'cancelled' => 'ملغى']) ?></td>
<td class="text-end text-nowrap"><?php if ($manage && $b['status'] === 'active'): ?>
<form method="post" class="d-inline-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input class="form-control form-control-sm" style="width:80px" name="discount" value="<?= e($b['discount_amount']) ?>" title="الخصم" dir="ltr"><input class="form-control form-control-sm" style="width:135px" type="date" name="end_date" value="<?= e($b['end_date']) ?>"><button class="btn btn-sm btn-outline-secondary">حفظ</button></form>
<form method="post" class="d-inline-flex gap-1" data-confirm="إلغاء هذا الاشتراك؟"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input class="form-control form-control-sm" style="width:100px" name="reason" placeholder="السبب" required><button class="btn btn-sm btn-outline-danger">إلغاء</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?= pager($pg); endif; ?>
<?php render_footer();
