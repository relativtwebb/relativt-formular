import { test, expect } from '@playwright/test';

/*
 * Fältbredder (1.11.0): hel, halv och tredjedel. Sidan byggs av
 * tests/build-width-demo.php med den riktiga renderaren och pluginets egen CSS.
 *
 * Rutnätet har sex kolumner (hel = 6, halv = 3, tredjedel = 2), så en tredjedel
 * är exakt (bredd − 2 × glapp) / 3 och en halva (bredd − glapp) / 2.
 *
 *   A  tredjedel × 3     B  halv × 2     C  hel     D  halv + tredjedel
 *
 * Under 768 px ska alla fält vara hela bredden, hur de än är inställda.
 */
const DEMO = '/demo-width.html';

const box = async (page, key) => {
	const b = await page.locator(`[data-xf-key="${key}"]`).boundingBox();
	if (!b) throw new Error(`Fältet ${key} har ingen ruta`);
	return b;
};

/** Rutnätets innerbredd och kolumnglapp i pixlar. */
const grid = (page) =>
	page.locator('.xf-grid').evaluate((el) => {
		const cs = getComputedStyle(el);
		const gap = parseFloat(cs.columnGap) || 0;
		return { width: el.getBoundingClientRect().width, gap };
	});

const close = (a, b, tolerance = 1.5) => Math.abs(a - b) <= tolerance;

test.beforeEach(async ({ page }) => {
	await page.goto(DEMO);
});

test('renderaren ger tredjedelsfälten sin klass', async ({ page }) => {
	await expect(page.locator('.xf-field--third')).toHaveCount(4);
	await expect(page.locator('.xf-field--half')).toHaveCount(3);
	await expect(page.locator('[data-xf-key="a1"]')).toHaveClass(/xf-field--third/);
});

test.describe('bred skärm', () => {
	test.beforeEach(async ({ page }, info) => {
		test.skip(info.project.name === 'mobil', 'Rutnätet är en kolumn på mobil.');
	});

	test('tre tredjedelar delar rad och fyller den exakt', async ({ page }) => {
		const { width, gap } = await grid(page);
		const [a1, a2, a3] = [await box(page, 'a1'), await box(page, 'a2'), await box(page, 'a3')];
		const third = (width - 2 * gap) / 3;

		expect(close(a1.y, a2.y)).toBe(true);
		expect(close(a2.y, a3.y)).toBe(true);
		for (const b of [a1, a2, a3]) expect(close(b.width, third)).toBe(true);
		expect(a1.x).toBeLessThan(a2.x);
		expect(a2.x).toBeLessThan(a3.x);
		// Sista fältets högerkant är rutnätets högerkant: ingen glipa, ingen överrinning.
		expect(close(a3.x + a3.width, a1.x + width)).toBe(true);
	});

	test('två halvor delar rad, på raden under tredjedelarna', async ({ page }) => {
		const { width, gap } = await grid(page);
		const [a1, b1, b2] = [await box(page, 'a1'), await box(page, 'b1'), await box(page, 'b2')];
		const half = (width - gap) / 2;

		expect(close(b1.y, b2.y)).toBe(true);
		expect(b1.y).toBeGreaterThan(a1.y + a1.height - 1);
		for (const b of [b1, b2]) expect(close(b.width, half)).toBe(true);
	});

	test('hel bredd tar hela raden för sig', async ({ page }) => {
		const { width } = await grid(page);
		const [b1, c1, d1] = [await box(page, 'b1'), await box(page, 'c1'), await box(page, 'd1')];

		expect(close(c1.width, width)).toBe(true);
		expect(c1.y).toBeGreaterThan(b1.y + b1.height - 1);
		expect(d1.y).toBeGreaterThan(c1.y + c1.height - 1);
	});

	test('halv och tredjedel delar rad (fem av sex kolumner)', async ({ page }) => {
		const { width, gap } = await grid(page);
		const [d1, d2] = [await box(page, 'd1'), await box(page, 'd2')];
		const col = (width - 5 * gap) / 6;

		expect(close(d1.y, d2.y)).toBe(true);
		expect(close(d1.width, 3 * col + 2 * gap)).toBe(true);
		expect(close(d2.width, 2 * col + gap)).toBe(true);
		expect(d1.x).toBeLessThan(d2.x);
	});

	test('en tredjedel är smalare än en halva', async ({ page }) => {
		const [a1, b1] = [await box(page, 'a1'), await box(page, 'b1')];
		expect(a1.width).toBeLessThan(b1.width - 20);
	});
});

test.describe('smal skärm', () => {
	test.beforeEach(async ({ page }, info) => {
		test.skip(info.project.name !== 'mobil', 'Bara för mobilprojektet.');
	});

	test('alla fält är hela bredden och staplas, oavsett inställd bredd', async ({ page }) => {
		const { width } = await grid(page);
		let previousBottom = -Infinity;
		for (const key of ['a1', 'a2', 'a3', 'b1', 'b2', 'c1', 'd1', 'd2']) {
			const b = await box(page, key);
			expect(close(b.width, width), `${key} ska vara hel bredd`).toBe(true);
			expect(b.y, `${key} ska ligga under föregående`).toBeGreaterThan(previousBottom - 1);
			previousBottom = b.y + b.height;
		}
	});
});
