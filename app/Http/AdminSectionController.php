<?php

declare(strict_types=1);

namespace Verdant\Http;

use Verdant\Admin\AdminAccess;
use Verdant\Storefront\SectionRegistry;
use Verdant\Storefront\InformationContentService;
use Verdant\Storefront\StorefrontSectionService;
use Verdant\Support\Csrf;
use Verdant\Support\ValidationException;

/** Protected editor for the fixed, allowlisted storefront section vocabulary. */
final class AdminSectionController
{
    public function __construct(
        private readonly StorefrontSectionService $sections,
        private readonly AdminAccess $access,
        private readonly ?InformationContentService $information = null,
    ) {
    }

    public function index(array $query, array $actor): void
    {
        $this->access->require($actor);
        $page = $query['page'] ?? 'home';
        $sectionKey = $query['section'] ?? null;
        $errors = [];
        if (!is_string($page) || !SectionRegistry::isPage($page)) {
            $errors['page'] = 'Choose a supported storefront page.';
            $sectionKey = null;
            $page = 'home';
        }
        if ($sectionKey !== null && (!is_string($sectionKey) || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $sectionKey) !== 1)) {
            $errors['section_key'] = 'Choose a valid section.';
            $sectionKey = null;
        }
        $this->renderPage($actor, $page, $sectionKey, [], $errors, $errors === [] ? 200 : 422,
            ($query['saved'] ?? null) === '1', ($query['published'] ?? null) === '1');
    }

    public function save(array $input, array $actor): void
    {
        if (!$this->postAllowed($input, $actor)) {
            return;
        }
        $values = self::safeValues($input);
        $page = is_string($input['page'] ?? null) && SectionRegistry::isPage($input['page']) ? $input['page'] : 'home';
        $sectionKey = is_string($input['section_key'] ?? null) ? $input['section_key'] : null;
        try {
            $page = $this->pageValue($input['page'] ?? null);
            $sectionKey = $this->sectionKeyValue($input['section_key'] ?? null);
            $this->sections->save($page, $sectionKey, $input);
            $this->redirect($page, $sectionKey);
        } catch (ValidationException $error) {
            $this->renderPage($actor, SectionRegistry::isPage($page) ? $page : 'home', $sectionKey, $values,
                $error->errors, 422, false);
        }
    }

    public function move(array $input, array $actor): void
    {
        if (!$this->postAllowed($input, $actor)) {
            return;
        }
        $page = is_string($input['page'] ?? null) && SectionRegistry::isPage($input['page']) ? $input['page'] : 'home';
        $sectionKey = is_string($input['section_key'] ?? null) ? $input['section_key'] : null;
        try {
            $page = $this->pageValue($input['page'] ?? null);
            $sectionKey = $this->sectionKeyValue($input['section_key'] ?? null);
            $direction = $input['direction'] ?? null;
            if (!is_string($direction) || !in_array($direction, ['-1', '1'], true)) {
                throw new ValidationException(['direction' => 'Choose move up or move down.']);
            }
            $this->sections->move($page, $sectionKey, (int) $direction);
            $this->redirect($page, $sectionKey);
        } catch (ValidationException $error) {
            $this->renderPage($actor, SectionRegistry::isPage($page) ? $page : 'home', is_string($sectionKey) ? $sectionKey : null,
                self::safeValues($input), $error->errors, 422, false);
        }
    }

    public function visibility(array $input, array $actor): void
    {
        if (!$this->postAllowed($input, $actor)) {
            return;
        }
        $page = is_string($input['page'] ?? null) && SectionRegistry::isPage($input['page']) ? $input['page'] : 'home';
        $sectionKey = is_string($input['section_key'] ?? null) ? $input['section_key'] : null;
        try {
            $page = $this->pageValue($input['page'] ?? null);
            $sectionKey = $this->sectionKeyValue($input['section_key'] ?? null);
            $visible = $input['is_enabled'] ?? null;
            if (!is_string($visible) || !in_array($visible, ['0', '1'], true)) {
                throw new ValidationException(['is_enabled' => 'Choose whether the section is visible.']);
            }
            $this->sections->setVisible($page, $sectionKey, $visible === '1');
            $this->redirect($page, $sectionKey);
        } catch (ValidationException $error) {
            $this->renderPage($actor, SectionRegistry::isPage($page) ? $page : 'home', is_string($sectionKey) ? $sectionKey : null,
                self::safeValues($input), $error->errors, 422, false);
        }
    }

    public function archive(array $input, array $actor): void
    {
        $this->lifecycle($input, $actor, true);
    }

    public function restore(array $input, array $actor): void
    {
        $this->lifecycle($input, $actor, false);
    }

    public function publishPolicy(array $input, array $actor): void
    {
        if (!$this->postAllowed($input, $actor)) {
            return;
        }
        $page = is_string($input['page'] ?? null) && SectionRegistry::isPage($input['page']) ? $input['page'] : 'privacy';
        try {
            $page = $this->pageValue($input['page'] ?? null);
            if ($this->information === null) {
                throw new ValidationException(['page' => 'Policy publication is not configured.']);
            }
            $this->information->publishPolicy($page, $input, (int) ($actor['customer_id'] ?? 0));
            header('Location: ' . Url::redirectTarget('/admin/sections?page=' . rawurlencode($page) . '&published=1'), true, 303);
            exit;
        } catch (ValidationException $error) {
            $this->renderPage($actor, SectionRegistry::isPage($page) ? $page : 'privacy', null,
                self::safeValues($input), $error->errors, 422, false, false);
        }
    }

    private function lifecycle(array $input, array $actor, bool $archive): void
    {
        if (!$this->postAllowed($input, $actor)) {
            return;
        }
        $page = is_string($input['page'] ?? null) && SectionRegistry::isPage($input['page']) ? $input['page'] : 'home';
        $sectionKey = is_string($input['section_key'] ?? null) ? $input['section_key'] : null;
        try {
            $page = $this->pageValue($input['page'] ?? null);
            $sectionKey = $this->sectionKeyValue($input['section_key'] ?? null);
            if ($archive) {
                $this->sections->archive($page, $sectionKey);
            } else {
                $this->sections->restore($page, $sectionKey);
            }
            $this->redirect($page, $sectionKey);
        } catch (ValidationException $error) {
            $this->renderPage($actor, SectionRegistry::isPage($page) ? $page : 'home', is_string($sectionKey) ? $sectionKey : null,
                self::safeValues($input), $error->errors, 422, false);
        }
    }

    private function postAllowed(array $input, array $actor): bool
    {
        $this->access->require($actor);
        if (Csrf::valid($input['csrf'] ?? null)) {
            return true;
        }
        View::render('error', [
            'title' => 'Request expired', 'message' => 'Refresh the page and try again.', 'actor' => $actor,
        ], 419, 'internal');
        return false;
    }

    private function renderPage(
        array $actor,
        string $page,
        ?string $sectionKey,
        array $values,
        array $errors,
        int $status,
        bool $saved,
        bool $published = false,
    ): void {
        $rows = $this->sections->sectionsForPage($page, true);
        $selected = null;
        foreach ($rows as $row) {
            if ($sectionKey !== null && $row['section_key'] === $sectionKey) {
                $selected = $row;
                break;
            }
        }
        if ($sectionKey !== null && $selected === null && !isset($errors['section_key'])) {
            $errors['section_key'] = 'Choose a section on this page.';
        }
        if ($selected === null && $sectionKey === null) {
            foreach ($rows as $row) {
                if ($row['archived_at'] === null) {
                    $selected = $row;
                    break;
                }
            }
        }
        if ($values === [] && $selected !== null) {
            $values = $selected;
        } elseif ($selected !== null) {
            $values = array_replace($selected, $values);
        }
        $referenceOptions = $this->sections->referenceOptions();
        $policyState = $this->information?->policyState($page);
        View::render('admin/sections', [
            'title' => 'Manage storefront sections', 'actor' => $actor,
            'pages' => SectionRegistry::pages(), 'page' => $page, 'sections' => $rows,
            'selected' => $selected, 'values' => $values, 'errors' => $errors,
            'diagnostics' => $this->sections->diagnostics($page), 'saved' => $saved,
            'mediaPaths' => $referenceOptions['media_paths'], 'linkPaths' => $referenceOptions['link_paths'],
            'collections' => $referenceOptions['collections'], 'arrangements' => $referenceOptions['arrangements'],
            'informationPages' => $this->information?->adminPages() ?? [],
            'policyState' => $policyState,
            'policyHistory' => $policyState === null ? [] : $this->information?->policyHistory($page) ?? [],
            'published' => $published,
        ], $status, 'internal');
    }

    private function pageValue(mixed $value): string
    {
        if (!is_string($value) || !SectionRegistry::isPage($value)) {
            throw new ValidationException(['page' => 'Choose a supported storefront page.']);
        }
        return $value;
    }

    private function sectionKeyValue(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $value) !== 1) {
            throw new ValidationException(['section_key' => 'Choose a valid section.']);
        }
        return $value;
    }

    private static function safeValues(array $input): array
    {
        $values = [];
        foreach (['heading', 'body', 'media_path', 'media_alt', 'link_label', 'link_path', 'collection_id', 'arrangement_id', 'is_enabled', 'version', 'effective_date', 'revision', 'confirm_publish'] as $field) {
            $value = $input[$field] ?? '';
            $values[$field] = is_string($value) || is_int($value) ? (string) $value : '';
        }
        $values['page'] = is_string($input['page'] ?? null) ? substr($input['page'], 0, 40) : '';
        $values['section_key'] = is_string($input['section_key'] ?? null) ? substr($input['section_key'], 0, 80) : '';
        return $values;
    }

    private function redirect(string $page, string $sectionKey): never
    {
        header('Location: ' . Url::redirectTarget('/admin/sections?page=' . rawurlencode($page) . '&section=' . rawurlencode($sectionKey) . '&saved=1'), true, 303);
        exit;
    }
}
