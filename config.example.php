<?php
/**
 * Phonehome plugin for Craft CMS 4.x
 *
 * @link      https://zaengle.com/
 * @copyright Copyright (c) 2025 Zaengle Corp
 *
 */

use craft\helpers\App;
return [
//    'token' => App::env('PHONEHOME_TOKEN') ?: null,
//    // Directory holding the npm package.json, if it is not at or above @root.
//    // Absolute, or relative to @root. Leave unset to search @root and up to three parents.
//    'npmPath' => 'frontend',
//
//    'additionalEnvKeys' => [
//        'MY_CUSTOM_ENV_KEY',
//    ],
//
//    // Queue Status Check Thresholds
//    'queueFailedWarningThreshold' => 3,
//    'queueFailedCriticalThreshold' => 6,
//    'queueDelayedWarningThreshold' => 20,
//    'queueDelayedCriticalThreshold' => 50,
//    'queuePendingWarningThreshold' => 20,
//    'queuePendingCriticalThreshold' => 50,
//
//    // Opt-in deployment verification. A runner reads these pages from the API and captures and
//    // asserts each one before and after a deployment. Paths are site-relative; the runner is told
//    // which origin to resolve them against separately. Leave unset to report verification as
//    // disabled. Any invalid page invalidates the whole manifest rather than silently reducing
//    // coverage, so a typo fails the run instead of shrinking it.
//    'verification' => [
//        'pages' => [
//            ['id' => 'home', 'path' => '/', 'assert' => ['visible' => '[data-testid="site-header"]']],
//            ['id' => 'contact', 'path' => '/contact', 'assert' => ['visible' => '[data-testid="contact-form"]']],
//            // Anything that legitimately differs between two runs -- a rotating testimonial, a
//            // relative date, a visitor counter -- has to be masked or it reports a change every
//            // time, and a check that cries wolf is a check nobody reads. A mask is coverage given
//            // up, so keep the list short and never mask the element being asserted on.
//            [
//                'id' => 'home',
//                'path' => '/',
//                'assert' => ['visible' => '[data-testid="site-header"]'],
//                'mask' => ['[data-testid="rotating-testimonial"]', '.published-ago'],
//            ],
//        ],
//    ],
//
//    // Tables whose DATA `craft db/backup` should leave out. The structure is still dumped, so an
//    // excluded table restores empty rather than missing, and the copy stays usable.
//    //
//    // ⚠️ Only exclude tables nothing renders from. Emptying `users` on a site whose templates
//    // print an entry author 500s every such page, and the exception names a field layout rather
//    // than the missing user, so it does not look like a backup problem.
//    'backupExcludeTables' => [
//        '{{%formie_submissions}}',
//        '{{%formie_sentnotifications}}',
//    ],
//
//    // Wildcard patterns, where * matches any run of characters. Form plugins spread submission
//    // data across per-form tables that nobody can enumerate reliably up front.
//    'backupExcludePatterns' => [
//        'freeform_submissions*',
//    ],
];
