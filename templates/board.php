<?php
require_once BASE_PATH . '/core/Analytics.php';
Analytics::track();

$members = Database::fetchAll(
    "SELECT b.*, m.path as photo_path, m.alt_text as photo_alt, m.width as photo_w, m.height as photo_h
     FROM board_members b
     LEFT JOIN media m ON m.id = b.photo_id
     WHERE b.site_id = ? AND b.is_active = 1
     ORDER BY b.display_order ASC, b.name ASC",
    [Database::siteId()]
);

renderPage('Board of Directors', function() use ($members) {
?>
<div class="page-wrap">
  <h1 class="page-heading">Board of Directors</h1>
  <p class="page-intro">Meet the dedicated leaders guiding our community in faith and service.</p>

  <?php if (empty($members)): ?>
  <p style="color:#6b7280; text-align:center; padding:40px 0;">Board information coming soon.</p>
  <?php else: ?>
  <div class="board-grid">
    <?php foreach ($members as $m): ?>
    <div class="board-card">
      <?php if ($m['photo_path']): ?>
      <div class="board-photo-wrap">
        <img src="<?= h(UPLOAD_URL . '/' . $m['photo_path']) ?>"
             alt="<?= h($m['photo_alt'] ?: $m['name']) ?>"
             class="board-photo"
             <?= $m['photo_w'] ? 'width="' . $m['photo_w'] . '" height="' . $m['photo_h'] . '"' : '' ?>
             loading="lazy">
      </div>
      <?php else: ?>
      <div class="board-photo-wrap board-photo-placeholder">
        <span class="board-initials"><?= h(mb_substr($m['name'], 0, 1)) ?></span>
      </div>
      <?php endif; ?>
      <div class="board-info">
        <h2 class="board-name"><?= h($m['name']) ?></h2>
        <?php if ($m['title']): ?>
        <p class="board-title"><?= h($m['title']) ?></p>
        <?php endif; ?>
        <?php if ($m['bio']): ?>
        <div class="board-bio"><?= $m['bio'] ?></div>
        <?php endif; ?>
        <?php if ($m['email']): ?>
        <a href="mailto:<?= h($m['email']) ?>" class="board-email"><?= h($m['email']) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php
});
