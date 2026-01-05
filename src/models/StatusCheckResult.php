<?php

namespace zaengle\phonehome\models;

use craft\base\Model;
use zaengle\phonehome\enums\StatusCheck;

class StatusCheckResult extends Model
{
    public string $name;
    public StatusCheck $status;
    public ?string $description = null;
    public array $meta = [];

    public function rules(): array
    {
        return [
            [['name', 'status'], 'required'],
            [['description'], 'string'],
        ];
    }
}
