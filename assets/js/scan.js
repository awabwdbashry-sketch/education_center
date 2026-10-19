/* ماسح QR: كاميرا المتصفح (jsQR) + إدخال يدوي. لا يرسل إلا محتوى الرمز للخادم. */
(function () {
  'use strict';
  var el = document.getElementById('scanCfg'); if (!el) return; var cfg = { url: el.dataset.url, csrf: el.dataset.csrf };
  var video = document.getElementById('video'), cv = document.getElementById('cv'), ctx = cv.getContext('2d', { willReadFrequently: true });
  var sel = document.getElementById('sessionSel'), res = document.getElementById('result'), log = document.getElementById('log'), err = document.getElementById('camErr');
  var startBtn = document.getElementById('startBtn'), stopBtn = document.getElementById('stopBtn');
  var stream = null, raf = null, busy = false, lastRaw = '', lastAt = 0;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function show(r) {
    var cls = r.ok ? 'ok' : (r.code === 'duplicate' || r.code === 'inactive' ? 'warn' : 'bad');
    var h = '<div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-' + (r.ok ? 'check-circle-fill text-success' : (cls === 'warn' ? 'exclamation-triangle-fill text-warning' : 'x-octagon-fill text-danger')) + ' fs-3"></i><strong>' + esc(r.message) + '</strong></div>';
    if (r.student) h += '<div class="fs-5">' + esc(r.student.name) + '</div><div class="small text-muted" dir="ltr">' + esc(r.student.no) + '</div>';
    if (r.fin) {
      h += '<div class="mt-1"><span class="badge text-bg-' + (r.fin.state === 'active' ? 'success' : (r.fin.state === 'expiring' ? 'warning' : 'secondary')) + '">' + esc(r.fin.label) + '</span>';
      if (parseFloat(r.fin.overdue) > 0) h += ' <span class="badge text-bg-danger">متأخر ' + esc(r.fin.overdue) + '</span>';
      else if (parseFloat(r.fin.due) > 0) h += ' <span class="badge text-bg-warning">متبقي ' + esc(r.fin.due) + '</span>';
      h += '</div>';
    }
    res.className = 'scan-result ' + cls; res.innerHTML = h;
    var li = document.createElement('li'); li.className = 'list-group-item px-0 small d-flex justify-content-between';
    li.innerHTML = '<span>' + esc(r.student ? r.student.name : 'غير معروف') + '</span><span class="' + (r.ok ? 'text-success' : 'text-danger') + '">' + esc(r.ok ? 'سُجّل' : r.message) + '</span>';
    log.insertBefore(li, log.firstChild); while (log.children.length > 30) log.removeChild(log.lastChild);
    if (navigator.vibrate) navigator.vibrate(r.ok ? 80 : [60, 40, 60]);
  }
  function send(payload, byNumber) {
    if (busy) return; busy = true;
    var body = new URLSearchParams({ session_id: sel.value, payload: payload, by_number: byNumber ? '1' : '0', _csrf: cfg.csrf });
    fetch(cfg.url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(show)
      .catch(function () { show({ ok: false, code: 'net', message: 'تعذر الاتصال بالخادم أو انتهت الجلسة.' }); })
      .finally(function () { setTimeout(function () { busy = false; }, 900); });
  }
  function tick() {
    if (!stream) return;
    if (video.readyState === video.HAVE_ENOUGH_DATA) {
      cv.width = video.videoWidth; cv.height = video.videoHeight; ctx.drawImage(video, 0, 0, cv.width, cv.height);
      var img = ctx.getImageData(0, 0, cv.width, cv.height), code = window.jsQR && jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
      if (code && code.data) {
        var now = Date.now();
        if (code.data !== lastRaw || now - lastAt > 4000) { lastRaw = code.data; lastAt = now; send(code.data, false); }
      }
    }
    raf = requestAnimationFrame(tick);
  }
  function stop() { if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; } if (raf) cancelAnimationFrame(raf); video.srcObject = null; startBtn.disabled = false; stopBtn.disabled = true; }
  startBtn.addEventListener('click', function () {
    err.classList.add('d-none');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { err.textContent = 'الكاميرا غير متاحة في هذا المتصفح أو الاتصال (يلزم localhost أو HTTPS). استخدم الإدخال اليدوي.'; err.classList.remove('d-none'); return; }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
      stream = s; video.srcObject = s; video.play(); startBtn.disabled = true; stopBtn.disabled = false; tick();
    }).catch(function (e) { err.textContent = (e && e.name === 'NotAllowedError') ? 'تم رفض إذن الكاميرا. اسمح بالوصول من إعدادات المتصفح أو استخدم الإدخال اليدوي.' : 'تعذر تشغيل الكاميرا. استخدم الإدخال اليدوي.'; err.classList.remove('d-none'); });
  });
  stopBtn.addEventListener('click', stop);
  sel.addEventListener('change', function () { lastRaw = ''; res.className = 'scan-result'; res.innerHTML = '<span class="text-muted">بانتظار المسح…</span>'; log.innerHTML = ''; });
  document.getElementById('manualForm').addEventListener('submit', function (e) {
    e.preventDefault(); var inp = document.getElementById('manualInput'), v = inp.value.trim(); if (!v) return;
    var isQr = v.indexOf('ECM1.') === 0; send(v, !isQr); inp.value = ''; inp.focus();
  });
  window.addEventListener('beforeunload', stop);
})();
