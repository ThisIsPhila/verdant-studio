<?php $returnContext = $returnContext ?? \Verdant\Admin\RentalReturnContext::listDefault(); ?>
<a class="admin-view-link" href="<?= $e($returnContext->url()) ?>"<?= $returnContext->origin === 'calendar' ? ' data-calendar-return' : '' ?>>
    <?= $returnContext->origin === 'calendar' ? 'Back to calendar' : ($returnContext->origin === 'returns' ? 'Back to returns' : 'Back to rentals') ?>
</a>

<header class="admin-page-heading">
    <div>
        <p>Administration · Rental reservation</p>
        <h1>Rental #<?= $e($rental_id) ?></h1>
        <p><?= $e($customer_name) ?> · <?= $e($customer_email) ?></p>
        <p>Created <?= $e($date($created_at)) ?></p>
    </div>
    <div class="admin-record-status" aria-label="Rental status">
        <span class="admin-status"><span aria-hidden="true"></span><?= $e($statusLabel((string) $state)) ?></span>
        <?php if ($archived_at !== null): ?>
            <span class="admin-status is-muted"><span aria-hidden="true"></span>Archived</span>
        <?php endif ?>
    </div>
</header>

<?php if ($notice !== null): ?>
    <p class="admin-notice" role="status"><?= $e($notice) ?></p>
<?php endif ?>

<div class="admin-detail-grid">
    <div>
        <section class="admin-panel" aria-labelledby="rental-items-heading">
            <h2 id="rental-items-heading">Booked items and returns</h2>

            <?php foreach ($items as $item): ?>
                <article class="admin-rental-item" id="rental-item-<?= $e($item['rental_item_id']) ?>">
                    <h3><?= $e($item['product_name_snapshot']) ?> <small>(<?= $e($item['sku_snapshot']) ?>)</small></h3>

                    <dl class="admin-facts">
                        <div>
                            <dt>Booked</dt>
                            <dd><?= $e($item['quantity']) ?></dd>
                        </div>
                        <div>
                            <dt>Dates</dt>
                            <dd><?= $e($date($item['start_date'])) ?> – <?= $e($date($item['end_date_exclusive'], true)) ?></dd>
                        </div>
                        <div>
                            <dt>Allocation</dt>
                            <dd><?= $e($statusLabel((string) $item['allocation_state'])) ?></dd>
                        </div>
                        <div>
                            <dt>Outstanding</dt>
                            <dd><?= $e($item['outstanding_quantity']) ?><?php if ($item['overdue']): ?> · <strong>Overdue</strong><?php endif ?></dd>
                        </div>
                    </dl>

                    <?php if ((int) $item['issued_quantity'] > 0): ?>
                        <?php
                        $issuedAt = (new DateTimeImmutable((string) $item['issued_at'], new DateTimeZone('UTC')))
                            ->setTimezone(new DateTimeZone('Africa/Johannesburg'))
                            ->format('j M Y H:i T');
                        ?>
                        <p>
                            Issued <?= $e($item['issued_quantity']) ?> on <?= $e($issuedAt) ?>
                            by <?= $e($item['issued_by_name'] ?? 'Not recorded') ?>
                        </p>
                    <?php endif ?>

                    <?php if ($item['returns'] !== []): ?>
                        <h4>Return history</h4>
                        <ul>
                            <?php foreach ($item['returns'] as $return):
                                $returnedAt = (new DateTimeImmutable((string) $return['returned_at'], new DateTimeZone('UTC')))
                                    ->setTimezone(new DateTimeZone('Africa/Johannesburg'))
                                    ->format('j M Y H:i T');
                            ?>
                                <li>
                                    <?= $e($return['quantity']) ?> returned by <?= $e($return['actor_name'] ?? 'Not recorded') ?>
                                    on <?= $e($returnedAt) ?>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>

                    <div class="admin-actions">
                        <?php if ($state === 'confirmed' && $item['allocation_state'] === 'confirmed'): ?>
                            <form action="/admin/rental/issue" method="post">
                                <?php foreach ($returnContext->fields() as $field => $value): ?><input type="hidden" name="<?= $e($field) ?>" value="<?= $e($value) ?>"><?php endforeach ?>
                                <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                                <input type="hidden" name="rental_id" value="<?= $e($rental_id) ?>">
                                <button type="submit">Issue rental items</button>
                            </form>
                        <?php endif ?>

                        <?php if ($item['allocation_state'] === 'issued' && (int) $item['outstanding_quantity'] > 0): ?>
                            <form class="admin-return-form" action="/admin/rental/return" method="post">
                                <?php foreach ($returnContext->fields() as $field => $value): ?><input type="hidden" name="<?= $e($field) ?>" value="<?= $e($value) ?>"><?php endforeach ?>
                                <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                                <input type="hidden" name="rental_id" value="<?= $e($rental_id) ?>">
                                <input type="hidden" name="rental_item_id" value="<?= $e($item['rental_item_id']) ?>">
                                <input type="hidden" name="request_key" value="<?= $e($returnKeys[$item['rental_item_id']]) ?>">
                                <div class="form-field">
                                    <label for="return-quantity-<?= $e($item['rental_item_id']) ?>">Quantity returned</label>
                                    <input
                                        id="return-quantity-<?= $e($item['rental_item_id']) ?>"
                                        type="number"
                                        name="quantity"
                                        min="1"
                                        max="<?= $e($item['outstanding_quantity']) ?>"
                                        value="<?= $e($item['outstanding_quantity']) ?>"
                                        required
                                    >
                                </div>
                                <button type="submit">Record return</button>
                                <p>Only physically returned items become available.</p>
                            </form>
                        <?php endif ?>
                    </div>
                </article>
            <?php endforeach ?>
        </section>
    </div>

    <section class="admin-panel" aria-labelledby="rental-actions-heading">
        <h2 id="rental-actions-heading">Reservation actions</h2>
        <div class="admin-actions">
            <?php if (!in_array($state, ['cancelled'], true)): ?>
                <form action="/admin/rental/cancel" method="post">
                    <?php foreach ($returnContext->fields() as $field => $value): ?><input type="hidden" name="<?= $e($field) ?>" value="<?= $e($value) ?>"><?php endforeach ?>
                    <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                    <input type="hidden" name="rental_id" value="<?= $e($rental_id) ?>">
                    <button class="quiet-button" type="submit">Cancel reservation</button>
                    <p>The rental service enforces payment, issue and inventory rules before cancellation.</p>
                    <?php if ($state === 'confirmed'): ?>
                        <p>This reservation is paid. Cancellation here does not process a refund.</p>
                    <?php endif ?>
                </form>
            <?php endif ?>

            <form action="/admin/rental/archive" method="post">
                <?php foreach ($returnContext->fields() as $field => $value): ?><input type="hidden" name="<?= $e($field) ?>" value="<?= $e($value) ?>"><?php endforeach ?>
                <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                <input type="hidden" name="rental_id" value="<?= $e($rental_id) ?>">
                <input type="hidden" name="archived" value="<?= $archived_at === null ? '1' : '0' ?>">
                <button class="quiet-button" type="submit"><?= $archived_at === null ? 'Archive history' : 'Restore history' ?></button>
                <p>Archiving preserves the reservation, allocation and audit history.</p>
            </form>
        </div>
    </section>
</div>
