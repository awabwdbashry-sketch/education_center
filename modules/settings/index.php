<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/upload.php';
require_perm('settings.manage');

$errors = [];
if (is_post()) {
    $v = fn(string $k, int $max = 255) => mb_substr(post($k), 0, $max);
    $center = $v('center_name', 150);
    if ($center === '') {
        $errors[] = 'اسم السنتر مطلوب.';
    }
    $email = $v('center_email', 150);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'بريد السنتر غير صالح.';
    }
    $ints = ['default_due_days' => [0, 90], 'subscription_alert_days' => [1, 60], 'absence_alert_count' => [2, 20], 'backup_alert_days' => [1, 90], 'session_timeout_minutes' => [10, 1440]];
    $iv = [];
    foreach ($ints as $k => [$lo, $hi]) {
        $n = post_int($k);
        if ($n < $lo || $n > $hi) {
            $errors[] = "قيمة $k يجب أن تكون بين $lo و$hi.";
        }
        $iv[$k] = (string)$n;
    }
    $methods = [];
    foreach (post_arr('pm_code') as $i => $code) {
        $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$code));
        $label = mb_substr(trim((string)($_POST['pm_label'][$i] ?? '')), 0, 60);
        if ($code === '' || $label === '') {
            continue;
        }
        $methods[$code] = ['code' => $code, 'label' => $label, 'enabled' => !empty($_POST['pm_enabled'][$i]), 'requires_ref' => !empty($_POST['pm_ref'][$i])];
    }
    if (!array_filter($methods, fn($m) => $m['enabled'])) {
        $errors[] = 'فعّل وسيلة دفع واحدة على الأقل.';
    }
    $logo = null;
    if (!$errors) {
        $up = save_upload('logo', 'branding');
        if (!$up['ok'] && $up['error'] !== '') {
            $errors[] = $up['error'];
        } elseif ($up['ok']) {
            $logo = $up['path'];
        }
    }
    if (!$errors) {
        tx(function () use ($v, $iv, $methods, $logo, $center, $email) {
            $set = ['center_name' => $center, 'center_phone' => $v('center_phone', 40), 'center_email' => $email, 'center_address' => $v('center_address'), 'currency' => $v('currency', 20) ?: 'جنيه',
                'phone_country_code' => preg_replace('/\D/', '', post('phone_country_code')), 'receipt_footer' => $v('receipt_footer', 200), 'charge_late_sessions' => post('charge_late_sessions') === '1' ? '1' : '0',
                'allow_advance_payments' => post('allow_advance_payments') === '1' ? '1' : '0', 'exams_enabled' => post('exams_enabled') === '1' ? '1' : '0', 'payment_methods' => json_encode(array_values($methods), JSON_UNESCAPED_UNICODE),
                'msg_tpl_subscription' => $v('msg_tpl_subscription', 600), 'msg_tpl_dues' => $v('msg_tpl_dues', 600), 'msg_tpl_absence' => $v('msg_tpl_absence', 600)] + $iv;
            if ($logo) {
                delete_upload((string)setting('center_logo', ''));
                $set['center_logo'] = $logo;
            }
            foreach ($set as $k => $val) {
                save_setting($k, (string)$val);
            }
            audit('settings_updated', 'settings', null, array_keys($set));
        });
        flash('success', 'تم حفظ الإعدادات.');
        redirect('modules/settings/index.php');
    }
}
$S = fn(string $k, $d = '') => $_POST[$k] ?? setting($k, $d);
$methods = payment_methods(false);
$methods['_new1'] = ['code' => '', 'label' => '', 'enabled' => false, 'requires_ref' => false];
$methods['_new2'] = ['code' => '', 'label' => '', 'enabled' => false, 'requires_ref' => false];
render_header('إعدادات السنتر');
echo errors_box($errors);
?>
<form method="post" enctype="multipart/form-data" class="row g-3"><?= csrf_field() ?>
<div class="col-lg-6"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-building"></i> بيانات السنتر</div><div class="row g-3">
  <?= field('text', 'center_name', 'اسم السنتر', $S('center_name'), ['col' => 'col-12', 'required' => true]) ?>
  <?= field('text', 'center_phone', 'الهاتف', $S('center_phone'), ['attrs' => 'dir="ltr"']) ?><?= field('email', 'center_email', 'البريد', $S('center_email'), ['attrs' => 'dir="ltr"']) ?>
  <?= field('text', 'center_address', 'العنوان', $S('center_address'), ['col' => 'col-12']) ?>
  <div class="col-12"><label class="form-label">الشعار</label><?php if (setting('center_logo')): ?><div class="mb-1"><img src="<?= e(url('file.php?f=' . rawurlencode((string)setting('center_logo')))) ?>" style="height:56px" alt=""></div><?php endif; ?><input class="form-control" type="file" name="logo" accept="image/jpeg,image/png,image/webp"></div>
  <?= field('text', 'currency', 'العملة', $S('currency', 'جنيه'), ['col' => 'col-md-4']) ?><?= field('text', 'phone_country_code', 'مفتاح الدولة للواتساب', $S('phone_country_code'), ['col' => 'col-md-4', 'attrs' => 'dir="ltr"', 'help' => 'مثال 249 للسودان، 20 لمصر']) ?>
  <?= field('text', 'receipt_footer', 'تذييل الإيصال', $S('receipt_footer'), ['col' => 'col-md-4']) ?></div></div></div>
