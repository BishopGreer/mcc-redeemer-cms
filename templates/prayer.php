<?php
$errors  = [];
$success = false;
$v = ['name'=>'','email'=>'','phone'=>'','intention_type'=>'','intention'=>'','is_anonymous'=>0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    // hCaptcha
    $hcSecret = setting('hcaptcha_secret_key');
    if ($hcSecret) {
        $hcResponse = $_POST['h-captcha-response'] ?? '';
        $verify = file_get_contents('https://hcaptcha.com/siteverify', false, stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query(['secret' => $hcSecret, 'response' => $hcResponse]),
            ],
        ]));
        $result = $verify ? json_decode($verify, true) : [];
        if (empty($result['success'])) {
            $errors[] = 'Please complete the CAPTCHA.';
        }
    }

    $intentionTypes = [
        'For Myself', 'For a Family Member', 'For a Friend',
        'For Someone Who Has Died', 'General Intention',
    ];

    $v = [
        'name'           => trim($_POST['name']           ?? ''),
        'email'          => trim($_POST['email']          ?? ''),
        'phone'          => trim($_POST['phone']          ?? ''),
        'intention_type' => in_array($_POST['intention_type'] ?? '', $intentionTypes)
                            ? $_POST['intention_type'] : '',
        'intention'      => trim($_POST['intention']      ?? ''),
        'is_anonymous'   => isset($_POST['is_anonymous'])  ? 1 : 0,
    ];

    if (empty($v['name']))      $errors[] = 'Your name is required.';
    if (empty($v['intention'])) $errors[] = 'Please share your prayer intention.';
    if ($v['email'] && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        Database::insert('prayer_requests', [
            'site_id'        => Database::siteId(),
            'name'           => $v['name'],
            'email'          => $v['email'] ?: null,
            'phone'          => $v['phone'] ?: null,
            'intention_type' => $v['intention_type'] ?: null,
            'intention'      => $v['intention'],
            'is_anonymous'   => $v['is_anonymous'],
            'ip'             => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        // Notify — use prayer_email if set, otherwise fall back to admin_email
        $notifyEmail = setting('prayer_email') ?: setting('admin_email');
        if ($notifyEmail) {
            $displayName = $v['is_anonymous'] ? 'Anonymous' : htmlspecialchars($v['name']);
            $html = '<h2>New Prayer Request</h2>'
                  . '<p><strong>From:</strong> ' . $displayName . '</p>'
                  . ($v['intention_type'] ? '<p><strong>Type:</strong> ' . htmlspecialchars($v['intention_type']) . '</p>' : '')
                  . '<hr><p style="font-style:italic;">' . nl2br(htmlspecialchars($v['intention'])) . '</p>'
                  . '<p><a href="' . siteUrl('admin/prayers') . '">View all prayer requests</a></p>';
            Mailer::send($notifyEmail, 'Parish Admin', 'Prayer Request Received', $html);
        }

        $success = true;
    }
}

$intro = setting('form_intro_prayer', 'We are honored to pray with you. Please share your intention below and our parish community will hold you in prayer.');

$prayerPage = Database::fetch(
    "SELECT content FROM pages WHERE slug = 'prayer' AND site_id = ? AND status IN ('published','private')",
    [Database::siteId()]
);

renderPage('Prayer Request', function() use ($errors, $success, $v, $intro, $prayerPage) {
?>

<div class="page-wrap">

  <?php if (!empty($prayerPage['content'])): ?>
  <div class="page-content entry-content" style="margin-bottom: 32px;">
    <?= $prayerPage['content'] ?>
  </div>
  <?php endif; ?>

  <div class="pub-form-wrap">

    <div class="pub-form-header">
      <h1 class="entry-title">Prayer Request</h1>
      <p class="pub-form-intro"><?= h($intro) ?></p>
    </div>

    <?php if ($success): ?>
      <div class="pub-form-success">
        <div class="pub-form-success-icon">&#9827;</div>
        <h2>Your Intention Has Been Received</h2>
        <p>Thank you<?= !$v['is_anonymous'] ? ', ' . h($v['name']) : '' ?>. Our parish community will hold
        your intention in prayer.</p>
        <p><em>Pax et Bonum</em></p>
        <a href="<?= siteUrl('forms') ?>" class="btn-outline" style="margin-top:16px;">&larr; Back to Forms</a>
      </div>
    <?php else: ?>

      <?php if ($errors): ?>
        <div class="pub-form-errors">
          <?php foreach ($errors as $e): ?>
            <div>&#9679; <?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="pub-form" novalidate>
        <?= csrfField() ?>

        <div class="pub-form-section">
          <h2 class="pub-section-title">Your Information</h2>

          <div class="pub-form-row two-up">
            <div class="pub-form-group">
              <label for="pr-name">Your Name <span class="req">*</span></label>
              <input type="text" id="pr-name" name="name" class="pub-input"
                     value="<?= h($v['name']) ?>" required autocomplete="name">
            </div>
            <div class="pub-form-group">
              <label for="pr-type">Intention Type</label>
              <select id="pr-type" name="intention_type" class="pub-input">
                <option value="">— Select —</option>
                <?php foreach (['For Myself','For a Family Member','For a Friend','For Someone Who Has Died','General Intention'] as $t): ?>
                  <option value="<?= h($t) ?>" <?= $v['intention_type']===$t ? 'selected':'' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="pub-form-row two-up">
            <div class="pub-form-group">
              <label for="pr-email">Email <span class="pub-optional">(optional)</span></label>
              <input type="email" id="pr-email" name="email" class="pub-input"
                     value="<?= h($v['email']) ?>" autocomplete="email">
            </div>
            <div class="pub-form-group">
              <label for="pr-phone">Phone <span class="pub-optional">(optional)</span></label>
              <input type="tel" id="pr-phone" name="phone" class="pub-input"
                     value="<?= h($v['phone']) ?>" autocomplete="tel">
            </div>
          </div>

          <div class="pub-form-group">
            <label class="sacrament-check" style="justify-content:flex-start; display:inline-flex;">
              <input type="checkbox" name="is_anonymous" value="1"
                     <?= $v['is_anonymous'] ? 'checked' : '' ?>>
              <span>Submit anonymously — do not share my name with others</span>
            </label>
          </div>
        </div>

        <div class="pub-form-section">
          <h2 class="pub-section-title">Prayer Intention</h2>

          <div class="pub-form-group">
            <label for="pr-intention">Please share your intention <span class="req">*</span></label>
            <textarea id="pr-intention" name="intention" class="pub-input" rows="7"
                      required placeholder="Please pray for…"><?= h($v['intention']) ?></textarea>
          </div>
        </div>

        <?php if (setting('hcaptcha_site_key')): ?>
          <div class="pub-form-group">
            <div class="h-captcha" data-sitekey="<?= h(setting('hcaptcha_site_key')) ?>"></div>
          </div>
        <?php endif; ?>

        <div class="pub-form-actions">
          <button type="submit" class="pub-btn-primary">Submit Prayer Request</button>
        </div>
      </form>

    <?php endif; ?>
  </div>
</div>

<?php }, ['meta_desc' => 'Submit a prayer request to Your Parish.', 'hcaptcha' => true]); ?>
