<?php
// (4.11) What the drafter found in a project, feature by feature, with what the pull request does with each.
// Shared by the PR queue and Check a game's test drafts.
declare(strict_types=1);

function rs_prq_found_html(?array $pqFound, string $repo): string {
    if (!is_array($pqFound) || (empty($pqFound['features']) && empty($pqFound['options']))) return '';
    ob_start(); ?>
      <div class="prq-found">
        <h4>Features <span class="muted small">what the project has, where, and what this pull request does with each</span></h4>
        <ol class="prq-steps">
        <?php foreach ((array)($pqFound['features'] ?? []) as $f): if (!is_array($f) || trim((string)($f['what'] ?? '')) === '') continue;
            $has = $f['has'] ?? null; $isGame = strtolower(trim((string)$f['what'])) === 'game location';
            $notNeeded = $has === false && array_key_exists('needed', $f) && $f['needed'] === false; // (4.10)
            // (4.10) Only in the game's own menus: nothing the launcher can pair with the json, so no tick.
            $inGame = $has === true && empty($f['used']) && (($f['hook'] ?? null) === false
                || (!array_key_exists('hook', $f) && preg_match('/only in the in-game|in-game (settings )?menu|didn\'t see the settings file key or a command-line option|no (command-line )?(flag|option|key) for it/i', (string)($f['how'] ?? '') . ' ' . (string)($f['why'] ?? ''))));
            if ($inGame) $has = 'game';
            // (4.10) A tick means it's in the json (or this pull request adds it); there but unused gets none.
            $inJson = $has === true && (!empty($f['used']) || !empty($f['added']) || str_starts_with(trim((string)($f['how'] ?? '')), 'Added in this pull request'));
            if ($has === true && !$inJson) $has = 'unused';
            $cls = $has === true ? 'ok' : ($has === 'unused' ? 'wait' : ($has === false || $has === 'game' ? ($notNeeded || $has === 'game' ? 'no' : ($isGame ? 'bad' : 'no')) : 'wait')); ?>
          <li class="prq-s-<?= $cls ?>"><span class="prq-s-i"><?= $has === true ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>' : ($has === false && $isGame ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M7 7l10 10M17 7 7 17"/></svg>' : '–') ?></span>
            <span><b><?= h((string)$f['what']) ?><?php // (4.10) What this pull request does with it
                $added = !empty($f['added']) || str_starts_with(trim((string)($f['how'] ?? '')), 'Added in this pull request');
                if ($has === true && $added): ?> <span class="pill prq-p-add">Feature added with this PR</span><?php elseif ($has === true && !empty($f['used'])): ?> <span class="pill ok">Flag exists, added to json</span><?php elseif ($has === 'unused'): ?> <span class="pill warn">Flag exists, not in json</span><?php elseif ($has === 'game'): ?> <span class="pill">In-game only, nothing to pair with the json</span><?php elseif ($notNeeded): ?> <span class="pill">Not needed</span><?php elseif ($has === false): ?> <span class="pill warn">Missing, not in this PR</span><?php endif; ?></b>
            <small><?= h(trim((string)($f['how'] ?? '')) !== '' ? (string)$f['how'] : ($has === false ? (trim((string)($f['why'] ?? '')) !== '' ? (string)$f['why'] : 'Not found') : 'Couldn\'t tell')) ?><?php if (($has === false || $has === 'unused') && trim((string)($f['how'] ?? '')) !== '' && trim((string)($f['why'] ?? '')) !== ''): ?> · <?= h((string)$f['why']) ?><?php endif; ?><?php $w = rs_prq_where($repo, (string)($f['where'] ?? '')); if ($w !== ''): ?> · <?= $w ?><?php endif; ?></small></span></li>
        <?php endforeach; ?>
        </ol>
        <?php $pqOpts = array_values(array_filter((array)($pqFound['options'] ?? []), fn($o) => is_array($o) && trim((string)($o['flag'] ?? '')) !== '')); if ($pqOpts): ?>
        <h4 class="prq-h4b">Command-line options it reads <span class="muted small"><?= count($pqOpts) ?></span></h4>
        <dl class="prq-facts"><?php foreach ($pqOpts as $o): ?><div><dt class="mono"><?= h((string)$o['flag']) ?></dt><dd><?= h((string)($o['does'] ?? '')) ?><?php $w = rs_prq_where($repo, (string)($o['where'] ?? '')); if ($w !== ''): ?><span class="prq-where small"><?= $w ?></span><?php endif; ?></dd></div><?php endforeach; ?></dl>
        <?php endif; ?>
      </div>
      <?php
    return (string)ob_get_clean();
}
