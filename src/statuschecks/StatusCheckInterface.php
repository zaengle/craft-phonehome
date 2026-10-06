<?php

namespace zaengle\phonehome\statuschecks;

use zaengle\phonehome\models\StatusCheckResult;

interface StatusCheckInterface
{
    public static function getName(): string;
    public static function getDescription(): string;
    public static function check(): StatusCheckResult;
}
