<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Storefront\ArrangementDiscoveryService;
use Verdant\Storefront\StorefrontSectionService;
use Verdant\Support\ValidationException;

final class ArrangementController
{
    public function __construct(
        private readonly ArrangementDiscoveryService $arrangements,
        private readonly ?StorefrontSectionService $sections = null,
    ) {
    }

    public function index(array $query, ?array $actor = null): void
    {
        $errors = [];
        $status = 200;
        try {
            $data = $this->arrangements->browse($query);
        } catch (ValidationException $error) {
            $errors = $error->errors;
            $status = 422;
            $data = $this->arrangements->browse([]);
        }
        $viewData = $data + [
            'title' => 'Plant arrangements',
            'actor' => $actor,
            'errors' => $errors,
        ];
        if ($this->sections !== null) {
            $viewData['pageSections'] = $this->sections->sectionsForPage('arrangements');
        }
        View::render('arrangements', $viewData, $status);
    }
}
