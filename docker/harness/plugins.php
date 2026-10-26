<?php

/**
 * Every platform package: its slug (omnifood/<slug>, github.com/glitchr-omnisense/omnifood-<slug>),
 * its tests' namespace and its factory class.
 */
return [
    'ubereats' => ['Omnifood\\UberEats\\Tests\\', 'Omnifood\\UberEats\\UberEatsPlatformFactory'],
    'deliveroo' => ['Omnifood\\Deliveroo\\Tests\\', 'Omnifood\\Deliveroo\\DeliverooPlatformFactory'],
    'justeat' => ['Omnifood\\JustEat\\Tests\\', 'Omnifood\\JustEat\\JustEatPlatformFactory'],
    'thefork' => ['Omnifood\\TheFork\\Tests\\', 'Omnifood\\TheFork\\TheForkPlatformFactory'],
    'zenchef' => ['Omnifood\\Zenchef\\Tests\\', 'Omnifood\\Zenchef\\ZenchefPlatformFactory'],
];
