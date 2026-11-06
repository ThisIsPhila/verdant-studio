<?php

declare(strict_types=1);

namespace Verdant\Admin;

use Verdant\Persistence\TransactionManager;
use Verdant\Support\ValidationException;

/** Admin boundary for reversible composition-definition and allowlist editing. */
final class CompositionAdminService
{
    private const ROLES = ['plant', 'pot', 'flower', 'foliage', 'container', 'other'];
    private const SOURCES = ['product', 'family', 'compatible_pot'];
    private const MODES = ['sale', 'rent', 'either'];

    public function __construct(
        private readonly CompositionAdminRepository $repository,
        private readonly TransactionManager $transactions,
        private readonly AdminAccess $access,
    ) {
    }

    public function page(array $actor): array
    {
        $this->access->require($actor);
        return [
            'definitions' => $this->repository->definitions(),
            'products' => $this->repository->products(),
            'families' => $this->repository->families(),
        ];
    }

    public function save(array $actor, mixed $id, array $input): void
    {
        $definitionId = $this->positiveId($id, 'definition_id');
        $name = $this->text($input['name'] ?? null, 'name', 180);
        $status = $input['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['active', 'archived'], true)) {
            throw new ValidationException(['status' => 'Choose an active or archived state.']);
        }
        $rawComponents = $input['components'] ?? [];
        if (!is_array($rawComponents) || count($rawComponents) > 30) {
            throw new ValidationException(['components' => 'Submit a valid set of at most 30 components.']);
        }
        $this->access->require($actor);
        $this->transactions->run(function () use ($actor, $definitionId, $name, $status, $rawComponents): void {
            $this->access->require($actor, true);
            $definition = $this->repository->definition($definitionId, true);
            if ($definition === null) {
                throw new ValidationException(['definition_id' => 'Composition definition not found.']);
            }
            if ($definition['strategy'] === 'finished_stock' && $rawComponents !== []) {
                throw new ValidationException(['components' => 'Finished-stock definitions reserve their own SKU and do not have editable component rules.']);
            }
            $submitted = $this->normalizeComponents($rawComponents, $definition);
            if ($definition['strategy'] !== 'finished_stock' && $status === 'active'
                && !array_filter($submitted, static fn (array $component): bool => !$component['archived'])) {
                throw new ValidationException(['components' => 'An active definition must keep at least one active component.']);
            }
            $this->validateAnchorsAndAllowLists($submitted, $definition);

            $this->repository->updateDefinition($definitionId, $name, $status);
            $idsByKey = [];
            foreach ($definition['components'] as $existing) {
                $idsByKey[$existing['component_key']] = (int) $existing['component_id'];
            }
            foreach ($submitted as $component) {
                $anchorId = $component['anchor_key'] === null ? null : ($idsByKey[$component['anchor_key']] ?? null);
                if ($component['source_type'] === 'compatible_pot' && $anchorId === null) {
                    throw new ValidationException(['components' => 'Save the plant component before adding a compatible-pot component.']);
                }
                $componentId = $this->repository->upsertComponent(
                    $definitionId, $component['component_id'], $component, $anchorId,
                );
                $idsByKey[$component['component_key']] = $componentId;
                if ($component['archived']) {
                    continue;
                }
                if ($component['source_type'] === 'product') {
                    $this->repository->syncProductAllowlist($componentId, $component['product_ids']);
                    $this->repository->syncFamilyAllowlist($componentId, []);
                } elseif ($component['source_type'] === 'family') {
                    $this->repository->syncFamilyAllowlist($componentId, $component['family_ids']);
                    $this->repository->syncProductAllowlist($componentId, []);
                } else {
                    $this->repository->syncProductAllowlist($componentId, []);
                    $this->repository->syncFamilyAllowlist($componentId, []);
                }
            }
        });
    }

