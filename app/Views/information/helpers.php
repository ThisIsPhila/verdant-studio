<?php
declare(strict_types=1);

$pageKey = is_string($pageKey ?? null) ? $pageKey : '';
$definition = is_array($definition ?? null) ? $definition : [];
$presentation = is_array($presentation ?? null) ? $presentation : [];
$sections = is_array($sections ?? null) ? $sections : [];
$preview = !empty($preview);
$articleSlug = is_string($articleSlug ?? null) ? $articleSlug : '';
$policyState = is_array($policyState ?? null) ? $policyState : [];
$layout = (string) ($definition['layout'] ?? 'editorial');
$esc = static fn (mixed $value): string => $e((string) ($value ?? ''));
$localUrl = static function (mixed $value): ?string {
    if (!is_string($value) || $value === '' || str_starts_with($value, '//') || str_contains($value, '\\') || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
        return null;
    }
    if (preg_match('/^#[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $value) === 1) {
        return $value;
    }
    $path = parse_url($value, PHP_URL_PATH);
    return is_string($path) && str_starts_with($path, '/') && preg_match('~(?:^|/)\.\.(?:/|$)~', $path) !== 1 ? $value : null;
};
$asset = static function (mixed $value) use ($localUrl): ?string {
    $url = $localUrl($value);
    return $url !== null && str_starts_with($url, '/assets/') ? $url : null;
};
$paragraphs = static function (mixed $body) use ($esc): void {
    if (!is_string($body) || trim($body) === '') {
        return;
    }
    foreach (preg_split('/\R\s*\R/u', trim($body)) ?: [] as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }
        $lines = preg_split('/\R/u', $paragraph) ?: [$paragraph];
        $bulletLines = array_values(array_filter($lines, static fn (string $line): bool => preg_match('/^\s*[-•]\s+/', $line) === 1));
        if ($bulletLines !== [] && count($bulletLines) === count($lines)) {
            echo '<ul>';
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^[-•]\s+/', $line) !== 1) {
                    continue;
                }
                echo '<li>' . $esc(preg_replace('/^[-•]\s+/', '', $line)) . '</li>';
            }
            echo '</ul>';
        } elseif ($bulletLines !== []) {
            $heading = trim((string) array_shift($lines));
            if ($heading !== '') {
                echo '<h3>' . $esc($heading) . '</h3>';
            }
            echo '<ul>';
            foreach ($lines as $line) {
                if (preg_match('/^\s*[-•]\s+/', $line) === 1) {
                    echo '<li>' . $esc(preg_replace('/^\s*[-•]\s+/', '', $line)) . '</li>';
                }
            }
            echo '</ul>';
        } else {
            echo '<p>' . nl2br($esc($paragraph), false) . '</p>';
        }
    }
};
$rows = [];
foreach ($sections as $key => $row) {
    if (!is_array($row)) {
        continue;
    }
    $rowKey = is_string($row['section_key'] ?? null) ? $row['section_key'] : (is_string($key) ? $key : '');
    if ($rowKey === '') {
        continue;
    }
    $row['section_key'] = $rowKey;
    $row['body'] = str_replace('\\n', "\n", (string) ($row['body'] ?? ''));
    $rows[$rowKey] = $row;
}
$roles = is_array($presentation['roles'] ?? null) ? $presentation['roles'] : [];
uasort($rows, static fn (array $left, array $right): int => ((int) ($left['position'] ?? 0)) <=> ((int) ($right['position'] ?? 0)));
$rows = array_values($rows);
$rows = array_combine(array_map(static fn (array $row): string => (string) $row['section_key'], $rows), $rows) ?: [];
$roleOf = static fn (array $row): string => (string) ($roles[$row['section_key'] ?? ''] ?? $row['section_type'] ?? 'text');
$byRole = static function (array $wanted) use ($rows, $roleOf): array {
    return array_values(array_filter($rows, static fn (array $row): bool => in_array($roleOf($row), $wanted, true)));
};
$hero = null;
foreach ($rows as $row) {
    if ($roleOf($row) === 'hero' || ($row['section_type'] ?? '') === 'info_hero') {
        $hero = $row;
        break;
    }
}
$title = (string) ($hero['heading'] ?? $definition['label'] ?? 'Information');
$eyebrow = (string) ($presentation['eyebrow'] ?? $definition['label'] ?? 'Verdant');
$description = (string) ($hero['body'] ?? '');
$heroImage = $asset($presentation['hero_image'] ?? $hero['media_path'] ?? null);
$heroAlt = (string) ($presentation['hero_alt'] ?? $hero['media_alt'] ?? '');
$heroScript = $byRole(['script-line'])[0]['heading'] ?? '';
$sectionId = static function (array $row) use ($presentation): string {
    $key = (string) ($row['section_key'] ?? 'section');
    $anchor = $presentation['anchors'][$key] ?? null;
    if (is_string($anchor) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $anchor) === 1) {
        return $anchor;
    }
    return trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($key)), '-');
};
$renderContentsNav = static function (array $contentRows, string $label) use ($esc, $sectionId): void {
    ?>
    <aside class="info-contents">
        <nav class="info-contents-desktop" aria-label="<?= $esc($label) ?>">
            <p class="info-eyebrow"><?= $esc($label) ?></p>
            <?php foreach ($contentRows as $contentRow): ?>
                <a href="#<?= $esc($sectionId($contentRow)) ?>"><?= $esc($contentRow['heading'] ?? '') ?></a>
            <?php endforeach ?>
        </nav>
        <details class="info-contents-mobile">
            <summary><?= $esc($label) ?></summary>
            <nav aria-label="<?= $esc($label . ' — mobile') ?>">
                <?php foreach ($contentRows as $contentRow): ?>
                    <a href="#<?= $esc($sectionId($contentRow)) ?>"><?= $esc($contentRow['heading'] ?? '') ?></a>
                <?php endforeach ?>
            </nav>
        </details>
    </aside>
    <?php
};
$renderReadingSections = static function (array $contentRows) use ($esc, $paragraphs, $sectionId): void {
    ?>
    <?php foreach ($contentRows as $index => $contentRow): ?>
        <section class="info-reading-section" id="<?= $esc($sectionId($contentRow)) ?>">
            <span class="info-section-number" aria-hidden="true"><?= $esc(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
            <div>
                <h2><?= $esc($contentRow['heading'] ?? '') ?></h2>
                <?php $paragraphs($contentRow['body'] ?? '') ?>
            </div>
        </section>
    <?php endforeach ?>
    <?php
};
$isPolicy = in_array($pageKey, ['privacy', 'terms'], true) || !empty($definition['policy']);
$policyPublished = ($policyState['status'] ?? '') === 'published';
$rootClasses = ['info-page', 'info-page-' . $pageKey];
if (in_array($layout, ['policy', 'contact-help', 'information-help'], true)) {
    $rootClasses[] = 'info-help-page';
}
if ($layout === 'policy') {
    $rootClasses[] = 'info-policy-page';
}
if ($pageKey === 'returns') {
    $rootClasses[] = 'info-returns-page';
}

if ($isPolicy && !$preview && !$policyPublished) {
    return;
}
?>
