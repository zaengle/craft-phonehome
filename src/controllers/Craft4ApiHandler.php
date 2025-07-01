<?php
namespace zaengle\phonehome\controllers;

use Craft;

class Craft4ApiHandler extends BaseApiHandler
{
    protected function getUpdatesInfo(): array
    {
        $updates = [];

        try {
            $updatesService = Craft::$app->getUpdates();

            if (isset($updatesService) && method_exists($updatesService, 'getUpdates')) {
                $availableUpdates = $updatesService->getUpdates(true); // true to include critical updates

                if (is_array($availableUpdates)) {
                    foreach ($availableUpdates as $update) {
                        $updates[] = [
                            'name' => $update->name ?? 'Unknown',
                            'version' => $update->version ?? 'Unknown',
                            'package' => $update->package ?? 'Unknown',
                            'critical' => isset($update->critical) ? (bool)$update->critical : false,
                            'release_date' => $update->date ?? null,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Log the error but don't expose it in the response
            Craft::error('Error getting Craft 4 updates: ' . $e->getMessage(), __METHOD__);
        }

        return $updates;
    }
}
