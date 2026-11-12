<?php
// RecompStore admin (4.8): pin or unpin a page in this login's pinned column. POST href, label, on.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
rs_check_csrf();
$href = (string)($_POST['href'] ?? '');
$label = trim(mb_substr((string)($_POST['label'] ?? ''), 0, 40));
if (!preg_match('#^[a-z0-9-]+\.php(\?[A-Za-z0-9=&_.%-]*)?$#', $href) || !rs_can_page($href)) { http_response_code(400); echo '{"ok":false,"error":"That page can\'t be pinned."}'; exit; }
$pins = rs_pins();
$pins = array_values(array_filter($pins, fn($p) => $p['href'] !== $href));
if (!empty($_POST['on'])) $pins[] = ['href' => $href, 'label' => $label !== '' ? $label : $href];
rs_set_setting('pins_' . (int)$_SESSION['uid'], json_encode(array_slice($pins, 0, 14), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo json_encode(['ok' => true, 'pins' => array_column($pins, 'href')]);
