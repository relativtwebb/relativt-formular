import { test, expect } from '@playwright/test';

/*
 * Flerspråkiga sajter (1.15.0). Sidorna byggs av tests/build-i18n-demo.php:
 * samma sajt i en svensk och en engelsk språkversion, med markup från den
 * riktiga renderaren och serversvar inspelade från den riktiga rest_submit().
 *
 * Den mockade servern svarar efter det lang som skriptet skickar. Kommer
 * språket inte fram får den engelska sidan svenska fel – det är precis
 * felet de här testerna finns för att fånga.
 *
 * #i18n-simple är ett vanligt formulär, #i18n-steps ett flerstegsformulär.
 */
const PAGES = {
	sv: {
		url: '/demo-i18n-sv.html',
		required: 'Fyll i detta fält.',
		email: 'Kontrollera e-postadressen.',
		rate: 'Du har skickat flera meddelanden på kort tid. Vänta en stund och försök igen.',
		toofast: 'Det gick lite för snabbt. Vänta en sekund och försök igen.',
		submit: 'Skicka',
		sending: 'Skickar…',
		thanksTitle: 'Tack för din förfrågan!',
		thanksText: 'Vi hör av oss inom en arbetsdag.',
		next: 'Nästa',
		prev: 'Tillbaka',
		step: (current, total) => `Steg ${current} av ${total}`,
		country: 'Land och landsnummer',
	},
	en: {
		url: '/demo-i18n-en.html',
		required: 'This field is required.',
		email: 'Please check the email address.',
		rate: 'You have sent several messages in a short time. Please wait a while and try again.',
		toofast: 'That was a little too fast. Wait a second and try again.',
		submit: 'Send',
		sending: 'Sending…',
		thanksTitle: 'Thanks for your enquiry!',
		thanksText: 'We will get back to you within one working day.',
		next: 'Next',
		prev: 'Back',
		step: (current, total) => `Step ${current} of ${total}`,
		country: 'Country and dialling code',
	},
};

const simple = (page) => page.locator('#i18n-simple .relativt-form');
const steps = (page) => page.locator('#i18n-steps .relativt-form');
const field = (form, key) => form.locator(`[data-xf-key="${key}"]`);

async function fillValid(form) {
	await field(form, 'namn').locator('input').fill('Anna Andersson');
	await field(form, 'epost').locator('input').fill('anna@exempel.se');
}

