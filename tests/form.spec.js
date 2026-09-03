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

test('hjälptexten renderas under fältet', async ({ page }) => {
	await page.goto(DEMO);
	const form = page_form(page);

	await expect(field(form, 'meddelande').locator('.xf-help')).toHaveText('Berätta gärna kort vad det gäller.');

	const helpId = await field(form, 'meddelande').locator('.xf-help').getAttribute('id');
	expect(await field(form, 'meddelande').locator('textarea').getAttribute('aria-describedby')).toContain(helpId);
});

/* -----------------------------------------------------------------------------
 * Samtycke och kampanjkakan
 *
 * Demon saknar relativtFormConfig, så standardbeteendet (auto utan
 * samtyckesverktyg = skriv som i 1.0) täcks av UTM-testerna ovan. Här
 * simuleras en sajt MED Relativt Cookie Consent: rccCookie i konfigurationen
 * är exakt vad PHP skickar när cookie-pluginet är aktivt.
 * -------------------------------------------------------------------------- */

test('utan samtycke skrivs ingen kampanjkaka, men attributionen följer ändå med', async ({ page }) => {
	await page.addInitScript(() => {
		window.relativtFormConfig = { rccCookie: 'relativt_cookie_consent' };
		document.cookie = `relativt_cookie_consent=${encodeURIComponent(JSON.stringify({ necessary: true, statistics: false, marketing: false }))}; path=/`;
	});
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
	await page.addInitScript(() => {
		window.relativtFormConfig = { rccCookie: 'relativt_cookie_consent' };
	});
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
	await page.addInitScript(() => {
		window.relativtFormConfig = { rccCookie: 'relativt_cookie_consent' };
		document.cookie = `relativt_cookie_consent=${encodeURIComponent(JSON.stringify({ necessary: true, statistics: true, marketing: true }))}; path=/`;
	});
	await page.goto(`${DEMO}?utm_source=google`);

	expect(await page.evaluate(() => document.cookie)).toContain('xf_src=');

	await page.evaluate(() => {
		document.dispatchEvent(new CustomEvent('rcc_consent_updated', {
			detail: { necessary: true, statistics: false, marketing: false },
		}));
	});

	expect(await page.evaluate(() => document.cookie)).not.toContain('xf_src=');
});
