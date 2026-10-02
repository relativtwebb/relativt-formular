import { test, expect } from '@playwright/test';

const DEMO = '/demo-form.html';

/** Formuläret på kontaktsidan (första på sidan). */
const page_form = (page) => page.locator('#xf-page .relativt-form');
/** Formuläret i modalen. */
const modal_form = (page) => page.locator('.site-modal .relativt-form');

const field = (form, key) => form.locator(`[data-xf-key="${key}"]`);

/** Fyller i de obligatoriska fälten så inskicket går igenom. */
async function fillValid(form) {
	await field(form, 'namn').locator('input').fill('Anna Andersson');
	await field(form, 'epost').locator('input').fill('anna@exempel.se');
}

test.beforeEach(async ({ page }) => {
	await page.context().clearCookies();
});

/* -----------------------------------------------------------------------------
 * Samtyckeshjälpare
 *
 * Sedan 1.7.0 skrivs kampanjkakan bara med samtycke från ett samtyckesverktyg.
 * Hjälparna simulerar verktygen precis som en riktig sajt ser ut för skriptet.
 * -------------------------------------------------------------------------- */

const ALL = { necessary: true, statistics: true, marketing: true };
const STATS = { necessary: true, statistics: true, marketing: false };
const NONE = { necessary: true, statistics: false, marketing: false };

/**
 * Relativt Cookie Consent: rccCookie och rccConsentVersion i konfigurationen
 * är exakt vad PHP skickar när cookie-pluginet är aktivt. window.rcc
 * efterliknar cookie-pluginets API: getConsent() svarar null när samtycket
 * har en äldre samtyckesversion än sajten. Samtyckescookien sätts bara om
 * den saknas, så att ett test kan ändra den mellan sidladdningar.
 *
 *   version – sajtens samtyckesversion (kakan skrivs med version 1)
 *   api     – false = cookie-pluginets skript har inte körts
 */
async function withRcc(page, consent, extra = {}, { version = 1, api = true } = {}) {
	await page.addInitScript(([value, more, siteVersion, withApi]) => {
		window.relativtFormConfig = { rccCookie: 'relativt_cookie_consent', rccConsentVersion: siteVersion, ...more };
		if (value && !document.cookie.includes('relativt_cookie_consent=')) {
			document.cookie = `relativt_cookie_consent=${encodeURIComponent(JSON.stringify({ version: 1, ...value }))}; path=/`;
		}
		if (withApi) {
			const read = () => {
				const row = document.cookie.split('; ').find((r) => r.startsWith('relativt_cookie_consent='));
				if (!row) return null;
				const consent = JSON.parse(decodeURIComponent(row.slice('relativt_cookie_consent='.length)));
				return (parseInt(consent.version, 10) || 1) === siteVersion ? consent : null;
			};
			window.rcc = { getConsent: read, hasConsent: (category) => !!read()?.[category] };
		}
	}, [consent, extra, version, api]);
}

/**
 * WP Consent API. Dess skript köas med mycket hög prioritet och körs EFTER
 * formulärskriptet – här efterliknat med DOMContentLoaded, som kommer efter
 * demons inline-skript. wp_has_consent har samma logik som API:ets egen.
 */
async function withWpConsent(page, { type = 'optin', cookies = {}, extra = {} } = {}) {
	await page.addInitScript(([consentType, values, more]) => {
		window.relativtFormConfig = { wpConsentApi: true, ...(window.relativtFormConfig ?? {}), ...more };
		for (const [category, value] of Object.entries(values)) {
			document.cookie = `wp_consent_${category}=${value}; path=/`;
		}
		document.addEventListener('DOMContentLoaded', () => {
			window.wp_fallback_consent_type = consentType;
			window.wp_has_consent = (category) => {
				const type = typeof window.wp_consent_type !== 'undefined' ? window.wp_consent_type : window.wp_fallback_consent_type;
				const row = document.cookie.split('; ').find((r) => r.startsWith(`wp_consent_${category}=`));
				const value = row ? row.split('=')[1] : '';
				if (!type) return true;
				if (type.includes('optout') && value === '') return true;
				return value === 'allow';
			};
			window.wp_set_consent = (category, value) => {
				document.cookie = `wp_consent_${category}=${value}; path=/`;
				const changed = [];
				changed[category] = value;
				document.dispatchEvent(new CustomEvent('wp_listen_for_consent_change', { detail: changed }));
			};
		});
	}, [type, cookies, extra]);
}

/** Kampanjkakan som objekt, eller null. */
const srcCookie = (page) => page.evaluate(() => {
	const row = document.cookie.split('; ').find((r) => r.startsWith('xf_src='));
	return row ? JSON.parse(decodeURIComponent(row.slice('xf_src='.length))) : null;
});

/** Skickar sidformuläret och returnerar nyttolasten. */
async function submitPage(page) {
	const form = page_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);
	const calls = await page.evaluate(() => window.__mockCalls);
	return calls[calls.length - 1];
}

/* -----------------------------------------------------------------------------
 * Förval via shortcode-attribut
 * -------------------------------------------------------------------------- */

test('shortcode-attributet sätter förvalt värde', async ({ page }) => {
	await page.goto(DEMO);

	const sida = page_form(page);
	await expect(sida).toHaveClass(/is-ready/);
	await expect(field(sida, 'jagar').locator('input[value="foretag"]')).toBeChecked();

	const modal = modal_form(page);
	await expect(field(modal, 'jagar').locator('input[value="kandidat"]')).toBeChecked();
});

test('URL-parametern överstyr shortcode-attributet', async ({ page }) => {
	await page.goto(`${DEMO}?jagar=kandidat`);

	const sida = page_form(page);
	await expect(field(sida, 'jagar').locator('input[value="kandidat"]')).toBeChecked();
	await expect(field(sida, 'jagar').locator('input[value="foretag"]')).not.toBeChecked();
});

/* -----------------------------------------------------------------------------
 * Villkorlig fältvisning
 * -------------------------------------------------------------------------- */

test('rätt rullista visas för Företag respektive Kandidat', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await expect(field(form, 'behov')).toBeVisible();
	await expect(field(form, 'onskemal')).toBeHidden();

	await field(form, 'jagar').locator('label[for$="jagar-1"]').click();

	await expect(field(form, 'behov')).toBeHidden();
	await expect(field(form, 'onskemal')).toBeVisible();
});

