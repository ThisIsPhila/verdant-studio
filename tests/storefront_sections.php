<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Storefront\SectionRegistry;
use Verdant\Storefront\StorefrontSectionService;
use Verdant\Support\ValidationException;

if (preg_match('/^verdant_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '') !== 1) {
    fwrite(STDERR, "Use an isolated verdant_test_ database.\n");
    exit(2);
}

$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
    echo "PASS: $label\n";
};
$snapshotPages = $db->query('SELECT * FROM storefront_pages')->fetch_all(MYSQLI_ASSOC);
$snapshotSections = $db->query('SELECT * FROM page_sections')->fetch_all(MYSQLI_ASSOC);
$service = new StorefrontSectionService($db);
$locker = null;

try {
    $db->begin_transaction();
    $db->query('DELETE FROM page_sections');
    $db->query('DELETE FROM storefront_pages');
    $db->commit();

    $service->initialisePage('home');
    $initial = $service->sectionsForPage('home', true);
    $commercePages = ['home', 'homes', 'catalogue', 'arrangements', 'rentals'];
    $commerceDefaults = array_filter(SectionRegistry::defaults(), static fn (array $row): bool => in_array($row['page_key'], $commercePages, true));
    $check(count($initial) === 7 && count($commerceDefaults) === 16, 'Page initialization inserts all fixed commerce defaults while information-page defaults remain additive.');
    $hero = null;
    foreach ($initial as $row) {
        if ($row['section_key'] === 'hero') {
            $hero = $row;
            break;
        }
    }
    $check($hero !== null && $hero['section_type'] === 'hero', 'Registry returns a registered page and section type.');
    $sectionKey = (string) $hero['section_key'];
    $originalOrder = array_column($initial, 'section_key');

    $edited = $service->save('home', $sectionKey, [
        'heading' => 'Owner-edited heading', 'body' => 'Owner-edited plain text.',
        'media_path' => '/assets/images/catalogue/placeholders/plant.svg', 'media_alt' => 'Synthetic plant placeholder',
        'link_label' => 'Visit the catalogue', 'link_path' => '/catalogue',
        'collection_id' => '', 'arrangement_id' => '',
    ]);
    $check($edited['heading'] === 'Owner-edited heading' && $edited['body'] === 'Owner-edited plain text.', 'Explicit content fields persist through the service.');
    $service->initialiseDefaults();
    $replayed = $service->sectionsForPage('home', true);
    $replayedHero = array_values(array_filter($replayed, static fn (array $row): bool => $row['section_key'] === $sectionKey))[0] ?? null;
    $check(($replayedHero['heading'] ?? null) === 'Owner-edited heading', 'Defaults replay does not overwrite owner edits.');
    $homePageId = (int) $db->query("SELECT storefront_page_id FROM storefront_pages WHERE page_key = 'home'")->fetch_row()[0];
    $missingDefault = $db->prepare('DELETE FROM page_sections WHERE storefront_page_id = ? AND section_key = ?');
    $missingDefault->execute([$homePageId, 'configuration']);
    $missingDefault->close();
    $service->initialiseDefaults();
    $refreshed = array_values(array_filter($service->sectionsForPage('home', true), static fn (array $row): bool => $row['section_key'] === 'configuration'))[0] ?? null;
    $check($refreshed !== null && (int) $refreshed['is_enabled'] === 1, 'Add-only replay inserts a newly approved missing default without changing existing rows.');

    $service->setVisible('home', $sectionKey, false);
    $service->archive('home', $sectionKey);
    $archived = array_values(array_filter($service->sectionsForPage('home', true), static fn (array $row): bool => $row['section_key'] === $sectionKey))[0] ?? null;
    $check($archived !== null && $archived['archived_at'] !== null, 'Archive preserves section identity and state.');
    $service->initialiseDefaults();
    $archivedAgain = array_values(array_filter($service->sectionsForPage('home', true), static fn (array $row): bool => $row['section_key'] === $sectionKey))[0] ?? null;
    $check($archivedAgain !== null && $archivedAgain['archived_at'] !== null, 'Defaults replay never recreates an archived section.');
    $service->restore('home', $sectionKey);
    $restored = array_values(array_filter($service->sectionsForPage('home', true), static fn (array $row): bool => $row['section_key'] === $sectionKey))[0] ?? null;
    $check($restored !== null && $restored['archived_at'] === null && (int) $restored['is_enabled'] === 0, 'Restore preserves identity and independent hidden visibility.');

    $current = $service->sectionsForPage('home', true);
    foreach ($current as $row) {
        if ($row['archived_at'] === null) {
            $service->setVisible('home', (string) $row['section_key'], false);
        }
    }
    $missingDefault = $db->prepare('DELETE FROM page_sections WHERE storefront_page_id = ? AND section_key = ?');
    $missingDefault->execute([$homePageId, 'rentals']);
    $missingDefault->close();
    $check($service->sectionsForPage('home') === [], 'A configured page with every section hidden stays empty.');
    $service->initialiseDefaults();
    $check($service->sectionsForPage('home') === [], 'Defaults do not resurrect a deliberately all-hidden page.');
    $hiddenNewDefault = array_values(array_filter($service->sectionsForPage('home', true), static fn (array $row): bool => $row['section_key'] === 'rentals'))[0] ?? null;
    $check($hiddenNewDefault !== null && (int) $hiddenNewDefault['is_enabled'] === 0, 'A newly added default stays disabled while the owner has hidden every page section.');

    foreach ($current as $row) {
        if ($row['archived_at'] === null) {
            $service->setVisible('home', (string) $row['section_key'], (bool) $row['is_enabled']);
        }
    }
    $beforeMove = $service->sectionsForPage('home', true);
    $movable = array_values(array_filter($beforeMove, static fn (array $row): bool => $row['archived_at'] === null));
    if (count($movable) > 1) {
        $secondKey = (string) $movable[1]['section_key'];
        $service->move('home', $secondKey, -1);
        $afterMove = array_values(array_filter($service->sectionsForPage('home', true), static fn (array $row): bool => $row['archived_at'] === null));
        $check($afterMove[0]['section_key'] === $secondKey, 'Reorder moves a section by taking the page lock and updating a stable order.');
    }

    $raceBefore = array_values(array_filter($service->sectionsForPage('home', true), static fn (array $row): bool => $row['archived_at'] === null));
    if (count($raceBefore) > 2) {
        $locker = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
        $locker->begin_transaction();
        $lockPage = $locker->prepare('SELECT storefront_page_id FROM storefront_pages WHERE page_key = ? FOR UPDATE');
        $lockPage->execute(['home']);
        $lockPage->get_result()->fetch_assoc();
        $lockPage->close();

        $startWorker = static function (string $key, int $direction): array {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/storefront_sections_reorder_worker.php', 'home', $key, (string) $direction],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to launch isolated reorder worker.');
            }
            fclose($pipes[0]);
            return [$process, $pipes];
        };
        $workers = [
            $startWorker((string) $raceBefore[0]['section_key'], 1),
            $startWorker((string) $raceBefore[1]['section_key'], -1),
        ];
        usleep(250000);
        $waitingOnPageLock = true;
        foreach ($workers as [$process]) {
            $status = proc_get_status($process);
            $waitingOnPageLock = $waitingOnPageLock && $status['running'];
        }
        $locker->commit();
        $locker->close();
        $locker = null;

        $workerCodes = [];
        $workerOutput = [];
        $workerErrors = [];
        $deadline = microtime(true) + 8.0;
        foreach ($workers as [$process, $pipes]) {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $workerCodes[] = $status['exitcode'];
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($process);
                $workerCodes[] = 124;
            }
            $workerOutput[] = trim(stream_get_contents($pipes[1]));
            $workerErrors[] = trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        $raceAfter = array_values(array_filter($service->sectionsForPage('home', true), static fn (array $row): bool => $row['archived_at'] === null));
        $beforeKeys = array_column($raceBefore, 'section_key');
        $afterKeys = array_column($raceAfter, 'section_key');
        sort($beforeKeys);
        sort($afterKeys);
        $positions = array_map('intval', array_column($raceAfter, 'position'));
        $racePassed = $waitingOnPageLock && $workerCodes === [0, 0] && $beforeKeys === $afterKeys
            && $positions === array_map(static fn (int $index): int => ($index + 1) * 10, array_keys($positions));
        if (!$racePassed) {
            fwrite(STDERR, 'Concurrent reorder diagnostics: waiting=' . (int) $waitingOnPageLock
                . '; exit=' . implode(',', $workerCodes) . '; output=' . implode(',', $workerOutput)
                . '; error=' . implode(',', $workerErrors)
                . '; before=' . implode(',', $beforeKeys) . '; after=' . implode(',', $afterKeys)
                . '; positions=' . implode(',', $positions) . "\n");
        }
        $check($racePassed, 'Concurrent separate-connection reorders serialize on the page lock and retain every section in contiguous order.');
    }

    try {
        $service->save('home', $sectionKey, [
            'heading' => 'Unsafe route test', 'body' => '', 'media_path' => '', 'media_alt' => '',
            'link_label' => 'Unsafe', 'link_path' => 'https://example.invalid/',
            'collection_id' => '', 'arrangement_id' => '',
        ]);
        throw new RuntimeException('External link was accepted.');
    } catch (ValidationException) {
        ++$checks;
        echo "PASS: unsafe external links are rejected.\n";
    }
    try {
        $service->save('home', $sectionKey, [
            'heading' => '<script>alert(1)</script>', 'body' => '', 'media_path' => '', 'media_alt' => '',
            'link_label' => '', 'link_path' => '', 'collection_id' => '', 'arrangement_id' => '',
        ]);
        throw new RuntimeException('Markup was accepted as editable content.');
    } catch (ValidationException) {
        ++$checks;
        echo "PASS: markup is rejected in editable content.\n";
    }
    try {
        $service->save('home', $sectionKey, [
            'heading' => 'Unsafe media test', 'body' => '', 'media_path' => '/assets/../index.php', 'media_alt' => '',
            'link_label' => '', 'link_path' => '', 'collection_id' => '', 'arrangement_id' => '',
        ]);
        throw new RuntimeException('Traversal media was accepted.');
    } catch (ValidationException) {
        ++$checks;
        echo "PASS: media traversal is rejected.\n";
    }
    try {
        $service->save('home', $sectionKey, [
            'heading' => 'Invalid collection reference', 'body' => '', 'media_path' => '', 'media_alt' => '',
            'link_label' => '', 'link_path' => '', 'collection_id' => '999999999999999999', 'arrangement_id' => '',
        ]);
        throw new RuntimeException('A missing collection was accepted.');
    } catch (ValidationException) {
        ++$checks;
        echo "PASS: missing collection references are rejected.\n";
    }
    $check(SectionRegistry::isSupportedRoute('/catalogue?kind=plant&mode=rent'), 'A supported catalogue route query is accepted.');
    $check(!SectionRegistry::isSupportedRoute('/catalogue?kind=plant&next=https://example.invalid'), 'Unregistered route query parameters are rejected.');

    echo "Storefront section checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    $failed = true;
} finally {
    try {
        if ($locker instanceof mysqli) {
            $locker->close();
        }
        $db->begin_transaction();
        $db->query('DELETE FROM page_sections');
        $db->query('DELETE FROM storefront_pages');
        foreach ($snapshotPages as $page) {
            $statement = $db->prepare('INSERT INTO storefront_pages(storefront_page_id,page_key,initialized_at,created_at,updated_at) VALUES(?,?,?,?,?)');
            $statement->execute([$page['storefront_page_id'], $page['page_key'], $page['initialized_at'], $page['created_at'], $page['updated_at']]);
            $statement->close();
        }
        foreach ($snapshotSections as $section) {
            $statement = $db->prepare('INSERT INTO page_sections
                (page_section_id,storefront_page_id,section_key,section_type,position,is_enabled,heading,body,media_path,media_alt,link_label,link_path,collection_id,arrangement_id,archived_at,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute([
                $section['page_section_id'], $section['storefront_page_id'], $section['section_key'], $section['section_type'],
                $section['position'], $section['is_enabled'], $section['heading'], $section['body'], $section['media_path'],
                $section['media_alt'], $section['link_label'], $section['link_path'], $section['collection_id'],
                $section['arrangement_id'], $section['archived_at'], $section['created_at'], $section['updated_at'],
            ]);
            $statement->close();
        }
        $db->commit();
    } catch (Throwable) {
        try { $db->rollback(); } catch (Throwable) { }
        fwrite(STDERR, "Test cleanup failed; inspect only the isolated test database.\n");
        $failed = true;
    }
    $db->close();
}

exit(isset($failed) ? 1 : 0);
