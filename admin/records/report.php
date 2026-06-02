<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once dirname(__DIR__) . '/layout.php';

Auth::init();
Auth::requireLogin(siteUrl('admin/login'));
Auth::requirePermission('view_records');

if (!Database::fetch("SHOW TABLES LIKE 'occi_baptisms'")) {
    adminLayout('Person Sacramental Report', function() { ?>
      <div class="alert alert-error">NSR tables are missing. Please run pending migrations under
        <a href="<?= siteUrl('admin/updates') ?>">Admin &rarr; Updates</a>.</div>
    <?php });
    return;
}

$searched  = isset($_GET['q']);
$firstName = trim($_GET['first_name'] ?? '');
$lastName  = trim($_GET['last_name']  ?? '');
$results   = [];

if ($searched && ($firstName || $lastName)) {
    $fl = '%' . $firstName . '%';
    $ll = '%' . $lastName  . '%';

    // Build a condition fragment depending on what was provided
    $nameCond = function(string $fn, string $ln) use ($firstName, $lastName, $fl, $ll): array {
        if ($firstName && $lastName) {
            return ["$fn LIKE ? AND $ln LIKE ?", [$fl, $ll]];
        } elseif ($firstName) {
            return ["$fn LIKE ?", [$fl]];
        } else {
            return ["$ln LIKE ?", [$ll]];
        }
    };

    // Baptisms
    [$cond, $params] = $nameCond('b.first_name', 'b.last_name');
    $rows = Database::fetchAll(
        "SELECT b.id, b.first_name, b.last_name, b.baptism_date AS event_date, 'Baptism' AS register,
                p.name AS parish_name
         FROM occi_baptisms b LEFT JOIN occi_parishes p ON p.id = b.parish_id
         WHERE $cond ORDER BY b.baptism_date DESC", $params);
    foreach ($rows as $row) $results['Baptisms'][] = $row;

    // Confirmations
    [$cond, $params] = $nameCond('c.first_name', 'c.last_name');
    $rows = Database::fetchAll(
        "SELECT c.id, c.first_name, c.last_name, c.confirmation_date AS event_date, 'Confirmation' AS register,
                p.name AS parish_name
         FROM occi_confirmations c LEFT JOIN occi_parishes p ON p.id = c.parish_id
         WHERE $cond ORDER BY c.confirmation_date DESC", $params);
    foreach ($rows as $row) $results['Confirmations'][] = $row;

    // Communions
    [$cond, $params] = $nameCond('c.first_name', 'c.last_name');
    $rows = Database::fetchAll(
        "SELECT c.id, c.first_name, c.last_name, c.communion_date AS event_date, 'First Communion' AS register,
                p.name AS parish_name
         FROM occi_communions c LEFT JOIN occi_parishes p ON p.id = c.parish_id
         WHERE $cond ORDER BY c.communion_date DESC", $params);
    foreach ($rows as $row) $results['First Communions'][] = $row;

    // Marriages (search both parties)
    $mfl = '%' . $firstName . '%';
    $mll = '%' . $lastName  . '%';
    if ($firstName && $lastName) {
        $mCond = "(party1_first_name LIKE ? AND party1_last_name LIKE ?) OR (party2_first_name LIKE ? AND party2_last_name LIKE ?)";
        $mParams = [$mfl, $mll, $mfl, $mll];
    } elseif ($firstName) {
        $mCond = "party1_first_name LIKE ? OR party2_first_name LIKE ?";
        $mParams = [$mfl, $mfl];
    } else {
        $mCond = "party1_last_name LIKE ? OR party2_last_name LIKE ?";
        $mParams = [$mll, $mll];
    }
    $rows = Database::fetchAll(
        "SELECT m.id,
                CONCAT(m.party1_first_name, ' ', m.party1_last_name, ' & ', m.party2_first_name, ' ', m.party2_last_name) AS display_name,
                '' AS first_name, '' AS last_name,
                m.marriage_date AS event_date, 'Marriage' AS register,
                p.name AS parish_name
         FROM occi_marriages m LEFT JOIN occi_parishes p ON p.id = m.parish_id
         WHERE $mCond ORDER BY m.marriage_date DESC", $mParams);
    foreach ($rows as $row) $results['Marriages'][] = $row;

    // Deaths
    [$cond, $params] = $nameCond('d.first_name', 'd.last_name');
    $rows = Database::fetchAll(
        "SELECT d.id, d.first_name, d.last_name, d.death_date AS event_date, 'Death' AS register,
                p.name AS parish_name
         FROM occi_deaths d LEFT JOIN occi_parishes p ON p.id = d.parish_id
         WHERE $cond ORDER BY d.death_date DESC", $params);
    foreach ($rows as $row) $results['Deaths'][] = $row;

    // Ordinations
    [$cond, $params] = $nameCond('o.first_name', 'o.last_name');
    $rows = Database::fetchAll(
        "SELECT o.id, o.first_name, o.last_name, o.ordination_date AS event_date, 'Ordination' AS register,
                p.name AS parish_name
         FROM occi_ordinations o LEFT JOIN occi_parishes p ON p.id = o.parish_id
         WHERE $cond ORDER BY o.ordination_date DESC", $params);
    foreach ($rows as $row) $results['Ordinations'][] = $row;
}

$registerSlugs = [
    'Baptisms'       => 'baptisms',
    'Confirmations'  => 'confirmations',
    'First Communions'=> 'communions',
    'Marriages'      => 'marriages',
    'Deaths'         => 'deaths',
    'Ordinations'    => 'ordinations',
];

adminLayout('Person Sacramental Report', function() use ($searched, $firstName, $lastName, $results, $registerSlugs) {
?>
<div class="card" style="max-width:560px; margin-bottom:24px;">
  <div class="card-header"><h3 class="card-title" style="font-size:15px;">Search All Registers</h3></div>
  <form method="get" action="<?= siteUrl('admin/records/report') ?>">
    <input type="hidden" name="q" value="1">
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
      <div>
        <label style="display:block; font-size:13px; margin-bottom:4px;">First Name</label>
        <input type="text" name="first_name" value="<?= h($firstName) ?>" class="form-control" placeholder="e.g. John">
      </div>
      <div>
        <label style="display:block; font-size:13px; margin-bottom:4px;">Last Name</label>
        <input type="text" name="last_name" value="<?= h($lastName) ?>" class="form-control" placeholder="e.g. Smith">
      </div>
    </div>
    <div style="display:flex; gap:8px;">
      <button type="submit" class="btn btn-primary">Search All Registers</button>
      <?php if ($searched): ?>
      <a href="<?= siteUrl('admin/records/report') ?>" class="btn btn-secondary">Clear</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($searched): ?>
  <?php if (!$firstName && !$lastName): ?>
    <div class="alert alert-error">Please enter at least a first or last name to search.</div>
  <?php else: ?>
    <?php
    $totalFound = array_sum(array_map('count', $results));
    ?>
    <p style="font-size:14px; color:#666; margin-bottom:20px;">
      Found <strong><?= $totalFound ?></strong> record<?= $totalFound !== 1 ? 's' : '' ?>
      for &ldquo;<?= h(trim($firstName . ' ' . $lastName)) ?>&rdquo; across all registers.
    </p>

    <?php if ($totalFound === 0): ?>
      <div class="card" style="text-align:center; padding:40px; color:#aaa;">No matching records found.</div>
    <?php else: ?>
      <?php foreach ($registerSlugs as $label => $slug): ?>
        <?php if (empty($results[$label])) continue; ?>
        <h3 style="font-size:15px; margin:24px 0 8px;"><?= h($label) ?></h3>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom:8px;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Date</th>
                <th>Parish</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($results[$label] as $r): ?>
              <tr>
                <td><?= isset($r['display_name']) ? h($r['display_name']) : h($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td style="font-size:13px;"><?= h($r['event_date']) ?></td>
                <td style="font-size:13px;"><?= $r['parish_name'] ? h($r['parish_name']) : '<span style="color:#aaa;">--</span>' ?></td>
                <td>
                  <a href="<?= siteUrl('admin/records/' . $slug . '/' . $r['id']) ?>" class="btn btn-sm btn-secondary">View</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<?php });
