import { test, expect, type Page } from '@playwright/test';

async function openAccountMenu(page: Page) {
    const account = page.locator('summary').filter({ hasText: 'Account' }).first();
    if (!(await account.evaluate((el) => (el.parentElement as HTMLDetailsElement)?.open))) {
        await account.click();
    }
}

test('temporary-password onboarding requires a new password before race access', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('textbox', { name: 'Email', exact: true }).fill('admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password-123');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login/);

    await page.getByRole('link', { name: 'People', exact: true }).click();
    await page.locator('summary').filter({ hasText: 'Create account' }).click();
    await page.getByLabel('Name', { exact: true }).fill('New Organizer');
    await page.getByLabel('Email', { exact: true }).fill('new-organizer@example.test');
    await page.getByLabel('Invitation', { exact: true }).selectOption('manual');
    await page.getByLabel('Temporary password', { exact: true }).fill('temporary-race-password');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page.getByText('Account created.', { exact: false })).toBeVisible();

    await openAccountMenu(page);
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);

    await page.getByRole('textbox', { name: 'Email', exact: true }).fill('new-organizer@example.test');
    await page.getByLabel('Password', { exact: true }).fill('temporary-race-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/account\/password$/);

    await page.goto('/races');
    await expect(page).toHaveURL(/\/account\/password$/);
    await page.getByLabel('Current password', { exact: true }).fill('temporary-race-password');
    await page.getByLabel('New password', { exact: true }).fill('my-new-race-password');
    await page.getByLabel('Confirm new password', { exact: true }).fill('my-new-race-password');
    await page.getByRole('button', { name: 'Update password', exact: true }).click();
    await expect(page).toHaveURL(/\/races$/);
    await expect(page.getByRole('heading', { name: 'Your first race starts here' })).toBeVisible();
});
