<?php
// RecompStore website (2.6): text the admin or a developer wrote (descriptions, patch notes) in
// the reader's language, through DeepL when the admin has given the site a key (Settings,
// Translations). Each text is translated once per language and kept: every PS5 and browser
// after the first gets the kept copy, so the monthly allowance goes on new text only.
declare(strict_types=1);

const RS_TR_LANGS = [
    // our code => DeepL's target (a second one to try if DeepL refuses the first)
    'en' => ['EN-US'], 'en-GB' => ['EN-GB'], 'es' => ['ES'], 'es-419' => ['ES-419', 'ES'],
    'fr' => ['FR'], 'fr-CA' => ['FR'], 'pt' => ['PT-PT'], 'pt-BR' => ['PT-BR'], 'it' => ['IT'],
    'de' => ['DE'], 'nl' => ['NL'], 'pl' => ['PL'], 'tr' => ['TR'], 'ru' => ['RU'], 'uk' => ['UK'], 'vi' => ['VI'], 'id' => ['ID'],
    'ar' => ['AR'], 'ja' => ['JA'], 'zh-Hans' => ['ZH-HANS', 'ZH'], 'zh-Hant' => ['ZH-HANT', 'ZH'], 'ko' => ['KO'],
];
const RS_TR_CAP = 450000; // characters a month: DeepL's free plan gives 500,000

function rs_tr_tables(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS translations (
        h CHAR(64) NOT NULL, lang VARCHAR(8) NOT NULL, text MEDIUMTEXT NOT NULL, at DATETIME NOT NULL,
        PRIMARY KEY (h, lang)) CHARACTER SET utf8mb4');
}

function rs_tr_key(): string {
    return trim((string)rs_setting('deepl_key', ''));
}

function rs_tr_on(): bool {
    return rs_setting('tr_on', '1') === '1' && rs_tr_key() !== '';
}

// This month's characters sent to DeepL, and the most the site sends.
function rs_tr_used(): int {
    return (int)rs_setting('deepl_chars_' . gmdate('Ym'), '0');
}
function rs_tr_cap(): int {
    $c = (int)rs_setting('deepl_cap', (string)RS_TR_CAP);
    return $c > 0 ? $c : RS_TR_CAP;
}

// Whether a text is already in the language asked for, near enough not to spend on: only told
// for English (mostly Latin letters and no other script), where it matters most.
function rs_tr_already(string $text, string $lang): bool {
    if ($lang !== 'en' && $lang !== 'en-GB') return false;
    $other = preg_match_all('/[\x{0400}-\x{04FF}\x{0590}-\x{08FF}\x{0E00}-\x{0E7F}\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text);
    if ($other > 3) return false;
    // A Latin-script text in another language (Spanish notes, say) is told by its little words.
    $words = preg_split('/[^a-zà-ÿ]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($words) < 6) return true;
    $en = 0;
    $common = ['the', 'and', 'to', 'of', 'a', 'in', 'is', 'for', 'with', 'on', 'it', 'this', 'that', 'now', 'fixed', 'added', 'when', 'from', 'be', 'are', 'not', 'you', 'can', 'was', 'an', 'by'];
    foreach ($words as $w) if (in_array($w, $common, true)) $en++;
    return $en / count($words) >= 0.08;
}

// One call to DeepL: the texts in order, or null when it didn't go.
function rs_tr_deepl(array $texts, string $target): ?array {
    $key = rs_tr_key();
    if ($key === '') return null;
    $host = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
    $c = curl_init($host . '/v2/translate');
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['text' => array_values($texts), 'target_lang' => $target, 'preserve_formatting' => true], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $key, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'RecompStore-site',
    ];
    $ca = getenv('RS_CA_BUNDLE');
    if (is_string($ca) && $ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($c, $opts);
    $body = curl_exec($c);
    $status = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    if ($status !== 200 || !is_string($body)) {
        rs_set_setting('deepl_last_error', gmdate('Y-m-d H:i') . ' ' . $target . ': ' . ($status ?: 'no answer'));
        return $status === 400 ? [] : null; // [] : DeepL doesn't take that language
    }
    $j = json_decode($body, true);
    $out = [];
    foreach (($j['translations'] ?? []) as $t) $out[] = (string)($t['text'] ?? '');
    return count($out) === count($texts) ? $out : null;
}

// The texts in lang: each translated (kept or new), the text itself when it's already in that
// language, or null when it can't be had now (no key, the month's allowance used, DeepL away).
function rs_translate(array $texts, string $lang, bool $may_ask = true): array {
    rs_tr_tables();
    $db = rs_db();
    $out = array_fill(0, count($texts), null);
    $miss = [];
    foreach ($texts as $i => $t) {
        $t = (string)$t;
        if (trim($t) === '' || rs_tr_already($t, $lang)) { $out[$i] = $t; continue; }
        $st = $db->prepare('SELECT text FROM translations WHERE h = ? AND lang = ?');
        $st->execute([hash('sha256', $t), $lang]);
        $kept = $st->fetchColumn();
        if (is_string($kept)) $out[$i] = $kept;
        else $miss[$i] = $t;
    }
    if (!$miss || !$may_ask || !rs_tr_on() || !isset(RS_TR_LANGS[$lang])) return $out;
    $chars = 0;
    foreach ($miss as $t) $chars += mb_strlen($t);
    if (rs_tr_used() + $chars > rs_tr_cap()) return $out;
    $got = null;
    foreach (RS_TR_LANGS[$lang] as $target) {
        $got = rs_tr_deepl($miss, $target);
        if ($got !== []) break;
    }
    if (!$got) return $out;
    rs_set_setting('deepl_chars_' . gmdate('Ym'), (string)(rs_tr_used() + $chars));
    $put = $db->prepare('INSERT INTO translations (h, lang, text, at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE text = VALUES(text), at = NOW()');
    $k = 0;
    foreach ($miss as $i => $t) {
        $out[$i] = $got[$k++];
        $put->execute([hash('sha256', $t), $lang, $out[$i]]);
    }
    return $out;
}

// DeepL's own count for the key: [used, limit], or null when it didn't answer (or refused it).
function rs_tr_usage(): ?array {
    $key = rs_tr_key();
    if ($key === '') return null;
    $host = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
    $c = curl_init($host . '/v2/usage');
    $opts = [CURLOPT_HTTPHEADER => ['Authorization: DeepL-Auth-Key ' . $key], CURLOPT_RETURNTRANSFER => true,
             CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'RecompStore-site'];
    $ca = getenv('RS_CA_BUNDLE');
    if (is_string($ca) && $ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($c, $opts);
    $body = curl_exec($c);
    $status = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    if ($status !== 200 || !is_string($body)) return null;
    $j = json_decode($body, true);
    return [(int)($j['character_count'] ?? 0), (int)($j['character_limit'] ?? 0)];
}
