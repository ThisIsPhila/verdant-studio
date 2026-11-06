<section class="support-account">
    <header class="support-heading"><p>Account · Support</p><h1>Your support conversations</h1><p>Read and reply to messages from Verdant support.</p></header>
    <?php if ($items === []): ?><div class="vision-account-empty"><h2>No support conversations yet</h2><p>Contact us if you need help with an order, rental or product.</p><a href="/contact">Contact Verdant</a></div>
    <?php else: ?><div class="support-list">
        <?php foreach ($items as $item): ?><a class="support-list-row" href="/account/enquiry?id=<?= $e($item['enquiry_id']) ?>">
            <span class="support-list-row__main"><strong><?= $e($item['subject']) ?></strong><span><?= $e(mb_substr((string) $item['latest_body'], 0, 180)) ?></span></span>
            <span class="support-list-row__meta"><span class="support-state support-state--<?= $e($item['enquiry_state']) ?>"><?= $e(ucfirst($item['enquiry_state'])) ?></span><?php if ((int) $item['unread'] > 0): ?><span class="support-unread">New reply</span><?php endif ?><span><?= $e($item['updated_at']) ?></span></span>
        </a><?php endforeach ?>
    </div>
        <?php if ($pages > 1): ?><nav class="support-pagination" aria-label="Conversation pages"><?php if ($page > 1): ?><a href="/account/support?page=<?= $e($page - 1) ?>">← Newer conversations</a><?php endif ?><span>Page <?= $e($page) ?> of <?= $e($pages) ?> · <?= $e($total) ?> conversations</span><?php if ($page < $pages): ?><a href="/account/support?page=<?= $e($page + 1) ?>">Older conversations →</a><?php endif ?></nav><?php endif ?>
    <?php endif ?>
</section>
