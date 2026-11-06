<?php

declare(strict_types=1);

namespace Verdant\Admin;

final class DashboardService
{
    private const RECENT_LIMIT = 5;
    private const ATTENTION_LIMIT = 5;

    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly AdminAccess $access,
    ) {
    }

    public function overview(array $actor): array
    {
        $this->access->require($actor);

        return [
            'counts' => $this->dashboard->counts(),
            'recentOrders' => $this->dashboard->recentOrders(self::RECENT_LIMIT),
            'recentRentals' => $this->dashboard->recentRentals(self::RECENT_LIMIT),
            'outstandingReturns' => $this->dashboard->outstandingReturns(self::ATTENTION_LIMIT),
        ];
    }
}
