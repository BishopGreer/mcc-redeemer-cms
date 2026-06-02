<?php
// Single post template
$featImg = $post['featured_image'] ? mediaUrl($post['featured_image']) : null;
$postUrl = rtrim(SITE_URL, '/') . '/blog/' . $post['slug'];
$pubDate = $post['published_at'] ?: $post['created_at'];

renderPage($post['meta_title'] ?: $post['title'], function() use ($post, $featImg) {
    $category = $post['category_id']
        ? Database::fetch("SELECT * FROM categories WHERE id = ?", [$post['category_id']])
        : null;
    $prev = Database::fetch(
        "SELECT title, slug FROM posts WHERE status='published' AND published_at < ? ORDER BY published_at DESC LIMIT 1",
        [$post['published_at'] ?: $post['created_at']]
    );
    $next = Database::fetch(
        "SELECT title, slug FROM posts WHERE status='published' AND published_at > ? ORDER BY published_at ASC LIMIT 1",
        [$post['published_at'] ?: $post['created_at']]
    );
?>

<div class="page-wrap">
  <div class="two-col">
    <main>
      <article>
        <header class="entry-header">
          <?php if ($category): ?>
            <div class="entry-meta" style="margin-bottom:6px;">
              <a href="<?= siteUrl('blog?cat=' . $category['slug']) ?>"><?= h($category['name']) ?></a>
            </div>
          <?php endif; ?>
          <h1 class="entry-title"><?= h($post['title']) ?></h1>
          <div class="entry-meta">
            <?= formatDate($post['published_at'] ?: $post['created_at'], 'F j, Y') ?>
          </div>
        </header>

        <?php if ($featImg): ?>
          <img src="<?= h($featImg) ?>" class="featured-image" alt="<?= h($post['title']) ?>">
        <?php endif; ?>

        <div class="entry-content">
          <?= $post['content'] ?>
        </div>
      </article>

      <!-- Post navigation -->
      <nav style="display:flex; justify-content:space-between; margin-top:36px; padding-top:20px; border-top:1px solid var(--sand); font-family:sans-serif; font-size:14px; gap:16px;">
        <?php if ($prev): ?>
          <div>
            <div style="color:var(--slate-lt); margin-bottom:3px;">&larr; Previous</div>
            <a href="<?= siteUrl('blog/' . $prev['slug']) ?>" style="color:var(--brown);">
              <?= h(truncate($prev['title'], 50)) ?>
            </a>
          </div>
        <?php else: ?><div></div><?php endif; ?>
        <?php if ($next): ?>
          <div style="text-align:right;">
            <div style="color:var(--slate-lt); margin-bottom:3px;">Next &rarr;</div>
            <a href="<?= siteUrl('blog/' . $next['slug']) ?>" style="color:var(--brown);">
              <?= h(truncate($next['title'], 50)) ?>
            </a>
          </div>
        <?php endif; ?>
      </nav>

      <div style="margin-top:20px;">
        <a href="<?= siteUrl('blog') ?>" class="btn-outline">&larr; Back to Blog</a>
      </div>
    </main>

    <aside>
      <?php include BASE_PATH . '/templates/sidebar.php'; ?>
    </aside>
  </div>
</div>

<?php }, [
    'meta_desc'       => $post['meta_desc'] ?: $post['excerpt'],
    'canonical'       => $postUrl,
    'og_image'        => $featImg,
    'og_type'         => 'article',
    'og_article_time' => date('c', strtotime($pubDate)),
    'json_ld'         => array_filter([
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'headline'      => $post['title'],
        'description'   => $post['meta_desc'] ?: $post['excerpt'],
        'url'           => $postUrl,
        'datePublished' => date('c', strtotime($pubDate)),
        'dateModified'  => date('c', strtotime($post['updated_at'] ?? $pubDate)),
        'image'         => $featImg ?: null,
        'author'        => ['@type' => 'Organization', 'name' => setting('site_name', 'Your Parish')],
        'publisher'     => ['@type' => 'Organization', 'name' => setting('site_name', 'Your Parish')],
    ]),
]); ?>
