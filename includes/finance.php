<?php
declare(strict_types=1);

/** استثناء مالي برسالة عربية آمنة للعرض للمستخدم */
class FinanceException extends RuntimeException
{
}

// =====================================================================  الفواتير
/** يعيد احتساب إجمالي الفاتورة والمدفوع والحالة من البنود والتخصيصات الفعلية (لا تعديل يدوي للرصيد). */
function invoice_recompute(int $invoiceId): void
{
    $inv = row('SELECT id, status FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
    if (!$inv || $inv['status'] === 'void') {
        return;
    }
    $total = to_cents(val('SELECT COALESCE(SUM(amount),0) FROM invoice_items WHERE invoice_id = ?', [$invoiceId]));
    $paid  = to_cents(val("SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a JOIN payments p ON p.id = a.payment_id AND p.status = 'valid' WHERE a.invoice_id = ?", [$invoiceId]));
    $status = ($paid <= 0 && $total > 0) ? 'open' : (($paid >= $total) ? 'paid' : 'partial');
    q('UPDATE invoices SET total = ?, paid_amount = ?, status = ? WHERE id = ?', [cents_str($total), cents_str($paid), $status, $invoiceId]);
}

function invoice_outstanding(array $inv): int
{
    return in_array($inv['status'], ['open', 'partial'], true) ? max(0, to_cents($inv['total']) - to_cents($inv['paid_amount'])) : 0;
}

/**
 * إنشاء فاتورة مع بنودها داخل معاملة.
 * @param array $items كل بند: [description, quantity(float), unit_cents(int), amount_cents(int), attendance_id?, type?]
 */
function create_invoice(array $d, array $items): int
{
    return tx(function () use ($d, $items) {
        $id = insert('invoices', [
            'invoice_no' => next_seq('invoice', 'INV-'),
            'student_id' => $d['student_id'],
            'subscription_id' => $d['subscription_id'] ?? null,
            'group_id' => $d['group_id'] ?? null,
            'billing_period_id' => $d['billing_period_id'] ?? null,
            'kind' => $d['kind'],
            'monthly_key' => $d['monthly_key'] ?? null,
            'issue_date' => $d['issue_date'],
            'due_date' => $d['due_date'],
            'total' => '0.00',
            'paid_amount' => '0.00',
            'status' => 'open',
            'notes' => $d['notes'] ?? null,
            'created_by' => $d['created_by'] ?? null,
        ]);
        foreach ($items as $it) {
            insert('invoice_items', [
                'invoice_id' => $id,
                'item_type' => $it['type'] ?? 'charge',
                'description' => $it['description'],
                'quantity' => $it['quantity'] ?? 1,
                'unit_price' => cents_str($it['unit_cents']),
                'amount' => cents_str($it['amount_cents']),
                'attendance_id' => $it['attendance_id'] ?? null,
                'created_by' => $d['created_by'] ?? null,
            ]);
        }
        invoice_recompute($id);
        return $id;
    });
}

/** خصم/تسوية مصرّح بها على فاتورة (delta سالب = خصم). */
function adjust_invoice(int $invoiceId, int $deltaCents, string $description, int $userId): void
{
    if ($deltaCents === 0) {
        throw new FinanceException('قيمة التسوية يجب ألا تكون صفراً.');
    }
    if (trim($description) === '') {
        throw new FinanceException('سبب التسوية مطلوب.');
    }
    tx(function () use ($invoiceId, $deltaCents, $description, $userId) {
        $inv = row('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
        if (!$inv || $inv['status'] === 'void') {
            throw new FinanceException('الفاتورة غير موجودة أو ملغاة.');
        }
        $newTotal = to_cents($inv['total']) + $deltaCents;
        if ($newTotal < 0 || $newTotal < to_cents($inv['paid_amount'])) {
            throw new FinanceException('لا يمكن أن يقل إجمالي الفاتورة عن المبلغ المدفوع فعلاً. ألغِ الدفعة أولاً أو سجّل تسوية أصغر.');
        }
        insert('invoice_items', ['invoice_id' => $invoiceId, 'item_type' => 'adjustment', 'description' => $description, 'quantity' => 1,
            'unit_price' => cents_str($deltaCents), 'amount' => cents_str($deltaCents), 'created_by' => $userId]);
        invoice_recompute($invoiceId);
        audit('invoice_adjusted', 'invoice', $invoiceId, ['delta' => cents_str($deltaCents), 'reason' => $description]);
    });
}

function void_invoice(int $invoiceId, string $reason, int $userId): void
{
    if (trim($reason) === '') {
        throw new FinanceException('سبب الإلغاء مطلوب.');
    }
    tx(function () use ($invoiceId, $reason, $userId) {
        $inv = row('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
        if (!$inv || $inv['status'] === 'void') {
            throw new FinanceException('الفاتورة غير موجودة أو ملغاة مسبقاً.');
        }
        $paid = to_cents(val("SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a JOIN payments p ON p.id = a.payment_id AND p.status = 'valid' WHERE a.invoice_id = ?", [$invoiceId]));
        if ($paid > 0) {
            throw new FinanceException('الفاتورة عليها دفعات معتمدة. ألغِ الدفعات أولاً بصلاحية الإلغاء.');
        }
        // تحرير حصص الحضور المرتبطة لتتاح للفوترة مجدداً
        q('UPDATE invoice_items SET attendance_id = NULL WHERE invoice_id = ?', [$invoiceId]);
        q("UPDATE invoices SET status = 'void', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?", [$reason, $userId, $invoiceId]);
        audit('invoice_voided', 'invoice', $invoiceId, ['reason' => $reason, 'invoice_no' => $inv['invoice_no']]);
    });
}

// =====================================================================  توليد الاستحقاقات
/** @return array{created:int,skipped:int,notes:string[]} توليد استحقاقات الاشتراكات الشهرية لشهر معيّن — آمن لإعادة التشغيل */
function generate_monthly_invoices(string $ym, int $userId): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
        throw new FinanceException('صيغة الشهر غير صحيحة.');
    }
    if ($ym > date('Y-m')) {
        throw new FinanceException('لا يمكن توليد استحقاقات لشهر مستقبلي.');
    }
    $start = month_start($ym);
    $end = month_end($ym);
    q('INSERT IGNORE INTO billing_periods (period_ym, period_start, period_end, generated_by) VALUES (?,?,?,?)', [$ym, $start, $end, $userId]);
    $periodId = (int)val('SELECT id FROM billing_periods WHERE period_ym = ?', [$ym]);
    $subs = rows("SELECT sb.*, g.name AS group_name, COALESCE(ps.due_days, ?) AS due_days
        FROM subscriptions sb
        JOIN class_groups g ON g.id = sb.group_id
        JOIN students st ON st.id = sb.student_id AND st.status <> 'withdrawn'
        JOIN group_students gs ON gs.group_id = sb.group_id AND gs.student_id = sb.student_id AND gs.status = 'active'
        LEFT JOIN group_payment_settings ps ON ps.group_id = sb.group_id
        WHERE sb.mode = 'monthly' AND sb.status = 'active' AND sb.start_date <= ? AND (sb.end_date IS NULL OR sb.end_date >= ?)",
        [(int)setting('default_due_days', 7), $end, $start]);
    $created = 0;
    $skipped = 0;
    $notes = [];
    $issue = ($ym === date('Y-m')) ? today() : $start;
    foreach ($subs as $sb) {
        $key = 'm-' . $sb['id'] . '-' . $ym;
        if (val('SELECT id FROM invoices WHERE monthly_key = ?', [$key])) {
            $skipped++;
            continue;
        }
        $amount = to_cents($sb['unit_price']) - to_cents($sb['discount_amount']);
        if ($amount <= 0) {
            $skipped++;
            $notes[] = 'تخطي اشتراك #' . $sb['id'] . ' لأن المبلغ بعد الخصم صفر.';
            continue;
        }
        try {
            create_invoice([
                'student_id' => $sb['student_id'], 'subscription_id' => $sb['id'], 'group_id' => $sb['group_id'], 'billing_period_id' => $periodId,
                'kind' => 'monthly', 'monthly_key' => $key, 'issue_date' => $issue, 'due_date' => date('Y-m-d', strtotime($issue . ' +' . (int)$sb['due_days'] . ' days')),
                'created_by' => $userId,
            ], [['description' => 'اشتراك شهري — ' . $sb['group_name'] . ' — ' . $ym, 'quantity' => 1, 'unit_cents' => $amount, 'amount_cents' => $amount]]);
            $created++;
        } catch (PDOException $e) {
            if (is_duplicate_error($e)) {
                $skipped++;
            } else {
                throw $e;
            }
        }
    }
    audit('billing_generated_monthly', 'billing_period', $periodId, ['period' => $ym, 'created' => $created, 'skipped' => $skipped]);
    return ['created' => $created, 'skipped' => $skipped, 'notes' => $notes];
}

/** حصص الحضور القابلة للفوترة لاشتراك حسب الحصص ولم تُفوتر بعد */
function billable_attendance(array $sub, string $until): array
{
    $statuses = setting('charge_late_sessions', '1') === '1' ? "('present','late')" : "('present')";
    $end = $sub['end_date'] && $sub['end_date'] < $until ? $sub['end_date'] : $until;
    return rows("SELECT a.id AS attendance_id, s.session_date
        FROM attendance a JOIN class_sessions s ON s.id = a.session_id AND s.status <> 'cancelled'
        LEFT JOIN invoice_items ii ON ii.attendance_id = a.id
        WHERE a.student_id = ? AND s.group_id = ? AND a.status IN $statuses AND ii.id IS NULL
          AND s.session_date >= ? AND s.session_date <= ?
        ORDER BY s.session_date, s.start_time, a.id", [$sub['student_id'], $sub['group_id'], $sub['start_date'], $end]);
}

/** @return array{created:int,skipped:int,sessions:int} فوترة الحصص التي حضرها الطالب (بلا تكرار بفضل attendance_id الفريد) */
function generate_session_invoices(?int $groupId, string $until, int $userId): array
{
    if (!valid_date($until) || $until > today()) {
        throw new FinanceException('تاريخ التسوية غير صالح (لا يمكن أن يكون مستقبلياً).');
    }
    $sql = "SELECT sb.*, g.name AS group_name, COALESCE(ps.due_days, ?) AS due_days, COALESCE(ps.settlement_sessions, 0) AS settle
        FROM subscriptions sb JOIN class_groups g ON g.id = sb.group_id
        LEFT JOIN group_payment_settings ps ON ps.group_id = sb.group_id
        WHERE sb.mode = 'per_session' AND sb.status = 'active'" . ($groupId ? ' AND sb.group_id = ?' : '');
    $p = [(int)setting('default_due_days', 7)];
    if ($groupId) {
        $p[] = $groupId;
    }
    $created = 0;
    $skipped = 0;
    $sessions = 0;
    foreach (rows($sql, $p) as $sb) {
        $att = billable_attendance($sb, $until);
        $n = count($att);
        if ($sb['settle'] > 0) {
            $n = intdiv($n, (int)$sb['settle']) * (int)$sb['settle'];
        }
        if ($n === 0) {
            $skipped++;
            continue;
        }
        $att = array_slice($att, 0, $n);
        $price = max(0, to_cents($sb['unit_price']) - to_cents($sb['discount_amount']));
        $items = array_map(fn($a) => ['description' => 'حصة ' . $sb['group_name'] . ' — ' . $a['session_date'], 'quantity' => 1, 'unit_cents' => $price, 'amount_cents' => $price, 'attendance_id' => $a['attendance_id']], $att);
        try {
            create_invoice([
                'student_id' => $sb['student_id'], 'subscription_id' => $sb['id'], 'group_id' => $sb['group_id'], 'kind' => 'sessions',
                'issue_date' => today(), 'due_date' => date('Y-m-d', strtotime('+' . (int)$sb['due_days'] . ' days')), 'created_by' => $userId,
                'notes' => 'تسوية ' . $n . ' حصة حتى ' . $until,
            ], $items);
            $created++;
            $sessions += $n;
        } catch (PDOException $e) {
            if (is_duplicate_error($e)) {
                $skipped++; // حصة فُوترت بالتوازي
            } else {
                throw $e;
            }
        }
    }
    audit('billing_generated_sessions', 'invoice', null, ['until' => $until, 'invoices' => $created, 'sessions' => $sessions]);
    return ['created' => $created, 'skipped' => $skipped, 'sessions' => $sessions];
}

// =====================================================================  الدفعات
function student_credit(int $studentId): int
{
    $c = val("SELECT COALESCE(SUM(p.amount - COALESCE((SELECT SUM(a.amount) FROM payment_allocations a WHERE a.payment_id = p.id),0)),0) FROM payments p WHERE p.student_id = ? AND p.status = 'valid'", [$studentId]);
    return max(0, to_cents($c));
}

/** @return array{due:int,overdue:int,credit:int} */
function student_balance(int $studentId): array
{
    $r = row("SELECT COALESCE(SUM(total - paid_amount),0) AS due, COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN total - paid_amount ELSE 0 END),0) AS overdue FROM invoices WHERE student_id = ? AND status IN ('open','partial')", [$studentId]);
    return ['due' => to_cents($r['due']), 'overdue' => to_cents($r['overdue']), 'credit' => student_credit($studentId)];
}

/**
 * تسجيل دفعة وتخصيصها على فواتير الطالب داخل معاملة واحدة وإصدار الإيصال.
 * @param array{student_id:int,method:string,reference?:string,paid_date:string,notes?:string,allocations:array<int,int>,advance_cents?:int,idem_key:string,user_id:int} $d
 * @return array{payment_id:int,receipt_no:string,duplicate:bool}
 */
function record_payment(array $d): array
{
    $methods = payment_methods(true);
    $method = $d['method'];
    if (!isset($methods[$method])) {
        throw new FinanceException('وسيلة الدفع غير مفعّلة.');
    }
    if (!empty($methods[$method]['requires_ref']) && trim((string)($d['reference'] ?? '')) === '') {
        throw new FinanceException('مرجع العملية مطلوب لوسيلة الدفع المختارة.');
    }
    if (!valid_date($d['paid_date']) || $d['paid_date'] > today()) {
        throw new FinanceException('تاريخ الدفع غير صالح أو مستقبلي.');
    }
    if (!preg_match('/^[a-f0-9]{40}$/', $d['idem_key'])) {
        throw new FinanceException('رمز العملية غير صالح. أعد تحميل الصفحة.');
    }
    $dup = row('SELECT p.id, r.receipt_no FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.idempotency_key = ?', [$d['idem_key']]);
    if ($dup) {
        return ['payment_id' => (int)$dup['id'], 'receipt_no' => (string)$dup['receipt_no'], 'duplicate' => true];
    }
    try {
        return record_payment_tx($d, $method);
    } catch (PDOException $e) {
        // سباق إرسال مزدوج: اكتملت دفعة بالمفتاح نفسه بين الفحص والإدخال => ليست خطأً، لا تُكرَّر الدفعة
        if (is_duplicate_error($e)) {
            $dup = row('SELECT p.id, r.receipt_no FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.idempotency_key = ?', [$d['idem_key']]);
            if ($dup) {
                return ['payment_id' => (int)$dup['id'], 'receipt_no' => (string)$dup['receipt_no'], 'duplicate' => true];
            }
        }
        throw $e;
    }
}

function record_payment_tx(array $d, string $method): array
{
    return tx(function () use ($d, $method) {
        $student = row('SELECT id, status FROM students WHERE id = ? FOR UPDATE', [$d['student_id']]);
        if (!$student) {
            throw new FinanceException('الطالب غير موجود.');
        }
        $allocs = array_filter($d['allocations'], fn($c) => $c > 0);
        $advance = (int)($d['advance_cents'] ?? 0);
        if ($advance < 0) {
            throw new FinanceException('قيمة الدفعة المقدمة غير صالحة.');
        }
        if ($advance > 0 && setting('allow_advance_payments', '1') !== '1') {
            throw new FinanceException('سياسة السنتر لا تسمح بالدفعات المقدمة.');
        }
        $total = array_sum($allocs) + $advance;
        if ($total <= 0) {
            throw new FinanceException('أدخل مبلغاً أكبر من صفر.');
        }
        $locked = [];
        foreach ($allocs as $invId => $cents) {
            $inv = row('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [(int)$invId]);
            if (!$inv || (int)$inv['student_id'] !== (int)$d['student_id']) {
                throw new FinanceException('الفاتورة المحددة لا تخص هذا الطالب.');
            }
            $out = invoice_outstanding($inv);
            if ($out <= 0) {
                throw new FinanceException('الفاتورة ' . $inv['invoice_no'] . ' ليست مستحقة السداد.');
            }
            if ($cents > $out) {
                throw new FinanceException('المبلغ المخصص للفاتورة ' . $inv['invoice_no'] . ' يتجاوز المتبقي (' . cents_str($out) . ').');
            }
            $locked[(int)$invId] = $cents;
        }
        $paidAt = $d['paid_date'] === today() ? now() : $d['paid_date'] . ' 12:00:00';
        $pid = insert('payments', [
            'student_id' => $d['student_id'], 'amount' => cents_str($total), 'method' => $method, 'reference' => ($d['reference'] ?? '') !== '' ? mb_substr($d['reference'], 0, 120) : null,
            'paid_at' => $paidAt, 'received_by' => $d['user_id'], 'status' => 'valid', 'notes' => ($d['notes'] ?? '') !== '' ? mb_substr($d['notes'], 0, 255) : null,
            'idempotency_key' => $d['idem_key'],
        ]);
        foreach ($locked as $invId => $cents) {
            insert('payment_allocations', ['payment_id' => $pid, 'invoice_id' => $invId, 'amount' => cents_str($cents)]);
        }
        $receiptNo = next_seq('receipt', 'R-');
        insert('receipts', ['receipt_no' => $receiptNo, 'payment_id' => $pid, 'issued_by' => $d['user_id'], 'status' => 'valid']);
        foreach (array_keys($locked) as $invId) {
            invoice_recompute($invId);
        }
        audit('payment_recorded', 'payment', $pid, ['receipt' => $receiptNo, 'amount' => cents_str($total), 'method' => $method, 'invoices' => array_keys($locked), 'advance' => cents_str($advance)]);
        return ['payment_id' => $pid, 'receipt_no' => $receiptNo, 'duplicate' => false];
    });
}

/** تطبيق رصيد مقدم (دفعات غير مخصصة) على فواتير مستحقة للطالب. @param array<int,int> $allocs invoice_id => cents */
function apply_credit(int $studentId, array $allocs, int $userId): void
{
    tx(function () use ($studentId, $allocs, $userId) {
        row('SELECT id FROM students WHERE id = ? FOR UPDATE', [$studentId]);
        $allocs = array_filter($allocs, fn($c) => $c > 0);
        $need = array_sum($allocs);
        if ($need <= 0) {
            throw new FinanceException('لا توجد مبالغ لتطبيقها.');
        }
        if ($need > student_credit($studentId)) {
            throw new FinanceException('المبلغ يتجاوز الرصيد المقدم المتاح.');
        }
        $pays = rows("SELECT p.id, p.amount - COALESCE((SELECT SUM(a.amount) FROM payment_allocations a WHERE a.payment_id = p.id),0) AS free
            FROM payments p WHERE p.student_id = ? AND p.status = 'valid' HAVING free > 0 ORDER BY p.paid_at, p.id FOR UPDATE", [$studentId]);
        foreach ($allocs as $invId => $cents) {
            $inv = row('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [(int)$invId]);
            if (!$inv || (int)$inv['student_id'] !== $studentId) {
                throw new FinanceException('الفاتورة لا تخص هذا الطالب.');
            }
            if ($cents > invoice_outstanding($inv)) {
                throw new FinanceException('المبلغ يتجاوز المتبقي على الفاتورة ' . $inv['invoice_no'] . '.');
            }
            $left = $cents;
            foreach ($pays as &$p) {
                if ($left <= 0) {
                    break;
                }
                $free = to_cents($p['free']);
                if ($free <= 0) {
                    continue;
                }
                $take = min($free, $left);
                q('INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)', [$p['id'], (int)$invId, cents_str($take)]);
                $p['free'] = cents_str($free - $take);
                $left -= $take;
            }
            unset($p);
            invoice_recompute((int)$invId);
        }
        audit('credit_applied', 'student', $studentId, ['allocations' => $allocs]);
    });
}

function void_payment(int $paymentId, string $reason, int $userId): void
{
    if (trim($reason) === '') {
        throw new FinanceException('سبب الإلغاء مطلوب.');
    }
    tx(function () use ($paymentId, $reason, $userId) {
        $p = row('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
        if (!$p) {
            throw new FinanceException('الدفعة غير موجودة.');
        }
        if ($p['status'] === 'voided') {
            throw new FinanceException('الدفعة ملغاة مسبقاً.');
        }
        q("UPDATE payments SET status = 'voided', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?", [mb_substr($reason, 0, 255), $userId, $paymentId]);
        q("UPDATE receipts SET status = 'void' WHERE payment_id = ?", [$paymentId]);
        foreach (rows('SELECT invoice_id FROM payment_allocations WHERE payment_id = ?', [$paymentId]) as $a) {
            invoice_recompute((int)$a['invoice_id']);
        }
        audit('payment_voided', 'payment', $paymentId, ['reason' => $reason, 'amount' => $p['amount']]);
    });
}

// =====================================================================  المصروفات ومستحقات المدرسين
function void_expense(int $expenseId, string $reason, int $userId): void
{
    if (trim($reason) === '') {
        throw new FinanceException('سبب الإلغاء مطلوب.');
    }
    tx(function () use ($expenseId, $reason, $userId) {
        $e = row('SELECT * FROM expenses WHERE id = ? FOR UPDATE', [$expenseId]);
        if (!$e || $e['status'] === 'voided') {
            throw new FinanceException('المصروف غير موجود أو ملغى مسبقاً.');
        }
        if (val('SELECT id FROM teacher_payouts WHERE expense_id = ?', [$expenseId])) {
            throw new FinanceException('هذا المصروف ناتج عن صرف مستحقات مدرس؛ ألغِ الصرف من صفحة مستحقات المدرسين.');
        }
        q("UPDATE expenses SET status = 'voided', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?", [mb_substr($reason, 0, 255), $userId, $expenseId]);
        audit('expense_voided', 'expense', $expenseId, ['reason' => $reason, 'amount' => $e['amount']]);
    });
}

/** @return array{created:int,skipped:int} احتساب مستحقات الشهر للمدرسين (ثابت/حسب الحصة) — آمن لإعادة التشغيل */
function accrue_teacher_compensations(string $ym, int $userId): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) || $ym > date('Y-m')) {
        throw new FinanceException('الشهر غير صالح.');
    }
    $created = 0;
    $skipped = 0;
    $start = month_start($ym);
    $end = month_end($ym);
    foreach (rows("SELECT * FROM teachers WHERE status = 'active' AND comp_type = 'fixed_monthly' AND comp_amount > 0 AND (hire_date IS NULL OR hire_date <= ?)", [$end]) as $t) {
        $key = 't-' . $t['id'] . '-' . $ym;
        $n = q('INSERT IGNORE INTO teacher_compensations (teacher_id, period_ym, kind, description, amount, accrual_key, created_by) VALUES (?,?,?,?,?,?,?)',
            [$t['id'], $ym, 'fixed', 'راتب شهري ثابت — ' . $ym, $t['comp_amount'], $key, $userId])->rowCount();
        $n ? $created++ : $skipped++;
    }
    $sess = rows("SELECT s.id, s.teacher_id, s.session_date, g.name AS gname, t.comp_amount
        FROM class_sessions s JOIN teachers t ON t.id = s.teacher_id AND t.comp_type = 'per_session' AND t.comp_amount > 0
        JOIN class_groups g ON g.id = s.group_id
        WHERE s.status = 'held' AND s.session_date BETWEEN ? AND ?", [$start, $end]);
    foreach ($sess as $s) {
        $n = q('INSERT IGNORE INTO teacher_compensations (teacher_id, period_ym, kind, description, amount, session_id, created_by) VALUES (?,?,?,?,?,?,?)',
            [$s['teacher_id'], $ym, 'session', 'حصة ' . $s['gname'] . ' — ' . $s['session_date'], $s['comp_amount'], $s['id'], $userId])->rowCount();
        $n ? $created++ : $skipped++;
    }
    audit('teacher_comp_accrued', 'teacher', null, ['period' => $ym, 'created' => $created, 'skipped' => $skipped]);
    return ['created' => $created, 'skipped' => $skipped];
}

/** @return array{accrued:int,paid:int,balance:int} */
function teacher_balance(int $teacherId): array
{
    $a = to_cents(val("SELECT COALESCE(SUM(amount),0) FROM teacher_compensations WHERE teacher_id = ? AND status = 'valid'", [$teacherId]));
    $p = to_cents(val("SELECT COALESCE(SUM(amount),0) FROM teacher_payouts WHERE teacher_id = ? AND status = 'valid'", [$teacherId]));
    return ['accrued' => $a, 'paid' => $p, 'balance' => $a - $p];
}

function record_teacher_payout(int $teacherId, int $cents, string $method, string $ref, string $date, string $notes, int $userId): int
{
    $methods = payment_methods(true);
    if (!isset($methods[$method])) {
        throw new FinanceException('وسيلة الصرف غير مفعّلة.');
    }
    if (!valid_date($date) || $date > today()) {
        throw new FinanceException('تاريخ الصرف غير صالح.');
    }
    if ($cents <= 0) {
        throw new FinanceException('المبلغ يجب أن يكون أكبر من صفر.');
    }
    return tx(function () use ($teacherId, $cents, $method, $ref, $date, $notes, $userId) {
        $t = row('SELECT id, full_name FROM teachers WHERE id = ? FOR UPDATE', [$teacherId]);
        if (!$t) {
            throw new FinanceException('المدرس غير موجود.');
        }
        $bal = teacher_balance($teacherId)['balance'];
        if ($cents > $bal) {
            throw new FinanceException('المبلغ يتجاوز المتبقي المستحق للمدرس (' . cents_str(max(0, $bal)) . ').');
        }
        $catId = (int)val("SELECT id FROM expense_categories WHERE is_system = 1 AND name = 'مستحقات المدرسين'");
        if (!$catId) {
            $catId = insert('expense_categories', ['name' => 'مستحقات المدرسين', 'is_system' => 1]);
        }
        // قيد مصروف واحد فقط يمثل الصرف الفعلي (لا يتكرر قيد المستحق والمصروف)
        $expId = insert('expenses', ['category_id' => $catId, 'expense_date' => $date, 'amount' => cents_str($cents), 'description' => 'صرف مستحقات المدرس ' . $t['full_name'],
            'payee' => $t['full_name'], 'recorded_by' => $userId, 'status' => 'valid']);
        $id = insert('teacher_payouts', ['teacher_id' => $teacherId, 'amount' => cents_str($cents), 'method' => $method, 'reference' => $ref !== '' ? mb_substr($ref, 0, 120) : null,
            'paid_date' => $date, 'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null, 'paid_by' => $userId, 'expense_id' => $expId, 'status' => 'valid']);
        audit('teacher_payout', 'teacher_payout', $id, ['teacher_id' => $teacherId, 'amount' => cents_str($cents), 'expense_id' => $expId]);
        return $id;
    });
}

function void_teacher_payout(int $payoutId, string $reason, int $userId): void
{
    if (trim($reason) === '') {
        throw new FinanceException('سبب الإلغاء مطلوب.');
    }
    tx(function () use ($payoutId, $reason, $userId) {
        $p = row('SELECT * FROM teacher_payouts WHERE id = ? FOR UPDATE', [$payoutId]);
        if (!$p || $p['status'] === 'voided') {
            throw new FinanceException('الصرف غير موجود أو ملغى مسبقاً.');
        }
        q("UPDATE teacher_payouts SET status = 'voided', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?", [mb_substr($reason, 0, 255), $userId, $payoutId]);
        if ($p['expense_id']) {
            q("UPDATE expenses SET status = 'voided', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?", ['إلغاء صرف مستحقات #' . $payoutId . ': ' . mb_substr($reason, 0, 200), $userId, $p['expense_id']]);
        }
        audit('teacher_payout_voided', 'teacher_payout', $payoutId, ['reason' => $reason]);
    });
}

// =====================================================================  حالة الاشتراك للطالب في مجموعة
/** @return array{sub:?array,state:string,due:int,overdue:int} state: none|active|expiring|expired|cancelled */
function subscription_state(int $studentId, int $groupId): array
{
    $sub = row('SELECT * FROM subscriptions WHERE student_id = ? AND group_id = ? ORDER BY (status = \'active\') DESC, id DESC LIMIT 1', [$studentId, $groupId]);
    $state = 'none';
    if ($sub) {
        if ($sub['status'] === 'cancelled') {
            $state = 'cancelled';
        } elseif ($sub['end_date'] && $sub['end_date'] < today()) {
            $state = 'expired';
        } elseif ($sub['end_date'] && $sub['end_date'] <= date('Y-m-d', strtotime('+' . (int)setting('subscription_alert_days', 7) . ' days'))) {
            $state = 'expiring';
        } else {
            $state = 'active';
        }
    }
    $r = row("SELECT COALESCE(SUM(total - paid_amount),0) AS due, COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN total - paid_amount ELSE 0 END),0) AS overdue FROM invoices WHERE student_id = ? AND group_id = ? AND status IN ('open','partial')", [$studentId, $groupId]);
    return ['sub' => $sub, 'state' => $state, 'due' => to_cents($r['due']), 'overdue' => to_cents($r['overdue'])];
}

function subscription_state_label(string $state): array
{
    return ['none' => ['بلا اشتراك', 'secondary'], 'active' => ['اشتراك نشط', 'success'], 'expiring' => ['قريب الانتهاء', 'warning'], 'expired' => ['منتهٍ', 'danger'], 'cancelled' => ['ملغى', 'secondary']][$state] ?? ['—', 'secondary'];
}
