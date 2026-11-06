<?php
$view = $calendarView ?? 'month';
$monthUrl = static fn (string $month, string $targetView): string => '/admin/rental-calendar?' . http_build_query([
    'month' => $month,
    'view' => $targetView,
]);
$calendarContext = ['return_origin' => 'calendar', 'return_month' => $calendar['month'], 'return_view' => $view];
?>
<div id="rental-calendar-region" class="rental-calendar-region" data-calendar-month="<?= $e($calendar['month']) ?>" data-calendar-view="<?= $e($view) ?>">
    <header class="admin-page-heading">
        <div>
            <p>Administration · Rental operations</p>
            <h1>Rental calendar</h1>
            <p>Bookings, handovers and returns from recorded rental items.</p>
        </div>
        <a class="admin-view-link" href="/admin/rentals">Rental records</a>
    </header>

    <section class="rental-calendar-summary" aria-label="Current overdue rental summary">
        <div><span>Overdue issued items</span><strong><?= $e($calendar['overdue_summary']['item_count']) ?></strong></div>
        <div><span>Outstanding quantity</span><strong><?= $e($calendar['overdue_summary']['outstanding_quantity']) ?></strong></div>
        <p>Current overdue items across all booking dates. Issued items remain overdue until all issued quantity is returned.</p>
    </section>

    <section class="admin-panel rental-calendar-panel" aria-labelledby="rental-calendar-title">
        <div class="admin-panel-heading">
            <h2 id="rental-calendar-title">Bookings by date</h2>
            <p><?= $e(count($calendar['events'])) ?> rental items in this month<?= $calendar['truncated'] ? ' · showing first 200' : '' ?></p>
        </div>
        <p class="rental-calendar-load-status" role="status" aria-live="polite" hidden></p>

        <div class="rental-calendar-toolbar">
            <nav class="rental-month-nav" aria-label="Calendar month">
                <?php if ($calendar['previous_month'] !== null): ?>
                    <a data-calendar-nav data-calendar-focus="previous" href="<?= $e($monthUrl($calendar['previous_month'], $view)) ?>" aria-label="Previous month">← Previous</a>
                <?php else: ?><span aria-disabled="true">← Previous</span><?php endif ?>
                <h3 aria-live="polite"><?= $e($calendar['month_label']) ?></h3>
                <?php if ($calendar['next_month'] !== null): ?>
                    <a data-calendar-nav data-calendar-focus="next" href="<?= $e($monthUrl($calendar['next_month'], $view)) ?>" aria-label="Next month">Next →</a>
                <?php else: ?><span aria-disabled="true">Next →</span><?php endif ?>
            </nav>
            <div class="rental-calendar-views" aria-label="Calendar display">
                <a data-calendar-nav data-calendar-focus="view-month" href="<?= $e($monthUrl($calendar['month'], 'month')) ?>"<?= $view === 'month' ? ' aria-current="page"' : '' ?>>Month</a>
                <a data-calendar-nav data-calendar-focus="view-agenda" href="<?= $e($monthUrl($calendar['month'], 'agenda')) ?>"<?= $view === 'agenda' ? ' aria-current="page"' : '' ?>>Agenda</a>
            </div>
            <form class="rental-month-picker" action="/admin/rental-calendar" method="get" data-calendar-picker>
                <label for="rental-calendar-month">Choose month</label>
                <input id="rental-calendar-month" type="month" name="month" min="2000-01" max="2100-12" value="<?= $e($calendar['month']) ?>" data-calendar-focus="month-picker">
                <input type="hidden" name="view" value="<?= $e($view) ?>">
                <button type="submit">Go</button>
            </form>
        </div>

        <?php if ($calendar['truncated']): ?>
            <p class="rental-calendar-truncated" role="status">This month has more than 200 rental items. The calendar shows the first 200 ordered by start date; use the rental records list for the full set.</p>
        <?php endif ?>

        <div class="rental-calendar-legend" aria-label="Rental item statuses">
            <span class="is-booked">Booked</span><span class="is-issued">Issued</span>
            <span class="is-overdue">Overdue return</span><span class="is-returned">Returned</span>
            <span class="is-cancelled">Cancelled</span><span class="is-other">Other recorded state</span>
        </div>

        <div class="rental-calendar-month-view"<?= $view === 'agenda' ? ' hidden' : '' ?>>
            <table class="rental-calendar-grid" aria-label="Rental items in <?= $e($calendar['month_label']) ?>">
                <thead><tr><?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $weekday): ?><th scope="col"><?= $e($weekday) ?></th><?php endforeach ?></tr></thead>
                <tbody>
                <?php foreach ($calendar['weeks'] as $week): ?>
                    <tr><td class="rental-calendar-week-cell" colspan="7">
                        <div class="rental-calendar-week" style="--rental-lane-count:<?= $e($week['visible_lane_count']) ?>">
                            <div class="rental-calendar-week-days">
                                <?php foreach ($week['days'] as $cell): ?>
                                    <time class="<?= $cell['in_month'] ? '' : 'is-outside' ?><?= $cell['is_today'] ? ' is-today' : '' ?>" datetime="<?= $e($cell['date']) ?>" aria-label="<?= $e((new DateTimeImmutable($cell['date']))->format('l, j F Y')) ?>"><?= $e($cell['day']) ?></time>
                                <?php endforeach ?>
                            </div>
                            <div class="rental-calendar-lanes" aria-label="Rental date ranges">
                                <?php foreach ($week['segments'] as $segment): ?>
                                    <?php if ($segment['lane'] < $week['visible_lane_count']):
                                        $segmentLabel = 'Rental #' . $segment['rental_id'] . ', ' . $segment['customer_name'] . ', ' . $segment['product_name_snapshot']
                                            . ', ' . $date($segment['start_date']) . ' to ' . $date($segment['end_date_exclusive'], true)
                                            . ', ' . $segment['status_label']
                                            . ($segment['continues_before'] ? ', continues from earlier date' : '')
                                            . ($segment['continues_after'] ? ', continues into later date' : '');
                                    ?>
                                        <a class="rental-calendar-event rental-calendar-range is-<?= $e($segment['status_key']) ?><?= $segment['continues_before'] ? ' continues-before' : '' ?><?= $segment['continues_after'] ? ' continues-after' : '' ?>"
                                            style="grid-column:<?= $e($segment['start_column']) ?> / span <?= $e($segment['span']) ?>;grid-row:<?= $e($segment['lane'] + 1) ?>"
                                            href="<?= $e('/admin/rental?' . http_build_query(['id' => $segment['rental_id']] + $calendarContext)) ?>"
                                            id="rental-calendar-item-<?= $e($segment['rental_item_id']) ?>"
                                            data-calendar-item-id="<?= $e($segment['rental_item_id']) ?>"
                                            aria-label="<?= $e($segmentLabel) ?>">
                                            <span class="rental-range-continuation" aria-hidden="true"><?= $segment['continues_before'] ? '‹ ' : '' ?></span>
                                            <strong>#<?= $e($segment['rental_id']) ?></strong><span><?= $e($segment['customer_name']) ?> · <?= $e($segment['product_name_snapshot']) ?></span>
                                            <small><?= $e($segment['status_label']) ?><?= $segment['continues_after'] ? ' · continues ›' : '' ?></small>
                                        </a>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </div>
                            <div class="rental-calendar-overflow">
                                <?php foreach ($week['days'] as $columnIndex => $cell): ?>
                                    <?php if ($week['overflow'][$columnIndex] > 0): ?>
                                        <details style="grid-column:<?= $e($columnIndex + 1) ?>">
                                            <summary>+<?= $e($week['overflow'][$columnIndex]) ?> more</summary>
                                            <ul>
                                                <?php foreach ($week['segments'] as $segment): ?>
                                                    <?php if ($segment['lane'] >= $week['visible_lane_count'] && $segment['start_date'] <= $cell['date'] && $segment['end_date_exclusive'] > $cell['date']): ?>
                                                        <li><a href="<?= $e('/admin/rental?' . http_build_query(['id' => $segment['rental_id']] + $calendarContext)) ?>">Rental #<?= $e($segment['rental_id']) ?> · <?= $e($segment['customer_name']) ?> · <?= $e($segment['product_name_snapshot']) ?> · <?= $e($segment['status_label']) ?></a></li>
                                                    <?php endif ?>
                                                <?php endforeach ?>
                                            </ul>
                                        </details>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </td></tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <details class="rental-calendar-agenda" open>
            <summary>Accessible agenda · <?= $e(count($calendar['events'])) ?> rental items this month</summary>
            <?php if ($calendar['events'] === []): ?><p class="admin-empty" role="status">No rental items overlap this month.</p>
            <?php else: ?><ul>
                <?php foreach ($calendar['events'] as $event): ?>
                    <li><a class="rental-calendar-event is-<?= $e($event['status_key']) ?>" href="<?= $e('/admin/rental?' . http_build_query(['id' => $event['rental_id']] + $calendarContext)) ?>" data-calendar-item-id="<?= $e($event['rental_item_id']) ?>">
                        <strong>Rental #<?= $e($event['rental_id']) ?></strong>
                        <span><?= $e($event['customer_name']) ?> · <?= $e($event['product_name_snapshot']) ?> (<?= $e($event['sku_snapshot']) ?>)</span>
                        <small><?= $e($date($event['start_date'])) ?> – <?= $e($date((new DateTimeImmutable($event['end_date_exclusive']))->modify('-1 day')->format('Y-m-d'))) ?> · <?= $e($event['quantity']) ?> booked, <?= $e($event['issued_quantity']) ?> issued, <?= $e($event['returned_quantity']) ?> returned, <?= $e($event['outstanding_quantity']) ?> outstanding · <?= $e($event['status_label']) ?></small>
                    </a></li>
                <?php endforeach ?>
            </ul><?php endif ?>
        </details>

        <p class="rental-calendar-note">Booked dates include the start date and exclude the stored end date. The final displayed rental day is the day before that exclusive end date. Archived rentals are omitted; cancelled rentals remain visible when their item dates overlap this month.</p>
    </section>
</div>
