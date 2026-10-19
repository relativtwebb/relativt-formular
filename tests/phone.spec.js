import { test, expect } from '@playwright/test';

/*
 * Telefonfält med landsväljare (1.12.0). Demon är genererad av den riktiga
 * renderaren: 'mobil' har Sverige som förval, 'mobil_no' Norge, och det vanliga
 * 'telefon'-fältet är orört och ska bete sig exakt som förut.
 */

const DEMO = '/demo-form.html';

const page_form = (page) => page.locator('#xf-page .relativt-form');
const field = (form, key) => form.locator(`[data-xf-key="${key}"]`);
const number = (form, key) => field(form, key).locator('.xf-phone-input');
const country = (form, key) => field(form, key).locator('.xf-phone-select');
const flag = (form, key) => field(form, key).locator('.xf-phone-flag');

async function fillValid(form) {
	await field(form, 'namn').locator('input').fill('Anna Andersson');
	await field(form, 'epost').locator('input').fill('anna@exempel.se');
}

/** Skickar formuläret och returnerar det som gick till servern. */
async function submit(page, form) {
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);
	const [body] = await page.evaluate(() => window.__mockCalls);
	return body;
}

test.beforeEach(async ({ page }) => {
	await page.context().clearCookies();
	await page.goto(DEMO);
});

test.describe('utseende och start', () => {
	test('numret är förifyllt med landsnumret och flaggan visas', async ({ page }) => {
		const form = page_form(page);
		await expect(number(form, 'mobil')).toHaveValue('+46 ');
		await expect(country(form, 'mobil')).toHaveValue('SE');
		await expect(flag(form, 'mobil')).toHaveText('🇸🇪');
	});

	test('förvalt land styr start', async ({ page }) => {
		const form = page_form(page);
		await expect(number(form, 'mobil_no')).toHaveValue('+47 ');
		await expect(country(form, 'mobil_no')).toHaveValue('NO');
		await expect(flag(form, 'mobil_no')).toHaveText('🇳🇴');
	});

	test('det vanliga telefonfältet är oförändrat', async ({ page }) => {
		const form = page_form(page);
		await expect(field(form, 'telefon').locator('.xf-phone')).toHaveCount(0);
		await expect(field(form, 'telefon').locator('input')).toHaveValue('');
	});

	test('den osynliga rullistan täcker exakt flaggan och pilen', async ({ page }) => {
		const form = page_form(page);
		const box = await field(form, 'mobil').locator('.xf-phone-cc').boundingBox();
		const sel = await country(form, 'mobil').boundingBox();
		expect(Math.abs(box.x - sel.x)).toBeLessThan(1.5);
		expect(Math.abs(box.width - sel.width)).toBeLessThan(1.5);
		expect(Math.abs(box.height - sel.height)).toBeLessThan(1.5);
		// Och är faktiskt osynlig – det är flaggan som syns.
		expect(await country(form, 'mobil').evaluate((el) => getComputedStyle(el).opacity)).toBe('0');
	});

	test('landsväljaren och numret står på samma rad och har samma höjd', async ({ page }) => {
		const form = page_form(page);
		const cc = await field(form, 'mobil').locator('.xf-phone-cc').boundingBox();
		const nr = await number(form, 'mobil').boundingBox();
		expect(Math.abs(cc.y - nr.y)).toBeLessThan(1.5);
		expect(Math.abs(cc.height - nr.height)).toBeLessThan(1.5);
		expect(nr.x).toBeGreaterThanOrEqual(cc.x + cc.width - 1.5);
	});

	test('rullistan har ett tillgängligt namn och etiketten pekar på numret', async ({ page }) => {
		const form = page_form(page);
		await expect(country(form, 'mobil')).toHaveAttribute('aria-label', 'Land och landsnummer');
		const id = await number(form, 'mobil').getAttribute('id');
		await expect(field(form, 'mobil').locator(`label[for="${id}"]`)).toHaveCount(1);
	});

	test('sidan scrollar aldrig i sidled', async ({ page }) => {
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow).toBeLessThanOrEqual(0);
	});

	test('fokus på rullistan syns som fokusram', async ({ page }) => {
		const form = page_form(page);
		await country(form, 'mobil').focus();
		const ring = await page.evaluate(() => {
			const probe = document.createElement('i');
			probe.style.color = getComputedStyle(document.querySelector('#xf-page .relativt-form')).getPropertyValue('--xf-focus-ring');
			document.body.append(probe);
			const color = getComputedStyle(probe).color;
			probe.remove();
			return color;
		});
		// Kantfärgen tonas över (transition), så den läses om tills den landat.
		await expect
			.poll(() => field(form, 'mobil').locator('.xf-phone-cc').evaluate((el) => getComputedStyle(el).borderTopColor))
			.toBe(ring);
	});
});

