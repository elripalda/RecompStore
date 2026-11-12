<?php
// RecompStore (4.8): sponsor spots on recomplauncher.com. Each spot is off until a sponsor is booked
// for it; a booking has a picture, a link and its dates, and counts its views and clicks by day.
declare(strict_types=1);

// The spots: key => [name, where it shows, picture size to ask for].
const RS_SP_SLOTS = [
    'rails' => ['Side rails', 'Both sides of every RecompLauncher page on wide screens', '300 × 600'],
    'banner' => ['Top banner', 'Under the featured games on Discover, above the list on All Recomps', '1456 × 180'],
    'game' => ['Game page', 'Beside the download box on every recomp\'s page', '600 × 500'],
];

function rs_sp_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS sponsors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slot VARCHAR(16) NOT NULL,
        name VARCHAR(120) NOT NULL,
        link VARCHAR(500) NOT NULL,
        alt VARCHAR(160) NOT NULL DEFAULT \'\',
        image VARCHAR(255) NOT NULL,
        starts DATE NOT NULL,
        ends DATE NULL,
        paused TINYINT NOT NULL DEFAULT 0,
        house TINYINT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL,
        KEY slot (slot, starts)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS sponsor_days (day DATE NOT NULL, sponsor_id INT NOT NULL, views INT NOT NULL DEFAULT 0, clicks INT NOT NULL DEFAULT 0, PRIMARY KEY (day, sponsor_id)) CHARACTER SET utf8mb4');
    $done = true;
}

// What runs today, one per spot: a paid booking first, a house ad (your own) when nothing's sold.
function rs_sp_live(): array {
    rs_sp_tables();
    $out = [];
    $rows = rs_db()->query("SELECT id, slot, name, alt, image, house FROM sponsors WHERE paused = 0 AND starts <= UTC_DATE() AND (ends IS NULL OR ends >= UTC_DATE()) ORDER BY house, starts DESC, id DESC")->fetchAll();
    foreach ($rows as $r) {
        if (!isset(RS_SP_SLOTS[$r['slot']]) || isset($out[$r['slot']])) continue;
        $out[$r['slot']] = ['id' => (int)$r['id'], 'slot' => $r['slot'], 'img' => (string)$r['image'], 'alt' => (string)($r['alt'] ?: $r['name']), 'house' => (bool)$r['house']];
    }
    return array_values($out);
}

function rs_sp_count(array $ids, string $col): void {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0))), 0, 4);
    if (!$ids) return;
    rs_sp_tables();
    $col = $col === 'clicks' ? 'clicks' : 'views';
    $st = rs_db()->prepare("INSERT INTO sponsor_days (day, sponsor_id, $col) VALUES (UTC_DATE(), ?, 1) ON DUPLICATE KEY UPDATE $col = $col + 1");
    foreach ($ids as $id) $st->execute([$id]);
}

// A picture for a spot, checked and kept under /media/sponsors. -> its address.
function rs_sp_save_image(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Pick a picture for the spot.');
    if ((int)$file['size'] > 3 * 1024 * 1024) throw new RuntimeException('The picture is over 3 MB.');
    $info = @getimagesize((string)$file['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? '';
    if ($ext === '') throw new RuntimeException('Use a PNG, JPG, WebP or GIF picture.');
    $dir = RS_MEDIA . '/sponsors';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], "$dir/$name")) throw new RuntimeException('Couldn\'t save the picture.');
    @chmod("$dir/$name", 0644);
    return '/media/sponsors/' . $name;
}
