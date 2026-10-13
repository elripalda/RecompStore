<?php
// Recomp Store admin: first-run setup. Saves the database login and creates the admin account.
// Once the config exists, this page refuses to run again.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (rs_config()) { header('Location: login.php'); exit; }

$error = '';
$v = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if ($v['db_name'] === '' || $v['db_user'] === '') $error = 'Fill in the database name and username.';
    elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $v['username'])) $error = 'Pick an admin username of 3 to 64 letters, numbers or . _ @ -';
    elseif (strlen($pass) < 10) $error = 'Make the admin password at least 10 characters.';
    elseif ($pass !== $pass2) $error = 'The two admin passwords don\'t match.';

    if ($error === '') {
        try {
            $pdo = new PDO('mysql:host=' . $v['db_host'] . ';dbname=' . $v['db_name'] . ';charset=utf8mb4',
                $v['db_user'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL UNIQUE,
                pass_hash VARCHAR(255) NOT NULL,
                created DATETIME NOT NULL
            ) CHARACTER SET utf8mb4');
            $pdo->exec('CREATE TABLE IF NOT EXISTS games (
                id VARCHAR(32) PRIMARY KEY,
                data MEDIUMTEXT NOT NULL,
                sort INT NOT NULL DEFAULT 0,
                updated DATETIME NOT NULL
            ) CHARACTER SET utf8mb4');
            $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
                k VARCHAR(64) PRIMARY KEY,
                v MEDIUMTEXT NOT NULL
            ) CHARACTER SET utf8mb4');
            $pdo->prepare('INSERT INTO users (username, pass_hash, created) VALUES (?, ?, NOW())')
                ->execute([$v['username'], password_hash($pass, PASSWORD_DEFAULT)]);

            // Bring in the games that ship with the site (seed.json), so the admin starts filled in.
            $seed = __DIR__ . '/inc/seed.json';
            if (is_file($seed)) {
                $games = json_decode((string)file_get_contents($seed), true) ?: [];
                $ins = $pdo->prepare('INSERT IGNORE INTO games (id, data, sort, updated) VALUES (?, ?, ?, NOW())');
                $sort = 10;
                foreach ($games as $g) {
                    $id = $g['id']; unset($g['id']);
                    $ins->execute([$id, json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $sort]);
                    $sort += 10;
                }
            }

            $config = "<?php\n// Recomp Store database login. Written by admin/setup.php. Keep this file private.\nreturn " . var_export([
                'db_host' => $v['db_host'], 'db_name' => $v['db_name'], 'db_user' => $v['db_user'], 'db_pass' => $dbPass,
            ], true) . ";\n";
            $path = rs_config_path();
            if (file_put_contents($path, $config) === false) {
                throw new RuntimeException('The database works, but the config file could not be saved at ' . $path . '.');
            }
            @chmod($path, 0600);
            rs_session();
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$pdo->lastInsertId() ?: 1;
            rs_flash('Setup is done. Your games are loaded; press Publish when you\'re ready for the PS5 store and the site to see them.');
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Could not connect to the database. Check the name, username and password Hostinger shows (they start with u…_).';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

rs_head('Setup', false);
?>
<section class="card narrow">
  <h1>Set up Recomp Store</h1>
  <p class="muted">This runs once. It connects the site to the database you made in hPanel and creates your admin login.</p>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
    <h2>Database</h2>
    <label>Database name<input name="db_name" value="<?= h($v['db_name']) ?>" placeholder="u123456789_recomp" required></label>
    <label>Database username<input name="db_user" value="<?= h($v['db_user']) ?>" placeholder="u123456789_recomp" required></label>
    <label>Database password<input type="password" name="db_pass" required></label>
    <label>Database host<input name="db_host" value="<?= h($v['db_host']) ?>" required><small>Leave as localhost on Hostinger.</small></label>
    <h2>Your admin login</h2>
    <label>Username<input name="username" value="<?= h($v['username']) ?>" required></label>
    <label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>Password again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <button class="primary" type="submit">Finish setup</button>
  </form>
</section>
<?php rs_foot();