test.describe('byta land', () => {
	test('landsnumret byts, och numret som skrivits in behålls', async ({ page }) => {
		const form = page_form(page);
		await number(form, 'mobil').fill('+46 70 123 45 67');
		await country(form, 'mobil').selectOption('NO');

		await expect(number(form, 'mobil')).toHaveValue('+47 70 123 45 67');
		await expect(flag(form, 'mobil')).toHaveText('🇳🇴');
	});

	test('ett tomt fält får det nya landsnumret', async ({ page }) => {
		const form = page_form(page);
		await country(form, 'mobil').selectOption('DK');
		await expect(number(form, 'mobil')).toHaveValue('+45 ');
		await country(form, 'mobil').selectOption('FI');
		await expect(number(form, 'mobil')).toHaveValue('+358 ');
		await expect(flag(form, 'mobil')).toHaveText('🇫🇮');
	});

	test('landsnummer med olika längd byts rent (+358 → +46)', async ({ page }) => {
		const form = page_form(page);
		await country(form, 'mobil').selectOption('FI');
		await number(form, 'mobil').fill('+358 40 123 4567');
		await country(form, 'mobil').selectOption('SE');
		await expect(number(form, 'mobil')).toHaveValue('+46 40 123 4567');
	});

	test('ett nummer skrivet utan landsnummer får det nya landet framför sig', async ({ page }) => {
		const form = page_form(page);
		await number(form, 'mobil').fill('070-123 45 67');
		await country(form, 'mobil').selectOption('NO');
		await expect(number(form, 'mobil')).toHaveValue('+47 070-123 45 67');
	});

	test('att skriva ett annat landsnummer flyttar rullistan och flaggan med', async ({ page }) => {
		const form = page_form(page);
		await number(form, 'mobil').fill('+47 91 23 45 67');
		await expect(country(form, 'mobil')).toHaveValue('NO');
		await expect(flag(form, 'mobil')).toHaveText('🇳🇴');

		await number(form, 'mobil').fill('+358 40 123 4567');
		await expect(country(form, 'mobil')).toHaveValue('FI');
		await expect(flag(form, 'mobil')).toHaveText('🇫🇮');
	});

	test('0046 räknas som +46', async ({ page }) => {
		const form = page_form(page);
		await country(form, 'mobil').selectOption('NO');
		await number(form, 'mobil').fill('0046 70 123 45 67');
		await expect(country(form, 'mobil')).toHaveValue('SE');
	});

	test('+1 blir inte om från Kanada till USA när Kanada redan är valt', async ({ page }) => {
		const form = page_form(page);
		await country(form, 'mobil').selectOption('CA');
		await number(form, 'mobil').fill('+1 416 555 0100');
		await expect(country(form, 'mobil')).toHaveValue('CA');
	});

	test('ett visat fel försvinner när landet byts', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await number(form, 'mobil').fill('+46 12');
		await form.locator('.xf-submit').click();
		await expect(field(form, 'mobil')).toHaveClass(/has-error/);

		await country(form, 'mobil').selectOption('NO');
		await expect(field(form, 'mobil')).not.toHaveClass(/has-error/);
	});

	test('tangentbordet: bokstäver väljer land i rullistan', async ({ page }) => {
		const form = page_form(page);
		await country(form, 'mobil').focus();
		await page.keyboard.type('Dan');
		await expect(country(form, 'mobil')).toHaveValue('DK');
		await expect(number(form, 'mobil')).toHaveValue('+45 ');
	});
});

