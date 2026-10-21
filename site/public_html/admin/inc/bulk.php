<?php
// Recomp Store admin (2.3): several games at once, from the Games list. Tick games, pick what to
// do (show or hide, testers only or not, featured or not, another category) and press Apply: one
// confirm, then each game is saved as if edited, with a change-log line for each. Nothing is
// published; Publish still sends it out.
declare(strict_types=1);

function rs_bulk_ops(): array {
    $ops = ['show' => 'Show', 'hide' => 'Hide', 'testers_on' => 'Testers only', 'testers_off' => 'Everyone (not testers only)',
        'featured_on' => 'Featured on the banner', 'featured_off' => 'Not featured'];
    foreach (RS_CATEGORIES as $k => $label) $ops['cat:' . $k] = 'Move to ' . $label;
    return $ops;
}

// The Apply button's form (index.php posts action=bulk here). Redirects back to the list.
function rs_bulk_apply(): void {
    $ops = rs_bulk_ops();
    $op = (string)($_POST['op'] ?? '');
    $cat = (string)($_POST['cat'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map(fn($x) => is_string($x) ? $x : '', (array)($_POST['ids'] ?? [])), fn($x) => $x !== '')));
    $back = 'index.php' . (isset(RS_CATEGORIES[$cat]) ? '?cat=' . urlencode($cat) : '');
    if (!isset($ops[$op])) { rs_flash('Pick what to do with the games you ticked.', 'err'); header('Location: ' . $back); exit; }
    if (!$ids) { rs_flash('Tick one or more games first.', 'err'); header('Location: ' . $back); exit; }
    $changed = $same = 0;
    $names = [];
    foreach (array_slice($ids, 0, 500) as $id) {
        $g = rs_game($id);
        if (!$g) continue;
        $before = $g;
        if ($op === 'show') $g['hidden'] = false;
        elseif ($op === 'hide') $g['hidden'] = true;
        elseif ($op === 'testers_on') $g['testers'] = true;
        elseif ($op === 'testers_off') $g['testers'] = false;
        elseif ($op === 'featured_on') $g['featured'] = true;
        elseif ($op === 'featured_off') $g['featured'] = false;
        elseif (str_starts_with($op, 'cat:')) $g['category'] = rs_category(substr($op, 4));
        if ($g == $before) { ++$same; continue; }
        rs_save_game($g);
        rs_log('change', 'index.php ' . mb_substr($id, 0, 40), 'bulk: ' . $ops[$op]);
        ++$changed;
        $names[] = $g['name'] !== '' ? $g['name'] : $id;
    }
    $what = $changed === 1 ? $names[0] : $changed . ' games';
    $msg = $changed ? $ops[$op] . ': ' . $what . ' saved.' . ($same ? ' ' . $same . ($same === 1 ? ' was' : ' were') . ' already like that.' : '') . ' Press Publish to send it to the store and the site.'
        : 'Nothing to change: ' . ($same === 1 ? 'that game was' : 'they were all') . ' already like that.';
    rs_flash($msg);
    header('Location: ' . $back);
    exit;
}

// A game's tick box on the list (it belongs to the bulk form below the list).
function rs_bulk_check(array $g): string {
    return '<label class="bulk-pick"><input type="checkbox" form="bulk" name="ids[]" value="' . h($g['id']) . '" data-bulk-pick><span class="sr">Select ' . h($g['name'] ?: $g['id']) . '</span></label>';
}

function rs_bulk_bar(string $cat): string {
    $o = '<form method="post" id="bulk" class="sticky-save bulk-bar" data-bulk-bar hidden>'
        . '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '"><input type="hidden" name="action" value="bulk"><input type="hidden" name="cat" value="' . h($cat) . '">'
        . '<strong data-bulk-count>0 selected</strong>'
        . '<label class="bulk-op"><span class="sr">What to do</span><select name="op" required><option value="">Choose what to do…</option>';
    $ops = rs_bulk_ops();
    foreach (['Visibility' => ['show', 'hide', 'testers_on', 'testers_off'], 'Banner' => ['featured_on', 'featured_off']] as $group => $keys) {
        $o .= '<optgroup label="' . h($group) . '">';
        foreach ($keys as $k) $o .= '<option value="' . h($k) . '">' . h($ops[$k]) . '</option>';
        $o .= '</optgroup>';
    }
    $o .= '<optgroup label="Category">';
    foreach (RS_CATEGORIES as $k => $label) $o .= '<option value="cat:' . h($k) . '">' . h($ops['cat:' . $k]) . '</option>';
    $o .= '</optgroup></select></label>'
        . '<button class="primary">Apply</button><button type="button" data-bulk-all>Select all shown</button><button type="button" data-bulk-none>Clear</button>'
        . '<span class="muted">Saves each game. Doesn\'t publish.</span></form>';
    return $o;
}
