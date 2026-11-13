// RecompStore admin (4.8): Sponsors > Instant Gaming. The add/edit form fills the card's name from the
// recomp you pick, points Find it at Instant Gaming's search, and says what the discount will look like.
(function () {
  'use strict';
  var names = {};
  try { names = JSON.parse((document.getElementById('ig-names') || {}).textContent || '{}'); } catch (e) { names = {}; }

  document.querySelectorAll('[data-ig-del]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.form && b.form.querySelector('[data-ig-del-field]');
      if (f) f.value = b.getAttribute('data-ig-del');
    });
  });

  var form = document.getElementById('edit');
  if (!form) return;
  var app = form.elements.app_id, title = form.elements.title, find = form.querySelector('[data-ig-find]'), hint = form.querySelector('[data-ig-hint]');
  var igr = form.getAttribute('data-igr') || 'recomp', min = parseInt(form.getAttribute('data-tagmin'), 10) || 0, tagOn = form.getAttribute('data-tagon') === '1';

  function findHref() {
    var q = (title.value || names[app.value] || app.value || '').trim();
    if (find) find.href = 'https://www.instant-gaming.com/en/search/?q=' + encodeURIComponent(q) + '&igr=' + encodeURIComponent(igr);
  }
  app.addEventListener('change', function () {
    if (names[app.value] && !title.value.trim()) title.value = names[app.value];
    findHref();
  });
  title.addEventListener('input', findHref);

  function num(v) { v = String(v || '').replace(',', '.').trim(); var n = parseFloat(v); return isFinite(n) && n > 0 ? n : 0; }
  function say() {
    var p = num(form.elements.price.value), o = num(form.elements.old_price.value), mode = form.elements.tag.value;
    hint.textContent = '';
    hint.className = 'span2 ig-hint';
    if (!p) { hint.textContent = 'No price: the button will say See price.'; return; }
    if (!o || o <= p) { hint.textContent = 'No discount: the card shows just the price.'; return; }
    var pct = Math.round((o - p) / o * 100);
    if (!tagOn) { hint.textContent = pct + '% off. Discount tags are off for every card, so it shows just the price.'; return; }
    if (mode === '2') { hint.textContent = pct + '% off. Its tag is set to never show.'; return; }
    if (mode === '1' && pct < min) {
      hint.className = 'span2 ig-hint warn';
      hint.textContent = 'Only ' + pct + '% off, and the tag is set to always show. A tag this small can look like noise. ';
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'small'; b.textContent = 'Use the rule instead';
      b.addEventListener('click', function () { form.elements.tag.value = '0'; say(); });
      hint.appendChild(b);
      return;
    }
    hint.textContent = pct + '% off. ' + (mode === '1' || pct >= min ? 'The tag and the old price show.' : 'Under ' + min + '%, so the tag and the old price stay hidden.');
  }
  ['price', 'old_price', 'tag'].forEach(function (n) { form.elements[n].addEventListener('input', say); form.elements[n].addEventListener('change', say); });
  say();
})();