test.describe('det som skickas', () => {
	test('orört fält är tomt: inget fel och inget nummer skickas', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		const body = await submit(page, form);
		expect(body.fields.mobil).toBe('');
		expect(body.fields.mobil_no).toBe('');
	});

	test('rullistan skickas aldrig med som eget fält', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await number(form, 'mobil').fill('+46 70 123 45 67');
		const body = await submit(page, form);
		// Bara de två telefonfälten bär nummer; rullistorna har inget namn och följer inte med.
		expect(Object.keys(body.fields).filter((k) => k.startsWith('mobil'))).toEqual(['mobil', 'mobil_no']);
		expect(Object.values(body.fields)).not.toContain('SE');
	});

	test('svenskt nummer skickas med landsnummer och utan mellanrum', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await number(form, 'mobil').fill('+46 70 123 45 67');
		expect((await submit(page, form)).fields.mobil).toBe('+46701234567');
	});

	test('inledande nolla efter landsnumret tas bort (+46 070…)', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await number(form, 'mobil').fill('+46 070-123 45 67');
		expect((await submit(page, form)).fields.mobil).toBe('+46701234567');
	});

	test('ett nummer utan landsnummer får det valda landets', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await number(form, 'mobil').fill('070-123 45 67');
		expect((await submit(page, form)).fields.mobil).toBe('+46701234567');
	});

	test('ett nummer utan nolla och landsnummer, som 701234567, godkänns som förut', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await number(form, 'mobil').fill('701234567');
		expect((await submit(page, form)).fields.mobil).toBe('+46701234567');
	});

	test('Storbritannien: nollan före abonnentnumret tas bort', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await country(form, 'mobil').selectOption('GB');
		await number(form, 'mobil').fill('+44 07946 123456');
		expect((await submit(page, form)).fields.mobil).toBe('+447946123456');
	});

	test('Italien: nollan hör till numret och behålls', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await country(form, 'mobil').selectOption('IT');
		await number(form, 'mobil').fill('+39 06 1234 5678');
		expect((await submit(page, form)).fields.mobil).toBe('+390612345678');
	});

	test('norskt förval: nummer utan landsnummer får +47', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await number(form, 'mobil_no').fill('912 34 567');
		expect((await submit(page, form)).fields.mobil_no).toBe('+4791234567');
	});

	test('båda fälten är oberoende av varandra', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await country(form, 'mobil').selectOption('DK');
		await expect(number(form, 'mobil_no')).toHaveValue('+47 ');
		await expect(country(form, 'mobil_no')).toHaveValue('NO');
	});
});

test.describe('validering', () => {
	const fel = async (form, key) => field(form, key).locator('.xf-error');

	test('obligatoriskt: bara landsnumret kvar räknas som tomt', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await page.evaluate(() => {
			document.querySelector('#xf-page [data-xf-key="mobil"]').dataset.xfRequired = '1';
		});
		await form.locator('.xf-submit').click();

		await expect(field(form, 'mobil')).toHaveClass(/has-error/);
		await expect(await fel(form, 'mobil')).toHaveText('Fyll i detta fält.');
		expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
	});

	for (const text of ['ring mig', '+46 abc', '070-ABC', '+46 12', '+46 0000000000', '+47 12']) {
		test(`ogiltigt "${text}" stoppas med telefonmeddelandet`, async ({ page }) => {
			const form = page_form(page);
			await fillValid(form);
			await number(form, 'mobil').fill(text);
			await form.locator('.xf-submit').click();

			await expect(field(form, 'mobil')).toHaveClass(/has-error/);
			await expect(await fel(form, 'mobil')).toContainText('070-123 45 67');
			expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
		});
	}

	test('felet pekas ut för skärmläsare på numret, inte på rullistan', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await number(form, 'mobil').fill('ring mig');
		await form.locator('.xf-submit').click();

		await expect(number(form, 'mobil')).toHaveAttribute('aria-invalid', 'true');
		await expect(number(form, 'mobil')).toHaveAttribute('aria-describedby', /-error/);
		await expect(country(form, 'mobil')).not.toHaveAttribute('aria-invalid', /.*/);
	});

	test('felkanten hamnar på både landsväljaren och numret', async ({ page }) => {
		const form = page_form(page);
		await fillValid(form);
		await number(form, 'mobil').fill('ring mig');
		await form.locator('.xf-submit').click();

		const border = (loc) => loc.evaluate((el) => getComputedStyle(el).borderTopColor);
		await expect.poll(() => border(field(form, 'mobil').locator('.xf-phone-cc'))).toBe('rgb(179, 45, 46)');
		await expect.poll(() => border(number(form, 'mobil'))).toBe('rgb(179, 45, 46)');
	});
});
