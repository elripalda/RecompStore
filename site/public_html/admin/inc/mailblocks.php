<?php
// RecompStore admin (2.7.2): the email builder. An email is a list of blocks (heading, text, button,
// app card, latest apps, image, divider, spacer), turned into HTML that looks right in mail apps
// (inline styles, tables where they matter) and into plain text for the text part. {name} becomes
// each person's name, {invite} their own developer invite link.
declare(strict_types=1);

const RS_MB_TYPES = ['heading', 'text', 'button', 'app', 'apps', 'image', 'divider', 'spacer'];
const RS_MB_FONT = '-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif';

// Blocks as they come from the builder, cleaned: only known types and fields, sizes capped.
function rs_mb_clean(mixed $in): array {
    $out = [];
    foreach (is_array($in) ? array_slice($in, 0, 60) : [] as $b) {
        if (!is_array($b) || !in_array($b['t'] ?? '', RS_MB_TYPES, true)) continue;
        $s = fn(string $k, int $max) => mb_substr(trim(str_replace("\r\n", "\n", (string)($b[$k] ?? ''))), 0, $max);
        $url = function (string $k) use ($b): string {
            $u = trim((string)($b[$k] ?? ''));
            return $u === '{invite}' || preg_match('#^(https://|mailto:)[^\s<>"\']{3,500}$#', $u) ? $u : '';
        };
        $c = ['t' => $b['t']];
        switch ($b['t']) {
            case 'heading': $c['text'] = $s('text', 200); $c['size'] = in_array($b['size'] ?? '', ['big', 'small'], true) ? $b['size'] : 'big'; break;
            case 'text': $c['text'] = $s('text', 8000); break;
            case 'button': $c['label'] = $s('label', 80); $c['url'] = $url('url'); $c['style'] = ($b['style'] ?? '') === 'plain' ? 'plain' : 'main'; break;
            case 'app': $c['id'] = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['id'] ?? '')) ?? ''; $c['big'] = !empty($b['big']); $c['note'] = $s('note', 600); break;
            case 'apps': $c['mode'] = in_array($b['mode'] ?? '', ['new', 'updated', 'top'], true) ? $b['mode'] : 'new'; $c['count'] = max(1, min(8, (int)($b['count'] ?? 3))); $c['title'] = $s('title', 120); break;
            case 'image': $c['url'] = $url('url'); $c['alt'] = $s('alt', 200); $c['link'] = $url('link'); break;
            case 'divider': case 'spacer': break;
        }
        $out[] = $c;
    }
    return $out;
}

// The apps a block shows: one app, or the newest / most recently updated / most installed.
function rs_mb_apps(): array {
    static $apps = null;
    return $apps ??= rs_published_apps();
}
function rs_mb_list(string $mode, int $n): array {
    $apps = rs_mb_apps();
    $ids = [];
    try {
        $ch = rs_charts();
        $ids = $mode === 'top' ? array_column((array)($ch['top'] ?? []), 'id') : (array)($ch[$mode] ?? []);
    } catch (Throwable $e) {
        $ids = [];
    }
    if (!$ids) { // no charts yet: by release date
        $all = $apps;
        uasort($all, fn($a, $b) => strcmp((string)($b['released'] ?? ''), (string)($a['released'] ?? '')));
        $ids = array_keys($all);
    }
    $out = [];
    foreach ($ids as $id) { if (isset($apps[(string)$id])) $out[] = $apps[(string)$id]; if (count($out) >= $n) break; }
    return $out;
}

function rs_mb_vars(string $s, array $v): string {
    return strtr($s, ['{name}' => (string)($v['name'] ?? 'there'), '{invite}' => (string)($v['invite'] ?? 'https://recompstore.com/developers')]);
}

// Text with paragraphs, **bold** and links, as email HTML.
function rs_mb_para(string $t, string $color = '#1b2430'): string {
    $out = '';
    foreach (preg_split('/\n\s*\n/', trim($t)) as $p) {
        if (trim($p) === '') continue;
        $h = htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8');
        $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h) ?? $h;
        $h = preg_replace('#\bhttps?://[^\s<>"\']+[^\s<>"\'.,;:!?)]#', '<a href="$0" style="color:#1f6fb2">$0</a>', $h) ?? $h;
        // A line that starts with "- " is a list item.
        if (preg_match('/^- /m', $h) && preg_match_all('/^- (.+)$/m', $h, $m) === substr_count($h, "\n") + 1) {
            $out .= '<ul style="margin:0 0 16px;padding-left:22px">' . implode('', array_map(fn($x) => '<li style="margin:0 0 6px">' . $x . '</li>', $m[1])) . '</ul>';
        } else {
            $out .= '<p style="margin:0 0 16px;color:' . $color . '">' . nl2br($h, false) . '</p>';
        }
    }
    return $out;
}

