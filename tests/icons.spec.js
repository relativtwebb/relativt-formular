import { test, expect } from '@playwright/test';

/*
 * Ikonväljaren (1.10.0). Sidan byggs av tests/build-icon-demo.php och innehåller
 * skicka-knappen renderad av den riktiga renderaren för varje ikon, plus
 * väljaren ovanpå en modell av ACF:s och WordPress radioknappsmarkup.
 */
const DEMO = '/demo-icons.html';

const cell = (page, key) => page.locator(`.cell[data-icon="${key}"]`);
const icon_svg = (page, key) => cell(page, key).locator('.xf-submit-icon svg');

/** Beräknad stil på en pseudo-element, som Playwright annars inte når. */
const pseudo = (locator, prop) =>
	locator.evaluate((el, p) => getComputedStyle(el, '::before').getPropertyValue(p), prop);

test.beforeEach(async ({ page }) => {
	await page.goto(DEMO);
});

test.describe('knappen', () => {
	test('standardpilen är fylld precis som förut', async ({ page }) => {
		const svg = icon_svg(page, 'standard');
		await expect(svg).toHaveCSS('fill', 'rgb(255, 255, 255)');
		await expect(svg).toHaveCSS('stroke', 'none');
	});

	test('Lucide-ikoner är linjer i knappens textfärg, inte fyllda former', async ({ page }) => {
		for (const key of ['send', 'arrow-right', 'shopping-cart', 'check']) {
			const svg = icon_svg(page, key);
			await expect(svg, key).toHaveCSS('fill', 'none');
			await expect(svg, key).toHaveCSS('stroke', 'rgb(255, 255, 255)');
			await expect(svg, key).toHaveCSS('stroke-width', '2px');
		}
	});

	test('ikonen följer knappens textfärg när knappen byter kulör', async ({ page }) => {
		// Variablerna sätts på .relativt-form själv, så en sajt skriver över dem där.
		await page.locator('#buttons .relativt-form').evaluateAll((els) => els.forEach((el) => el.style.setProperty('--xf-accent-text', 'rgb(200, 30, 30)')));
		await expect(icon_svg(page, 'send')).toHaveCSS('stroke', 'rgb(200, 30, 30)');
		await expect(icon_svg(page, 'standard')).toHaveCSS('fill', 'rgb(200, 30, 30)');
	});

	test('linjetjockleken styrs av --xf-icon-stroke', async ({ page }) => {
		await cell(page, 'send').locator('.relativt-form').evaluate((el) => el.style.setProperty('--xf-icon-stroke', '1'));
		await expect(icon_svg(page, 'send')).toHaveCSS('stroke-width', '1px');
	});

	test('alla ikoner får samma ruta så knappen inte hoppar mellan val', async ({ page }) => {
		const keys = await page.locator('.cell').evaluateAll((els) => els.map((e) => e.dataset.icon).filter((k) => k !== 'none'));
		expect(keys.length).toBeGreaterThanOrEqual(30);
		for (const key of keys) {
			const box = await icon_svg(page, key).boundingBox();
			expect(box?.width, key).toBe(22);
			expect(box?.height, key).toBe(22);
		}
	});

	test('Ingen ikon ger en knapp med bara text', async ({ page }) => {
		await expect(cell(page, 'none').locator('.xf-submit-text')).toHaveText('Skicka');
		await expect(cell(page, 'none').locator('.xf-submit-icon')).toHaveCount(0);
	});

	test('ingen ikon ritar en tom ruta, varje ikon har synliga linjer', async ({ page }) => {
		const empty = await page.locator('.cell[data-icon] .xf-submit-icon svg').evaluateAll((svgs) =>
			svgs.filter((s) => {
				const b = s.getBBox();
				return b.width === 0 || b.height === 0;
			}).length
		);
		expect(empty).toBe(0);
	});
});

test.describe('väljaren', () => {
	const labels = (page) => page.locator('#picker .acf-radio-list label');

	test('visar en ruta per ikon, med standard markerad från start', async ({ page }) => {
		const total = await page.locator('.cell[data-icon]').count();
		await expect(labels(page)).toHaveCount(total);
		await expect(page.locator('#picker input:checked')).toHaveAttribute('value', 'standard');
		await expect(page.locator('#picker label.selected')).toHaveCount(1);
	});

	test('varje ruta visar sin ikon och inte WordPress radioprick', async ({ page }) => {
		const input = page.locator('#picker input[value="send"]');
		expect(await pseudo(input, 'mask-image')).toContain('data:image/svg+xml');
		expect(await pseudo(input, 'border-radius')).toBe('0px');
		expect(await pseudo(input, 'width')).not.toBe('8px');
		await expect(input).toHaveCSS('width', '26px');
		await expect(input).toHaveCSS('border-top-width', '0px');
	});

	test('att välja en ikon markerar den och avmarkerar den förra', async ({ page }) => {
		await page.locator('#picker label', { hasText: 'Varukorg' }).click();
		await expect(page.locator('#picker input:checked')).toHaveAttribute('value', 'shopping-cart');
		await expect(page.locator('#picker label.selected')).toHaveCount(1);
		await expect(page.locator('#picker label.selected')).toContainText('Varukorg');
		await expect(page.locator('#picker label.selected')).toHaveCSS('border-top-color', 'rgb(34, 113, 177)');
	});

	test('Ingen ikon går att välja', async ({ page }) => {
		await page.locator('#picker label', { hasText: 'Ingen ikon' }).click();
		await expect(page.locator('#picker input:checked')).toHaveAttribute('value', 'none');
	});

	test('går att styra med tangentbordet', async ({ page }) => {
		await page.locator('#picker input[value="standard"]').focus();
		await page.keyboard.press('ArrowRight');
		await expect(page.locator('#picker input:checked')).toHaveAttribute('value', 'none');
		await page.keyboard.press('ArrowRight');
		await expect(page.locator('#picker input:checked')).toHaveAttribute('value', 'arrow-right');
	});

	test('sidan scrollar aldrig i sidled', async ({ page }) => {
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow).toBeLessThanOrEqual(0);
	});
});