test('villkor skrivet med den synliga etiketten fungerar också', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	// Kunden skriver "Företag" i wp-admin istället för det tekniska "foretag".
	await page.evaluate(() => {
		document.querySelector('#xf-page [data-xf-key="behov"]').dataset.xfCondValue = 'Företag';
		document.querySelector('#xf-page [data-xf-key="onskemal"]').dataset.xfCondValue = 'Kandidat';
		document.querySelector('#xf-page .xf-form').dispatchEvent(new Event('change', { bubbles: true }));
	});

	await expect(field(form, 'behov')).toBeVisible();
	await expect(field(form, 'onskemal')).toBeHidden();

	await field(form, 'jagar').locator('label[for$="jagar-1"]').click();
	await expect(field(form, 'onskemal')).toBeVisible();
});

/*
 * Regression 2026-08-12. Villkorsfälten renderades alltid dolda och revealades
 * av JS. Laddades inte JS syntes de aldrig. Nu utvärderar servern villkoren
 * utifrån förvalet, så rätt rullista är på plats redan utan JavaScript.
 */
test.describe('utan JavaScript', () => {
	test.use({ javaScriptEnabled: false });

	test('rätt rullista är ändå synlig på kontaktsidan', async ({ page }) => {
		await page.goto(DEMO);
		const form = page_form(page);

		await expect(field(form, 'behov')).toBeVisible();
		await expect(field(form, 'onskemal')).toBeHidden();
	});

	test('modalens formulär visar kandidatvarianten', async ({ page }) => {
		await page.goto(DEMO);
		const form = modal_form(page);

		await expect(field(form, 'onskemal')).not.toHaveAttribute('hidden', /.*/);
		await expect(field(form, 'behov')).toHaveAttribute('hidden', /.*/);
	});
});

test('URL-parameter med den synliga etiketten förväljer rätt', async ({ page }) => {
	await page.goto(`${DEMO}?jagar=Kandidat`);
	const form = page_form(page);

	await expect(field(form, 'jagar').locator('input[value="kandidat"]')).toBeChecked();
	await expect(field(form, 'onskemal')).toBeVisible();
});

test('okänt värde i URL:en rör inte förvalet', async ({ page }) => {
	await page.goto(`${DEMO}?jagar=leverantor`);
	const form = page_form(page);

	await expect(field(form, 'jagar').locator('input[value="foretag"]')).toBeChecked();
});

test('villkorsdolda fält skickas inte med i nyttolasten', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);

	const [body] = await page.evaluate(() => window.__mockCalls);
	expect(body.fields).toHaveProperty('behov');
	expect(body.fields).not.toHaveProperty('onskemal');
	expect(body.fields.jagar).toBe('foretag');
});

/*
 * Regression 1.1.3: fälttypen Dolt fält skickades ALDRIG med – nyttolasten
 * hoppade över alla fält med dold wrapper, och typen Dolt fält bär samma
 * hidden-attribut som villkorsdolda fält. Bara villkoret får avgöra.
 */
test('fälttypen Dolt fält följer däremot med, med sitt shortcode-värde', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);

	const [body] = await page.evaluate(() => window.__mockCalls);
	expect(body.fields.audit).toBe('Webb');
});

/* -----------------------------------------------------------------------------
 * Validering
 * -------------------------------------------------------------------------- */

test('tomt formulär skickas aldrig till servern', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await form.locator('.xf-submit').click();

	await expect(field(form, 'namn')).toHaveClass(/has-error/);
	await expect(field(form, 'namn').locator('.xf-error')).toHaveText('Fyll i detta fält.');
	await expect(field(form, 'epost')).toHaveClass(/has-error/);

	expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
});

test('felaktig e-postadress fångas', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await field(form, 'namn').locator('input').fill('Anna');
	await field(form, 'epost').locator('input').fill('anna@');
	await form.locator('.xf-submit').click();

	await expect(field(form, 'epost').locator('.xf-error')).toHaveText('Kontrollera e-postadressen.');
	expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
});

/*
 * Telefon- och e-postreglerna finns i både PHP och JS. Går de isär godkänner
 * webbläsaren något servern sedan avvisar, vilket ser ut som ett slumpmässigt
 * fel för besökaren. Listorna nedan speglar dem i tests/server-test.php.
 */
const GILTIGA_NUMMER = ['070-123 45 67', '0701234567', '+46 70 123 45 67', '+46(0)70 123 45 67', '0046701234567', '701234567', '08-12 34 56', '+44 20 7946 0958'];
const OGILTIGA_NUMMER = ['ring mig', '070-ABC', '123', '0000000000', '+4', '070123456789012345'];

test('giltiga telefonnummer släpps igenom', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);
	await fillValid(form);

	for (const nummer of GILTIGA_NUMMER) {
		await field(form, 'telefon').locator('input').fill(nummer);
		await form.locator('.xf-submit').click();

		const fel = await field(form, 'telefon').locator('.xf-error').textContent();
		expect(fel, `${nummer} borde godkännas`).toBe('');

		// Kom vi vidare betyder det att valideringen släppte igenom.
		await expect(form).toHaveClass(/is-submitted/);
		await page.reload();
		await fillValid(form);
	}
});

test('ogiltiga telefonnummer stoppas innan servern', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);
	await fillValid(form);

	for (const nummer of OGILTIGA_NUMMER) {
		await field(form, 'telefon').locator('input').fill(nummer);
		await form.locator('.xf-submit').click();

		await expect(field(form, 'telefon'), `${nummer} borde avvisas`).toHaveClass(/has-error/);
		await expect(field(form, 'telefon').locator('.xf-error')).toContainText('070-123 45 67');
	}

	expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
});

test('e-postvalideringen fångar det is_email släpper igenom', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	for (const adress of ['anna@exempel', 'anna..a@exempel.se', 'anna@exempel.s', 'anna@.exempel.se']) {
		await field(form, 'namn').locator('input').fill('Anna');
		await field(form, 'epost').locator('input').fill(adress);
		await form.locator('.xf-submit').click();

		await expect(field(form, 'epost'), `${adress} borde avvisas`).toHaveClass(/has-error/);
	}
});

