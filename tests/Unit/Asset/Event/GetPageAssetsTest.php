<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\Event\GetPageAssets;
use Piwigo\PluginConfig\EventDispatcher;

test('defaults to an empty asset list', function (): void {
    $event = new GetPageAssets();

    expect($event->assets)
        ->toBe([]);
});

test('a listener can append contributions and the dispatcher returns the mutated event', function (): void {
    $dispatcher = new EventDispatcher();
    $contribution = AssetContribution::script('my-plugin', 'plugins/my-plugin/script.js');

    $dispatcher->addTypedHandler(GetPageAssets::class, function (GetPageAssets $event) use ($contribution): GetPageAssets {
        $event->assets = [...$event->assets, $contribution];

        return $event;
    });

    $result = $dispatcher->dispatch(new GetPageAssets());

    expect($result->assets)
        ->toBe([$contribution]);
});
