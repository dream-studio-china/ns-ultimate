<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        codeQuality: true,
        typeDeclarationDocblocks: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withCache(__DIR__ . '/var/cache/rector');
