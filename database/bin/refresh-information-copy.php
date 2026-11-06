<?php

declare(strict_types=1);

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;

require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || ($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Use APP_ENV=local php database/bin/refresh-information-copy.php --apply.\n");
    exit(2);
}
$changes = json_decode(file_get_contents(dirname(__DIR__) . '/data/information-copy-2026-10-22.json'), true, 512, JSON_THROW_ON_ERROR);
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$updated = $preserved = 0;
$policyChanges = [];
$db->begin_transaction();
try {
    foreach ($changes as $change) {
        $query = $db->prepare('SELECT s.* FROM page_sections s JOIN storefront_pages p ON p.storefront_page_id = s.storefront_page_id WHERE p.page_key = ? AND s.section_key = ? FOR UPDATE');
        $query->execute([$change['page_key'], $change['section_key']]);
        $row = $query->get_result()->fetch_assoc();
        $query->close();
        if ($row === null) continue;
        foreach ($change['fields'] as $field => $copy) {
            if (!in_array($field, ['heading', 'body', 'link_label', 'link_path'], true)) throw new RuntimeException('Invalid copy field.');
            if ($row[$field] === $copy['after']) continue;
            if (!in_array($row[$field], $copy['before'], true)) {
                if ($row[$field] !== $copy['after']) ++$preserved;
                continue;
            }
            $save = $db->prepare("UPDATE page_sections SET $field = ?, updated_at = UTC_TIMESTAMP() WHERE page_section_id = ?");
            $save->execute([$copy['after'], $row['page_section_id']]);
            $save->close();
            ++$updated;
            if (in_array($change['page_key'], ['privacy', 'terms'], true)) $policyChanges[$change['page_key']] = true;
        }
    }
    foreach (array_keys($policyChanges) as $key) {
        $bump = $db->prepare('UPDATE information_policy_state SET draft_revision = draft_revision + 1, updated_at = UTC_TIMESTAMP() WHERE page_key = ?');
        $bump->execute([$key]);
        $bump->close();
    }
    $db->commit();
    echo "Updated untouched default fields: $updated; preserved edited fields: $preserved. Published snapshots unchanged.\n";
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
}
