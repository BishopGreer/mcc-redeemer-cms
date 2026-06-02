<?php
Analytics::track();

$homePage = Database::fetch(
    "SELECT * FROM pages WHERE slug = 'home' AND site_id = ? AND status = 'published'",
    [Database::siteId()]
);

$blogEnabled = setting('blog_enabled', '0') === '1';
$recentPosts = [];
if ($blogEnabled) {
    $recentPosts = Database::fetchAll(
        "SELECT p.*, u.name as author_name FROM posts p
         LEFT JOIN users u ON u.id = p.author_id
         WHERE p.status = 'published' AND p.published_at <= NOW() AND p.site_id = ?
         ORDER BY p.published_at DESC, p.created_at DESC LIMIT 3",
        [Database::siteId()]
    );
}

// Home page feature cards
$cards = [];
for ($i = 1; $i <= 3; $i++) {
    $title = setting("home_card{$i}_title", '');
    if ($title) {
        $cards[] = [
            'title'     => $title,
            'text'      => setting("home_card{$i}_text",      ''),
            'link_text' => setting("home_card{$i}_link_text", ''),
            'link_url'  => setting("home_card{$i}_link_url",  ''),
        ];
    }
}

// Donation links
$paypalLink = setting('paypal_link', '');
$venmoLink  = setting('venmo_link',  '');
$donateTitle = setting('donate_page_title',   'Support Our Church');
$donateDesc  = setting('donate_description',  'Your generosity helps us continue our ministry.');

renderPage(setting('site_name', 'MCC Our Redeemer'), function() use ($homePage, $recentPosts, $cards, $paypalLink, $venmoLink, $donateTitle, $donateDesc, $blogEnabled) {
?>

<!-- Hero section -->
<div class="home-hero">
  <div class="hero-text">
    <h1 class="hero-title"><?= h(setting('site_name', 'MCC Our Redeemer')) ?></h1>
    <p class="hero-tagline"><?= h(setting('site_tagline', 'Open Hearts, Open Doors, Open Minds')) ?></p>
    <?php
    $heroBtnText = setting('hero_button_text', 'Worship With Us');
    $heroBtnUrl  = setting('hero_button_url',  siteUrl('worship'));
    if ($heroBtnText && $heroBtnUrl):
    ?>
    <a href="<?= h($heroBtnUrl) ?>" class="hero-cta"><?= h($heroBtnText) ?></a>
    <?php endif; ?>
  </div>
</div>

<!-- Home page CMS content -->
<?php if ($homePage && $homePage['content']): ?>
<div class="page-wrap" style="padding-top:40px; padding-bottom:0;">
  <div class="page-content entry-content">
    <?= $homePage['content'] ?>
  </div>
</div>
<?php endif; ?>

<!-- Feature cards -->
<?php if (!empty($cards)): ?>
<section class="home-cards-section">
  <div class="home-cards">
    <?php foreach ($cards as $card): ?>
    <div class="home-card">
      <h3 class="home-card-title"><?= h($card['title']) ?></h3>
      <?php if ($card['text']): ?>
      <p class="home-card-text"><?= h($card['text']) ?></p>
      <?php endif; ?>
      <?php if ($card['link_text'] && $card['link_url']): ?>
      <a href="<?= h($card['link_url']) ?>" class="btn btn-secondary btn-sm"><?= h($card['link_text']) ?></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Donation section -->
<?php if ($paypalLink || $venmoLink): ?>
<section class="donate-section" aria-label="Donate">
  <div class="donate-inner">
    <h2 class="donate-title"><?= h($donateTitle) ?></h2>
    <p class="donate-desc"><?= h($donateDesc) ?></p>
    <div class="donate-buttons">
      <?php if ($paypalLink): ?>
      <a href="<?= h($paypalLink) ?>" class="donate-btn donate-btn-paypal"
         target="_blank" rel="noopener noreferrer">
        <span class="donate-btn-icon">&#128179;</span>
        Give via PayPal
      </a>
      <?php endif; ?>
      <?php if ($venmoLink): ?>
      <a href="<?= h($venmoLink) ?>" class="donate-btn donate-btn-venmo"
         target="_blank" rel="noopener noreferrer">
        <span class="donate-btn-icon">&#128172;</span>
        Give via Venmo
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Recent Blog Posts (only when blog is enabled) -->
<?php if ($blogEnabled && !empty($recentPosts)): ?>
<section class="home-blog-section" style="background:var(--color-bg-alt, #F5EFE8); padding:56px 0;">
  <div class="page-wrap" style="padding-top:0; padding-bottom:0;">
    <h2 class="section-title" style="text-align:center; margin-bottom:8px;">News &amp; Reflections</h2>
    <p class="section-subtitle" style="text-align:center; margin-bottom:32px; color:var(--color-text-muted);">The latest from our community</p>

    <div class="blog-grid">
      <?php foreach ($recentPosts as $p): ?>
        <div class="blog-card">
          <?php if ($p['featured_image']): ?>
          <div class="blog-card-img">
            <img src="<?= h(mediaUrl($p['featured_image'])) ?>" alt="<?= h($p['title']) ?>" loading="lazy">
          </div>
          <?php endif; ?>
          <div class="blog-card-body">
            <div class="blog-card-meta"><?= formatDate($p['published_at'] ?: $p['created_at'], 'F j, Y') ?></div>
            <h3 class="blog-card-title">
              <a href="<?= siteUrl('blog/' . $p['slug']) ?>"><?= h($p['title']) ?></a>
            </h3>
            <p class="blog-card-excerpt"><?= h(excerpt($p['excerpt'] ?: $p['content'], 25)) ?></p>
            <a href="<?= siteUrl('blog/' . $p['slug']) ?>" class="btn btn-secondary btn-sm">Read More</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center; margin-top:32px;">
      <a href="<?= siteUrl('blog') ?>" class="btn btn-primary">View All Posts</a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php }, [
    'canonical' => rtrim(SITE_URL, '/') . '/',
    'json_ld'   => [
        '@context'     => 'https://schema.org',
        '@type'        => 'Church',
        '@id'          => rtrim(SITE_URL, '/') . '/#church',
        'name'         => setting('site_name', 'MCC Our Redeemer'),
        'description'  => setting('site_tagline', 'Open Hearts, Open Doors, Open Minds'),
        'url'          => rtrim(SITE_URL, '/') . '/',
        'telephone'    => setting('parish_phone') ?: null,
        'email'        => setting('admin_email')  ?: null,
        'address'      => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => setting('parish_address', ''),
            'addressLocality' => setting('parish_city',  'Augusta'),
            'addressRegion'   => setting('parish_state', 'GA'),
            'addressCountry'  => 'US',
        ],
    ],
]); ?>
