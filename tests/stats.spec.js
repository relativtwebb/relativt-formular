import { test, expect } from '@playwright/test';

/*
 * Statistiksidan (1.5.0). demo-stats.html byggs av tests/build-stats-demo.php
 * med den riktiga renderaren och påhittade inskick.
 */
const DEMO = '/demo-stats.html';

test('statistiksidan visar nyckeltal, diagram och topplistor', async ({ page }) => {
	await page.goto(DEMO);

	await expect(page.locator('.xf-tile')).toHaveCount(4);
	await expect(page.locator('.xf-tile').first()).toContainText('Inskick');
	await expect(page.locator('svg.xf-chart .xf-bar-mark').first()).toBeVisible();
	await expect(page.locator('.xf-card', { hasText: 'Kanaler' })).toContainText('Organisk sök');
	await expect(page.locator('.xf-heat tbody tr')).toHaveCount(7);
	await expect(page.locator('.xf-heat tbody td')).toHaveCount(7 * 24);
});

test('datumfälten syns bara för eget intervall', async ({ page }) => {
	await page.goto(DEMO);

	const custom = page.locator('.xf-custom');
	await expect(custom).toBeHidden();

	await page.selectOption('#xf-stats-period', 'custom');
	await expect(custom).toBeVisible();
	await expect(custom.locator('input[name="from"]')).toHaveValue(/^\d{4}-\d{2}-\d{2}$/);

	await page.selectOption('#xf-stats-period', '30');
	await expect(custom).toBeHidden();
});

test('varje stapel har verktygstips och diagrammet finns som tabell', async ({ page }) => {
	await page.goto(DEMO);

	const hits = page.locator('svg.xf-chart .xf-hit');
	const n = await hits.count();
	expect(n).toBeGreaterThan(10);
	await expect(hits.nth(1).locator('title')).toHaveText(/^Vecka \d+, \d{4}: \d+ inskick/);
	// Första veckan täcks bara delvis av perioden och säger det.
	await expect(hits.first().locator('title')).toHaveText(/dag(ar)? i perioden\)$/);

	const table = page.locator('details.xf-table');
	await expect(table.locator('tbody')).toBeHidden();
	await table.locator('summary').click();
	await expect(table.locator('tbody tr')).toHaveCount(n);
});

test('sidan scrollar aldrig i sidled', async ({ page }) => {
	await page.goto(DEMO);

	const [scroll, width] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
	expect(scroll).toBeLessThanOrEqual(width);
});
