<?php
// Blog listing template
$pg      = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)setting('posts_per_page', '10');
$offset  = ($pg - 1) * $perPage;
$cat     = trim($_GET['cat'] ?? '');

$where  = "WHERE p.status = 'published' AND p.published_at <= NOW() AND p.site_id = ?";
$params = [Database::siteId()];
if ($cat) {
    $where  .= " AND c.slug = ?";
    $params[] = $cat;
}

$total = Database::fetch(
    "SELECT COUNT(*) c FROM posts p LEFT JOIN categories c ON c.id = p.category_id $where",
    $params
)['c'];

$posts = Database::fetchAll(
    "SELECT p.*, u.name as author_name, c.name as cat_name, c.slug as cat_slug
     FROM posts p
     LEFT JOIN users u ON u.id = p.author_id
     LEFT JOIN categories c ON c.id = p.category_id
     $where
     ORDER BY p.published_at DESC, p.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

$cats = Database::fetchAll(
    "SELECT c.*, COUNT(p.id) as post_count FROM categories c
     LEFT JOIN posts p ON p.category_id = c.id AND p.status = 'published' AND p.published_at <= NOW() AND p.site_id = ?
     WHERE c.site_id = ?
     GROUP BY c.id HAVING post_count > 0 ORDER BY c.name",
    [Database::siteId(), Database::siteId()]
);

renderPage('Parish Blog', function() use ($posts, $total, $pg, $perPage, $cats, $cat) {
?>

<div class="page-wrap">
  <div class="two-col">
    <main>
      <header class="entry-header">
        <h1 class="entry-title">Parish News &amp; Reflections</h1>
        <?php if ($cat): ?>
          <p class="entry-meta">Filtered by category &mdash; <a href="<?= siteUrl('blog') ?>">View All</a></p>
        <?php endif; ?>
      </header>

      <?php if (empty($posts)): ?>
        <p style="color:var(--slate-lt); font-style:italic;">No posts published yet. Check back soon.</p>
      <?php endif; ?>

      <div class="blog-grid">
        <?php foreach ($posts as $p): ?>
          <div class="post-card">
            <a href="<?= siteUrl('blog/' . $p['slug']) ?>">
              <img src="<?= $p['featured_image'] ? mediaUrl($p['featured_image']) : siteUrl('public/assets/images/default-post.png') ?>"
                   alt="<?= h($p['title']) ?>">
            </a>
            <div class="post-card-body">
              <div class="post-meta">
                <?= formatDate($p['published_at'] ?: $p['created_at'], 'F j, Y') ?>
                <?php if ($p['cat_name']): ?>
                  &bull;
                  <a href="<?= siteUrl('blog?cat=' . $p['cat_slug']) ?>"><?= h($p['cat_name']) ?></a>
                <?php endif; ?>
              </div>
              <a href="<?= siteUrl('blog/' . $p['slug']) ?>" class="post-title"><?= h($p['title']) ?></a>
              <p class="post-excerpt"><?= h(excerpt($p['excerpt'] ?: $p['content'], 30)) ?></p>
              <a href="<?= siteUrl('blog/' . $p['slug']) ?>" class="btn-outline">Read More</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?= pagination($total, $pg, $perPage, siteUrl('blog') . ($cat ? '?cat=' . urlencode($cat) : '')) ?>
    </main>

    <aside>
      <?php include BASE_PATH . '/templates/sidebar.php'; ?>
    </aside>
  </div>
</div>

<?php }); ?>
