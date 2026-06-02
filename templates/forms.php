<?php
require_once BASE_PATH . '/core/Forms.php';

$intro  = setting('form_intro_forms', 'Find all of our church forms below. We welcome your messages and any questions you may have.');
$siteId = Database::siteId();
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

renderPage('Church Forms', function() use ($intro, $cmsForms) {
?>

<div class="page-wrap">
  <div class="forms-page">

    <div class="forms-page__header">
      <h1 class="forms-page__title">Church Forms</h1>
      <p class="forms-page__intro"><?= h($intro) ?></p>
    </div>

    <div class="forms-page__grid">

      <a href="<?= siteUrl('contact') ?>" class="forms-card">
        <div class="forms-card__icon">&#9993;</div>
        <div class="forms-card__body">
          <h2 class="forms-card__title">Contact Us</h2>
          <p class="forms-card__desc">Send a message to our church staff. We welcome general
          inquiries, questions about worship, volunteer opportunities, and more.</p>
          <span class="forms-card__link">Open Form &rarr;</span>
        </div>
      </a>

      <?php foreach ($cmsForms as $cf): ?>
      <a href="<?= siteUrl('forms/' . $cf['slug']) ?>" class="forms-card">
        <div class="forms-card__icon">&#128196;</div>
        <div class="forms-card__body">
          <h2 class="forms-card__title"><?= h($cf['title']) ?></h2>
          <?php if ($cf['description']): ?>
          <p class="forms-card__desc"><?= h($cf['description']) ?></p>
          <?php endif; ?>
          <span class="forms-card__link">Open Form &rarr;</span>
        </div>
      </a>
      <?php endforeach; ?>

    </div>
  </div>
</div>

<?php }, ['meta_desc' => 'Church forms — contact us or reach out with any questions about MCC of Our Redeemer.']); ?>
