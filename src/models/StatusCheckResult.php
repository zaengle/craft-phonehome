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

    /**
     * Returns the list of fields that should be returned by default by toArray().
     * Ensures the status enum is serialized as its string value.
     *
     * @return array
     */
    public function fields(): array
    {
        $fields = parent::fields();
        // Ensure the status enum is serialized as its string value
        $fields['status'] = fn() => $this->status->value;
        return $fields;
    }
}
