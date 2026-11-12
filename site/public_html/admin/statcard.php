<?php
// RecompStore admin (4.8): a stats card to send to sponsors and partners. Pick the numbers, the
// time span, the brand and the size; the card is drawn in the browser and saved as a PNG.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/lstats.php';
rs_require_login();

if (isset($_GET['data'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $days = (int)($_GET['days'] ?? 30);
    if (!in_array($days, [1, 7, 30, 90, 0], true)) $days = 30;
    $key = 'statcard_' . $days;
    $c = rs_kv_get($key);
    if ($c && $c['age'] < 120) { echo $c['v']; exit; }
    $db = rs_db();
    $one = function (string $sql, array $a = []) use ($db) { try { $st = $db->prepare($sql); $st->execute($a); return (float)$st->fetchColumn(); } catch (Throwable $e) { return 0.0; } };
    $from = $days ? gmdate('Y-m-d', time() - ($days - 1) * 86400) : '2000-01-01';
    $live = (function () { try { return rs_ls_live(); } catch (Throwable $e) { return ['open' => 0, 'playing' => 0]; } })();
    $sum = (function () { try { return rs_ls_summary(); } catch (Throwable $e) { return []; } })();
    $perDay = function (string $sql) use ($db, $from) { try { $st = $db->prepare($sql); $st->execute([$from]); return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { return []; } };
    $play = $perDay('SELECT COUNT(*) FROM lstat_days WHERE day >= ? GROUP BY day');
    $vis = $perDay('SELECT COUNT(*) FROM site_visitors WHERE day >= ? GROUP BY day');
    $avg = fn(array $x) => $x ? (int)round(array_sum($x) / count($x)) : 0;
    $out = [
        'acc_total' => (int)$one('SELECT COUNT(*) FROM accounts'),
        'acc_new' => (int)$one('SELECT COUNT(*) FROM accounts WHERE created >= ?', [$from]),
        'players' => (int)$one('SELECT COUNT(DISTINCT ih) FROM lstat_days WHERE day >= ?', [$from]),
        'players_daily' => $avg($play),
        'players_peak' => $play ? max($play) : 0,
        'open_now' => (int)($live['open'] ?? 0),
        'installs' => (int)($sum['installs'] ?? 0),
        'hours' => (int)round($one('SELECT COALESCE(SUM(seconds), 0) FROM lstat_sessions WHERE started >= ?', [$from]) / 3600),
        'visitors_daily' => $avg($vis),
        'visits' => array_sum($vis),
        'page_views' => (int)$one('SELECT COALESCE(SUM(views), 0) FROM site_pages WHERE day >= ?', [$from]),
        'pc_recomps' => count(array_filter(rs_pc_apps(), fn($a) => empty($a['hidden']) && empty($a['soon']))),
        'ps5_apps' => count(array_filter(rs_games(), fn($g) => empty($g['hidden']))),
        'consoles' => (int)$one('SELECT COUNT(DISTINCT install_id) FROM console_days WHERE day >= ?', [$from]),
        'cloud_saves' => (int)$one('SELECT COUNT(*) FROM cloud_saves'),
        'days' => $days,
        'from' => $from,
    ];
    $json = (string)json_encode($out);
    rs_kv_put($key, $json);
    echo $json;
    exit;
}

rs_head('Stats card');
?>
<div class="ov-head"><h1>Stats card</h1><span class="muted small">Pick what to show, then save it as a picture to send to sponsors and partners.</span></div>
<div class="sc-wrap">
  <section class="card sc-opts">
    <h2>Numbers</h2>
    <div class="sc-checks" data-metrics>
      <?php foreach ([
          ['open_now', 'In the launcher right now', 1], ['players', 'Players (unique)', 1], ['players_daily', 'Daily players (average)', 1], ['players_peak', 'Best day', 0],
          ['acc_new', 'New accounts', 1], ['acc_total', 'Accounts in total', 1], ['installs', 'Launcher installs, all time', 0], ['hours', 'Hours played', 1],
          ['visitors_daily', 'Website visitors a day', 0], ['visits', 'Website visits', 0], ['page_views', 'Page views', 0], ['pc_recomps', 'PC recomps listed', 1],
          ['ps5_apps', 'PS5 games and apps', 0], ['consoles', 'PS5 consoles', 0], ['cloud_saves', 'Cloud saves stored', 0],
      ] as [$k, $label, $on]): ?>
      <label class="check"><input type="checkbox" value="<?= h($k) ?>"<?= $on ? ' checked' : '' ?>> <?= h($label) ?></label>
      <?php endforeach; ?>
    </div>
    <h2 style="margin-top:14px">Time span</h2>
    <div class="seg-row" data-days>
      <?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days', 0 => 'All time'] as $d => $n): ?><button type="button" data-v="<?= $d ?>"<?= $d === 30 ? ' class="on"' : '' ?>><?= $n ?></button><?php endforeach; ?>
    </div>
    <h2 style="margin-top:14px">Look</h2>
    <label>Brand<select data-brand><option value="RecompLauncher">RecompLauncher</option><option value="RecompStore">RecompStore</option><option value="both">RecompLauncher + RecompStore</option></select></label>
    <label>Size<select data-size><option value="1200x675">Landscape (1200 × 675)</option><option value="1080x1080">Square (1080 × 1080)</option><option value="1080x1350">Portrait (1080 × 1350)</option></select></label>
    <label>Colors<select data-theme><option value="dark">Dark</option><option value="light">Light</option></select></label>
    <label>Headline<input data-title value="By the numbers" maxlength="60"></label>
    <label>Line under it<input data-sub value="" maxlength="90" placeholder="The date span goes here if you leave it empty"></label>
    <div class="actions"><button class="primary" type="button" data-save>Save as PNG</button><button type="button" data-copy>Copy picture</button></div>
    <p class="muted small" data-note></p>
  </section>
  <section class="sc-preview"><canvas data-canvas aria-label="Stats card preview"></canvas></section>
</div>
<style>
.sc-wrap{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap}
.sc-opts{flex:0 0 320px}
.sc-checks{display:flex;flex-direction:column;gap:2px}
.sc-checks label.check{margin:0;font-weight:500;font-size:13px;gap:8px}
.sc-checks input[type=checkbox]{width:16px;height:16px}
.seg-row{display:flex;gap:4px;flex-wrap:wrap}
.seg-row button{min-height:30px;padding:4px 10px;font-size:12.5px}
.seg-row button.on{background:var(--cyan);color:var(--on-accent);border-color:var(--cyan)}
.sc-preview{flex:1;min-width:300px;position:sticky;top:70px}
.sc-preview canvas{width:100%;height:auto;border-radius:10px;border:1px solid var(--line);display:block}
</style>
<script>
(function () {
  var cv = document.querySelector('[data-canvas]'), ctx = cv.getContext('2d');
  var days = 30, data = null, logo = new Image(), logoOk = false;
  logo.onload = function () { logoOk = true; draw(); };
  logo.src = '/assets/mark.svg';
  var labels = {
    open_now: 'in the launcher right now', players: 'players', players_daily: 'players a day', players_peak: 'players on the best day',
    acc_new: 'new accounts', acc_total: 'accounts', installs: 'launcher installs', hours: 'hours played', visitors_daily: 'website visitors a day',
    visits: 'website visits', page_views: 'page views', pc_recomps: 'PC recomps', ps5_apps: 'PS5 games and apps', consoles: 'PS5 consoles', cloud_saves: 'cloud saves'
  };
  var q = function (s) { return document.querySelector(s); };
  var short = function (n) { n = +n || 0; if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M'; if (n >= 1e4) return (n / 1e3).toFixed(n >= 1e5 ? 0 : 1).replace(/\.0$/, '') + 'K'; return n.toLocaleString('en-US'); };
  var span = function () {
    if (!data) return '';
    var f = function (d) { return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); };
    var to = new Date();
    if (days === 1) return 'Today, ' + f(to);
    if (days === 0) return 'All time, through ' + f(to);
    return 'Last ' + days + ' days \u00b7 ' + f(new Date(data.from + 'T12:00:00Z')) + ' \u2013 ' + f(to);
  };
  var round = function (x, y, w, h, r) { ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath(); };
  function draw() {
    var sz = q('[data-size]').value.split('x'), W = +sz[0], H = +sz[1], dark = q('[data-theme]').value === 'dark';
    cv.width = W; cv.height = H;
    var bg = dark ? '#0b0d10' : '#f6f8fb', fg = dark ? '#f2f4f6' : '#0f1a2a', mu = dark ? '#9aa3ae' : '#5b6778', panel = dark ? '#14181e' : '#ffffff', line = dark ? '#262c35' : '#e2e7ee', acc = dark ? '#34F2E8' : '#06757E';
    ctx.fillStyle = bg; ctx.fillRect(0, 0, W, H);
    var g = ctx.createRadialGradient(W * 0.85, -H * 0.1, 0, W * 0.85, -H * 0.1, W * 0.8);
    g.addColorStop(0, dark ? 'rgba(52,242,232,0.14)' : 'rgba(44,217,208,0.16)'); g.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    var pad = Math.round(W * 0.06), y = pad;
    var ls = Math.round(W * 0.045);
    if (logoOk) ctx.drawImage(logo, pad, y, ls, ls);
    var brand = q('[data-brand]').value, bx = pad + ls + Math.round(ls * 0.35);
    ctx.textBaseline = 'middle'; ctx.fillStyle = fg; ctx.font = '700 ' + Math.round(ls * 0.62) + 'px Inter, sans-serif';
    var names = brand === 'both' ? ['RecompLauncher', ' + RecompStore'] : [brand];
    ctx.fillText(names[0], bx, y + ls / 2);
    if (names[1]) { ctx.fillStyle = mu; ctx.fillText(names[1], bx + ctx.measureText(names[0]).width, y + ls / 2); }
    y += ls + Math.round(H * 0.07);
    ctx.textBaseline = 'alphabetic'; ctx.fillStyle = fg; ctx.font = '700 ' + Math.round(W * 0.05) + 'px Inter, sans-serif';
    ctx.fillText(q('[data-title]').value || 'By the numbers', pad, y);
    y += Math.round(W * 0.035);
    ctx.fillStyle = mu; ctx.font = '500 ' + Math.round(W * 0.02) + 'px Inter, sans-serif';
    ctx.fillText(q('[data-sub]').value || span(), pad, y);
    y += Math.round(H * 0.06);
    var picked = Array.prototype.map.call(document.querySelectorAll('[data-metrics] input:checked'), function (i) { return i.value; });
    var n = picked.length;
    if (!n || !data) { ctx.fillStyle = mu; ctx.fillText(data ? 'Pick some numbers' : 'Loading…', pad, y + 40); return; }
    // Rows as even as they can be, every row filling the width: never one box left alone on a row.
    var C = W > H * 1.2 ? 4 : 3, sizes;
    for (;;) {
      var rowsN = Math.ceil(n / C), base = Math.floor(n / rowsN), extra = n % rowsN;
      sizes = []; for (var r0 = 0; r0 < rowsN; r0++) sizes.push(base + (r0 < extra ? 1 : 0));
      if (n === 1 || Math.min.apply(null, sizes) > 1 || C >= 5) break;
      C++;
    }
    var rows = sizes.length, gap = Math.round(W * 0.018), foot = Math.round(H * 0.08);
    var ch = Math.min((H - y - pad - foot - gap * (rows - 1)) / rows, (W - pad * 2) / Math.max.apply(null, sizes) * 0.7);
    var fit = function (text, weight, size, max) { do { ctx.font = weight + ' ' + Math.round(size) + 'px Inter, sans-serif'; size *= 0.94; } while (ctx.measureText(text).width > max && size > 8); };
    var i = 0;
    sizes.forEach(function (inRow, r) {
      var cw = (W - pad * 2 - gap * (inRow - 1)) / inRow, yy = y + r * (ch + gap);
      for (var c = 0; c < inRow; c++, i++) {
        var k = picked[i], x = pad + c * (cw + gap);
        ctx.fillStyle = panel; round(x, yy, cw, ch, Math.round(W * 0.012)); ctx.fill();
        ctx.strokeStyle = line; ctx.lineWidth = 2; ctx.stroke();
        var big = Math.min(ch * 0.42, cw * 0.3), inner = cw * 0.84;
        ctx.fillStyle = k === 'open_now' ? acc : fg; fit(short(data[k]), '700', big, inner);
        ctx.fillText(short(data[k]), x + cw * 0.08, yy + ch * 0.55);
        ctx.fillStyle = mu; fit(labels[k], '500', Math.min(big * 0.34, W * 0.022), inner);
        ctx.fillText(labels[k], x + cw * 0.08, yy + ch * 0.8);
      }
    });
    ctx.fillStyle = mu; ctx.font = '500 ' + Math.round(W * 0.016) + 'px Inter, sans-serif';
    ctx.fillText(brand === 'RecompStore' ? 'recompstore.com' : brand === 'both' ? 'recomplauncher.com \u00b7 recompstore.com' : 'recomplauncher.com', pad, H - pad * 0.7);
  }
  function load() {
    q('[data-note]').textContent = 'Loading the numbers…';
    fetch('statcard.php?data=1&days=' + days, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      data = j; q('[data-note]').textContent = ''; draw();
    }).catch(function () { q('[data-note]').textContent = 'Couldn\'t load the numbers. Refresh the page.'; });
  }
  document.querySelectorAll('[data-days] button').forEach(function (b) {
    b.addEventListener('click', function () { days = +b.getAttribute('data-v'); document.querySelectorAll('[data-days] button').forEach(function (x) { x.classList.toggle('on', x === b); }); load(); });
  });
  document.querySelectorAll('[data-metrics] input, [data-brand], [data-size], [data-theme]').forEach(function (el) { el.addEventListener('change', draw); });
  document.querySelectorAll('[data-title], [data-sub]').forEach(function (el) { el.addEventListener('input', draw); });
  q('[data-save]').addEventListener('click', function () {
    var a = document.createElement('a');
    a.download = 'recomp-stats-' + new Date().toISOString().slice(0, 10) + '.png';
    a.href = cv.toDataURL('image/png'); a.click();
  });
  q('[data-copy]').addEventListener('click', function () {
    cv.toBlob(function (b) {
      if (!navigator.clipboard || !window.ClipboardItem) { q('[data-note]').textContent = 'This browser can\'t copy pictures. Use Save as PNG.'; return; }
      navigator.clipboard.write([new ClipboardItem({ 'image/png': b })]).then(function () { q('[data-note]').textContent = 'Copied. Paste it into an email or a message.'; });
    });
  });
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(load);
})();
</script>
<?php rs_foot(); ?>
