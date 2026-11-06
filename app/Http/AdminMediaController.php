<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Admin\AdminAccess;
use Verdant\Media\MediaAssetRegistry;
use Verdant\Media\MediaAssignmentRepository;
use Verdant\Media\MediaAssignmentService;
use Verdant\Support\Csrf;
use Verdant\Support\ValidationException;

/** Protected editor for the reviewed local media registry and reversible assignments. */
final class AdminMediaController
{
    public function __construct(
        private readonly MediaAssignmentRepository $assignments,
        private readonly MediaAssignmentService $service,
        private readonly MediaAssetRegistry $assets,
        private readonly AdminAccess $access,
    ) {
    }

    public function index(array $query, array $actor): void
    {
        $this->access->require($actor);
        $bindingId = self::id($query['binding_id'] ?? null);
        $selected = $bindingId === null ? null : $this->assignments->binding($bindingId);
        if ($bindingId !== null && $selected === null) {
            AdminView::render('error', [
                'title' => 'Media assignment unavailable', 'message' => 'This media assignment could not be found.', 'actor' => $actor,
            ], 404);
            return;
        }
        $values = $selected === null ? [] : $this->valuesFromBinding($selected);
        $this->render($actor, $values, [], $selected, $query['saved'] ?? null, $bindingId);
    }

    public function save(array $input, array $actor): void
    {
        if (!$this->postAllowed($input, $actor)) return;
        $values = self::safeValues($input);
        try {
            $version = self::optionalVersion($input['expected_version'] ?? null);
            $target = $input['target_type'] ?? null;
            if ($target === 'product') {
                $saved = $this->service->saveProductAssignment(
                    self::requiredId($input['product_id'] ?? null, 'product_id'),
                    self::string($input['media_kind'] ?? null),
                    self::string($input['asset_id'] ?? null),
                    self::optionalMillimetres($input['visible_height_mm'] ?? null, 'visible_height_mm'),
                    self::optionalMillimetres($input['pot_height_mm'] ?? null, 'pot_height_mm'),
                    self::string($input['measurement_status'] ?? null), $version, (int) $actor['customer_id'],
                );
            } elseif ($target === 'scene') {
                $saved = $this->service->saveSceneBackground(
                    self::string($input['scene_key'] ?? null), self::string($input['asset_id'] ?? null),
                    $version, (int) $actor['customer_id'], self::cameraScale($input['camera_scale'] ?? '1.00'),
                );
            } else {
                throw new ValidationException(['target_type' => 'Choose a product assignment or a scene background.']);
            }
            $this->redirect('/admin/media?binding_id=' . (int) $saved['binding_id'] . '&saved=1');
        } catch (ValidationException $error) {
            $selectedId = self::id($input['binding_id'] ?? null);
            $selected = $selectedId === null ? null : $this->assignments->binding($selectedId);
            $status = isset($error->errors['version']) ? 409 : 422;
            $this->render($actor, $values, $error->errors, $selected, null, $selectedId, $status);
        }
    }

    public function archive(array $input, array $actor): void
    {
        $this->changeArchive($input, $actor, true);
    }

    public function restore(array $input, array $actor): void
    {
        $this->changeArchive($input, $actor, false);
    }

    private function changeArchive(array $input, array $actor, bool $archive): void
    {
        if (!$this->postAllowed($input, $actor)) return;
        try {
            $bindingId = self::requiredId($input['binding_id'] ?? null, 'binding_id');
            $version = self::requiredId($input['expected_version'] ?? null, 'expected_version');
            if ($archive) $this->service->archiveBinding($bindingId, $version, (int) $actor['customer_id']);
            else $this->service->restoreBinding($bindingId, $version, (int) $actor['customer_id']);
            $this->redirect('/admin/media?binding_id=' . $bindingId . '&saved=' . ($archive ? 'archived' : 'restored'));
        } catch (ValidationException $error) {
            $selectedId = self::id($input['binding_id'] ?? null);
            $selected = $selectedId === null ? null : $this->assignments->binding($selectedId);
            $status = isset($error->errors['version']) ? 409 : 422;
            $this->render($actor, self::safeValues($input), $error->errors, $selected, null, $selectedId, $status);
        }
    }

