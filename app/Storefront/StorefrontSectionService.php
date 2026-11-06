<?php

declare(strict_types=1);

namespace Verdant\Storefront;

use mysqli;
use Verdant\Persistence\DatabaseException;
use Verdant\Support\ValidationException;

/** Coordinates the editorial lifecycle without taking ownership of commerce data. */
final class StorefrontSectionService
{
    private readonly SectionRepository $sections;

    public function __construct(private readonly mysqli $connection)
    {
        $this->sections = new SectionRepository($connection);
    }

    /** Add the approved defaults once. A page marker distinguishes an empty edited page from an unconfigured one. */
    public function initialisePage(string $page): void
    {
        $this->assertPage($page);
        $this->transaction(function () use ($page): void {
            $this->sections->createPageIfMissing($page);
            $this->ensurePolicyState($page);
            $pageId = $this->sections->lockPage($page);
            if ($pageId === null || $this->sections->defaultsInitialized($pageId)) {
                return;
            }
            foreach (SectionRegistry::defaults() as $default) {
                if ($default['page_key'] !== $page) {
                    continue;
                }
                if (!SectionRegistry::isType($page, (string) $default['section_type'])) {
                    throw new \LogicException('A registered storefront default has an unsupported section type.');
                }
                $this->sections->insertDefault($pageId, $default);
            }
            $this->sections->markInitialized($pageId);
        });
    }

    /** @return array{pages:int,sections:int} */
    public function initialiseDefaults(): array
    {
        $counts = ['pages' => 0, 'sections' => 0];
        foreach (array_keys(SectionRegistry::pages()) as $page) {
            $this->assertPage($page);
            $this->transaction(function () use ($page): void {
                $this->sections->createPageIfMissing($page);
                $this->ensurePolicyState($page);
                $pageId = $this->sections->lockPage($page);
                if ($pageId === null) {
                    throw new \RuntimeException('Storefront page initialisation was lost.');
                }
                $wasInitialised = $this->sections->defaultsInitialized($pageId);
                $enableNewDefaults = !$wasInitialised || $this->sections->hasVisibleSections($pageId);
                foreach (SectionRegistry::defaults() as $default) {
                    if ($default['page_key'] !== $page) {
                        continue;
                    }
                    if (!SectionRegistry::isType($page, (string) $default['section_type'])) {
                        throw new \LogicException('A registered storefront default has an unsupported section type.');
                    }
                    $this->sections->insertDefault($pageId, $default, $enableNewDefaults);
                }
                $this->sections->markInitialized($pageId);
            });
            $after = count($this->sectionsForPage($page, true));
            ++$counts['pages'];
            $counts['sections'] += $after;
        }
        return $counts;
    }

    /** Visible sections only for rendering. Calling this initialises a never-configured page once. */
    public function sectionsForPage(string $page, bool $includeHidden = false): array
    {
        $this->assertPage($page);
        if (!$this->isInitialised($page)) {
            $this->initialisePage($page);
        }
        $pageId = $this->sections->pageId($page);
        if ($pageId === null) {
            return [];
        }
        $rows = $this->sections->sections($pageId, $includeHidden);
        $result = [];
        foreach ($rows as $row) {
            if (!SectionRegistry::isType($page, (string) $row['section_type'])) {
                continue;
            }
            if (!SectionRegistry::isSupportedRoute((string) ($row['link_path'] ?? ''))) {
                $row['link_path'] = null;
            }
            if (!$this->validDefinitionDestination((string) ($row['link_path'] ?? ''))) {
                $row['link_path'] = null;
            }
            if (!in_array($row['media_path'], SectionRegistry::mediaPaths(), true)) {
                $row['media_path'] = null;
            }
            $row['diagnostics'] = [];
            if ((int) $row['collection_unavailable'] === 1) {
                $row['diagnostics'][] = 'The linked collection is missing or archived.';
            }
            if ((int) $row['arrangement_unavailable'] === 1) {
                $row['diagnostics'][] = 'The linked arrangement is missing or archived.';
            }
            if (!$includeHidden && $row['diagnostics'] !== []) {
                continue;
            }
            unset($row['collection_unavailable'], $row['arrangement_unavailable']);
            $result[] = $row;
        }
        return $result;
    }

