<?php
// Homepage template
$homePage = Database::fetch(
    "SELECT * FROM pages WHERE slug = 'home' AND site_id = ? AND status = 'published'",
    [Database::siteId()]
);
$featImage = $homePage && $homePage['featured_image'] ? mediaUrl($homePage['featured_image']) : null;

$recentPosts = Database::fetchAll(
    "SELECT p.*, u.name as author_name FROM posts p
     LEFT JOIN users u ON u.id = p.author_id
     WHERE p.status = 'published' AND p.published_at <= NOW() AND p.site_id = ?
     ORDER BY p.published_at DESC, p.created_at DESC LIMIT 3",
    [Database::siteId()]
);

renderPage(setting('site_name'), function() use ($homePage, $featImage, $recentPosts) {
?>

<!-- Hero -->
<div class="hero">
  <?php if ($featImage): ?>
    <div style="position:absolute;inset:0;background:url(<?= h($featImage) ?>) center/cover no-repeat; opacity:.25;"></div>
    <div style="position:relative; z-index:1;">
  <?php endif; ?>

  <h1><?= h(setting('site_name', 'Your Parish')) ?></h1>
  <p><?= h(setting('site_tagline', 'A Community of Faith')) ?></p>
  <?php
  $heroBtnText = setting('hero_button_text', 'Mass Times & Sacraments');
  $heroBtnUrl  = setting('hero_button_url',  siteUrl('mass-and-sacraments'));
  if ($heroBtnText && $heroBtnUrl):
  ?>
  <a href="<?= h($heroBtnUrl) ?>" class="hero-cta"><?= h($heroBtnText) ?></a>
  <?php endif; ?>

  <?php if ($featImage): ?></div><?php endif; ?>
</div>

<!-- Mass Times Bar -->
<?php if (setting('mass_schedule_short')): ?>
<div class="mass-times-bar">
  &#9827; <?= h(setting('mass_schedule_short')) ?>
</div>
<?php endif; ?>

<!-- Home page content -->
<?php if ($homePage && $homePage['content']): ?>
<div class="page-wrap">
  <div class="page-content entry-content">
    <?= $homePage['content'] ?>
  </div>
</div>
<?php endif; ?>

<!-- Recent Blog Posts -->
<?php if ($recentPosts): ?>
<div style="background:var(--white); padding:48px 0;">
  <div class="page-wrap" style="padding-top:0; padding-bottom:0;">
    <h2 class="section-title">Parish News &amp; Reflections</h2>
    <p class="section-subtitle">The latest from our community</p>

    <div class="blog-grid">
      <?php foreach ($recentPosts as $p): ?>
        <div class="post-card">
          <img src="<?= $p['featured_image'] ? mediaUrl($p['featured_image']) : siteUrl('public/assets/images/default-post.png') ?>"
               alt="<?= h($p['title']) ?>">
          <div class="post-card-body">
            <div class="post-meta"><?= formatDate($p['published_at'] ?: $p['created_at'], 'F j, Y') ?></div>
            <a href="<?= siteUrl('blog/' . $p['slug']) ?>" class="post-title"><?= h($p['title']) ?></a>
            <p class="post-excerpt"><?= h(excerpt($p['excerpt'] ?: $p['content'], 25)) ?></p>
            <a href="<?= siteUrl('blog/' . $p['slug']) ?>" class="btn-outline">Read More</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center; margin-top:28px;">
      <a href="<?= siteUrl('blog') ?>" class="btn-outline">View All Posts</a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php }, [
    'canonical' => rtrim(SITE_URL, '/') . '/',
    'json_ld'   => array_filter([
        '@context'    => 'https://schema.org',
        '@type'       => ['Organization', 'Church'],
        '@id'         => rtrim(SITE_URL, '/') . '/#organization',
        'name'        => setting('site_name', 'Your Parish'),
        'alternateName' => 'Your Parish',
        'description' => setting('site_tagline', ''),
        'url'         => rtrim(SITE_URL, '/') . '/',
        'logo'        => rtrim(SITE_URL, '/') . '/public/assets/images/site-banner.png',
        'telephone'   => setting('parish_phone') ?: null,
        'email'       => setting('admin_email') ?: null,
        'address'     => setting('parish_address') ? [
            '@type'           => 'PostalAddress',
            'streetAddress'   => setting('parish_address'),
        ] : null,
        'sameAs'      => array_values(array_filter([
            setting('social_fb_page_name')   ? 'https://www.facebook.com/' . setting('social_fb_page_name') : null,
        ])),
    ]),
]); ?>
