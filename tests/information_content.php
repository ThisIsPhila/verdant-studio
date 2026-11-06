<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Persistence\ConnectionFactory;
use Verdant\Persistence\DatabaseConfig;
use Verdant\Storefront\InformationContentService;
use Verdant\Storefront\InformationPageRegistry;
use Verdant\Storefront\StorefrontSectionService;
use Verdant\Support\ValidationException;

$database = getenv('DB_NAME') ?: '';
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || preg_match('/^verdant_test_[a-z0-9_]+$/', $database) !== 1) {
    fwrite(STDERR, "Use APP_ENV=local and an isolated verdant_test_ database.\n");
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
$policyPage = 'privacy';
$pageBackup = [];
$sectionBackup = [];
$stateBackup = null;
$publicationBackup = [];
$hadPage = $db->prepare('SELECT * FROM storefront_pages WHERE page_key = ?');
$hadPage->execute([$policyPage]);
$pageBackup = $hadPage->get_result()->fetch_all(MYSQLI_ASSOC);
$hadPage->close();
if ($pageBackup !== []) {
    $sectionQuery = $db->prepare('SELECT s.* FROM page_sections s WHERE s.storefront_page_id = ?');
    $sectionQuery->execute([(int) $pageBackup[0]['storefront_page_id']]);
    $sectionBackup = $sectionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $sectionQuery->close();
}
$stateQuery = $db->prepare('SELECT * FROM information_policy_state WHERE page_key = ?');
$stateQuery->execute([$policyPage]);
$stateBackup = $stateQuery->get_result()->fetch_assoc() ?: null;
$stateQuery->close();
$historyQuery = $db->prepare('SELECT * FROM information_policy_publications WHERE page_key = ?');
$historyQuery->execute([$policyPage]);
$publicationBackup = $historyQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$historyQuery->close();

$cleanPolicy = static function () use ($db, $policyPage): void {
    $db->begin_transaction();
    try {
        foreach ([
            'DELETE FROM information_policy_publications WHERE page_key = ?',
            'DELETE FROM information_policy_state WHERE page_key = ?',
        ] as $sql) {
            $statement = $db->prepare($sql);
            $statement->execute([$policyPage]);
            $statement->close();
        }
        $statement = $db->prepare('DELETE s FROM page_sections s JOIN storefront_pages p ON p.storefront_page_id = s.storefront_page_id WHERE p.page_key = ?');
        $statement->execute([$policyPage]);
        $statement->close();
        $statement = $db->prepare('DELETE FROM storefront_pages WHERE page_key = ?');
        $statement->execute([$policyPage]);
        $statement->close();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
};

$restorePolicy = static function () use ($db, $policyPage, $pageBackup, $sectionBackup, $stateBackup, $publicationBackup, $cleanPolicy): void {
    $cleanPolicy();
    $db->begin_transaction();
    try {
        foreach ($pageBackup as $row) {
            $statement = $db->prepare('INSERT INTO storefront_pages (storefront_page_id, page_key, initialized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$row['storefront_page_id'], $row['page_key'], $row['initialized_at'], $row['created_at'], $row['updated_at']]);
            $statement->close();
        }
        foreach ($sectionBackup as $row) {
            $statement = $db->prepare('INSERT INTO page_sections
                (page_section_id, storefront_page_id, section_key, section_type, position, is_enabled, heading, body, media_path, media_alt, link_label, link_path, collection_id, arrangement_id, archived_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([
                $row['page_section_id'], $row['storefront_page_id'], $row['section_key'], $row['section_type'], $row['position'],
                $row['is_enabled'], $row['heading'], $row['body'], $row['media_path'], $row['media_alt'], $row['link_label'],
                $row['link_path'], $row['collection_id'], $row['arrangement_id'], $row['archived_at'], $row['created_at'], $row['updated_at'],
            ]);
            $statement->close();
        }
        if ($stateBackup !== null) {
            $statement = $db->prepare('INSERT INTO information_policy_state (page_key, status, draft_revision, published_revision, updated_at) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$stateBackup['page_key'], $stateBackup['status'], $stateBackup['draft_revision'], $stateBackup['published_revision'], $stateBackup['updated_at']]);
            $statement->close();
        }
        foreach ($publicationBackup as $row) {
            $statement = $db->prepare('INSERT INTO information_policy_publications
                (publication_id, page_key, version, effective_date, draft_revision, sections_json, published_by, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([
                $row['publication_id'], $row['page_key'], $row['version'], $row['effective_date'], $row['draft_revision'],
                $row['sections_json'], $row['published_by'], $row['published_at'],
            ]);
            $statement->close();
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
};

$cleanPolicy();
$failed = false;
try {
    $sections = new StorefrontSectionService($db);
    $content = new InformationContentService($db, $sections);
    $check(count(InformationPageRegistry::pages()) === 12, 'The fixed registry includes ten information destinations and two additional article identities.');
    $check(!empty($content->page($policyPage)['publication']['baseline']), 'A controlled public baseline is available before the first policy snapshot.');
    $draft = $content->page($policyPage, false);
    $check(is_array($draft) && count($draft['sections']) > 0, 'The protected draft view receives the registered policy sections.');
    $state = $content->policyState($policyPage);
    $check($state !== null && $state['status'] === 'draft' && $state['has_draft_changes'], 'Initial policy content is classified as a private draft.');

    $section = $draft['sections'][0];
    $input = [
        'heading' => (string) $section['heading'], 'body' => (string) $section['body'],
        'media_path' => (string) ($section['media_path'] ?? ''), 'media_alt' => (string) $section['media_alt'],
        'link_label' => (string) $section['link_label'], 'link_path' => (string) ($section['link_path'] ?? ''),
        'collection_id' => (string) ($section['collection_id'] ?? ''), 'arrangement_id' => (string) ($section['arrangement_id'] ?? ''),
    ];
    $input['body'] .= "\n\nReviewed local policy draft wording.";
    $sections->save($policyPage, (string) $section['section_key'], $input);
    $state = $content->policyState($policyPage);
    $check(!str_contains(json_encode($content->page($policyPage)), 'Reviewed local policy draft wording.'), 'Private policy edits never leak into the public baseline.');
    $check((int) $state['draft_revision'] === 2, 'Saving policy sections advances the protected draft revision.');

    $expectValidation = static function (callable $operation, string $message) use ($check): void {
        try {
            $operation();
        } catch (ValidationException) {
            $check(true, $message);
            return;
        }
        $check(false, $message);
    };
    $expectValidation(static fn () => $content->publishPolicy($policyPage, [
        'version' => '1.0', 'effective_date' => '2030-02-03', 'revision' => (string) $state['draft_revision'],
    ], 100), 'Publication requires the explicit confirmation field.');
    $expectValidation(static fn () => $content->publishPolicy($policyPage, [
        'version' => '1.0', 'effective_date' => '2030-02-30', 'revision' => (string) $state['draft_revision'], 'confirm_publish' => '1',
    ], 100), 'Invalid effective dates are rejected.');
    $expectValidation(static fn () => $content->publishPolicy($policyPage, [
        'version' => '1.0', 'effective_date' => '2030-02-03', 'revision' => '1', 'confirm_publish' => '1',
    ], 100), 'Stale reviewed revisions cannot be published.');

    $published = $content->publishPolicy($policyPage, [
        'version' => '1.0', 'effective_date' => '2030-02-03', 'revision' => (string) $state['draft_revision'], 'confirm_publish' => '1',
    ], 100);
    $publicBeforeEdit = $content->page($policyPage);
    $check($published['version'] === '1.0' && $publicBeforeEdit !== null, 'A confirmed policy draft creates a public versioned snapshot.');
    $oldPublicSections = $publicBeforeEdit['sections'];
    $row = $content->page($policyPage, false)['sections'][0];
    $nextInput = [
        'heading' => (string) $row['heading'], 'body' => (string) $row['body'] . "\n\nLater draft edit.",
        'media_path' => (string) ($row['media_path'] ?? ''), 'media_alt' => (string) $row['media_alt'],
        'link_label' => (string) $row['link_label'], 'link_path' => (string) ($row['link_path'] ?? ''),
        'collection_id' => (string) ($row['collection_id'] ?? ''), 'arrangement_id' => (string) ($row['arrangement_id'] ?? ''),
    ];
    $sections->save($policyPage, (string) $row['section_key'], $nextInput);
    $publicAfterEdit = $content->page($policyPage);
    $check($publicAfterEdit['sections'] === $oldPublicSections, 'Editing a published policy leaves the public snapshot unchanged.');
    $check($content->policyState($policyPage)['has_draft_changes'], 'Editing a published policy creates a private draft revision.');

    $nextState = $content->policyState($policyPage);
    $content->publishPolicy($policyPage, [
        'version' => '1.1', 'effective_date' => '2031-01-01', 'revision' => (string) $nextState['draft_revision'], 'confirm_publish' => '1',
    ], 100);
    $history = $content->policyHistory($policyPage);
    $check(count($history) === 2 && $history[0]['version'] === '1.1', 'Publishing a later version retains the earlier publication in history.');
    $check($content->page($policyPage)['sections'][0]['body'] === $nextInput['body'], 'Public rendering selects the newest explicitly published version.');
    $article = $content->page('journal-article');
    $check($article !== null, 'A registered visible article is available.');
    $sections->setVisible('journal-article', 'hero', false);
    try {
        $check($content->page('journal-article') === null, 'An unpublished article cannot be retrieved publicly.');
        $check($content->page('journal-article', false) !== null, 'An administrator can still prepare an unpublished article.');
    } finally {
        $sections->setVisible('journal-article', 'hero', true);
    }
    echo "Information content checks: $checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    $failed = true;
} finally {
    try {
        $restorePolicy();
    } catch (Throwable $error) {
        fwrite(STDERR, 'Test cleanup failed; inspect only the isolated test database: ' . $error->getMessage() . "\n");
        $failed = true;
    }
    $db->close();
}

exit($failed ? 1 : 0);
