<?php /** Input: $hero = [image, title, body, eyebrow?, id?]. Fixed local sample media supplied by the caller. */ ?>
<section class="page-hero relative overflow-hidden bg-surface">
    <img src="<?= $e($hero['image']) ?>" alt="" width="1920" height="640" class="absolute inset-0 h-full w-full object-cover object-left">
    <div class="page-hero__veil" aria-hidden="true"></div>
    <div class="relative mx-auto max-w-7xl px-6 py-16 md:py-20"><div class="max-w-xl">
        <?php if (!empty($hero['eyebrow'])): ?><p class="eyebrow"><?= $e($hero['eyebrow']) ?></p><?php endif ?>
        <h1<?= !empty($hero['id']) ? ' id="' . $e($hero['id']) . '"' : '' ?> class="mt-3 text-5xl leading-[0.95] md:text-7xl"><?= $e($hero['title']) ?></h1>
        <?php if (!empty($hero['body'])): ?><p class="mt-4 text-lg text-foreground/80"><?= $e($hero['body']) ?></p><?php endif ?>
    </div></div>
</section>
