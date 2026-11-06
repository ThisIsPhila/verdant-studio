<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Catalogue\CatalogueService;
use Verdant\Storefront\InformationContentService;
use Verdant\Storefront\InformationPageContent;
use Verdant\Storefront\InformationPageRegistry;

/** Prepares registered editorial content; no URL may select a template. */
final class InformationController
{
    public function __construct(
        private readonly InformationContentService $content,
        private readonly CatalogueService $catalogue,
    ) {
    }

    public function show(string $pageKey, ?array $actor, bool $preview = false): void
    {
        $page = $this->content->page($pageKey, !$preview);
        if ($page === null) {
            View::render('error', [
                'title' => 'Page unavailable', 'message' => 'This page is not available yet.',
                'actor' => $actor, 'visionPage' => true,
            ], 404);
            return;
        }
        $sections = [];
        foreach ($page['sections'] as $section) {
            $link = (string) ($section['link_path'] ?? '');
            if (!$preview && str_starts_with($link, '/journal/article?slug=')) {
                $articleKey = InformationPageRegistry::articleKey(substr($link, strlen('/journal/article?slug=')));
                if ($articleKey !== null && $this->content->page($articleKey) === null) {
                    continue;
                }
            }
            $sections[$section['section_key']] = $section;
        }
        $careProducts = $pageKey === 'plant-care'
            ? array_slice($this->catalogue->catalogue(['kind' => 'plant'])['products'], 0, 6)
            : [];
        View::render('information/page', [
            'title' => $page['definition']['label'], 'actor' => $actor,
            'visionPage' => true, 'visionComponentStyles' => ['information', 'information-help'],
            'pageKey' => $pageKey, 'definition' => $page['definition'],
            'presentation' => InformationPageContent::presentation($pageKey),
            'sections' => $sections, 'preview' => $preview,
            'careProducts' => $careProducts,
            'articleSlug' => $page['definition']['article_slug'] ?? null,
            'articlePublishedAt' => isset($page['definition']['article_slug']) ? ($sections['hero']['created_at'] ?? null) : null,
            'policyState' => isset($page['publication']) ? $page['publication'] + ['status' => 'published']
                : (($page['definition']['policy'] ?? false) ? $this->content->policyState($pageKey) : null),
        ]);
    }

    public static function resolve(string $path, array $query): ?string
    {
        foreach (InformationPageRegistry::pages() as $key => $definition) {
            if (isset($definition['article_slug'])) {
                if ($path === '/journal/article' && ($query['slug'] ?? null) === $definition['article_slug']) {
                    return $key;
                }
            } elseif ($path === $definition['route']) {
                return $key;
            }
        }
        return null;
    }
}
