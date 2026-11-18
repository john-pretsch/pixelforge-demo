import { expect, test as setup } from '@playwright/test';

// User is created by tests/e2e/server.sh.
setup('log in', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('e2e@example.test');
    await page.getByLabel('Password', { exact: true }).fill('e2e-password');
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL(/\/generations$/);
    await page.context().storageState({ path: 'tests/e2e/.auth/user.json' });
});
