<?php
namespace zaengle\phonehome\controllers;

use Craft;

class Craft5ApiHandler extends BaseApiHandler
{
    /**
     * Get information about available updates for Craft 5
     */
    protected function getUpdatesInfo(): array
    {
        $updates = [];

        try {
            Craft::info('Starting Craft 5 update check...', __METHOD__);

            $updatesService = Craft::$app->getUpdates();

            // First, let's try to get detailed updates through the getTotalAvailableUpdates method
            if (method_exists($updatesService, 'getTotalAvailableUpdates') &&
                $updatesService->getTotalAvailableUpdates(true) > 0) {

                // Try to get the actual updates model
                try {
                    $updatesModel = $updatesService->getUpdates(true);
                    Craft::info('Got updates model: ' . json_encode($updatesModel), __METHOD__);

                    // Extract CMS updates
                    if (isset($updatesModel->cms) && !empty($updatesModel->cms->releases)) {
                        foreach ($updatesModel->cms->releases as $release) {
                            $updates[] = [
                                'name' => 'Craft CMS',
                                'version' => $release->version ?? 'Unknown',
                                'package' => 'craftcms/cms',
                                'critical' => !empty($release->critical),
                                'release_date' => $release->date ?? null,
                                'notes' => $release->notes ?? null,
                            ];
                        }

                        Craft::info('Added ' . count($updatesModel->cms->releases) . ' Craft CMS updates', __METHOD__);
                    }

                    // Extract plugin updates
                    if (isset($updatesModel->plugins)) {
                        foreach ($updatesModel->plugins as $pluginHandle => $pluginData) {
                            if (!empty($pluginData->releases)) {
                                foreach ($pluginData->releases as $release) {
                                    $updates[] = [
                                        'name' => $pluginData->name ?? $pluginHandle,
                                        'version' => $release->version ?? 'Unknown',
                                        'package' => $pluginData->handle ?? $pluginHandle,
                                        'critical' => !empty($release->critical),
                                        'release_date' => $release->date ?? null,
                                        'notes' => $release->notes ?? null,
                                    ];
                                }

                                Craft::info("Added " . count($pluginData->releases) . " updates for plugin $pluginHandle", __METHOD__);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Craft::warning('Error extracting detailed update info: ' . $e->getMessage(), __METHOD__);
                }
            }

            // If we couldn't get detailed updates, fall back to our existing methods
            if (empty($updates)) {
                Craft::info('No detailed updates found, using fallback methods', __METHOD__);

                // Check for pending updates
                if (method_exists($updatesService, 'getIsUpdatePending') && $updatesService->getIsUpdatePending()) {
                    // Check if we can get more specific information
                    $hasCraftUpdate = method_exists($updatesService, 'getIsCraftUpdatePending') &&
                                      $updatesService->getIsCraftUpdatePending();

                    $hasPluginUpdate = method_exists($updatesService, 'getIsPluginUpdatePending') &&
                                       $updatesService->getIsPluginUpdatePending();

                    $isCriticalUpdate = method_exists($updatesService, 'getIsCriticalUpdateAvailable') &&
                                        $updatesService->getIsCriticalUpdateAvailable(true);

                    // Add Craft CMS update if needed
                    if ($hasCraftUpdate) {
                        $updates[] = [
                            'name' => 'Craft CMS',
                            'version' => 'Update Available',
                            'package' => 'craftcms/cms',
                            'critical' => $isCriticalUpdate,
                            'release_date' => null,
                        ];
                    }

                    // Try to get specific plugin update information
                    if ($hasPluginUpdate && method_exists($updatesService, 'getPendingMigrationHandles')) {
                        $pendingHandles = $updatesService->getPendingMigrationHandles();

                        foreach ($pendingHandles as $handle) {
                            if ($handle !== 'craft' && $handle !== 'content') {
                                try {
                                    $plugin = Craft::$app->getPlugins()->getPlugin($handle);
                                    if ($plugin) {
                                        $updates[] = [
                                            'name' => $plugin->name,
                                            'version' => 'Update Available',
                                            'package' => $handle,
                                            'critical' => false,
                                            'release_date' => null,
                                        ];
                                    }
                                } catch (\Throwable $e) {
                                    Craft::warning("Could not get plugin info for $handle: " . $e->getMessage(), __METHOD__);
                                }
                            }
                        }
                    }

                    // If we still don't have any specific updates, add a generic entry
                    if (empty($updates)) {
                        $total = $updatesService->getTotalAvailableUpdates(true);
                        $updates[] = [
                            'name' => 'Craft CMS or Plugins',
                            'version' => $total > 0 ? "$total Update(s) Available" : 'Updates Available',
                            'package' => 'unknown',
                            'critical' => $isCriticalUpdate,
                            'release_date' => null,
                        ];
                    }
                }
            }

            Craft::info('Total updates found: ' . count($updates), __METHOD__);

        } catch (\Exception $e) {
            Craft::error('Error getting Craft 5 updates: ' . $e->getMessage(), __METHOD__);
        }

        return $updates;
    }
}
