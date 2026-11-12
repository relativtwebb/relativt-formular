import { test, expect } from '@playwright/test';

/*
 * Flerstegsformulär (1.14.0). Sidan byggs av tests/build-multistep-demo.php
 * med den riktiga renderaren – se den filen för vad varje formulär innehåller.
 */
const DEMO = '/demo-multistep.html';

const form = (page, id = 'ms-main') => page.locator(`#${id} .relativt-form`);
const step = (page, n, id = 'ms-main') => form(page, id).locator(`.xf-step[data-xf-step="${n}"]`);
const field = (page, key, id = 'ms-main') => form(page, id).locator(`[data-xf-key="${key}"]`);
const input = (page, key, id = 'ms-main') => field(page, key, id).locator('input.xf-input, textarea');
const error = (page, key, id = 'ms-main') => field(page, key, id).locator('.xf-error');
const next = (page, id = 'ms-main') => form(page, id).locator('.xf-next');
const prev = (page, id = 'ms-main') => form(page, id).locator('.xf-prev');
const submitBtn = (page, id = 'ms-main') => form(page, id).locator('.xf-submit');
const counter = (page, id = 'ms-main') => form(page, id).locator('.xf-progress-text');

const REQUIRED = 'Fyll i detta fält.';

/** Steg 1 ifyllt med giltiga värden. */
async function fillStep1(page) {
	await input(page, 'namn').fill('Anna Andersson');
	await input(page, 'epost').fill('anna@exempel.se');
}

/** Från steg 1 till sista steget som företag med mail (Telefon hoppas över). */
async function toLastStep(page) {
	await fillStep1(page);
	await next(page).click();
	await expect(step(page, 1)).toBeVisible();
	await input(page, 'foretag').fill('Exempel AB');
	await input(page, 'epost2').fill('anna@exempel.se');
	await next(page).click();
	await expect(step(page, 3)).toBeVisible();
}

test.beforeEach(async ({ page }) => {
	await page.context().clearCookies();
	await page.goto(DEMO);
});

test.describe('utgångsläge', () => {
	test('bara första steget syns, med indikator och Nästa', async ({ page }) => {
		await expect(step(page, 0)).toBeVisible();
		for (const n of [1, 2, 3]) await expect(step(page, n)).toBeHidden();
		await expect(counter(page)).toHaveText('Steg 1 av 3');
		await expect(next(page)).toBeVisible();
		await expect(prev(page)).toBeHidden();
		await expect(submitBtn(page)).toBeHidden();
	});

	test('samtyckesrutan väntar till sista steget', async ({ page }) => {
		await expect(form(page).locator('.xf-type-consent')).toBeHidden();
		await toLastStep(page);
		await expect(form(page).locator('.xf-type-consent')).toBeVisible();
	});

	test('stapeln visar hur långt besökaren kommit', async ({ page }) => {
		const width = () => form(page).locator('.xf-progress-fill').evaluate((el) => el.style.width);
		expect(parseFloat(await width())).toBeCloseTo(33.33, 1);
		await toLastStep(page);
		expect(await width()).toBe('100%');
	});

	test('stegets rubrik och ingress syns', async ({ page }) => {
		await expect(step(page, 0).locator('.xf-step-title')).toHaveText('Om dig');
		await fillStep1(page);
		await next(page).click();
		await expect(step(page, 1).locator('.xf-step-title')).toHaveText('Företaget');
		await expect(step(page, 1).locator('.xf-step-help')).toHaveText('Berätta lite om er.');
	});
});

test.describe('validering per steg', () => {
	test('Nästa kontrollerar bara det aktuella steget', async ({ page }) => {
		await next(page).click();
		await expect(error(page, 'namn')).toHaveText(REQUIRED);
		await expect(error(page, 'epost')).toHaveText(REQUIRED);
		await expect(step(page, 0)).toBeVisible();
		// Fält i senare steg får inga fel än.
		await expect(error(page, 'foretag')).toHaveText('');
		await expect(error(page, 'meddelande')).toHaveText('');
		await expect(input(page, 'namn')).toBeFocused();
	});

	test('ogiltig e-post stoppar i steget', async ({ page }) => {
		await input(page, 'namn').fill('Anna');
		await input(page, 'epost').fill('anna@');
		await next(page).click();
		await expect(error(page, 'epost')).not.toHaveText('');
		await expect(step(page, 0)).toBeVisible();
	});

	test('bekräftelsen kontrolleras i sitt eget steg', async ({ page }) => {
		await fillStep1(page);
		await next(page).click();
		await input(page, 'foretag').fill('Exempel AB');
		await input(page, 'epost2').fill('fel@exempel.se');
		await next(page).click();
		await expect(error(page, 'epost2')).not.toHaveText('');
		await expect(step(page, 1)).toBeVisible();
	});

	test('Tillbaka kontrollerar ingenting', async ({ page }) => {
		await fillStep1(page);
		await next(page).click();
		await expect(step(page, 1)).toBeVisible();
		await prev(page).click();
		await expect(step(page, 0)).toBeVisible();
		await expect(error(page, 'foretag')).toHaveText('');
		await expect(input(page, 'namn')).toHaveValue('Anna Andersson');
	});

	test('Enter i ett fält går till nästa steg i stället för att skicka', async ({ page }) => {
		await fillStep1(page);
		await input(page, 'epost').press('Enter');
		await expect(step(page, 1)).toBeVisible();
		expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
	});

	test('Enter i ett ofullständigt steg visar felen', async ({ page }) => {
		await input(page, 'namn').press('Enter');
		await expect(error(page, 'namn')).toHaveText(REQUIRED);
		await expect(step(page, 0)).toBeVisible();
	});
});