test('telefonfältet får rätt tangentbord på mobil', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await expect(field(form, 'telefon').locator('input')).toHaveAttribute('inputmode', 'tel');
	await expect(field(form, 'telefon').locator('input')).toHaveAttribute('autocomplete', 'tel');
	await expect(field(form, 'epost').locator('input')).toHaveAttribute('inputmode', 'email');
	await expect(field(form, 'epost').locator('input')).toHaveAttribute('autocomplete', 'email');
});

test('felet försvinner när besökaren rättar sig', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await form.locator('.xf-submit').click();
	await expect(field(form, 'namn')).toHaveClass(/has-error/);

	await field(form, 'namn').locator('input').fill('Anna');
	await expect(field(form, 'namn')).not.toHaveClass(/has-error/);
});

test('serverns fältfel visas på rätt fält', async ({ page }) => {
	await page.goto(DEMO);
	await page.evaluate(() => {
		window.__mockFail = { status: 422, payload: { ok: false, errors: { epost: 'Adressen är spärrad.' } } };
	});

	const form = page_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();

	await expect(field(form, 'epost').locator('.xf-error')).toHaveText('Adressen är spärrad.');
	await expect(form).not.toHaveClass(/is-submitted/);
});

/* -----------------------------------------------------------------------------
 * Kampanjspårning
 * -------------------------------------------------------------------------- */

test('UTM följer med besökaren mellan sidladdningar', async ({ page }) => {
	await withRcc(page, ALL);
	await page.goto(`${DEMO}?utm_source=google&utm_medium=cpc&utm_campaign=rekrytering&gclid=abc123`);

	// Besökaren surfar vidare till en ren URL – taggarna finns inte längre där.
	await page.goto(DEMO);

	const form = page_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);

	const [body] = await page.evaluate(() => window.__mockCalls);
	expect(body.utm.utm_source).toBe('google');
	expect(body.utm.utm_medium).toBe('cpc');
	expect(body.utm.utm_campaign).toBe('rekrytering');
	expect(body.utm.gclid).toBe('abc123');
	expect(body.utm.landing).toContain('demo-form.html');
});

test('en ny kampanjlänk skriver över den gamla, men direktbesök gör det inte', async ({ page }) => {
	await withRcc(page, ALL);
	await page.goto(`${DEMO}?utm_source=google`);
	await page.goto(DEMO); // direktbesök – ska inte nolla något
	await page.goto(`${DEMO}?utm_source=linkedin`);

	const source = await page.evaluate(() => window.relativtForm.source());
	expect(source.utm_source).toBe('linkedin');

	await page.goto(DEMO);
	expect(await page.evaluate(() => window.relativtForm.source().utm_source)).toBe('linkedin');
});

test('inskick utan kampanj har tom UTM men känd sida', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);

	const [body] = await page.evaluate(() => window.__mockCalls);
	expect(body.utm.utm_source).toBeUndefined();
	expect(body.page).toContain('demo-form.html');
});

/* -----------------------------------------------------------------------------
 * Spamskydd
 * -------------------------------------------------------------------------- */

test('honungsfällan ligger utanför skärmen och är inte fokuserbar', async ({ page }) => {
	await page.goto(DEMO);
	const honeypot = page_form(page).locator('[name="xf_website"]');

	await expect(honeypot).toHaveAttribute('tabindex', '-1');

	const box = await honeypot.boundingBox();
	expect(box === null || box.x < 0).toBeTruthy();
});

test('ifylld honungsfälla skickas med så servern kan tysta boten', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await fillValid(form);
	await form.locator('[name="xf_website"]').fill('https://spam.example', { force: true });
	await form.locator('.xf-submit').click();

	const [body] = await page.evaluate(() => window.__mockCalls);
	expect(body.xf_website).toBe('https://spam.example');
});

test('token hämtas innan inskicket och följer med', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);

	const [body] = await page.evaluate(() => window.__mockCalls);
	expect(body.nonce).toBe('test-nonce');
	expect(body.sig).toBe('test-sig');
	expect(body.ts).toBeGreaterThan(0);
});

/* -----------------------------------------------------------------------------
 * Tack-läge
 * -------------------------------------------------------------------------- */

test('lyckat inskick visar tack-rutan med serverns text', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await fillValid(form);
	await form.locator('.xf-submit').click();

	await expect(form.locator('.xf-form')).toBeHidden();
	await expect(form.locator('.xf-thanks')).toBeVisible();
	await expect(form.locator('.xf-thanks-title')).toHaveText('Tack för ditt meddelande!');
	await expect(form.locator('.xf-thanks-text')).toHaveText('Vi återkommer till dig så snart vi kan.');

	// tabindex="-1" i markupen gör fokusflytten möjlig – utan den är focus()
	// en tyst no-op och skärmläsaren blir kvar i det dolda formuläret.
	await expect(form.locator('.xf-thanks')).toBeFocused();
});

test('lyckat inskick sänder ett event som GTM kan lyssna på', async ({ page }) => {
	await page.goto(DEMO);
	await page.evaluate(() => {
		window.__events = [];
		document.addEventListener('relativt-form:success', (e) => window.__events.push(e.detail.formId));
	});

	const form = page_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);

	expect(await page.evaluate(() => window.__events)).toEqual(['12']);
});

test('serverfel visar felmeddelande utan att låsa formuläret', async ({ page }) => {
	await page.goto(DEMO);
	await page.evaluate(() => {
		window.__mockFail = { status: 500, payload: { ok: false, message: 'Servern svarar inte.' } };
	});

	const form = page_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();

	await expect(form.locator('.xf-form-error')).toHaveText('Servern svarar inte.');
	await expect(form.locator('.xf-submit')).toBeEnabled();
	await expect(form.locator('.xf-submit-text')).toHaveText('Skicka');
});

/* -----------------------------------------------------------------------------
 * Modal
 * -------------------------------------------------------------------------- */

test('inskick i modalen anropar modalens tack-hook', async ({ page }) => {
	await page.goto(DEMO);
	await page.locator('.demo-open').click();

	const form = modal_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();

	await expect(form).toHaveClass(/is-submitted/);
	expect(await page.evaluate(() => window.relativtFormModal.submitted)).toEqual(['kontakt']);
	await expect(page.locator('.site-modal')).toHaveClass(/is-submitted/);
});