<div class="col-lg-6"><div class="ecm-card h-100"><div class="ecm-card-title"><i class="bi bi-sliders"></i> الاشتراكات والتنبيهات والنظام</div><div class="row g-3">
  <?= field('number', 'default_due_days', 'مهلة السداد الافتراضية (أيام)', $S('default_due_days', 7), ['col' => 'col-md-6']) ?><?= field('number', 'subscription_alert_days', 'التنبيه قبل انتهاء الاشتراك (أيام)', $S('subscription_alert_days', 7), ['col' => 'col-md-6']) ?>
  <?= field('number', 'absence_alert_count', 'عدد الغياب للتنبيه (30 يوماً)', $S('absence_alert_count', 3), ['col' => 'col-md-6']) ?><?= field('number', 'backup_alert_days', 'تنبيه النسخ الاحتياطي القديم (أيام)', $S('backup_alert_days', 7), ['col' => 'col-md-6']) ?>
  <?= field('number', 'session_timeout_minutes', 'انتهاء الجلسة عند الخمول (دقائق)', $S('session_timeout_minutes', 120), ['col' => 'col-md-6']) ?>
  <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="charge_late_sessions" value="1" <?= $S('charge_late_sessions', '1') === '1' ? 'checked' : '' ?>> <span class="form-check-label">احتساب حصة «المتأخر» في الدفع بالحصة</span></label>
  <label class="form-check"><input class="form-check-input" type="checkbox" name="allow_advance_payments" value="1" <?= $S('allow_advance_payments', '1') === '1' ? 'checked' : '' ?>> <span class="form-check-label">السماح بالدفعات المقدمة</span></label>
  <label class="form-check"><input class="form-check-input" type="checkbox" name="exams_enabled" value="1" <?= $S('exams_enabled', '0') === '1' ? 'checked' : '' ?>> <span class="form-check-label">تفعيل وحدة الاختبارات والنتائج</span></label></div></div></div></div>
<div class="col-12"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-credit-card"></i> وسائل الدفع (لا يوجد دفع إلكتروني فعلي؛ تُسجَّل الدفعات يدوياً)</div>
  <table class="table ecm-table"><thead><tr><th>الرمز (إنجليزي)</th><th>الاسم</th><th>مفعّلة</th><th>مرجع إلزامي</th></tr></thead><tbody><?php $i = 0; foreach ($methods as $m): ?><tr><td><input class="form-control form-control-sm" name="pm_code[<?= $i ?>]" value="<?= e($m['code']) ?>" dir="ltr" <?= $m['code'] !== '' ? 'readonly' : '' ?>></td><td><input class="form-control form-control-sm" name="pm_label[<?= $i ?>]" value="<?= e($m['label']) ?>"></td><td><input class="form-check-input" type="checkbox" name="pm_enabled[<?= $i ?>]" value="1" <?= !empty($m['enabled']) ? 'checked' : '' ?>></td><td><input class="form-check-input" type="checkbox" name="pm_ref[<?= $i ?>]" value="1" <?= !empty($m['requires_ref']) ? 'checked' : '' ?>></td></tr><?php $i++; endforeach; ?></tbody></table></div></div>
<div class="col-12"><div class="ecm-card"><div class="ecm-card-title"><i class="bi bi-chat-text"></i> قوالب رسائل أولياء الأمور</div><div class="form-text mb-2">المتغيرات: {guardian} {student} {group} {amount} {date} {center}</div><div class="row g-3">
  <?= textarea_field('msg_tpl_subscription', 'تذكير بالاشتراك', $S('msg_tpl_subscription'), ['col' => 'col-md-4', 'rows' => 4]) ?><?= textarea_field('msg_tpl_dues', 'تذكير بالمتأخرات', $S('msg_tpl_dues'), ['col' => 'col-md-4', 'rows' => 4]) ?><?= textarea_field('msg_tpl_absence', 'إشعار الغياب', $S('msg_tpl_absence'), ['col' => 'col-md-4', 'rows' => 4]) ?></div></div></div>
<div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2"></i> حفظ الإعدادات</button></div></form>
<?php render_footer();
