import { defineConfig } from '@playwright/test';

/*
 * Sökvägen till Chromium sätts BARA om CHROMIUM_PATH finns i miljön.
 *
 * En hårdkodad executablePath fungerar på exakt en maskin. Överallt annars –
 * en GitHub-runner, en kollegas dator – pekar den på en fil som inte finns,
 * och då startar inte webbläsaren alls. Utan variabeln använder Playwright
 * den webbläsare den installerat själv, vilket är det normala fallet.
 */
const executablePath = process.env.CHROMIUM_PATH || undefined;

export default defineConfig({
	testDir: './tests',
	timeout: 15000,
	fullyParallel: true,
	reporter: [['list']],
	use: {
		baseURL: 'http://127.0.0.1:8788',
		viewport: { width: 1280, height: 900 },
		launchOptions: executablePath ? { executablePath } : {},
	},
	webServer: {
		command: 'npx --yes http-server . -p 8788 -s -c-1',
		port: 8788,
		reuseExistingServer: true,
		timeout: 60000,
	},
	projects: [
		{ name: 'desktop', use: { viewport: { width: 1280, height: 900 } } },
		{ name: 'mobil', use: { viewport: { width: 390, height: 844 } } },
	],
});