    /** Return true only for a page configured at least once, including intentionally empty pages. */
    public function isInitialised(string $page): bool
    {
        $this->assertPage($page);
        $pageId = $this->sections->pageId($page);
        return $pageId !== null && $this->sections->defaultsInitialized($pageId);
    }

    /** @return array<string,mixed> */
    public function save(string $page, string $sectionKey, array $input): array
    {
        $this->assertPage($page);
        $this->assertSectionKey($sectionKey);
        $this->initialisePage($page);
        $this->transaction(function () use ($page, $sectionKey, $input): void {
            $pageId = $this->lockExistingPage($page);
            $section = $this->sections->section($pageId, $sectionKey, false, true);
            if ($section === null) {
                throw new ValidationException(['section_key' => 'Choose an active section on this page.']);
            }
            $content = SectionRegistry::validateContent($page, (string) $section['section_type'], $input);
            $this->validateReference($content, 'collection_id', 'collection');
            $this->validateReference($content, 'arrangement_id', 'arrangement');
            $this->validateDefinitionDestination((string) ($content['link_path'] ?? ''));
            $this->sections->updateContent((int) $section['page_section_id'], $content);
            $this->markPolicyDraftChanged($page);
        });
        return $this->findSection($page, $sectionKey, true);
    }

    public function move(string $page, string $sectionKey, int $direction): void
    {
        $this->assertPage($page);
        $this->assertSectionKey($sectionKey);
        if (!in_array($direction, [-1, 1], true)) {
            throw new ValidationException(['direction' => 'Choose move up or move down.']);
        }
        $this->initialisePage($page);
        $this->transaction(function () use ($page, $sectionKey, $direction): void {
            $pageId = $this->lockExistingPage($page);
            $rows = $this->sections->orderedActive($pageId, true);
            $keys = array_column($rows, 'section_key');
            $index = array_search($sectionKey, $keys, true);
            if ($index === false) {
                throw new ValidationException(['section_key' => 'Choose an active section on this page.']);
            }
            $target = $index + $direction;
            if (!isset($rows[$target])) {
                return;
            }
            $moved = $rows[$index];
            array_splice($rows, $index, 1);
            array_splice($rows, $target, 0, [$moved]);
            foreach ($rows as $position => $row) {
                $this->sections->setPosition((int) $row['page_section_id'], ($position + 1) * 10);
            }
            $this->markPolicyDraftChanged($page);
        });
    }

    public function setVisible(string $page, string $sectionKey, bool $visible): void
    {
        $this->mutateSection($page, $sectionKey, fn (int $id) => $this->sections->setEnabled($id, $visible), false);
    }

    public function archive(string $page, string $sectionKey): void
    {
        $this->mutateSection($page, $sectionKey, fn (int $id) => $this->sections->setArchived($id, true), false);
    }

    public function restore(string $page, string $sectionKey): void
    {
        $this->mutateSection($page, $sectionKey, fn (int $id) => $this->sections->setArchived($id, false), true);
    }

    /** @return list<array{section_key:string,warnings:list<string>}> */
    public function diagnostics(string $page): array
    {
        $warnings = [];
        foreach ($this->sectionsForPage($page, true) as $section) {
            if ($section['diagnostics'] !== []) {
                $warnings[] = ['section_key' => (string) $section['section_key'], 'warnings' => $section['diagnostics']];
            }
        }
        return $warnings;
    }

    /** Active existing references only; the admin form never invents catalogue records. */
    public function referenceOptions(): array
    {
        return [
            'collections' => $this->sections->activeCollections(),
            'arrangements' => $this->sections->activeArrangements(),
            'media_paths' => SectionRegistry::mediaPaths(),
            'link_paths' => SectionRegistry::linkPaths(),
        ];
    }

