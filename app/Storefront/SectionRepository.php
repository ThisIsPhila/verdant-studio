<?php

declare(strict_types=1);

namespace Verdant\Storefront;

use Verdant\Persistence\Repository;

final class SectionRepository extends Repository
{
    public function createPageIfMissing(string $page): void
    {
        $this->execute('INSERT IGNORE INTO storefront_pages(page_key) VALUES (?)', [$page]);
    }

    public function lockPage(string $page): ?int
    {
        $rows = $this->select('SELECT storefront_page_id FROM storefront_pages WHERE page_key = ? FOR UPDATE', [$page]);
        return $rows === [] ? null : (int) $rows[0]['storefront_page_id'];
    }

    public function defaultsInitialized(int $pageId): bool
    {
        $rows = $this->select('SELECT initialized_at FROM storefront_pages WHERE storefront_page_id = ?', [$pageId]);
        return $rows !== [] && $rows[0]['initialized_at'] !== null;
    }

    public function insertDefault(int $pageId, array $definition, bool $enabled = true): void
    {
        $this->execute(
            'INSERT IGNORE INTO page_sections
                (storefront_page_id, section_key, section_type, position, is_enabled, heading, body, media_path, media_alt, link_label, link_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $pageId, $definition['section_key'], $definition['section_type'], (int) $definition['position'], (int) $enabled,
                $definition['heading'], $definition['body'], $definition['media_path'], $definition['media_alt'],
                $definition['link_label'], $definition['link_path'],
            ],
        );
    }

    public function hasVisibleSections(int $pageId): bool
    {
        return $this->select(
            'SELECT page_section_id FROM page_sections
             WHERE storefront_page_id = ? AND is_enabled = 1 AND archived_at IS NULL LIMIT 1',
            [$pageId],
        ) !== [];
    }

    public function markInitialized(int $pageId): void
    {
        $this->execute('UPDATE storefront_pages SET initialized_at = COALESCE(initialized_at, UTC_TIMESTAMP()) WHERE storefront_page_id = ?', [$pageId]);
    }

    public function sections(int $pageId, bool $includeHidden): array
    {
        $sql = 'SELECT s.page_section_id, p.page_key, s.section_key, s.section_type, s.position, s.is_enabled,
                       s.heading, s.body, s.media_path, s.media_alt, s.link_label, s.link_path,
                       CASE WHEN c.collection_id IS NOT NULL AND c.state = \'active\' THEN s.collection_id ELSE NULL END AS collection_id,
                       CASE WHEN d.definition_id IS NOT NULL AND d.status = \'active\' THEN s.arrangement_id ELSE NULL END AS arrangement_id,
                       s.archived_at, s.created_at, s.updated_at,
                       CASE WHEN s.collection_id IS NOT NULL AND (c.collection_id IS NULL OR c.state <> \'active\') THEN 1 ELSE 0 END AS collection_unavailable,
                       CASE WHEN s.arrangement_id IS NOT NULL AND (d.definition_id IS NULL OR d.status <> \'active\') THEN 1 ELSE 0 END AS arrangement_unavailable
                FROM page_sections s
                JOIN storefront_pages p ON p.storefront_page_id = s.storefront_page_id
                LEFT JOIN collections c ON c.collection_id = s.collection_id
                LEFT JOIN composition_definitions d ON d.definition_id = s.arrangement_id
                WHERE s.storefront_page_id = ?';
        if (!$includeHidden) {
            $sql .= ' AND s.is_enabled = 1 AND s.archived_at IS NULL';
        }
        $sql .= ' ORDER BY s.position, s.page_section_id';
        return $this->select($sql, [$pageId]);
    }

    public function pageId(string $page): ?int
    {
        $rows = $this->select('SELECT storefront_page_id FROM storefront_pages WHERE page_key = ?', [$page]);
        return $rows === [] ? null : (int) $rows[0]['storefront_page_id'];
    }

    public function section(int $pageId, string $key, bool $includeArchived = false, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM page_sections WHERE storefront_page_id = ? AND section_key = ?';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $rows = $this->select($sql, [$pageId, $key]);
        return $rows[0] ?? null;
    }

    public function activeReferenceExists(string $kind, int $id): bool
    {
        if ($kind === 'collection') {
            return $this->select("SELECT collection_id FROM collections WHERE collection_id = ? AND state = 'active'", [$id]) !== [];
        }
        if ($kind === 'arrangement') {
            return $this->select("SELECT definition_id FROM composition_definitions WHERE definition_id = ? AND status = 'active'", [$id]) !== [];
        }
        return false;
    }

    /** @return list<array{collection_id:int,name:string}> */
    public function activeCollections(): array
    {
        return $this->select("SELECT collection_id, name FROM collections WHERE state = 'active' ORDER BY name, collection_id");
    }

    /** @return list<array{definition_id:int,definition_code:string,name:string}> */
    public function activeArrangements(): array
    {
        return $this->select("SELECT definition_id, definition_code, name FROM composition_definitions WHERE status = 'active' ORDER BY name, definition_id");
    }

    public function updateContent(int $sectionId, array $content): void
    {
        $this->execute(
            'UPDATE page_sections SET heading = ?, body = ?, media_path = ?, media_alt = ?, link_label = ?, link_path = ?,
                collection_id = ?, arrangement_id = ? WHERE page_section_id = ? AND archived_at IS NULL',
            [
                $content['heading'], $content['body'], $content['media_path'], $content['media_alt'],
                $content['link_label'], $content['link_path'], $content['collection_id'], $content['arrangement_id'], $sectionId,
            ],
        );
    }

    public function setEnabled(int $sectionId, bool $enabled): void
    {
        $this->execute('UPDATE page_sections SET is_enabled = ? WHERE page_section_id = ? AND archived_at IS NULL', [(int) $enabled, $sectionId]);
    }

    public function setArchived(int $sectionId, bool $archived): void
    {
        $sql = $archived
            ? 'UPDATE page_sections SET archived_at = UTC_TIMESTAMP() WHERE page_section_id = ? AND archived_at IS NULL'
            : 'UPDATE page_sections SET archived_at = NULL WHERE page_section_id = ? AND archived_at IS NOT NULL';
        $this->execute($sql, [$sectionId]);
    }

    /** @return list<array<string,mixed>> */
    public function orderedActive(int $pageId, bool $forUpdate = false): array
    {
        return $this->select(
            'SELECT page_section_id, section_key, position FROM page_sections
             WHERE storefront_page_id = ? AND archived_at IS NULL ORDER BY position, page_section_id' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$pageId],
        );
    }

    public function setPosition(int $sectionId, int $position): void
    {
        $this->execute('UPDATE page_sections SET position = ? WHERE page_section_id = ? AND archived_at IS NULL', [$position, $sectionId]);
    }
}
