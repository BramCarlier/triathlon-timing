import { test, expect } from '@playwright/test';

test('admin creates, edits and deletes an Official access preset', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('textbox', { name: 'Email', exact: true }).fill('admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password-123');
    await page.getByRole('button', { name: /sign in|log in/i }).click();
    await expect(page).not.toHaveURL(/\/login/);

    await page.goto('/admin/roles');
    await expect(page.getByRole('heading', { name: 'Organizer (admin)', exact: true })).toBeVisible();

    await page.getByLabel('Preset name', { exact: true }).fill('Browser checkpoint official');
    await page.getByRole('checkbox', { name: /Record checkpoint times/ }).check();
    await page.getByRole('button', { name: 'Create preset', exact: true }).click();
    await expect(page.getByText('Role created.', { exact: false })).toBeVisible();

    await page.getByRole('button', { name: 'Edit Browser checkpoint official', exact: true }).click();
    await expect(page.getByRole('checkbox', { name: /Record checkpoint times/ })).toBeChecked();
    await page.getByRole('checkbox', { name: /Export results/ }).check();
    await page.getByRole('button', { name: 'Save preset', exact: true }).click();
    await expect(page.getByText('Role updated.', { exact: false })).toBeVisible();

    await page.getByRole('button', { name: 'Delete Browser checkpoint official', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete preset', exact: true }).click();
    await expect(page.getByText('Role deleted.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Edit Browser checkpoint official', exact: true })).toHaveCount(0);
});
