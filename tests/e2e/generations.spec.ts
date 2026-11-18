import { expect, Page, test } from '@playwright/test';

// Real Pixelforge API (dev key), low quality only to keep cost down.
// Prompts are unique per run so each test finds its own history row.

const estimateBox = (page: Page) => page.getByTestId('estimate');

async function submit(page: Page, prompt: string) {
    await page.goto('/generations');
    await page.locator('#prompt').fill(prompt);
    await page.locator('#quality').selectOption('low');
    await page.getByRole('button', { name: 'Generate' }).click();

    const row = page.locator('article', { hasText: prompt });
    await expect(row).toBeVisible();
    return row;
}

test('estimate appears as you type and changes with quality', async ({ page }) => {
    await page.goto('/generations');
    await page.locator('#prompt').fill('A lighthouse on a cliff at dusk');
    await page.locator('#quality').selectOption('low');

    const box = estimateBox(page);
    await expect(box).toContainText(/\$\d/);
    const low = await box.innerText();

    await page.locator('#quality').selectOption('high');
    await expect(box).not.toHaveText(low);
    await expect(box).toContainText(/\$\d/);
    await expect(box).toContainText('Failed generations are free');
});

test('succeeded generation shows live status, charge and image', async ({ page }) => {
    const row = await submit(page, `E2E success ${Date.now()} [succeed]`);

    await expect(row).toContainText('Succeeded', { timeout: 120_000 });
    await expect(row.getByText('Charged:').locator('..')).toContainText(/\$\d/);
    await expect(row).toContainText('tokens');
    await expect(row.locator('img')).toBeVisible();
});

test('generation that fails during processing is not charged', async ({ page }) => {
    const row = await submit(page, `E2E failure ${Date.now()} [fail]`);

    await expect(row).toContainText('Failed', { timeout: 120_000 });
    await expect(row).toContainText('$0.00');
    await expect(row).toContainText('not charged');
});

test('prompt rejected by content policy fails immediately at $0', async ({ page }) => {
    const row = await submit(page, `E2E blocked ${Date.now()} [blocked]`);

    await expect(row).toContainText('Failed', { timeout: 30_000 });
    await expect(row).toContainText('content policy');
    await expect(row).toContainText('not charged');
});
