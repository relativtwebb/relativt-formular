/**
 * Relativt Formulär
 * -----------------------------------------------------------------------------
 * Två ansvarsområden:
 *
 *  1. Kampanjspårning. Körs på ALLA sidor, oavsett om ett formulär finns där,
 *     eftersom UTM-taggarna ligger på landningssidan och inte på kontaktsidan.
 *  2. Formulärlogik. Villkorlig fältvisning, förval, validering, ajax-inskick
 *     och tack-läge.
 *
 * Laddas globalt via enqueue-arrayen. Inga beroenden.
 *
 * Konfigurationen (window.relativtFormConfig) skrivs av PHP:s register_assets
 * före den här filen: felmeddelanden, länkspärrens tak, samtyckesläget och
 * samtyckeskategorierna för kampanjkakan, och vilka samtyckesverktyg som finns.
 * Saknas objektet – som i demon – gäller standardvärdena nedan.
 */

(() => {
	'use strict';

	const CONFIG = typeof window.relativtFormConfig === 'object' && window.relativtFormConfig !== null
		? window.relativtFormConfig
		: {};

	/* =========================================================================
	 * 1. Kampanjspårning
	 * ====================================================================== */

	const COOKIE = 'xf_src';
	const DAYS = 90;
	const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
	const CLICK_KEYS = ['gclid', 'fbclid'];
	const CAMPAIGN_KEYS = [...UTM_KEYS, ...CLICK_KEYS];
	/** Attributionen: det som räcker med statistiksamtycke för. */
	const BASE_KEYS = [...UTM_KEYS, 'landing', 'referrer'];

	/*
	 * Samtyckesläge för kakan, satt via filtret relativt_form_utm_cookie:
	 *
	 *   'auto'   (standard) – kakan skrivs bara med samtycke från ett
	 *            samtyckesverktyg, i den här prioritetsordningen:
	 *              1. Relativt Cookie Consent (rccCookie i konfigurationen)
	 *              2. WP Consent API (wp_has_consent)
	 *              3. den generella kroken window.relativtFormConsent
	 *            Finns inget av dem skrivs kakan inte alls (före 1.7.0 skrevs
	 *            den direkt).
	 *   'always' – skriv alltid, med klick-id. Det gamla beteendet, för sajter
	 *            som hanterar samtycket på annat håll och blockerar skriptet
	 *            därifrån.
	 *   'never'  – skriv aldrig.
	 *
	 * Kakan har två delar med var sin samtyckeskategori (filtret
	 * relativt_form_consent_categories, någon kategori i listan räcker):
	 *
	 *   attribution – UTM-parametrar, landningssida, hänvisande sida.
	 *                 Standard: statistics eller marketing.
	 *   clickIds    – gclid och fbclid. Annonsplattformarnas identifierare för
	 *                 ett enskilt klick. Standard: marketing.
	 *
	 * Det kakan inte får innehålla hålls bara i minnet: värden från den här
	 * sidans URL följer med ett inskick härifrån, men inte till nästa sida.
	 * Det kakan inte längre får innehålla läses inte heller ur den – det
	 * plockas bort, både ur kakan och ur minnet.
	 */
	const UTM_MODE = ['always', 'never'].includes(CONFIG.utmCookie) ? CONFIG.utmCookie : 'auto';
	const RCC_COOKIE = typeof CONFIG.rccCookie === 'string' && CONFIG.rccCookie !== '' ? CONFIG.rccCookie : null;
	const WP_CONSENT_API = CONFIG.wpConsentApi === true;

	const categoryList = (value, fallback) => (Array.isArray(value)
		? value.filter((category) => typeof category === 'string' && category !== '')
		: fallback);

	const CATEGORIES = {
		attribution: categoryList(CONFIG.consentCategories?.attribution, ['statistics', 'marketing']),
		clickIds: categoryList(CONFIG.consentCategories?.clickIds, ['marketing']),
	};

	const hasCookie = (name) => document.cookie.split('; ').some((row) => row.startsWith(`${name}=`));

	const readCookie = (name) => {
		const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
		if (!match) return null;
		try {
			return JSON.parse(decodeURIComponent(match.slice(name.length + 1)));
		} catch {
			return null;
		}
	};

	/**
	 * Livslängden räknas från posten (t), inte från skrivtillfället. En kaka
	 * som skrivs om – klick-id som plockas bort, samtycke som kommer senare –
	 * får alltså aldrig förlängd livstid.
	 */
	const writeCookie = (name, value) => {
		const start = Number(value?.t) || Date.now();
		const expires = new Date(start + DAYS * 864e5).toUTCString();
		const secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = `${name}=${encodeURIComponent(JSON.stringify(value))}; expires=${expires}; path=/; SameSite=Lax${secure}`;
	};

	const removeCookie = (name) => {
		document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax`;
	};

	/* -- Samtyckesverktygen ---------------------------------------------- */

	/** true, 'allow' (WP Consent API) och 'granted' (Consent Mode) räknas som ja. */
	const isYes = (value) => value === true || value === 'allow' || value === 'granted';

	// Senaste besked från respektive verktygs ändringshändelse. Händelsen kan
	// komma innan verktyget hunnit spara beskedet där det annars läses.
	let rccLatest = null;
	const wpLatest = {};
	let hookLatest = null;

	/*
	 * WP Consent API köar sitt skript med mycket hög prioritet, så det körs
	 * EFTER det här. Och en egen samtyckeslösning kan definiera kroken i ett
	 * senare skript. Inget samtyckesverktyg är därför inte avgjort förrän
	 * sidan laddat färdigt – fram till dess lämnas kakan orörd.
	 */
	let settled = document.readyState === 'complete';

	const wpConsentType = () => (typeof window.wp_consent_type !== 'undefined'
		? window.wp_consent_type
		: window.wp_fallback_consent_type);

	/**
	 * Vilket samtyckesverktyg som gäller just nu: 'rcc', 'wp-consent-api',
	 * 'hook', null (inget) eller 'pending' (inte avgjort ännu).
	 *
	 * WP Consent API utan samtyckestyp betyder att inget samtyckesverktyg
	 * kopplat in sig, och då svarar wp_has_consent() ja på allt. Det svaret
	 * används inte – API:et räknas bara när en typ (optin/optout) är satt.
	 * Med wp_consent_api_waitfor_consent_hook väntar vi på typen
	 * (wp_consent_type_defined) så länge det tar.
	 */
	const consentTool = () => {
		if (RCC_COOKIE) return 'rcc';

		if (typeof window.wp_has_consent === 'function') {
			if (wpConsentType()) return 'wp-consent-api';
			if (!settled || window.waitfor_consent_hook) return 'pending';
		} else if (WP_CONSENT_API && !settled) {
			return 'pending';
		}

		if (typeof window.relativtFormConsent === 'function' || hookLatest) return 'hook';

		return settled ? null : 'pending';
	};

	const granted = (tool, category) => {
		try {
			switch (tool) {
				case 'rcc': {
					const consent = rccLatest ?? window.rcc?.getConsent?.() ?? readCookie(RCC_COOKIE);
					return !!(consent && isYes(consent[category]));
				}
				case 'wp-consent-api':
					return category in wpLatest ? wpLatest[category] : !!window.wp_has_consent(category);
				case 'hook':
					if (hookLatest && category in hookLatest) return isYes(hookLatest[category]);
					return typeof window.relativtFormConsent === 'function' && isYes(window.relativtFormConsent(category));
				default:
					return false;
			}
		} catch {
			// Ett trasigt samtyckesverktyg ska aldrig betyda ja.
			return false;
		}
	};

	const NOTHING = { base: false, clicks: false };

	/** Vad kakan får innehålla just nu, eller null om det inte är avgjort. */
	const permissions = () => {
		if (UTM_MODE === 'always') return { base: true, clicks: true };
		if (UTM_MODE === 'never') return NOTHING;

		const tool = consentTool();
		if (tool === 'pending') return null;
		if (!tool) return NOTHING;

		return {
			base: CATEGORIES.attribution.some((category) => granted(tool, category)),
			clicks: CATEGORIES.clickIds.some((category) => granted(tool, category)),
		};
	};

	/** De delar av posten som får sparas, eller null om ingenting återstår. */
	const pick = (record, perms) => {
		if (!record || typeof record !== 'object' || !perms) return null;

		const keys = [...(perms.base ? BASE_KEYS : []), ...(perms.clicks ? CLICK_KEYS : [])];
		const out = {};
		let filled = false;

		for (const key of keys) {
			if (typeof record[key] !== 'string') continue;
			out[key] = record[key];
			filled = filled || record[key] !== '';
		}

		if (!filled) return null;
		out.t = Number(record.t) || Date.now();
		return out;
	};

	/* -- Posten -------------------------------------------------------- */

	const params = new URLSearchParams(location.search);
	const incoming = {};
	for (const key of CAMPAIGN_KEYS) {
		const value = params.get(key);
		if (value) incoming[key] = value.slice(0, 200);
	}
	const hasCampaign = Object.keys(incoming).length > 0;

	/** Den här sidans post – innan samtycket är avgjort är det den som gäller. */
	const pageRecord = {
		...incoming,
		landing: `${location.origin}${location.pathname}`,
		referrer: document.referrer && !document.referrer.includes(location.host) ? document.referrer : '',
		t: Date.now(),
	};

	/** Sätts när samtycket först är avgjort. */
	let state = null;

	/**
	 * Kakan skrivs vid första besöket, och skrivs om när besökaren kommer
	 * tillbaka via en NY kampanjlänk. Ett direktbesök däremellan rör aldrig
	 * posten – det är hela poängen med att spara den.
	 *
	 * Ur en befintlig kaka läses bara det som får sparas nu. Dras samtycket
	 * till marknadsföring tillbaka försvinner alltså klick-id även ur minnet,
	 * och de kommer inte tillbaka om samtycket ges igen senare på sidan.
	 */
	const initialise = (perms) => {
		const stored = pick(readCookie(COOKIE), perms);
		const page = { ...pageRecord, referrer: pageRecord.referrer || stored?.referrer || '' };
		const fromUrl = hasCampaign || !stored;
		state = { page, fromUrl, captured: fromUrl ? page : stored };
	};

	/** Attributionen som följer med ett inskick. */
	const currentSource = () => {
		if (!state) return pageRecord;
		if (state.fromUrl) return state.captured;

		// Posten kom ur kakan: samma gränser som för kakan, även i minnet.
		const perms = permissions();
		if (!perms) return state.captured;
		return pick(state.captured, perms) ?? state.page;
	};

	/** Skriver, skalar av eller tar bort kakan efter dagens samtycke. */
	const sync = () => {
		const perms = permissions();
		if (!perms) return; // Inte avgjort – kakan lämnas orörd.

		if (!state) initialise(perms);

		const record = pick(state.captured, perms);
		if (record) {
			if (JSON.stringify(record) !== JSON.stringify(readCookie(COOKIE))) writeCookie(COOKIE, record);
		} else if (hasCookie(COOKIE)) {
			removeCookie(COOKIE);
		}
	};

	// Samtycket kan komma, ändras eller dras tillbaka långt efter sidladdningen.
	if (UTM_MODE === 'auto') {
		document.addEventListener('rcc_consent_updated', (event) => {
			if (event.detail && typeof event.detail === 'object') rccLatest = event.detail;
			sync();
		});

		document.addEventListener('wp_listen_for_consent_change', (event) => {
			const changed = event.detail && typeof event.detail === 'object' ? event.detail : {};
			for (const category of Object.keys(changed)) wpLatest[category] = isYes(changed[category]);
			sync();
		});
		document.addEventListener('wp_consent_type_defined', sync);

		/*
		 * Den generella kroken för egna samtyckeslösningar: definiera
		 * window.relativtFormConsent = (kategori) => true/false, och skicka
		 * relativt-form:consent när samtycket ändras. Händelsens detail kan
		 * bära beskedet direkt: { statistics: true, marketing: false }.
		 */
		document.addEventListener('relativt-form:consent', (event) => {
			if (event.detail && typeof event.detail === 'object') hookLatest = { ...(hookLatest ?? {}), ...event.detail };
			sync();
		});

		if (!settled) {
			window.addEventListener('load', () => {
				settled = true;
				sync();
			});
		}
	}

	sync();

	/** Felsökning: vilket verktyg som läses och vad kakan får innehålla. */
	const consentStatus = () => {
		const perms = permissions();
		return {
			mode: UTM_MODE,
			tool: UTM_MODE === 'auto' ? consentTool() : null,
			attribution: perms ? perms.base : null,
			clickIds: perms ? perms.clicks : null,
		};
	};

	/* =========================================================================
	 * 2. Formulärlogik
	 * ====================================================================== */

	// I Oxygens builder ska formuläret vara ett vanligt redigerbart block.
	const inBuilder = () => document.body?.classList.contains('oxygen-builder-body');

	/*
	 * Standardtexterna SPEGLAR messages() i class-relativt-form.php – på en
	 * riktig sajt skrivs de över av samma filtrerade lista via konfigurationen,
	 * så klient och server säger ordagrant samma sak.
	 */
	const MESSAGES = {
		required: 'Fyll i detta fält.',
		email: 'Kontrollera e-postadressen.',
		tel: 'Ange ett giltigt telefonnummer, t.ex. 070-123 45 67.',
		number: 'Ange ett nummer.',
		date: 'Kontrollera datumet.',
		url: 'Ange en giltig webbadress, t.ex. https://exempel.se.',
		links: 'Meddelandet innehåller för många länkar.',
		consent: 'Du behöver godkänna villkoren.',
		generic: 'Något gick fel. Försök igen om en liten stund.',
		turnstile: 'Säkerhetskontrollen är inte klar. Vänta en sekund och försök igen.',
		...(typeof CONFIG.messages === 'object' && CONFIG.messages !== null ? CONFIG.messages : {}),
	};

	/** Speglar länkspärren i validate() – samma tak, samma räkning. 0 = av. */
	const MAX_LINKS = Number.isFinite(Number(CONFIG.maxLinks)) ? Math.max(0, Math.trunc(Number(CONFIG.maxLinks))) : 3;

	/* -------------------------------------------------------------------------
	 * E-post och telefon
	 *
	 * SPEGLAR valid_email() och normalize_phone() i class-relativt-form.php. Ändras den
	 * ena MÅSTE den andra ändras – annars godkänner webbläsaren något servern
	 * sedan avvisar, vilket ser ut som ett slumpmässigt fel för besökaren.
	 * ---------------------------------------------------------------------- */

	const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i;

	const validEmail = (value) => {
		const email = value.trim();
		if (!EMAIL_RE.test(email)) return false;
		if (email.includes('..') || email.includes('.@') || email.includes('@.')) return false;
		return true;
	};

	/** Svenskt nummer med inledande nolla: 8–12 siffror, inte samma siffra rakt igenom. */
	const validNational = (digits) => {
		if (digits.length < 8 || digits.length > 12) return false;
		return !/^(\d)\1+$/.test(digits);
	};

	/** Returnerar det normaliserade numret, eller null om det inte kan vara ett nummer. */
	const normalizePhone = (raw) => {
		const input = raw.trim();
		if (input === '') return null;

		let plus = input.startsWith('+');
		let digits = input.replace(/\D+/g, '');
		if (digits === '') return null;

		// 0046… är samma sak som +46…
		if (!plus && digits.startsWith('00')) {
			plus = true;
			digits = digits.slice(2);
		}

		if (plus) {
			if (digits.startsWith('46')) {
				const national = `0${digits.slice(2).replace(/^0+/, '')}`;
				return validNational(national) ? national : null;
			}
			// Landsnummer börjar aldrig med noll. Utan den kontrollen tolkas
			// t.ex. 0000000000 som "+00000000" och släpps igenom.
			if (digits.startsWith('0')) return null;
			return digits.length >= 8 && digits.length <= 15 ? `+${digits}` : null;
		}

		if (digits.startsWith('0')) {
			return validNational(digits) ? digits : null;
		}

		// Mobilnummer utan inledande nolla, t.ex. "701234567".
		if (digits.length === 9 && digits.startsWith('7')) return `0${digits}`;

		return null;
	};

	/**
	 * Speglar datumkontrollen i validate(): formatet räcker inte, 2026-13-45
	 * matchar mönstret. Date-objektet rullar över ogiltiga datum (13:e månaden
	 * blir januari året därpå), så komponenterna jämförs efter konstruktionen.
	 */
	const validDate = (value) => {
		if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
		const [y, m, d] = value.split('-').map(Number);
		const date = new Date(y, m - 1, d);
		return date.getFullYear() === y && date.getMonth() === m - 1 && date.getDate() === d;
	};

	/** Speglar länkräkningen i validate() – samma mönster, samma resultat. */
	const countLinks = (value) => (value.match(/https?:\/\/|www\./gi) ?? []).length;

	/**
	 * Returnerar en normaliserad URL, eller null om värdet inte kan vara en
	 * webbadress. SPEGLAR normalize_url() i class-relativt-form.php – ändras
	 * den ena MÅSTE den andra ändras.
	 */
	const normalizeUrl = (raw) => {
		let input = raw.trim();
		if (input === '') return null;

		if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(input)) {
			input = `https://${input.replace(/^\/+/, '')}`;
		}

		try {
			const url = new URL(input);
			if (!url.hostname.includes('.')) return null;
			return url.href;
		} catch {
			return null;
		}
	};

	class RelativtForm {
		constructor(root) {
			this.root = root;
			this.form = root.querySelector('.xf-form');
			this.thanks = root.querySelector('.xf-thanks');
			this.formError = root.querySelector('.xf-form-error');
			this.submitBtn = root.querySelector('.xf-submit');
			// Turnstile-widgeten finns bara när formuläret kräver det (1.4.0).
			this.turnstileBox = root.querySelector('[data-xf-turnstile]');
			this.turnstileField = this.turnstileBox?.closest('.xf-field') ?? null;
			this.id = root.dataset.xfForm;
			this.rest = root.dataset.xfRest;
			this.token = null;
			this.tokenPromise = null;
			this.busy = false;
			this.redirecting = false;

			this.fields = [...root.querySelectorAll('[data-xf-key]')].filter((el) => el.dataset.xfKey !== '');

			this.applyUrlPresets();
			this.mountTurnstile();
			this.wireA11y();
			this.bind();
			this.evaluateConditions();

			root.classList.add('is-ready');
		}

		/**
		 * Kopplar hjälptext och felrad till fältet via aria-describedby, en
		 * gång vid start. Felraden är tom tills ett fel visas – en tom
		 * beskrivning läses inte upp, så kopplingen kan ligga kvar permanent
		 * i stället för att växlas fram och tillbaka.
		 */
		wireA11y() {
			for (const field of this.fields) {
				const ids = [field.querySelector('.xf-help')?.id, field.querySelector('.xf-error')?.id].filter(Boolean);
				if (!ids.length) continue;

				for (const input of this.inputs(field)) {
					input.setAttribute('aria-describedby', ids.join(' '));
				}
			}
		}

		/* -- Fältåtkomst ------------------------------------------------- */

		wrapper(key) {
			return this.fields.find((el) => el.dataset.xfKey === key) ?? null;
		}

		inputs(wrapper) {
			return [...wrapper.querySelectorAll('input, select, textarea')].filter((el) => el.name !== 'xf_website');
		}

		valueOf(key) {
			const wrapper = this.wrapper(key);
			if (!wrapper) return '';

			const inputs = this.inputs(wrapper);
			if (!inputs.length) return '';

			const first = inputs[0];

			if (first.type === 'radio') {
				return inputs.find((el) => el.checked)?.value ?? '';
			}
			if (first.type === 'checkbox') {
				const checked = inputs.filter((el) => el.checked);
				if (inputs.length === 1) return checked.length ? '1' : '';
				return checked.map((el) => el.value).join(', ');
			}
			return first.value ?? '';
		}

		/**
		 * Det tekniska värdet OCH den synliga etiketten. Villkor och regler
		 * skrivs i wp-admin av en människa som lika gärna skriver "Kandidat"
		 * som "kandidat" – båda måste träffa, och servern gör samma sak.
		 */
		candidatesOf(key) {
			const wrapper = this.wrapper(key);
			const raw = this.valueOf(key);
			if (!wrapper) return [raw];

			const inputs = this.inputs(wrapper);
			const first = inputs[0];
			let label = '';

			if (first?.type === 'radio') {
				const checked = inputs.find((el) => el.checked);
				label = checked ? wrapper.querySelector(`label[for="${CSS.escape(checked.id)}"]`)?.textContent ?? '' : '';
			} else if (first?.tagName === 'SELECT') {
				label = first.options[first.selectedIndex]?.textContent ?? '';
			}

			return [raw, label.trim()].filter(Boolean);
		}

		setValue(key, value) {
			const wrapper = this.wrapper(key);
			if (!wrapper) return;

			const inputs = this.inputs(wrapper);
			if (!inputs.length) return;

			const first = inputs[0];

			if (first.type === 'radio' || (first.type === 'checkbox' && inputs.length > 1)) {
				const wanted = String(value).trim().toLowerCase();
				// Matcha på tekniskt värde i första hand, annars på den synliga
				// etiketten – en kampanjlänk skrivs av en människa.
				const labelOf = (input) =>
					(wrapper.querySelector(`label[for="${CSS.escape(input.id)}"]`)?.textContent ?? '').trim().toLowerCase();

				const target =
					inputs.find((input) => input.value.trim().toLowerCase() === wanted) ??
					inputs.find((input) => labelOf(input) === wanted);

				if (!target) return;
				for (const input of inputs) input.checked = input === target;
				return;
			}
			if (first.type === 'checkbox') {
				first.checked = value === '1' || value === 'true';
				return;
			}
			if (first.tagName === 'SELECT') {
				const wanted = String(value).trim().toLowerCase();
				const match =
					[...first.options].find((o) => o.value.trim().toLowerCase() === wanted) ??
					[...first.options].find((o) => o.textContent.trim().toLowerCase() === wanted);
				if (match) first.value = match.value;
				return;
			}
			first.value = value;
		}

		/**
		 * URL-parametern vinner över shortcode-attributet, så kampanjlänkar kan
		 * styra förvalet: ?jagar=kandidat
		 */
		applyUrlPresets() {
			const params = new URLSearchParams(location.search);
			for (const field of this.fields) {
				const key = field.dataset.xfKey;
				if (params.has(key)) this.setValue(key, params.get(key));
			}
		}

		/* -- Villkor ------------------------------------------------------ */

		matches(candidates, expected) {
			const list = (Array.isArray(candidates) ? candidates : [candidates]).map((v) => String(v).trim());
			const wanted = String(expected ?? '').trim();

			if (wanted === '') return list.some(Boolean);

			const accepted = wanted
				.split(',')
				.map((v) => v.trim().toLowerCase())
				.filter(Boolean);

			return list.some((value) => accepted.includes(value.toLowerCase()));
		}

		evaluateConditions() {
			for (const field of this.fields) {
				const controller = field.dataset.xfCondField;
				if (!controller) continue;

				const visible = this.matches(this.candidatesOf(controller), field.dataset.xfCondValue);
				field.hidden = !visible;
				field.classList.toggle('is-hidden', !visible);

				if (!visible) this.clearError(field);
			}
		}

		/**
		 * Är fältets VILLKOR uppfyllt? Fält utan villkor är alltid med.
		 *
		 * Regression 1.1.3: testet var tidigare "är wrappern dold?"
		 * (field.hidden) – men fälttypen Dolt fält renderas med samma
		 * hidden-attribut som villkorsdolda fält, så dolda fält skickades
		 * ALDRIG med i inskicket. Bara villkoret får avgöra deltagandet;
		 * att typen inte syns är en annan sak än att fältet inte gäller.
		 */
		condSatisfied(field) {
			const controller = field.dataset.xfCondField;
			if (!controller) return true;
			return this.matches(this.candidatesOf(controller), field.dataset.xfCondValue);
		}

		/* -- Turnstile ---------------------------------------------------- */

		/*
		 * Widgeten renderas av Cloudflares api.js (implicit rendering via
		 * klassen cf-turnstile). Varje formulär pratar med SIN container – inte
		 * med ett widget-id – så att sidan och modalen inte blandar ihop
		 * varandras token. getResponse/reset tar containern lika gärna som id.
		 */
		mountTurnstile() {
			if (!this.turnstileBox) return;
			// Implicit rendering hittar bara containrar som finns när api.js
			// laddas. Ett formulär som läggs in senare (ajax-modal) renderas här.
			if (document.readyState === 'complete' && window.turnstile?.render && !this.turnstileBox.childElementCount) {
				try {
					window.turnstile.render(this.turnstileBox);
				} catch {
					/* Redan renderad – inget att göra. */
				}
			}
		}

		turnstileToken() {
			if (!this.turnstileBox) return '';
			let token = '';
			try {
				token = window.turnstile?.getResponse?.(this.turnstileBox) ?? '';
			} catch {
				token = '';
			}
			// Widgeten skriver också token till ett dolt fält i containern.
			return token || this.turnstileBox.querySelector('[name="cf-turnstile-response"]')?.value || '';
		}

		/** En token kan bara verifieras en gång – efter ett misslyckat inskick behövs en ny. */
		resetTurnstile() {
			if (!this.turnstileBox) return;
			try {
				window.turnstile?.reset?.(this.turnstileBox);
			} catch {
				/* Ingen widget att återställa. */
			}
		}

		/* -- Fel ---------------------------------------------------------- */

		showError(field, message) {
			const target = field.querySelector('.xf-error');
			if (target) target.textContent = message;
			field.classList.add('has-error');
			for (const input of this.inputs(field)) input.setAttribute('aria-invalid', 'true');
		}

		clearError(field) {
			const target = field.querySelector('.xf-error');
			if (target) target.textContent = '';
			field.classList.remove('has-error');
			for (const input of this.inputs(field)) input.removeAttribute('aria-invalid');
		}

		clearAllErrors() {
			for (const field of this.root.querySelectorAll('.xf-field')) this.clearError(field);
			if (this.formError) this.formError.textContent = '';
		}

		/* -- Validering --------------------------------------------------- */

		validate() {
			let firstBad = null;

			for (const field of this.fields) {
				if (!this.condSatisfied(field)) continue;

				const key = field.dataset.xfKey;
				const type = [...field.classList].find((c) => c.startsWith('xf-type-'))?.replace('xf-type-', '') ?? 'text';
				if (type === 'heading' || type === 'hidden') continue;

				const value = String(this.valueOf(key)).trim();

				/*
				 * data-xf-required sätts av renderaren. required-ATTRIBUTET
				 * räcker inte: en flervalsgrupp kan inte bära det (då kräver
				 * webbläsaren ALLA rutor), så utan flaggan åkte obligatoriska
				 * flerval till servern i onödan bara för att studsa där.
				 */
				const required = field.dataset.xfRequired === '1' || this.inputs(field).some((el) => el.required);

				if (required && value === '') {
					this.showError(field, MESSAGES.required);
					firstBad ??= field;
					continue;
				}
				if (value === '') continue;

				if (type === 'email' && !validEmail(value)) {
					this.showError(field, MESSAGES.email);
					firstBad ??= field;
				} else if (type === 'tel' && normalizePhone(value) === null) {
					this.showError(field, MESSAGES.tel);
					firstBad ??= field;
				} else if (type === 'number' && Number.isNaN(Number(value))) {
					this.showError(field, MESSAGES.number);
					firstBad ??= field;
				} else if (type === 'date' && !validDate(value)) {
					this.showError(field, MESSAGES.date);
					firstBad ??= field;
				} else if (type === 'url' && normalizeUrl(value) === null) {
					this.showError(field, MESSAGES.url);
					firstBad ??= field;
				} else if (type === 'textarea' && MAX_LINKS > 0 && countLinks(value) > MAX_LINKS) {
					this.showError(field, MESSAGES.links);
					firstBad ??= field;
				}
			}

			const consent = this.root.querySelector('.xf-type-consent .xf-check-input');
			if (consent && !consent.checked) {
				const field = consent.closest('.xf-field');
				this.showError(field, MESSAGES.consent);
				firstBad ??= field;
			}

			// Utan token avvisar servern ändå – säg det vid widgeten direkt.
			if (this.turnstileField && this.turnstileToken() === '') {
				this.showError(this.turnstileField, MESSAGES.turnstile);
				firstBad ??= this.turnstileField;
			}

			return firstBad;
		}

		/* -- Token -------------------------------------------------------- */

		/**
		 * Nonce och tidsstämpel hämtas separat, så att en cachad sida inte
		 * serverar en utgången nonce. Tidsstämpeln är signerad på servern.
		 */
		fetchToken() {
			if (this.token) return Promise.resolve(this.token);
			this.tokenPromise ??= fetch(`${this.rest}token?form=${encodeURIComponent(this.id)}`, {
				credentials: 'same-origin',
			})
				.then((r) => (r.ok ? r.json() : null))
				.then((data) => {
					this.token = data;
					return data;
				})
				.catch(() => null)
				.finally(() => {
					this.tokenPromise = null;
				});
			return this.tokenPromise;
		}

		/* -- Nyttolast ---------------------------------------------------- */

		payload() {
			const fields = {};

			for (const field of this.fields) {
				// Villkoret avgör – INTE wrapperns hidden-attribut, som även
				// fälttypen Dolt fält bär. Rubriker faller bort på inputs-
				// kontrollen nedan.
				if (!this.condSatisfied(field)) continue;

				const key = field.dataset.xfKey;
				const inputs = this.inputs(field);
				if (!inputs.length) continue;

				const first = inputs[0];
				if (first.type === 'checkbox' && inputs.length > 1) {
					fields[key] = inputs.filter((el) => el.checked).map((el) => el.value);
				} else {
					fields[key] = this.valueOf(key);
				}
			}

			const utm = { ...currentSource() };
			delete utm.t;

			return {
				form: this.id,
				fields,
				utm,
				page: `${location.origin}${location.pathname}`,
				xf_website: this.form.querySelector('[name="xf_website"]')?.value ?? '',
				xf_consent: this.root.querySelector('.xf-type-consent .xf-check-input')?.checked ? 1 : 0,
				nonce: this.token?.nonce ?? '',
				ts: this.token?.ts ?? 0,
				sig: this.token?.sig ?? '',
				// Bara när widgeten finns – nyttolasten är oförändrad för alla andra formulär.
				...(this.turnstileBox ? { turnstile: this.turnstileToken() } : {}),
			};
		}

		/* -- Inskick ------------------------------------------------------ */

		setBusy(busy) {
			this.busy = busy;
			if (!this.submitBtn) return;

			this.submitBtn.disabled = busy;
			this.root.classList.toggle('is-sending', busy);

			// Knappanimationen i temat lindar knapptexten i .btn-anim-text. Skriv till
			// den innersta noden, annars raderas lindningen och hover-animationen
			// slutar fungera efter första inskicket.
			const label =
				this.submitBtn.querySelector('.xf-submit-text .btn-anim-text') ??
				this.submitBtn.querySelector('.xf-submit-text');

			if (label) {
				label.textContent = busy
					? this.submitBtn.dataset.xfSending || 'Skickar…'
					: this.submitBtn.dataset.xfLabel || label.textContent;
			}
		}

		async submit() {
			if (this.busy) return;

			this.clearAllErrors();

			const bad = this.validate();
			if (bad) {
				bad.querySelector('input, select, textarea')?.focus();
				return;
			}

			this.setBusy(true);
			try {
				await this.send(0);
			} finally {
				// Vid omdirigering behålls "Skickar…" tills sidbytet sker –
				// en knapp som hinner bli klickbar igen ger dubbelinskick.
				if (!this.redirecting) this.setBusy(false);
			}
		}

		/**
		 * Själva sändningen, skild från submit() så att den kan göra om sig
		 * själv. Två fall åtgärdas tyst i stället för att skickas tillbaka
		 * till besökaren:
		 *
		 *  - "toofast": tidsspärren. Token hämtas vid första fokus, och en
		 *    besökare med autofyll fyller allt på en sekund – hade spärren
		 *    fått synas åt besökaren vore den ren friktion. Vi väntar ut
		 *    serverns retry_after och skickar om. En bot som postar direkt
		 *    mot REST-rutten kör inte den här koden och får bara felet.
		 *  - "nonce": utgången nonce, typiskt en flik som stått öppen över
		 *    natten. Ny token hämtas och inskicket görs om, en gång.
		 */
		async send(attempt) {
			await this.fetchToken();

			try {
				const response = await fetch(`${this.rest}submit`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify(this.payload()),
				});

				const data = await response.json().catch(() => null);

				if (response.ok && data?.ok) {
					this.succeed(data);
					return;
				}

				if (response.status === 422 && data?.errors) {
					// Servern verifierar Turnstile FÖRE valideringen, så token är förbrukad.
					this.resetTurnstile();
					for (const [key, message] of Object.entries(data.errors)) {
						const field = this.wrapper(key) ?? this.root.querySelector('.xf-type-consent');
						if (field) this.showError(field, message);
					}
					this.root.querySelector('.has-error input, .has-error select, .has-error textarea')?.focus();
					return;
				}

				if (data?.code === 'toofast' && attempt < 2) {
					const wait = Math.min(10, Math.max(1, Number(data.retry_after) || 3));
					await new Promise((resolve) => setTimeout(resolve, wait * 1000 + 250));
					return this.send(attempt + 1);
				}

				if (data?.code === 'nonce' && attempt < 1) {
					this.token = null;
					return this.send(attempt + 1);
				}

				/*
				 * Slutgiltigt misslyckande: ny Turnstile-token till nästa försök.
				 * De tysta omförsöken ovan (toofast, nonce) återställer INTE –
				 * de spärrarna ligger före Turnstile på servern, så token är
				 * fortfarande oanvänd och följer med i omsändningen.
				 */
				this.resetTurnstile();

				if (data?.code === 'turnstile' && this.turnstileField) {
					this.showError(this.turnstileField, data.message || MESSAGES.turnstile);
					return;
				}

				if (this.formError) this.formError.textContent = data?.message || MESSAGES.generic;
			} catch {
				this.resetTurnstile();
				if (this.formError) this.formError.textContent = MESSAGES.generic;
			}
		}

		succeed(data) {
			// Låter GTM eller annan spårning hänga på utan att vi bakar in den.
			// Sänds FÖRE en eventuell omdirigering – men en extern förfrågan
			// hinner sällan iväg innan sidbytet, så på sajter med tack-sida
			// hör spårningen hemma på själva tack-sidan i stället.
			document.dispatchEvent(
				new CustomEvent('relativt-form:success', {
					bubbles: true,
					detail: { formId: this.id, root: this.root },
				})
			);

			// Tack-sida angiven i wp-admin: skicka besökaren dit i stället
			// för att visa tack-rutan. Adressen är satt av sajtens redaktör,
			// aldrig av besökaren.
			if (typeof data.redirect === 'string' && data.redirect !== '') {
				this.redirecting = true;
				window.location.assign(data.redirect);
				return;
			}

			if (data.title) {
				const title = this.thanks?.querySelector('.xf-thanks-title');
				if (title) title.textContent = data.title;
			}
			if (data.text) {
				const text = this.thanks?.querySelector('.xf-thanks-text');
				if (text) text.textContent = data.text;
			}

			this.form.hidden = true;
			if (this.thanks) this.thanks.hidden = false;
			this.root.classList.add('is-submitted');

			/*
			 * Modalens tack-läge, om formuläret ligger i en. Vi letar efter
			 * [data-modal] i stället för en klass från en viss sajt – det är
			 * ändå data-attributet som bär modalens id, och då fungerar
			 * kopplingen oavsett vad temat kallar sin modalruta.
			 */
			const modal = this.root.closest('[data-modal]');
			const modalId = modal?.dataset.modal;
			if (modalId && window.relativtFormModal?.onSubmitSuccess) {
				window.relativtFormModal.onSubmitSuccess(modalId);
			}

			this.thanks?.focus?.();
		}

		/* -- Bindningar ---------------------------------------------------- */

		bind() {
			// Token hämtas vid första interaktionen. Det ger också tidsspärren
			// något meningsfullt att mäta mot.
			const prime = () => this.fetchToken();
			this.form.addEventListener('focusin', prime, { once: true });
			this.form.addEventListener('input', prime, { once: true });

			this.form.addEventListener('change', () => this.evaluateConditions());

			this.form.addEventListener('input', (event) => {
				const field = event.target.closest?.('.xf-field');
				if (field?.classList.contains('has-error')) this.clearError(field);
			});

			this.form.addEventListener('submit', (event) => {
				event.preventDefault();
				this.submit();
			});
		}
	}

	/* =========================================================================
	 * Init
	 * ====================================================================== */

	const instances = new WeakMap();

	const init = (scope = document) => {
		if (inBuilder()) return;

		for (const root of scope.querySelectorAll('.relativt-form[data-xf-form]')) {
			if (instances.has(root)) continue;
			instances.set(root, new RelativtForm(root));
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => init());
	} else {
		init();
	}

	// Formulär som portas in i modalen eller läggs in via ajax.
	document.addEventListener('modal:open', () => init());

	window.relativtForm = { init, source: () => ({ ...currentSource() }), consent: consentStatus };
})();
