<?php
// (4.10) AI usage: which features may call Claude and with which model, what each call cost (worked out from
// the tokens Anthropic sends back and the prices in Settings), and a daily budget that stops the calls once
// it's used up. Page: Settings > AI usage (admin/ai-usage.php).
declare(strict_types=1);

const RS_AI_MODELS = ['opus' => 'claude-opus-5-5', 'sonnet' => 'claude-sonnet-5-5', 'haiku' => 'claude-haiku-5-5'];
// (4.11) Prices per million tokens (in, out, cache read, 5-minute cache write), from Anthropic's pricing page
// (Nov 2026). Haiku costs more once one prompt is over 100,000 tokens; those calls are recorded as "<model>:long".
const RS_AI_PRICES = ['opus' => ['in' => 4, 'out' => 20, 'cr' => 0.2, 'cw' => 5], 'sonnet' => ['in' => 2, 'out' => 10, 'cr' => 0.1, 'cw' => 2.5],
    'haiku' => ['in' => 0.1, 'out' => 0.5, 'cr' => 0.01, 'cw' => 0.125], 'haiku_long' => ['in' => 0.5, 'out' => 2.5, 'cr' => 0.05, 'cw' => 0.625]];
const RS_AI_PRICE_NAMES = ['opus' => 'Opus', 'sonnet' => 'Sonnet', 'haiku' => 'Haiku', 'haiku_long' => 'Haiku, prompts over 100k tokens'];
// What each feature does with AI by default. 'off' = rules only (inc/rules.php).
const RS_AI_FEATURES = [
    'autoadd' => ['Auto-add page text', 'off', ['off', 'haiku', 'sonnet']],
    'scout' => ['Daily scout sorting', 'off', ['off', 'haiku', 'sonnet']],
    'translate' => ['Translating players\' notes', 'haiku', ['off', 'haiku', 'sonnet']],
    'prq' => ['PR drafter: reading the project and writing the json', 'sonnet', ['off', 'haiku', 'sonnet', 'opus']],
    'prqcode' => ['PR drafter: code changes', 'sonnet', ['haiku', 'sonnet', 'opus']],
    'ondemand' => ['"Write it with AI" on a draft', 'haiku', ['off', 'haiku', 'sonnet', 'opus']],
];

