<?php
// Editable intro content
$clergyPage = Database::fetch(
    "SELECT content FROM pages WHERE slug = 'clergy-directory' AND site_id = ? AND status IN ('published','private')",
    [Database::siteId()]
);

// Position display labels and canonical sort order
$positionLabels = [
    'presiding_bishop' => 'Presiding Bishop',
    'chancellor'       => 'Chancellor',
    'bishop'           => 'Bishop',
    'monsignor'        => 'Monsignor',
    'priest'           => 'Priest',
    'deacon'           => 'Deacon',
    'subdeacon'        => 'SubDeacon',
    'religious'        => 'Religious',
    'seminarian'       => 'Seminarian',
    'candidate'        => 'Candidate',
    'laity'            => 'Laity',
];

$socialMeta = [
    'social_facebook'  => ['label' => 'Facebook',   'icon' => 'f'],
    'social_instagram' => ['label' => 'Instagram',  'icon' => '&#128247;'],
    'social_twitter'   => ['label' => 'X',          'icon' => '&#120143;'],
    'social_threads'   => ['label' => 'Threads',    'icon' => '&#64;'],
    'social_youtube'   => ['label' => 'YouTube',    'icon' => '&#9654;'],
    'social_tiktok'    => ['label' => 'TikTok',     'icon' => '&#9835;'],
    'social_linkedin'  => ['label' => 'LinkedIn',   'icon' => 'in'],
    'social_bluesky'   => ['label' => 'BlueSky',    'icon' => '&#9729;'],
    'social_pinterest' => ['label' => 'Pinterest',  'icon' => 'P'],
    'social_snapchat'  => ['label' => 'Snapchat',   'icon' => '&#128126;'],
    'social_mastodon'  => ['label' => 'Mastodon',   'icon' => 'M'],
    'social_pixelfed'  => ['label' => 'Pixelfed',   'icon' => 'P'],
];

// Guard: table may not exist yet — use try/catch instead of SHOW TABLES
try {
    Database::fetch("SELECT 1 FROM clergy LIMIT 1");
} catch (\Throwable) {
    renderPage('Clergy Directory', fn() => print(
        '<div class="page-wrap"><p>Clergy directory coming soon.</p></div>'
    ));
    return;
}

// Fetch active clergy in canonical position order, then by last name
$posOrder = implode("','", array_keys($positionLabels));
$allClergy = Database::fetchAll(
    "SELECT * FROM clergy WHERE status = 'active'
     ORDER BY FIELD(position, '{$posOrder}'), menu_order ASC, last_name ASC, first_name ASC"
);

// Group by position
$grouped = [];
foreach ($allClergy as $c) {
    $grouped[$c['position']][] = $c;
}

