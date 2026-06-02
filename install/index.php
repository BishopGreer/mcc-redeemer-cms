<?php
/**
 * OurSaintFrancis CMS — Web Installer
 *
 * Access: https://yourdomain.org/install/
 * Locked automatically after successful install.
 */

session_name('osf_install');
session_start();

require_once __DIR__ . '/installer.php';

// -------------------------------------------------------
// Block if already installed — but recover if config.local.php is missing
// -------------------------------------------------------
if (Installer::isAlreadyInstalled()) {
    if (file_exists(Installer::configPath())) {
        header('Location: ../admin/');
        exit;
    }
    // install.lock exists but config.local.php is missing — recovery mode
    $recoveryMode = true;
} else {
    $recoveryMode = false;
}

// -------------------------------------------------------
// Recovery mode: install.lock exists but config.local.php is missing
// -------------------------------------------------------
if ($recoveryMode) {
    $recoveryErrors = [];
    $recoveryInfo   = [];
    $recoveryDone   = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recovery_db_name'])) {
        $rHost = trim($_POST['recovery_db_host'] ?? 'localhost');
        $rPort = (int)($_POST['recovery_db_port'] ?? 3306);
        $rName = trim($_POST['recovery_db_name'] ?? '');
        $rUser = trim($_POST['recovery_db_user'] ?? '');
        $rPass = $_POST['recovery_db_pass'] ?? '';

        if (!$rHost || !$rName || !$rUser) {
            $recoveryErrors[] = 'Host, database name, and username are all required.';
        } else {
            $cfgData = [
                'db_host_q'   => Installer::quote($rHost),
                'db_name_q'   => Installer::quote($rName),
                'db_user_q'   => Installer::quote($rUser),
                'db_pass_q'   => Installer::quote($rPass),
                'base_path_q' => Installer::quote(dirname(__DIR__)),
                'env'         => 'production',
                'date'        => date('Y-m-d H:i:s'),
            ];
            if (Installer::writeConfig($cfgData)) {
                $recoveryDone = true;
            } else {
                $recoveryErrors[] = 'Could not write the file automatically (permissions). Copy the code below and create the file manually.';
                $_SESSION['recovery_config'] = Installer::generateConfig($cfgData);
            }
        }
    }

    // Show recovery UI — skip all other installer steps
    $configContent = $_SESSION['recovery_config'] ?? '';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recover — OurSaintFrancis CMS</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Georgia, serif; background: linear-gradient(135deg, #4a2c16 0%, #6b4226 60%, #c49a6c 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.box { background: #fff; border-radius: 10px; max-width: 640px; width: 100%; padding: 36px; box-shadow: 0 8px 40px rgba(0,0,0,.35); }
h2 { color: #4a2c16; margin-bottom: 12px; }
p { color: #555; line-height: 1.6; margin-bottom: 14px; }
.alert-error { background: #fdecea; border: 1px solid #f5c2c0; color: #7a1f1f; padding: 10px 14px; border-radius: 6px; margin-bottom: 12px; }
.alert-success { background: #eaf5ea; border: 1px solid #b2d9b2; color: #1a5c1a; padding: 10px 14px; border-radius: 6px; margin-bottom: 12px; }
.form-group { margin-bottom: 14px; }
label { display: block; font-size: 13px; font-weight: bold; color: #4a2c16; margin-bottom: 4px; }
input[type=text], input[type=password], input[type=number] { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 14px; }
.btn { display: inline-block; padding: 10px 22px; border-radius: 6px; font-size: 14px; font-weight: bold; cursor: pointer; border: none; text-decoration: none; }
.btn-primary { background: #6b4226; color: #fff; }
pre { background: #f3ece1; border: 1px solid #d4b896; border-radius: 6px; padding: 14px; font-size: 12px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; margin: 12px 0; }
</style>
</head>
<body>
<div class="box">
  <h2>&#9888; Configuration Recovery</h2>
  <p>Your installation is complete (<code>install.lock</code> exists) but <code>config/config.local.php</code> is missing. Enter your database credentials to regenerate it.</p>

  <?php foreach ($recoveryErrors as $e): ?>
    <div class="alert-error"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <?php if ($recoveryDone): ?>
    <div class="alert-success">&#10003; <strong>config/config.local.php has been written.</strong> Your site should now work correctly.</div>
    <p><a href="../admin/" class="btn btn-primary">Go to Admin Panel &rarr;</a></p>
  <?php else: ?>
    <form method="post">
      <div class="form-group">
        <label>Database Host</label>
        <input type="text" name="recovery_db_host" value="localhost" required>
      </div>
      <div class="form-group">
        <label>Port</label>
        <input type="number" name="recovery_db_port" value="3306">
      </div>
      <div class="form-group">
        <label>Database Name</label>
        <input type="text" name="recovery_db_name" required placeholder="The name you chose during install">
      </div>
      <div class="form-group">
        <label>Database Username</label>
        <input type="text" name="recovery_db_user" required>
      </div>
      <div class="form-group">
        <label>Database Password</label>
        <input type="password" name="recovery_db_pass">
      </div>
      <button type="submit" class="btn btn-primary">Write config.local.php</button>
    </form>

    <?php if ($configContent): ?>
      <hr style="margin: 24px 0; border-color: #e0d0c0;">
      <p><strong>Automatic write failed.</strong> Create the file manually:</p>
      <p>File path: <code><?= htmlspecialchars(Installer::configPath()) ?></code></p>
      <p>Paste this content into the file:</p>
      <pre><?= htmlspecialchars($configContent) ?></pre>
      <p>After creating the file, <a href="../admin/">go to the admin panel</a>.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
<?php
    exit;
}

// -------------------------------------------------------
// "Start Over" — clears all session data so nothing is pre-filled
// -------------------------------------------------------
if (isset($_GET['restart'])) {
    session_destroy();
    session_start();
    header('Location: ?step=1');
    exit;
}

// -------------------------------------------------------
// Step logic
// -------------------------------------------------------
$step   = max(1, min(5, (int)($_GET['step'] ?? $_SESSION['install_step'] ?? 1)));
$errors = [];
$info   = [];

// ---- Step 1: Requirements check (GET only) ----
if ($step === 1) {
    $checks = Installer::checkRequirements();
    $blocked = Installer::hasBlockingFailures($checks);
    if (!$blocked) {
        // Auto-advance link is available, user clicks Next
    }
}

// ---- Step 2: Database — handle POST ----
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    // Keep stored password if user left the field blank (returning via Back)
    $dbPass = $_POST['db_pass'] !== '' ? $_POST['db_pass'] : ($_SESSION['db']['dbPass'] ?? '');

    if (!$dbHost || !$dbName || !$dbUser) {
        $errors[] = 'Host, database name, and username are all required.';
    } else {
        $result = Installer::testDbConnection($dbHost, $dbName, $dbUser, $dbPass, $dbPort);
        if (!$result['ok']) {
            $errors[] = 'Could not connect to the database: ' . $result['error'];
        } else {
            $_SESSION['db'] = compact('dbHost', 'dbPort', 'dbName', 'dbUser', 'dbPass');

            // Write config.local.php NOW — don't wait until the final step.
            // If the write fails, store the content so step 3 can show it for manual creation.
            $cfgData = [
                'db_host_q'   => Installer::quote($dbHost),
                'db_name_q'   => Installer::quote($dbName),
                'db_user_q'   => Installer::quote($dbUser),
                'db_pass_q'   => Installer::quote($dbPass),
                'base_path_q' => Installer::quote(dirname(__DIR__)),
                'env'         => 'production',
                'date'        => date('Y-m-d H:i:s'),
            ];
            if (Installer::writeConfig($cfgData)) {
                $_SESSION['config_written'] = true;
                unset($_SESSION['config_content']);
            } else {
                $_SESSION['config_written'] = false;
                $_SESSION['config_content'] = Installer::generateConfig($cfgData);
            }

            if ($result['created']) {
                $info[] = "Database <strong>{$dbName}</strong> was created automatically.";
            }
            $_SESSION['install_step'] = 3;
            header('Location: ?step=3');
            exit;
        }
    }
}

// ---- Step 3: Site info — handle POST ----
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteName    = trim($_POST['site_name']   ?? '');
    $tagline     = trim($_POST['tagline']     ?? 'A Community of Faith');
    $siteUrl     = rtrim(trim($_POST['site_url']   ?? ''), '/');
    $adminEmail  = strtolower(trim($_POST['admin_email'] ?? ''));
    $networkMode = !empty($_POST['network_mode']);
    $baseDomain  = trim($_POST['base_domain'] ?? '');

    if (!$siteName)   $errors[] = 'Site name is required.';
    if (!$siteUrl)    $errors[] = 'Site URL is required.';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid admin email is required.';
    if ($networkMode && !$baseDomain) $errors[] = 'Base domain is required when network mode is enabled.';

    if (empty($errors)) {
        $_SESSION['site'] = compact('siteName', 'tagline', 'siteUrl', 'adminEmail', 'networkMode', 'baseDomain');
        $_SESSION['install_step'] = 4;
        header('Location: ?step=4');
        exit;
    }
}

// ---- Step 4: Admin account — handle POST ----
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminName  = trim($_POST['admin_name']  ?? '');
    $adminEmail = strtolower(trim($_POST['admin_email'] ?? $_SESSION['site']['adminEmail'] ?? ''));
    $adminPass  = $_POST['admin_pass']  ?? '';
    $adminPass2 = $_POST['admin_pass2'] ?? '';

    if (!$adminName)               $errors[] = 'Your name is required.';
    if (strlen($adminPass) < 10)   $errors[] = 'Password must be at least 10 characters.';
    if ($adminPass !== $adminPass2) $errors[] = 'Passwords do not match.';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

    if (empty($errors)) {
        $_SESSION['admin'] = compact('adminName', 'adminEmail', 'adminPass');
        $_SESSION['install_step'] = 5;
        header('Location: ?step=5');
        exit;
    }
}

// ---- Step 5: Run the install ----
if ($step === 5 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db   = $_SESSION['db']   ?? null;
    $site = $_SESSION['site'] ?? null;
    $adm  = $_SESSION['admin'] ?? null;

    if (!$db || !$site || !$adm) {
        $errors[] = 'Session expired. Please start over.';
        $step = 1;
    } else {
        // 1. Import schema
        $schemaResult = Installer::importSchema(
            $db['dbHost'], $db['dbName'], $db['dbUser'], $db['dbPass'], $db['dbPort']
        );
        if (!$schemaResult['ok']) {
            $errors[] = 'Database error: ' . $schemaResult['error'];
        }

        if (empty($errors)) {
            // 2. Write config.local.php — already written at step 2, but retry here if it wasn't.
            if (!($_SESSION['config_written'] ?? false) || !file_exists(Installer::configPath())) {
                $cfgData = [
                    'db_host_q'    => Installer::quote($db['dbHost']),
                    'db_name_q'    => Installer::quote($db['dbName']),
                    'db_user_q'    => Installer::quote($db['dbUser']),
                    'db_pass_q'    => Installer::quote($db['dbPass']),
                    'base_path_q'  => Installer::quote(dirname(__DIR__)),
                    'env'          => 'production',
                    'date'         => date('Y-m-d H:i:s'),
                    'network_mode' => !empty($site['networkMode']),
                    'base_domain_q'=> Installer::quote($site['baseDomain'] ?? ''),
                ];
                if (!Installer::writeConfig($cfgData)) {
                    $errors[] = 'Could not write config/config.local.php. '
                        . 'Please create this file manually — the contents were shown on the previous step. '
                        . 'File path: ' . Installer::configPath();
                }
            }
        }

        if (empty($errors)) {
            // 3. Create admin user and update site settings
            $pdo = Installer::makePdo(
                $db['dbHost'], $db['dbName'], $db['dbUser'], $db['dbPass'], $db['dbPort']
            );

            $networkMode = !empty($site['networkMode']);
            Installer::createAdminUser($pdo, $adm['adminName'], $adm['adminEmail'], $adm['adminPass'], $networkMode);

            Installer::writeSiteSettings($pdo, [
                'site_name'  => $site['siteName'],
                'tagline'    => $site['tagline'],
                'site_url'   => $site['siteUrl'],
                'admin_email'=> $site['adminEmail'],
            ]);

            // 4. Write lock file
            Installer::writeLock(Installer::VERSION);

            // 5. Clean session
            session_destroy();

            header('Location: ?step=done');
            exit;
        }
    }
}

// -------------------------------------------------------
// Render
// -------------------------------------------------------
$done = ($_GET['step'] ?? '') === 'done';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Install OurSaintFrancis CMS</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Georgia', serif;
  background: linear-gradient(135deg, #4a2c16 0%, #6b4226 60%, #c49a6c 100%);
  min-height: 100vh;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 40px 16px;
}
.box {
  background: #fff;
  border-radius: 10px;
  width: 100%;
  max-width: 640px;
  box-shadow: 0 8px 40px rgba(0,0,0,.25);
  overflow: hidden;
}
.box-header {
  background: #4a2c16;
  padding: 24px 32px;
  color: #fdf6ec;
}
.box-header h1 { font-size: 22px; font-weight: normal; }
.box-header p  { font-size: 13px; color: #c49a6c; margin-top: 4px; }
.steps {
  display: flex;
  background: #f3ece1;
  border-bottom: 1px solid #e8d9c4;
}
.step-pill {
  flex: 1;
  text-align: center;
  padding: 10px 4px;
  font-family: 'Helvetica Neue', Arial, sans-serif;
  font-size: 11px;
  color: #aaa;
  border-right: 1px solid #e8d9c4;
}
.step-pill:last-child { border-right: none; }
.step-pill.active  { color: #6b4226; font-weight: 700; border-bottom: 3px solid #6b4226; }
.step-pill.done    { color: #2e7d32; }
.body { padding: 28px 32px; }
h2 { font-size: 20px; color: #4a2c16; margin-bottom: 6px; font-weight: normal; }
.lead { color: #767676; font-size: 14px; margin-bottom: 22px; font-family: sans-serif; }
.form-group { margin-bottom: 16px; }
.form-group label {
  display: block; font-size: 13px; font-family: sans-serif;
  font-weight: 600; color: #4a4a4a; margin-bottom: 4px;
}
.form-group input[type=text],
.form-group input[type=email],
.form-group input[type=url],
.form-group input[type=password],
.form-group input[type=number] {
  width: 100%; padding: 9px 12px; border: 1px solid #cdb99a;
  border-radius: 5px; font-size: 14px; font-family: 'Georgia', serif;
  color: #4a4a4a; background: #fff;
}
.form-group input:focus { outline: none; border-color: #c49a6c; box-shadow: 0 0 0 3px rgba(196,154,108,.2); }
.form-hint { font-size: 11.5px; color: #999; margin-top: 3px; font-family: sans-serif; }
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 10px 22px; border-radius: 5px; border: none;
  font-family: sans-serif; font-size: 14px; font-weight: 600;
  cursor: pointer; text-decoration: none; transition: filter .15s;
}
.btn:hover { filter: brightness(.9); }
.btn-primary   { background: #6b4226; color: #fff; }
.btn-secondary { background: #e8d9c4; color: #4a2c16; }
.btn-success   { background: #2e7d32; color: #fff; }
.alert {
  padding: 10px 14px; border-radius: 5px; font-family: sans-serif;
  font-size: 13.5px; margin-bottom: 16px;
}
.alert-error   { background: #fdecea; color: #721c24; border-left: 4px solid #c0392b; }
.alert-info    { background: #e8f4fd; color: #1565c0; border-left: 4px solid #1565c0; }
.alert-success { background: #d4edda; color: #155724; border-left: 4px solid #2e7d32; }
.check-list { list-style: none; }
.check-list li {
  display: flex; align-items: center; gap: 10px; padding: 8px 0;
  border-bottom: 1px solid #f3ece1; font-family: sans-serif; font-size: 13.5px;
}
.check-list li:last-child { border-bottom: none; }
.check-icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
.check-label { flex: 1; }
.check-value { font-size: 12px; color: #999; }
.pass  { color: #2e7d32; }
.fail  { color: #c0392b; }
.warn  { color: #e65100; }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.footer { text-align: center; margin-top: 22px; font-family: sans-serif; font-size: 12px; color: #aaa; }
.done-icon { font-size: 64px; text-align: center; margin: 16px 0; }
</style>
</head>
<body>
<div class="box">

  <div class="box-header">
    <h1>&#9827; OurSaintFrancis CMS</h1>
    <p><?= $done ? 'Installation Complete' : 'Setup Wizard &bull; Version ' . Installer::VERSION ?></p>
  </div>

  <?php if (!$done): ?>
  <div class="steps">
    <?php
    $labels = ['Requirements', 'Database', 'Site Info', 'Admin Account', 'Install'];
    for ($i = 1; $i <= 5; $i++):
      $cls = $i === $step ? 'active' : ($i < $step ? 'done' : '');
    ?>
      <div class="step-pill <?= $cls ?>">
        <?= $i < $step ? '&#10003; ' : '' ?><?= $labels[$i - 1] ?>
      </div>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <div class="body">

  <?php if ($done): ?>
    <!-- SUCCESS -->
    <div class="done-icon">&#127881;</div>
    <h2 style="text-align:center;">Installation Complete!</h2>
    <p class="lead" style="text-align:center;">OurSaintFrancis CMS has been installed successfully.</p>

    <div class="alert alert-success">
      The installer is now <strong>locked</strong>. This page will redirect to the admin panel
      on your next visit.
    </div>

    <div style="margin-bottom:20px; background:#f3ece1; border-radius:6px; padding:16px; font-family:sans-serif; font-size:14px; line-height:1.8;">
      <strong>Next steps:</strong>
      <ol style="margin: 10px 0 0 20px;">
        <li>Log into the admin panel with the account you just created</li>
        <li>Go to <strong>Settings</strong> and enter your parish address, phone, and SMTP email settings</li>
        <li>Edit the <strong>Home</strong> page with your welcome message</li>
        <li>Get a free TinyMCE API key at <strong>tiny.cloud</strong> and add it in Settings</li>
        <li>Import your MailPoet subscribers in <strong>Subscribers → Import CSV</strong></li>
      </ol>
    </div>

    <div style="text-align:center;">
      <a href="../admin/login" class="btn btn-success" style="font-size:16px; padding:12px 32px;">
        Go to Admin Panel &rarr;
      </a>
    </div>

    <div class="footer">Pax et Bonum</div>

  <?php elseif ($step === 1): ?>
    <!-- STEP 1: Requirements -->
    <h2>System Requirements</h2>
    <p class="lead">Checking that your server meets the minimum requirements.</p>

    <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <ul class="check-list">
      <?php foreach ($checks as $c):
        $icon  = $c['pass'] ? '&#10003;' : ($c['fatal'] ? '&#10007;' : '&#9888;');
        $cls   = $c['pass'] ? 'pass'     : ($c['fatal'] ? 'fail'     : 'warn');
      ?>
        <li>
          <span class="check-icon <?= $cls ?>"><?= $icon ?></span>
          <span class="check-label"><?= htmlspecialchars($c['label']) ?></span>
          <span class="check-value <?= $cls ?>"><?= htmlspecialchars($c['value']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($blocked): ?>
      <div class="alert alert-error" style="margin-top:18px;">
        One or more <strong>fatal requirements</strong> are not met.
        Please fix the issues above before continuing.
      </div>
    <?php else: ?>
      <div class="alert alert-info" style="margin-top:18px;">
        All critical requirements are met. You may proceed.
      </div>
      <div style="text-align:right; margin-top:12px;">
        <a href="?step=2" class="btn btn-primary">Next: Database &rarr;</a>
      </div>
    <?php endif; ?>

  <?php elseif ($step === 2): ?>
    <!-- STEP 2: Database -->
    <h2>Database Connection</h2>
    <p class="lead">Enter your MariaDB / MySQL database credentials.</p>

    <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    <?php foreach ($info  as $i): ?><div class="alert alert-info"><?= $i ?></div><?php endforeach; ?>

    <?php
    // Restore values: POST (error re-render) > session (returning via Back) > defaults
    $db2 = $_SESSION['db'] ?? [];
    $v   = fn(string $key, string $default = '') =>
        htmlspecialchars($_POST[$key] ?? $db2['db' . ucfirst($key)] ?? $default);
    ?>
    <form method="post" action="?step=2">
      <div class="row2">
        <div class="form-group">
          <label>Database Host</label>
          <input type="text" name="db_host" value="<?= $v('host', 'localhost') ?>" required>
        </div>
        <div class="form-group">
          <label>Port</label>
          <input type="number" name="db_port" value="<?= $v('port', '3306') ?>" min="1" max="65535">
        </div>
      </div>
      <div class="form-group">
        <label>Database Name</label>
        <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>"
               placeholder="e.g. myparish_cms" required autofocus>
        <div class="form-hint">Type the exact name you want. It will be created automatically if it does not exist and your user has CREATE permission. This field is intentionally blank — do not leave it empty.</div>
      </div>
      <div class="form-group">
        <label>Database Username</label>
        <input type="text" name="db_user" value="<?= $v('user') ?>" required autocomplete="username">
      </div>
      <div class="form-group">
        <label>Database Password</label>
        <input type="password" name="db_pass" autocomplete="current-password"
               placeholder="<?= $db2 ? '(saved — leave blank to keep)' : '' ?>">
        <div class="form-hint">Leave blank if your DB user has no password (not recommended for production).</div>
      </div>
      <div style="display:flex; gap:10px; justify-content:space-between; align-items:center; margin-top:8px;">
        <a href="?step=1" class="btn btn-secondary">&larr; Back</a>
        <a href="?restart=1" style="font-size:12px; color:#999;">Start Over</a>
        <button type="submit" class="btn btn-primary">Test &amp; Continue &rarr;</button>
      </div>
    </form>

  <?php elseif ($step === 3): ?>
    <!-- STEP 3: Site info -->
    <h2>Site Information</h2>
    <p class="lead">Tell us about your parish website.</p>

    <?php if (!($_SESSION['config_written'] ?? true)): ?>
    <div class="alert alert-error" style="margin-bottom:16px;">
      <strong>&#9888; Action Required: Create config/config.local.php manually</strong><br>
      PHP could not write the configuration file automatically (check directory permissions on <code>config/</code>).
      Create the file at the path below and paste in the code shown, then continue.
      <br><br>
      <strong>File path:</strong><br>
      <code><?= htmlspecialchars(Installer::configPath()) ?></code>
      <br><br>
      <strong>File contents:</strong>
      <pre style="background:#fff;border:1px solid #f5c2c0;border-radius:4px;padding:10px;margin-top:6px;font-size:11px;overflow-x:auto;white-space:pre-wrap;"><?= htmlspecialchars($_SESSION['config_content'] ?? '') ?></pre>
      Once the file exists, click <strong>Next</strong> to continue.
    </div>
    <?php elseif (($_SESSION['config_written'] ?? false)): ?>
    <div class="alert alert-info" style="margin-bottom:12px;">
      &#10003; <code>config/config.local.php</code> written successfully.
    </div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <form method="post" action="?step=3">
      <div class="form-group">
        <label>Parish / Site Name</label>
        <input type="text" name="site_name" value="<?= htmlspecialchars($_POST['site_name'] ?? $_SESSION['site']['siteName'] ?? 'Your Parish') ?>" required>
      </div>
      <div class="form-group">
        <label>Tagline</label>
        <input type="text" name="tagline" value="<?= htmlspecialchars($_POST['tagline'] ?? $_SESSION['site']['tagline'] ?? 'A Community of Faith') ?>">
      </div>
      <div class="form-group">
        <label>Site URL</label>
        <input type="url" name="site_url" placeholder="https://your-site.org"
               value="<?= htmlspecialchars($_POST['site_url'] ?? $_SESSION['site']['siteUrl'] ?? (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')) ?>" required>
        <div class="form-hint">Include https:// — no trailing slash.</div>
      </div>
      <div class="form-group">
        <label>Admin Email Address</label>
        <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? $_SESSION['site']['adminEmail'] ?? '') ?>" required>
      </div>

      <div style="border-top:1px solid #e8d9c4; margin:20px 0 16px; padding-top:16px;">
        <div style="font-size:13px; font-weight:600; color:#4a4a4a; margin-bottom:10px;">
          Network / Multisite Mode
        </div>
        <div class="form-group">
          <label style="display:flex; align-items:center; gap:8px; font-weight:normal;">
            <input type="checkbox" name="network_mode" value="1"
                   id="network_mode"
                   <?= !empty($_POST['network_mode']) || !empty($_SESSION['site']['networkMode']) ? 'checked' : '' ?>
                   onchange="document.getElementById('base_domain_row').style.display=this.checked?'block':'none'">
            Enable network mode (subdomain multisite)
          </label>
          <div class="form-hint">
            Enables multiple sites on subdomains, e.g. <code>osfoc.myocci.org</code>.
            Requires a wildcard DNS record <code>*.yourdomain.org</code>.
          </div>
        </div>
        <div class="form-group" id="base_domain_row"
             style="display:<?= (!empty($_POST['network_mode']) || !empty($_SESSION['site']['networkMode'])) ? 'block' : 'none' ?>;">
          <label>Base Domain</label>
          <input type="text" name="base_domain"
                 value="<?= htmlspecialchars($_POST['base_domain'] ?? $_SESSION['site']['baseDomain'] ?? '') ?>"
                 placeholder="myocci.org">
          <div class="form-hint">Just the domain — no https:// and no trailing slash.</div>
        </div>
      </div>

      <div style="display:flex; gap:10px; justify-content:space-between; margin-top:8px;">
        <a href="?step=2" class="btn btn-secondary">&larr; Back</a>
        <button type="submit" class="btn btn-primary">Next: Admin Account &rarr;</button>
      </div>
    </form>

  <?php elseif ($step === 4): ?>
    <!-- STEP 4: Admin account -->
    <h2>Create Admin Account</h2>
    <p class="lead">Set up the administrator account you'll use to log in.</p>

    <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <form method="post" action="?step=4">
      <div class="form-group">
        <label>Your Name</label>
        <input type="text" name="admin_name"
               value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required autofocus>
      </div>
      <div class="form-group">
        <label>Admin Email</label>
        <input type="email" name="admin_email"
               value="<?= htmlspecialchars($_POST['admin_email'] ?? $_SESSION['site']['adminEmail'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Password <span style="font-weight:normal;color:#aaa;">(minimum 10 characters)</span></label>
        <input type="password" name="admin_pass" required autocomplete="new-password" minlength="10">
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="admin_pass2" required autocomplete="new-password">
      </div>
      <div style="display:flex; gap:10px; justify-content:space-between; margin-top:8px;">
        <a href="?step=3" class="btn btn-secondary">&larr; Back</a>
        <button type="submit" class="btn btn-primary">Next: Review &amp; Install &rarr;</button>
      </div>
    </form>

  <?php elseif ($step === 5): ?>
    <!-- STEP 5: Review and install -->
    <h2>Review &amp; Install</h2>
    <p class="lead">Everything is ready. Review your settings and click Install.</p>

    <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <?php
    $db   = $_SESSION['db']   ?? [];
    $site = $_SESSION['site'] ?? [];
    $adm  = $_SESSION['admin'] ?? [];
    ?>

    <div style="background:#f3ece1; border-radius:6px; padding:16px; margin-bottom:20px; font-family:sans-serif; font-size:14px; line-height:2;">
      <strong style="display:block; color:#4a2c16; margin-bottom:6px;">Database</strong>
      Host: <?= htmlspecialchars($db['dbHost'] ?? '') ?> &bull;
      Name: <?= htmlspecialchars($db['dbName'] ?? '') ?> &bull;
      User: <?= htmlspecialchars($db['dbUser'] ?? '') ?>

      <strong style="display:block; color:#4a2c16; margin-top:10px; margin-bottom:6px;">Site</strong>
      <?= htmlspecialchars($site['siteName'] ?? '') ?> &bull;
      <?= htmlspecialchars($site['siteUrl'] ?? '') ?>

      <strong style="display:block; color:#4a2c16; margin-top:10px; margin-bottom:6px;">Admin Account</strong>
      <?= htmlspecialchars($adm['adminName'] ?? '') ?> &lt;<?= htmlspecialchars($adm['adminEmail'] ?? '') ?>&gt;
    </div>

    <div class="alert alert-info">
      Clicking <strong>Install Now</strong> will:
      <ul style="margin: 8px 0 0 20px; line-height:1.9;">
        <li>Import the database schema</li>
        <li>Write <code>config/config.local.php</code></li>
        <li>Create your admin account</li>
        <li>Lock this installer</li>
      </ul>
    </div>

    <form method="post" action="?step=5">
      <div style="display:flex; gap:10px; justify-content:space-between;">
        <a href="?step=4" class="btn btn-secondary">&larr; Back</a>
        <button type="submit" class="btn btn-success" style="font-size:15px; padding:11px 28px;">
          &#9989; Install Now
        </button>
      </div>
    </form>

  <?php endif; ?>

  </div><!-- .body -->
</div><!-- .box -->
</body>
</html>
