<?php
// RecompStore admin (3.1): developer docs. Markdown pages approved developers read in their hub's
// Docs tab: for PC (RecompLauncher), PS5 (RecompStore) or everyone. Drafts only show here.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_dev_tables();
$db = rs_db();
$link = fn(string $slug) => 'https://recompstore.com/hub?tab=docs&doc=' . rawurlencode($slug);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = 'docs.php';
    try {
        if ($action === 'save') {
            $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 160));
            if ($title === '') throw new RuntimeException('Give it a title.');
            $slug = rs_dev_doc_slug((string)($_POST['slug'] ?? '') ?: $title);
            if ($slug === '') throw new RuntimeException('Give it a link name with letters or numbers.');
            $dupe = $db->prepare('SELECT id FROM dev_docs WHERE slug = ? AND id <> ?');
            $dupe->execute([$slug, $id]);
            if ($dupe->fetch()) throw new RuntimeException('Another doc already uses the link name "' . $slug . '".');
            $aud = in_array($_POST['audience'] ?? '', ['pc', 'ps5', 'all'], true) ? (string)$_POST['audience'] : 'all';
            $vals = [$slug, $title, trim(mb_substr((string)($_POST['summary'] ?? ''), 0, 300)), $aud, str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')),
                (int)($_POST['sort'] ?? 0), !empty($_POST['published']) ? 1 : 0];
            if ($id) {
                $db->prepare('UPDATE dev_docs SET slug = ?, title = ?, summary = ?, audience = ?, body = ?, sort = ?, published = ?, updated = NOW() WHERE id = ?')->execute([...$vals, $id]);
            } else {
                $db->prepare('INSERT INTO dev_docs (slug, title, summary, audience, body, sort, published, updated) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')->execute($vals);
                $id = (int)$db->lastInsertId();
            }
            rs_log('developers', 'doc', $title . ($vals[6] ? ' (published)' : ' (draft)'));
            rs_flash($vals[6] ? 'Saved. It\'s in developers\' Docs tab.' : 'Saved as a draft. Only you can see it.');
            $back = 'docs.php?edit=' . $id;
        } elseif ($action === 'delete') {
            $db->prepare('DELETE FROM dev_docs WHERE id = ?')->execute([$id]);
            rs_flash('Deleted.');
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
        if ($id) $back = 'docs.php?edit=' . $id;
    }
    header('Location: ' . $back);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $db->prepare('SELECT * FROM dev_docs WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$new = isset($_GET['new']);
$docs = $db->query('SELECT id, slug, title, summary, audience, sort, published, updated FROM dev_docs ORDER BY FIELD(audience, \'pc\', \'ps5\', \'all\'), sort, id')->fetchAll();
$when = fn(string $c) => rs_date('M j, Y', strtotime($c));

rs_head($edit ? $edit['title'] . ' · Docs' : 'Docs', true, ['assets/docs.css']);
?>
<?php if ($edit || $new): $d = $edit ?: ['id' => 0, 'slug' => '', 'title' => '', 'summary' => '', 'audience' => 'pc', 'body' => '', 'sort' => (count($docs) + 1) * 10, 'published' => 0]; ?>
<section class="head-row">
  <div>
    <p class="muted"><a href="docs.php">← All docs</a></p>
    <h1><?= $edit ? h($d['title']) : 'New doc' ?></h1>
  </div>
  <?php if ($edit): ?><div class="actions"><button type="button" class="btn" data-copy-link="<?= h($link($d['slug'])) ?>">Copy its link</button></div><?php endif; ?>
</section>
<form method="post" class="card doc-form" id="doc-form">
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
  <div class="doc-fields">
    <label class="doc-title">Title<input name="title" maxlength="160" required value="<?= h($d['title']) ?>"></label>
    <label>Link name<input name="slug" maxlength="80" value="<?= h($d['slug']) ?>" placeholder="made from the title" spellcheck="false"><small>hub?tab=docs&amp;doc=<span data-slug-out><?= h($d['slug']) ?></span></small></label>
    <label>For<select name="audience"><?php foreach (RS_DEV_DOC_AUDIENCES as $k => $v): ?><option value="<?= h($k) ?>"<?= $d['audience'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select><small>PS5 developers don't see PC docs, and the other way round.</small></label>
    <label>Order<input name="sort" type="number" step="1" value="<?= (int)$d['sort'] ?>"><small>Lower comes first.</small></label>
    <label class="doc-summary">Summary<input name="summary" maxlength="300" value="<?= h($d['summary']) ?>" placeholder="One line under the title in the list"></label>
  </div>
  <div class="doc-edit">
    <div class="doc-pane">
      <div class="doc-pane-head"><strong>Markdown</strong><label class="btn doc-load">Load a .md file<input type="file" accept=".md,.markdown,.txt,text/markdown,text/plain" hidden></label></div>
      <textarea name="body" spellcheck="true" data-body><?= h($d['body']) ?></textarea>
      <small class="muted">## Heading, ### Smaller heading, - list, 1. steps, **bold**, `code`, ```json for code blocks, | tables |, [link](https://…). Headings make the "On this page" list.</small>
    </div>
    <div class="doc-pane">
      <div class="doc-pane-head"><strong>How it looks</strong></div>
      <article class="md doc-preview" data-preview></article>
    </div>
  </div>
  <div class="doc-save">
    <label class="check"><input type="checkbox" name="published" value="1"<?= (int)$d['published'] ? ' checked' : '' ?>> Published: developers see it in their Docs tab</label>
    <button class="primary">Save</button>
  </div>
</form>
<?php if ($edit): ?>
<form method="post" class="doc-delete" onsubmit="return confirm('Delete this doc for good?');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button name="action" value="delete" class="danger">Delete this doc</button></form>
<?php endif; ?>
<?php else: ?>
<section class="head-row">
  <div>
    <h1>Docs</h1>
    <p class="muted">Guides approved developers read in their hub's Docs tab. Each one is for PC, PS5 or everyone, and developers only see the ones for what they make. They can download any doc as Markdown or a PDF.</p>
  </div>
  <div class="actions"><a class="btn primary" href="docs.php?new=1">New doc</a></div>
</section>
<?php foreach (RS_DEV_DOC_AUDIENCES as $k => $label): $list = array_values(array_filter($docs, fn($x) => $x['audience'] === $k)); ?>
<section class="card doc-group">
  <h2><?= h($label) ?> <span class="muted"><?= count($list) ?></span></h2>
  <?php if (!$list): ?><p class="muted">None yet.</p><?php endif; ?>
  <ol class="doc-list">
    <?php foreach ($list as $i => $x): ?>
    <li>
      <span class="doc-n"><?= $i + 1 ?></span>
      <div class="doc-words"><a href="docs.php?edit=<?= (int)$x['id'] ?>"><strong><?= h($x['title']) ?></strong></a><span class="muted"><?= h($x['summary']) ?></span></div>
      <span class="pill<?= (int)$x['published'] ? ' ok' : '' ?>"><?= (int)$x['published'] ? 'Published' : 'Draft' ?></span>
      <span class="muted doc-when"><?= h($when((string)$x['updated'])) ?></span>
      <span class="doc-btns"><button type="button" class="btn" data-copy-link="<?= h($link($x['slug'])) ?>">Copy link</button><a class="btn" href="docs.php?edit=<?= (int)$x['id'] ?>">Edit</a></span>
    </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endforeach; ?>
<?php endif; ?>
<script src="/assets/hub-md.js?v=<?= (int)@filemtime(RS_PUBLIC . '/assets/hub-md.js') ?>"></script>
<script>
// The preview, the link name from the title, a .md file into the editor, and Copy link.
(() => {
  const f = document.getElementById('doc-form');
  document.querySelectorAll('[data-copy-link]').forEach((b) => b.addEventListener('click', () => {
    navigator.clipboard.writeText(b.dataset.copyLink).then(() => { const t = b.textContent; b.textContent = 'Copied'; setTimeout(() => { b.textContent = t; }, 1500); });
  }));
  if (!f) return;
  const body = f.querySelector('[data-body]'), prev = f.querySelector('[data-preview]');
  const slugOf = (v) => v.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  const draw = () => { prev.innerHTML = window.RSmd ? window.RSmd(body.value, { idPrefix: 'p-' }).html : ''; };
  let tm = 0;
  body.addEventListener('input', () => { clearTimeout(tm); tm = setTimeout(draw, 150); });
  draw();
  const out = f.querySelector('[data-slug-out]');
  const syncSlug = () => { out.textContent = f.slug.value.trim() ? slugOf(f.slug.value) : slugOf(f.title.value); };
  f.title.addEventListener('input', syncSlug);
  f.slug.addEventListener('input', syncSlug);
  f.querySelector('.doc-load input').addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    file.text().then((txt) => {
      // A title line at the top becomes the title, if there isn't one yet.
      const m = txt.match(/^#\s+(.+)\n+/);
      if (m && !f.title.value.trim()) { f.title.value = m[1].trim(); txt = txt.slice(m[0].length); syncSlug(); }
      body.value = txt;
      draw();
    });
  });
})();
</script>
<?php rs_foot();
