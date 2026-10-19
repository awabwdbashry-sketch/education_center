<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_perm('payments.collect');

$sid = get_int('student_id') ?: post_int('student_id');
if (is_post() && $sid) {
    require_student_access($sid);
    $act = post('action');
    try {
        if ($act === 'pay') {
            $allocs = [];
            foreach (post_arr('alloc') as $iid => $v) {
                $v = trim((string)$v);
                if ($v === '') {
                    continue;
                }
                $c = parse_money($v);
                if ($c === null || $c < 0) {
                    throw new FinanceException('مبلغ غير صالح في إحدى الفواتير.');
                }
                if ($c > 0) {
                    $allocs[(int)$iid] = $c;
                }
            }
            $adv = parse_money(post('advance') ?: '0');
            if ($adv === null || $adv < 0) {
                throw new FinanceException('قيمة الدفعة المقدمة غير صالحة.');
            }
            $r = record_payment(['student_id' => $sid, 'method' => post('method'), 'reference' => post('reference'), 'paid_date' => post('paid_date') ?: today(), 'notes' => post('notes'),
                'allocations' => $allocs, 'advance_cents' => $adv, 'idem_key' => post('idem'), 'user_id' => uid()]);
            flash($r['duplicate'] ? 'warning' : 'success', $r['duplicate'] ? 'هذه العملية سُجّلت سابقاً (لم تتكرر). الإيصال: ' . $r['receipt_no'] : 'تم تسجيل الدفعة. إيصال رقم ' . $r['receipt_no']);
            redirect('modules/cashier/receipt.php?id=' . $r['payment_id']);
        } elseif ($act === 'apply_credit') {
            $allocs = [];
            foreach (post_arr('credit') as $iid => $v) {
                $c = parse_money(trim((string)$v) ?: '0');
                if ($c === null || $c < 0) {
                    throw new FinanceException('مبلغ غير صالح.');
                }
                if ($c > 0) {
                    $allocs[(int)$iid] = $c;
                }
            }
            apply_credit($sid, $allocs, uid());
            flash('success', 'تم تطبيق الرصيد المقدم على الفواتير.');
        }
    } catch (FinanceException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        // المعاملة تُراجَع بالكامل (لا دفعة ولا إيصال جزئي). يُسجَّل السبب الفعلي داخلياً ويُعرض رمز مرجعي فقط.
        $ref = ecm_log_exception($e, 'collect:' . $act);
        flash('error', 'تعذّر حفظ العملية بسبب خطأ في قاعدة البيانات ولم تُسجَّل أي دفعة. رمز الخطأ: ' . $ref . ' — أبلغ المدير (التفاصيل في storage/logs/php_errors.log).');
    }
    redirect('modules/cashier/collect.php?student_id=' . $sid);
}
$qs = get('q');
$found = [];
if (!$sid && $qs !== '') {
    [$scSql, $scP] = student_scope_sql('s');
    $found = rows("SELECT s.id, s.student_no, s.full_name, s.phone, s.status FROM students s WHERE (s.full_name LIKE ? OR s.student_no LIKE ? OR s.phone LIKE ?) $scSql ORDER BY s.full_name LIMIT 20", array_merge(["%$qs%", "%$qs%", "%$qs%"], $scP));
    if (count($found) === 1) {
        redirect('modules/cashier/collect.php?student_id=' . $found[0]['id']);
    }
}
$student = $sid ? row('SELECT * FROM students WHERE id = ?', [$sid]) : null;
if ($sid && !$student) {
    abort(404, 'الطالب غير موجود');
}
if ($student) {
    require_student_access($sid);
    $open = rows("SELECT i.*, g.name AS gname FROM invoices i LEFT JOIN class_groups g ON g.id = i.group_id WHERE i.student_id = ? AND i.status IN ('open','partial') ORDER BY i.due_date, i.id", [$sid]);
    $bal = student_balance($sid);
}
$methods = payment_methods(true);
$pre = get_int('invoice_id');
render_header('التحصيل');
?>
<form class="filter-bar row g-2" method="get"><div class="col-md-8"><input class="form-control" name="q" value="<?= e($qs) ?>" placeholder="ابحث عن الطالب بالاسم أو الرقم أو الهاتف" autofocus></div><div class="col-md-4"><button class="btn btn-primary"><i class="bi bi-search"></i> بحث</button></div></form>
<?php if ($qs !== '' && !$student): if (!$found): echo '<div class="ecm-card">' . empty_state('لا نتائج', 'جرّب اسماً أو رقماً آخر.', '', 'search') . '</div>'; else: ?>
<div class="list-group mb-3"><?php foreach ($found as $f): ?><a class="list-group-item list-group-item-action d-flex justify-content-between" href="?student_id=<?= (int)$f['id'] ?>"><span><?= e($f['full_name']) ?> <small class="text-muted" dir="ltr"><?= e($f['student_no']) ?></small></span><span><?= e($f['phone']) ?> <?= status_badge($f['status'], student_statuses()) ?></span></a><?php endforeach; ?></div><?php endif; endif; ?>
<?php if ($student): ?>
<div class="ecm-card d-flex flex-wrap justify-content-between align-items-center gap-2"><div><h5 class="mb-0"><?= e($student['full_name']) ?> <small class="text-muted" dir="ltr"><?= e($student['student_no']) ?></small></h5><div class="small text-muted"><?= e($student['phone']) ?></div></div>
  <div class="d-flex gap-3 flex-wrap"><div>المتبقي: <strong class="money text-danger"><?= e(money_c($bal['due'])) ?></strong></div><div>متأخر: <strong class="money"><?= e(money_c($bal['overdue'])) ?></strong></div><div>رصيد مقدم: <strong class="money text-success"><?= e(money_c($bal['credit'])) ?></strong></div></div>
  <a class="btn btn-outline-secondary btn-sm" href="statement.php?student_id=<?= $sid ?>">كشف الحساب</a></div>
