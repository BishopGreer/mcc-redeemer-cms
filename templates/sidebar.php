<?php
// Reusable sidebar — recent posts, categories, search
$recentSidebar = Database::fetchAll(
    "SELECT title, slug, published_at, created_at FROM posts
     WHERE status = 'published' ORDER BY published_at DESC, created_at DESC LIMIT 5"
);
$sideCats = Database::fetchAll(
    "SELECT c.name, c.slug, COUNT(p.id) as cnt FROM categories c
     LEFT JOIN posts p ON p.category_id = c.id AND p.status='published'
     GROUP BY c.id HAVING cnt > 0 ORDER BY c.name"
);
?>

<div class="sidebar-widget">
  <form method="get" action="<?= siteUrl('blog') ?>">
    <input type="text" name="q" placeholder="Search posts…" class="form-control"
           style="font-size:14px;" value="<?= h($_GET['q'] ?? '') ?>">
  </form>
</div>

<?php if ($recentSidebar): ?>
<div class="sidebar-widget">
  <h3 class="widget-title">Recent Posts</h3>
  <?php foreach ($recentSidebar as $rp): ?>
    <div style="margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid var(--sand);">
      <a href="<?= siteUrl('blog/' . $rp['slug']) ?>"
         style="font-size:14px; color:var(--brown); text-decoration:none; display:block; margin-bottom:2px;">
        <?= h($rp['title']) ?>
      </a>
      <span style="font-size:12px; color:var(--slate-lt); font-family:sans-serif;">
        <?= formatDate($rp['published_at'] ?: $rp['created_at'], 'M j, Y') ?>
      </span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($sideCats): ?>
<div class="sidebar-widget">
  <h3 class="widget-title">Categories</h3>
  <?php foreach ($sideCats as $sc): ?>
    <div style="margin-bottom:6px;">
      <a href="<?= siteUrl('blog?cat=' . $sc['slug']) ?>"
         style="font-size:14px; color:var(--brown); text-decoration:none;">
        <?= h($sc['name']) ?>
        <span style="color:var(--slate-lt); font-size:12px;">(<?= $sc['cnt'] ?>)</span>
      </a>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="sidebar-widget">
  <h3 class="widget-title">About Our Parish</h3>
  <p style="font-size:14px; color:var(--slate-lt); line-height:1.7;">
    Your Parish is a community of faith, rooted in the Gospel
    of Jesus Christ.
  </p>
  <div style="margin-top:12px;">
    <a href="<?= siteUrl('about') ?>" class="btn-outline" style="font-size:13px;">Learn More</a>
  </div>
</div>
