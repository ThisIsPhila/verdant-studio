<section class="editorial-section">
    <h2><?= $e($section['heading']) ?></h2>
    <p><?= $e($section['body']) ?></p>
    <?php if ($section['link_path'] !== null): ?>
        <?php
        $component = ['label' => $section['link_label'], 'href' => $section['link_path'], 'variant' => 'secondary'];
        require __DIR__ . '/../components/button.php';
        ?>
    <?php endif ?>
</section>
