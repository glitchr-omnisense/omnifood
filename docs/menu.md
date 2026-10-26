---
title: Menu
order: 31
---

# Menu

`MenuInterface`: `pushMenu(Menu $menu)` and `setAvailability($itemRef, $available, $until)`.
Uber Eats, Deliveroo and Just Eat implement it.

## The model

```php
use Omnifood\Model\Allergen;
use Omnifood\Model\Hours;
use Omnifood\Model\Menu\Category;
use Omnifood\Model\Menu\Item;
use Omnifood\Model\Menu\Menu;
use Omnifood\Model\Menu\Modifier;
use Omnifood\Model\Menu\ModifierGroup;
use Omnifood\Model\Money;

$extras = new ModifierGroup('extras', 'Extras', [
    new Modifier('egg', 'Egg', Money::of(150, 'EUR')),
    new Modifier('nori', 'Nori', Money::of(100, 'EUR')),
], min: 0, max: 2);

$menu = new Menu('Dinner', [
    new Category('mains', 'Mains', [
        new Item('ramen', 'Shoyu ramen', Money::of(1400, 'EUR'), 'Chicken broth', vatRate: 10.0,
            allergens: [Allergen::GLUTEN, Allergen::EGGS], photo: 'https://site.example/ramen.jpg',
            modifierGroups: [$extras]),
    ]),
], 'EUR', new Hours([1 => [['12:00', '14:30'], ['19:00', '22:30']]]), ref: 'dinner');
```

