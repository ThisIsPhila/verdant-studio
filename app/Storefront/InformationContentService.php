<?php

declare(strict_types=1);

namespace Verdant\Storefront;

use DateTimeImmutable;
use mysqli;
use Verdant\Support\ValidationException;

/** Reads registered information pages and manages append-only policy publication snapshots. */
final class InformationContentService
{
    public function __construct(
        private readonly mysqli $connection,
        private readonly StorefrontSectionService $sections,
    ) {
    }

    /** @return array{definition:array<string,mixed>,sections:list<array<string,mixed>>,publication?:array<string,mixed>}|null */
    public function page(string $key, bool $public = true): ?array
    {
        $definition = InformationPageRegistry::pages()[$key] ?? null;
        if ($definition === null) {
            return null;
        }
        if (InformationPageRegistry::isPolicy($key) && $public) {
            $publication = $this->currentPublication($key);
            if ($publication === null) {
                // Fixed public baseline is separate from the editable, private draft.
                $baseline = array_values(array_filter(InformationPageContent::defaults(), static fn (array $row): bool => $row['page_key'] === $key));
                return ['definition' => $definition, 'sections' => $baseline,
                    'publication' => ['baseline' => true, 'version' => '1.0', 'effective_date' => '2026-10-22']];
            }
            return ['definition' => $definition, 'sections' => $publication['sections'], 'publication' => $publication['metadata']];
        }
        $rows = $this->sections->sectionsForPage($key);
        if ($public && isset($definition['article_slug'])
            && !array_filter($rows, static fn (array $row): bool => $row['section_key'] === 'hero')) {
            return null;
        }
        return ['definition' => $definition, 'sections' => $rows];
    }

    /** @return array<string,array<string,mixed>> */
    public function adminPages(): array
    {
        $pages = [];
        foreach (InformationPageRegistry::pages() as $key => $definition) {
            $pages[$key] = $definition + ['policy' => InformationPageRegistry::isPolicy($key)];
        }
        return $pages;
    }