test('modalens formulär skickar sitt eget förval, inte sidans', async ({ page }) => {
	await page.goto(DEMO);
	await page.locator('.demo-open').click();

	const form = modal_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);

	const [body] = await page.evaluate(() => window.__mockCalls);
	expect(body.fields.jagar).toBe('kandidat');
	expect(body.fields).toHaveProperty('onskemal');
	expect(body.fields).not.toHaveProperty('behov');
});

/* -----------------------------------------------------------------------------
 * Oxygen-regressioner
 * -------------------------------------------------------------------------- */

test('id-regel på föräldern (flex row) välter inte rutnätet', async ({ page }) => {
	await page.goto(DEMO);

	// #div_block-42-9 sätter flex-direction: row, precis som Oxygen gör så fort
	// man rör Layout på ett element. Roten måste ändå fylla bredden.
	const parentWidth = await page.locator('#div_block-42-9').evaluate((el) => el.getBoundingClientRect().width);
	const formWidth = await page_form(page).evaluate((el) => el.getBoundingClientRect().width);
	const padding = await page.locator('#div_block-42-9').evaluate((el) => parseFloat(getComputedStyle(el).paddingLeft) * 2);

	expect(Math.abs(parentWidth - padding - formWidth)).toBeLessThan(2);
});

test('halva fält ligger sida vid sida på desktop och staplas på mobil', async ({ page }, testInfo) => {
	await page.goto(DEMO);
	const form = page_form(page);

	const namn = await field(form, 'namn').boundingBox();
	const foretag = await field(form, 'foretag').boundingBox();

	if (testInfo.project.name === 'mobil') {
		expect(foretag.y).toBeGreaterThan(namn.y + namn.height - 1);
	} else {
		expect(Math.abs(namn.y - foretag.y)).toBeLessThan(2);
		expect(foretag.x).toBeGreaterThan(namn.x + namn.width - 1);
	}
});

test('val-knapparna markerar valt alternativ visuellt', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	const foretagLabel = field(form, 'jagar').locator('label[for$="jagar-0"]');
	const kandidatLabel = field(form, 'jagar').locator('label[for$="jagar-1"]');

	const bg = (locator) => locator.evaluate((el) => getComputedStyle(el).backgroundColor);

	expect(await bg(foretagLabel)).not.toBe(await bg(kandidatLabel));

	await kandidatLabel.click();
	expect(await bg(kandidatLabel)).not.toBe(await bg(foretagLabel));
});

test('formuläret initieras inte i Oxygens builder', async ({ page }) => {
	await page.goto(DEMO);
	await page.evaluate(() => {
		document.body.classList.add('oxygen-builder-body');
		document.querySelectorAll('.relativt-form').forEach((el) => el.classList.remove('is-ready'));
		window.relativtForm.init();
	});

	await expect(page_form(page)).not.toHaveClass(/is-ready/);
});

/* -----------------------------------------------------------------------------
 * Tyst omsändning
 *
 * Två serverfel ska besökaren ALDRIG behöva se: "toofast" (tidsspärren, som
 * autofyll-användare träffar) och "nonce" (utgången nonce i en gammal flik).
 * JS gör om inskicket självt; mockens once:true låter första försöket falla
 * och det andra lyckas.
 * -------------------------------------------------------------------------- */

test('för snabbt inskick görs om tyst efter väntetiden', async ({ page }) => {
	await page.goto(DEMO);
	await page.evaluate(() => {
		window.__mockFail = { status: 425, payload: { ok: false, code: 'toofast', retry_after: 1 }, once: true };
	});

	const form = page_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();

	await expect(form).toHaveClass(/is-submitted/, { timeout: 10000 });
	expect(await page.evaluate(() => window.__mockCalls.length)).toBe(2);
	await expect(form.locator('.xf-form-error')).toHaveText('');
});

test('utgången nonce hämtar ny token och gör om inskicket', async ({ page }) => {
	await page.goto(DEMO);
	await page.evaluate(() => {
		window.__mockFail = { status: 403, payload: { ok: false, code: 'nonce', message: 'Sessionen har gått ut.' }, once: true };
	});

	const form = page_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();

	await expect(form).toHaveClass(/is-submitted/);
	expect(await page.evaluate(() => window.__mockCalls.length)).toBe(2);
});

/* -----------------------------------------------------------------------------
 * Länkspärr och tillgänglighet
 * -------------------------------------------------------------------------- */

test('fler än tre länkar i meddelandet stoppas innan servern', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await fillValid(form);
	await field(form, 'meddelande').locator('textarea').fill('Kolla https://a.se https://b.se www.c.se och https://d.se');
	await form.locator('.xf-submit').click();

	await expect(field(form, 'meddelande')).toHaveClass(/has-error/);
	await expect(field(form, 'meddelande').locator('.xf-error')).toHaveText('Meddelandet innehåller för många länkar.');
	expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
});

test('fel kopplas till fältet för skärmläsare', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await form.locator('.xf-submit').click();

	const input = field(form, 'namn').locator('input');
	await expect(input).toHaveAttribute('aria-invalid', 'true');

	const errorId = await field(form, 'namn').locator('.xf-error').getAttribute('id');
	expect(await input.getAttribute('aria-describedby')).toContain(errorId);

	await input.fill('Anna');
	await expect(input).not.toHaveAttribute('aria-invalid', /.*/);
});

test('obligatorisk grupp utan required-attribut stoppas via data-xf-required', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await fillValid(form);
	// Flervalsgrupper kan inte bära required-attributet – flaggan på wrappern
	// är vad klientvalideringen läser. Telefonfältet får agera testyta.
	await page.evaluate(() => {
		document.querySelector('#xf-page [data-xf-key="telefon"]').dataset.xfRequired = '1';
	});
	await form.locator('.xf-submit').click();

	await expect(field(form, 'telefon')).toHaveClass(/has-error/);
	await expect(field(form, 'telefon').locator('.xf-error')).toHaveText('Fyll i detta fält.');
	expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
});

