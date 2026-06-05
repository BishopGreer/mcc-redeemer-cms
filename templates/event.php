<?php
require_once BASE_PATH . '/core/Events.php';

// $eventSlug is set by the router
$eventRow = Database::fetch(
    "SELECT e.*, m.path AS img_path, m.alt_text AS img_alt
     FROM events e
     LEFT JOIN media m ON m.id = e.featured_image_id
     WHERE e.slug = ? AND e.site_id = ? AND e.status = 'published'",
    [$eventSlug, Database::siteId()]
);

if (!$eventRow) {
    http_response_code(404);
    require BASE_PATH . '/templates/404.php';
    exit;
}

$e      = $eventRow;
$start  = new DateTimeImmutable($e['start_dt']);
$end    = $e['end_dt'] ? new DateTimeImmutable($e['end_dt']) : null;
$recur  = Events::recurrenceLabel($e);
$imgUrl = $e['img_path'] ? UPLOAD_URL . '/' . $e['img_path'] : null;

// Next upcoming occurrence
$from       = new DateTimeImmutable('today midnight');
$to         = new DateTimeImmutable('+365 days');
$next       = Events::occurrences($e, $from, $to, 1);
$nextStart  = !empty($next) ? $next[0]['start'] : $start;
$nextEnd    = !empty($next) ? $next[0]['end']   : $end;

renderPage($e['title'], function() use ($e, $start, $end, $nextStart, $nextEnd, $recur, $imgUrl) {
?>
<div class="page-wrap event-single">

  <nav class="event-single__breadcrumb">
    <a href="<?= siteUrl('events') ?>">&#8592; All Events</a>
  </nav>

  <?php if ($imgUrl): ?>
  <img src="<?= h($imgUrl) ?>"
       alt="<?= h($e['img_alt'] ?: $e['title']) ?>"
       class="event-single__img">
  <?php endif; ?>

  <h1 class="event-single__title"><?= h($e['title']) ?></h1>

  <div class="event-single__meta">

    <div class="event-single__meta-item">
      <span class="event-single__meta-icon">&#128197;</span>
      <div>
        <strong>Date &amp; Time</strong><br>
        <?= Events::formatDateRange(
              $nextStart->format('Y-m-d H:i:s'),
              $nextEnd ? $nextEnd->format('Y-m-d H:i:s') : null,
              (bool)$e['all_day']
           ) ?>
        <?php if ($recur): ?>
        <br><span class="event-single__recur"><?= h($recur) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($e['location']): ?>
    <div class="event-single__meta-item">
      <span class="event-single__meta-icon">&#128205;</span>
      <div>
        <strong>Location</strong><br>
        <?= h($e['location']) ?>
        <?php if ($e['address']): ?>
        <br><span style="color:#6b7280;"><?= h($e['address']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <?php if (!empty($e['description'])): ?>
  <div class="event-single__body entry-content">
    <?= $e['description'] ?>
  </div>
  <?php endif; ?>

  <div class="event-single__footer">
    <a href="<?= siteUrl('events') ?>" class="btn btn-secondary">&#8592; All Events</a>
    <a href="<?= siteUrl('contact') ?>" class="btn btn-primary">Contact Us</a>
  </div>

</div>
<?php
}, [
    'meta_desc' => strip_tags($e['description'] ?? '') ?: 'Event at MCC of Our Redeemer.',
    'og_image'  => $imgUrl,
]);