function rs_mb_app_card(array $a, bool $big, string $note = ''): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $url = 'https://recompstore.com/game/' . rawurlencode((string)$a['id']);
    $icon = (string)($a['icon'] ?? '');
    $hero = (string)($a['heroWeb'] ?? '') ?: (string)($a['hero'] ?? '');
    $by = ($a['kind'] ?? '') === 'original' ? 'RecompStore' : trim((string)($a['author'] ?? ''));
    $meta = implode(' · ', array_filter([$by !== '' ? 'by ' . $by : '', ($a['version'] ?? '') !== '' ? 'v' . ltrim((string)$a['version'], 'vV') : '']));
    $blurb = trim((string)($a['blurb'] ?? ''));
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:1px solid #e3e8ef;border-radius:12px;border-collapse:separate;overflow:hidden"><tr><td style="padding:0">';
    if ($big && preg_match('#^https://#', $hero)) $html .= '<a href="' . $e($url) . '"><img src="' . $e($hero) . '" alt="" width="546" style="display:block;width:100%;height:auto;border:0"></a>';
    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>';
    if (preg_match('#^https://#', $icon)) $html .= '<td width="76" valign="top" style="padding:14px 0 14px 14px"><a href="' . $e($url) . '"><img src="' . $e($icon) . '" alt="" width="62" height="62" style="display:block;width:62px;height:62px;border-radius:14px;border:0"></a></td>';
    $html .= '<td valign="top" style="padding:14px 16px;font:15px/1.5 ' . RS_MB_FONT . ';color:#1b2430">'
        . '<a href="' . $e($url) . '" style="font-weight:700;font-size:17px;color:#0b1220;text-decoration:none">' . $e($a['name'] ?? $a['id']) . '</a>'
        . ($meta !== '' ? '<div style="font-size:13px;color:#6b7686;margin:2px 0 4px">' . $e($meta) . '</div>' : '')
        . ($blurb !== '' ? '<div>' . $e($blurb) . '</div>' : '')
        . ($note !== '' ? '<div style="margin-top:6px">' . $e($note) . '</div>' : '')
        . '<div style="margin-top:8px"><a href="' . $e($url) . '" style="color:#1f6fb2;font-weight:600;text-decoration:none">See it on RecompStore &rarr;</a></div>'
        . '</td></tr></table></td></tr></table>';
    return $html;
}

// The blocks as the inside of an email (between the logo and the footer).
function rs_mb_html(array $blocks, array $v = []): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $apps = rs_mb_apps();
    $html = '';
    foreach ($blocks as $b) {
        switch ($b['t']) {
            case 'heading':
                $big = ($b['size'] ?? 'big') === 'big';
                $html .= '<h' . ($big ? '1' : '2') . ' style="margin:0 0 14px;font:700 ' . ($big ? '26px/1.25' : '19px/1.3') . ' ' . RS_MB_FONT . ';color:#0b1220">' . $e(rs_mb_vars($b['text'], $v)) . '</h' . ($big ? '1' : '2') . '>';
                break;
            case 'text':
                $html .= rs_mb_para(rs_mb_vars($b['text'], $v));
                break;
            case 'button':
                $u = rs_mb_vars($b['url'], $v);
                if ($u === '' || $b['label'] === '') break;
                $main = ($b['style'] ?? 'main') === 'main';
                $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px auto 22px"><tr><td style="border-radius:999px;' . ($main ? 'background:#22c7d6;background-image:linear-gradient(#34f2e8,#377bb6)' : 'border:2px solid #22c7d6') . '">'
                    . '<a href="' . $e($u) . '" style="display:inline-block;padding:13px 28px;font:700 16px ' . RS_MB_FONT . ';color:' . ($main ? '#0b1220' : '#1f6fb2') . ';text-decoration:none;border-radius:999px">' . $e(rs_mb_vars($b['label'], $v)) . '</a></td></tr></table>';
                break;
            case 'app':
                if (isset($apps[$b['id']])) $html .= rs_mb_app_card($apps[$b['id']], !empty($b['big']), rs_mb_vars((string)($b['note'] ?? ''), $v));
                break;
            case 'apps':
                $list = rs_mb_list($b['mode'], (int)$b['count']);
                if (!$list) break;
                if (($b['title'] ?? '') !== '') $html .= '<h2 style="margin:8px 0 12px;font:700 19px/1.3 ' . RS_MB_FONT . ';color:#0b1220">' . $e($b['title']) . '</h2>';
                foreach ($list as $i => $a) $html .= rs_mb_app_card($a, $i === 0 && $b['mode'] !== 'top');
                break;
            case 'image':
                if ($b['url'] === '') break;
                $img = '<img src="' . $e($b['url']) . '" alt="' . $e($b['alt']) . '" width="546" style="display:block;width:100%;height:auto;border:0;border-radius:10px">';
                $html .= '<div style="margin:0 0 18px">' . ($b['link'] !== '' ? '<a href="' . $e(rs_mb_vars($b['link'], $v)) . '">' . $img . '</a>' : $img) . '</div>';
                break;
            case 'divider':
                $html .= '<hr style="border:0;border-top:1px solid #e3e8ef;margin:22px 0">';
                break;
            case 'spacer':
                $html .= '<div style="height:18px;line-height:18px">&nbsp;</div>';
                break;
        }
    }
    return $html;
}

