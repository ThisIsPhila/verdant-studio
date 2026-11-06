<?php

declare(strict_types=1);

namespace Verdant\Admin;

/** A route-key based return target. It never accepts a posted URL or path. */
final class RentalReturnContext
{
    private function __construct(
        public readonly string $origin,
        public readonly ?string $month = null,
        public readonly string $view = 'month',
        public readonly string $group = 'current',
        public readonly int $page = 1,
    ) {
    }

    public static function from(array $values): self
    {
        if (($values['origin'] ?? null) === 'returns') {
            $page = $values['return_page'] ?? $values['page'] ?? '1';
            if ((!is_string($page) && !is_int($page))
                || preg_match('/^[1-9][0-9]{0,8}$/D', (string) $page) !== 1) {
                return new self('returns');
            }
            return new self('returns', null, 'month', 'current', (int) $page);
        }
        $origin = $values['return_origin'] ?? null;
        if ($origin === 'calendar') {
            $month = $values['return_month'] ?? null;
            $view = $values['return_view'] ?? 'month';
            if (!self::validMonth($month) || !in_array($view, ['month', 'agenda'], true)) {
                return self::listDefault();
            }
            return new self('calendar', $month, $view);
        }
        if ($origin === 'list') {
            $group = $values['return_group'] ?? 'current';
            $page = $values['return_page'] ?? '1';
            if (!is_string($group) || !in_array($group, ['overdue', 'current', 'upcoming', 'history'], true)
                || (!is_string($page) && !is_int($page))
                || preg_match('/^[1-9][0-9]{0,8}$/D', (string) $page) !== 1) {
                return self::listDefault();
            }
            return new self('list', null, 'month', $group, (int) $page);
        }
        return self::listDefault();
    }

    public static function listDefault(): self
    {
        return new self('list');
    }

    public function url(): string
    {
        if ($this->origin === 'returns') {
            return '/admin/returns?' . http_build_query(['page' => $this->page]);
        }
        if ($this->origin === 'calendar') {
            return '/admin/rental-calendar?' . http_build_query(['month' => $this->month, 'view' => $this->view]);
        }
        return '/admin/rentals?' . http_build_query([
            'group' => $this->group,
            'page' => $this->page,
        ]);
    }

    /** @return array<string,string> */
    public function fields(): array
    {
        if ($this->origin === 'returns') {
            return ['origin' => 'returns', 'return_page' => (string) $this->page];
        }
        if ($this->origin === 'calendar') {
            return ['return_origin' => 'calendar', 'return_month' => (string) $this->month, 'return_view' => $this->view];
        }
        return ['return_origin' => 'list', 'return_group' => $this->group, 'return_page' => (string) $this->page];
    }

    public function detailQuery(int $rentalId): string
    {
        return '/admin/rental?' . http_build_query(['id' => $rentalId] + $this->fields());
    }

    private static function validMonth(mixed $month): bool
    {
        return is_string($month)
            && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $month) === 1
            && $month >= '2000-01' && $month <= '2100-12';
    }
}
