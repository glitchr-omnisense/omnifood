<?php

namespace Omnifood\Policy;

use Omnifood\MenuInterface;
use Omnifood\Model\Menu\Menu;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * A platform's menu behind its guard: "pushMenu", "setAvailability".
 *
 * @extends Guarded<MenuInterface>
 */
final class GuardedMenu extends Guarded implements MenuInterface
{
    public function __construct(MenuInterface $platform, PolicyGuard $guard)
    {
        parent::__construct($platform, $guard);
    }

    public function pushMenu(Menu $menu): void
    {
        $this->guard->call('pushMenu', fn () => $this->platform->pushMenu($menu));
    }

    public function setAvailability(string $itemRef, bool $available, ?\DateTimeImmutable $until = null): void
    {
        $this->guard->call('setAvailability', fn () => $this->platform->setAvailability($itemRef, $available, $until));
    }
}
