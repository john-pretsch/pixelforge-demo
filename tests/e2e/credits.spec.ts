import { expect, test } from '@playwright/test';

// No Pixelforge generation is created here: buying credits is local only.

test('buying credits adds to the balance', async ({ page }) => {
    await page.goto('/generations');

    const balance = page.getByTestId('credit-balance');
    await expect(balance).toHaveText(/\$\d/);
    const before = await balance.innerText();

    await page.getByRole('button', { name: 'Buy credits' }).click();
    await page.getByRole('menuitem', { name: 'Add $5.00' }).click();

    await expect(page.getByText('Added $5.00 in credits.')).toBeVisible();
    await expect(balance).not.toHaveText(before);
});
