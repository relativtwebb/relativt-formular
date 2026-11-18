import { test, expect } from '@playwright/test';

/*
 * Dynamiska alternativ och sidkontext (1.16.0). Sidorna byggs av
 * tests/build-dynamic-demo.php med den riktiga renderaren: kursformuläret på
 * kurssidan med post-ID 82, där sajtens filter har fyllt tillfällena och
 * spärrat ett alternativ i varje fälttyp.
 */
const DEMO = '/demo-dynamic.html';
const DEMO_115 = '/demo-dynamic-115.html';
const DEMO_STEPS = '/demo-dynamic-steps.html';

const DISABLED = 'Alternativet går inte längre att välja.';

const form = (page) => page.locator('.relativt-form');
const field = (page, key) => page.locator(`[data-xf-key="${key}"]`);
const select = (page) => field(page, 'tillfalle').locator('select');

async function fillPerson(page) {
	await field(page, 'namn').locator('input').fill('Anna Andersson');
	await field(page, 'epost').locator('input').fill('anna@exempel.se');
}

async function submit(page) {
	await form(page).locator('.xf-submit').click();
}

async function submitted(page) {
	await expect(form(page)).toHaveClass(/is-submitted/);
	return page.evaluate(() => window.__mockCalls.at(-1));
}

test.beforeEach(async ({ page }) => {
	await page.context().clearCookies();
});

test.describe('spärrade alternativ', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(DEMO);
	});

	test('rullistan visar alla tillfällen, det fullbokade är spärrat', async ({ page }) => {
		const options = select(page).locator('option');
		await expect(options).toHaveText([
			'Välj tillfälle',
			'2–3 februari 2027 · På plats',
			'1 mars 2027 · Distans (Fullbokat)',
			'10 april 2027 · På plats (Fåtal platser kvar)',
		]);
		await expect(select(page).locator('option[value="20270301_webbaserad"]')).toBeDisabled();
		await expect(select(page).locator('option[value="20270301_webbaserad"]')).toHaveClass('is-disabled');
		await expect(select(page).locator('option[value="20270202_plats"]')).toBeEnabled();
	});

	test('radioknapp, val-knapp och kryssruta: spärrade går inte att välja', async ({ page }) => {
		await expect(field(page, 'plats').locator('input[value="b"]')).toBeDisabled();
		await expect(field(page, 'niva').locator('input[value="pro"]')).toBeDisabled();
		await expect(field(page, 'tillval').locator('input[value="middag"]')).toBeDisabled();

		// Ett klick på etiketten väljer ingenting.
		await field(page, 'niva').getByText('Pro').click({ force: true });
		await expect(field(page, 'niva').locator('input[value="pro"]')).not.toBeChecked();
		await field(page, 'tillval').getByText('Middag').click({ force: true });
		await expect(field(page, 'tillval').locator('input[value="middag"]')).not.toBeChecked();

		// De öppna går fortfarande att välja.
		await field(page, 'niva').getByText('Bas').click();
		await expect(field(page, 'niva').locator('input[value="bas"]')).toBeChecked();
	});

	test('spärrade alternativ är blekare och har klassen is-disabled', async ({ page }) => {
		const pro = field(page, 'niva').locator('label', { hasText: 'Pro' });
		const bas = field(page, 'niva').locator('label', { hasText: 'Bas' });
		await expect(pro).toHaveClass(/is-disabled/);
		await expect(field(page, 'tillval').locator('label.xf-check', { hasText: 'Middag' })).toHaveClass(/is-disabled/);
		const opacity = async (loc) => Number(await loc.evaluate((el) => getComputedStyle(el).opacity));
		expect(await opacity(pro)).toBeLessThan(1);
		expect(await opacity(bas)).toBe(1);
		await expect(pro).toHaveCSS('cursor', 'not-allowed');
	});

	test('villkor mot ett dynamiskt värde: Resväg visas för rätt tillfälle', async ({ page }) => {
		await expect(field(page, 'resa')).toBeHidden();
		await select(page).selectOption('20270202_plats');
		await expect(field(page, 'resa')).toBeVisible();
		await select(page).selectOption('20270410_plats');
		await expect(field(page, 'resa')).toBeHidden();
	});
});