    private function normalizeComponents(array $rawComponents, array $definition): array
    {
        $existing = [];
        foreach ($definition['components'] as $component) {
            $existing[(int) $component['component_id']] = $component;
        }
        $normalized = [];
        $keys = [];
        foreach ($rawComponents as $raw) {
            if (!is_array($raw)) {
                throw new ValidationException(['components' => 'Each component must be a valid form section.']);
            }
            $rawKey = $raw['component_key'] ?? null;
            $rawLabel = $raw['label'] ?? null;
            $rawId = $raw['component_id'] ?? null;
            $blankKey = $rawKey === null || (is_string($rawKey) && trim($rawKey) === '');
            $blankLabel = $rawLabel === null || (is_string($rawLabel) && trim($rawLabel) === '');
            $allBlank = $blankKey && $blankLabel
                && ($rawId === null || $rawId === '');
            if ($allBlank) {
                continue;
            }
            $id = ($raw['component_id'] ?? '') === '' ? null : $this->positiveId($raw['component_id'], 'components');
            if ($id !== null && !isset($existing[$id])) {
                throw new ValidationException(['components' => 'A submitted component does not belong to this definition.']);
            }
            $key = $raw['component_key'] ?? null;
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key) !== 1 || isset($keys[$key])) {
                throw new ValidationException(['components' => 'Each component needs a unique lowercase key.']);
            }
            $keys[$key] = true;
            $role = $raw['component_role'] ?? null;
            $source = $raw['source_type'] ?? null;
            $mode = $raw['permitted_mode'] ?? null;
            if (!in_array($role, self::ROLES, true) || !in_array($source, self::SOURCES, true) || !in_array($mode, self::MODES, true)) {
                throw new ValidationException(['components' => 'Choose valid component role, source and permitted mode values.']);
            }
            $anchorKey = $raw['anchor_key'] ?? '';
            if (!is_string($anchorKey) || ($anchorKey !== '' && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $anchorKey) !== 1)) {
                throw new ValidationException(['components' => 'Choose a valid plant component as the compatibility anchor.']);
            }
            $component = [
                'component_id' => $id,
                'component_key' => $key,
                'label' => $this->text($raw['label'] ?? null, 'components', 120),
                'component_role' => $role,
                'source_type' => $source,
                'required' => $this->flag($raw['required'] ?? '0', 'components'),
                'archived' => $this->flag($raw['archived'] ?? '0', 'components'),
                'min_selections' => $this->boundedInt($raw['min_selections'] ?? null, 1, 30, 'components'),
                'max_selections' => $this->boundedInt($raw['max_selections'] ?? null, 1, 30, 'components'),
                'min_quantity' => $this->boundedInt($raw['min_quantity'] ?? null, 1, 10000, 'components'),
                'max_quantity' => $this->boundedInt($raw['max_quantity'] ?? null, 1, 10000, 'components'),
                'permitted_mode' => $mode,
                'sort_order' => $this->boundedInt($raw['sort_order'] ?? '0', 0, 65535, 'components'),
                'anchor_key' => $anchorKey === '' ? null : $anchorKey,
                'product_ids' => $this->idList($raw['product_ids'] ?? [], 'components'),
                'family_ids' => $this->idList($raw['family_ids'] ?? [], 'components'),
                'archived_at' => (($raw['archived'] ?? '0') === '1' || ($raw['archived'] ?? 0) === 1) ? gmdate('Y-m-d H:i:s') : null,
            ];
            if ($component['max_selections'] < $component['min_selections']
                || $component['max_quantity'] < $component['min_quantity']) {
                throw new ValidationException(['components' => 'Each maximum must be at least its matching minimum.']);
            }
            if ($component['required'] && $component['max_selections'] < $component['min_selections']) {
                throw new ValidationException(['components' => 'Required components must permit the minimum number of selections.']);
            }
            if (in_array($component['component_role'], ['flower', 'foliage'], true) && $component['permitted_mode'] !== 'sale') {
                throw new ValidationException(['components' => 'Flowers and cut foliage must remain sale-only.']);
            }
            if ($component['source_type'] === 'compatible_pot' && !in_array($component['component_role'], ['pot', 'container'], true)) {
                throw new ValidationException(['components' => 'A compatible-pot source must use the pot or container role.']);
            }
            $normalized[] = $component;
        }
        return $normalized;
    }

    private function validateAnchorsAndAllowLists(array $components, array $definition): void
    {
        $keys = [];
        foreach ($definition['components'] as $old) {
            $keys[$old['component_key']] = $old;
        }
        foreach ($components as $component) {
            $keys[$component['component_key']] = $component;
        }
        foreach ($components as $component) {
            if ($component['archived']) {
                continue;
            }
            if ($component['source_type'] === 'compatible_pot') {
                $anchor = $keys[$component['anchor_key'] ?? ''] ?? null;
                $anchorArchived = $anchor !== null && (($anchor['archived'] ?? false)
                    || (($anchor['archived_at'] ?? null) !== null && ($anchor['archived_at'] ?? '') !== ''));
                if ($anchor === null || $anchorArchived || $anchor['component_role'] !== 'plant'
                    || $anchor['source_type'] === 'compatible_pot') {
                    throw new ValidationException(['components' => 'A compatible pot must point to an active plant component.']);
                }
                if ($component['product_ids'] !== [] || $component['family_ids'] !== []) {
                    throw new ValidationException(['components' => 'Compatible pots come only from explicit plant/pot compatibility records.']);
                }
            } elseif ($component['source_type'] === 'product' && $component['product_ids'] === []) {
                throw new ValidationException(['components' => 'Select at least one product for each active product component.']);
            } elseif ($component['source_type'] === 'family' && $component['family_ids'] === []) {
                throw new ValidationException(['components' => 'Select at least one family for each active family component.']);
            }
            foreach ($component['product_ids'] as $productId) {
                $product = $this->repository->product($productId);
                if ($product === null || $product['archived_at'] !== null || $product['category_archived_at'] !== null
                    || !$this->roleMatches($component['component_role'], $product['product_kind'], $product['accessory_subtype'])) {
                    throw new ValidationException(['components' => 'Every selected product must be active and match its component role.']);
                }
            }
            foreach ($component['family_ids'] as $familyId) {
                $family = $this->repository->family($familyId);
                if ($family === null || $family['archived_at'] !== null
                    || !$this->roleMatches($component['component_role'], $family['product_kind'], null)) {
                    throw new ValidationException(['components' => 'Every selected family must be active and match its component role.']);
                }
                $variants = $this->repository->familyProductRoles($familyId);
                if ($variants === []) {
                    throw new ValidationException(['components' => 'An allowed family must contain at least one active SKU.']);
                }
                foreach ($variants as $variant) {
                    if (!$this->roleMatches($component['component_role'], $variant['product_kind'], $variant['accessory_subtype'])) {
                        throw new ValidationException(['components' => 'Every active SKU in an allowed family must match its component role.']);
                    }
                }
            }
        }
    }

    private function roleMatches(string $role, string $kind, ?string $subtype): bool
    {
        return match ($role) {
            'plant' => $kind === 'plant',
            'flower' => in_array($kind, ['cut_flower', 'floral_arrangement'], true),
            'foliage' => $kind === 'cut_foliage',
            'pot', 'container' => $kind === 'accessory' && ($subtype === null || $subtype === 'pot'),
            default => true,
        };
    }

    private function idList(mixed $values, string $field): array
    {
        if (!is_array($values) || count($values) > 100) {
            throw new ValidationException([$field => 'Choose a valid allowlist.']);
        }
        $ids = [];
        foreach ($values as $value) {
            $ids[] = $this->positiveId($value, $field);
        }
        return array_values(array_unique($ids));
    }

    private function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value)) {
            throw new ValidationException([$field => 'Enter valid text.']);
        }
        $value = trim($value);
        $length = preg_match_all('/./us', $value);
        if ($value === '' || $length === false || $length > $max) {
            throw new ValidationException([$field => 'Enter text within the allowed length.']);
        }
        return $value;
    }

    private function positiveId(mixed $value, string $field): int
    {
        if ((is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0)
            || (is_int($value) && $value > 0)) {
            return (int) $value;
        }
        throw new ValidationException([$field => 'Choose a valid record.']);
    }

    private function boundedInt(mixed $value, int $min, int $max, string $field): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new ValidationException([$field => 'Enter a whole number in the allowed range.']);
        }
        $text = (string) $value;
        if (!ctype_digit($text) || strlen($text) > 9 || (int) $text < $min || (int) $text > $max) {
            throw new ValidationException([$field => 'Enter a whole number in the allowed range.']);
        }
        return (int) $text;
    }

    private function flag(mixed $value, string $field): bool
    {
        if (in_array($value, ['1', 1, true], true)) {
            return true;
        }
        if (in_array($value, ['0', 0, false, null], true)) {
            return false;
        }
        throw new ValidationException([$field => 'Choose a valid yes/no value.']);
    }
}
