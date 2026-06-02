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

        // Notify — use contact_email if set, otherwise fall back to admin_email
        $notifyEmail = setting('contact_email') ?: setting('admin_email');
        if ($notifyEmail) {
            $html = '<h2>New Contact Form Submission</h2>'
                  . '<p><strong>From:</strong> ' . htmlspecialchars($values['name']) . ' &lt;' . htmlspecialchars($values['email']) . '&gt;</p>'
                  . ($values['phone'] ? '<p><strong>Phone:</strong> ' . htmlspecialchars($values['phone']) . '</p>' : '')
                  . '<p><strong>Subject:</strong> ' . htmlspecialchars($values['subject']) . '</p>'
                  . '<hr><p>' . nl2br(htmlspecialchars($values['message'])) . '</p>';
            Mailer::send($notifyEmail, 'Parish Admin', 'Contact Form: ' . ($values['subject'] ?: 'New Message'), $html);
        }

        $success = true;
    }
}

$intro = setting('form_intro_contact', 'We would love to hear from you. Fill out the form below and a member of our parish staff will be in touch soon.');

$contactPage = Database::fetch(
    "SELECT content FROM pages WHERE slug = 'contact' AND site_id = ? AND status IN ('published','private')",
    [Database::siteId()]
);

renderPage('Contact Us', function() use ($errors, $success, $values, $intro, $contactPage) {
?>

<div class="page-wrap">

  <?php if (!empty($contactPage['content'])): ?>
  <div class="page-content entry-content" style="margin-bottom: 32px;">
    <?= $contactPage['content'] ?>
  </div>
  <?php endif; ?>

  <div class="pub-form-wrap">

    <div class="pub-form-header">
      <h1 class="entry-title">Contact Us</h1>
      <p class="pub-form-intro"><?= h($intro) ?></p>
    </div>

    <?php if ($success): ?>
      <div class="pub-form-success">
        <div class="pub-form-success-icon">&#10003;</div>
        <h2>Message Sent</h2>
        <p>Thank you, <?= h($values['name']) ?>. We have received your message and will reply to
        <strong><?= h($values['email']) ?></strong> shortly.</p>
        <p><em>Pax et Bonum</em></p>
        <a href="<?= siteUrl() ?>" class="btn-outline" style="margin-top:16px;">Return to Home</a>
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

        <div class="pub-form-row two-up">
          <div class="pub-form-group">
            <label for="cf-name">Full Name <span class="req">*</span></label>
            <input type="text" id="cf-name" name="name" class="pub-input"
                   value="<?= h($values['name']) ?>" required autocomplete="name">
          </div>
          <div class="pub-form-group">
            <label for="cf-email">Email Address <span class="req">*</span></label>
            <input type="email" id="cf-email" name="email" class="pub-input"
                   value="<?= h($values['email']) ?>" required autocomplete="email">
          </div>
        </div>

        <div class="pub-form-row two-up">
          <div class="pub-form-group">
            <label for="cf-phone">Phone <span class="pub-optional">(optional)</span></label>
            <input type="tel" id="cf-phone" name="phone" class="pub-input"
                   value="<?= h($values['phone']) ?>" autocomplete="tel">
          </div>
          <div class="pub-form-group">
            <label for="cf-subject">Subject</label>
            <select id="cf-subject" name="subject" class="pub-input">
              <?php
              $subjects = ['General Inquiry','Sacraments','Religious Education',
                           'Volunteer Opportunities','Prayer Request','Other'];
              foreach ($subjects as $sub):
              ?>
                <option value="<?= h($sub) ?>" <?= $values['subject']===$sub ? 'selected':'' ?>>
                  <?= h($sub) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="pub-form-group">
          <label for="cf-message">Message <span class="req">*</span></label>
          <textarea id="cf-message" name="message" class="pub-input" rows="7"
                    required><?= h($values['message']) ?></textarea>
        </div>

        <?php if (setting('hcaptcha_site_key')): ?>
          <div class="pub-form-group">
            <div class="h-captcha" data-sitekey="<?= h(setting('hcaptcha_site_key')) ?>"></div>
          </div>
        <?php endif; ?>

        <div class="pub-form-actions">
          <button type="submit" class="pub-btn-primary">Send Message</button>
        </div>
      </form>

    <?php endif; ?>

    <div class="pub-form-contact-info">
      <?php if (setting('parish_address')): ?>
        <div class="pub-contact-item">
          <span class="pub-contact-icon">&#9679;</span>
          <div><?= nl2br(h(setting('parish_address'))) ?></div>
        </div>
      <?php endif; ?>
      <?php if (setting('parish_phone')): ?>
        <div class="pub-contact-item">
          <span class="pub-contact-icon">&#9829;</span>
          <div><a href="tel:<?= h(setting('parish_phone')) ?>"><?= h(setting('parish_phone')) ?></a></div>
        </div>
      <?php endif; ?>
      <?php if (setting('admin_email')): ?>
        <div class="pub-contact-item">
          <span class="pub-contact-icon">&#9993;</span>
          <div><a href="mailto:<?= h(setting('admin_email')) ?>"><?= h(setting('admin_email')) ?></a></div>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php }, ['meta_desc' => 'Contact Your Parish.', 'hcaptcha' => true]); ?>
