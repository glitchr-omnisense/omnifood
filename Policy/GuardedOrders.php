<?php

namespace Omnifood\Policy;

use Omnifood\Model\DenyReason;
use Omnifood\Model\Order;
use Omnifood\OrdersInterface;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * A platform's orders behind its guard: "order", "orders", "accept", "deny",
 * "ready", "cancel".
 *
 * @extends Guarded<OrdersInterface>
 */
final class GuardedOrders extends Guarded implements OrdersInterface
{
    public function __construct(OrdersInterface $platform, PolicyGuard $guard)
    {
        parent::__construct($platform, $guard);
    }

    public function order(string $ref): Order
    {
        return $this->guard->call('order', fn () => $this->platform->order($ref));
    }

    public function orders(?\DateTimeImmutable $since = null): array
    {
        return $this->guard->call('orders', fn () => $this->platform->orders($since));
    }

    public function accept(string $ref, ?\DateTimeImmutable $readyAt = null): void
    {
        $this->guard->call('accept', fn () => $this->platform->accept($ref, $readyAt));
    }

    public function deny(string $ref, DenyReason $reason, ?string $note = null): void
    {
        $this->guard->call('deny', fn () => $this->platform->deny($ref, $reason, $note));
    }

    public function ready(string $ref): void
    {
        $this->guard->call('ready', fn () => $this->platform->ready($ref));
    }

    public function cancel(string $ref, DenyReason $reason, ?string $note = null): void
    {
        $this->guard->call('cancel', fn () => $this->platform->cancel($ref, $reason, $note));
    }
}