test.describe('villkor och överhoppade steg', () => {
	test('ett steg med villkor hoppas över, och räknas inte', async ({ page }) => {
		await field(page, 'kundtyp').getByText('Privatperson').click();
		await expect(counter(page)).toHaveText('Steg 1 av 2');
		await fillStep1(page);
		await next(page).click();
		await expect(step(page, 3)).toBeVisible();
		await expect(counter(page)).toHaveText('Steg 2 av 2');
		await expect(submitBtn(page)).toBeVisible();
		await expect(next(page)).toBeHidden();
	});

	test('ett steg vars alla fält är dolda hoppas över', async ({ page }) => {
		await expect(counter(page)).toHaveText('Steg 1 av 3');
		await field(page, 'kontakt').getByText('Ring mig').click();
		await expect(counter(page)).toHaveText('Steg 1 av 4');
		await fillStep1(page);
		await next(page).click();
		await input(page, 'foretag').fill('Exempel AB');
		await input(page, 'epost2').fill('anna@exempel.se');
		await next(page).click();
		await expect(step(page, 2)).toBeVisible();
		await expect(step(page, 2).locator('.xf-step-title')).toHaveText('Telefon');
	});

	test('Tillbaka hoppar också över överhoppade steg', async ({ page }) => {
		await toLastStep(page);
		await prev(page).click();
		await expect(step(page, 1)).toBeVisible();
	});
});

test.describe('inskick', () => {
	test('ett inskick med alla stegs fält', async ({ page }) => {
		await toLastStep(page);
		await input(page, 'meddelande').fill('Hej!');
		await form(page).locator('.xf-type-consent .xf-check').click();
		await submitBtn(page).click();
		await expect(form(page)).toHaveClass(/is-submitted/);

		const calls = await page.evaluate(() => window.__mockCalls);
		expect(calls).toHaveLength(1);
		expect(calls[0].fields).toMatchObject({
			namn: 'Anna Andersson',
			epost: 'anna@exempel.se',
			kundtyp: 'foretag',
			foretag: 'Exempel AB',
			epost2: 'anna@exempel.se',
			meddelande: 'Hej!',
			kalla: 'demo',
		});
		// Telefonsteget hoppades över – dess fält är inte med.
		expect(calls[0].fields).not.toHaveProperty('mobil');
	});

	test('fält i ett steg som hoppats över skickas inte', async ({ page }) => {
		await fillStep1(page);
		await next(page).click();
		await input(page, 'foretag').fill('Exempel AB');
		await prev(page).click();
		await field(page, 'kundtyp').getByText('Privatperson').click();
		await next(page).click();
		await expect(step(page, 3)).toBeVisible();
		await input(page, 'meddelande').fill('Hej!');
		await form(page).locator('.xf-type-consent .xf-check').click();
		await submitBtn(page).click();
		await expect(form(page)).toHaveClass(/is-submitted/);

		const body = await page.evaluate(() => window.__mockCalls[0]);
		expect(body.fields).not.toHaveProperty('foretag');
		expect(body.fields).not.toHaveProperty('epost2');
		expect(body.fields.kundtyp).toBe('privat');
	});

	test('samtyckesrutan kontrolleras i sista steget', async ({ page }) => {
		await toLastStep(page);
		await input(page, 'meddelande').fill('Hej!');
		await submitBtn(page).click();
		await expect(form(page).locator('.xf-type-consent')).toHaveClass(/has-error/);
		expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
	});

	test('fel från servern i ett tidigare steg visas där', async ({ page }) => {
		await toLastStep(page);
		await input(page, 'meddelande').fill('Hej!');
		await form(page).locator('.xf-type-consent .xf-check').click();
		await page.evaluate(() => {
			window.__mockErrors = { namn: 'Servern säger nej.' };
		});
		await submitBtn(page).click();

		await expect(step(page, 0)).toBeVisible();
		await expect(error(page, 'namn')).toHaveText('Servern säger nej.');
		await expect(input(page, 'namn')).toBeFocused();
		await expect(form(page)).not.toHaveClass(/is-submitted/);
	});
});

