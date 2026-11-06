<?php

declare(strict_types=1);

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli' || (getenv('APP_ENV') ?: '') !== 'local'
    || (getenv('DB_NAME') ?: '') !== 'verdant_test_phase006_http') {
    fwrite(STDERR, "Refusing rental HTTP fixture outside the named local HTTP test database.\n");
    exit(2);
}

$action = $argv[1] ?? '';
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
try {
    if ($action === 'create') {
        $statement = $db->prepare(
            "INSERT INTO products(category_id,sku,name,description,light_level,watering_level,toxicity,
                buy_enabled,rent_enabled,retail_price_minor,rental_daily_rate_minor,archived_at)
             VALUES(2001,'HTTP-ARCHIVED-RENTAL','Archived HTTP rental fixture','Synthetic fixture','indirect','weekly','non_toxic',
                0,1,NULL,750,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE product_id=LAST_INSERT_ID(product_id),archived_at=UTC_TIMESTAMP()",
        );
        $statement->execute();
        echo (string) $db->insert_id;
    } else {
        fwrite(STDERR, "Expected create.\n");
        exit(2);
    }
} finally {
    $db->close();
}
