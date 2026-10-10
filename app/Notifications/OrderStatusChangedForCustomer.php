<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Notifications\Notification;

class OrderStatusChangedForCustomer extends Notification
{
    public function __construct(
        public Order $order,
        public OrderStatus $from,
        public OrderStatus $to,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'customer',
            'title' => 'Order status updated',
            'body' => "Order {$this->order->order_number} is now {$this->to->getLabel()}.",
            'order_number' => $this->order->order_number,
            'from_status' => $this->from->value,
            'to_status' => $this->to->value,
        ];
    }

    public static function send(Order $order, OrderStatus $from, OrderStatus $to): void
    {
        $order->loadMissing('customer');

        $order->customer?->notify(new self($order, $from, $to));
    }
}
