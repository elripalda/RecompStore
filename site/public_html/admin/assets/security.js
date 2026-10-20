// Recomp Store admin (2.1): copy buttons, and the QR code for two-step login (qrcode.js, MIT).
(function () {
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.getElementById(b.getAttribute('data-copy'));
      if (!el) return;
      var text = 'value' in el && el.tagName === 'INPUT' ? el.value : el.textContent;
      var done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1600); };
      if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { if (el.select) el.select(); });
      else if (el.select) { el.select(); document.execCommand('copy'); done(); }
    });
  });
  var qr = document.getElementById('totp-qr');
  if (qr && window.qrcode) {
    var q = qrcode(0, 'M');
    q.addData(qr.getAttribute('data-uri'));
    q.make();
    qr.innerHTML = q.createSvgTag({ cellSize: 5, margin: 3, scalable: true, alt: 'QR code for your authenticator app' });
  }
})();
