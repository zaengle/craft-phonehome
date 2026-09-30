import { expect, test } from '@playwright/test';
import { describeEnvironmentDelta, readPackages } from '../../src/manifest';

/**
 * The environment delta is what lets a comparison say whether the change it was asked to verify
 * actually reached the site. The distinction that matters most is unknown versus unchanged: a
 * baseline that predates package snapshots must not read as "nothing moved".
 */
test.describe('readPackages', () => {
    test('keys Craft by its Composer name and plugins by handle', () => {
        const packages = readPackages({
            craft_version: '5.8.14',
            plugins: {
                formie: { name: 'Formie', version: '3.0.1' },
                seomatic: { name: 'SEOmatic', version: '5.1.0' },
            },
        });

        expect(packages).toEqual({ 'craftcms/cms': '5.8.14', formie: '3.0.1', seomatic: '5.1.0' });
    });

    test('ignores what it cannot read rather than guessing', () => {
        const packages = readPackages({
            craft_version: 5,
            plugins: { broken: null, unversioned: { name: 'x' }, ok: { version: '1.0.0' } },
        });

        expect(packages).toEqual({ ok: '1.0.0' });
    });

    test('a payload with no plugins still records Craft', () => {
        expect(readPackages({ craft_version: '5.8.14', plugins: [] })).toEqual({ 'craftcms/cms': '5.8.14' });
    });
});

test.describe('describeEnvironmentDelta', () => {
    test('a baseline without package versions is unknown, not unchanged', () => {
        const delta = describeEnvironmentDelta(undefined, { 'craftcms/cms': '5.8.14' });

        expect(delta.known).toBe(false);
        expect(delta.changed).toEqual([]);
    });

    test('identical versions are a known, empty delta', () => {
        const versions = { 'craftcms/cms': '5.8.14', formie: '3.0.1' };

        expect(describeEnvironmentDelta(versions, { ...versions })).toEqual({ known: true, changed: [] });
    });

    test('names every package that moved, was added or was removed, in a stable order', () => {
        const delta = describeEnvironmentDelta(
            { 'craftcms/cms': '5.8.14', formie: '3.0.1', retired: '1.0.0' },
            { 'craftcms/cms': '5.8.15', formie: '3.0.1', added: '0.1.0' },
        );

        expect(delta).toEqual({
            known: true,
            changed: [
                { name: 'added', before: null, after: '0.1.0' },
                { name: 'craftcms/cms', before: '5.8.14', after: '5.8.15' },
                { name: 'retired', before: '1.0.0', after: null },
            ],
        });
    });
});