test.describe('förval via URL', () => {
	test('ett öppet tillfälle förväljs, med värde eller etikett', async ({ page }) => {
		await page.goto(`${DEMO}?tillfalle=20270410_plats`);
		await expect(select(page)).toHaveValue('20270410_plats');
		await page.goto(`${DEMO}?tillfalle=${encodeURIComponent('2–3 februari 2027 · På plats')}`);
		await expect(select(page)).toHaveValue('20270202_plats');
	});

	test('ett spärrat tillfälle förväljs inte, inte heller via etiketten', async ({ page }) => {
		await page.goto(`${DEMO}?tillfalle=20270301_webbaserad`);
		await expect(select(page)).toHaveValue('');
		await page.goto(`${DEMO}?tillfalle=${encodeURIComponent('1 mars 2027 · Distans (Fullbokat)')}`);
		await expect(select(page)).toHaveValue('');
	});

	test('spärrad radioknapp, val-knapp och kryssruta förväljs inte', async ({ page }) => {
		await page.goto(`${DEMO}?plats=b&niva=Pro&tillval=middag`);
		await expect(field(page, 'plats').locator('input[value="b"]')).not.toBeChecked();
		await expect(field(page, 'niva').locator('input[value="pro"]')).not.toBeChecked();
		await expect(field(page, 'tillval').locator('input[value="middag"]')).not.toBeChecked();
	});

	test('öppen radioknapp förväljs som förut', async ({ page }) => {
		await page.goto(`${DEMO}?plats=a&niva=bas`);
		await expect(field(page, 'plats').locator('input[value="a"]')).toBeChecked();
		await expect(field(page, 'niva').locator('input[value="bas"]')).toBeChecked();
	});
});

test.describe('inskicket', () => {
	test('sidkontexten följer med som fältet _xf_post, med signatur', async ({ page }) => {
		await page.goto(DEMO);
		await fillPerson(page);
		await select(page).selectOption('20270202_plats');
		await field(page, 'resa').locator('input').fill('Tåg');
		await submit(page);
		const body = await submitted(page);
		expect(body.fields._xf_post).toMatch(/^82:[a-f0-9]{64}$/);
		expect(body.fields.tillfalle).toBe('20270202_plats');
		expect(body.fields.resa).toBe('Tåg');
		// Spärrade alternativ skickas aldrig med.
		expect(body.fields.tillval).toEqual([]);
	});

	test('kontextfältet syns inte och stoppar inte valideringen', async ({ page }) => {
		await page.goto(DEMO);
		await expect(field(page, '_xf_post')).toBeHidden();
		await submit(page);
		await expect(field(page, 'namn')).toHaveClass(/has-error/);
		await expect(field(page, '_xf_post')).not.toHaveClass(/has-error/);
	});

	test('serverns choice_disabled visas vid fältet', async ({ page }) => {
		await page.goto(DEMO);
		await fillPerson(page);
		await select(page).selectOption('20270202_plats');
		await page.evaluate((m) => {
			window.__mockErrors = { tillfalle: m };
		}, DISABLED);
		await submit(page);
		await expect(field(page, 'tillfalle')).toHaveClass(/has-error/);
		await expect(field(page, 'tillfalle').locator('.xf-error')).toHaveText(DISABLED);
		await expect(select(page)).toBeFocused();
	});

	test('JS från 1.15.0 (gammal cache) skickar också med sidkontexten', async ({ page }) => {
		await page.goto(DEMO_115);
		await fillPerson(page);
		await select(page).selectOption('20270410_plats');
		await submit(page);
		const body = await submitted(page);
		expect(body.fields._xf_post).toMatch(/^82:[a-f0-9]{64}$/);
		expect(body.fields.tillfalle).toBe('20270410_plats');
	});
});

test.describe('flersteg', () => {
	async function toLastStep(page) {
		await field(page, 'namn').locator('input').fill('Anna Andersson');
		await select(page).selectOption('20270202_plats');
		await form(page).locator('.xf-next').click();
		await field(page, 'epost').locator('input').fill('anna@exempel.se');
	}

	test('kontextfältet ligger utanför stegen och följer med från sista steget', async ({ page }) => {
		await page.goto(DEMO_STEPS);
		await toLastStep(page);
		await submit(page);
		const body = await submitted(page);
		expect(body.fields._xf_post).toMatch(/^82:[a-f0-9]{64}$/);
		expect(body.fields.tillfalle).toBe('20270202_plats');
	});

	test('choice_disabled på tillfället i steg 1 tar besökaren tillbaka dit', async ({ page }) => {
		await page.goto(DEMO_STEPS);
		await toLastStep(page);
		await expect(field(page, 'tillfalle')).toBeHidden();
		await page.evaluate((m) => {
			window.__mockErrors = { tillfalle: m };
		}, DISABLED);
		await submit(page);
		await expect(field(page, 'tillfalle')).toBeVisible();
		await expect(field(page, 'tillfalle').locator('.xf-error')).toHaveText(DISABLED);
		await expect(select(page)).toBeFocused();
		await expect(field(page, 'epost')).toBeHidden();
	});
});
