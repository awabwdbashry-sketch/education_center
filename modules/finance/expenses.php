<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/finance.php';
require_once __DIR__ . '/../../includes/upload.php';
require_perm('expenses.view');

if (is_post()) {
    try {
        $act = post('action');
        if ($act === 'add') {
            require_perm('expenses.manage');
            $amt = parse_money(post('amount'));
            $date = post('expense_date');
            $cat = post_int('category_id');
            $desc = mb_substr(post('description'), 0, 255);
            if ($amt === null || $amt <= 0) {
                throw new FinanceException('المبلغ غير صالح.');
            }
            if (!valid_date($date) || $date > today()) {
                throw new FinanceException('التاريخ غير صالح أو مستقبلي.');
            }
            if (!row('SELECT id FROM expense_categories WHERE id = ? AND is_active = 1 AND is_system = 0', [$cat])) {
                throw new FinanceException('التصنيف غير صالح (مستحقات المدرسين تُسجَّل من صفحتها).');
            }
            if ($desc === '') {
                throw new FinanceException('الوصف مطلوب.');
            }
            $att = null;
            $up = save_upload('attachment', 'expenses', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'], 4194304);
            if (!$up['ok'] && $up['error'] !== '') {
                throw new FinanceException($up['error']);
            }
            $att = $up['ok'] ? $up['path'] : null;
            $id = insert('expenses', ['category_id' => $cat, 'expense_date' => $date, 'amount' => cents_str($amt), 'description' => $desc, 'payee' => mb_substr(post('payee'), 0, 150) ?: null, 'attachment_path' => $att, 'recorded_by' => uid(), 'status' => 'valid']);
            audit('expense_created', 'expense', $id, ['amount' => cents_str($amt), 'category' => $cat]);
            flash('success', 'تم تسجيل المصروف.');
        } elseif ($act === 'void') {
            require_perm('expenses.void');
            void_expense(post_int('id'), post('reason'), uid());
            flash('success', 'تم إلغاء المصروف بسبب موثق.');
        } elseif ($act === 'cat_add') {
            require_perm('expenses.manage');
            $n = mb_substr(post('name'), 0, 100);
            if ($n === '') {
                throw new FinanceException('اسم التصنيف مطلوب.');
            }
            insert('expense_categories', ['name' => $n, 'is_system' => 0]);
            flash('success', 'تمت إضافة التصنيف.');
        }
    } catch (FinanceException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        flash('error', is_duplicate_error($e) ? 'التصنيف موجود مسبقاً.' : 'تعذر الحفظ.');
    }
    redirect('modules/finance/expenses.php');
}
$from = valid_date(get('from')) ? get('from') : date('Y-m-01');
$to = valid_date(get('to')) ? get('to') : today();
$where = 'e.expense_date BETWEEN ? AND ?';
$p = [$from, $to];
if (get_int('category_id')) {
    $where .= ' AND e.category_id = ?';
    $p[] = get_int('category_id');
}
$cats = col_opts(rows('SELECT id, name FROM expense_categories WHERE is_active = 1 ORDER BY is_system, name'), 'id', 'name');
$addCats = col_opts(rows('SELECT id, name FROM expense_categories WHERE is_active = 1 AND is_system = 0 ORDER BY name'), 'id', 'name');
$tot = to_cents(val("SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE $where AND e.status = 'valid'", $p));
$pg = paginate("SELECT COUNT(*) FROM expenses e WHERE $where", "SELECT e.*, c.name AS cname, u.full_name AS uname FROM expenses e JOIN expense_categories c ON c.id = e.category_id LEFT JOIN users u ON u.id = e.recorded_by WHERE $where ORDER BY e.expense_date DESC, e.id DESC", $p, 25);
if (get('export') === 'csv') {
    require_perm('reports.export');
    $all = rows("SELECT e.expense_date, c.name AS cname, e.amount, e.description, e.payee, e.status FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE $where ORDER BY e.expense_date", $p);
    audit('export_expenses', 'expense', null, ['rows' => count($all)]);
    csv_download('expenses_' . $from . '_' . $to . '.csv', ['التاريخ', 'التصنيف', 'المبلغ', 'الوصف', 'المستفيد', 'الحالة'], array_map(fn($r) => [$r['expense_date'], $r['cname'], $r['amount'], $r['description'], $r['payee'], $r['status'] === 'valid' ? 'معتمد' : 'ملغى'], $all));
}
render_header('المصروفات');
?>
<form class="filter-bar row g-2 align-items-end" method="get"><div class="col-6 col-md-2"><label class="form-label small mb-0">من</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div><div class="col-6 col-md-2"><label class="form-label small mb-0">إلى</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-4"><label class="form-label small mb-0">التصنيف</label><select class="form-select" name="category_id"><?= opt_list($cats, get('category_id'), true, 'كل التصنيفات') ?></select></div>
  <div class="col-md-4 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-funnel"></i></button><a class="btn btn-light" href="expenses.php">مسح</a><?php if (can('reports.export')): ?><a class="btn btn-outline-secondary" href="<?= e(query_with(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i></a><?php endif; ?></div></form>
<?php if (can('expenses.manage')): ?><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-plus-circle"></i> تسجيل مصروف</div><form method="post" enctype="multipart/form-data" class="row g-2"><?= csrf_field() ?><input type="hidden" name="action" value="add">
  <div class="col-md-3"><select class="form-select" name="category_id" required><?= opt_list($addCats, '', true, '— التصنيف —') ?></select></div><div class="col-md-2"><input class="form-control" name="amount" placeholder="المبلغ" required inputmode="decimal" dir="ltr"></div><div class="col-md-2"><input class="form-control" type="date" name="expense_date" value="<?= e(today()) ?>" max="<?= e(today()) ?>" required></div>
  <div class="col-md-3"><input class="form-control" name="payee" placeholder="الجهة المستفيدة"></div><div class="col-md-2"><input class="form-control" type="file" name="attachment" accept="image/*,application/pdf"></div>
  <div class="col-md-10"><input class="form-control" name="description" placeholder="الوصف" required maxlength="255"></div><div class="col-md-2"><button class="btn btn-primary w-100">حفظ</button></div></form>
  <form method="post" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="cat_add"><div class="col-md-4"><input class="form-control form-control-sm" name="name" placeholder="تصنيف جديد"></div><div class="col-md-2"><button class="btn btn-sm btn-outline-secondary">إضافة تصنيف</button></div></form></div><?php endif; ?>
<div class="alert alert-light border">مجموع المصروفات المسجلة (غير الملغاة) في الفترة: <strong class="money text-danger"><?= e(money_c($tot)) ?></strong></div>
<?php if (!$pg['rows']): echo '<div class="ecm-card">' . empty_state('لا توجد مصروفات في هذه الفترة', '', '', 'wallet2') . '</div>'; else: ?>
<div class="table-responsive"><table class="table ecm-table"><thead><tr><th>التاريخ</th><th>التصنيف</th><th>الوصف</th><th>المستفيد</th><th>المبلغ</th><th>سجّله</th><th></th></tr></thead><tbody>
<?php foreach ($pg['rows'] as $x): $v = $x['status'] === 'voided'; ?><tr class="<?= $v ? 'text-muted' : '' ?>"><td><?= e(fdate($x['expense_date'])) ?></td><td><?= e($x['cname']) ?></td><td><?= e($x['description']) ?><?= $x['attachment_path'] ? ' <a href="' . e(url('file.php?f=' . rawurlencode($x['attachment_path']))) . '" target="_blank"><i class="bi bi-paperclip"></i></a>' : '' ?><?= $v ? '<div class="small text-danger">ملغى: ' . e($x['void_reason']) . '</div>' : '' ?></td><td><?= e($x['payee']) ?></td><td class="money <?= $v ? 'text-decoration-line-through' : '' ?>"><?= e(money($x['amount'])) ?></td><td class="small"><?= e($x['uname']) ?></td>
<td class="text-end"><?php if (!$v && can('expenses.void')): ?><form method="post" class="d-inline-flex gap-1" data-confirm="إلغاء هذا المصروف؟"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><input class="form-control form-control-sm" style="width:110px" name="reason" placeholder="السبب" required><button class="btn btn-sm btn-outline-danger">إلغاء</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?= pager($pg); endif; ?>
<?php render_footer();
