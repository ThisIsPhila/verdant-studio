<?php
/**
 * Button/link primitive.
 * Input: $component = ['label'=>string, 'href'=>local path|null, 'type'=>button|submit|reset,
 * 'variant'=>primary|secondary|quiet, 'name'=>string|null, 'value'=>string|null].
 */
require_once __DIR__ . '/_helpers.php';
$_viewButton = $component ?? [];
$_viewButtonVariant = in_array($_viewButton['variant'] ?? 'primary', ['primary', 'secondary', 'quiet'], true) ? $_viewButton['variant'] ?? 'primary' : 'primary';
if (isset($_viewButton['href'])):
    $_viewButtonHref = verdant_view_local_path($_viewButton['href'], '#');
?>
<a class="button button--<?= $e($_viewButtonVariant) ?>" href="<?= $e($_viewButtonHref) ?>"><?= $e($_viewButton['label'] ?? '') ?></a>
<?php else: ?>
<button class="button button--<?= $e($_viewButtonVariant) ?>" type="<?= $e(in_array($_viewButton['type'] ?? 'button', ['button', 'submit', 'reset'], true) ? ($_viewButton['type'] ?? 'button') : 'button') ?>"<?php if (isset($_viewButton['name'])): ?> name="<?= $e($_viewButton['name']) ?>"<?php endif ?><?php if (isset($_viewButton['value'])): ?> value="<?= $e($_viewButton['value']) ?>"<?php endif ?>><?= $e($_viewButton['label'] ?? '') ?></button>
<?php endif ?>
