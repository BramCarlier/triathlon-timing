import { test, expect, type Page } from '@playwright/test';

async function login(page: Page) {
  await page.goto('/login');
  await page.getByRole('textbox', { name: 'Email', exact: true }).fill('admin@example.test');
  await page.getByLabel('Password', { exact: true }).fill('browser-test-password-123');
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/\/login/);
}

/** Creates a started race with one athlete and returns the race-day link. */
async function startedRace(page: Page, name: string, athlete: string): Promise<string> {
  await login(page);
  await page.goto('/races/create');
  await page.getByPlaceholder('Halle Triathlon 2027').fill(name);
  await page.getByRole('button', { name: 'Create and continue', exact: true }).click();
  await expect(page).toHaveURL(/\/races\/\d+$/);
  await page
    .getByRole('button', { name: /Athletes/ })
    .first()
    .click();
  const athleteForm = page.locator('form').filter({ has: page.getByRole('heading', { name: 'Add athlete', exact: true }) });
  await athleteForm.getByLabel('Name', { exact: true }).fill(athlete);
  await athleteForm.getByRole('button', { name: 'Add athlete', exact: true }).click();
  await expect(page.getByText('Participant added.', { exact: true })).toBeVisible();
  await page
    .getByRole('button', { name: /Start & timing/ })
    .first()
    .click();
  const link = await page.getByRole('link', { name: 'Open race-day view', exact: true }).getAttribute('href');
  await page.getByRole('button', { name: 'Start race', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Start race', exact: true }).click();
  await expect(page.getByRole('timer')).not.toHaveText('NOT STARTED');
  return new URL(link!, page.url()).toString();
}

test('a phone with a wrong clock still records the server time', async ({ page, browser }) => {
  const link = await startedRace(page, 'Clock skew check', 'Skewed Swimmer');
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
  // The device clock runs 8 seconds fast.
  await context.addInitScript(() => {
    const real = performance.timeOrigin;
    Object.defineProperty(performance, 'timeOrigin', { get: () => real + 8000 });
    const RealDate = Date;
    const offset = 8000;
    class SkewedDate extends RealDate {
      constructor(...args: ConstructorParameters<DateConstructor>) {
        super(...((args.length ? args : [RealDate.now() + offset]) as []));
      }
      static now() {
        return RealDate.now() + offset;
      }
    }
    (window as unknown as { Date: DateConstructor }).Date = SkewedDate as unknown as DateConstructor;
  });
  const timer = await context.newPage();
  const sampled = timer.waitForResponse(response => response.url().endsWith('/time'));
  await timer.goto(link);
  await sampled;
  await timer.getByRole('dialog', { name: 'Choose a checkpoint' }).getByRole('button', { name: 'Swim Exit', exact: true }).click();

  const request = timer.waitForRequest(r => r.url().endsWith('/timings') && r.method() === 'POST');
  await timer.getByRole('button', { name: /Skewed Swimmer.*Tap/ }).click();
  const observed = Date.parse(JSON.parse((await request).postData()!).observed_at);
  expect(Math.abs(observed - Date.now())).toBeLessThan(2000);
  await expect(timer.getByRole('status').filter({ hasText: 'recorded at' })).toContainText('recorded at Swim Exit');
  await context.close();
});

test('a tap without signal is kept with its time, can be undone, and uploads later', async ({ page, browser }) => {
  const link = await startedRace(page, 'Offline link check', 'Offline Finisher');
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
  const timer = await context.newPage();
  await timer.goto(link);
  await timer.getByRole('dialog', { name: 'Choose a checkpoint' }).getByRole('button', { name: 'Finish', exact: true }).click();

  await context.route('**/race/*/timings', route => route.abort());
  await timer.getByRole('button', { name: /Offline Finisher.*Tap/ }).click();
  await expect(timer.getByRole('status').filter({ hasText: 'saved on this device' })).toBeVisible();
  await expect(timer.getByText('1 taps waiting to upload', { exact: true })).toBeVisible();
  const queuedTime = (await timer.locator('li').filter({ hasText: 'Offline Finisher' }).locator('.tabular').innerText()).trim();

  // The saved tap survives a reload and keeps the selected checkpoint.
  await timer.reload();
  await expect(timer.getByRole('button', { name: /Recording at Finish/ })).toBeVisible();
  await expect(timer.getByText('1 taps waiting to upload', { exact: true })).toBeVisible();

  await context.unroute('**/race/*/timings');
  // Reconnecting triggers the upload (the periodic retry may already have done it).
  await timer.evaluate(() => window.dispatchEvent(new Event('online')));
  await expect(timer.getByText('1 taps waiting to upload', { exact: true })).toHaveCount(0);
  await timer.getByRole('button', { name: 'Latest times', exact: true }).click();
  const latest = timer.getByRole('listitem').filter({ hasText: 'Offline Finisher' });
  await expect(latest).toContainText(queuedTime);

  // The organizer sees the race finish automatically once the only athlete has a time.
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Race finished', exact: true })).toBeVisible({ timeout: 15000 });
  await context.close();
});

test('the tapping phone can undo a mistaken tap', async ({ page, browser }) => {
  const link = await startedRace(page, 'Undo check', 'Mistaken Runner');
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
  const timer = await context.newPage();
  await timer.goto(link);
  await timer.getByRole('dialog', { name: 'Choose a checkpoint' }).getByRole('button', { name: 'T1 (Bike Start)', exact: true }).click();
  await timer.getByRole('button', { name: /Mistaken Runner.*Tap/ }).click();
  await expect(timer.getByRole('status').filter({ hasText: 'recorded at' })).toBeVisible();
  await timer.getByRole('button', { name: /^Undo/ }).click();
  await expect(timer.getByRole('status')).toContainText('Mistaken Runner removed from T1 (Bike Start).');
  await expect(timer.getByRole('button', { name: /Mistaken Runner.*Tap/ })).toBeEnabled();
  await context.close();
});
