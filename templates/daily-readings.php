<?php
/**
 * Daily Readings — public page template.
 * Route: /daily-readings
 */
require_once BASE_PATH . '/core/Lectionary.php';

$today   = new DateTimeImmutable('today');
$info    = Lectionary::liturgicalDay($today);
$readRow = Lectionary::readingsForDate($today);

// Season colour accent
$seasonColor = match ($info['season']) {
    'advent'    => '#4a0072',
    'lent'      => '#6a0e0e',
    'holyweek'  => '#6a0e0e',
    'easter'    => '#c8a400',
    'christmas' => '#b71c1c',
    default     => '#5d4037',
};

// Helper: get display text for one reading slot
// Prefers manually pasted text; falls back to auto-fetch from bible-api.com
$getText = function(?string $textOverride, ?string $ref): ?string {
    if ($textOverride && trim($textOverride)) return trim($textOverride);
    if ($ref && trim($ref)) return Lectionary::fetchPassage(trim($ref));
    return null;
};

renderPage('Daily Readings', function() use ($today, $info, $readRow, $seasonColor, $getText) {
?>
<div class="page-wrap">
  <article class="page-content">

    <header class="entry-header">
      <h1 class="entry-title">Daily Readings</h1>
    </header>

    <!-- Liturgical day banner -->
    <div style="background:<?= $seasonColor ?>; color:#fff; border-radius:8px;
                padding:18px 24px; margin-bottom:28px; text-align:center;">
      <div style="font-size:13px; letter-spacing:.08em; text-transform:uppercase;
                  opacity:.85; margin-bottom:4px;">
        <?= h(date('l, F j, Y', $today->getTimestamp())) ?>
      </div>
      <div style="font-size:22px; font-weight:700;">
        <?= h($readRow['liturgical_title'] ?? $info['title']) ?>
      </div>
      <div style="font-size:13px; margin-top:6px; opacity:.8;">
        <?= h(ucfirst($info['season'])) ?> &bull;
        Year <?= h($info['sunday_cycle']) ?> &bull;
        Weekday Cycle <?= h($info['weekday_cycle']) ?>
      </div>
    </div>

    <?php if (!$readRow): ?>

      <div style="background:#fff8e1; border:1px solid #ffe082; border-radius:6px;
                  padding:24px; text-align:center; color:#5d4037;">
        <p style="font-size:17px; margin:0 0 10px; font-weight:600;">
          Readings for today have not been entered yet.
        </p>
        <p style="font-size:14px; margin:0; color:#888;">
          Please check the official Roman Catholic lectionary for today's Scripture readings,
          or contact the parish office.
        </p>
      </div>

    <?php else: ?>

      <?php if (!empty($readRow['notes'])): ?>
      <p style="font-style:italic; color:#666; border-left:3px solid <?= $seasonColor ?>;
                padding-left:14px; margin-bottom:24px;">
        <?= nl2br(h($readRow['notes'])) ?>
      </p>
      <?php endif; ?>

      <?php
      $readings = [
          ['label' => 'First Reading',      'ref' => $readRow['reading1_ref'], 'text' => $readRow['reading1_text'] ?? null],
          ['label' => 'Responsorial Psalm', 'ref' => $readRow['psalm_ref'],    'text' => $readRow['psalm_text'] ?? null,    'psalm' => true],
          ['label' => 'Second Reading',     'ref' => $readRow['reading2_ref'], 'text' => $readRow['reading2_text'] ?? null],
          ['label' => 'Gospel',             'ref' => $readRow['gospel_ref'],   'text' => $readRow['gospel_text'] ?? null,   'gospel' => true],
      ];

      foreach ($readings as $rd):
          if (!$rd['ref'] && !$rd['text']) continue; // skip empty slots
          $passageText = $getText($rd['text'], $rd['ref']);
      ?>
      <section style="margin-bottom:32px;">
        <h2 style="font-size:15px; text-transform:uppercase; letter-spacing:.06em;
                   color:<?= $seasonColor ?>; border-bottom:2px solid <?= $seasonColor ?>;
                   padding-bottom:6px; margin-bottom:10px;">
          <?= h($rd['label']) ?>
        </h2>
        <?php if ($rd['ref']): ?>
        <p style="font-size:13px; color:#888; margin-bottom:12px; font-style:italic;">
          <?= h($rd['ref']) ?>
        </p>
        <?php endif; ?>

        <?php if ($passageText): ?>
          <div class="reading-text" style="font-size:16px; line-height:1.85; color:#333;
               <?= !empty($rd['psalm'])  ? 'font-style:italic;' : '' ?>
               <?= !empty($rd['gospel']) ? 'border-left:3px solid ' . $seasonColor . '; padding-left:18px;' : '' ?>">
            <?= nl2br(h($passageText)) ?>
          </div>
        <?php endif; ?>
      </section>
      <?php endforeach; ?>

    <?php endif; ?>

    <div style="margin-top:32px; padding:12px 18px; background:#f5f0ea; border-radius:6px;
                font-size:13px; color:#7a6652; text-align:center;">
      Readings from the Roman Catholic Lectionary &bull;
      Scripture: Catholic Public Domain Version (CPDV) &bull;
      Lectionary citations from the
      <a href="https://bible.usccb.org" target="_blank" rel="noopener"
         style="color:inherit;">USCCB</a>
    </div>

  </article>
</div>
<?php }, [
    'meta_desc' => 'Daily Mass readings for ' . date('l, F j, Y', $today->getTimestamp())
                 . ': ' . ($readRow['liturgical_title'] ?? $info['title']),
    'og_type'   => 'article',
]); ?>
