<?php
$errors  = [];
$success = false;
$values  = ['name'=>'','email'=>'','phone'=>'','subject'=>'','message'=>''];

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

    $values = [
        'name'    => trim($_POST['name']    ?? ''),
        'email'   => trim($_POST['email']   ?? ''),
        'phone'   => trim($_POST['phone']   ?? ''),
        'subject' => trim($_POST['subject'] ?? ''),
        'message' => trim($_POST['message'] ?? ''),
    ];

    if (empty($values['name']))    $errors[] = 'Your name is required.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (empty($values['message'])) $errors[] = 'Please enter a message.';

    if (empty($errors)) {
        Database::insert('contact_submissions', [
            'site_id' => Database::siteId(),
            'name'    => $values['name'],
            'email'   => $values['email'],
            'phone'   => $values['phone'],
            'subject' => $values['subject'],
            'message' => $values['message'],
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $notifyEmail = setting('contact_email') ?: setting('admin_email');
        if ($notifyEmail) {
            $html = '<h2>New Contact Form Submission</h2>'
                  . '<p><strong>From:</strong> ' . htmlspecialchars($values['name']) . ' &lt;' . htmlspecialchars($values['email']) . '&gt;</p>'
                  . ($values['phone'] ? '<p><strong>Phone:</strong> ' . htmlspecialchars($values['phone']) . '</p>' : '')
                  . '<p><strong>Subject:</strong> ' . htmlspecialchars($values['subject']) . '</p>'
                  . '<hr><p>' . nl2br(htmlspecialchars($values['message'])) . '</p>';
            Mailer::send($notifyEmail, 'MCC Our Redeemer', 'Contact Form: ' . ($values['subject'] ?: 'New Message'), $html);
        }

        $success = true;
    }
}

$intro = setting('form_intro_contact', 'We would love to hear from you. Fill out the form below and a member of our staff will be in touch soon.');

$contactPage = Database::fetch(
    "SELECT content FROM pages WHERE slug = 'contact' AND site_id = ? AND status IN ('published','private')",
    [Database::siteId()]
);

renderPage('Contact Us', function() use ($errors, $success, $values, $intro, $contactPage) {
?>

<div class="page-wrap" style="max-width: var(--wrap-wide);">

  <?php if (!empty($contactPage['content'])): ?>
  <div class="entry-content" style="max-width: 860px; margin: 0 auto 2rem;">
    <?= $contactPage['content'] ?>
  </div>
  <?php endif; ?>

  <div class="contact-form">

    <div style="margin-bottom: 1.75rem;">
      <h1 style="margin-bottom: 0.4rem;">Contact Us</h1>
      <p style="color: var(--color-text-muted); margin: 0;"><?= h($intro) ?></p>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success" style="text-align: center; padding: 2.5rem 2rem;">
        <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">&#10003;</div>
        <h2 style="margin-bottom: 0.5rem;">Message Sent</h2>
        <p>Thank you, <?= h($values['name']) ?>. We have received your message and will reply to
        <strong><?= h($values['email']) ?></strong> shortly.</p>
        <a href="<?= siteUrl() ?>" class="btn btn-primary" style="margin-top: 1.25rem;">Return to Home</a>
      </div>
    <?php else: ?>

      <?php if ($errors): ?>
        <div class="alert alert-error" style="margin-bottom: 1.5rem;">
          <?php foreach ($errors as $e): ?>
            <div>&#9679; <?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrfField() ?>

        <div class="form-row-two">
          <div class="form-group">
            <label class="form-label form-label-required" for="cf-name">Full Name</label>
            <input type="text" id="cf-name" name="name" class="form-control"
                   value="<?= h($values['name']) ?>" required autocomplete="name">
          </div>
          <div class="form-group">
            <label class="form-label form-label-required" for="cf-email">Email Address</label>
            <input type="email" id="cf-email" name="email" class="form-control"
                   value="<?= h($values['email']) ?>" required autocomplete="email">
          </div>
        </div>

        <div class="form-row-two">
          <div class="form-group">
            <label class="form-label" for="cf-phone">Phone <span style="font-weight:400; color:var(--color-text-muted);">(optional)</span></label>
            <input type="tel" id="cf-phone" name="phone" class="form-control"
                   value="<?= h($values['phone']) ?>" autocomplete="tel">
          </div>
          <div class="form-group">
            <label class="form-label" for="cf-subject">Subject</label>
            <select id="cf-subject" name="subject" class="form-control">
              <?php
              $subjects = ['General Inquiry','Prayer Request','Worship & Services',
                           'Volunteer Opportunities','Membership','Other'];
              foreach ($subjects as $sub):
              ?>
                <option value="<?= h($sub) ?>" <?= $values['subject']===$sub ? 'selected':'' ?>>
                  <?= h($sub) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label form-label-required" for="cf-message">Message</label>
          <textarea id="cf-message" name="message" class="form-control" rows="8"
                    required><?= h($values['message']) ?></textarea>
        </div>

        <?php if (setting('hcaptcha_site_key')): ?>
          <div class="form-group">
            <div class="h-captcha" data-sitekey="<?= h(setting('hcaptcha_site_key')) ?>"></div>
          </div>
        <?php endif; ?>

        <div style="margin-top: 1.5rem;">
          <button type="submit" class="btn btn-primary btn-lg">Send Message</button>
        </div>

      </form>

    <?php endif; ?>

    <?php if (setting('parish_address') || setting('parish_phone') || setting('admin_email')): ?>
    <div class="contact-info" style="margin-top: 2.5rem; border-top: 1px solid var(--color-border); padding-top: 2rem;">
      <?php if (setting('parish_address')): ?>
        <div class="contact-info-item">
          <span class="contact-info-icon">&#128205;</span>
          <div><?= nl2br(h(setting('parish_address'))) ?></div>
        </div>
      <?php endif; ?>
      <?php if (setting('parish_phone')): ?>
        <div class="contact-info-item">
          <span class="contact-info-icon">&#128222;</span>
          <div><a href="tel:<?= h(setting('parish_phone')) ?>"><?= h(setting('parish_phone')) ?></a></div>
        </div>
      <?php endif; ?>
      <?php if (setting('admin_email')): ?>
        <div class="contact-info-item">
          <span class="contact-info-icon">&#9993;</span>
          <div><a href="mailto:<?= h(setting('admin_email')) ?>"><?= h(setting('admin_email')) ?></a></div>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php }, ['meta_desc' => 'Contact MCC Our Redeemer in Augusta, GA.', 'hcaptcha' => true]); ?>
