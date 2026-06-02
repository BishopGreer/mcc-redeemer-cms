<?php
$search  = trim($_GET['s']       ?? '');
$country = trim($_GET['country'] ?? '');

$locatorPage = Database::fetch(
    "SELECT content FROM pages WHERE slug = 'find-a-parish' AND site_id = ? AND status IN ('published','private')",
    [Database::siteId()]
);

// Guard: table may not exist yet — use try/catch instead of SHOW TABLES
try {
    Database::fetch("SELECT 1 FROM parish_locator LIMIT 1");
} catch (\Throwable) {
    renderPage('Find a Parish', fn() => print(
        '<div class="page-wrap"><p>Parish directory coming soon.</p></div>'
    ));
    return;
}

$where  = "status = 'active'";
$params = [];

if ($search) {
    $like    = '%' . $search . '%';
    $where  .= ' AND (name LIKE ? OR city LIKE ? OR pastor_name LIKE ? OR country LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like, $like]);
}
if ($country) {
    $where  .= ' AND country = ?';
    $params[] = $country;
}

$parishes  = Database::fetchAll(
    "SELECT * FROM parish_locator WHERE {$where} ORDER BY menu_order ASC, country ASC, name ASC",
    $params
);
$countries = Database::fetchAll(
    "SELECT DISTINCT country FROM parish_locator WHERE status = 'active' ORDER BY country ASC"
);

