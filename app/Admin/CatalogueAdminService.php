<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Inventory\CapacityRepository;
use Verdant\Inventory\InventoryRepository;
use Verdant\Persistence\TransactionManager;
use Verdant\Support\Format;
use Verdant\Support\ValidationException;

final class CatalogueAdminService
{
    private const PAGE_SIZE = 20;
    private const MAX_QUANTITY = 1_000_000;
    private const PRODUCT_KINDS = ['plant', 'cut_flower', 'cut_foliage', 'accessory', 'plant_arrangement', 'floral_arrangement', 'other'];
    private const LIGHT_LEVELS = ['unknown', 'bright_indirect', 'indirect', 'full_sun', 'low_light'];
    private const WATERING_LEVELS = ['unknown', 'weekly', 'keep_moist', 'regular', 'sparingly'];
    private const TOXICITY_LEVELS = ['unknown', 'toxic_to_pets', 'non_toxic'];

    public function __construct(
        private readonly CatalogueAdminRepository $repository,
        private readonly InventoryRepository $inventory,
        private readonly CapacityRepository $capacity,
        private readonly TransactionManager $transactions,
        private readonly AdminAccess $access,
    ) {
    }

    public function products(array $actor, mixed $page = 1): array
    {
        $this->access->require($actor);
        $page = $this->pageNumber($page);
        $rows = $this->repository->products(self::PAGE_SIZE + 1, ($page - 1) * self::PAGE_SIZE);
        return $this->pagination($rows, $page);
    }

    public function product(array $actor, mixed $id): ?array
    {
        $this->access->require($actor);
        $productId = $this->positiveId($id, 'product_id');
        return $this->repository->product($productId);
    }

    public function categories(array $actor, mixed $page = 1): array
    {
        $this->access->require($actor);
        $page = $this->pageNumber($page);
        $rows = $this->repository->categories(self::PAGE_SIZE + 1, ($page - 1) * self::PAGE_SIZE);
        return $this->pagination($rows, $page);
    }

    public function activeCategories(array $actor): array
    {
        $this->access->require($actor);
        return $this->repository->activeCategories();
    }

    public function category(array $actor, mixed $id): ?array
    {
        $this->access->require($actor);
        $categoryId = $this->positiveId($id, 'category_id');
        return $this->repository->category($categoryId);
    }

    public function saveProduct(array $actor, ?int $id, array $input): int
    {
        $product = $this->validateProduct($input);
        $onHand = $this->quantity($input['on_hand'] ?? null, 'on_hand');
        $expectedVersion = null;
        if ($id !== null) {
            $id = $this->positiveId($id, 'product_id');
            $expectedVersion = $this->quantity($input['version'] ?? null, 'version', PHP_INT_MAX);
        }

        $this->access->require($actor);
        return $this->transactions->run(function () use ($actor, $id, $product, $onHand, $expectedVersion): int {
            $this->access->require($actor, true);
            if ($id === null) {
                if (!$this->repository->activeCategoryExists($product['category_id'])) {
                    throw new ValidationException(['category_id' => 'Choose an active category.']);
                }
                $this->validateProductFamily($product, true);
                $productId = $this->repository->insertProduct($product);
                $this->repository->insertInventory($productId, $onHand);
                return $productId;
            }

            // This is the first database read in the mutation transaction. Capacity reads
            // happen only after this current locking read to serialize stock changes.
            $inventoryRows = $this->inventory->lockProducts([$id]);
            $stock = $inventoryRows[0];
            if ((int) $stock['version'] !== $expectedVersion) {
                throw new ValidationException(['on_hand' => 'Stock changed since this form was opened. Reload and try again.']);
            }
            $current = $this->repository->product($id, true);
            if ($current === null) {
                throw new ValidationException(['product_id' => 'Product not found.']);
            }
            if (!$product['family_assignment_submitted']) {
                $product['family_id'] = $current['family_id'] === null ? null : (int) $current['family_id'];
                $product['variant_label'] = $current['variant_label'];
                $product['accessory_subtype'] = $current['accessory_subtype'];
            }
            $this->validateProductFamily($product, true, $current);
            if (!$this->repository->activeCategoryExists($product['category_id'])) {
                throw new ValidationException(['category_id' => 'Choose an active category.']);
            }
            if ($onHand < (int) $stock['on_hand'] - $this->capacity->retailAvailable($id)) {
                throw new ValidationException(['on_hand' => 'Stock cannot be reduced below current retail and rental commitments.']);
            }
            $this->repository->updateProduct($id, $product);
            if (!$this->repository->updateInventory($id, $onHand, $expectedVersion)) {
                throw new ValidationException(['on_hand' => 'Stock changed since this form was opened. Reload and try again.']);
            }
            return $id;
        });
    }

