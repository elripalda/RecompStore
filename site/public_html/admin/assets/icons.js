// RecompStore admin (2.6): Icons. Asks for each listing in turn (three at a time) and shows the
// icon in its newest release beside the one it has.
(function () {
  const form = document.querySelector('[data-ic]');
  if (!form) return;
  const rows = [...form.querySelectorAll('[data-ic-row]')];
  const status = form.querySelector('[data-ic-status]');
  const take = form.querySelector('[data-ic-take]');
  let run = 0;
  const count = () => {
    const ticked = form.querySelectorAll('input[name="ids[]"]:checked').length;
    take.disabled = ticked === 0;
    take.textContent = ticked ? 'Take ' + ticked + (ticked === 1 ? ' icon' : ' icons') : 'Take the ticked icons';
  };
  form.addEventListener('change', count);
  const one = async (li, me) => {
    const box = li.querySelector('input'), why = li.querySelector('[data-ic-why]'), img = li.querySelector('.ic-new');
    li.className = 'wait'; why.textContent = 'Asking GitHub…'; box.checked = false; box.disabled = true; img.hidden = true;
    let r;
    try {
      const res = await fetch('icons.php?check=' + encodeURIComponent(li.dataset.icRow), {credentials: 'same-origin'});
      r = await res.json();
    } catch (e) { r = {state: 'err', why: 'GitHub didn\'t answer. Check again in a moment.'}; }
    if (me !== run) return null;
    li.className = r.state;
    if (r.icon) { img.src = r.icon; img.hidden = false; }
    if (r.state === 'new') {
      box.disabled = false;
      box.checked = li.dataset.own !== '1';
      why.textContent = (r.why || 'A different icon in its release') + (r.version ? ' (' + r.version + ')' : '') + (li.dataset.own === '1' ? '. Yours: tick it to take it.' : '.');
    } else if (r.state === 'same') why.textContent = 'Same icon as its release' + (r.version ? ' (' + r.version + ')' : '') + '.';
    else why.textContent = r.why || 'Couldn\'t check.';
    return r.state;
  };
  const all = async () => {
    const me = ++run;
    let i = 0, done = 0, found = 0;
    status.textContent = 'Asking GitHub… 0 of ' + rows.length;
    const worker = async () => {
      while (i < rows.length && me === run) {
        const li = rows[i++];
        const st = await one(li, me);
        if (me !== run) return;
        if (st === 'new') found++;
        status.textContent = 'Asking GitHub… ' + (++done) + ' of ' + rows.length;
        count();
      }
    };
    await Promise.all([worker(), worker(), worker()]);
    if (me !== run) return;
    status.textContent = found ? found + (found === 1 ? ' listing has' : ' listings have') + ' a different icon in its newest release.' : 'Every icon matches its release.';
    count();
  };
  form.closest('main').querySelector('[data-ic-again]').addEventListener('click', all);
  form.querySelector('[data-ic-all]').addEventListener('click', () => { rows.forEach(li => { if (li.className === 'new') li.querySelector('input').checked = true; }); count(); });
  form.querySelector('[data-ic-none]').addEventListener('click', () => { form.querySelectorAll('input[name="ids[]"]').forEach(b => b.checked = false); count(); });
  all();
})();
