<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Catalogue\CatalogueService;
use Verdant\Catalogue\ProductRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\Format;

if (PHP_SAPI !== 'cli' || preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
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

try {
    $db->begin_transaction();
    try {
        $suffix = strtoupper(bin2hex(random_bytes(5)));
        $statement = $db->prepare('INSERT INTO categories (name) VALUES (?)');
        $statement->execute(['Kind test ' . $suffix]);
        $categoryId = (int) $db->insert_id;
        $statement->close();

        $statement = $db->prepare("INSERT INTO products
            (category_id, sku, name, description, light_level, watering_level, toxicity,
             product_kind, selling_unit, size_label, details_status, image_path, image_alt,
             buy_enabled, rent_enabled, retail_price_minor)
            VALUES (?, ?, 'Synthetic shears', 'Demonstration only', 'unknown', 'unknown', 'unknown',
             'accessory', 'Each', 'Standard', 'demonstration',
             '/assets/images/catalogue/placeholders/accessory.svg',
             'Illustrative accessory placeholder; not product photography.', 1, 0, 15000)");
        $statement->execute([$categoryId, 'KF-' . $suffix]);
        $productId = (int) $db->insert_id;
        $statement->close();
        $statement = $db->prepare('INSERT INTO inventory (product_id, on_hand) VALUES (?, 4)');
        $statement->execute([$productId]);
        $statement->close();

        $repository = new ProductRepository($db);
        $product = $repository->findActive($productId);
        $check($product !== null && $product['product_kind'] === 'accessory'
            && $product['selling_unit'] === 'Each' && $product['size_label'] === 'Standard',
            'Product details are returned with the stock-bearing SKU.');
        $check($product['light_level'] === 'unknown' && $product['watering_level'] === 'unknown'
            && $product['toxicity'] === 'unknown', 'Unverified accessory care and safety remain unknown.');
        $check(is_file(dirname(__DIR__) . '/public' . $product['image_path'])
            && Format::imagePath($product['image_path']) === $product['image_path'],
            'The local neutral placeholder is a controlled, available image path.');
        $page = (new CatalogueService($repository))->catalogue(['category_id' => (string) $categoryId]);
        $check(count($page['products']) === 1 && $page['products'][0]['product_kind'] === 'accessory',
            'Typed accessory offers appear in normal catalogue results.');
        $check(!in_array('unknown', $page['options']['light_level'], true)
            && !in_array('unknown', $page['options']['watering_level'], true)
            && !in_array('unknown', $page['options']['toxicity'], true),
            'Unknown care values are not offered as positive catalogue filters.');

        try {
            $statement = $db->prepare("INSERT INTO products
                (category_id, sku, name, description, light_level, watering_level, toxicity, product_kind,
                 buy_enabled, rent_enabled, retail_price_minor, rental_daily_rate_minor)
                VALUES (?, ?, 'Cut flower', 'Synthetic', 'unknown', 'unknown', 'unknown', 'cut_flower', 1, 1, 1000, 100)");
            $statement->execute([$categoryId, 'KF-FL-' . $suffix]);
            $statement->close();
            throw new RuntimeException('The database accepted a rentable cut flower.');
        } catch (mysqli_sql_exception $error) {
            $check($error->getCode() === 3819, 'The database rejects direct cut flower rental writes.');
        }

        $db->rollback();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
    echo "Catalogue foundation checks: $checks passed, 0 failed.\n";
} finally {
    $db->close();
}
