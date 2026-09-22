<?php

namespace zaengle\phonehome\enums;

enum NpmStatus: string
{
    /** The manifest was read, and a package-lock.json was read and parsed. */
    case OK = 'ok';

    /** There is no package.json, so the site genuinely has no npm dependencies. */
    case NO_MANIFEST = 'no_manifest';

    /** A package.json is present, but it could not be read or parsed. */
    case UNREADABLE_MANIFEST = 'unreadable_manifest';

    /** The manifest was read, but no lockfile of any kind was found. */
    case NO_LOCKFILE = 'no_lockfile';

    /** The manifest was read, and a yarn.lock or pnpm-lock.yaml was found but is not parsed. */
    case UNSUPPORTED_LOCKFILE = 'unsupported_lockfile';

    /** The manifest was read, but the package-lock.json present could not be parsed. */
    case UNREADABLE_LOCKFILE = 'unreadable_lockfile';
}
