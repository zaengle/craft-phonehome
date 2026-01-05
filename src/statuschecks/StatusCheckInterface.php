<?php

namespace zaengle\phonehome\statuschecks;

use zaengle\phonehome\models\StatusCheckResult;

interface StatusCheckInterface
{
    public static function getHandle(): string;
    public static function check(): StatusCheckResult;
}
