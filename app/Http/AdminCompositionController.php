<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Admin\CompositionAdminService;
use Verdant\Persistence\DatabaseException;
use Verdant\Support\ValidationException;

final class AdminCompositionController
{
    public function __construct(private readonly CompositionAdminService $compositions)
    {
    }

    public function definitions(array $query, array $actor): void
    {
        $data = $this->compositions->page($actor);
        $selectedId = self::id($query['edit'] ?? null);
        $selected = null;
        foreach ($data['definitions'] as $definition) {
            if ((int) $definition['definition_id'] === $selectedId) {
                $selected = $definition;
                break;
            }
        }
        if ($selectedId !== null && $selected === null) {
            AdminView::render('error', ['title' => 'Definition unavailable', 'message' => 'This composition definition could not be found.', 'actor' => $actor], 404);
            return;
        }
        AdminView::render('admin/compositions', $data + [
            'title' => 'Arrangements', 'actor' => $actor, 'selected' => $selected,
            'values' => $selected ?? [], 'errors' => [], 'notice' => isset($query['saved']),
            'addRequested' => in_array($query['add'] ?? null, ['item', 'choice'], true) ? $query['add'] : null,
        ]);
    }

    public function save(array $input, array $actor): void
    {
        $id = self::id($input['definition_id'] ?? null);
        try {
            if ($id === null) {
                throw new ValidationException(['definition_id' => 'Choose a composition definition to edit.']);
            }
            $this->compositions->save($actor, $id, $input);
            $this->redirect('/admin/compositions?edit=' . $id . '&saved=1');
        } catch (ValidationException|DatabaseException $error) {
            $errors = $error instanceof ValidationException
                ? $error->errors
                : ($error->getCode() === 1062 ? ['components' => 'That component key is already used in this definition.'] : null);
            if ($errors === null) {
                throw $error;
            }
            $data = $this->compositions->page($actor);
            $selected = null;
            foreach ($data['definitions'] as $definition) {
                if ((int) $definition['definition_id'] === $id) {
                    $selected = $definition;
                    break;
                }
            }
            AdminView::render('admin/compositions', $data + [
                'title' => 'Arrangements', 'actor' => $actor, 'selected' => $selected,
                'values' => $this->displayValues($input), 'errors' => $errors, 'notice' => false,
            ], 422);
        }
    }

    /** Keep hostile nested POST values out of the HTML rendering path. */
    private function displayValues(array $input): array
    {
        $values = [];
        foreach (['name', 'status', 'definition_id'] as $field) {
            $value = $input[$field] ?? null;
            if (is_string($value) || is_int($value)) {
                $values[$field] = substr((string) $value, 0, $field === 'name' ? 180 : 80);
            }
        }
        $values['components'] = [];
        $components = $input['components'] ?? null;
        if (!is_array($components)) {
            return $values;
        }
        foreach (array_slice($components, 0, 30, true) as $component) {
            if (!is_array($component)) {
                continue;
            }
            $safe = [];
            foreach ([
                'component_id', 'component_key', 'label', 'component_role', 'source_type', 'required', 'archived',
                'min_selections', 'max_selections', 'min_quantity', 'max_quantity', 'permitted_mode', 'sort_order', 'anchor_key',
            ] as $field) {
                $value = $component[$field] ?? null;
                if (is_string($value) || is_int($value)) {
                    $safe[$field] = substr((string) $value, 0, $field === 'label' ? 120 : 80);
                }
            }
            foreach (['product_ids', 'family_ids'] as $field) {
                $rawIds = $component[$field] ?? null;
                if (!is_array($rawIds)) {
                    continue;
                }
                $ids = [];
                foreach (array_slice($rawIds, 0, 100) as $value) {
                    if ((is_string($value) || is_int($value)) && ctype_digit((string) $value)
                        && strlen((string) $value) <= 18 && (int) $value > 0) {
                        $ids[] = (int) $value;
                    }
                }
                $safe[$field] = $ids;
            }
            $values['components'][] = $safe;
        }
        return $values;
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0
            ? (int) $value : null;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Url::redirectTarget($path), true, 303);
        exit;
    }
}