    private function postAllowed(array $input, array $actor): bool
    {
        $this->access->require($actor);
        if (Csrf::valid($input['csrf'] ?? null)) return true;
        View::render('error', [
            'title' => 'Request expired', 'message' => 'Refresh the page and try again.', 'actor' => $actor,
        ], 419, 'internal');
        return false;
    }

    private function render(
        array $actor, array $values, array $errors, ?array $selected, mixed $saved,
        ?int $selectedId = null, int $status = 200,
    ): void {
        $products = $this->assignments->adminProducts();
        $bindings = $this->assignments->adminBindings();
        $history = $selected === null ? [] : $this->assignments->history((int) $selected['binding_id']);
        AdminView::render('admin/media', [
            'title' => 'Product media', 'actor' => $actor, 'products' => $products, 'bindings' => $bindings,
            'assets' => $this->assets->allAssets(), 'scenes' => $this->assets->backgroundScenes(),
            'values' => $values, 'errors' => $errors, 'selected' => $selected,
            'selectedId' => $selectedId, 'history' => $history,
            'saved' => in_array($saved, ['1', 'archived', 'restored'], true) ? $saved : null,
        ], $status);
    }

    private function valuesFromBinding(array $binding): array
    {
        $target = (string) ($binding['product_id'] ?? '') !== '' ? 'product' : 'scene';
        return [
            'binding_id' => (string) $binding['binding_id'],
            'target_type' => $target,
            'product_id' => (string) ($binding['product_id'] ?? ''),
            'media_kind' => (string) $binding['media_kind'],
            'scene_key' => $target === 'scene' ? substr((string) $binding['target_key'], strlen('scene:')) : '',
            'asset_id' => (string) $binding['asset_id'],
            'camera_scale' => number_format((float) ($binding['camera_scale'] ?? 1.0), 2, '.', ''),
            'visible_height_mm' => (string) ($binding['visible_height_mm'] ?? ''),
            'pot_height_mm' => (string) ($binding['pot_height_mm'] ?? ''),
            'measurement_status' => (string) $binding['measurement_status'],
            'expected_version' => (string) $binding['lock_version'],
        ];
    }

    /** Keep only bounded scalar form fields after validation so hostile nested values cannot reach HTML. */
    private static function safeValues(array $input): array
    {
        $values = [];
        foreach ([
            'binding_id' => 20, 'target_type' => 20, 'product_id' => 20, 'media_kind' => 20,
            'scene_key' => 80, 'asset_id' => 100, 'camera_scale' => 8, 'visible_height_mm' => 8, 'pot_height_mm' => 8,
            'measurement_status' => 20, 'expected_version' => 20,
        ] as $field => $maxLength) {
            $value = $input[$field] ?? null;
            if (is_string($value) || is_int($value)) $values[$field] = substr((string) $value, 0, $maxLength);
        }
        return $values;
    }

    private static function optionalMillimetres(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_int($value) && $value > 0) return $value;
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,3}$/D', $value) !== 1) {
            throw new ValidationException([$field => 'Enter a whole-number measurement between 1 and 5000 mm, or leave it blank.']);
        }
        return (int) $value;
    }

    private static function cameraScale(mixed $value): float
    {
        if (!is_string($value) || preg_match('/^(?:0\.(?:85|90|95)|1\.(?:00|05|10|15))$/D', $value) !== 1) {
            throw new ValidationException(['camera_scale' => 'Choose a framing value between 0.85 and 1.15.']);
        }
        return (float) $value;
    }

    private static function optionalVersion(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : self::requiredId($value, 'expected_version');
    }

    private static function requiredId(mixed $value, string $field): int
    {
        if ((is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1)) return (int) $value;
        throw new ValidationException([$field => 'Choose a valid positive ID.']);
    }

    private static function id(mixed $value): ?int
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1)
            ? (int) $value : null;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function redirect(string $location): never
    {
        header('Location: ' . Url::redirectTarget($location), true, 303);
        exit;
    }
}
