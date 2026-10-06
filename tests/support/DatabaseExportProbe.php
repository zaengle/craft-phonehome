<?php

namespace zaengle\phonehome\tests\support;

use zaengle\phonehome\services\DatabaseExport;

/**
 * Stands in for the table prefix, which needs a booted Craft application to read.
 */
class DatabaseExportProbe extends DatabaseExport
{
    public string $prefix = 'craft_';

    protected function tablePrefix(): string
    {
        return $this->prefix;
    }
}