test('fälten för besökarens uppgifter talar om vad de gäller (WCAG 1.3.5)', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await expect(field(form, 'namn').locator('input')).toHaveAttribute('autocomplete', 'name');
	await expect(field(form, 'foretag').locator('input')).toHaveAttribute('autocomplete', 'organization');
	await expect(field(form, 'epost').locator('input')).toHaveAttribute('autocomplete', 'email');
	await expect(field(form, 'telefon').locator('input')).toHaveAttribute('autocomplete', 'tel');
	await expect(field(form, 'meddelande').locator('textarea')).not.toHaveAttribute('autocomplete', /.+/);
});

test('hjälptexten renderas under fältet', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await expect(field(form, 'meddelande').locator('.xf-help')).toHaveText('Berätta gärna kort vad det gäller.');

	const helpId = await field(form, 'meddelande').locator('.xf-help').getAttribute('id');
	expect(await field(form, 'meddelande').locator('textarea').getAttribute('aria-describedby')).toContain(helpId);
});

/* -----------------------------------------------------------------------------
 * Visa etikett (1.3.0)
 *
 * "Visa etikett" döljer etiketten VISUELLT, inte ur DOM:en. Testerna nedan
 * verifierar det med role/name-lokatorer – de går via webbläsarens riktiga
 * tillgänglighetsträd, så de bevisar att fältet fortfarande har ett namn för
 * skärmläsare, inte bara att någon text råkar ligga kvar i markupen.
 * -------------------------------------------------------------------------- */

test('textfält med avstängd etikett har fortfarande sitt tillgängliga namn', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	const input = form.getByRole('textbox', { name: 'Smeknamn' });
	await expect(input).toBeAttached();

	// Etiketten ligger kvar i DOM:en, men görs visuellt osynlig.
	const label = field(form, 'smeknamn').locator('.xf-label');
	await expect(label).toHaveText('Smeknamn');
	await expect(label).toHaveClass(/xf-sr-only/);
	const box = await label.boundingBox();
	expect(box.width).toBeLessThanOrEqual(1);
	expect(box.height).toBeLessThanOrEqual(1);
});

test('kryssruta med avstängd etikett går fortfarande att kryssa i och har sitt namn kvar', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	const checkbox = form.getByRole('checkbox', { name: 'Jag vill ha nyhetsbrevet' });
	await expect(checkbox).toBeAttached();
	await checkbox.check({ force: true });
	await expect(checkbox).toBeChecked();

	const text = field(form, 'nyhetsbrev').locator('.xf-check-text');
	await expect(text).toHaveClass(/xf-sr-only/);
});

test('gruppfält (radio) med avstängd etikett behåller sitt aria-labelledby-namn', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	const group = form.getByRole('radiogroup', { name: 'Språk' });
	await expect(group).toBeAttached();

	const label = field(form, 'sprak').locator('.xf-label');
	await expect(label).toHaveClass(/xf-sr-only/);
});

/* -----------------------------------------------------------------------------
 * Samtycke och kampanjkakan: Relativt Cookie Consent
 * -------------------------------------------------------------------------- */

test('utan samtycke skrivs ingen kampanjkaka, men attributionen följer ändå med', async ({ page }) => {
	await withRcc(page, NONE);
	await page.goto(`${DEMO}?utm_source=google&utm_medium=cpc`);

	expect(await page.evaluate(() => document.cookie)).not.toContain('xf_src=');

	// Minnesposten finns kvar – inskick från landningssidan attribueras ändå.
	const form = page_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);

	const [body] = await page.evaluate(() => window.__mockCalls);
	expect(body.utm.utm_source).toBe('google');
});

test('kampanjkakan skrivs i efterhand när samtycket kommer', async ({ page }) => {
	await withRcc(page, null);
	await page.goto(`${DEMO}?utm_source=linkedin`);

	// Inget beslut i bannern ännu – ingen kaka.
	expect(await page.evaluate(() => document.cookie)).not.toContain('xf_src=');

	await page.evaluate(() => {
		document.dispatchEvent(new CustomEvent('rcc_consent_updated', {
			detail: { necessary: true, statistics: true, marketing: false },
		}));
	});

	expect(await page.evaluate(() => document.cookie)).toContain('xf_src=');
	expect(await page.evaluate(() => window.relativtForm.source().utm_source)).toBe('linkedin');
});

/* -----------------------------------------------------------------------------
 * Samtycke och kampanjkakan: kategorierna (1.7.0)
 *
 * UTM, landningssida och hänvisare räcker det med statistik för. gclid och
 * fbclid kräver marknadsföring – utan det lever de bara i minnet på sidan.
 * -------------------------------------------------------------------------- */

const LANDING = `${DEMO}?utm_source=google&utm_medium=cpc&gclid=abc123&fbclid=def456`;

test('statistiksamtycke: UTM sparas, gclid och fbclid stannar i webbläsarens minne', async ({ page }) => {
	await withRcc(page, STATS);
	await page.goto(LANDING);

	const cookie = await srcCookie(page);
	expect(cookie.utm_source).toBe('google');
	expect(cookie.landing).toContain('demo-form.html');
	expect(cookie.gclid).toBeUndefined();
	expect(cookie.fbclid).toBeUndefined();

	// I minnet på landningssidan…
	expect(await page.evaluate(() => window.relativtForm.source().gclid)).toBe('abc123');

	// …men de följer inte med inskicket (1.8.0). UTM gör det.
	const body = await submitPage(page);
	expect(body.utm.utm_source).toBe('google');
	expect(body.utm.gclid).toBeUndefined();
	expect(body.utm.fbclid).toBeUndefined();

	// Nästa sida: bara det som fick sparas.
	await page.goto(DEMO);
	const source = await page.evaluate(() => window.relativtForm.source());
	expect(source.utm_source).toBe('google');
	expect(source.gclid).toBeUndefined();
});

test('marknadsföringssamtycke: klick-id sparas i kakan', async ({ page }) => {
	await withRcc(page, ALL);
	await page.goto(LANDING);

	const cookie = await srcCookie(page);
	expect(cookie.gclid).toBe('abc123');
	expect(cookie.fbclid).toBe('def456');
	expect(await page.evaluate(() => window.relativtForm.consent())).toEqual({
		mode: 'auto', tool: 'rcc', attribution: true, clickIds: true,
	});

	const body = await submitPage(page);
	expect(body.utm.gclid).toBe('abc123');
	expect(body.utm.fbclid).toBe('def456');
});

