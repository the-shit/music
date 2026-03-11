<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameVariableToMatchNewTypeRector;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchExprVariableRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ]);

    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::NAMING,
        SetList::STRICT_BOOLEANS,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
        PHPUnitSetList::PHPUNIT_100,
    ]);

    $rectorConfig->skip([
        __DIR__.'/tests/TestCase.php',
        // Skip aggressive renames — existing variable names are intentional
        RenameVariableToMatchMethodCallReturnTypeRector::class,
        RenameParamToMatchTypeRector::class,
        RenameForeachValueVariableToMatchExprVariableRector::class,
        RenameVariableToMatchNewTypeRector::class,
        RenamePropertyToMatchTypeRector::class,
    ]);
};
