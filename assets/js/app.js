/* سلوك الواجهة العام: القائمة الجانبية، نوافذ التأكيد، منع الإرسال المزدوج، QR */
(function () {
  'use strict';
  var sb = document.getElementById('sidebar');
  var tg = document.getElementById('sidebarToggle');
  if (tg && sb) {
    tg.addEventListener('click', function () { sb.classList.toggle('open'); });
    document.addEventListener('click', function (e) {
      if (sb.classList.contains('open') && !sb.contains(e.target) && !tg.contains(e.target)) sb.classList.remove('open');
    });
  }

  // نافذة تأكيد للنماذج التي تحمل data-confirm
  var modalEl = document.getElementById('confirmModal');
  var pending = null;
  if (modalEl && window.bootstrap) {
    var modal = new bootstrap.Modal(modalEl);
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (f.dataset && f.dataset.confirm && !f.dataset.confirmed) {
        e.preventDefault();
        pending = f;
        document.getElementById('confirmBody').textContent = f.dataset.confirm;
        modal.show();
      }
    }, true);
    document.getElementById('confirmOk').addEventListener('click', function () {
      if (pending) { pending.dataset.confirmed = '1'; modal.hide(); pending.requestSubmit ? pending.requestSubmit() : pending.submit(); }
    });
  }

  // منع الضغط المزدوج على أزرار الإرسال
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented) return;
    if (f.dataset && f.dataset.noLock) return;
    var btns = f.querySelectorAll('button[type=submit]');
    setTimeout(function () {
      btns.forEach(function (b) { b.disabled = true; b.dataset.label = b.innerHTML; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> جارٍ الحفظ...'; });
    }, 0);
  });
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) document.querySelectorAll('button[type=submit][data-label]').forEach(function (b) { b.disabled = false; b.innerHTML = b.dataset.label; });
  });

  // تنبيهات تختفي تلقائياً
  setTimeout(function () {
    document.querySelectorAll('.ecm-alert.alert-success').forEach(function (a) { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(function () { a.remove(); }, 600); });
  }, 6000);

  // رسم QR داخل العناصر التي تحمل data-qr
  if (window.qrcode) {
    document.querySelectorAll('[data-qr]').forEach(function (el) {
      var qr = qrcode(0, 'M');
      qr.addData(el.getAttribute('data-qr'));
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: parseInt(el.getAttribute('data-qr-size') || '4', 10), margin: 2, scalable: true });
      var svg = el.querySelector('svg'); if (svg) { svg.style.width = '100%'; svg.style.height = 'auto'; }
    });
  }

  // أزرار الطباعة
  document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

  // تحديد/إلغاء تحديد الكل
  document.querySelectorAll('[data-check-all]').forEach(function (c) {
    c.addEventListener('change', function () {
      document.querySelectorAll(c.getAttribute('data-check-all')).forEach(function (x) { if (!x.disabled) { x.checked = c.checked; x.dispatchEvent(new Event('change', { bubbles: true })); } });
    });
  });
})();