<?php if ($student['status'] !== 'active'): ?><div class="alert alert-warning">الطالب <?= e(student_statuses()[$student['status']]) ?> — يمكن تحصيل مستحقاته السابقة.</div><?php endif; ?>
<form method="post" class="ecm-card" id="payForm"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="student_id" value="<?= $sid ?>"><input type="hidden" name="idem" value="<?= e(bin2hex(random_bytes(20))) ?>">
  <div class="ecm-card-title"><i class="bi bi-cash-coin"></i> المستحقات المفتوحة</div>
  <?php if (!$open): echo empty_state('لا توجد مستحقات مفتوحة', 'يمكنك تسجيل دفعة مقدمة أدناه إن سمحت السياسة.', '', 'check-circle'); else: ?>
  <div class="table-responsive"><table class="table ecm-table"><thead><tr><th>الفاتورة</th><th>المجموعة</th><th>الاستحقاق</th><th>المتبقي</th><th style="width:170px">المبلغ المسدَّد</th></tr></thead><tbody>
  <?php foreach ($open as $i): $o = invoice_outstanding($i); $late = $i['due_date'] < today(); ?><tr><td><a href="../billing/invoice.php?id=<?= (int)$i['id'] ?>"><?= e($i['invoice_no']) ?></a> <?= $i['status'] === 'partial' ? badge('جزئية', 'warning') : '' ?></td><td><?= e($i['gname']) ?></td><td class="<?= $late ? 'text-danger' : '' ?>"><?= e(fdate($i['due_date'])) ?></td><td class="money"><?= e(money_c($o)) ?></td>
  <td><div class="input-group input-group-sm"><input class="form-control alloc" dir="ltr" inputmode="decimal" name="alloc[<?= (int)$i['id'] ?>]" data-max="<?= e(cents_str($o)) ?>" value="<?= $pre === (int)$i['id'] ? e(cents_str($o)) : '' ?>" placeholder="0.00"><button type="button" class="btn btn-outline-secondary fill" data-v="<?= e(cents_str($o)) ?>">كامل</button></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <div class="row g-3 mt-1">
    <?php if (setting('allow_advance_payments', '1') === '1'): ?><?= field('text', 'advance', 'دفعة مقدمة (غير مخصصة لفاتورة)', '', ['col' => 'col-md-3', 'attrs' => 'dir="ltr" inputmode="decimal" id="advance" placeholder="0.00"']) ?><?php endif; ?>
    <?= select_field('method', 'وسيلة الدفع', array_map(fn($m) => $m['label'], $methods), array_key_first($methods), ['col' => 'col-md-3', 'blank' => false, 'required' => true, 'attrs' => 'id="method"']) ?>
    <?= field('text', 'reference', 'مرجع التحويل', '', ['col' => 'col-md-3', 'attrs' => 'dir="ltr" id="reference"']) ?>
    <?= field('date', 'paid_date', 'تاريخ الدفع', today(), ['col' => 'col-md-3', 'attrs' => 'max="' . today() . '"']) ?>
    <?= field('text', 'notes', 'ملاحظات', '', ['col' => 'col-md-9']) ?>
    <div class="col-md-3 d-flex align-items-end"><div class="w-100 text-center"><div class="small text-muted">إجمالي الدفعة</div><div class="fs-4 fw-bold money" id="total">0.00</div></div></div>
    <div class="col-12"><button class="btn btn-success" type="submit" id="payBtn"><i class="bi bi-check2-circle"></i> تسجيل الدفعة وإصدار الإيصال</button></div></div>
