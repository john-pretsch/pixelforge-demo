import { defineConfig, devices } from '@playwright/test';

/**
 * Browser E2E against an isolated local stack (tests/e2e/server.sh):
 * own database (pixelforge_e2e), dev Pixelforge key from .env.e2e, real API.
 * Run: npm run build && npm run test:e2e
 */
export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 150_000,
    expect: { timeout: 15_000 },
    workers: 1,
    fullyParallel: false,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: 'http://127.0.0.1:8001',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], storageState: 'tests/e2e/.auth/user.json' },
            dependencies: ['setup'],
            testMatch: /.*\.spec\.ts/,
        },
    ],
    webServer: {
        command: 'bash tests/e2e/server.sh',
        url: 'http://127.0.0.1:8001/up',
        timeout: 120_000,
        reuseExistingServer: false,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
