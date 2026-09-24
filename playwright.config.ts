import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/Browser',
    testMatch: '**/*.spec.ts',
    workers: 1,
    retries: 0,
    timeout: 60000,
    projects: [
        {
            name: 'chromium-smoke',
            testIgnore: '**/responsive.spec.ts',
            use: { browserName: 'chromium' },
        },
        {
            name: 'chromium-responsive',
            testMatch: '**/responsive.spec.ts',
            use: { browserName: 'chromium' },
        },
        {
            name: 'webkit-responsive',
            testMatch: '**/responsive.spec.ts',
            use: { browserName: 'webkit', hasTouch: true },
        },
    ],
    expect: {
        timeout: 10000,
    },
    use: {
        baseURL: 'http://127.0.0.1:8000',
        actionTimeout: 10000,
        navigationTimeout: 15000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000/up',
        reuseExistingServer: false,
    },
});
