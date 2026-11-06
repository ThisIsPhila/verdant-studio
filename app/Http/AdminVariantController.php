<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Admin\VariantAdminService;
use Verdant\Persistence\DatabaseException;
use Verdant\Support\ValidationException;

final class AdminVariantController
{
    public function __construct(private readonly VariantAdminService $variants)
    {
    }

    public function families(array $query, array $actor): void
    {
        $values = [];
        $errors = [];
        $status = 200;
        if (isset($query['edit'])) {
            try {
                $values = $this->variants->family($actor, $query['edit']) ?? [];
            } catch (ValidationException $error) {
                $errors = $error->errors;
                $status = 422;
            }
        }
        $this->renderFamilies($actor, $values, $errors, $status);
    }

    public function saveFamily(array $input, array $actor): void
    {
        try {
            $this->variants->saveFamily($actor, $input['family_id'] ?? null, $input);
            $this->redirect('/admin/families?saved=1');
        } catch (ValidationException|DatabaseException $error) {
            $errors = $error instanceof ValidationException
                ? $error->errors
                : ($error->getCode() === 1062 ? ['family_code' => 'That family code is already in use.'] : null);
            if ($errors === null) {
                throw $error;
            }
            $this->renderFamilies($actor, $input, $errors, 422);
        }
    }

    public function familyArchive(array $input, array $actor): void
    {
        try {
            $this->variants->setFamilyArchived($actor, $input['family_id'] ?? null, $this->archiveFlag($input['archive'] ?? null));
            $this->redirect('/admin/families?saved=1');
        } catch (ValidationException $error) {
            $this->renderFamilies($actor, [], $error->errors, 422);
        }
    }

    public function compatibilities(array $query, array $actor): void
    {
        $this->renderCompatibilities($actor, [], []);
    }

    public function saveCompatibility(array $input, array $actor): void
    {
        try {
            $this->variants->saveCompatibility(
                $actor, $input['plant_product_id'] ?? null, $input['pot_product_id'] ?? null,
                $input['source_status'] ?? null,
            );
            $this->redirect('/admin/compatibility?saved=1');
        } catch (ValidationException|DatabaseException $error) {
            $errors = $error instanceof ValidationException
                ? $error->errors
                : ($error->getCode() === 1062 ? ['pot_product_id' => 'That plant and pot pairing already exists.'] : null);
            if ($errors === null) {
                throw $error;
            }
            $this->renderCompatibilities($actor, $input, $errors);
        }
    }

    public function compatibilityArchive(array $input, array $actor): void
    {
        try {
            $this->variants->setCompatibilityArchived(
                $actor, $input['compatibility_id'] ?? null, $this->archiveFlag($input['archive'] ?? null),
            );
            $this->redirect('/admin/compatibility?saved=1');
        } catch (ValidationException $error) {
            $this->renderCompatibilities($actor, [], $error->errors);
        }
    }

    private function renderFamilies(array $actor, array $values, array $errors, int $status = 200): void
    {
        AdminView::render('admin/families', [
            'title' => 'Product families', 'actor' => $actor,
            'families' => $this->variants->families($actor), 'values' => $values, 'errors' => $errors,
        ], $status);
    }

    private function renderCompatibilities(array $actor, array $values, array $errors): void
    {
        $data = $this->variants->compatibilityPage($actor);
        AdminView::render('admin/compatibility', $data + [
            'title' => 'Item compatibility', 'actor' => $actor, 'values' => $values, 'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    private function archiveFlag(mixed $value): bool
    {
        if ($value === '1' || $value === 1) {
            return true;
        }
        if ($value === '0' || $value === 0) {
            return false;
        }
        throw new ValidationException(['archive' => 'Choose whether to archive or restore this record.']);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Url::redirectTarget($path), true, 303);
        exit;
    }
}