    /** @return list<string> Policies with an explicitly published snapshot; draft edits do not hide that snapshot. */
    public function publishedPolicyKeys(): array
    {
        $result = $this->connection->query(
            'SELECT page_key FROM information_policy_state WHERE published_revision IS NOT NULL ORDER BY page_key',
        );
        return array_map(static fn (array $row): string => (string) $row['page_key'], $result->fetch_all(MYSQLI_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function policyState(string $key): ?array
    {
        if (!InformationPageRegistry::isPolicy($key)) {
            return null;
        }
        $this->sections->sectionsForPage($key, true);
        $statement = $this->connection->prepare(
            'SELECT s.page_key, s.status, s.draft_revision, s.published_revision,
                    p.version AS published_version, p.effective_date, p.published_at
             FROM information_policy_state s
             LEFT JOIN information_policy_publications p
               ON p.page_key = s.page_key AND p.draft_revision = s.published_revision
             WHERE s.page_key = ?',
        );
        try {
            $statement->execute([$key]);
            $row = $statement->get_result()->fetch_assoc();
        } finally {
            $statement->close();
        }
        if (!is_array($row)) {
            return null;
        }
        $row['draft_revision'] = (int) $row['draft_revision'];
        $row['published_revision'] = $row['published_revision'] === null ? null : (int) $row['published_revision'];
        $row['has_draft_changes'] = $row['published_revision'] === null
            || $row['draft_revision'] > $row['published_revision'];
        return $row;
    }

    /** @return array<string,mixed> */
    public function publishPolicy(string $key, array $input, int|string $actorId): array
    {
        if (!InformationPageRegistry::isPolicy($key)) {
            throw new ValidationException(['page' => 'Choose a registered policy page.']);
        }
        $version = $input['version'] ?? null;
        if (!is_string($version) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D', $version) !== 1) {
            throw new ValidationException(['version' => 'Enter a version using up to 32 letters, numbers, dots, underscores or hyphens.']);
        }
        $effectiveDate = $this->dateValue($input['effective_date'] ?? null);
        if (($input['confirm_publish'] ?? null) !== '1') {
            throw new ValidationException(['confirm_publish' => 'Confirm that this policy revision is ready to publish.']);
        }
        $revisionInput = $input['revision'] ?? null;
        if ((!is_string($revisionInput) && !is_int($revisionInput)) || preg_match('/^[1-9][0-9]{0,9}$/D', (string) $revisionInput) !== 1) {
            throw new ValidationException(['revision' => 'Refresh the policy draft before publishing.']);
        }
        $expectedRevision = (int) $revisionInput;

        // Initialise the registered page and its seeded defaults before taking the publication lock.
        $this->sections->sectionsForPage($key, true);
        return $this->transaction(function () use ($key, $version, $effectiveDate, $expectedRevision, $actorId): array {
            $pageLock = $this->connection->prepare('SELECT storefront_page_id FROM storefront_pages WHERE page_key = ? FOR UPDATE');
            try {
                $pageLock->execute([$key]);
                if ($pageLock->get_result()->fetch_assoc() === null) {
                    throw new \RuntimeException('The registered policy page is not initialised.');
                }
            } finally {
                $pageLock->close();
            }
            $state = $this->lockedPolicyState($key);
            if ($state === null || (int) $state['draft_revision'] !== $expectedRevision) {
                throw new ValidationException(['revision' => 'The policy draft changed. Review the current revision and try again.']);
            }
            if ($state['published_revision'] !== null && (int) $state['published_revision'] === $expectedRevision) {
                throw new ValidationException(['revision' => 'There are no unpublished policy changes to publish.']);
            }
            $existingVersion = $this->connection->prepare('SELECT publication_id FROM information_policy_publications WHERE page_key = ? AND version = ?');
            try {
                $existingVersion->execute([$key, $version]);
                if ($existingVersion->get_result()->num_rows !== 0) {
                    throw new ValidationException(['version' => 'That policy version already exists. Choose a new version.']);
                }
            } finally {
                $existingVersion->close();
            }
            $rows = $this->publicSectionRows($key, $this->sections->sectionsForPage($key, true));
            if ($rows === []) {
                throw new ValidationException(['revision' => 'Add visible policy sections before publishing.']);
            }
            $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $actor = (string) $actorId;
            if (strlen($actor) > 80 || preg_match('/^[A-Za-z0-9._:@-]*$/D', $actor) !== 1) {
                $actor = '';
            }
            $insert = $this->connection->prepare(
                'INSERT INTO information_policy_publications
                    (page_key, version, effective_date, draft_revision, sections_json, published_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
            );
            try {
                $insert->execute([$key, $version, $effectiveDate, $expectedRevision, $json, $actor === '' ? null : $actor]);
            } finally {
                $insert->close();
            }
            $update = $this->connection->prepare(
                "UPDATE information_policy_state SET status = 'published', published_revision = ?, updated_at = UTC_TIMESTAMP() WHERE page_key = ?",
            );
            try {
                $update->execute([$expectedRevision, $key]);
            } finally {
                $update->close();
            }
            return [
                'page_key' => $key, 'version' => $version, 'effective_date' => $effectiveDate,
                'draft_revision' => $expectedRevision, 'sections' => $rows,
            ];
        });
    }

    /** @return list<array<string,mixed>> */
    public function policyHistory(string $key): array
    {
        if (!InformationPageRegistry::isPolicy($key)) {
            throw new ValidationException(['page' => 'Choose a registered policy page.']);
        }
        $statement = $this->connection->prepare(
            'SELECT publication_id, page_key, version, effective_date, draft_revision, published_by, published_at
             FROM information_policy_publications WHERE page_key = ?
             ORDER BY published_at DESC, publication_id DESC',
        );
        try {
            $statement->execute([$key]);
            return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }

    /** @return array{sections:list<array<string,mixed>>,metadata:array<string,mixed>}|null */
    private function currentPublication(string $key): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT p.version, p.effective_date, p.draft_revision, p.published_by, p.published_at, p.sections_json
             FROM information_policy_state s
             JOIN information_policy_publications p
               ON p.page_key = s.page_key AND p.draft_revision = s.published_revision
             WHERE s.page_key = ?',
        );
        try {
            $statement->execute([$key]);
            $row = $statement->get_result()->fetch_assoc();
        } finally {
            $statement->close();
        }
        if (!is_array($row)) {
            return null;
        }
        try {
            $sections = json_decode((string) $row['sections_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($sections) || !array_is_list($sections)) {
            return null;
        }
        unset($row['sections_json']);
        $row['draft_revision'] = (int) $row['draft_revision'];
        return ['sections' => $sections, 'metadata' => $row];
    }

    private function lockedPolicyState(string $key): ?array
    {
        $statement = $this->connection->prepare('SELECT status, draft_revision, published_revision FROM information_policy_state WHERE page_key = ? FOR UPDATE');
        try {
            $statement->execute([$key]);
            $row = $statement->get_result()->fetch_assoc();
            return is_array($row) ? $row : null;
        } finally {
            $statement->close();
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function publicSectionRows(string $page, array $rows): array
    {
        $visible = array_filter($rows, static fn (array $row): bool => $row['archived_at'] === null && (int) $row['is_enabled'] === 1);
        $result = [];
        foreach ($visible as $row) {
            $content = SectionRegistry::validateContent($page, (string) $row['section_type'], $row);
            $result[] = array_intersect_key(array_merge($row, $content), array_flip([
                'page_key', 'section_key', 'section_type', 'position', 'heading', 'body', 'media_path', 'media_alt', 'link_label', 'link_path',
            ]));
        }
        return $result;
    }

    private function dateValue(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new ValidationException(['effective_date' => 'Choose a valid effective date.']);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new ValidationException(['effective_date' => 'Choose a valid effective date.']);
        }
        return $value;
    }

    private function transaction(callable $operation): mixed
    {
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
            throw $error;
        }
    }
}
