<?php
require_once BASE_PATH . '/core/Events.php';

$siteId   = Database::siteId();
$upcoming = Events::upcoming($siteId, 40, 180);

// Group occurrences by month
$byMonth = [];
foreach ($upcoming as $e) {
    $key = (new DateTimeImmutable($e['occ_start']))->format('Y-m');
    $byMonth[$key][] = $e;
}

renderPage('Events', function() use ($byMonth, $upcoming) {
?>
<div class="page-wrap events-page">

  <header class="events-page__header">
    <h1 class="events-page__title">Upcoming Events</h1>
    <p class="events-page__lead">Join us for worship, fellowship, and community.</p>
  </header>

  <?php if (empty($upcoming)): ?>
  <p class="events-page__empty">No upcoming events scheduled. Check back soon.</p>

  <?php else: ?>
  <?php foreach ($byMonth as $monthKey => $events):
    $monthLabel = (new DateTimeImmutable($monthKey . '-01'))->format('F Y');
  ?>
  <section class="events-month">
    <h2 class="events-month__heading"><?= h($monthLabel) ?></h2>
    <div class="events-list">
      <?php foreach ($events as $e):
        $start = new DateTimeImmutable($e['occ_start']);
        $end   = $e['occ_end'] ? new DateTimeImmutable($e['occ_end']) : null;
        $recur = Events::recurrenceLabel($e);
      ?>
      <a href="<?= siteUrl('events/' . $e['slug']) ?>" class="event-card">
        <div class="event-card__date">
          <span class="event-card__day"><?= $start->format('j') ?></span>
          <span class="event-card__month"><?= $start->format('M') ?></span>
        </div>
        <div class="event-card__body">
          <h3 class="event-card__title"><?= h($e['title']) ?></h3>
          <p class="event-card__time">
            <?php if ($e['all_day']): ?>
              All day
            <?php else: ?>
              <?= $start->format('g:i a') ?>
              <?php if ($end && $end > $start): ?>&ndash;<?= $end->format('g:i a') ?><?php endif; ?>
            <?php endif; ?>
            <?php if ($recur): ?>
              &nbsp;<span class="event-card__recur"><?= h($recur) ?></span>
            <?php endif; ?>
          </p>
          <?php if ($e['location']): ?>
          <p class="event-card__location">&#128205; <?= h($e['location']) ?></p>
          <?php endif; ?>
        </div>
        <div class="event-card__arrow">&#8250;</div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>

</div>
<?php
}, ['meta_desc' => 'Upcoming events at MCC of Our Redeemer in Augusta, Georgia.']);
