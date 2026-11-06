<footer class="site-footer">
    <div class="footer-brand">
        <a class="brand" href="/">VERDANT</a>
        <p><?= $e($footerTagline ?? 'Nature, curated by hand.') ?></p>
    </div>
    <div>
        <span class="footer-label">Shop</span>
        <a href="/catalogue">Plants &amp; botanicals</a>
        <a href="/arrangements">Arrangements</a>
        <a href="/catalogue?mode=rent">Rentals</a>
    </div>
    <div>
        <span class="footer-label">Explore</span>
        <a href="/collections">Collections</a>
        <a href="/collections/homes">Homes</a>
        <a href="/account">My account</a>
    </div>
    <div>
        <span class="footer-label">About &amp; help</span>
        <a href="/about">Our Story</a>
        <a href="/sustainability">Sustainability</a>
        <a href="/plant-care">Plant Care</a>
        <a href="/journal">Journal</a>
        <a href="/contact">Contact</a>
        <a href="/delivery">Delivery &amp; Collection</a>
        <a href="/returns">Returns &amp; FAQs</a>
        <?php foreach (['privacy' => 'Privacy & POPIA', 'terms' => 'Terms of Use & Sale'] as $key => $name): ?>
            <?php if (in_array($key, $informationPolicies ?? [], true)): ?>
                <a href="/<?= $e($key) ?>"><?= $e($name) ?></a>
            <?php endif ?>
        <?php endforeach ?>
    </div>
    <small class="footer-legal">© Verdant Studio · South Africa</small>
</footer>
