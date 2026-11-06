<?php
$visionPage = true;
require_once __DIR__ . '/components/_helpers.php';
$hero = ['image' => '/assets/vision/images/hero-account.jpg', 'title' => 'Welcome back', 'body' => 'Manage your orders, rentals, saved configurations and account details all in one place.'];
require __DIR__ . '/vision/page-hero.php';
?>
<section class="vision-container vision-account-layout" data-account-tabs>
    <?php require __DIR__ . '/vision/account-navigation.php'; ?>
    <div class="vision-account-history">
        <p class="vision-account-greeting">Hello, <?= $e($actor['name']) ?></p>
        <div class="vision-account-panels">
        <?php foreach ([['title'=>'Your orders','records'=>$orders,'kind'=>'order','id'=>'account-orders'], ['title'=>'Your rentals','records'=>$rentals,'kind'=>'rental','id'=>'account-rentals']] as $history): ?>
            <section id="<?= $e($history['id']) ?>" class="account-section vision-account-section">
                <div class="vision-results-heading"><div><h2 class="text-4xl"><?= $e($history['title']) ?></h2><p class="vision-muted"><?= $history['kind'] === 'order' ? 'Track and review your purchases.' : 'View your plant and styling rentals.' ?></p></div><span><?= $e(count($history['records'])) ?> records</span></div>
                <?php if (!$history['records']): ?><div class="vision-account-empty"><h3><?= $history['kind'] === 'order' ? 'No purchases yet' : 'No rentals yet' ?></h3><p>Your <?= $history['kind'] === 'order' ? 'purchases' : 'bookings' ?> will appear here once prepared.</p><a class="link-caps" href="<?= $history['kind'] === 'order' ? '/catalogue' : '/catalogue?mode=rent' ?>">Browse <?= $history['kind'] === 'order' ? 'the collection' : 'rentals' ?> →</a></div>
                <?php else: foreach ($history['records'] as $record): require __DIR__ . '/vision/account-record.php'; endforeach; endif ?>
            </section>
        <?php endforeach ?>
        <section id="account-saved" class="vision-account-section">
            <div class="vision-results-heading"><div><h2 class="text-4xl">Your saved configurations</h2><p class="vision-muted">Keep your favourite plant and pot combinations for later.</p></div><a class="link-caps" href="/compositions/saved">View all saved →</a></div>
            <?php if (empty($saved)): ?><p class="vision-account-empty">You have no saved arrangements yet. <a href="/configure">Create your first pairing.</a></p><?php else: foreach ($saved as $savedItem): ?><article class="vision-saved-record"><h3><?= $e($savedItem['name']) ?></h3><p class="vision-muted"><?= $e($savedItem['definition_name_snapshot']) ?></p><a class="btn-outline" href="/configure?saved_id=<?= $e($savedItem['saved_composition_id']) ?>">View configuration →</a></article><?php endforeach; endif ?>
            <a class="link-caps" href="/compositions/saved">Arrangement checkouts →</a>
        </section>
        <section id="account-support" class="vision-account-section">
            <div class="vision-results-heading"><div><h2 class="text-4xl">Your support conversations</h2><p class="vision-muted">Read replies and continue a conversation with our team.</p></div><a class="link-caps" href="/contact#customer-support">Contact support →</a></div>
            <?php if (empty($supportInbox['items'])): ?><p class="vision-account-empty">Your support conversations will appear here.</p>
            <?php else: ?><div class="support-list"><?php foreach ($supportInbox['items'] as $supportItem): ?>
                <a class="support-list-row" href="/account/enquiry?id=<?= $e($supportItem['enquiry_id']) ?>"><span class="support-list-row__main"><strong><?= $e($supportItem['subject']) ?></strong><span><?= $e(ucfirst($supportItem['enquiry_state'])) ?></span></span><?php if ((int)$supportItem['unread'] > 0): ?><span class="support-unread">New reply</span><?php endif ?></a>
            <?php endforeach ?></div><a class="link-caps" href="/account/support">All support conversations →</a><?php endif ?>
        </section>
            <aside id="account-profile" class="vision-account-profile vision-account-section">
                <h2 class="text-3xl">Your account</h2><p class="vision-muted">Signed in as</p><p><?= $e($actor['name']) ?></p><p class="vision-profile-email"><?= $e($actor['email'] ?? '') ?></p>
                <form action="/logout" method="post"><input type="hidden" name="csrf" value="<?= $e(\Verdant\Support\Csrf::token()) ?>"><button type="submit" class="btn-outline">Sign out</button></form>
                <a class="link-caps" href="/catalogue">Explore the shop →</a>
                <?php if (($actor['role'] ?? null) === 'admin' && ($adminOrigin = \Verdant\Config\TrustedOrigins::admin()) !== null): ?>
                    <a class="link-caps" href="<?= $e($adminOrigin) ?>/admin">Administration →</a>
                <?php endif ?>
            </aside>
        </div>
    </div>
</section>
<script src="/assets/js/vision-account.js?v=<?= $e((string) filemtime(dirname(__DIR__, 2) . '/public/assets/js/vision-account.js')) ?>" defer></script>
