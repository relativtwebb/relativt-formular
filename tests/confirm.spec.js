import { test, expect } from '@playwright/test';

/*
 * Bekräftelsefält (1.13.0). Sidan byggs av tests/build-confirm-demo.php med den
 * riktiga renderaren: epost2 bekräftar epost, kundnr2 (som står FÖRE sitt
 * original) bekräftar kundnr, och mobil2 bekräftar mobil – ett telefonfält med
 * landsväljare som bara visas när "Ring mig" är valt.
 */
const DEMO = '/demo-confirm.html';

const form = (page) => page.locator('.relativt-form');
const field = (page, key) => page.locator(`[data-xf-key="${key}"]`);
const input = (page, key) => field(page, key).locator('input.xf-input');
const error = (page, key) => field(page, key).locator('.xf-error');

const MISMATCH = 'Stämmer inte överens. Kontrollera att du skrivit likadant båda gångerna.';
const REQUIRED = 'Fyll i detta fält.';

async function submit(page) {
	await form(page).locator('.xf-submit').click();
}

async function submitted(page) {
	await expect(form(page)).toHaveClass(/is-submitted/);
	return page.evaluate(() => window.__mockCalls.at(-1));
}

test.beforeEach(async ({ page }) => {
	await page.context().clearCookies();
	await page.goto(DEMO);
});

test.describe('utseende', () => {
	test('bekräftelsen får originalets typ, tangentbord och autofyll', async ({ page }) => {
		await expect(input(page, 'epost2')).toHaveAttribute('type', 'email');
		await expect(input(page, 'epost2')).toHaveAttribute('inputmode', 'email');
		await expect(input(page, 'epost2')).toHaveAttribute('autocomplete', 'email');
		await expect(input(page, 'kundnr2')).toHaveAttribute('type', 'text');
	});

	test('bekräftelsen av ett telefonfält med landsväljare har en egen landsväljare', async ({ page }) => {
		await field(page, 'kontakt').getByText('Ring mig').click();
		await expect(field(page, 'mobil2').locator('.xf-phone-select')).toHaveValue('SE');
		await expect(field(page, 'mobil2').locator('.xf-phone-input')).toHaveValue('+46 ');
	});

	test('bekräftelsen följer originalets villkor', async ({ page }) => {
		await expect(field(page, 'mobil')).toBeHidden();
		await expect(field(page, 'mobil2')).toBeHidden();
		await field(page, 'kontakt').getByText('Ring mig').click();
		await expect(field(page, 'mobil')).toBeVisible();
		await expect(field(page, 'mobil2')).toBeVisible();
	});

	test('bekräftelsen fylls inte i från URL:en', async ({ page }) => {
		await page.goto(`${DEMO}?epost=anna@exempel.se&epost2=anna@exempel.se`);
		await expect(input(page, 'epost')).toHaveValue('anna@exempel.se');
		await expect(input(page, 'epost2')).toHaveValue('');
	});
});

