<?php
// (2.9) AI-assisted: whether an app (PS5 or PC) was made with help from AI tools, and what for.
// The site labels it "AI-assisted" and lets visitors hide those apps everywhere (Settings).
//   ai       '' not decided yet, 'yes' AI-assisted, 'no' looked at and not labeled
//   ai_uses  what AI helped with: code, art, text, audio
//   ai_note  a line in the developer's own words (optional)
//   ai_by    who said so: 'developer' (their submission or the hub) or 'store' (the admin, from its GitHub)
// The GitHub check (rs_ai_scan) only finds signs: AI agents' files in the repo, commits credited to an AI
// tool, the README saying so. It suggests; the admin (or the developer) decides. No signs proves nothing.
declare(strict_types=1);

const RS_AI_USES = ['code' => 'Code', 'art' => 'Art', 'text' => 'Writing', 'audio' => 'Voice or music'];

function rs_ai_defaults(): array {
    return ['ai' => '', 'ai_uses' => [], 'ai_note' => '', 'ai_by' => ''];
}

function rs_ai_uses_clean($in): array {
    $in = is_array($in) ? $in : explode(',', (string)$in);
    return array_values(array_intersect(array_keys(RS_AI_USES), array_map(fn($x) => trim((string)$x), $in)));
}

// What the catalogs carry: only apps labeled AI-assisted have it.
function rs_ai_entry(array $a): array {
    if (($a['ai'] ?? '') !== 'yes') return [];
    $e = ['uses' => rs_ai_uses_clean($a['ai_uses'] ?? []) ?: ['code'], 'by' => ($a['ai_by'] ?? '') === 'developer' ? 'developer' : 'store'];
    $note = trim((string)($a['ai_note'] ?? ''));
    if ($note !== '') $e['note'] = mb_substr($note, 0, 200);
    return ['ai' => $e];
}

// The admin's edit form (PS5 and PC): the label, what for, a note, and the GitHub check.
function rs_ai_from_post(array $a): array {
    $v = (string)($_POST['ai'] ?? '');
    $was = (string)($a['ai'] ?? '');
    $a['ai'] = in_array($v, ['yes', 'no'], true) ? $v : '';
    $a['ai_uses'] = $a['ai'] === 'yes' ? (rs_ai_uses_clean((array)($_POST['ai_uses'] ?? [])) ?: ['code']) : [];
    $a['ai_note'] = $a['ai'] === 'yes' ? mb_substr(trim((string)($_POST['ai_note'] ?? '')), 0, 200) : '';
    // Who said so stays the developer's when they did and nothing changed here.
    if ($a['ai'] === '') $a['ai_by'] = '';
    elseif ($a['ai'] !== $was || ($a['ai_by'] ?? '') === '') $a['ai_by'] = 'store';
    return $a;
}

function rs_ai_fieldset(array $a, string $repo): string {
    $ai = (string)($a['ai'] ?? '');
    $uses = rs_ai_uses_clean($a['ai_uses'] ?? []);
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = '<fieldset class="span2 ai-set" data-ai-set><legend>AI use</legend><div class="ai-choice">';
    foreach (['' => 'Not decided', 'yes' => 'AI-assisted', 'no' => 'Not AI-assisted'] as $v => $label)
        $out .= '<label class="check"><input type="radio" name="ai" value="' . $v . '"' . ($ai === $v ? ' checked' : '') . '> ' . $label . '</label>';
    $out .= '</div><div class="ai-more"' . ($ai === 'yes' ? '' : ' hidden') . '><div class="ai-uses"><span class="muted">Used for</span>';
    foreach (RS_AI_USES as $k => $label)
        $out .= '<label class="check"><input type="checkbox" name="ai_uses[]" value="' . $k . '"' . (in_array($k, $uses, true) || ($uses === [] && $k === 'code') ? ' checked' : '') . '> ' . $label . '</label>';
    $out .= '</div><label>In their words <span class="muted">(optional)</span><input name="ai_note" maxlength="200" value="' . $h($a['ai_note'] ?? '') . '" placeholder="Claude helped write the renderer"></label></div>';
    $by = (string)($a['ai_by'] ?? '');
    $out .= '<small class="muted">AI-assisted apps get a label on their page, and visitors can hide them everywhere in Settings.'
        . ($by === 'developer' ? ' <strong>The developer told us this.</strong>' : ($ai !== '' ? ' Set here.' : '')) . '</small>';
    if ($repo !== '' && rs_repo_ok($repo)) $out .= rs_ai_scan_box($repo);
    $out .= '</fieldset><script>document.querySelectorAll("[data-ai-set]").forEach(function(f){f.addEventListener("change",function(){f.querySelector(".ai-more").hidden=!f.querySelector("input[name=ai][value=yes]").checked;});});</script>';
    return $out;
}

