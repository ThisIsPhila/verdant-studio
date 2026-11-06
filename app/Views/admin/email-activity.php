<?php
$stateLabels = [
    'held' => 'Saved · held',
    'queued' => 'Queued for processing',
    'captured' => 'Captured locally',
    'provider_accepted' => 'Accepted by provider',
    'failed' => 'Failed',
    'uncertain' => 'Uncertain · review required',
];
?>
<?php if (is_string($delivery_notice ?? null) && $delivery_notice !== ''): ?><p class="admin-notice" role="status"><?= $e($delivery_notice) ?></p><?php endif ?>
<?php if (is_array($detail)): ?>
    <header class="admin-page-heading">
        <div>
            <p>Administration · Email activity</p>
            <h1><?= $e($detail['event_label']) ?></h1>
            <p><a href="/admin/email">← All email activity</a></p>
        </div>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?><p class="admin-notice" role="status"><?= $e($notice) ?></p><?php endif ?>

    <section class="admin-panel email-detail" aria-labelledby="email-record-heading">
        <h2 id="email-record-heading">Delivery record #<?= $e($detail['outbox_id']) ?></h2>
        <dl class="admin-facts">
            <div><dt>Recipient</dt><dd><?= $e($detail['recipient_email']) ?></dd></div>
            <div><dt>Record</dt><dd><?= $e($detail['record_label']) ?></dd></div>
            <div><dt>Status</dt><dd><span class="email-state email-state--<?= $e($detail['delivery_state']) ?>"><?= $e($detail['state_label']) ?></span></dd></div>
            <div><dt>Attempts</dt><dd><?= $e($detail['attempt_count']) ?></dd></div>
            <div><dt>Created</dt><dd><?= $e($detail['created_at']) ?></dd></div>
            <div><dt>Last updated</dt><dd><?= $e($detail['updated_at']) ?></dd></div>
            <?php if ($detail['failure_code'] !== null): ?><div><dt>Failure reason</dt><dd><code><?= $e($detail['failure_code']) ?></code></dd></div><?php endif ?>
        </dl>
        <p class="email-delivery-note">Accepted by provider means the mail provider received the message. Mailbox receipt is not tracked here.</p>
    </section>

    <section class="admin-panel email-preview" aria-labelledby="email-preview-heading">
        <h2 id="email-preview-heading">Message preview</h2>
        <?php if (is_array($detail['preview'])): ?>
            <p><strong>Subject:</strong> <?= $e($detail['preview']['subject']) ?></p>
            <h3>Plain-text email</h3>
            <pre><?= $e($detail['preview']['text']) ?></pre>
            <details>
                <summary>Escaped HTML template source</summary>
                <pre><?= $e($detail['preview']['html']) ?></pre>
            </details>
        <?php else: ?>
            <p><?= $e($detail['preview_error'] ?? 'The saved message could not be rendered.') ?></p>
        <?php endif ?>
    </section>

    <?php if (!empty($detail['can_retry'])): ?>
        <section class="admin-panel email-retry" aria-labelledby="email-retry-heading">
            <h2 id="email-retry-heading">Retry this failed message</h2>
            <p>This queues the same message for one more delivery attempt with the current email settings. It does not send from this page.</p>
            <form action="/admin/email/retry" method="post">
                <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                <input type="hidden" name="outbox_id" value="<?= $e($detail['outbox_id']) ?>">
                <input type="hidden" name="updated_at" value="<?= $e($detail['updated_at']) ?>">
                <button class="btn-solid" type="submit">Queue retry</button>
            </form>
        </section>
    <?php endif ?>

    <?php if (!empty($detail['can_release'])): ?>
        <section class="admin-panel email-release" aria-labelledby="email-release-heading">
            <h2 id="email-release-heading">Release this held message</h2>
            <p>This queues the message for delivery to its recipient with the current email settings. It does not send from this page.</p>
            <form action="/admin/email/release" method="post">
                <input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>">
                <input type="hidden" name="outbox_id" value="<?= $e($detail['outbox_id']) ?>">
                <input type="hidden" name="updated_at" value="<?= $e($detail['updated_at']) ?>">
                <button class="btn-solid" type="submit">Queue for delivery</button>
            </form>
        </section>
    <?php endif ?>
<?php else: ?>
    <header class="admin-page-heading">
        <div>
            <p>Administration · Customer messaging</p>
            <h1>Email activity</h1>
            <p>Review saved notifications and their processing state.</p>
        </div>
    </header>

    <?php if (is_string($notice) && $notice !== ''): ?><p class="admin-notice" role="status"><?= $e($notice) ?></p><?php endif ?>

    <section class="admin-panel" aria-labelledby="email-list-heading">
        <div class="email-list-toolbar">
            <h2 id="email-list-heading">Outbox records</h2>
            <form action="/admin/email" method="get">
                <label for="email-status-filter">Status</label>
                <select id="email-status-filter" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($stateLabels as $state => $label): ?>
                        <option value="<?= $e($state) ?>"<?= $status === $state ? ' selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <button class="btn-outline" type="submit">Filter</button>
            </form>
        </div>

        <?php if ($rows === []): ?>
            <p>No email activity matches this filter.</p>
        <?php else: ?>
            <div class="admin-scroll-table" tabindex="0" role="region" aria-label="Email activity records">
                <table class="admin-table">
                    <thead><tr>
                        <th scope="col">Event / record</th>
                        <th scope="col">Recipient</th>
                        <th scope="col">Status</th>
                        <th scope="col">Attempts</th>
                        <th scope="col">Last updated</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <th scope="row"><a href="/admin/email?id=<?= $e($row['outbox_id']) ?>"><?= $e($row['event_label']) ?></a><br><small><?= $e($row['record_label']) ?></small></th>
                            <td><?= $e($row['recipient_email']) ?></td>
                            <td><span class="email-state email-state--<?= $e($row['delivery_state']) ?>"><?= $e($row['state_label']) ?></span><?php if ($row['failure_code'] !== null): ?><br><small><?= $e($row['failure_code']) ?></small><?php endif ?></td>
                            <td><?= $e($row['attempt_count']) ?></td>
                            <td><?= $e($row['updated_at']) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
        <nav class="admin-pagination" aria-label="Email activity pages">
            <p>Page <?= $e($page) ?> · <?= $e($total) ?> records</p>
            <div>
                <?php if ($page > 1): ?><a href="/admin/email?page=<?= $e($page - 1) ?><?= $status !== null ? '&amp;status=' . rawurlencode($status) : '' ?>">Previous page</a><?php endif ?>
                <?php if ($has_next): ?><a href="/admin/email?page=<?= $e($page + 1) ?><?= $status !== null ? '&amp;status=' . rawurlencode($status) : '' ?>">Next page</a><?php endif ?>
            </div>
        </nav>
    </section>
<?php endif ?>