Every `ref` is the restaurant's own (its till's): the platforms carry it back on the orders
(`Line::$posRef`), and `setAvailability()` takes it. An item listed in two categories, or an option
offered in two groups, is the same thing under the same reference - with the same name and
price. Every field is described in [Concepts](concepts.md#menu).

## Labels

`Item::$labels` carries what the model has no field for. The packages read:

| Label | Uber Eats | Deliveroo | Just Eat |
|---|---|---|---|
| `vegan`, `vegetarian` | dietary label `VEGAN`, `VEGETARIAN` | `diets` | `dietary_restrictions` (`VEGAN` first, else `VEGETARIAN`) |
| `gluten_free` | `GLUTEN_FREE` | `diets` | - |
| `dairy_free`, `halal`, `keto`, `paleo`, `plant_based` | - | `diets` | - |
| `alcohol` | - | `contains_alcohol` | - |
| `no_allergens` | - | `allergies: [no_allergens]` (an empty list says "unknown" to Deliveroo) | - |
| a platform's own allergen codes | - | `gluten_wheat`, `nuts_almond`... | `CEREAL_WHEAT`, `NUTS_ALMONDS`... |

Omnifood's `GLUTEN` and `NUTS` are the regulation's families; Deliveroo and JET list each cereal
and each nut. An item with `Allergen::GLUTEN` declares every cereal of the family unless its labels
name the ones it holds (`gluten_wheat` at Deliveroo, `CEREAL_WHEAT` at JET); `NUTS` likewise. The
labels are compared in lower case at Uber Eats and Deliveroo, as written at Just Eat for the
allergen codes. A modifier has no labels.

## Checking it

```php
use Omnifood\Validator;

$violations = (new Validator())->validate($menu, $platform->capabilities());
foreach ($violations as $violation) {
    echo $violation, "\n";      // path: message
}
```

`Validator` checks, against the platform's [capabilities](concepts.md#capabilities):

- the menu: a name, a currency among `currencies` (when the platform lists any), at least one
  category; each category at least one item;
- the references: given (not blank), and one thing each - a category's is its own; an item's is
  shared with another item only with the same name and price; a modifier's likewise, among the
  modifiers, or among the items where `modifiersAreItems` (Uber Eats, Deliveroo: an option is an
  item of the menu); one group reference names one group;
- the names: not blank, at most `nameMaxLength` characters; descriptions at most
  `descriptionMaxLength`;
- the prices: in the menu's currency, not negative; VAT rates between 0 and 100;
- the modifier groups: not empty, `min` at least 0, `max` at least 1, `min` not above `max` nor
  above the number of options; nesting not deeper than `modifierDepth` (and none at all where it
  is 0).

What a platform ignores is not a violation (a photo where it takes none): only what it would
refuse. A menu with every mistake at once, checked against Deliveroo's capabilities:

```php
$extras = new ModifierGroup('extras', 'Extras', [
    new Modifier('egg', 'Egg', Money::of(150, 'EUR')),
    new Modifier('nori', 'Nori', Money::of(100, 'EUR')),
], min: 3, max: 2);

$menu = new Menu('Dinner', [
    new Category('mains', 'Mains', [
        new Item('ramen', 'Shoyu ramen', Money::of(1400, 'EUR'), 'Chicken broth', vatRate: 10.0,
            allergens: [Allergen::GLUTEN, Allergen::EGGS], modifierGroups: [$extras]),
        new Item('ramen', 'Miso ramen', Money::of(1500, 'EUR')),
        new Item('gyoza', str_repeat('Gyoza ', 25), Money::of(-100, 'USD'), vatRate: 120.0),
    ]),
    new Category('mains', 'Drinks', []),
], 'EUR');

$deliveroo = (new DeliverooPlatformFactory())->create();      // no key: nothing will be sent
foreach ((new Validator())->validate($menu, $deliveroo->capabilities()) as $violation) {
    echo $violation, "\n";
}
try {
    $deliveroo->pushMenu($menu);
} catch (InvalidMenuException $e) {
    echo count($e->violations), " violations, nothing sent ({$e->platform})\n";
}
```

```
categories[0].items[0].modifierGroups[0]: At least 3 but at most 2.
categories[0].items[1].ref: "ramen" is already categories[0].items[0], with another name or price.
categories[0].items[2].name: 150 characters, at most 120.
categories[0].items[2].price: In USD, the menu is in EUR.
categories[0].items[2].price: -1.00 USD: a price is not negative.
categories[0].items[2].vatRate: 120 %: a rate is between 0 and 100.
categories[1].ref: "mains" is already categories[0].
categories[1].items: "Drinks" has no item.

10 violations, nothing sent (deliveroo)
```

(run on 2026-10-26 in the [harness](harness.md))

`pushMenu()` runs the validator and throws `InvalidMenuException` (its `platform` and its
`violations`) before sending anything. Deliveroo adds three checks of its own, hence 10: a
category name of at least 3 characters, a VAT rate on every item (`items[<ref>].vatRate`), and a
picture for the menu (`photo`: the `mealtime_image` option, or an item's photo). The harness
checks a JSON menu without sending it: `menu:push <platform> <file> --validate`.

## Pushing it, and items out of stock

```php
$platform->pushMenu($menu);                                              // the platform's menu replaced
$platform->setAvailability('ramen', false, new \DateTimeImmutable('tomorrow 11:00'));
$platform->setAvailability('ramen', true);
```

`pushMenu()` replaces the platform's menu whole. `setAvailability()` takes an item's (or a
modifier's) reference.

| | Uber Eats | Deliveroo | Just Eat |
|---|---|---|---|
| Call | `PUT /v2/eats/stores/{store}/menus` | `PUT /menu/v1/brands/{brand}/menus/{menu_id}` | `POST /menus` |
| Options | items of the menu | items of the menu | items nested in the group |
| Allergens | free strings (the EU-14 names) | Deliveroo's list | JET's list (shown in DE, NL, AT, BE, BG, DK, LU, PL, SK) |
| VAT | rate per item (`vat_rate_percentage`, or `tax_rate` with `tax_inclusive: false`) | rate per item (required) | category: `NO_TAX` at 0, `STANDARD_RATE` at `standard_vat_rate` or above, `REDUCED_RATE` between |
| Hours | the menu's service hours | the menu's mealtime schedule | the menu's availability |
| `Item::$available` false | not sent: `setAvailability()` after the push | not sent: `setAvailability()` once the menu webhook says it is in | `out_of_stock` |
| Out of stock until | a date (a year from now when none) | `unavailable` until the next opening when a date is given, `hidden` when none | a date (`nextAvailableAt`), or until said |
| Texts | `{translations: {<language>: text}}` | `{<language>: text}` | as they are |

`Hours::$exceptions` of `Menu::$hours` are not sent by any platform: only the week.
