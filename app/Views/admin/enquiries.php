<header class="admin-page-heading">
    <div><p>Administration · Customer support</p><h1>Customer conversations</h1>
        <p><?= $e($total) ?> conversations<?= $unread > 0 ? ' · ' . $e($unread) . ' unread for you' : '' ?></p></div>
</header>
<section class="admin-panel support-inbox">
    <form method="get" action="/admin/enquiries" class="support-filters">
        <label>Search <input type="search" name="q" value="<?= $e($query) ?>" maxlength="100" placeholder="Subject, name, email or ID"></label>
        <label>Status <select name="state"><option value="">All statuses</option><option value="open"<?= $stateFilter === 'open' ? ' selected' : '' ?>>Open</option><option value="resolved"<?= $stateFilter === 'resolved' ? ' selected' : '' ?>>Resolved</option></select></label>
        <button type="submit" class="btn-outline">Filter</button>
        <?php if ($query !== '' || $stateFilter !== ''): ?><a href="/admin/enquiries">Clear</a><?php endif ?>
    </form>
    <?php if ($items === []): ?><p class="support-empty">No conversations match these filters.</p>
    <?php else: ?><div class="support-list">
        <?php foreach ($items as $item): ?><a class="support-list-row" href="/admin/enquiry?id=<?= $e($item['enquiry_id']) ?>">
            <span class="support-list-row__main"><strong><?= $e($item['subject']) ?></strong><span><?= $e($item['name']) ?> · <?= $e($item['email']) ?></span>
                <?php if (!empty($item['order_reference'])): ?><span>Order reference · <?= $e($item['order_reference']) ?></span><?php endif ?>
                <span class="support-list-row__preview"><?= $e(mb_substr((string) $item['latest_body'], 0, 180)) ?></span></span>
            <span class="support-list-row__meta"><span class="support-state support-state--<?= $e($item['enquiry_state']) ?>"><?= $e(ucfirst($item['enquiry_state'])) ?></span>
                <?php if ((int) $item['unread'] > 0): ?><span class="support-unread"><?= $e($item['unread']) ?> unread</span><?php endif ?>
                <span><?= $e($item['latest_at'] ?? $item['updated_at']) ?></span><span>Open →</span></span>
        </a><?php endforeach ?>
    </div><?php endif ?>
    <?php if ($pages > 1): ?><nav class="support-pagination" aria-label="Conversation pages">
        <?php if ($page > 1): ?><a href="/admin/enquiries?<?= $e(http_build_query(['q'=>$query,'state'=>$stateFilter,'page'=>$page-1])) ?>">← Previous</a><?php endif ?>
        <span>Page <?= $e($page) ?> of <?= $e($pages) ?></span>
        <?php if ($page < $pages): ?><a href="/admin/enquiries?<?= $e(http_build_query(['q'=>$query,'state'=>$stateFilter,'page'=>$page+1])) ?>">Next →</a><?php endif ?>
    </nav><?php endif ?>
</section>
