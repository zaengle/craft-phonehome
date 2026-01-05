<?php

namespace zaengle\phonehome\models;

use craft\base\Model;
use zaengle\phonehome\enums\StatusCheck;

class StatusCheckResult extends Model
{
    public string $handle;
    public StatusCheck $status;
    public array $meta = [];

    public function rules(): array
    {
        return [
            [['handle', 'status'], 'required'],
        ];
    }
}