test('klick-id som ges samtycke senare på sidan följer med inskicket', async ({ page }) => {
	await withRcc(page, STATS);
	await page.goto(LANDING);

	await page.evaluate((detail) => {
		document.dispatchEvent(new CustomEvent('rcc_consent_updated', { detail }));
	}, ALL);

	const body = await submitPage(page);
	expect(body.utm.gclid).toBe('abc123');
});

/* -----------------------------------------------------------------------------
 * Samtycke och kampanjkakan: samtyckesversionen i Relativt Cookie Consent (1.8.0)
 * -------------------------------------------------------------------------- */

test('ett samtycke med äldre samtyckesversion räknas inte', async ({ page }) => {
	// Kakan har version 1, sajten har höjt till 2 – rutan visas igen.
	await withRcc(page, ALL, {}, { version: 2 });
	await page.goto(LANDING);

	expect(await srcCookie(page)).toBeNull();
	expect(await page.evaluate(() => window.relativtForm.consent())).toEqual({
		mode: 'auto', tool: 'rcc', attribution: false, clickIds: false,
	});
	const body = await submitPage(page);
	expect(body.utm.gclid).toBeUndefined();
});

test('utan cookie-pluginets skript läses samtyckeskakan direkt, men bara med rätt version', async ({ page }) => {
	await withRcc(page, ALL, {}, { version: 1, api: false });
	await page.goto(LANDING);

	expect((await srcCookie(page)).gclid).toBe('abc123');
});

test('utan cookie-pluginets skript och med höjd version skrivs ingen kaka', async ({ page }) => {
	await withRcc(page, ALL, {}, { version: 2, api: false });
	await page.goto(LANDING);

	expect(await srcCookie(page)).toBeNull();
});

test('utan cookie-pluginets skript och okänd version räknas samtyckeskakan inte', async ({ page }) => {
	await withRcc(page, ALL, { rccConsentVersion: null }, { api: false });
	await page.goto(LANDING);

	expect(await srcCookie(page)).toBeNull();
});

test('cookie-pluginets API används även när det laddas efter formulärskriptet', async ({ page }) => {
	await withRcc(page, ALL, {}, { version: 2, api: false });
	// API:et dyker upp efter formulärskriptet och säger nej (inaktuell version),
	// fast kakan själv ser ut att ge samtycke.
	await page.addInitScript(() => {
		document.addEventListener('DOMContentLoaded', () => {
			window.rcc = { getConsent: () => null, hasConsent: () => false };
			document.dispatchEvent(new Event('rcc_ready'));
		});
	});
	await page.goto(LANDING);

	expect(await srcCookie(page)).toBeNull();
	expect((await page.evaluate(() => window.relativtForm.consent())).tool).toBe('rcc');
});

test('nedgradering till bara statistik skalar av klick-id utan att förlänga kakan', async ({ page }) => {
	await withRcc(page, ALL);
	await page.goto(LANDING);

	const expiresBefore = (await page.context().cookies()).find((c) => c.name === 'xf_src').expires;
	expect((await srcCookie(page)).gclid).toBe('abc123');

	await page.evaluate((detail) => {
		document.dispatchEvent(new CustomEvent('rcc_consent_updated', { detail }));
	}, STATS);

	const cookie = await srcCookie(page);
	expect(cookie.utm_source).toBe('google');
	expect(cookie.gclid).toBeUndefined();
	expect(cookie.fbclid).toBeUndefined();

	const expiresAfter = (await page.context().cookies()).find((c) => c.name === 'xf_src').expires;
	expect(Math.abs(expiresAfter - expiresBefore)).toBeLessThan(2);

	// Besökaren landade här med klick-id – de finns kvar i minnet på sidan.
	expect(await page.evaluate(() => window.relativtForm.source().gclid)).toBe('abc123');
});

test('dras marknadsföring tillbaka mellan sidladdningar läses klick-id inte ur kakan', async ({ page }) => {
	await withRcc(page, ALL);
	await page.goto(LANDING);
	expect((await srcCookie(page)).gclid).toBe('abc123');

	// Samtycket ändras i en annan flik – nästa sidladdning ser bara statistik.
	await page.evaluate((value) => {
		document.cookie = `relativt_cookie_consent=${encodeURIComponent(JSON.stringify(value))}; path=/`;
	}, STATS);
	await page.goto(DEMO);

	const cookie = await srcCookie(page);
	expect(cookie.utm_source).toBe('google');
	expect(cookie.gclid).toBeUndefined();
	expect(await page.evaluate(() => window.relativtForm.source().gclid)).toBeUndefined();

	// Ges samtycket igen kommer de avskalade värdena inte tillbaka.
	await page.evaluate((detail) => {
		document.dispatchEvent(new CustomEvent('rcc_consent_updated', { detail }));
	}, ALL);
	expect((await srcCookie(page)).gclid).toBeUndefined();
	expect((await srcCookie(page)).utm_source).toBe('google');
});

test('kategorierna kommer från PHP-filtret', async ({ page }) => {
	await withRcc(page, STATS, { consentCategories: { attribution: ['marketing'], clickIds: ['marketing'] } });
	await page.goto(LANDING);

	// Statistik räcker inte längre för attributionen.
	expect(await srcCookie(page)).toBeNull();

	await page.evaluate((detail) => {
		document.dispatchEvent(new CustomEvent('rcc_consent_updated', { detail }));
	}, ALL);

	const cookie = await srcCookie(page);
	expect(cookie.utm_source).toBe('google');
	expect(cookie.gclid).toBe('abc123');
});

/* -----------------------------------------------------------------------------
 * Samtycke och kampanjkakan: utan samtyckesverktyg (1.7.0)
 * -------------------------------------------------------------------------- */

