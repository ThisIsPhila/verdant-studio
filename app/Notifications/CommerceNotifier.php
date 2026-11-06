<?php

declare(strict_types=1);

namespace Verdant\Notifications;

/** Joins the caller's transaction; never starts a transaction or sends mail. */
final class CommerceNotifier
{
    private const TYPES = [
        'purchase_confirmed' => 'order', 'composition_confirmed' => 'composition',
        'rental_confirmed' => 'rental', 'rental_issued' => 'rental',
        'rental_returned' => 'rental', 'rental_cancelled' => 'rental',
        'order_fulfilled' => 'order', 'order_cancelled' => 'order',
    ];

    public function __construct(private readonly \mysqli $connection) {}

    public function record(string $kind, string $eventKey, string $recordType, int $recordId, ?int $operationId = null): void
    {
        if ((self::TYPES[$kind] ?? null) !== $recordType || $recordId < 1
            || preg_match('/^[A-Za-z0-9:_-]{1,160}$/D', $eventKey) !== 1) {
            throw new \InvalidArgumentException('Invalid commerce notification event.');
        }
        $existing = $this->select('SELECT commerce_event_id FROM commerce_notification_events WHERE event_key=?', [$eventKey]);
        if ($existing !== []) return;
        [$record, $items, $path] = $this->snapshot($recordType, $recordId, $kind, $operationId);
        if (str_ends_with($kind, '_confirmed') && $record['state'] !== 'confirmed') {
            throw new \LogicException('Only a confirmed record can create a confirmation.');
        }
        $context = [
            'event_kind' => $kind, 'record_type' => $recordType, 'record_id' => $recordId,
            'status' => $kind === 'order_fulfilled' ? 'fulfilled' : $record['state'], 'customer_name' => $record['name'],
            'items' => $items, 'currency' => 'ZAR', 'path' => $path,
        ];
        if (isset($record['new_return_quantity'])) $context['newly_returned_quantity'] = $record['new_return_quantity'];
        if (isset($record['total_minor'])) $context['total_minor'] = (int) $record['total_minor'];
        $json = json_encode($context, JSON_THROW_ON_ERROR);
        if (strlen($json) > 131072) throw new \DomainException('Notification summary is too large.');
        $field = ['order' => 'order_id', 'rental' => 'rental_id', 'composition' => 'composition_checkout_id'][$recordType];
        $statement = $this->connection->prepare("INSERT INTO commerce_notification_events(event_key,event_kind,$field,operation_id,context_json) VALUES(?,?,?,?,?)");
        $statement->execute([$eventKey, $kind, $recordId, $operationId, $json]);
        $eventId = (int) $this->connection->insert_id;
        $statement->close();
        $recipients = [['customer_id' => $record['customer_id'], 'email' => $record['email'], 'role' => $record['account_role']]];
        foreach ($this->select("SELECT customer_id,email,role FROM customers WHERE role='admin' AND account_state='active'") as $admin) $recipients[] = $admin;
        $insert = $this->connection->prepare("INSERT INTO email_outbox(commerce_event_id,recipient_email,recipient_customer_id,recipient_role,template_key,context_json,delivery_state,transport) VALUES(?,?,?,?,?,?,?,?)");
        $seen = [];
        foreach ($recipients as $recipient) {
            $addressKey = strtolower((string)$recipient['email']);
            if (isset($seen[$addressKey])) continue;
            $seen[$addressKey] = true;
            $delivery = \Verdant\Support\Mail\OutboxQueuePolicy::initialDelivery((string) $recipient['email']);
            $insert->execute([$eventId, $recipient['email'], $recipient['customer_id'], $recipient['role'], $kind, $json,
                $delivery['delivery_state'], $delivery['transport']]);
        }
        $insert->close();
    }

    private function snapshot(string $type, int $id, string $kind, ?int $operation): array
    {
        $table = ['order' => 'sales_orders', 'rental' => 'rentals', 'composition' => 'composition_checkouts'][$type];
        $idField = ['order' => 'order_id', 'rental' => 'rental_id', 'composition' => 'composition_checkout_id'][$type];
        $state = 'r.state';
        $record = $this->select("SELECT r.*, $state AS state, c.name,c.email,c.customer_id,c.role AS account_role FROM $table r JOIN customers c ON c.customer_id=r.customer_id WHERE r.$idField=?", [$id])[0] ?? null;
        if ($record === null) throw new \DomainException('Notification record is unavailable.');
        if ($type === 'order') {
            $items = $this->select('SELECT product_name_snapshot AS name,quantity,unit_price_minor FROM order_items WHERE order_id=? ORDER BY order_item_id', [$id]);
            $record['total_minor'] = array_sum(array_map(static fn(array $line): int => (int)$line['quantity'] * (int)$line['unit_price_minor'], $items));
            $path = '/account/order?id=' . $id;
        } elseif ($type === 'composition') {
            $items = $this->select('SELECT product_name_snapshot AS name,quantity,mode,start_date,end_date_exclusive,unit_price_minor,daily_rate_minor FROM composition_checkout_lines WHERE composition_checkout_id=? ORDER BY composition_checkout_line_id', [$id]);
            // The existing account order record is the aggregate payment anchor.
            $path = '/account/order?id=' . (int)$record['order_id'];
        } else {
            $items = $this->select('SELECT i.product_name_snapshot AS name,i.quantity,i.start_date,i.end_date_exclusive,i.daily_rate_minor,
                COALESCE(iss.quantity,0) AS issued_quantity,
                COALESCE((SELECT SUM(rr.quantity) FROM rental_returns rr WHERE rr.rental_item_id=i.rental_item_id),0) AS returned_quantity
                FROM rental_items i LEFT JOIN rental_issues iss ON iss.rental_item_id=i.rental_item_id WHERE i.rental_id=? ORDER BY i.rental_item_id', [$id]);
            foreach ($items as &$item) $item['outstanding_quantity'] = max(0, (int)$item['issued_quantity']-(int)$item['returned_quantity']);
            unset($item);
            if ($kind === 'rental_returned') {
                $receipt = $this->select('SELECT rr.quantity FROM rental_returns rr JOIN rental_items i ON i.rental_item_id=rr.rental_item_id WHERE rr.rental_return_id=? AND i.rental_id=?', [$operation, $id])[0] ?? null;
                if ($receipt === null) throw new \DomainException('Return receipt is unavailable.');
                $record['new_return_quantity'] = (int)$receipt['quantity'];
            }
            $payment = $this->select("SELECT amount_minor FROM rental_payments WHERE rental_id=? AND state='succeeded' ORDER BY rental_payment_id DESC LIMIT 1", [$id])[0] ?? null;
            if ($payment) $record['total_minor'] = (int)$payment['amount_minor'];
            $path = '/account/rental?id=' . $id;
        }
        return [$record, $items, $path];
    }

    private function select(string $sql, array $parameters = []): array
    {
        $statement = $this->connection->prepare($sql);
        try { $statement->execute($parameters); return $statement->get_result()->fetch_all(MYSQLI_ASSOC); }
        finally { $statement->close(); }
    }
}