test.describe('tillgänglighet och events', () => {
	test('fokus flyttas till det nya steget, som har sin rubrik som namn', async ({ page }) => {
		await fillStep1(page);
		await next(page).click();
		await expect(step(page, 1)).toBeFocused();
		await expect(step(page, 1)).toHaveAttribute('role', 'group');
		const name = await step(page, 1).evaluate((el) => document.getElementById(el.getAttribute('aria-labelledby'))?.textContent);
		expect(name).toBe('Företaget');
	});

	test('indikatorns text är en aria-live-region', async ({ page }) => {
		await expect(counter(page)).toHaveAttribute('aria-live', 'polite');
	});

	test('relativt-form:step berättar vilket steg som visas', async ({ page }) => {
		await toLastStep(page);
		await prev(page).click();
		await expect(step(page, 1)).toBeVisible();
		const events = await page.evaluate(() => window.__stepEvents);
		expect(events.map((e) => [e.step, e.total, e.title, e.direction])).toEqual([
			[2, 3, 'Företaget', 'next'],
			[3, 3, 'Ärendet', 'next'],
			[2, 3, 'Företaget', 'prev'],
		]);
		expect(events[0].formId).toBe('12');
	});

	test('step-leave: waitUntil håller kvar steget tills den egna animationen är klar', async ({ page }) => {
		await page.evaluate(() => {
			document.addEventListener('relativt-form:step-leave', (event) => {
				window.__leaveSeen = event.detail.step;
				event.detail.waitUntil(new Promise((resolve) => setTimeout(resolve, 600)));
			});
		});
		await fillStep1(page);
		await next(page).click();
		await page.waitForTimeout(250);
		await expect(step(page, 0)).toBeVisible();
		await expect(step(page, 1)).toBeVisible({ timeout: 2000 });
		await expect(step(page, 0)).toBeHidden();
		expect(await page.evaluate(() => window.__leaveSeen)).toBe(1);
	});
});

test.describe('animation', () => {
	test('glidet byter riktning när besökaren backar', async ({ page }) => {
		await fillStep1(page);
		await next(page).click();
		await expect(step(page, 1)).toBeVisible();
		await expect(form(page)).not.toHaveClass(/is-going-back/);
		await prev(page).click();
		await expect(form(page)).toHaveClass(/is-going-back/);
	});

	test('stegen animeras med CSS-variablerna', async ({ page }) => {
		const anim = await page.evaluate(() => {
			const root = document.querySelector('#ms-main .relativt-form');
			const el = root.querySelector('.xf-step[data-xf-step="1"]');
			el.classList.add('is-active', 'is-entering');
			const style = getComputedStyle(el);
			const out = { name: style.animationName, duration: style.animationDuration };
			el.classList.remove('is-active', 'is-entering');
			return out;
		});
		expect(anim.name).toBe('xf-step-slide-in');
		expect(anim.duration).toBe('0.22s');
	});

	test('minskad rörelse: inga animationer', async ({ page }) => {
		await page.emulateMedia({ reducedMotion: 'reduce' });
		const name = await page.evaluate(() => {
			const el = document.querySelector('#ms-main .xf-step[data-xf-step="0"]');
			el.classList.add('is-leaving');
			const n = getComputedStyle(el).animationName;
			el.classList.remove('is-leaving');
			return n;
		});
		expect(name).toBe('none');
		await fillStep1(page);
		await next(page).click();
		await expect(step(page, 1)).toBeVisible();
	});

	test('valet Ingen animation byter steg direkt', async ({ page }) => {
		await input(page, 'namn', 'ms-custom').fill('Anna');
		await next(page, 'ms-custom').click();
		await expect(step(page, 1, 'ms-custom')).toBeVisible();
		const name = await step(page, 1, 'ms-custom').evaluate((el) => getComputedStyle(el).animationName);
		expect(name).toBe('none');
	});
});