test('utan samtyckesverktyg skrivs ingen kampanjkaka, men attributionen följer med från landningssidan', async ({ page }) => {
	await page.goto(LANDING);

	expect(await srcCookie(page)).toBeNull();
	expect(await page.evaluate(() => window.relativtForm.consent())).toEqual({
		mode: 'auto', tool: null, attribution: false, clickIds: false,
	});

	// UTM följer med inskicket från landningssidan; klick-id gör det inte.
	const body = await submitPage(page);
	expect(body.utm.utm_source).toBe('google');
	expect(body.utm.gclid).toBeUndefined();

	await page.goto(DEMO);
	expect(await page.evaluate(() => window.relativtForm.source().utm_source)).toBeUndefined();
});

test('utan samtyckesverktyg tas en kaka från en äldre version bort och används inte', async ({ page, baseURL }) => {
	const old = { utm_source: 'gammal', gclid: 'x', landing: `${baseURL}/`, referrer: '', t: Date.now() };
	await page.context().addCookies([{ name: 'xf_src', value: encodeURIComponent(JSON.stringify(old)), url: baseURL }]);

	await page.goto(DEMO);

	expect(await srcCookie(page)).toBeNull();
	expect(await page.evaluate(() => window.relativtForm.source().utm_source)).toBeUndefined();
});

test("läget 'always' skriver kakan med klick-id utan samtyckesverktyg, som före 1.7.0", async ({ page }) => {
	await page.addInitScript(() => {
		window.relativtFormConfig = { utmCookie: 'always' };
	});
	await page.goto(LANDING);

	const cookie = await srcCookie(page);
	expect(cookie.utm_source).toBe('google');
	expect(cookie.gclid).toBe('abc123');
});

/* -----------------------------------------------------------------------------
 * Samtycke och kampanjkakan: WP Consent API (1.7.0)
 * -------------------------------------------------------------------------- */

test('WP Consent API: kategorierna läses med wp_has_consent och följer ändringshändelsen', async ({ page }) => {
	await withWpConsent(page, { cookies: { statistics: 'allow' } });
	await page.goto(LANDING);

	let cookie = await srcCookie(page);
	expect(cookie.utm_source).toBe('google');
	expect(cookie.gclid).toBeUndefined();
	expect((await page.evaluate(() => window.relativtForm.consent())).tool).toBe('wp-consent-api');

	await page.evaluate(() => window.wp_set_consent('marketing', 'allow'));
	cookie = await srcCookie(page);
	expect(cookie.gclid).toBe('abc123');

	// Nedgradering till bara statistik.
	await page.evaluate(() => window.wp_set_consent('marketing', 'deny'));
	cookie = await srcCookie(page);
	expect(cookie.utm_source).toBe('google');
	expect(cookie.gclid).toBeUndefined();

	await page.evaluate(() => window.wp_set_consent('statistics', 'deny'));
	expect(await srcCookie(page)).toBeNull();
});

test('WP Consent API utan samtyckestyp räknas inte som samtyckesverktyg', async ({ page }) => {
	// API:et själv svarar ja på allt när inget samtyckesverktyg satt en typ.
	await withWpConsent(page, { type: '' });
	await page.goto(LANDING);

	expect(await srcCookie(page)).toBeNull();
	expect((await page.evaluate(() => window.relativtForm.consent())).tool).toBeNull();
});

/* -----------------------------------------------------------------------------
 * Samtycke och kampanjkakan: JS-kroken och prioriteten (1.7.0)
 * -------------------------------------------------------------------------- */

test('JS-kroken: egen samtyckeslösning, även när den definieras efter formulärskriptet', async ({ page }) => {
	await page.addInitScript(() => {
		document.addEventListener('DOMContentLoaded', () => {
			window.relativtFormConsent = (category) => category === 'statistics';
		});
	});
	await page.goto(LANDING);

	let cookie = await srcCookie(page);
	expect(cookie.utm_source).toBe('google');
	expect(cookie.gclid).toBeUndefined();
	expect((await page.evaluate(() => window.relativtForm.consent())).tool).toBe('hook');

	await page.evaluate(() => {
		document.dispatchEvent(new CustomEvent('relativt-form:consent', { detail: { marketing: true } }));
	});
	cookie = await srcCookie(page);
	expect(cookie.gclid).toBe('abc123');

	await page.evaluate(() => {
		document.dispatchEvent(new CustomEvent('relativt-form:consent', { detail: { statistics: false, marketing: false } }));
	});
	expect(await srcCookie(page)).toBeNull();
});

test('ett trasigt samtyckesverktyg betyder aldrig ja', async ({ page }) => {
	await page.addInitScript(() => {
		window.relativtFormConsent = () => { throw new Error('trasig'); };
	});
	await page.goto(LANDING);

	expect(await srcCookie(page)).toBeNull();
});

test('prioritet: Relativt Cookie Consent före WP Consent API och kroken', async ({ page }) => {
	await withRcc(page, NONE);
	await withWpConsent(page, { cookies: { statistics: 'allow', marketing: 'allow' } });
	await page.addInitScript(() => {
		window.relativtFormConsent = () => true;
	});
	await page.goto(LANDING);

	expect(await srcCookie(page)).toBeNull();
	expect((await page.evaluate(() => window.relativtForm.consent())).tool).toBe('rcc');
});

test('prioritet: WP Consent API före kroken', async ({ page }) => {
	await withWpConsent(page, { cookies: { statistics: 'deny', marketing: 'deny' } });
	await page.addInitScript(() => {
		window.relativtFormConsent = () => true;
	});
	await page.goto(LANDING);

	expect(await srcCookie(page)).toBeNull();
	expect((await page.evaluate(() => window.relativtForm.consent())).tool).toBe('wp-consent-api');
});

/* -----------------------------------------------------------------------------
 * Tack-sida
 * -------------------------------------------------------------------------- */

test('tack-sida: besökaren skickas vidare vid lyckat inskick', async ({ page }) => {
	await page.goto(DEMO);
	await page.evaluate(() => {
		window.__mockRedirect = '/demo-form.html?tack=1';
	});

	const form = page_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();

	await page.waitForURL(/tack=1/);
});

test('utan tack-sida visas tack-rutan precis som vanligt', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await fillValid(form);
	await form.locator('.xf-submit').click();

	await expect(form).toHaveClass(/is-submitted/);
	expect(page.url()).not.toContain('tack=1');
});