    private function mutateSection(string $page, string $key, callable $operation, bool $includeArchived): void
    {
        $this->assertPage($page);
        $this->assertSectionKey($key);
        $this->initialisePage($page);
        $this->transaction(function () use ($page, $key, $operation, $includeArchived): void {
            $pageId = $this->lockExistingPage($page);
            $section = $this->sections->section($pageId, $key, $includeArchived, true);
            if ($section === null) {
                throw new ValidationException(['section_key' => 'Choose a section on this page.']);
            }
            if (!SectionRegistry::isType($page, (string) $section['section_type'])) {
                throw new ValidationException(['section_type' => 'This section type is no longer supported.']);
            }
            $operation((int) $section['page_section_id']);
            $this->markPolicyDraftChanged($page);
        });
    }

    private function ensurePolicyState(string $page): void
    {
        if (!InformationPageRegistry::isPolicy($page)) {
            return;
        }
        $statement = $this->connection->prepare(
            "INSERT IGNORE INTO information_policy_state (page_key, status, draft_revision) VALUES (?, 'draft', 1)",
        );
        try {
            $statement->execute([$page]);
        } finally {
            $statement->close();
        }
    }

    private function markPolicyDraftChanged(string $page): void
    {
        if (!InformationPageRegistry::isPolicy($page)) {
            return;
        }
        $statement = $this->connection->prepare(
            "UPDATE information_policy_state SET draft_revision = draft_revision + 1, status = 'draft' WHERE page_key = ?",
        );
        try {
            $statement->execute([$page]);
        } finally {
            $statement->close();
        }
    }

    private function findSection(string $page, string $key, bool $includeHidden): array
    {
        foreach ($this->sectionsForPage($page, $includeHidden) as $section) {
            if ($section['section_key'] === $key) {
                return $section;
            }
        }
        throw new ValidationException(['section_key' => 'Section could not be loaded after saving.']);
    }

    private function validateReference(array $content, string $field, string $kind): void
    {
        $id = $content[$field];
        if ($id !== null && !$this->sections->activeReferenceExists($kind, $id)) {
            throw new ValidationException([$field => 'Choose an active existing ' . $kind . ' or clear this reference.']);
        }
    }

    private function validateDefinitionDestination(string $path): void
    {
        if (preg_match('/^\/(?:compositions|configure)\?code=([a-z0-9][a-z0-9-]{0,79})$/D', $path, $match) === 1
            && !$this->validDefinitionCode($match[1])) {
            throw new ValidationException(['link_path' => 'Choose an active arrangement destination.']);
        }
    }

    private function validDefinitionDestination(string $path): bool
    {
        if (preg_match('/^\/(?:compositions|configure)\?code=([a-z0-9][a-z0-9-]{0,79})$/D', $path, $match) !== 1) {
            return true;
        }
        return $this->validDefinitionCode($match[1]);
    }

    private function validDefinitionCode(string $code): bool
    {
        $statement = $this->connection->prepare("SELECT definition_id FROM composition_definitions WHERE definition_code = ? AND status = 'active'");
        try {
            $statement->execute([$code]);
            return $statement->get_result()->num_rows === 1;
        } finally {
            $statement->close();
        }
    }

    private function lockExistingPage(string $page): int
    {
        $pageId = $this->sections->lockPage($page);
        if ($pageId === null) {
            throw new \RuntimeException('Storefront page initialisation was lost.');
        }
        return $pageId;
    }

    private function assertPage(string $page): void
    {
        if (!SectionRegistry::isPage($page)) {
            throw new ValidationException(['page' => 'Choose a supported storefront page.']);
        }
    }

    private function assertSectionKey(string $key): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $key) !== 1) {
            throw new ValidationException(['section_key' => 'Choose a valid section.']);
        }
    }

    private function transaction(callable $operation): mixed
    {
        for ($attempt = 0; ; ++$attempt) {
            $this->connection->begin_transaction();
            try {
                $result = $operation();
                $this->connection->commit();
                return $result;
            } catch (\Throwable $error) {
                try {
                    $this->connection->rollback();
                } catch (\Throwable) {
                }
                if ($attempt < 2 && $error instanceof DatabaseException && in_array($error->getCode(), [1205, 1213], true)) {
                    usleep(15000 * ($attempt + 1));
                    continue;
                }
                throw $error;
            }
        }
    }

}