renderPage('Clergy Directory', function() use ($clergyPage, $grouped, $positionLabels, $socialMeta) {
?>

<div class="page-wrap">

  <?php if (!empty($clergyPage['content'])): ?>
    <div class="page-content entry-content" style="margin-bottom:36px;">
      <?= $clergyPage['content'] ?>
    </div>
  <?php else: ?>
    <h1 class="entry-title" style="margin-bottom:6px;">Clergy Directory</h1>
    <p style="color:var(--muted); margin-bottom:36px; font-size:17px;">
      Old Catholic Churches International clergy worldwide.
    </p>
  <?php endif; ?>

  <?php if (empty($grouped)): ?>
    <p style="color:var(--muted); text-align:center; padding:48px 0; font-size:17px;">
      No clergy records available at this time.
    </p>
  <?php else: ?>

    <?php foreach ($positionLabels as $posKey => $posLabel):
      if (empty($grouped[$posKey])) continue;
    ?>
      <section style="margin-bottom:48px;">

        <h2 style="font-size:1.1rem; text-transform:uppercase; letter-spacing:.06em;
                   color:var(--muted); border-bottom:2px solid var(--line);
                   padding-bottom:8px; margin-bottom:24px;">
          <?= h($posLabel) ?>
        </h2>

        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:28px;">

          <?php foreach ($grouped[$posKey] as $c):
            $displayTitle = $c['title_prefix'] === 'other' ? $c['title_custom'] : $c['title_prefix'];
            $fullName = trim(implode(' ', array_filter([
                $displayTitle, $c['first_name'], $c['last_name']
            ])));
            if ($c['religious_order']) $fullName .= ', ' . $c['religious_order'];

            $hasSocial = false;
            foreach ($socialMeta as $key => $meta) {
                if (!empty($c[$key])) { $hasSocial = true; break; }
            }
          ?>
          <div style="background:var(--white); border:1px solid var(--line); border-radius:6px;
                      box-shadow:var(--shadow); overflow:hidden; display:flex; flex-direction:column;">

            <!-- Photo -->
            <div style="background:var(--sand); display:flex; justify-content:center;
                        padding:20px 20px 0; flex-shrink:0;">
              <?php if ($c['photo']): ?>
                <img src="<?= siteUrl('public/' . h($c['photo'])) ?>"
                     alt="<?= h($fullName) ?>"
                     style="width:130px; height:158px; object-fit:cover; object-position:center top;
                            border-radius:4px; border:3px solid var(--white);
                            box-shadow:0 2px 8px rgba(0,0,0,.15); display:block;">
              <?php else: ?>
                <div style="width:130px; height:158px; background:#ddd; border-radius:4px;
                            border:3px solid var(--white); display:flex; align-items:center;
                            justify-content:center; font-size:52px; color:#999;">&#128100;</div>
              <?php endif; ?>
            </div>

            <!-- Info -->
            <div style="padding:16px 20px 20px; flex:1; display:flex; flex-direction:column;">

              <h3 style="margin:0 0 2px; font-size:1.05rem; color:var(--navy);
                         line-height:1.3; text-align:center;">
                <?= h($fullName) ?>
              </h3>

              <?php if ($c['office']): ?>
                <p style="margin:0 0 10px; font-size:13px; color:var(--muted);
                           font-style:italic; text-align:center;">
                  <?php if ($c['office_url']): ?>
                    <a href="<?= h($c['office_url']) ?>" target="_blank" rel="noopener noreferrer"
                       style="color:inherit;"><?= h($c['office']) ?></a>
                  <?php else: ?>
                    <?= h($c['office']) ?>
                  <?php endif; ?>
                </p>
              <?php endif; ?>

              <div style="margin-top:auto; padding-top:14px; border-top:1px solid var(--line);">

                <?php if ($c['parish'] || $c['diocese']): ?>
                  <div style="font-size:13px; color:var(--text); margin-bottom:8px; line-height:1.5;">
                    <?php if ($c['parish']): ?>
                      <div><strong>
                        <?php if ($c['parish_url']): ?>
                          <a href="<?= h($c['parish_url']) ?>" target="_blank" rel="noopener noreferrer">
                            <?= h($c['parish']) ?>
                          </a>
                        <?php else: ?>
                          <?= h($c['parish']) ?>
                        <?php endif; ?>
                      </strong></div>
                    <?php endif; ?>
                    <?php if ($c['diocese']): ?>
                      <div style="color:var(--muted);">
                        <?php if ($c['diocese_url']): ?>
                          <a href="<?= h($c['diocese_url']) ?>" target="_blank" rel="noopener noreferrer"
                             style="color:inherit;"><?= h($c['diocese']) ?></a>
                        <?php else: ?>
                          <?= h($c['diocese']) ?>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php
                $addrParts = array_filter([
                    $c['city'], $c['state_province'], $c['postal_code'], $c['country']
                ]);
                if ($addrParts):
                ?>
                  <div style="display:flex; gap:6px; font-size:13px; color:var(--muted);
                               margin-bottom:6px; align-items:flex-start;">
                    <span>&#128205;</span>
                    <span><?= h(implode(', ', $addrParts)) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($c['phone']): ?>
                  <div style="display:flex; gap:6px; font-size:13px; margin-bottom:5px; align-items:center;">
                    <span style="color:var(--muted);">&#128222;</span>
                    <a href="tel:<?= h(preg_replace('/[^\d+]/', '', $c['phone'])) ?>"><?= h($c['phone']) ?></a>
                  </div>
                <?php endif; ?>

                <?php if ($c['email']): ?>
                  <div style="display:flex; gap:6px; font-size:13px; margin-bottom:5px; align-items:center;">
                    <span style="color:var(--muted);">&#9993;</span>
                    <a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a>
                  </div>
                <?php endif; ?>

                <?php if ($hasSocial): ?>
                  <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:12px;">
                    <?php foreach ($socialMeta as $key => $meta):
                      if (empty($c[$key])) continue;
                    ?>
                      <a href="<?= h($c[$key]) ?>" target="_blank" rel="noopener noreferrer"
                         title="<?= h($meta['label']) ?>"
                         style="display:inline-flex; align-items:center; justify-content:center;
                                min-width:28px; height:28px; padding:0 8px;
                                background:var(--sand); border:1px solid var(--line);
                                border-radius:4px; font-size:12px; color:var(--text);
                                text-decoration:none; font-weight:600; line-height:1;"
                         onmouseover="this.style.background='var(--navy)';this.style.color='#fff';"
                         onmouseout="this.style.background='var(--sand)';this.style.color='var(--text)';">
                        <?= $meta['label'] ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

              </div>
            </div>
          </div>
          <?php endforeach; ?>

        </div>
      </section>
    <?php endforeach; ?>

  <?php endif; ?>
</div>

<?php }, [
    'meta_desc' => 'Old Catholic Churches International clergy directory.',
]); ?>
