<?php
renderPage('Page Not Found', function() {
?>
<div class="page-wrap error-page">
  <h1>404</h1>
  <h2>Page Not Found</h2>
  <p style="color:var(--slate-lt); margin-bottom:24px;">
    The page you&rsquo;re looking for doesn&rsquo;t exist or has been moved.
  </p>
  <a href="<?= siteUrl() ?>" class="btn-outline">Return to Home</a>
</div>
<?php }); ?>