test('återkallat samtycke tar bort kampanjkakan', async ({ page }) => {
	await withRcc(page, ALL);
	await page.goto(`${DEMO}?utm_source=google`);

	expect(await page.evaluate(() => document.cookie)).toContain('xf_src=');

	await page.evaluate(() => {
		document.dispatchEvent(new CustomEvent('rcc_consent_updated', {
			detail: { necessary: true, statistics: false, marketing: false },
		}));
	});

	expect(await page.evaluate(() => document.cookie)).not.toContain('xf_src=');
});

/* -----------------------------------------------------------------------------
 * Turnstile (1.4.0)
 *
 * Demon har två formulär med Turnstile (#xf-ts-a, #xf-ts-b), renderade av den
 * riktiga renderaren med Cloudflares testnyckel. Cloudflares api.js ersätts
 * av en stub i demon – se TURNSTILE-STUB i build-demo.php.
 * -------------------------------------------------------------------------- */

const ts_form = (page, which = 'a') => page.locator(`#xf-ts-${which} .relativt-form`);
const widget = (form) => form.locator('[data-xf-turnstile]');

test('Turnstile: widgeten renderas ovanför knappen med site key', async ({ page }) => {
	await page.goto(DEMO);
	const form = ts_form(page);

	await expect(widget(form)).toHaveAttribute('data-sitekey', '1x00000000000000000000AA');
	await expect(widget(form)).toHaveClass(/cf-turnstile/);
	await expect(widget(form)).toHaveAttribute('data-rendered', '1');

	const above = await form.evaluate((root) => {
		const w = root.querySelector('[data-xf-turnstile]');
		const btn = root.querySelector('.xf-submit');
		return !!(w.compareDocumentPosition(btn) & Node.DOCUMENT_POSITION_FOLLOWING);
	});
	expect(above).toBe(true);
});

test('Turnstile: formulär utan Turnstile har ingen widget och skickar ingen token', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);
	await expect(widget(form)).toHaveCount(0);

	await fillValid(form);
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);

	const body = await page.evaluate(() => window.__mockCalls[0]);
	expect(body).not.toHaveProperty('turnstile');
});

test('Turnstile: två formulär på samma sida har varsin widget', async ({ page }) => {
	await page.goto(DEMO);
	await expect(page.locator('[data-xf-turnstile][data-rendered="1"]')).toHaveCount(2);

	// Ett misslyckat inskick i det ena formuläret återställer bara dess egen widget.
	await page.evaluate(() => {
		window.__mockFail = { status: 403, payload: { ok: false, code: 'turnstile', message: 'Säkerhetskontrollen är inte klar.' }, once: true };
	});
	const a = ts_form(page, 'a');
	await fillValid(a);
	await a.locator('.xf-submit').click();

	await expect(widget(a)).toHaveAttribute('data-resets', '1');
	await expect(widget(ts_form(page, 'b'))).toHaveAttribute('data-resets', '0');
});

test('Turnstile: token följer med i nyttolasten', async ({ page }) => {
	await page.goto(DEMO);
	const form = ts_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();

	await expect(form).toHaveClass(/is-submitted/);
	const body = await page.evaluate(() => window.__mockCalls[0]);
	expect(body.turnstile).toBe('XXXX.DUMMY.TOKEN.XXXX');
	expect(Number(body.form)).toBe(12);
});

test('Turnstile: utan token skickas ingenting, felet visas vid widgeten', async ({ page }) => {
	await page.addInitScript(() => {
		window.__turnstileNoToken = true;
	});
	await page.goto(DEMO);
	const form = ts_form(page);
	await fillValid(form);
	await form.locator('.xf-submit').click();

	await expect(form.locator('[data-xf-error="turnstile"]')).toHaveText('Säkerhetskontrollen är inte klar. Vänta en sekund och försök igen.');
	await expect(form.locator('.xf-type-turnstile')).toHaveClass(/has-error/);
	expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
});

test('Turnstile: widgeten återställs efter ett 422-svar', async ({ page }) => {
	await page.goto(DEMO);
	const form = ts_form(page);
	await fillValid(form);
	await page.evaluate(() => {
		window.__mockFail = { status: 422, payload: { ok: false, errors: { namn: 'Fyll i detta fält.' } }, once: true };
	});
	await form.locator('.xf-submit').click();

	await expect(field(form, 'namn').locator('.xf-error')).toHaveText('Fyll i detta fält.');
	await expect(widget(form)).toHaveAttribute('data-resets', '1');

	// Nästa försök går igenom med en ny token.
	await form.locator('.xf-submit').click();
	await expect(form).toHaveClass(/is-submitted/);
	expect(await page.evaluate(() => window.__mockCalls[1].turnstile)).toBe('XXXX.DUMMY.TOKEN.XXXX');
});

test('Turnstile: avslag från servern visas vid widgeten och återställer den', async ({ page }) => {
	await page.goto(DEMO);
	const form = ts_form(page);
	await fillValid(form);
	await page.evaluate(() => {
		window.__mockFail = { status: 403, payload: { ok: false, code: 'turnstile', message: 'Säkerhetskontrollen är inte klar. Vänta en sekund och försök igen.' }, once: true };
	});
	await form.locator('.xf-submit').click();

	await expect(form.locator('[data-xf-error="turnstile"]')).toHaveText('Säkerhetskontrollen är inte klar. Vänta en sekund och försök igen.');
	await expect(widget(form)).toHaveAttribute('data-resets', '1');
	await expect(form.locator('.xf-form-error')).toHaveText('');
	await expect(form.locator('.xf-submit')).toBeEnabled();
});

test('Turnstile: tyst toofast-omsändning återanvänder token utan att återställa', async ({ page }) => {
	await page.goto(DEMO);
	const form = ts_form(page);
	await fillValid(form);
	await page.evaluate(() => {
		window.__mockFail = { status: 425, payload: { ok: false, code: 'toofast', retry_after: 1 }, once: true };
	});
	await form.locator('.xf-submit').click();

	await expect(form).toHaveClass(/is-submitted/, { timeout: 10000 });
	const calls = await page.evaluate(() => window.__mockCalls.map((c) => c.turnstile));
	expect(calls).toEqual(['XXXX.DUMMY.TOKEN.XXXX', 'XXXX.DUMMY.TOKEN.XXXX']);
	await expect(widget(form)).toHaveAttribute('data-resets', '0');
});
