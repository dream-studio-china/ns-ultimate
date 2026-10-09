<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Bundle230\Rector\Class_\AddAnnotationToRepositoryRector;
use Rector\Doctrine\TypedCollections\Rector\Class_\CompleteReturnDocblockFromToManyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withRules([
        AddAnnotationToRepositoryRector::class,
        CompleteReturnDocblockFromToManyRector::class,
    ])
    ->withCache(__DIR__ . '/var/cache/rector-types');
