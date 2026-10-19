<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('teacher_pay.view');

$manage = can('teacher_pay.manage');
if (is_post()) {
    require_perm('teacher_pay.manage');
    try {
        $act = post('action');
        if ($act === 'accrue') {
            $r = accrue_teacher_compensations(post('ym'), uid());
            flash('success', "تم احتساب {$r['created']} مستحقاً جديداً (موجود مسبقاً: {$r['skipped']}). المستحق ليس مصروفاً حتى يُصرف فعلياً.");
        } elseif ($act === 'adjust') {
            $tid = post_int('teacher_id');
            $amt = parse_money(post('amount'));
            if (!row('SELECT id FROM teachers WHERE id = ?', [$tid]) || $amt === null || $amt === 0 || trim(post('description')) === '') {
                throw new FinanceException('بيانات التسوية غير مكتملة.');
            }
            $amt = post('sign') === 'deduct' ? -abs($amt) : abs($amt);
            $bal = teacher_balance($tid)['balance'];
            if ($bal + $amt < 0) {
                throw new FinanceException('الخصم يتجاوز المتبقي المستحق للمدرس.');
            }
            $id = insert('teacher_compensations', ['teacher_id' => $tid, 'period_ym' => date('Y-m'), 'kind' => 'adjustment', 'description' => mb_substr(post('description'), 0, 255), 'amount' => cents_str($amt), 'created_by' => uid()]);
            audit('teacher_comp_adjustment', 'teacher', $tid, ['amount' => cents_str($amt), 'id' => $id]);
            flash('success', 'تم تسجيل التسوية.');
        } elseif ($act === 'payout') {
            $amt = parse_money(post('amount'));
            record_teacher_payout(post_int('teacher_id'), (int)$amt, post('method'), post('reference'), post('paid_date') ?: today(), post('notes'), uid());
            flash('success', 'تم صرف المبلغ وتسجيله مرة واحدة كمصروف.');
        } elseif ($act === 'void_payout') {
            void_teacher_payout(post_int('id'), post('reason'), uid());
            flash('success', 'تم إلغاء الصرف وما يقابله من مصروف.');
        }
    } catch (FinanceException $e) {
        flash('error', $e->getMessage());
    }
    redirect('modules/finance/teacher_pay.php' . (post_int('teacher_id') ? '?teacher_id=' . post_int('teacher_id') : ''));
}
$tid = get_int('teacher_id');
$teachers = rows("SELECT t.* FROM teachers t ORDER BY t.status, t.full_name");
$summary = [];
foreach ($teachers as $t) {
    $summary[$t['id']] = teacher_balance((int)$t['id']);
}
$sel = $tid ? row('SELECT * FROM teachers WHERE id = ?', [$tid]) : null;
render_header('مستحقات المدرسين');
?>
<?php if ($manage): ?><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-calculator"></i> احتساب مستحقات شهر</div><form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="accrue"><div class="col-md-3"><input class="form-control" type="month" name="ym" value="<?= e(date('Y-m')) ?>" max="<?= e(date('Y-m')) ?>" required></div><div class="col-md-3"><button class="btn btn-primary">احتساب</button></div><div class="col-12 form-text">الراتب الثابت يُحتسب مرة لكل شهر، وحصة المدرس تُحتسب لكل حصة «عُقدت» مرة واحدة فقط — آمن لإعادة التشغيل.</div></form></div><?php endif; ?>
<div class="table-responsive mb-3"><table class="table ecm-table table-hover"><thead><tr><th>المدرس</th><th>نظام الأجر</th><th>المحتسب</th><th>المصروف</th><th>المتبقي</th><th></th></tr></thead><tbody>
<?php foreach ($teachers as $t): $b = $summary[$t['id']]; ?><tr class="<?= (int)$t['id'] === $tid ? 'table-warning' : '' ?>"><td><?= e($t['full_name']) ?> <?= $t['status'] === 'inactive' ? badge('غير نشط', 'secondary') : '' ?></td><td><?= e(comp_type_labels()[$t['comp_type']]) ?><?= $t['comp_type'] !== 'none' ? ' · ' . e(money($t['comp_amount'])) : '' ?></td><td class="money"><?= e(money_c($b['accrued'])) ?></td><td class="money"><?= e(money_c($b['paid'])) ?></td><td class="money fw-bold"><?= e(money_c($b['balance'])) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?teacher_id=<?= (int)$t['id'] ?>">تفاصيل</a></td></tr><?php endforeach; ?>
<?php if (!$teachers) echo '<tr><td colspan="6">' . empty_state('لا يوجد مدرسون', '', '', 'person-workspace') . '</td></tr>'; ?></tbody></table></div>
<?php if ($sel): $b = $summary[$sel['id']];
    $comps = rows("SELECT * FROM teacher_compensations WHERE teacher_id = ? AND status = 'valid' ORDER BY id DESC LIMIT 50", [$tid]);
    $pays = rows('SELECT * FROM teacher_payouts WHERE teacher_id = ? ORDER BY id DESC LIMIT 50', [$tid]); ?>
