<?php
// Local product search; callers set $searchAction, $searchPreserve, $searchId and $searchLabel.
$searchAction = $searchAction ?? '/catalogue';
$searchPreserve = is_array($searchPreserve ?? null) ? $searchPreserve : [];
$searchId = $searchId ?? 'catalogue-search';
$searchLabel = $searchLabel ?? 'Search products';
?>
<form class="vision-catalogue-search" action="<?= $e($searchAction) ?>" method="get" role="search">
    <label for="<?= $e($searchId) ?>"><?= $e($searchLabel) ?></label>
    <input id="<?= $e($searchId) ?>" type="search" name="q" maxlength="120" placeholder="<?= $e($searchLabel) ?>" value="<?= $e((string) ($filters['q'] ?? '')) ?>">
    <?php foreach ($searchPreserve as $key => $value): if ($key === 'q' || $key === 'page' || !is_string($value)) continue; ?>
        <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
    <?php endforeach ?>
    <button type="submit" class="vision-small-button">Search</button>
</form>
