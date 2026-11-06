<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local'
    || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$runFixture = static function (): string {
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/database/bin/seed-catalogue-variants.php')
        . ' apply 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Catalogue variant seed failed; inspect its safe local output.');
    }
    return implode("\n", $output);
};
$beforeProduct = null;
$beforeInventory = null;
try {
    $beforeProduct = $db->query(
        "SELECT p.product_id, p.name, p.retail_price_minor, p.image_path, p.image_alt,
                p.family_id, p.variant_label, p.accessory_subtype
         FROM products p JOIN catalogue_fixture_ledger l ON l.product_id = p.product_id
         WHERE l.fixture_code = 'starter-catalogue-v1' AND l.sku = 'PL-MON-S' AND l.disposition = 'inserted'",
    )->fetch_assoc();
    if ($beforeProduct === null) {
        throw new RuntimeException('The starter SKU fixture must run before its variant fixture replay check.');
    }
    $productId = (int) $beforeProduct['product_id'];
    $beforeInventory = $db->query('SELECT on_hand, version FROM inventory WHERE product_id=' . $productId)->fetch_assoc();
    $check($beforeProduct['family_id'] !== null && $beforeProduct['variant_label'] === 'Small'
        && $beforeProduct['accessory_subtype'] === 'other',
        'The reviewed variant seed assigned the Monstera SKU without changing its product kind.');

    $newName = 'Synthetic preserved admin edit ' . bin2hex(random_bytes(5));
    $newPrice = (int) $beforeProduct['retail_price_minor'] + 321;
    $newOnHand = (int) $beforeInventory['on_hand'] + 1;
    $edit = $db->prepare('UPDATE products SET name=?, retail_price_minor=?, image_alt=? WHERE product_id=?');
    $edit->execute([$newName, $newPrice, 'Synthetic preserved alt text', $productId]);
    $edit->close();
    $edit = $db->prepare('UPDATE inventory SET on_hand=?, version=version+1 WHERE product_id=?');
    $edit->execute([$newOnHand, $productId]);
    $edit->close();
    $editedVersion = (int) $db->query('SELECT version FROM inventory WHERE product_id=' . $productId)->fetch_assoc()['version'];

    $output = $runFixture();
    $check(str_contains($output, 'Catalogue variant fixture complete: 0 inserted,')
        && str_contains($output, 'already processed.'),
        'Fixture runner reports existing rows as already processed rather than new inserts.');
    $afterProduct = $db->query(
        'SELECT name, retail_price_minor, image_alt, family_id, variant_label FROM products WHERE product_id=' . $productId,
    )->fetch_assoc();
    $afterInventory = $db->query('SELECT on_hand, version FROM inventory WHERE product_id=' . $productId)->fetch_assoc();
    $check($afterProduct['name'] === $newName && (int) $afterProduct['retail_price_minor'] === $newPrice
        && $afterProduct['image_alt'] === 'Synthetic preserved alt text',
        'Fixture replay preserves an administrator product name, price and image-alt edit.');
    $check((int) $afterProduct['family_id'] === (int) $beforeProduct['family_id']
        && $afterProduct['variant_label'] === 'Small',
        'Fixture replay preserves the existing family assignment and label.');
    $check((int) $afterInventory['on_hand'] === $newOnHand && (int) $afterInventory['version'] === $editedVersion,
        'Fixture replay changes neither on-hand stock nor the optimistic inventory version.');
    echo "Catalogue variant seed checks: $checks passed, 0 failed.\n";
} finally {
    if ($beforeProduct !== null && $beforeInventory !== null) {
        $productId = (int) $beforeProduct['product_id'];
        $restore = $db->prepare('UPDATE products SET name=?, retail_price_minor=?, image_path=?, image_alt=? WHERE product_id=?');
        $restore->execute([
            $beforeProduct['name'], $beforeProduct['retail_price_minor'], $beforeProduct['image_path'],
            $beforeProduct['image_alt'], $productId,
        ]);
        $restore->close();
        $restore = $db->prepare('UPDATE inventory SET on_hand=?, version=version+1 WHERE product_id=?');
        $restore->execute([$beforeInventory['on_hand'], $productId]);
        $restore->close();
    }
    $db->close();
}
