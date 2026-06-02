<?php
// Single page template
$featImg  = $page['featured_image'] ? mediaUrl($page['featured_image']) : null;
$pageUrl  = rtrim(SITE_URL, '/') . '/' . ltrim($page['slug'], '/');

// Process shortcodes before rendering
$pageContent = processShortcodes($page['content'] ?? '', ['page_id' => $page['id']]);

renderPage($page['meta_title'] ?: $page['title'], function() use ($page, $featImg, $pageContent) {
    $template = $page['template'] ?? 'default';
?>

<div class="page-wrap">
  <?php if ($template === 'full-width'): ?>
    <article class="entry-content">
      <header class="entry-header">
        <h1 class="entry-title"><?= h($page['title']) ?></h1>
      </header>
      <?php if ($featImg): ?><img src="<?= h($featImg) ?>" class="featured-image" alt="<?= h($page['title']) ?>"><?php endif; ?>
      <div class="entry-content"><?= $pageContent ?></div>
    </article>

  <?php elseif ($template === 'sidebar'): ?>
    <div class="two-col">
      <article>
        <header class="entry-header">
          <h1 class="entry-title"><?= h($page['title']) ?></h1>
        </header>
        <?php if ($featImg): ?><img src="<?= h($featImg) ?>" class="featured-image" alt="<?= h($page['title']) ?>"><?php endif; ?>
        <div class="entry-content"><?= $pageContent ?></div>
      </article>
      <aside>
        <?php include BASE_PATH . '/templates/sidebar.php'; ?>
      </aside>
    </div>

  <?php else: /* default */ ?>
    <article class="page-content">
      <header class="entry-header">
        <h1 class="entry-title"><?= h($page['title']) ?></h1>
      </header>
      <?php if ($featImg): ?><img src="<?= h($featImg) ?>" class="featured-image" alt="<?= h($page['title']) ?>"><?php endif; ?>
      <div class="entry-content"><?= $pageContent ?></div>
    </article>
  <?php endif; ?>
</div>

<?php }, [
    'meta_desc' => $page['meta_desc'] ?: $page['excerpt'],
    'canonical' => $pageUrl,
    'og_image'  => $featImg,
    'json_ld'   => [
        '@context'    => 'https://schema.org',
        '@type'       => 'WebPage',
        'name'        => $page['meta_title'] ?: $page['title'],
        'description' => $page['meta_desc'] ?: $page['excerpt'],
        'url'         => $pageUrl,
    ],
]); ?>
