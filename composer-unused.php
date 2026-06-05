<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\ConfigurationSet\SymfonyConfigurationSet;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

return static function (Configuration $config): Configuration {
    $config->applyConfigurationSet(new SymfonyConfigurationSet('shopware/ucp-php-sdk'));

    foreach ([
        'php',
        'shopware/ucp-php-sdk-core',
        'ucp-php-sdk/symfony-bundle',
    ] as $package) {
        $config->addNamedFilter(NamedFilter::fromString($package));
    }

    return $config;
};