    public function setProductArchived(array $actor, mixed $id, bool $archived): void
    {
        $productId = $this->positiveId($id, 'product_id');
        $this->access->require($actor);
        $this->transactions->run(function () use ($actor, $productId, $archived): void {
            $this->access->require($actor, true);
            $this->inventory->lockProducts([$productId]);
            $product = $this->repository->product($productId, true);
            if ($product === null) {
                throw new ValidationException(['product_id' => 'Product not found.']);
            }
            if (!$archived && !$this->repository->activeCategoryExists((int) $product['category_id'])) {
                throw new ValidationException(['product_id' => 'Restore its category before restoring this product.']);
            }
            if (!$archived && $product['family_id'] !== null) {
                $family = $this->repository->familyMetadata((int) $product['family_id'], true);
                if ($family === null || $family['archived_at'] !== null
                    || $family['product_kind'] !== $product['product_kind']) {
                    throw new ValidationException(['product_id' => 'Restore a matching active product family before restoring this SKU.']);
                }
            }
            $this->repository->setProductArchived($productId, $archived);
        });
    }

    public function saveCategory(array $actor, ?int $id, mixed $name): int
    {
        $name = $this->categoryName($name);
        if ($id !== null) {
            $id = $this->positiveId($id, 'category_id');
        }
        $this->access->require($actor);
        return $this->transactions->run(function () use ($actor, $id, $name): int {
            $this->access->require($actor, true);
            if ($id === null) {
                return $this->repository->insertCategory($name);
            }
            if ($this->repository->category($id) === null) {
                throw new ValidationException(['category_id' => 'Category not found.']);
            }
            $this->repository->updateCategory($id, $name);
            return $id;
        });
    }

    public function setCategoryArchived(array $actor, mixed $id, bool $archived): void
    {
        $categoryId = $this->positiveId($id, 'category_id');
        $this->access->require($actor);
        $this->transactions->run(function () use ($actor, $categoryId, $archived): void {
            $this->access->require($actor, true);
            $category = $this->repository->category($categoryId, true);
            if ($category === null) {
                throw new ValidationException(['category_id' => 'Category not found.']);
            }
            if ($archived) {
                if ($this->repository->hasActiveProducts($categoryId)) {
                    throw new ValidationException(['category_id' => 'Move or archive active products before archiving this category.']);
                }
            }
            $this->repository->setCategoryArchived($categoryId, $archived);
        });
    }

