<?php

declare(strict_types=1);

namespace Verdant\Storefront;

use Verdant\Inventory\RentalPeriod;
use Verdant\Rentals\RentalService;
use Verdant\Support\ValidationException;

/** Read-only dated rental availability over already-filtered catalogue rows. */
final class RentalDiscoveryService
{
    private const NON_RENTABLE_KINDS = ['cut_flower', 'cut_foliage', 'floral_arrangement'];

    public function __construct(private readonly RentalService $rentals)
    {
    }

    /** @return array{start_date:string,end_date:string,has_dates:bool} */
    public static function normalizeDates(array $query): array
    {
        $start = $query['start_date'] ?? '';
        $end = $query['end_date'] ?? '';
        if (!is_string($start) || !is_string($end) || strlen($start) > 10 || strlen($end) > 10) {
            throw new ValidationException(['dates' => 'Choose valid rental start and end dates.']);
        }
        $start = trim($start);
        $end = trim($end);
        if ($start === '' && $end === '') {
            return ['start_date' => '', 'end_date' => '', 'has_dates' => false];
        }
        if ($start === '' || $end === '') {
            throw new ValidationException(['dates' => 'Choose both a rental start date and an end date.']);
        }
        try {
            RentalPeriod::fromInclusive($start, $end);
        } catch (\InvalidArgumentException $error) {
            throw new ValidationException(['dates' => $error->getMessage()]);
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        if ($start < $today) {
            throw new ValidationException(['dates' => 'Rental dates cannot be in the past.']);
        }
        return ['start_date' => $start, 'end_date' => $end, 'has_dates' => true];
    }

    /** @param list<array<string,mixed>> $products @return array<int,array<string,mixed>> */
    public function quotes(array $products, string $startInclusive, string $endInclusive): array
    {
        $period = RentalPeriod::fromInclusive($startInclusive, $endInclusive);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        if ($period['start_date'] < $today) {
            throw new ValidationException(['dates' => 'Rental dates cannot be in the past.']);
        }
        $quotes = [];
        foreach ($products as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            if ($productId < 1 || !self::isEligibleProduct($product)) {
                continue;
            }
            try {
                $quotes[$productId] = $this->rentals->quote($productId, 1, $startInclusive, $endInclusive);
            } catch (\DomainException) {
                $quotes[$productId] = [
                    'product_id' => $productId,
                    'available' => false,
                    'available_quantity' => 0,
                    'unavailable' => true,
                ];
            } catch (\OverflowException) {
                $quotes[$productId] = [
                    'product_id' => $productId,
                    'available' => false,
                    'available_quantity' => 0,
                    'unavailable' => true,
                ];
            }
        }
        return $quotes;
    }

    /** Rental discovery has an explicit sale-only boundary even if a row bypasses database constraints. */
    public static function isEligibleProduct(array $product): bool
    {
        $rate = $product['rental_daily_rate_minor'] ?? null;
        return !empty($product['rent_enabled'])
            && !in_array($product['product_kind'] ?? '', self::NON_RENTABLE_KINDS, true)
            && (is_int($rate) || (is_string($rate) && ctype_digit($rate)))
            && (int) $rate >= 0;
    }
}