function rs_ai_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS ai_usage (
        day DATE NOT NULL,
        feature VARCHAR(20) NOT NULL,
        model VARCHAR(40) NOT NULL,
        calls INT NOT NULL DEFAULT 0,
        tin BIGINT NOT NULL DEFAULT 0,
        tout BIGINT NOT NULL DEFAULT 0,
        tcache_read BIGINT NOT NULL DEFAULT 0,
        tcache_write BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY (day, feature, model)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_ai_cfg(): array {
    $c = json_decode((string)rs_setting('ai_cfg', ''), true);
    $c = is_array($c) ? $c : [];
    $mode = [];
    foreach (RS_AI_FEATURES as $k => [, $def, $allowed]) $mode[$k] = in_array($c['mode'][$k] ?? '', $allowed, true) ? $c['mode'][$k] : $def;
    $price = is_array($c['price'] ?? null) ? $c['price'] : [];
    // US$ per million tokens: in, out, cache read, cache write. Saved ones win over RS_AI_PRICES (prices change).
    $out = [];
    foreach (RS_AI_PRICES as $m => $d) $out[$m] = array_map('floatval', array_replace($d, array_intersect_key((array)($price[$m] ?? []), $d)));
    return ['mode' => $mode, 'cap' => max(0, (float)($c['cap'] ?? 3)), 'price' => $out];
}
function rs_ai_save_cfg(array $c): void { rs_set_setting('ai_cfg', (string)json_encode($c)); }

// The model a feature uses, or '' when it's off (rules only).
function rs_ai_model_for(string $feature): string {
    $m = rs_ai_cfg()['mode'][$feature] ?? 'off';
    return RS_AI_MODELS[$m] ?? '';
}
function rs_ai_model_key(string $model): string {
    foreach (RS_AI_MODELS as $k => $id) if ($id === $model || str_contains($model, $k)) return $k === 'haiku' && str_ends_with($model, ':long') ? 'haiku_long' : $k;
    return 'sonnet';
}

function rs_ai_cost(string $model, int $in, int $out, int $cr = 0, int $cw = 0): float {
    $p = rs_ai_cfg()['price'][rs_ai_model_key($model)];
    return ($in * $p['in'] + $out * $p['out'] + $cr * $p['cr'] + $cw * $p['cw']) / 1e6;
}

function rs_ai_record(string $feature, string $model, array $usage): void {
    if (str_contains($model, 'haiku') && (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0) > 100000) $model .= ':long';
    try {
        rs_ai_tables();
        rs_db()->prepare('INSERT INTO ai_usage (day, feature, model, calls, tin, tout, tcache_read, tcache_write) VALUES (UTC_DATE(), ?, ?, 1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE calls = calls + 1, tin = tin + VALUES(tin), tout = tout + VALUES(tout), tcache_read = tcache_read + VALUES(tcache_read), tcache_write = tcache_write + VALUES(tcache_write)')
            ->execute([mb_substr($feature, 0, 20), mb_substr($model, 0, 40), (int)($usage['input_tokens'] ?? 0), (int)($usage['output_tokens'] ?? 0),
                (int)($usage['cache_read_input_tokens'] ?? 0), (int)($usage['cache_creation_input_tokens'] ?? 0)]);
    } catch (Throwable $e) { /* counting never stops the work */ }
}

// Several calls at once (the PR drafter sends a project's total). (4.11) A list is one total per model.
function rs_ai_record_n(string $feature, string $model, array $u): void {
    if (array_is_list($u)) { foreach ($u as $one) if (is_array($one)) rs_ai_record_n($feature, (string)($one['model'] ?? $model), $one); return; }
    try {
        rs_ai_tables();
        rs_db()->prepare('INSERT INTO ai_usage (day, feature, model, calls, tin, tout, tcache_read, tcache_write) VALUES (UTC_DATE(), ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE calls = calls + VALUES(calls), tin = tin + VALUES(tin), tout = tout + VALUES(tout), tcache_read = tcache_read + VALUES(tcache_read), tcache_write = tcache_write + VALUES(tcache_write)')
            ->execute([mb_substr($feature, 0, 20), mb_substr($model !== '' ? $model : RS_AI_MODELS['sonnet'], 0, 40), max(1, (int)($u['calls'] ?? 1)), (int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0),
                (int)($u['cache_read_input_tokens'] ?? 0), (int)($u['cache_creation_input_tokens'] ?? 0)]);
    } catch (Throwable $e) { }
}

// Spend in US$ for a range of days, by feature. -> [feature => ['calls', 'cost']], plus 'total'
function rs_ai_spend(string $from, string $to): array {
    rs_ai_tables();
    $st = rs_db()->prepare('SELECT feature, model, SUM(calls) c, SUM(tin) i, SUM(tout) o, SUM(tcache_read) r, SUM(tcache_write) w FROM ai_usage WHERE day BETWEEN ? AND ? GROUP BY feature, model');
    $st->execute([$from, $to]);
    $out = ['total' => ['calls' => 0, 'cost' => 0.0]];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cost = rs_ai_cost((string)$r['model'], (int)$r['i'], (int)$r['o'], (int)$r['r'], (int)$r['w']);
        $out[$r['feature']] = ['calls' => ($out[$r['feature']]['calls'] ?? 0) + (int)$r['c'], 'cost' => ($out[$r['feature']]['cost'] ?? 0) + $cost];
        $out['total']['calls'] += (int)$r['c'];
        $out['total']['cost'] += $cost;
    }
    return $out;
}
function rs_ai_today(): float { return rs_ai_spend(gmdate('Y-m-d'), gmdate('Y-m-d'))['total']['cost']; }

// Before a call: throws when today's budget is used up.
function rs_ai_budget_ok(): void {
    $cap = rs_ai_cfg()['cap'];
    if ($cap > 0 && rs_ai_today() >= $cap)
        throw new RuntimeException('Today\'s AI budget ($' . number_format($cap, 2) . ') is used up. It starts again at midnight UTC, or raise it on Settings > AI usage.');
}