// ---- The GitHub check ---------------------------------------------------------------------------

// Files AI coding tools keep in a project, at its top level (and in .github).
const RS_AI_FILES = ['claude.md' => 'Claude Code', 'claude.local.md' => 'Claude Code', '.claude' => 'Claude Code', 'agents.md' => 'an AI coding agent',
    'agent.md' => 'an AI coding agent', 'gemini.md' => 'Gemini', '.gemini' => 'Gemini', '.cursorrules' => 'Cursor', '.cursor' => 'Cursor',
    '.windsurfrules' => 'Windsurf', '.windsurf' => 'Windsurf', '.clinerules' => 'Cline', '.cline' => 'Cline', '.roo' => 'Roo Code', '.roomodes' => 'Roo Code',
    '.aider.conf.yml' => 'Aider', '.aiderignore' => 'Aider', '.continue' => 'Continue', '.codex' => 'Codex', '.junie' => 'Junie', '.kiro' => 'Kiro',
    '.specstory' => 'Cursor (SpecStory)', '.openhands' => 'OpenHands', '.devin' => 'Devin', '.augment' => 'Augment', '.amazonq' => 'Amazon Q', '.qodo' => 'Qodo',
    'copilot-instructions.md' => 'GitHub Copilot'];
const RS_AI_GITHUB_FILES = ['copilot-instructions.md' => 'GitHub Copilot', 'instructions' => 'GitHub Copilot', 'prompts' => 'GitHub Copilot',
    'chatmodes' => 'GitHub Copilot', 'agents' => 'an AI coding agent'];
// Commits made by AI tools' own accounts.
const RS_AI_BOTS = ['claude[bot]' => 'Claude', 'copilot' => 'GitHub Copilot', 'copilot-swe-agent[bot]' => 'GitHub Copilot',
    'devin-ai-integration[bot]' => 'Devin', 'chatgpt-codex-connector[bot]' => 'Codex', 'google-labs-jules[bot]' => 'Jules',
    'cursor[bot]' => 'Cursor', 'cursoragent' => 'Cursor', 'openhands-agent' => 'OpenHands', 'sweep-ai[bot]' => 'Sweep', 'codegen-sh[bot]' => 'Codegen'];

// Which AI tool a commit credits, from its message and authors, or ''.
function rs_ai_commit_tool(array $c): string {
    $msg = (string)($c['commit']['message'] ?? '');
    foreach ([[$c['author']['login'] ?? ''], [$c['committer']['login'] ?? '']] as [$login]) {
        $l = strtolower((string)$login);
        if ($l !== '' && isset(RS_AI_BOTS[$l])) return RS_AI_BOTS[$l];
    }
    $emails = strtolower(($c['commit']['author']['email'] ?? '') . ' ' . ($c['commit']['committer']['email'] ?? ''));
    if (str_contains($emails, 'noreply@anthropic.com')) return 'Claude';
    if (str_contains($emails, 'cursoragent@cursor.com')) return 'Cursor';
    if (str_contains($emails, '+copilot@users.noreply.github.com')) return 'GitHub Copilot';
    if (preg_match('/^co-authored-by:\s*([^<\n]*)<([^>\n]*)>/mi', $msg) && preg_match_all('/^co-authored-by:\s*([^<\n]*)<([^>\n]*)>/mi', $msg, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $who = strtolower($x[1] . ' ' . $x[2]);
            foreach (['claude' => 'Claude', 'anthropic' => 'Claude', 'copilot' => 'GitHub Copilot', 'cursor' => 'Cursor', 'codex' => 'Codex', 'openai' => 'Codex',
                'chatgpt' => 'ChatGPT', 'gemini' => 'Gemini', 'devin' => 'Devin', 'aider' => 'Aider', 'jules' => 'Jules', 'windsurf' => 'Windsurf'] as $k => $tool)
                if (str_contains($who, $k)) return $tool;
        }
    }
    if (preg_match('/generated with \[?claude code|claude-session:|\x{1F916} generated with/iu', $msg)) return 'Claude';
    if (preg_match('/^aider: /m', $msg)) return 'Aider';
    return '';
}

