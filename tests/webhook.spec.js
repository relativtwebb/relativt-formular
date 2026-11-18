import { test, expect } from '@playwright/test';

/*
 * Webhook (1.17.0), från besökarens håll. Sidan byggs av
 * tests/build-webhook-demo.php: kontaktformuläret med webhooken på och en
 * adress och en hemlighet ifyllda. Webhooken körs helt på servern – det här
 * visar att inget av den når webbläsaren och att inskicket går som förut.
 */
const DEMO = '/demo-webhook.html';

const form = (page) => page.locator('.relativt-form');
const field = (page, key) => page.locator(`[data-xf-key="${key}"]`);

test.beforeEach(async ({ page }) => {
	await page.context().clearCookies();
});

test('varken adressen eller hemligheten finns i sidan eller i skriptets konfiguration', async ({ page }) => {
	await page.goto(DEMO);
	const html = await page.content();
	expect(html).not.toContain('hooks.zapier.com');
	expect(html).not.toContain('superhemligt-varde');
	expect(html).not.toContain('X-Webhook-Secret');
	const config = await page.evaluate(() => JSON.stringify(window.relativtFormConfig ?? {}));
	expect(config).not.toContain('webhook');
});

test('inskicket går till /submit som förut, och webbläsaren pratar aldrig med webhooken', async ({ page }) => {
	const hosts = [];
	page.on('request', (request) => hosts.push(new URL(request.url()).host));

	await page.goto(DEMO);
	await field(page, 'namn').locator('input').fill('Anna Andersson');
	await field(page, 'epost').locator('input').fill('anna@exempel.se');
	await form(page).locator('.xf-submit').click();
	await expect(form(page)).toHaveClass(/is-submitted/);

	const calls = await page.evaluate(() => window.__mockCalls);
	expect(calls).toHaveLength(1);
	expect(calls[0].url).toContain('submit');
	expect(Object.keys(calls[0].body)).not.toContain('webhook');
	expect(hosts.some((host) => host.includes('zapier'))).toBe(false);
});