// The same email as plain text.
function rs_mb_text(array $blocks, array $v = []): string {
    $apps = rs_mb_apps();
    $out = [];
    $card = fn(array $a) => (string)$a['name'] . (($a['blurb'] ?? '') !== '' ? ': ' . $a['blurb'] : '') . "\nhttps://recompstore.com/game/" . rawurlencode((string)$a['id']);
    foreach ($blocks as $b) {
        switch ($b['t']) {
            case 'heading': $out[] = mb_strtoupper(rs_mb_vars($b['text'], $v)); break;
            case 'text': $out[] = preg_replace('/\*\*(.+?)\*\*/s', '$1', rs_mb_vars($b['text'], $v)); break;
            case 'button': if ($b['url'] !== '') $out[] = rs_mb_vars($b['label'], $v) . ': ' . rs_mb_vars($b['url'], $v); break;
            case 'app': if (isset($apps[$b['id']])) $out[] = $card($apps[$b['id']]) . (($b['note'] ?? '') !== '' ? "\n" . rs_mb_vars($b['note'], $v) : ''); break;
            case 'apps':
                $list = rs_mb_list($b['mode'], (int)$b['count']);
                if ($list) $out[] = (($b['title'] ?? '') !== '' ? mb_strtoupper($b['title']) . "\n\n" : '') . implode("\n\n", array_map($card, $list));
                break;
            case 'image': if ($b['link'] !== '') $out[] = rs_mb_vars($b['link'], $v); break;
            case 'divider': $out[] = '---'; break;
        }
    }
    return trim(implode("\n\n", $out));
}

// A whole email from blocks: [html, text]. $sign goes under the blocks, $footer at the bottom.
function rs_mb_email(array $blocks, array $v, string $sign, string $footer): array {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $inner = rs_mb_html($blocks, $v) . ($sign !== '' ? rs_mb_para($sign, '#3b4656') : '');
    $foot = $footer !== '' ? '<div style="margin:24px 0 0;padding-top:16px;border-top:1px solid #e3e8ef;font:13px/1.5 ' . RS_MB_FONT . ';color:#6b7686">' . rs_mb_para($footer, '#6b7686') . '</div>' : '';
    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="color-scheme" content="light"></head>'
        . '<body style="margin:0;background:#f3f6f9"><div style="max-width:600px;margin:0 auto;padding:28px 16px">' . rs_mail_logo()
        . '<div style="background:#ffffff;border-radius:14px;padding:28px 26px;font:16px/1.6 ' . RS_MB_FONT . ';color:#1b2430">' . $inner . $foot . '</div>'
        . '<div style="text-align:center;font:12px/1.5 ' . RS_MB_FONT . ';color:#8a95a5;padding:16px 0">RecompStore · <a href="https://recompstore.com/" style="color:#8a95a5">recompstore.com</a></div>'
        . '</div></body></html>';
    $text = rs_mb_text($blocks, $v) . ($sign !== '' ? "\n\n" . $sign : '');
    return [$html, $text];
}

// ---- Templates --------------------------------------------------------------------------------