// What the README says about AI: [mentions (short quotes), says none].
function rs_ai_readme(string $md): array {
    $text = preg_replace('/\s+/', ' ', strip_tags($md));
    $no = (bool)preg_match('/\b(no|without|zero|not using|never used|free of)\s+(any\s+)?(gen(erative)?\s*)?AI\b|\bAI[- ]free\b|\bno LLMs?\b/i', $text);
    $pat = '/\b(Claude Code|Claude (Opus|Sonnet|Haiku|AI)|Claude\.ai|(with|using|by|from) Claude|Anthropic|ChatGPT|GPT-[345]\w*|OpenAI Codex|Codex CLI|GitHub Copilot|Copilot|Cursor (AI|IDE|editor)|Gemini (CLI|Code Assist|2\.\d|3)|Windsurf|Cline|Aider|Devin|LLMs?|large language models?|AI[- ]assisted|AI[- ]generated|AI[- ]written|(generated|written|made|built|created|coded) (with|by|using) (the help of )?(an )?AI|with the help of AI|vibe[- ]?cod\w*|AI disclosure|AI (coding )?(tools?|agents?|assistants?) (were |was )?used|(assisted|helped) by AI|help (of|from) (an )?AI)\b/i';
    $found = [];
    if (preg_match_all($pat, $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$hit, $pos]) {
            $from = max(0, $pos - 70);
            $q = trim(mb_strcut($text, $from, 170, 'UTF-8'));
            $found[] = ($from > 0 ? '…' : '') . $q . '…';
            if (count($found) >= 3) break;
        }
    }
    return [$found, $no];
}

function rs_ai_gh(string $path): ?array {
    $r = rs_http(rs_gh_api() . $path, ['github' => true, 'max' => 12 << 20, 'timeout' => 20]);
    if ($r['status'] === 404) return [];
    if ($r['status'] !== 200) {
        if ($r['status'] === 403 || $r['status'] === 429) throw new RuntimeException('GitHub is busy right now (its limit). Try again in a few minutes.');
        return null;
    }
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : null;
}