</form>
<?php if ($bal['credit'] > 0 && $open): ?><form method="post" class="ecm-card" data-confirm="تطبيق الرصيد المقدم على الفواتير المحددة؟"><?= csrf_field() ?><input type="hidden" name="action" value="apply_credit"><input type="hidden" name="student_id" value="<?= $sid ?>">
  <div class="ecm-card-title"><i class="bi bi-wallet2"></i> تطبيق الرصيد المقدم (<?= e(money_c($bal['credit'])) ?>)</div><div class="row g-2"><?php foreach ($open as $i): ?><div class="col-md-3"><label class="form-label small mb-0"><?= e($i['invoice_no']) ?> (متبقي <?= e(money_c(invoice_outstanding($i))) ?>)</label><input class="form-control form-control-sm" dir="ltr" name="credit[<?= (int)$i['id'] ?>]" inputmode="decimal"></div><?php endforeach; ?></div><button class="btn btn-outline-primary btn-sm mt-2">تطبيق</button></form><?php endif; ?>
<?php inline_script('(function(){var f=document.getElementById("payForm"),t=document.getElementById("total"),m=document.getElementById("method"),r=document.getElementById("reference");var need=' . json_encode(array_map(fn($m) => !empty($m['requires_ref']), $methods)) . ';function calc(){var s=0;f.querySelectorAll(".alloc").forEach(function(i){var v=parseFloat((i.value||"0").replace(/,/g,""));var mx=parseFloat(i.dataset.max);if(v>mx){i.classList.add("is-invalid")}else{i.classList.remove("is-invalid")}if(v>0)s+=v;});var a=document.getElementById("advance");if(a){var v=parseFloat((a.value||"0").replace(/,/g,""));if(v>0)s+=v;}t.textContent=s.toFixed(2);}f.addEventListener("input",calc);f.querySelectorAll(".fill").forEach(function(b){b.addEventListener("click",function(){b.parentNode.querySelector("input").value=b.dataset.v;calc();});});function refreq(){r.required=!!need[m.value];}m.addEventListener("change",refreq);refreq();calc();})();'); endif; ?>
<?php render_footer();