renderPage('Find a Parish', function() use ($parishes, $countries, $search, $country, $locatorPage) {
?>

<div class="page-wrap">

  <?php if (!empty($locatorPage['content'])): ?>
  <div class="page-content entry-content" style="margin-bottom:32px;">
    <?= $locatorPage['content'] ?>
  </div>
  <?php else: ?>
  <h1 class="entry-title" style="margin-bottom:6px;">Find a Parish</h1>
  <p style="color:var(--muted); margin-bottom:32px; font-size:17px;">
    Old Catholic Churches International parishes worldwide.
  </p>
  <?php endif; ?>

  <!-- Search / Filter -->
  <form method="get" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:36px;
                             background:var(--sand); border:1px solid var(--line); border-radius:6px; padding:18px 20px;">
    <div>
      <label style="display:block; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); margin-bottom:5px;">Search</label>
      <input type="text" name="s" value="<?= h($search) ?>" placeholder="Name, city, or pastor..."
             style="padding:8px 12px; border:1px solid var(--line); border-radius:4px; font-size:15px; width:240px; background:#fff;">
    </div>
    <?php if (count($countries) > 1): ?>
    <div>
      <label style="display:block; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); margin-bottom:5px;">Country</label>
      <select name="country"
              style="padding:8px 12px; border:1px solid var(--line); border-radius:4px; font-size:15px; background:#fff;">
        <option value="">All Countries</option>
        <?php foreach ($countries as $c): ?>
          <option value="<?= h($c['country']) ?>" <?= $country === $c['country'] ? 'selected' : '' ?>>
            <?= h($c['country']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn-outline" style="padding:8px 20px;">Search</button>
    <?php if ($search || $country): ?>
      <a href="<?= siteUrl('find-a-parish') ?>"
         style="padding:8px 14px; color:var(--muted); font-size:14px; align-self:center;">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (empty($parishes)): ?>
    <p style="color:var(--muted); text-align:center; padding:48px 0; font-size:17px;">
      No parishes found<?= ($search || $country) ? ' matching your search' : '' ?>.
    </p>
  <?php else: ?>
    <p style="font-size:13px; color:var(--muted); margin-bottom:24px;">
      <?= count($parishes) ?> parish(es) found
    </p>

    <p id="parish-sort-notice"
       style="font-size:13px; color:var(--muted); margin-bottom:16px; font-style:italic;"></p>

    <div id="parish-grid"
         style="display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:28px;">
      <?php foreach ($parishes as $p):
        // Determine map embed src
        $mapSrc = '';
        if (!empty($p['map_embed_url'])) {
            $mapSrc = $p['map_embed_url'];
        } elseif (!empty($p['latitude']) && !empty($p['longitude'])) {
            $lat  = (float)$p['latitude'];
            $lng  = (float)$p['longitude'];
            $bw   = $lng - 0.012; $bs = $lat - 0.008;
            $be   = $lng + 0.012; $bn = $lat + 0.008;
            $mapSrc = "https://www.openstreetmap.org/export/embed.html"
                    . "?bbox={$bw}%2C{$bs}%2C{$be}%2C{$bn}&layer=mapnik&marker={$lat}%2C{$lng}";
        }

        // Google Maps directions link — use coords if available, else full address
        if (!empty($p['latitude']) && !empty($p['longitude'])) {
            $dest = $p['latitude'] . ',' . $p['longitude'];
        } else {
            $addrParts = array_filter([
                $p['address_line1'], $p['address_line2'],
                $p['city'], $p['state_province'], $p['postal_code'], $p['country'],
            ]);
            $dest = implode(', ', $addrParts) ?: $p['name'];
        }
        $gmaps = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($dest);
      ?>
      <div data-lat="<?= h($p['latitude'] ?? '') ?>" data-lng="<?= h($p['longitude'] ?? '') ?>"
           style="background:var(--white); border:1px solid var(--line); border-radius:6px;
                  overflow:hidden; box-shadow:var(--shadow); display:flex; flex-direction:column;">

        <?php if ($mapSrc): ?>
          <iframe src="<?= h($mapSrc) ?>"
                  width="100%" height="210" style="border:0; display:block; flex-shrink:0;"
                  loading="lazy"
                  title="Map for <?= h($p['name']) ?>"></iframe>
        <?php endif; ?>

        <div style="padding:22px; flex:1; display:flex; flex-direction:column;">

          <h2 style="margin:0 0 2px; font-size:1.18rem; color:var(--navy); line-height:1.25;">
            <?= h($p['name']) ?>
          </h2>

          <span class="parish-distance"
                style="display:none; font-size:12px; color:var(--muted); margin-bottom:6px;
                       background:var(--sand); border:1px solid var(--line); border-radius:10px;
                       padding:2px 8px;">
          </span>

          <?php if ($p['pastor_name']): ?>
            <p style="margin:0 0 12px; color:var(--muted); font-size:14px; font-style:italic;">
              <?= h($p['pastor_name']) ?>
            </p>
          <?php endif; ?>

          <?php if ($p['description']): ?>
            <p style="margin:0 0 14px; font-size:15px; color:var(--text);">
              <?= nl2br(h($p['description'])) ?>
            </p>
          <?php endif; ?>

          <div style="margin-top:auto; padding-top:14px; border-top:1px solid var(--line);">

            <?php if ($p['address_line1'] || $p['city']): ?>
              <div style="display:flex; gap:8px; margin-bottom:8px; font-size:14px; color:var(--text); align-items:flex-start;">
                <span style="color:var(--muted); flex-shrink:0; line-height:1.6;">&#128205;</span>
                <div>
                  <?php if ($p['address_line1']): ?><div><?= h($p['address_line1']) ?></div><?php endif; ?>
                  <?php if ($p['address_line2']): ?><div><?= h($p['address_line2']) ?></div><?php endif; ?>
                  <?php $cityLine = implode(', ', array_filter([$p['city'], $p['state_province'], $p['postal_code']])); ?>
                  <?php if ($cityLine): ?><div><?= h($cityLine) ?></div><?php endif; ?>
                  <?php if ($p['country']): ?><div><?= h($p['country']) ?></div><?php endif; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($p['phone']): ?>
              <div style="display:flex; gap:8px; margin-bottom:6px; font-size:14px; align-items:center;">
                <span style="color:var(--muted);">&#128222;</span>
                <a href="tel:<?= h(preg_replace('/[^\d+]/', '', $p['phone'])) ?>"><?= h($p['phone']) ?></a>
              </div>
            <?php endif; ?>

            <?php if ($p['email']): ?>
              <div style="display:flex; gap:8px; margin-bottom:6px; font-size:14px; align-items:center;">
                <span style="color:var(--muted);">&#9993;</span>
                <a href="mailto:<?= h($p['email']) ?>"><?= h($p['email']) ?></a>
              </div>
            <?php endif; ?>

            <?php if ($p['website']): ?>
              <div style="display:flex; gap:8px; margin-bottom:6px; font-size:14px; align-items:center;">
                <span style="color:var(--muted);">&#127760;</span>
                <a href="<?= h($p['website']) ?>" target="_blank" rel="noopener noreferrer">
                  <?= h(preg_replace('#^https?://#', '', rtrim($p['website'], '/'))) ?>
                </a>
              </div>
            <?php endif; ?>

            <div style="margin-top:14px;">
              <a href="<?= h($gmaps) ?>" target="_blank" rel="noopener noreferrer"
                 class="btn-outline" style="font-size:13px; padding:6px 14px;">
                &#128205; Get Directions
              </a>
            </div>

          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>

<script src="<?= siteUrl('public/assets/js/parish-locator.js') ?>" defer></script>

<?php }, [
    'meta_desc' => 'Find an Old Catholic Churches International parish near you.',
]); ?>