// Checks a repo, or gives the last check (younger than $maxAge seconds).
// -> ['verdict' => 'signs'|'mentions'|'clear'|'error', 'evidence' => [strings], 'at' => unix time, 'says_none' => bool]
function rs_ai_scan(string $repo, int $maxAge = 30 * 86400): array {
    $key = 'ai:' . strtolower($repo);
    if ($maxAge > 0 && ($have = rs_kv_get($key)) && $have['age'] < $maxAge) {
        $j = json_decode($have['v'], true);
        if (is_array($j)) return $j;
    }
    if (!rs_repo_ok($repo)) return ['verdict' => 'error', 'evidence' => ['Not a GitHub repo.'], 'at' => time(), 'says_none' => false];
    $ev = [];
    $signs = false;
    $root = rs_ai_gh('/repos/' . $repo . '/contents/');
    if ($root === null) return ['verdict' => 'error', 'evidence' => ['GitHub didn\'t answer.'], 'at' => time(), 'says_none' => false];
    $names = [];
    foreach ($root as $f) if (is_array($f)) $names[strtolower((string)($f['name'] ?? ''))] = (string)($f['name'] ?? '');
    foreach (RS_AI_FILES as $f => $tool) if (isset($names[$f])) { $ev[] = 'Has ' . $names[$f] . ' (' . $tool . ')'; $signs = true; }
    if (isset($names['.github'])) {
        foreach (rs_ai_gh('/repos/' . $repo . '/contents/.github') ?? [] as $f) {
            $n = strtolower((string)($f['name'] ?? ''));
            if (isset(RS_AI_GITHUB_FILES[$n])) { $ev[] = 'Has .github/' . $f['name'] . ' (' . RS_AI_GITHUB_FILES[$n] . ')'; $signs = true; }
        }
    }
    $commits = rs_ai_gh('/repos/' . $repo . '/commits?per_page=100') ?? [];
    $tools = [];
    $n = 0;
    foreach ($commits as $c) {
        if (!is_array($c)) continue;
        $n++;
        if (($t = rs_ai_commit_tool($c)) !== '') $tools[$t] = ($tools[$t] ?? 0) + 1;
    }
    foreach ($tools as $t => $k) { $ev[] = $k . ' of the last ' . $n . ' commits ' . ($k === 1 ? 'credits ' : 'credit ') . $t; $signs = true; }
    // (2.9) Older history too: GitHub's commit search for AI tools' names, each hit checked the same way
    // (a commit saying "cursor movement" isn't Cursor's). Only needed when there's more than one page.
    if ($n >= 100) {
        $found = rs_ai_gh('/search/commits?per_page=100&q=' . rawurlencode('repo:' . $repo . ' claude OR anthropic OR copilot OR cursoragent OR codex'));
        $old = [];
        foreach ((array)($found['items'] ?? []) as $c) if (is_array($c) && ($t = rs_ai_commit_tool($c)) !== '') $old[$t] = ($old[$t] ?? 0) + 1;
        foreach ($old as $t => $k) {
            if ($k <= ($tools[$t] ?? 0)) continue;
            $more = (int)($found['total_count'] ?? 0) > 100 && $k >= 90;
            $ev[] = ($more ? 'Over ' . $k . ' commits' : ($k === 1 ? '1 commit' : $k . ' commits')) . ' in its history ' . ($k === 1 && !$more ? 'credits ' : 'credit ') . $t;
            $signs = true;
        }
    }
    // (2.9) AI tools' own accounts among its contributors.
    foreach (rs_ai_gh('/repos/' . $repo . '/contributors?per_page=100') ?? [] as $c) {
        $l = strtolower((string)($c['login'] ?? ''));
        // The account named claude is Anthropic's (commits Claude made show it).
        if ($l !== '' && (isset(RS_AI_BOTS[$l]) || $l === 'claude')) { $ev[] = (RS_AI_BOTS[$l] ?? 'Claude') . ' is a contributor (' . (int)($c['contributions'] ?? 0) . ' commits)'; $signs = true; }
    }
    $readme = rs_ai_gh('/repos/' . $repo . '/readme');
    $saysNone = false;
    $mentions = [];
    if (is_array($readme) && isset($readme['content'])) {
        [$mentions, $saysNone] = rs_ai_readme((string)base64_decode((string)$readme['content']));
        foreach ($mentions as $q) $ev[] = 'README: "' . $q . '"';
        if ($saysNone) $ev[] = 'README says it was made without AI';
    }
    $out = ['verdict' => $signs ? 'signs' : ($mentions ? 'mentions' : 'clear'), 'evidence' => $ev, 'at' => time(), 'says_none' => $saysNone, 'commits' => $n, 'v' => 2];
    rs_kv_put($key, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $out;
}

// The last check, without asking GitHub; null when it was never checked.
function rs_ai_scan_cached(string $repo): ?array {
    $have = rs_kv_get('ai:' . strtolower($repo));
    $j = $have ? json_decode($have['v'], true) : null;
    return is_array($j) ? $j : null;
}

const RS_AI_VERDICTS = ['signs' => 'Signs of AI', 'mentions' => 'README mentions AI', 'clear' => 'No signs found', 'error' => 'Couldn\'t check'];

function rs_ai_scan_box(string $repo): string {
    $s = rs_ai_scan_cached($repo);
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = '<div class="ai-scan"><strong>On GitHub:</strong> ';
    if (!$s) return $out . '<span class="muted">not checked yet.</span> <a href="ai.php?repo=' . rawurlencode($repo) . '">Check it</a></div>';
    $cls = ['signs' => 'warn', 'mentions' => 'warn', 'clear' => 'ok', 'error' => ''][$s['verdict']] ?? '';
    $out .= '<span class="pill ' . $cls . '">' . $h(RS_AI_VERDICTS[$s['verdict']] ?? $s['verdict']) . '</span> <span class="muted">' . $h(rs_date('M j', (int)$s['at'])) . '</span> <a href="ai.php?repo=' . rawurlencode($repo) . '">Check again</a>';
    if ($s['evidence']) $out .= '<ul>' . implode('', array_map(fn($e) => '<li>' . $h($e) . '</li>', $s['evidence'])) . '</ul>';
    return $out . '</div>';
}

// A newly added app: checked on GitHub, and labeled when the repo plainly shows AI tools at work
// (their files, or commits credited to them) and the developer didn't already say.
function rs_ai_on_add(array $a, string $repo): array {
    if (($a['ai'] ?? '') !== '' || !rs_repo_ok($repo)) return $a;
    try {
        $s = rs_ai_scan($repo, 0);
        if ($s['verdict'] === 'signs') $a = array_merge($a, ['ai' => 'yes', 'ai_uses' => ['code'], 'ai_by' => 'store']);
    } catch (Throwable $e) {
    }
    return $a;
}

// What a submission says about AI, as the app's fields (the developer's answer wins over the check).
function rs_ai_from_submission(array $d, array $a): array {
    $v = (string)($d['ai'] ?? '');
    if (!in_array($v, ['yes', 'no'], true)) return [];
    return ['ai' => $v, 'ai_uses' => $v === 'yes' ? (rs_ai_uses_clean($d['ai_uses'] ?? []) ?: ['code']) : [],
        'ai_note' => $v === 'yes' ? mb_substr(trim((string)($d['ai_note'] ?? '')), 0, 200) : '', 'ai_by' => 'developer'];
}
