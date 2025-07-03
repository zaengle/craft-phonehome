<?php
namespace zaengle\phonehome\models;

use craft\base\Model;
use craft\helpers\App;
/**
 * PhoneHome Plugin Settings Model
 *
 * @since     1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================
    public ?string $token = null;
    public array $additionalEnvKeys = [];

    public function rules() : array
    {
        return [
            [['token'], 'required'],
        ];
    }

    public function getToken(): ?string
    {
        return App::parseEnv($this->token);
    }
}