<div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-person-workspace"></i> <?= e($sel['full_name']) ?> — المتبقي: <span class="money"><?= e(money_c($b['balance'])) ?></span></div>
<div class="row g-3"><div class="col-lg-6"><h6>المستحقات</h6><?php if (!$comps): echo empty_state('لا مستحقات'); else: ?><table class="table ecm-table"><tbody><?php foreach ($comps as $c): ?><tr><td><?= e($c['description']) ?></td><td class="money <?= to_cents($c['amount']) < 0 ? 'text-danger' : '' ?>"><?= e(money($c['amount'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<?php if ($manage): ?><form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="teacher_id" value="<?= $tid ?>"><div class="col-4"><select class="form-select form-select-sm" name="sign"><option value="add">مكافأة / إضافة</option><option value="deduct">خصم</option></select></div><div class="col-4"><input class="form-control form-control-sm" name="amount" placeholder="المبلغ" dir="ltr" inputmode="decimal" required></div><div class="col-4"><button class="btn btn-sm btn-outline-secondary w-100">تسوية</button></div><div class="col-12"><input class="form-control form-control-sm" name="description" placeholder="السبب" required></div></form><?php endif; ?></div>
<div class="col-lg-6"><h6>المصروف للمدرس</h6><?php if (!$pays): echo empty_state('لا دفعات'); else: ?><table class="table ecm-table"><tbody><?php foreach ($pays as $p): $v = $p['status'] === 'voided'; ?><tr class="<?= $v ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= e(fdate($p['paid_date'])) ?> · <?= e(payment_method_label($p['method'])) ?></td><td class="money"><?= e(money($p['amount'])) ?></td><td class="text-end"><?php if (!$v && $manage): ?><form method="post" class="d-inline-flex gap-1" data-confirm="إلغاء هذا الصرف؟ سيُلغى المصروف المرتبط."><?= csrf_field() ?><input type="hidden" name="action" value="void_payout"><input type="hidden" name="teacher_id" value="<?= $tid ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input class="form-control form-control-sm" style="width:90px" name="reason" placeholder="السبب" required><button class="btn btn-sm btn-outline-danger">إلغاء</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<?php if ($manage): ?><form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="payout"><input type="hidden" name="teacher_id" value="<?= $tid ?>"><div class="col-4"><input class="form-control form-control-sm" name="amount" placeholder="مبلغ الصرف" dir="ltr" inputmode="decimal" required></div><div class="col-4"><select class="form-select form-select-sm" name="method"><?= opt_list(array_map(fn($m) => $m['label'], payment_methods(true))) ?></select></div><div class="col-4"><input class="form-control form-control-sm" type="date" name="paid_date" value="<?= e(today()) ?>" max="<?= e(today()) ?>"></div><div class="col-8"><input class="form-control form-control-sm" name="reference" placeholder="مرجع (اختياري)"></div><div class="col-4"><button class="btn btn-sm btn-primary w-100">صرف</button></div></form><?php endif; ?></div></div></div>
<?php endif; render_footer();
