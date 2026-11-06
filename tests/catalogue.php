<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Catalogue\CatalogueService;
use Verdant\Catalogue\ProductRepository;
use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Support\ValidationException;

if (!preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '')) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n"); exit(2);
}
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$db->begin_transaction();
$checks = 0;
$check = function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
try {
    $db->query("INSERT INTO categories(name) VALUES ('Catalogue verification')");
    $category = (int) $db->insert_id;
    $statement = $db->prepare("INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,product_kind,buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor)
        VALUES(?,?,'Botanical <script>fixture</script>','Synthetic','indirect','weekly','non_toxic','plant',1,1,1000,200)");
    $statement->execute([$category, 'CAT-' . bin2hex(random_bytes(6))]);
    $id = (int) $db->insert_id;
    $service = new CatalogueService(new ProductRepository($db));
    $page = $service->catalogue(['category_id' => (string) $category, 'light_level' => 'indirect']);
    $check(count($page['products']) === 1, 'Combined filters select stored data.');
    $check((int) $page['products'][0]['product_id'] === $id, 'Correct product returned.');
    $check($service->product($id)['rent_enabled'] == 1, 'Dual eligibility retained.');
    $check($service->catalogue(['light_level' => "' OR 1=1 --"])['products'] === [], 'Filter injection treated as a value.');
    $check($service->catalogue(['toxicity' => 'does_not_exist'])['products'] === [], 'Empty result supported.');
    foreach ([['category_id' => ['invalid']], ['page' => '-1'], ['category_id' => '-5']] as $invalid) {
        try { $service->catalogue($invalid); throw new RuntimeException('Invalid filter accepted.'); }
        catch (ValidationException) { ++$checks; }
    }
    $db->query("UPDATE products SET archived_at=UTC_TIMESTAMP() WHERE product_id=$id");
    $check($service->product($id) === null, 'Archived detail unavailable.');
    $check($service->catalogue(['category_id' => (string) $category])['products'] === [], 'Archived item absent in lists.');
    echo "Catalogue checks: $checks passed, 0 failed.\n";
} finally { $db->rollback(); $db->close(); }