for (const [lang, t] of Object.entries(PAGES)) {
	test.describe(`sidan på ${lang}`, () => {
		test.beforeEach(async ({ page }) => {
			await page.context().clearCookies();
			await page.goto(t.url);
		});

		test('konfigurationen bär sidans språk', async ({ page }) => {
			expect(await page.evaluate(() => window.relativtFormConfig.lang)).toBe(lang);
		});

		test('språket skickas med både /token och /submit', async ({ page }) => {
			const form = simple(page);
			await fillValid(form);
			await form.locator('.xf-submit').click();
			await expect(form).toHaveClass(/is-submitted/);

			const tokenCalls = await page.evaluate(() => window.__tokenCalls);
			expect(tokenCalls.length).toBeGreaterThan(0);
			for (const href of tokenCalls) {
				expect(new URL(href, 'http://x').searchParams.get('lang')).toBe(lang);
				// Den unika parametern som håller svaret utanför cacher (1.12.2) finns kvar.
				expect(new URL(href, 'http://x').searchParams.get('_')).toMatch(/^\d+$/);
			}
			expect(await page.evaluate(() => window.__mockCalls.at(-1).lang)).toBe(lang);
		});

		test('frekvensspärrens fel från servern visas på sidans språk', async ({ page }) => {
			await page.evaluate(() => { window.__mockScenario = 'rate'; });
			const form = simple(page);
			await fillValid(form);
			await form.locator('.xf-submit').click();

			await expect(form.locator('.xf-form-error')).toHaveText(t.rate);
			await expect(form).not.toHaveClass(/is-submitted/);
		});

		test('tidsspärrens fel visas på sidans språk när omförsöken tagit slut', async ({ page }) => {
			await page.evaluate(() => { window.__mockScenario = 'toofast'; });
			const form = simple(page);
			await fillValid(form);
			await form.locator('.xf-submit').click();

			// Två tysta omförsök först; därefter visas serverns text.
			await expect(form.locator('.xf-form-error')).toHaveText(t.toofast, { timeout: 10000 });
			expect(await page.evaluate(() => window.__mockCalls.length)).toBe(3);
			expect(await page.evaluate(() => window.__mockCalls.every((call) => call.lang === document.documentElement.lang))).toBe(true);
		});

		test('serverns valideringsfel visas på sidans språk', async ({ page }) => {
			await page.evaluate(() => { window.__mockScenario = 'invalid'; });
			const form = simple(page);
			await fillValid(form);
			await form.locator('.xf-submit').click();

			await expect(field(form, 'namn').locator('.xf-error')).toHaveText(t.required);
			await expect(field(form, 'epost').locator('.xf-error')).toHaveText(t.email);
		});

		test('klientens validering säger samma sak som servern', async ({ page }) => {
			const form = simple(page);
			await form.locator('.xf-submit').click();

			await expect(field(form, 'namn').locator('.xf-error')).toHaveText(t.required);
			// Stoppat i webbläsaren: inget anrop gick iväg.
			expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);

			await field(form, 'namn').locator('input').fill('Anna');
			await field(form, 'epost').locator('input').fill('inte-en-adress');
			await form.locator('.xf-submit').click();
			await expect(field(form, 'epost').locator('.xf-error')).toHaveText(t.email);
		});

		test('knappen och tackrutan står på sidans språk', async ({ page }) => {
			const form = simple(page);
			await expect(form.locator('.xf-submit-text')).toHaveText(t.submit);
			await expect(form.locator('.xf-submit')).toHaveAttribute('data-xf-sending', t.sending);

			await fillValid(form);
			await form.locator('.xf-submit').click();
			await expect(form).toHaveClass(/is-submitted/);
			await expect(form.locator('.xf-thanks-title')).toHaveText(t.thanksTitle);
			await expect(form.locator('.xf-thanks-text')).toHaveText(t.thanksText);
		});

		test('landsväljarens etikett står på sidans språk', async ({ page }) => {
			await expect(simple(page).locator('.xf-phone-select')).toHaveAttribute('aria-label', t.country);
		});

		test('stegräknaren och stegknapparna står på sidans språk', async ({ page }) => {
			const form = steps(page);
			await expect(form.locator('[data-xf-progress-text]')).toHaveText(t.step(1, 2));
			await expect(form.locator('[data-xf-next] .xf-step-btn-text')).toHaveText(t.next);

			// Nästa utan namn: stegets validering, på sidans språk.
			await form.locator('[data-xf-next]').click();
			await expect(field(form, 'namn').locator('.xf-error')).toHaveText(t.required);

			await field(form, 'namn').locator('input').fill('Anna Andersson');
			await form.locator('[data-xf-next]').click();
			// JS räknar om texten ur mallen i markupen – även den ska vara översatt.
			await expect(form.locator('[data-xf-progress-text]')).toHaveText(t.step(2, 2));
			await expect(form.locator('[data-xf-prev] .xf-step-btn-text')).toHaveText(t.prev);
			await expect(form.locator('.xf-submit-text')).toHaveText(t.submit);
		});

		test('ett serverfel i ett flerstegsformulär står också på sidans språk', async ({ page }) => {
			await page.evaluate(() => { window.__mockScenario = 'rate'; });
			const form = steps(page);
			await field(form, 'namn').locator('input').fill('Anna Andersson');
			await form.locator('[data-xf-next]').click();
			await expect(form.locator('[data-xf-progress-text]')).toHaveText(t.step(2, 2));
			await field(form, 'epost').locator('input').fill('anna@exempel.se');
			await form.locator('.xf-submit').click();

			await expect(form.locator('.xf-form-error')).toHaveText(t.rate);
			expect(await page.evaluate(() => window.__mockCalls.at(-1).lang)).toBe(lang);
		});
	});
}

test.describe('utan språk i konfigurationen', () => {
	/*
	 * demo-form.html har ingen relativtFormConfig alls – som en sida ur en
	 * sidcache från före 1.15.0. Då ska anropen se ut precis som förut.
	 */
	test('inget lang skickas, varken i /token eller /submit', async ({ page }) => {
		await page.goto('/demo-form.html');
		const form = page.locator('#xf-page .relativt-form');
		await fillValid(form);
		await form.locator('.xf-submit').click();
		await expect(form).toHaveClass(/is-submitted/);

		const tokenCalls = await page.evaluate(() => window.__tokenCalls.map((call) => call.href));
		expect(tokenCalls.length).toBeGreaterThan(0);
		for (const href of tokenCalls) expect(href).not.toContain('lang=');
		expect(await page.evaluate(() => 'lang' in window.__mockCalls.at(-1))).toBe(false);
	});

	test('ett språk som inte ser ut som ett språk skickas inte', async ({ page }) => {
		await page.addInitScript(() => {
			window.relativtFormConfig = { lang: 'sv"><script>' };
		});
		await page.goto('/demo-form.html');
		const form = page.locator('#xf-page .relativt-form');
		await fillValid(form);
		await form.locator('.xf-submit').click();
		await expect(form).toHaveClass(/is-submitted/);

		expect(await page.evaluate(() => 'lang' in window.__mockCalls.at(-1))).toBe(false);
		expect(await page.evaluate(() => window.__tokenCalls.every((call) => !call.href.includes('lang=')))).toBe(true);
	});
});
