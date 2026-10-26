---
title: Orders
order: 30
---

# Orders

`OrdersInterface`: `order($ref)`, `orders($since)`, `accept($ref, $readyAt)`,
`deny($ref, $reason, $note)`, `ready($ref)`, `cancel($ref, $reason, $note)`. `$ref` is always
the platform's id for the order (`Order::$reference`).

## The cycle

1. A webhook says an order arrived ([webhooks](webhooks.md)); `Notification::$order` carries it
   when the platform sends it whole (Deliveroo, Just Eat), `order($notification->reference)` reads
   it otherwise (Uber Eats).
2. The kitchen accepts or denies it within the platform's delay - `capabilities()->acceptanceDelay`,
   in seconds: 690 at Uber Eats, 600 at Deliveroo - or the platform cancels it. Just Eat's orders
   are accepted on its own tablet before they are pushed: there `accept()` and `deny()` say
   whether the order reached the till.
3. `ready()` when it is ready for the courier or the customer.
4. The courier's progress and a cancellation by the platform arrive as webhooks
   (`NotificationSubject::COURIER`, `OrderStatus::CANCELLED`).

```php
use Omnifood\Model\DenyReason;
use Omnifood\OrdersInterface;

/** @var OrdersInterface $platform */
$order = $platform->order($ref);
foreach ($order->lines as $line) {
    echo $line->quantity, ' x ', $line->name, ' (', $line->posRef, ')';
    foreach ($line->modifiers as $modifier) {
        echo ' + ', $modifier->name;
    }
    echo "\n";
}

if ($kitchenCanDoIt) {
    $platform->accept($order->reference, new \DateTimeImmutable('+15 minutes'));
} else {
    $platform->deny($order->reference, DenyReason::TOO_BUSY, 'Kitchen full until 21:00');
}
```

`$readyAt` is sent where the platform takes it (`capabilities()->readyAt`: Uber Eats only); it is
ignored elsewhere. `Line::$posRef` is the restaurant's reference when the platform carries it back
(the menu's `Item::$ref`, pushed as Uber's item id, Deliveroo's `pos_item_id`, JET's `plu`).

## Listing orders

`orders(?\DateTimeImmutable $since = null)` gives the orders placed since then, newest first,
every page read. With no date, the last 24 hours. Just Eat lists none (`NotSupportedException`):
its orders arrive only by webhook, and the application keeps them.

| | Uber Eats | Deliveroo |
|---|---|---|
| Call | `GET /v1/delivery/store/{store_id}/orders`, pages of 50 (`next_page_token`) | `GET /order/v2/brand/{brand}/restaurant/{site}/orders` (`cursor`) |
| `since` sent as | `start_time`, RFC 3339 | `start_date`, UTC `Y-m-d\TH:i:s\Z` |
| How far back the platform keeps them | 60 days | 30 days |

## Deny reasons

`DenyReason` is Omnifood's; each package maps it to its platform's codes:

| `DenyReason` | Uber Eats (`deny_reason.type`, `cancellation_reason.type`) | Deliveroo, no tablet (`reject_reason`) | Deliveroo, tablet (sync `reason`) | Just Eat (`errorCode`) |
|---|---|---|---|---|
| `ITEM_UNAVAILABLE` | `ITEM_ISSUE` | `ingredient_unavailable` | `items_out_of_stock` | `MENU_ERROR` |
| `CLOSED` | `STORE_CLOSED` | `closing_early` | `location_offline` | `STORE_CLOSED` |
| `TOO_BUSY` | `RESTAURANT_TOO_BUSY` | `busy` | `other` | `UNKNOWN` |
| `CUSTOMER_REQUEST` | `CUSTOMER_CALLED_TO_CANCEL` | `other` | `other` | `UNKNOWN` |
| `ADDRESS` | `ADDRESS` | `other` | `other` | `UNKNOWN` |
| `PRICING` | `PRICING` | `other` | `price_mismatched` | `MENU_ERROR` |
| `SPECIAL_INSTRUCTIONS` | `SPECIAL_INSTRUCTIONS` | `other` | `other` | `UNKNOWN` |
| `TECHNICAL` | `TECHNICAL_FAILURE` | `other` | `other` | `UNKNOWN` |
| `OTHER` | `OTHER` | `other` | `other` | `UNKNOWN` |

The note goes as Uber's `info`, Deliveroo's `notes`, JET's `errorMessage` (the reason's value
when there is no note).

## Statuses

How each platform's word becomes an `OrderStatus` (the platform's own stays in `Order::$raw`):

| `OrderStatus` | Uber Eats (`state`) | Deliveroo (`status`, `prep_stages`) | Just Eat |
|---|---|---|---|
| `NEW` | `CREATED`, `OFFERED` | `pending`, `placed` | every order pushed |
| `ACCEPTED` | `ACCEPTED` | `accepted`, `confirmed` | - |
| `READY` | `ACCEPTED` with `preparation_status` `READY_FOR_HANDOFF` | accepted, with the stage `ready_for_collection` | - |
| `PICKED_UP` | `HANDED_OFF` | accepted, with the stage `collected` | driver `onItsWay` (webhook) |
| `DELIVERED` | `SUCCEEDED` | - | driver `delivered` (webhook) |
| `DENIED` | `FAILED` with `failure_info.reason` `POS_DENIED` | `rejected` | - |
| `CANCELLED` | `FAILED` otherwise | `canceled` | Cancel Order webhook |
| `UNKNOWN` | anything else | anything else | - |

## What each platform does

| | Uber Eats | Deliveroo | Just Eat |
|---|---|---|---|
| `order()` | yes | yes | refused (pushed only) |
| `orders()` | yes (60 days) | yes (30 days) | refused |
| `accept()` | yes, with a ready time | yes (tablet sites: sync status `succeeded`) | the till's confirmation after a 202 (`sent-to-pos-success`) |
| `deny()` | yes | yes (tablet sites: sync status `failed`) | to the tablet's backup flow (`sent-to-pos-failed`) |
| `ready()` | yes | prep stage `ready_for_collection` | refused |
| `cancel()` | yes | refused (Deliveroo's customer service cancels) | refused (done on the tablet) |

And beyond the interface, on the platform's own class:

- `UberEatsPlatform::updateReadyTime($ref, $readyAt)`: a new time the order will be ready
  (accepted, not ready yet, the courier not on the way).
- `DeliverooPlatform::prepStage($ref, $stage, $delay)`: `in_kitchen` (with a delay of 0, 2, 4, 6,
  8 or 10 minutes), `ready_for_collection_soon`, `ready_for_collection`, `collected`.
- `DeliverooPlatform::syncStatus($ref, $succeeded, $reason, $notes)`: for a tablet site, whether
  the order reached the till.

Each platform's manual has the endpoints and every field read.
