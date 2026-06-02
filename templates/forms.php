<?php
require_once BASE_PATH . '/core/Forms.php';

$intro      = setting('form_intro_forms', 'Find all of our parish forms below. We welcome your messages, registration, and prayer intentions.');
$siteId     = Database::siteId();
$cmsForms = [];
try {
    $cmsForms = Database::fetchAll(
        "SELECT id, title, slug, description FROM custom_forms
         WHERE site_id = ? AND status = 'published' AND (requires_login = 0)
         ORDER BY created_at ASC",
        [$siteId]
    );
} catch (\Throwable) {
    // custom_forms table may not exist yet
}

renderPage('Forms', function() use ($intro, $cmsForms) {
?>

<div class="page-wrap">
  <div class="pub-form-wrap">

    <div class="pub-form-header">
      <h1 class="entry-title">Parish Forms</h1>
      <p class="pub-form-intro"><?= h($intro) ?></p>
    </div>

    <div class="forms-listing">

      <a href="<?= siteUrl('contact') ?>" class="forms-card">
        <div class="forms-card-icon">&#9993;</div>
        <div class="forms-card-body">
          <h2 class="forms-card-title">Contact Us</h2>
          <p class="forms-card-desc">Send a message to our parish staff. We welcome general
          inquiries, questions about sacraments, volunteer opportunities, and more.</p>
          <span class="forms-card-link">Open Form &rarr;</span>
        </div>
      </a>

      <a href="<?= siteUrl('prayer') ?>" class="forms-card">
        <div class="forms-card-icon">&#9827;</div>
        <div class="forms-card-body">
          <h2 class="forms-card-title">Prayer Request</h2>
          <p class="forms-card-desc">Share a prayer intention with our parish community. Requests
          may be submitted anonymously. We hold all intentions in confidence and in prayer.</p>
          <span class="forms-card-link">Open Form &rarr;</span>
        </div>
      </a>

      <?php foreach ($cmsForms as $cf): ?>
      <a href="<?= siteUrl('forms/' . $cf['slug']) ?>" class="forms-card">
        <div class="forms-card-icon">&#128196;</div>
        <div class="forms-card-body">
          <h2 class="forms-card-title"><?= h($cf['title']) ?></h2>
          <?php if ($cf['description']): ?>
          <p class="forms-card-desc"><?= h($cf['description']) ?></p>
          <?php endif; ?>
          <span class="forms-card-link">Open Form &rarr;</span>
        </div>
      </a>
      <?php endforeach; ?>

    </div>
  </div>
</div>

<?php }, ['meta_desc' => 'Parish forms — contact us, register your household, or submit a prayer request.']); ?>