test.describe('kontrollen', () => {
	test('lika adresser skickas, och bekräftelsen följer med till servern', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill(' Anna@Exempel.se ');
		await submit(page);
		const body = await submitted(page);
		expect(body.fields.epost).toBe('anna@exempel.se');
		// Servern behöver båda för att kontrollera själv; den sparar bara originalet.
		// (Webbläsaren tar själv bort mellanrummen i kanterna på ett e-postfält.)
		expect(body.fields.epost2).toBe('Anna@Exempel.se');
	});

	test('olika adresser stoppas i webbläsaren, med fokus på bekräftelsen', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('ana@exempel.se');
		await submit(page);
		await expect(error(page, 'epost2')).toHaveText(MISMATCH);
		await expect(input(page, 'epost2')).toHaveAttribute('aria-invalid', 'true');
		await expect(input(page, 'epost2')).toBeFocused();
		await expect(error(page, 'epost')).toHaveText('');
		expect(await page.evaluate(() => window.__mockCalls.length)).toBe(0);
	});

	test('tom bekräftelse när originalet är ifyllt ger Fyll i', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await submit(page);
		await expect(error(page, 'epost2')).toHaveText(REQUIRED);
	});

	test('har originalet ett eget fel säger bekräftelsen ingenting', async ({ page }) => {
		await input(page, 'epost').fill('anna@');
		await input(page, 'epost2').fill('något annat');
		await submit(page);
		await expect(error(page, 'epost')).toHaveText('Kontrollera e-postadressen.');
		await expect(error(page, 'epost2')).toHaveText('');
	});

	test('felet försvinner när bekräftelsen rättas', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('ana@exempel.se');
		await submit(page);
		await expect(error(page, 'epost2')).toHaveText(MISMATCH);
		await input(page, 'epost2').fill('anna@exempel.se');
		await expect(error(page, 'epost2')).toHaveText('');
	});

	test('felet försvinner också när originalet ändras', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('ana@exempel.se');
		await submit(page);
		await expect(error(page, 'epost2')).toHaveText(MISMATCH);
		await input(page, 'epost').fill('ana@exempel.se');
		await expect(error(page, 'epost2')).toHaveText('');
	});

	test('textfält jämförs exakt, även när bekräftelsen står före originalet', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('anna@exempel.se');
		await input(page, 'kundnr').fill('A-123');
		await input(page, 'kundnr2').fill('a-123');
		await submit(page);
		await expect(error(page, 'kundnr2')).toHaveText(MISMATCH);
		await expect(input(page, 'kundnr2')).toBeFocused();
		await input(page, 'kundnr2').fill('A-123');
		await submit(page);
		await submitted(page);
	});

	test('valfritt original som lämnas tomt kräver ingen bekräftelse', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('anna@exempel.se');
		await submit(page);
		const body = await submitted(page);
		expect(body.fields.kundnr2).toBe('');
	});

	test('dolt original: varken det eller bekräftelsen kontrolleras eller skickas', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('anna@exempel.se');
		await submit(page);
		const body = await submitted(page);
		expect(body.fields).not.toHaveProperty('mobil');
		expect(body.fields).not.toHaveProperty('mobil2');
	});

	test('telefon: samma nummer i olika form godkänns', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('anna@exempel.se');
		await field(page, 'kontakt').getByText('Ring mig').click();
		await field(page, 'mobil').locator('.xf-phone-input').fill('+46 70 123 45 67');
		await field(page, 'mobil2').locator('.xf-phone-input').fill('070-123 45 67');
		await submit(page);
		const body = await submitted(page);
		expect(body.fields.mobil).toBe('+46701234567');
		expect(body.fields.mobil2).toBe('+46701234567');
	});

	test('telefon: olika nummer stoppas', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('anna@exempel.se');
		await field(page, 'kontakt').getByText('Ring mig').click();
		await field(page, 'mobil').locator('.xf-phone-input').fill('+46 70 123 45 67');
		await field(page, 'mobil2').locator('.xf-phone-input').fill('+46 70 123 45 68');
		await submit(page);
		await expect(error(page, 'mobil2')).toHaveText(MISMATCH);
	});

	test('telefon: olika länder med samma siffror stoppas', async ({ page }) => {
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('anna@exempel.se');
		await field(page, 'kontakt').getByText('Ring mig').click();
		await field(page, 'mobil').locator('.xf-phone-input').fill('+46 70 123 45 67');
		await field(page, 'mobil2').locator('.xf-phone-select').selectOption('NO');
		await field(page, 'mobil2').locator('.xf-phone-input').fill('+47 70 123 45 67');
		await submit(page);
		await expect(error(page, 'mobil2')).toHaveText(MISMATCH);
	});

	test('serverns fel på bekräftelsen visas vid fältet', async ({ page }) => {
		await page.evaluate(() => {
			window.__mockErrors = { epost2: 'Stämmer inte överens. Kontrollera att du skrivit likadant båda gångerna.' };
		});
		await input(page, 'epost').fill('anna@exempel.se');
		await input(page, 'epost2').fill('anna@exempel.se');
		await submit(page);
		await expect(error(page, 'epost2')).toHaveText(MISMATCH);
	});
});