test.describe('knappar och ikoner', () => {
	test('standardikonerna: pil höger på Nästa, pil vänster före texten på Tillbaka', async ({ page }) => {
		const nextIcon = next(page).locator('.xf-step-btn-icon svg');
		await expect(nextIcon).toHaveCount(1);
		await expect(nextIcon.locator('g')).toHaveCount(0);
		await expect(next(page).locator('.xf-step-btn-text')).toHaveText('Nästa');

		const order = await prev(page).evaluate((btn) => [...btn.children].map((c) => c.className));
		expect(order).toEqual(['xf-step-btn-icon', 'xf-step-btn-text']);
		await expect(prev(page).locator('.xf-step-btn-icon svg g[transform]')).toHaveCount(1);
		await expect(prev(page).locator('.xf-step-btn-text')).toHaveText('Tillbaka');
	});

	test('egna texter och ikoner', async ({ page }) => {
		await expect(next(page, 'ms-custom').locator('.xf-step-btn-text')).toHaveText('Fortsätt');
		await expect(next(page, 'ms-custom').locator('svg[fill="none"] path')).toHaveCount(2);
		await input(page, 'namn', 'ms-custom').fill('Anna');
		await next(page, 'ms-custom').click();
		await expect(prev(page, 'ms-custom').locator('.xf-step-btn-text')).toHaveText('Bakåt');
		await expect(prev(page, 'ms-custom').locator('svg')).toHaveCount(0);
		await expect(submitBtn(page, 'ms-custom')).toBeVisible();
	});

	test('Lucide-ikonerna ritas som linjer, som i skicka-knappen', async ({ page }) => {
		const stroke = await next(page, 'ms-custom').locator('svg').evaluate((el) => getComputedStyle(el).stroke);
		expect(stroke).not.toBe('none');
	});

	test('Nästa och Tillbaka är vanliga knappar, inte skicka-knappar', async ({ page }) => {
		await expect(next(page)).toHaveAttribute('type', 'button');
		await expect(prev(page)).toHaveAttribute('type', 'button');
	});
});

test.describe('indikatorlägen', () => {
	test('bara text: ingen stapel', async ({ page }) => {
		await expect(counter(page, 'ms-custom')).toHaveText('Steg 1 av 2');
		await expect(form(page, 'ms-custom').locator('.xf-progress-bar')).toHaveCount(0);
	});

	test('ingen indikator: texten finns kvar för skärmläsare', async ({ page }) => {
		await expect(counter(page, 'ms-turnstile')).toHaveClass(/xf-sr-only/);
		await expect(form(page, 'ms-turnstile').locator('.xf-progress-bar')).toHaveCount(0);
	});
});

test.describe('Turnstile', () => {
	test('widgeten renderas först i sista steget, och token skickas med', async ({ page }) => {
		const widget = form(page, 'ms-turnstile').locator('[data-xf-turnstile]');
		await expect(widget).not.toHaveClass(/cf-turnstile/);
		await expect(widget).not.toHaveAttribute('data-rendered', '1');

		await input(page, 'namn', 'ms-turnstile').fill('Anna');
		await next(page, 'ms-turnstile').click();
		await expect(widget).toHaveAttribute('data-rendered', '1');
		// Stubben ritar ingenting synligt, så det är behållaren som ska visas.
		await expect(form(page, 'ms-turnstile').locator('.xf-type-turnstile')).toHaveCSS('display', /^(?!none$)/);

		await submitBtn(page, 'ms-turnstile').click();
		await expect(form(page, 'ms-turnstile')).toHaveClass(/is-submitted/);
		const body = await page.evaluate(() => window.__mockCalls.at(-1));
		expect(body.turnstile).toBe('XXXX.DUMMY.TOKEN.XXXX');
	});
});

test.describe('flerstegsläget avstängt', () => {
	test('stegraderna blir rubriker och hela formuläret syns', async ({ page }) => {
		await expect(form(page, 'ms-off')).not.toHaveClass(/xf-multistep/);
		await expect(form(page, 'ms-off').locator('.xf-step')).toHaveCount(0);
		await expect(form(page, 'ms-off').locator('.xf-type-heading .xf-heading')).toHaveText('Rubrik från en stegrad');
		await expect(input(page, 'ort', 'ms-off')).toBeVisible();
		await expect(submitBtn(page, 'ms-off')).toBeVisible();
		await expect(form(page, 'ms-off').locator('.xf-next, .xf-prev, .xf-progress')).toHaveCount(0);
	});
});

test.describe('scroll', () => {
	test('har formuläret scrollat ur bild flyttas det tillbaka vid stegbyte', async ({ page }) => {
		await fillStep1(page);
		// Scrolla så att formulärets överkant hamnar ovanför fönstret.
		await page.evaluate(() => {
			const root = document.querySelector('#ms-main .relativt-form');
			window.scrollTo(0, root.getBoundingClientRect().top + window.scrollY + 300);
		});
		await next(page).click();
		await expect(step(page, 1)).toBeVisible();
		await expect.poll(() => page.evaluate(() => document.querySelector('#ms-main .relativt-form').getBoundingClientRect().top)).toBeGreaterThanOrEqual(-1);
	});
});
