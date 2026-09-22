import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'tests/Browser',testMatch:'**/*.spec.ts',workers:1,retries:0,timeout:60000,use:{baseURL:'http://127.0.0.1:8000',trace:'retain-on-failure',screenshot:'only-on-failure'},webServer:{command:'php artisan serve --host=127.0.0.1 --port=8000',url:'http://127.0.0.1:8000/up',reuseExistingServer:false}});
