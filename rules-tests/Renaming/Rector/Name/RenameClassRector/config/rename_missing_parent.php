<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->removeUnusedImports();

    $rectorConfig
        ->ruleWithConfiguration(RenameClassRector::class, [
            'Missing\\ParentClass' => 'Renamed\\Missing\\ParentWithMethod',
        ]);
};
