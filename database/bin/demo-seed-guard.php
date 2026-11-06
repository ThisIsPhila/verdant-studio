<?php

declare(strict_types=1);

/** The policy migration's two untouched rows are schema defaults, not demo data. */
function demoSeedPolicyRowsAreInitial(mysqli $connection): bool
{
    $rows = $connection->query(
        'SELECT page_key, status, draft_revision, published_revision
         FROM information_policy_state ORDER BY page_key',
    )->fetch_all(MYSQLI_ASSOC);
    $actual = array_map(static fn (array $row): array => [
        'page_key' => (string) $row['page_key'],
        'status' => (string) $row['status'],
        'draft_revision' => (int) $row['draft_revision'],
        'published_revision' => $row['published_revision'] === null ? null : (int) $row['published_revision'],
    ], $rows);
    return $actual === [
        ['page_key' => 'privacy', 'status' => 'draft', 'draft_revision' => 1, 'published_revision' => null],
        ['page_key' => 'terms', 'status' => 'draft', 'draft_revision' => 1, 'published_revision' => null],
    ];
}
