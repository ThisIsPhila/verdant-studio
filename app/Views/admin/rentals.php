<?php
$groupLabels = [
    'overdue' => 'Overdue returns',
    'current' => 'Current rentals',
    'upcoming' => 'Upcoming bookings',
    'history' => 'Completed and cancelled',
];
$rentalGroupUrl = static fn (string $key, int $targetPage = 1): string => '/admin/rentals?' . http_build_query([
    'group' => $key,
    'page' => max(1, $targetPage),
]);
?>
<header class="admin-page-heading">
    <div>
        <p>Administration</p>
        <h1>Rental reservations</h1>
        <p>Review overdue returns, current rentals and upcoming bookings in date order.</p>
    </div>
    <a class="admin-view-link" href="/admin/rental-calendar">Open calendar</a>
</header>

<nav class="admin-rental-groups" aria-label="Rental timing groups">
    <?php foreach ($groupLabels as $key => $groupLabel): ?>
        <a href="<?= $e($rentalGroupUrl($key)) ?>"<?= $group === $key ? ' aria-current="page"' : '' ?>>
            <span><?= $e($groupLabel) ?></span><strong><?= $e($groups[$key] ?? 0) ?></strong>
        </a>
    <?php endforeach ?>
</nav>

<section class="admin-panel admin-rentals-panel" aria-labelledby="rental-list-title">
    <header class="admin-panel-heading">
        <div>
            <h2 id="rental-list-title"><?= $e($groupLabels[$group]) ?></h2>
            <p><?= $e($total) ?> record<?= $total === 1 ? '' : 's' ?> · Today <?= $e($date($today)) ?></p>
        </div>
    </header>

    <?php if ($rentals === []): ?>
        <p class="admin-empty" role="status">No <?= $e(strtolower($groupLabels[$group])) ?> to show.</p>
    <?php else: ?>
        <div class="admin-scroll-table" tabindex="0" role="region" aria-label="<?= $e($groupLabels[$group]) ?>">
            <table class="admin-table admin-rental-timing-table">
                <thead>
                    <tr>
                        <th scope="col">Rental</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Booked items and dates</th>
                        <th scope="col">Outstanding</th>
                        <th scope="col">Reservation state</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $previousDeadline = null; foreach ($rentals as $rentalIndex => $rental):
                        $boundary = $group === 'overdue' ? $rental['overdue_deadline'] : null;
                        $showTodayBoundary = $group === 'overdue' && $boundary !== $previousDeadline;
                        if ($boundary !== null) $previousDeadline = $boundary;
                    ?>
                        <?php if ($showTodayBoundary): ?>
                            <tr class="admin-rental-date-divider"><th colspan="5" scope="rowgroup">
                                <span><?= $boundary < $today ? 'Past due' : 'Due today' ?></span>
                                <time datetime="<?= $e($boundary) ?>"><?= $e($date($boundary)) ?></time>
                            </th></tr>
                        <?php elseif ($group === 'current' && $rentalIndex === 0): ?>
                            <tr class="admin-rental-date-divider"><th colspan="5" scope="rowgroup">Today · current rentals</th></tr>
                        <?php endif ?>
                        <tr>
                            <th scope="row">
                                <a href="/admin/rental?id=<?= $e($rental['rental_id']) ?>&amp;return_origin=list&amp;return_group=<?= $e($group) ?>&amp;return_page=<?= $e($page) ?>">#<?= $e($rental['rental_id']) ?></a>
                                <?php if ($rental['archived_at'] !== null): ?><br><small>Archived</small><?php endif ?>
                                <?php if ($rental['overdue_item_count'] > 0): ?><br><strong class="admin-rental-alert">! <?= $e($rental['overdue_item_count']) ?> overdue item<?= $rental['overdue_item_count'] === 1 ? '' : 's' ?></strong><?php endif ?>
                            </th>
                            <td><?= $e($rental['customer_name']) ?><br><small><?= $e($rental['customer_email']) ?></small></td>
                            <td>
                                <ul class="admin-rental-item-dates">
                                    <?php foreach ($rental['items'] as $item): ?>
                                        <li class="<?= $item['overdue'] ? 'is-overdue' : '' ?>">
                                            <span><?= $e($item['product_name_snapshot']) ?></span>
                                            <time datetime="<?= $e($item['start_date']) ?>"><?= $e($date($item['start_date'])) ?></time>
                                            <span aria-hidden="true">–</span>
                                            <time datetime="<?= $e($item['end_date_exclusive']) ?>"><?= $e($date($item['end_date_exclusive'], true)) ?></time>
                                            <span class="admin-rental-item-status"><?= $item['overdue'] ? 'Overdue return' : $e($item['timing_label']) ?></span>
                                            <?php if ($item['outstanding_quantity'] > 0): ?><small><?= $e($item['outstanding_quantity']) ?> outstanding</small><?php endif ?>
                                        </li>
                                    <?php endforeach ?>
                                </ul>
                            </td>
                            <td><?= $e($rental['outstanding_quantity']) ?><?php if ($rental['overdue_quantity'] > 0): ?><br><strong class="admin-rental-alert"><?= $e($rental['overdue_quantity']) ?> overdue</strong><?php endif ?></td>
                            <td><span class="admin-status"><span aria-hidden="true"></span><?= $e($statusLabel((string) $rental['state'])) ?></span></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>

    <?php if ($pages > 1): ?>
        <nav class="admin-pagination" aria-label="Rental pages">
            <p>Page <?= $e($page) ?> of <?= $e($pages) ?> (<?= $e($total) ?> records)</p>
            <div>
                <?php if ($page > 1): ?><a href="<?= $e($rentalGroupUrl($group, $page - 1)) ?>">Previous</a><?php endif ?>
                <?php if ($page < $pages): ?><a href="<?= $e($rentalGroupUrl($group, $page + 1)) ?>">Next</a><?php endif ?>
            </div>
        </nav>
    <?php endif ?>
</section>
