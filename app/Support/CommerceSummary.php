<?php

declare(strict_types=1);

namespace Verdant\Support;

/** Builds display-only item-summary data from an authoritative commerce quote. */
final class CommerceSummary
{
    /** @return array{title:string,items:list<array{label:string,detail:string,quantity:string,amount:string}>,total_label:string,total:string} */
    public static function fromRetailOrder(array $order): array
    {
        $lines = [];
        foreach ($order['items'] ?? [] as $item) {
            $lines[] = [
                'label' => $item['product_name_snapshot'] ?? 'Order item',
                'sku' => $item['sku_snapshot'] ?? '',
                'detail' => 'Purchased item'
                    . (empty($item['sku_snapshot']) ? '' : ' · SKU ' . $item['sku_snapshot']),
                'quantity' => (int) ($item['quantity'] ?? 0) . ' × ' . Format::money($item['unit_price_minor'] ?? null) . ' each',
                'amount' => $item['total_minor'] ?? null,
            ];
        }
        return self::fromSnapshotLines('Order items', $lines, $order['total_minor'] ?? null, 'Order total');
    }

    /** @return array{title:string,items:list<array{label:string,detail:string,quantity:string,amount:string}>,total_label:string,total:string} */
    public static function fromRental(array $rental): array
    {
        $lines = [];
        foreach ($rental['items'] ?? [] as $item) {
            $quantity = (int) ($item['quantity'] ?? 0);
            $start = (string) ($item['start_date'] ?? '');
            $end = (string) ($item['end_date_exclusive'] ?? '');
            $days = self::rentalDays($start, $end);
            $lines[] = [
                'label' => $item['product_name_snapshot'] ?? 'Rental item',
                'sku' => $item['sku_snapshot'] ?? '',
                'detail' => 'Rental · return required · ' . Format::date($start) . '–' . Format::date($end, true) . ' inclusive'
                    . (empty($item['sku_snapshot']) ? '' : ' · SKU ' . $item['sku_snapshot']),
                'quantity' => $quantity . ' × ' . Format::money($item['daily_rate_minor'] ?? null) . ' per item/day × ' . $days . ' day' . ($days === 1 ? '' : 's'),
                'amount' => $item['total_minor'] ?? null,
            ];
        }
        return self::fromSnapshotLines('Rental items', $lines, $rental['total_minor'] ?? null, 'Rental total');
    }

    /** @return array{title:string,items:list<array{label:string,detail:string,quantity:string,amount:string}>,total_label:string,total:string} */
    public static function fromCompositionCheckout(array $checkout): array
    {
        $summary = self::fromQuote([
            'definition' => ['name' => $checkout['definition_name_snapshot'] ?? 'Arrangement'],
            'lines' => $checkout['lines'] ?? [],
            'total_minor' => $checkout['total_minor'] ?? null,
            'available' => true,
        ]);
        foreach ($summary['items'] as $index => &$item) {
            $line = $checkout['lines'][$index];
            if (($line['mode'] ?? '') !== 'rent') {
                $item['detail'] = 'Buy · no rental return';
            }
            if (!empty($line['sku_snapshot'])) {
                $item['detail'] .= ' · SKU ' . $line['sku_snapshot'];
            }
        }
        unset($item);
        $summary['title'] = 'Prepared arrangement items';
        $summary['total_label'] = 'Prepared total';
        return $summary;
    }

    /**
     * @param array{definition:array,lines:list<array>,total_minor:int,available:bool} $quote
     * @return array{title:string,items:list<array{label:string,detail:string,quantity:string,amount:string}>,total_label:string,total:string}
     */
    public static function fromQuote(array $quote): array
    {
        $items = [];
        foreach ($quote['lines'] ?? [] as $line) {
            $mode = ($line['mode'] ?? '') === 'rent' ? 'rent' : 'sale';
            $quantity = (int) ($line['quantity'] ?? 0);
            $detail = $mode === 'sale' ? 'Buy' : 'Rent · return required';

            if ($mode === 'rent') {
                $start = (string) ($line['start_date'] ?? '');
                $endExclusive = (string) ($line['end_date_exclusive'] ?? '');
                $days = self::rentalDays($start, $endExclusive);
                $detail .= ' · ' . Format::date($start) . '–' . Format::date($endExclusive, true) . ' inclusive';
                $quantityDisplay = $quantity . ' × ' . Format::money($line['daily_rate_minor'] ?? null)
                    . ' per item/day × ' . $days . ' day' . ($days === 1 ? '' : 's');
            } else {
                $quantityDisplay = $quantity . ' × ' . Format::money($line['unit_price_minor'] ?? null) . ' each';
            }

            $items[] = [
                'label' => (string) ($line['product_name_snapshot'] ?? 'Arrangement item'),
                'detail' => $detail,
                'quantity' => $quantityDisplay,
                // The quote's line amount is authoritative. This helper never calculates it.
                'amount' => Format::money($line['line_total_minor'] ?? null),
            ];
        }

        return [
            'title' => 'Your estimate',
            'items' => $items,
            'total_label' => 'Estimated total:',
            // The quote's total is authoritative. This helper never sums its items.
            'total' => Format::money($quote['total_minor'] ?? null),
        ];
    }

    private static function rentalDays(string $start, string $endExclusive): int
    {
        $from = new \DateTimeImmutable($start, new \DateTimeZone('Africa/Johannesburg'));
        $until = new \DateTimeImmutable($endExclusive, new \DateTimeZone('Africa/Johannesburg'));
        return max(0, (int) $from->diff($until)->format('%r%a'));
    }

    /** @param list<array{label?:mixed,sku?:mixed,detail?:mixed,quantity?:mixed,amount?:mixed}> $lines */
    private static function fromSnapshotLines(string $title, array $lines, mixed $totalMinor, string $totalLabel): array
    {
        $items = [];
        foreach ($lines as $line) {
            $detail = (string) ($line['detail'] ?? '');
            $items[] = [
                'label' => (string) ($line['label'] ?? 'Order item'),
                'detail' => $detail,
                'quantity' => (string) ($line['quantity'] ?? ''),
                'amount' => Format::money($line['amount'] ?? null),
            ];
        }
        return [
            'title' => $title,
            'items' => $items,
            'total_label' => $totalLabel,
            'total' => Format::money($totalMinor),
        ];
    }
}
