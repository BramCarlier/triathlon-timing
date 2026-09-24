import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test.beforeAll(() => {
    execFileSync('php', ['tests/Browser/reset-cache.php']);
});

test.afterAll(() => {
    execFileSync('php', ['tests/Browser/reset-cache.php']);
});

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
        .locator('input:not([type=checkbox]):not([type=hidden]),select,textarea')
        .evaluateAll((elements) => elements
            .filter((el) => el.getClientRects().length && el.getBoundingClientRect().width > 0)
            .filter((el) => {
                const rect = el.getBoundingClientRect();
                return rect.left < -1 || rect.right > innerWidth + 1;
            })
            .map((el) => el.outerHTML.slice(0, 180)));

    expect(offenders, label + ': form controls fit').toEqual([]);

    const clippedClocks = await page
        .getByRole('timer')
        .evaluateAll((elements) => elements
            .filter((el) => el.scrollWidth > el.clientWidth + 1)
            .map((el) => el.textContent));

    expect(clippedClocks, label + ': complete clock stays readable').toEqual([]);

    if (await page.evaluate(() => matchMedia('(pointer: coarse)').matches)) {
        const smallButtons = await page
            .getByRole('button')
            .evaluateAll((elements) => elements
                .filter((el) => el.getClientRects().length && el.getBoundingClientRect().height < 43)
                .map((el) => el.textContent));

        expect(smallButtons, label + ': touch buttons have usable height').toEqual([]);
    }
}

async function atEverySize(
    page: Page,
    label: string,
    callback?: (size: Viewport) => Promise<void>,
) {
    for (const size of sizes) {
        await page.setViewportSize({ width: size.width, height: size.height });
        await page.emulateMedia({
            colorScheme: size.name.includes('portrait') ? 'light' : 'dark',
        });

        if (callback) {
            await callback(size);
        }

        await layout(page, size.name + ' ' + label);
    }
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

test('reachable layouts adapt across portrait and landscape without reloading every viewport', async ({ page }, info) => {
    test.setTimeout(180000);

    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    for (const path of [
        '/login',
        '/forgot-password',
        '/reset-password/layout-preview?email=preview@example.test&welcome=1',
    ]) {
        await page.goto(path);
        await expect(page.locator('form')).toBeVisible();

        await atEverySize(page, path, async (size) => {
            const form = await page.locator('form').boundingBox();
            const toggle = await page.getByRole('button', { name: /Switch to .* mode/ }).boundingBox();

            expect(form, size.name + ': auth form should be visible').not.toBeNull();
            expect(toggle, size.name + ': theme switch should be visible').not.toBeNull();
            expect(form!.y, size.name + ': theme switch must not cover sign-in')
                .toBeGreaterThanOrEqual(toggle!.y + toggle!.height);
        });
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, 'admin@example.test');

    const adminPaths = [
        '/races',
        '/races/create',
        '/guide',
        '/races/9001',
        '/races/9002',
        '/races/9003',
        '/races/9001/participants',
        '/races/9001/participants/9001/edit',
        '/races/9002/participants/import',
        '/races/9001/control',
        '/races/9002/control',
        '/races/9003/control',
        '/races/9001/results',
        '/users',
        '/users/9001/edit',
        '/admin/roles',
        '/admin/health',
        '/account/password',
    ];

    const screenshotPaths = new Set([
        '/races/9001/participants',
        '/races/9001/control',
        '/admin/roles',
    ]);

    for (const path of adminPaths) {
        await page.goto(path);
        await expect(page.locator('h1')).toBeVisible();

        await atEverySize(page, path, async (size) => {
            if (screenshotPaths.has(path)) {
                const label = path.split('/').filter(Boolean).join('-');
                await screenshot(page, info, size, label);
            }
        });
    }

    await page.goto('/races');
    for (const size of sizes) {
        await page.setViewportSize({ width: size.width, height: size.height });
        await page.emulateMedia({
            colorScheme: size.name.includes('portrait') ? 'light' : 'dark',
        });

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

    await atEverySize(page, 'delete race dialog', async (size) => {
        const box = await dialog.boundingBox();
        expect(box, size.name + ': dialog should be visible').not.toBeNull();
        expect(box!.height).toBeLessThanOrEqual(size.height - 16);
        expect(box!.width).toBeLessThanOrEqual(size.width - 16);
    });

    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(dialog).toHaveCount(0);

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/races/9001/station');
    if (await page.getByRole('button', { name: 'Open checkpoint' }).isVisible()) {
        await page.getByRole('button', { name: 'Open checkpoint' }).click();
    }

    await expect(page.getByLabel('Find participant')).toBeVisible();
    await page.getByLabel('Find participant').fill('TheLongestRelay');
    await expect(page.getByRole('button', { name: /TheLongestRelay.*TAP/ })).toBeVisible();

    await atEverySize(page, 'selected station', async (size) => {
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
    await atEverySize(page, 'display results');
    await page.getByRole('button', { name: 'Exit display mode' }).click();

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/users/9001/edit');
    await page.getByLabel('Role', { exact: true }).selectOption('athlete');
    await expect(page.getByLabel('Athlete', { exact: true })).toBeVisible();
    await atEverySize(page, 'athlete picker');

    await page.setViewportSize({ width: 1440, height: 900 });
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
    await atEverySize(page, 'import preview');

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/races');
    await accountMenu(page);
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);

    await login(page, 'responsive-official@example.test');

    for (const path of [
        '/races',
        '/guide',
        '/races/9001',
        '/races/9001/station',
        '/races/9001/results',
    ]) {
        await page.goto(path);
        await atEverySize(page, 'official ' + path);
        await expect(page.getByRole('link', { name: 'Corrections & station health', exact: true }))
            .toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Export CSV', exact: true }))
            .toHaveCount(0);
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    await accountMenu(page);
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);

    await login(page, 'responsive-athlete@example.test');

    for (const path of ['/guide', '/athlete', '/races/9001/results']) {
        await page.goto(path);

        if (path === '/athlete') {
            await expect(page.locator('h1')).toContainText('Welcome');
        }

        await atEverySize(page, 'athlete ' + path);
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    await accountMenu(page);
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);

    await page.goto('/live/responsive-public');
    await expect(page.locator('h1')).toContainText('Results');
    await atEverySize(page, 'public results');

    expect(errors).toEqual([]);
});
