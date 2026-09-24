import { test, expect, type Page, type TestInfo } from '@playwright/test';

const sizes = [
    { name: 'small-phone', width: 320, height: 568 },
    { name: 'phone-portrait', width: 390, height: 844 },
    { name: 'phone-landscape', width: 844, height: 390 },
    { name: 'tablet-portrait', width: 768, height: 1024 },
    { name: 'tablet-landscape', width: 1024, height: 768 },
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'desktop-portrait', width: 1080, height: 1920 },
] as const;

type Viewport = (typeof sizes)[number];

const representativeSizes = sizes.filter((size) =>
    ['small-phone', 'phone-landscape', 'tablet-landscape', 'desktop'].includes(size.name),
);
const screenshotSizes = new Set(['small-phone', 'phone-landscape', 'desktop']);

async function layout(page: Page, label: string) {
    await expect(page.locator('body')).not.toContainText('Internal Server Error');

    const dimensions = await page.evaluate(() => ({
        width: window.innerWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(dimensions.scroll, label + ': page must not scroll horizontally')
        .toBeLessThanOrEqual(dimensions.width + 1);

    const offenders = await page
        .locator('input:not([type=checkbox]):not([type=hidden]):visible,select:visible,textarea:visible')
        .evaluateAll((elements) => elements
            .filter((el) => {
                const rect = el.getBoundingClientRect();
                return rect.width > 0 && (rect.left < -1 || rect.right > innerWidth + 1);
            })
            .map((el) => el.outerHTML.slice(0, 180)));

    expect(offenders, label + ': visible form controls fit').toEqual([]);

    const clippedClocks = await page
        .locator('[role="timer"]:visible')
        .evaluateAll((elements) => elements
            .filter((el) => el.scrollWidth > el.clientWidth + 1)
            .map((el) => el.textContent));

    expect(clippedClocks, label + ': complete clock stays readable').toEqual([]);

    if (await page.evaluate(() => matchMedia('(pointer: coarse)').matches)) {
        const smallButtons = await page
            .locator('button:visible')
            .evaluateAll((elements) => elements
                .filter((el) => el.getBoundingClientRect().height < 43)
                .map((el) => el.textContent));

        expect(smallButtons, label + ': touch buttons have usable height').toEqual([]);
    }
}

async function atSizes(
    page: Page,
    label: string,
    viewports: readonly Viewport[],
    callback?: (size: Viewport) => Promise<void>,
) {
    for (const size of viewports) {
        await page.setViewportSize({ width: size.width, height: size.height });

        if (callback) {
            await callback(size);
        }

        await layout(page, size.name + ' ' + label);
    }
}

async function atEverySize(
    page: Page,
    label: string,
    callback?: (size: Viewport) => Promise<void>,
) {
    await atSizes(page, label, sizes, callback);
}

async function login(page: Page, email: string) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password-123');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login$/);
}

async function menu(page: Page) {
    const button = page.getByRole('button', { name: 'Menu', exact: true });

    if (await button.isVisible() && await button.getAttribute('aria-expanded') === 'false') {
        await button.click();
    }
}

async function accountMenu(page: Page) {
    await menu(page);
    const summary = page.locator('summary').filter({ hasText: 'Account' }).first();

    if (!(await summary.evaluate((el) => (el.parentElement as HTMLDetailsElement)?.open))) {
        await summary.click();
    }
}

async function logout(page: Page) {
    await page.setViewportSize({ width: 1440, height: 900 });
    await accountMenu(page);
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
}

async function screenshot(
    page: Page,
    info: TestInfo,
    size: Viewport,
    label: string,
) {
    if (!screenshotSizes.has(size.name)) {
        return;
    }

    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({
        path: info.outputPath('responsive-' + size.name + '-' + label + '.png'),
        fullPage: true,
    });
}

function collectPageErrors(page: Page) {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    return errors;
}

test.describe('responsive layouts', () => {
    test.describe.configure({ mode: 'parallel' });

    test('auth, navigation and core admin layouts', async ({ page }) => {
        test.setTimeout(90000);
        const errors = collectPageErrors(page);

        await page.goto('/login');
        await expect(page.locator('form')).toBeVisible();
        await atEverySize(page, '/login', async (size) => {
            await page.emulateMedia({
                colorScheme: size.name.includes('portrait') ? 'light' : 'dark',
            });
            const form = await page.locator('form').boundingBox();
            const toggle = await page.getByRole('button', { name: /Switch to .* mode/ }).boundingBox();
            expect(form, size.name + ': auth form should be visible').not.toBeNull();
            expect(toggle, size.name + ': theme switch should be visible').not.toBeNull();
            expect(form!.y, size.name + ': theme switch must not cover sign-in')
                .toBeGreaterThanOrEqual(toggle!.y + toggle!.height);
        });

        await page.goto('/reset-password/layout-preview?email=preview@example.test&welcome=1');
        await expect(page.locator('form')).toBeVisible();
        await atSizes(page, 'password setup', representativeSizes);

        await page.setViewportSize({ width: 1440, height: 900 });
        await login(page, 'admin@example.test');

        for (const path of ['/races/9001', '/races/9001/participants', '/users']) {
            await page.goto(path);
            await expect(page.locator('h1')).toBeVisible();
            await atEverySize(page, path);
        }

        await page.goto('/admin/roles');
        await expect(page.locator('h1')).toBeVisible();
        await atSizes(page, '/admin/roles', representativeSizes);

        await page.goto('/account/password');
        await expect(page.locator('h1')).toBeVisible();
        await atSizes(page, '/account/password', representativeSizes);

        await page.goto('/races');
        for (const size of sizes) {
            await page.setViewportSize({ width: size.width, height: size.height });
            const button = page.getByRole('button', { name: 'Menu', exact: true });

            if (await button.isVisible() && await button.getAttribute('aria-expanded') === 'false') {
                await button.click();
            }

            await expect(page.getByRole('link', { name: 'People', exact: true })).toBeVisible();
            await layout(page, size.name + ' open menu');

            if (await button.isVisible() && await button.getAttribute('aria-expanded') === 'true') {
                await button.click();
            }
        }

        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/races/9002');
        await page.locator('summary').filter({ hasText: 'More tools' }).click();
        await page.getByRole('button', { name: 'Delete race', exact: true }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await atSizes(page, 'delete race dialog', representativeSizes, async (size) => {
            const box = await dialog.boundingBox();
            expect(box, size.name + ': dialog should be visible').not.toBeNull();
            expect(box!.height).toBeLessThanOrEqual(size.height - 16);
            expect(box!.width).toBeLessThanOrEqual(size.width - 16);
        });

        expect(errors).toEqual([]);
    });

    test('timing, results and complex form states', async ({ page }, info) => {
        test.setTimeout(90000);
        const errors = collectPageErrors(page);

        await page.setViewportSize({ width: 1440, height: 900 });
        await login(page, 'admin@example.test');

        await page.goto('/races/9001/station');
        if (await page.getByRole('button', { name: 'Open checkpoint' }).isVisible()) {
            await page.getByRole('button', { name: 'Open checkpoint' }).click();
        }

        await page.getByLabel('Find participant').fill('TheLongestRelay');
        await expect(page.getByRole('button', { name: /TheLongestRelay.*TAP/ })).toBeVisible();
        await atEverySize(page, 'selected timing station', async (size) => {
            await screenshot(page, info, size, 'station');
        });

        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/races/9001/results');
        await page.getByText('Display options', { exact: true }).click();
        await page.getByLabel('Timing precision').selectOption('3');
        await page.getByRole('button', { name: 'View splits', exact: true }).first().click();
        await expect(
            page.getByText('split 00:16:40.234', { exact: true }).filter({ visible: true }),
        ).toBeVisible();

        await atEverySize(page, 'expanded results', async (size) => {
            await screenshot(page, info, size, 'results');
        });

        await page.getByRole('button', { name: 'Large-screen display' }).click();
        await atSizes(page, 'large-screen results', representativeSizes);
        await page.getByRole('button', { name: 'Exit display mode' }).click();

        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/users/9001/edit');
        await expect(page.getByRole('heading', { name: 'Edit person & access', exact: true })).toBeVisible();
        await atSizes(page, 'person access editor', representativeSizes);

        await page.goto('/races/9002/participants/import');
        await page.getByLabel('Participant file').setInputFiles({
            name: 'long-participant-import-preview.csv',
            mimeType: 'text/csv',
            buffer: Buffer.from(
                'type,first_name,last_name\nsolo,' + 'LongName'.repeat(10) + ',Runner\n',
            ),
        });
        await page.getByRole('button', { name: 'Preview file', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Confirm import' })).toBeVisible();
        await atSizes(page, 'import preview', representativeSizes);

        expect(errors).toEqual([]);
    });

    test('official, athlete and public layouts', async ({ page }) => {
        test.setTimeout(90000);
        const errors = collectPageErrors(page);

        await page.setViewportSize({ width: 1440, height: 900 });
        await login(page, 'responsive-official@example.test');

        for (const path of ['/races/9001/station', '/races/9001/results']) {
            await page.goto(path);
            await atSizes(page, 'official ' + path, representativeSizes);
            await expect(page.getByRole('link', { name: 'Corrections & station health', exact: true }))
                .toHaveCount(0);
            await expect(page.getByRole('link', { name: 'Export CSV', exact: true }))
                .toHaveCount(0);
        }

        await logout(page);
        await login(page, 'responsive-athlete@example.test');

        for (const path of ['/athlete', '/races/9001/results']) {
            await page.goto(path);
            if (path === '/athlete') {
                await expect(page.locator('h1')).toContainText('My races');
            }
            await atSizes(page, 'athlete ' + path, representativeSizes);
        }

        await logout(page);
        await page.goto('/live/responsive-public');
        await expect(page.locator('h1')).toContainText('Results');
        await atEverySize(page, 'public results');

        await page.goto('/race/responsive-timing');
        await expect(page.locator('h1')).toContainText('TriathlonChampionship');
        await expect(page.getByLabel('1. Checkpoint', { exact: true })).toBeVisible();
        await atEverySize(page, 'public race-day timing');

        expect(errors).toEqual([]);
    });
});
