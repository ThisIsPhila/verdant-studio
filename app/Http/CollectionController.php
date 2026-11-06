<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Catalogue\CollectionService;
use Verdant\Storefront\StorefrontSectionService;
use Verdant\Support\ValidationException;

final class CollectionController
{
    public function __construct(
        private readonly CollectionService $collections,
        private readonly ?StorefrontSectionService $sections = null,
    ) {
    }

    public function index(array $query, ?array $actor = null): void
    {
        $this->render($query, null, $actor);
    }

    public function detail(string $code, array $query = [], ?array $actor = null): void
    {
        $this->render($query, $code, $actor);
    }

    private function render(array $query, ?string $code, ?array $actor): void
    {
        if ($code !== null && preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $code) !== 1) {
            View::render('error', [
                'title' => 'Collection unavailable', 'message' => 'This collection is unavailable. Explore other botanical collections.',
                'actor' => $actor,
            ], 404);
            return;
        }
        $errors = [];
        $status = 200;
        try {
            $data = $this->collections->browse($query, $code);
        } catch (ValidationException $error) {
            $errors = $error->errors;
            $status = 422;
            $data = $this->collections->browse([], $code);
        }
        if ($code !== null && $data['collection'] === null) {
            View::render('error', [
                'title' => 'Collection unavailable', 'message' => 'This collection is unavailable. Explore other botanical collections.',
                'actor' => $actor,
            ], 404);
            return;
        }
        $viewData = $data + [
            'title' => $data['collection']['name'] ?? 'Botanical collections',
            'actor' => $actor, 'errors' => $errors,
        ];
        if ($code === 'homes' && $this->sections !== null) {
            $viewData['pageSections'] = $this->sections->sectionsForPage('homes');
        }
        View::render('collections', $viewData, $status);
    }
}
