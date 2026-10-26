---
title: Webhooks
order: 34
---

# Webhooks

`NotifiableInterface::notify(string $body, array $headers): Notification` checks a webhook - a
signature, a key, a token - and reads it. It throws `InvalidSignatureException` when the check
fails, or when the body is not JSON: answer 401 and do nothing. It throws
`InvalidConfigException` when the secret it checks with is not configured.

```php
// webhook.php, in plain PHP; $ubereats from the registry
try {
    $notification = $ubereats->notify((string) file_get_contents('php://input'), getallheaders());
} catch (Omnifood\Exception\InvalidSignatureException) {
    http_response_code(401);
    exit;
}
$queue->push($notification->channel->value, $notification->id, $notification->reference);   // the application's queue
http_response_code(200);
```

The body is the raw one, as received; the headers an array, names in any case, values a string
or a list of strings (the first is read), as the request has them. In a Symfony controller:
`$request->getContent()` and `$request->headers->all()` ([Symfony](symfony.md#a-webhook)).

Answer fast and work later (a queue, Messenger): the platforms retry when the answer is slow or
not 2xx, and send the same event more than once, out of order. `Notification::$id` is the
platform's event id, or one composed from the order and its status (the same on a retry): keep the
ids handled, and handle each once.

A Uber Eats notification, checked and read (run on 2026-10-26 in the [harness](harness.md)):

```php
$body = '{"event_id":"e-1","event_type":"orders.notification","event_time":1759579200,"meta":{"resource_id":"o-1","user_id":"store-1"}}';
$n = $ubereats->notify($body, ['X-Uber-Signature' => hash_hmac('sha256', $body, $clientSecret)]);
echo $n->event, ' ', $n->subject->value, ' ', $n->reference, ' ', $n->id, ' ', $n->status->value, ' ', $n->occurredAt->format(DATE_ATOM), ' ', $n->store, "\n";
```

```
orders.notification order o-1 e-1 new 2025-10-04T12:00:00+00:00 store-1
```

and with a wrong signature, `InvalidSignatureException`:
`[ubereats] The X-Uber-Signature signature does not match the body.`

The `Notification`'s fields are in [Concepts](concepts.md#notifications).

## What each platform checks

| | Check | Configured by | Carries |
|---|---|---|---|
| Uber Eats | `X-Uber-Signature`: lowercase hex HMAC-SHA256 of the body | `client_secret` | the event and the order's id: `order($n->reference)` |
| Deliveroo | `X-Deliveroo-Hmac-Sha256`: hex HMAC-SHA256 of `X-Deliveroo-Sequence-Guid`, a space and the body | `webhook_secret` | the whole order; the rider's status; the menu's upload result |
| Just Eat | `Authorization` equal to the key given to JET; `X-JET-Connect-Hash` (`signature=` base64 HMAC-SHA256 of the body) | `webhook_key`, `webhook_secret` (one at least) | the whole order; cancellations, the driver, the restaurant set offline, the backup flow |
| TheFork | the token put in the URL given to TheFork, passed among the headers as `token` | `webhook_token` | the entity and its id: `reservation($n->reference)` |
| Zenchef | refused: not public | - | - |

> [!WARNING]
> Known defect: with only `webhook_secret` set for Just Eat, the hash is checked on order bodies
> and on bodies that carry an `X-JET-Connect-Hash` header; a cancellation, driver, offline or
> backup-flow body sent without that header is accepted unchecked, where every webhook should be
> authenticated. Set `webhook_key` too.

## Events, ids and statuses

| | `event` | `subject` | `id` | `status` |
|---|---|---|---|---|
| Uber Eats | Uber's `event_type` | `orders.*` order, `delivery.*` courier, `store.menu*` menu, `store.*` store | Uber's `event_id` | `orders.notification`, `orders.scheduled.notification`: `NEW`; `orders.cancel`, `orders.failure`: `CANCELLED` |
| Deliveroo | Deliveroo's `event` | an order body: order; `rider.status_update`: courier; `menu.upload_result`: menu | an order: `<id>:<status>`; else the sequence GUID | the order's status; a rider `rider_in_transit`: `PICKED_UP` |
| Just Eat | `order.received`, `order.cancelled`, `driver.<code>`, `restaurant.temp_offline`, `order.backup_flow`, `unknown` | order, courier or store | `<order>:received[:<transmission_id>]`, `<order>:cancelled`, `<order>:driver:<code>`, `<restaurant>:offline:<time>`, `<order>:backup` | `NEW`, `CANCELLED`, driver `onItsWay` `PICKED_UP`, `delivered` `DELIVERED` |
| TheFork | TheFork's `eventType` | `entityType` reservation: reservation; else other | `null`: TheFork gives none | `null` |

## Answering

- Uber Eats: 200, empty.
- Deliveroo: 200.
- Just Eat's Receive Order is answered `{"OrderId": "..."}` with 200 (taken in) or 202 (taken in
  later: then `accept()` or `deny()` within 5 minutes); the other webhooks 200 with the same body.
  A 500 makes JET retry, up to 5 times.
- TheFork: 200 `{"data": {}}` within a few seconds.
