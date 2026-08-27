import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: './tests',
	timeout: 15000,
	fullyParallel: true,
	reporter: [['list']],
	use: {
		baseURL: 'http://127.0.0.1:8788',
		viewport: { width: 1280, height: 900 },
		launchOptions: {
			executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
		},
	},
	webServer: {
		command: 'npx --yes http-server . -p 8788 -s -c-1',
		port: 8788,
		reuseExistingServer: true,
		timeout: 30000,
	},
	projects: [
		{ name: 'desktop', use: { viewport: { width: 1280, height: 900 } } },
		{ name: 'mobil', use: { viewport: { width: 390, height: 844 } } },
	],
});