    private function validateProduct(array $input): array
    {
        $errors = [];
        $text = static function (mixed $value, string $field, int $max, array &$errors, bool $required = true): ?string {
            if (!is_string($value)) {
                $errors[$field] = 'Enter valid text.';
                return null;
            }
            $value = trim($value);
            $characters = preg_match_all('/./us', $value);
            if ($characters === false || ($required && $value === '') || $characters > $max) {
                $errors[$field] = $required ? 'Enter a value within the allowed length.' : 'Value is too long.';
                return null;
            }
            return $value;
        };
        $categoryId = $this->positiveId($input['category_id'] ?? null, 'category_id', $errors);
        $sku = $text($input['sku'] ?? null, 'sku', 64, $errors);
        $name = $text($input['name'] ?? null, 'name', 180, $errors);
        $description = $text($input['description'] ?? null, 'description', 10_000, $errors);
        $kindValue = $input['product_kind'] ?? 'other';
        $productKind = $text($kindValue, 'product_kind', 32, $errors);
        if ($productKind !== null && !in_array($productKind, self::PRODUCT_KINDS, true)) {
            $errors['product_kind'] = 'Choose a listed product kind.';
        }
        $sellingUnit = $text($input['selling_unit'] ?? '', 'selling_unit', 80, $errors, false);
        $sizeLabel = $text($input['size_label'] ?? '', 'size_label', 120, $errors, false);
        $colourLabel = $text($input['colour_label'] ?? '', 'colour_label', 80, $errors, false);
        $light = $text($input['light_level'] ?? null, 'light_level', 40, $errors);
        $watering = $text($input['watering_level'] ?? null, 'watering_level', 40, $errors);
        $toxicity = $text($input['toxicity'] ?? null, 'toxicity', 40, $errors);
        $imageValue = $input['image_path'] ?? '';
        $image = $text($imageValue, 'image_path', 255, $errors, false);
        if ($sku !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $sku) !== 1) {
            $errors['sku'] = 'Use letters, numbers, dots, underscores or hyphens for the SKU.';
        }
        foreach ([['light_level', $light, self::LIGHT_LEVELS], ['watering_level', $watering, self::WATERING_LEVELS], ['toxicity', $toxicity, self::TOXICITY_LEVELS]] as [$key, $value, $allowed]) {
            if ($value !== null && !in_array($value, $allowed, true)) {
                $errors[$key] = 'Choose a listed care value.';
            }
        }
        if ($productKind !== null && !in_array($productKind, ['plant', 'other'], true)) {
            $light = $watering = $toxicity = 'unknown';
        }
        $buy = $this->flag($input['buy_enabled'] ?? null, 'buy_enabled', $errors);
        $rent = $this->flag($input['rent_enabled'] ?? null, 'rent_enabled', $errors);
        $retail = $this->moneyMinor($input['retail_price'] ?? null, 'retail_price', $errors, $buy);
        $rental = $this->moneyMinor($input['rental_daily_rate'] ?? null, 'rental_daily_rate', $errors, $rent);
        $imagePath = $image === '' ? null : $image;
        if ($imagePath !== null && (Format::imagePath($imagePath) !== $imagePath || !is_file(dirname(__DIR__, 2) . '/public' . $imagePath))) {
            $errors['image_path'] = 'Choose an existing local catalogue image or leave this blank.';
        }
        $imageAlt = $text($input['image_alt'] ?? '', 'image_alt', 180, $errors, false);
        if ($imagePath === null) {
            $imageAlt = null;
        }
        if ($buy === false && $rent === false) {
            $errors['buy_enabled'] = 'Enable retail or rental availability.';
        }
        if (in_array($productKind, ['cut_flower', 'cut_foliage', 'floral_arrangement'], true) && $rent) {
            $errors['rent_enabled'] = 'Cut flowers, cut foliage and floral arrangements are sale-only.';
        }
        $familyAssignmentSubmitted = array_key_exists('family_id', $input)
            || array_key_exists('variant_label', $input) || array_key_exists('accessory_subtype', $input);
        $familyValue = $input['family_id'] ?? '';
        $familyId = $familyValue === '' || $familyValue === null
            ? null : $this->positiveId($familyValue, 'family_id', $errors);
        $variantLabel = $text($input['variant_label'] ?? '', 'variant_label', 120, $errors, false);
        $accessorySubtype = $text($input['accessory_subtype'] ?? 'other', 'accessory_subtype', 16, $errors);
        if ($accessorySubtype !== null && !in_array($accessorySubtype, ['pot', 'tool', 'care', 'other'], true)) {
            $errors['accessory_subtype'] = 'Choose a listed accessory type.';
        }
        if ($productKind !== 'accessory' && $accessorySubtype !== 'other') {
            $errors['accessory_subtype'] = 'Only accessory products can use an accessory type.';
        }
        if (($familyId === null) !== ($variantLabel === null || $variantLabel === '')) {
            $errors['variant_label'] = 'Choose a family and enter its variant label together.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'category_id' => $categoryId, 'sku' => $sku, 'name' => $name, 'description' => $description,
            'product_kind' => $productKind, 'selling_unit' => $sellingUnit ?: null,
            'family_id' => $familyId, 'variant_label' => $variantLabel ?: null,
            'accessory_subtype' => $accessorySubtype, 'family_assignment_submitted' => $familyAssignmentSubmitted,
            'size_label' => $sizeLabel ?: null, 'colour_label' => $colourLabel ?: null,
            'light_level' => $light, 'watering_level' => $watering, 'toxicity' => $toxicity,
            'image_path' => $imagePath, 'image_alt' => $imageAlt ?: null,
            'buy_enabled' => (int) $buy, 'rent_enabled' => (int) $rent,
            'retail_price_minor' => $buy ? $retail : null, 'rental_daily_rate_minor' => $rent ? $rental : null,
        ];
    }

    private function validateProductFamily(array $product, bool $lock, ?array $current = null): void
    {
        if ($product['family_id'] === null) {
            return;
        }
        $family = $this->repository->familyMetadata((int) $product['family_id'], $lock);
        if ($family === null || $family['product_kind'] !== $product['product_kind']) {
            throw new ValidationException(['family_id' => 'Choose an active family with the same product kind.']);
        }
        if ($family['archived_at'] !== null) {
            $preservingArchivedAssignment = $current !== null && $current['archived_at'] !== null
                && (int) $current['family_id'] === (int) $product['family_id']
                && $current['variant_label'] === $product['variant_label']
                && $current['product_kind'] === $product['product_kind'];
            if ($preservingArchivedAssignment) {
                return;
            }
            throw new ValidationException(['family_id' => 'Choose an active family with the same product kind.']);
        }
    }

    private function moneyMinor(mixed $value, string $field, array &$errors, bool $enabled): ?int
    {
        if (!$enabled) {
            return null;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,2}))?$/D', $value) !== 1) {
            $errors[$field] = 'Enter a nonnegative amount with up to two decimal places.';
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $minorString = ltrim($whole . str_pad($fraction, 2, '0'), '0') ?: '0';
        if (strlen($minorString) > strlen((string) PHP_INT_MAX)
            || (strlen($minorString) === strlen((string) PHP_INT_MAX) && strcmp($minorString, (string) PHP_INT_MAX) > 0)) {
            $errors[$field] = 'Amount is too large.';
            return null;
        }
        return (int) $minorString;
    }

    private function flag(mixed $value, string $field, array &$errors): bool
    {
        if ($value === '1' || $value === 1) {
            return true;
        }
        if ($value === '0' || $value === 0) {
            return false;
        }
        $errors[$field] = 'Choose yes or no.';
        return false;
    }

    private function quantity(mixed $value, string $field, int $maximum = self::MAX_QUANTITY): int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^(0|[1-9][0-9]{0,18})$/D', (string) $value) !== 1
            || (float) $value > $maximum) {
            throw new ValidationException([$field => 'Enter a valid whole number.']);
        }
        return (int) $value;
    }

    private function positiveId(mixed $value, string $field, ?array &$errors = null): int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1
            || (float) $value > PHP_INT_MAX) {
            if ($errors !== null) {
                $errors[$field] = 'Choose a valid record.';
                return 0;
            }
            throw new ValidationException([$field => 'Choose a valid record.']);
        }
        return (int) $value;
    }

    private function pageNumber(mixed $page): int
    {
        if ((!is_string($page) && !is_int($page)) || preg_match('/^[1-9][0-9]{0,3}$/D', (string) $page) !== 1) {
            throw new ValidationException(['page' => 'Choose a valid page.']);
        }
        return (int) $page;
    }

    private function categoryName(mixed $name): string
    {
        if (!is_string($name)) {
            throw new ValidationException(['name' => 'Enter a category name.']);
        }
        $name = trim($name);
        $characters = preg_match_all('/./us', $name);
        if ($name === '' || $characters === false || $characters > 120) {
            throw new ValidationException(['name' => 'Enter a category name up to 120 characters.']);
        }
        return $name;
    }

    private function pagination(array $rows, int $page): array
    {
        $hasNext = count($rows) > self::PAGE_SIZE;
        if ($hasNext) {
            array_pop($rows);
        }
        return ['items' => $rows, 'page' => $page, 'has_next' => $hasNext];
    }
}