function rs_mb_templates(): array {
    $T = fn(string $t) => ['t' => 'text', 'text' => $t];
    return [
        'press' => ['name' => 'Press kit announcement', 'subject' => 'Badges for your RecompStore page', 'blocks' => [
            $T("Hi {name},\n\nThanks for sending your work to RecompStore. Quick update on something new for developers."),
            ['t' => 'heading', 'size' => 'small', 'text' => 'A badge for your README'],
            $T("There's now a press kit with a \"Get it on RecompStore\" badge, logos and a few lines you can use. Pick your app and you get the badge already linked to its page, ready to paste into your GitHub README, your site or a forum post. Anyone who clicks it lands on your app's page and can install it straight from their PS5."),
            ['t' => 'button', 'label' => 'Open the press kit', 'url' => 'https://recompstore.com/press', 'style' => 'main'],
            $T("Every app in the store also has its own page now, built so it comes up when people search for it.\n\nIf you're working on something new, send it in at https://recompstore.com/submit. We're excited to see what you make next, and we'd love to help get it in front of more players. Questions or ideas, just reply to this email."),
        ]],
        'invite' => ['name' => 'Developer invite', 'subject' => 'Your developer page on RecompStore', 'blocks' => [
            ['t' => 'heading', 'size' => 'big', 'text' => 'Hi {name}, you\'re invited'],
            $T("We're opening developer accounts on RecompStore, and we're starting with a small group of people whose work we already love. You're one of them."),
            $T("With a developer account you get:\n\n- Your own page with a **Verified developer** badge, your links and every app you've made\n- Install numbers for your apps, and the logs players send when something goes wrong\n- A Support button on your app pages for your Ko-fi, Patreon or PayPal\n- Betas for your apps, with codes you hand out yourself\n- A quicker way to send in new apps and update your pages"),
            ['t' => 'button', 'label' => 'Set up my developer page', 'url' => '{invite}', 'style' => 'main'],
            $T("Sign in with GitHub (RecompStore only sees your public profile, no access to your repositories), or make an account with your email. The link is just for you.\n\nQuestions? Reply to this email and it comes straight to me."),
        ]],
        'newsletter' => ['name' => 'Newsletter', 'subject' => 'What\'s new on RecompStore', 'blocks' => [
            ['t' => 'heading', 'size' => 'big', 'text' => 'What\'s new on RecompStore'],
            $T("Hi {name}, here's what landed in the store lately."),
            ['t' => 'apps', 'mode' => 'new', 'count' => 3, 'title' => 'New in the store'],
            ['t' => 'apps', 'mode' => 'updated', 'count' => 3, 'title' => 'Just updated'],
            ['t' => 'divider'],
            $T("Made something for jailbroken PS5? Send it in and we'll take a look."),
            ['t' => 'button', 'label' => 'Submit an app', 'url' => 'https://recompstore.com/submit', 'style' => 'plain'],
        ]],
        'drop' => ['name' => 'New app drop', 'subject' => 'New on RecompStore', 'blocks' => [
            ['t' => 'heading', 'size' => 'big', 'text' => 'Out now on RecompStore'],
            $T("Hi {name}, something new just came out."),
            ['t' => 'apps', 'mode' => 'new', 'count' => 1, 'title' => ''],
            $T("Open RecompStore on your PS5 to install it, or have a look on the site first."),
        ]],
        'updates' => ['name' => 'Updates roundup', 'subject' => 'Fresh updates on RecompStore', 'blocks' => [
            ['t' => 'heading', 'size' => 'big', 'text' => 'Fresh updates'],
            $T("Hi {name}, these apps got updates this week. RecompStore updates them for you when you open it."),
            ['t' => 'apps', 'mode' => 'updated', 'count' => 5, 'title' => ''],
        ]],
        'beta' => ['name' => 'Beta announcement', 'subject' => 'A new beta is open', 'blocks' => [
            ['t' => 'heading', 'size' => 'big', 'text' => 'A new beta is open'],
            $T("Hi {name}, we're looking for testers. Spots are limited, so grab one while you can."),
            ['t' => 'button', 'label' => 'Get a beta code', 'url' => 'https://recompstore.com/beta', 'style' => 'main'],
            $T("Sign in with Discord on the page, get your code, then unlock the beta in RecompStore."),
        ]],
        'blank' => ['name' => 'Blank', 'subject' => '', 'blocks' => [$T("Hi {name},\n\n")]],
    ];
}

// Old drafts were plain text: as blocks, one text block.
function rs_mb_from_text(string $text): array {
    return [['t' => 'text', 'text' => $text]];
}
