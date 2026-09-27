import { test, expect } from '@playwright/test';

test('public results offer a sortable table and cards on phones and desktop', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/live/responsive-public');
    const table = page.getByRole('region', { name: 'Results table', exact: true });
    const cards = page.getByRole('region', { name: 'Race results', exact: true });
    await expect(table).toBeVisible();
    await expect(page.getByRole('button', { name: 'Table', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const nameHeader = table.getByRole('columnheader', { name: 'Name', exact: true });
    await nameHeader.getByRole('button').click();
    await expect(nameHeader).toHaveAttribute('aria-sort', 'ascending');
    await expect(table.locator('tbody > tr').first()).toContainText('Alexandria');
    await nameHeader.getByRole('button').click();
    await expect(nameHeader).toHaveAttribute('aria-sort', 'descending');
    await expect(table.locator('tbody > tr').first()).toContainText('TheLongestRelay');

    await page.getByRole('button', { name: 'Cards', exact: true }).click();
    await expect(table).toHaveCount(0);
    await expect(cards.locator('article').first()).toContainText('TheLongestRelay');
    await page.getByLabel('Sort results by', { exact: true }).selectOption('place');
    await expect(cards.locator('article').first()).toContainText('Alexandria');
    await page.getByRole('button', { name: 'Table', exact: true }).click();
    await expect(table).toBeVisible();
    await expect(table.getByRole('columnheader', { name: 'Place', exact: true })).toHaveAttribute('aria-sort', 'descending');
    await table.getByRole('columnheader', { name: 'Finish', exact: true }).getByRole('button').click();
    await expect(table.getByRole('columnheader', { name: 'Finish', exact: true })).toHaveAttribute('aria-sort', 'ascending');
    await expect(table.locator('tbody > tr').first()).toContainText('Alexandria');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);

    await page.getByText('Display options', { exact: true }).click();
    await page.getByRole('button', { name: 'Large-screen display', exact: true }).click();
    await expect(table).toBeVisible();
    await page.getByRole('button', { name: 'Exit display mode', exact: true }).click();
    await expect(table).toBeVisible();
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.getByRole('button', { name: 'Cards', exact: true }).click();
    await expect(cards).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
});
