<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Admin\CatalogueAdminService;
use Verdant\Admin\VariantAdminService;
use Verdant\Persistence\DatabaseException;
use Verdant\Support\ValidationException;

final class AdminCatalogueController
{
    public function __construct(
        private readonly CatalogueAdminService $catalogue,
        private readonly ?VariantAdminService $variants = null,
    ) {
    }

    public function products(array $query, array $actor): void
    {
        try {
            $data = $this->catalogue->products($actor, $query['page'] ?? '1');
            AdminView::render('admin/catalogue', $data + [
                'title' => 'Manage products', 'actor' => $actor, 'errors' => [], 'notice' => $query['saved'] ?? null,
            ]);
        } catch (ValidationException $error) {
            $data = $this->catalogue->products($actor, 1);
            AdminView::render('admin/catalogue', $data + [
                'title' => 'Manage products', 'actor' => $actor, 'errors' => $error->errors, 'notice' => null,
            ], 422);
        }
    }

    public function inventory(array $query, array $actor): void
    {
        try {
            $data = $this->catalogue->products($actor, $query['page'] ?? '1');
            AdminView::render('admin/inventory', $data + [
                'title' => 'Inventory', 'actor' => $actor, 'errors' => [],
            ]);
        } catch (ValidationException $error) {
            $data = $this->catalogue->products($actor, 1);
            AdminView::render('admin/inventory', $data + [
                'title' => 'Inventory', 'actor' => $actor, 'errors' => $error->errors,
            ], 422);
        }
    }

    public function productForm(array $query, array $actor): void
    {
        $id = $query['id'] ?? null;
        $errors = [];
        $status = 200;
        $product = null;
        if ($id !== null) {
            try {
                $product = $this->catalogue->product($actor, $id);
            } catch (ValidationException $error) {
                $errors = $error->errors;
                $status = 422;
            }
            if ($product === null && $status === 200) {
                AdminView::render('error', [
                    'title' => 'Product unavailable', 'message' => 'This product could not be found.', 'actor' => $actor,
                ], 404);
                return;
            }
        } else {
            // Still cross the service boundary for the active role check.
            $this->catalogue->products($actor, 1);
        }
        AdminView::render('admin/product-form', [
            'title' => $product === null ? 'Add product' : 'Edit product', 'actor' => $actor,
            'product' => $product, 'values' => $product ?? [], 'errors' => $errors,
            'categories' => $this->catalogue->activeCategories($actor),
            'families' => $this->variants?->familiesForProduct(
                $actor, ($product['family_id'] ?? null) === null ? null : (int) $product['family_id'],
            ) ?? [],
        ], $status);
    }

    public function saveProduct(array $input, array $actor): void
    {
        $id = null;
        try {
            $id = $this->optionalId($input['product_id'] ?? null, 'product_id');
            $this->catalogue->saveProduct($actor, $id, $input);
            $this->redirect('/admin/products?saved=1');
        } catch (ValidationException|DatabaseException $error) {
            $errors = $error instanceof ValidationException
                ? $error->errors
                : ($error->getCode() === 1062 ? ['sku' => 'That SKU is already in use.'] : null);
            if ($errors === null) {
                throw $error;
            }
            $data = [
                'title' => $id === null ? 'Add product' : 'Edit product', 'actor' => $actor,
                'product' => null, 'values' => $input, 'errors' => $errors,
                'categories' => $this->catalogue->activeCategories($actor),
                'families' => $this->variants?->activeFamilies($actor) ?? [],
            ];
            if ($id !== null) {
                $data['product'] = $this->catalogue->product($actor, $id);
            }
            $data['families'] = $this->variants?->familiesForProduct(
                $actor, ($data['product']['family_id'] ?? null) === null ? null : (int) $data['product']['family_id'],
            ) ?? [];
            AdminView::render('admin/product-form', $data, 422);
        }
    }

    public function productArchive(array $input, array $actor): void
    {
        try {
            $id = $this->requiredId($input['product_id'] ?? null, 'product_id');
            $archived = $this->archiveFlag($input['archive'] ?? null);
            $this->catalogue->setProductArchived($actor, $id, $archived);
            $this->redirect('/admin/products?saved=1');
        } catch (ValidationException $error) {
            $data = $this->catalogue->products($actor, 1);
            AdminView::render('admin/catalogue', $data + ['title' => 'Manage products', 'actor' => $actor, 'errors' => $error->errors, 'notice' => null], 422);
        }
    }

    public function categories(array $query, array $actor): void
    {
        try {
            $data = $this->catalogue->categories($actor, $query['page'] ?? '1');
            $values = [];
            if (isset($query['edit'])) {
                $values = $this->catalogue->category($actor, $query['edit']) ?? [];
            }
            AdminView::render('admin/categories', $data + [
                'title' => 'Manage categories', 'actor' => $actor, 'errors' => [], 'values' => $values,
                'notice' => $query['saved'] ?? null,
            ]);
        } catch (ValidationException $error) {
            $data = $this->catalogue->categories($actor, 1);
            AdminView::render('admin/categories', $data + [
                'title' => 'Manage categories', 'actor' => $actor, 'errors' => $error->errors, 'values' => [], 'notice' => null,
            ], 422);
        }
    }

    public function saveCategory(array $input, array $actor): void
    {
        $id = null;
        try {
            $id = $this->optionalId($input['category_id'] ?? null, 'category_id');
            $this->catalogue->saveCategory($actor, $id, $input['name'] ?? null);
            $this->redirect('/admin/categories?saved=1');
        } catch (ValidationException|DatabaseException $error) {
            $errors = $error instanceof ValidationException
                ? $error->errors
                : ($error->getCode() === 1062 ? ['name' => 'That category name is already in use.'] : null);
            if ($errors === null) {
                throw $error;
            }
            $data = $this->catalogue->categories($actor, 1);
            AdminView::render('admin/categories', $data + [
                'title' => 'Manage categories', 'actor' => $actor, 'errors' => $errors,
                'values' => $input, 'notice' => null,
            ], 422);
        }
    }

    public function categoryArchive(array $input, array $actor): void
    {
        try {
            $id = $this->requiredId($input['category_id'] ?? null, 'category_id');
            $archived = $this->archiveFlag($input['archive'] ?? null);
            $this->catalogue->setCategoryArchived($actor, $id, $archived);
            $this->redirect('/admin/categories?saved=1');
        } catch (ValidationException $error) {
            $data = $this->catalogue->categories($actor, 1);
            AdminView::render('admin/categories', $data + ['title' => 'Manage categories', 'actor' => $actor, 'errors' => $error->errors, 'values' => [], 'notice' => null], 422);
        }
    }

    private function optionalId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->requiredId($value, $field);
    }

    private function requiredId(mixed $value, string $field): int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1
            || (float) $value > PHP_INT_MAX) {
            throw new ValidationException([$field => 'Choose a valid record.']);
        }
        return (int) $value;
    }

    private function archiveFlag(mixed $value): bool
    {
        if ($value !== '0' && $value !== '1') {
            throw new ValidationException(['archive' => 'Choose a valid archive action.']);
        }
        return $value === '1';
    }

    private function redirect(string $location): void
    {
        header('Location: ' . Url::redirectTarget($location), true, 303);
    }
}
