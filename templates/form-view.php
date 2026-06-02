<?php
/**
 * Public form display template.
 * Route: /forms/{slug}  (GET = display form, POST = process submission)
 *
 * Expected in scope: $formSlug (string)
 */
require_once BASE_PATH . '/core/Forms.php';

$siteId  = Database::siteId();
$form    = Database::fetch(
    "SELECT * FROM custom_forms WHERE slug = ? AND site_id = ? AND status = 'published'",
    [$formSlug, $siteId]
);

if (!$form) {
    http_response_code(404);
    require BASE_PATH . '/templates/404.php';
    exit;
}

// Access control
if ($form['requires_login'] && !Auth::check()) {
    flash('info', 'Please log in to access this form.');
    redirect(siteUrl('admin/login?next=' . rawurlencode(siteUrl('forms/' . $formSlug))));
}

$errors  = [];
$values  = [];
$success = false;

// Process POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ((int)($_POST['_form_id'] ?? 0)) === (int)$form['id']) {
    $result = Forms::processSubmission($form);
    if (!empty($result['success'])) {
        $success = true;
    } else {
        $errors = $result['errors'] ?? [];
        // Preserve submitted values for re-display
        $values = $_POST['ff'] ?? [];
    }
}

$successMsg = $form['success_msg'] ?: 'Thank you — your form has been submitted successfully.';
$siteKey    = setting('hcaptcha_site_key');

renderPage(
    h($form['title']),
    function () use ($form, $success, $successMsg, $errors, $values) {
        ?>
        <div class="page-wrap">
          <article class="page-content">
            <header class="entry-header">
              <h1 class="entry-title"><?= h($form['title']) ?></h1>
              <?php if ($form['description']): ?>
              <p class="entry-intro"><?= nl2br(h($form['description'])) ?></p>
              <?php endif; ?>
            </header>

            <?php if ($success): ?>
            <div class="form-success-banner">
              <div class="form-success-icon">&#10003;</div>
              <p><?= h($successMsg) ?></p>
            </div>
            <?php else: ?>

            <?php if (!empty($errors) && !isset($errors['_hcaptcha'])): ?>
            <div class="form-alert form-alert-error">
              Please correct the errors below and resubmit.
            </div>
            <?php endif; ?>

            <?= Forms::renderForm($form, $values, $errors) ?>

            <?php endif; ?>
          </article>
        </div>
        <?php
    },
    [
        'meta_desc' => $form['description'] ?: $form['title'],
        'og_type'   => 'website',
        'hcaptcha'  => $form['use_hcaptcha'] ?? false,
    ]
);
