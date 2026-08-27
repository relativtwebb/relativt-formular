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
 */

(() => {
	'use strict';

	/* =========================================================================
	 * 1. Kampanjspårning
	 * ====================================================================== */

	const COOKIE = 'xf_src';
	const DAYS = 90;
	const CAMPAIGN_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];

	const readCookie = (name) => {
		const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
		if (!match) return null;
		try {
			return JSON.parse(decodeURIComponent(match.slice(name.length + 1)));
		} catch {
			return null;
		}
	};

	const writeCookie = (name, value) => {
		const expires = new Date(Date.now() + DAYS * 864e5).toUTCString();
		const secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = `${name}=${encodeURIComponent(JSON.stringify(value))}; expires=${expires}; path=/; SameSite=Lax${secure}`;
	};

	/**
	 * Skrivs vid första besöket, och skrivs om när besökaren kommer tillbaka
	 * via en NY kampanjlänk. Ett direktbesök däremellan rör aldrig posten – det
	 * är hela poängen med att spara den.
	 */
	const captureSource = () => {
		const params = new URLSearchParams(location.search);
		const incoming = {};

		for (const key of CAMPAIGN_KEYS) {
			const value = params.get(key);
			if (value) incoming[key] = value.slice(0, 200);
		}

		const existing = readCookie(COOKIE);
		const hasCampaign = Object.keys(incoming).length > 0;

		if (existing && !hasCampaign) return existing;

		const referrer = document.referrer && !document.referrer.includes(location.host) ? document.referrer : '';

		const record = {
			...incoming,
			landing: `${location.origin}${location.pathname}`,
			referrer: referrer || existing?.referrer || '',
			t: Date.now(),
		};

		writeCookie(COOKIE, record);
		return record;
	};

	const source = captureSource();

	/* =========================================================================
	 * 2. Formulärlogik
	 * ====================================================================== */

	// I Oxygens builder ska formuläret vara ett vanligt redigerbart block.
	const inBuilder = () => document.body?.classList.contains('oxygen-builder-body');

	const MESSAGES = {
		required: 'Fyll i detta fält.',
		email: 'Kontrollera e-postadressen.',
		tel: 'Ange ett giltigt telefonnummer, t.ex. 070-123 45 67.',
		number: 'Ange ett nummer.',
		date: 'Kontrollera datumet.',
		consent: 'Du behöver godkänna villkoren.',
		generic: 'Något gick fel. Försök igen om en liten stund.',
	};

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

	class RelativtForm {
		constructor(root) {
			this.root = root;
			this.form = root.querySelector('.xf-form');
			this.thanks = root.querySelector('.xf-thanks');
			this.formError = root.querySelector('.xf-form-error');
			this.submitBtn = root.querySelector('.xf-submit');
			this.id = root.dataset.xfForm;
			this.rest = root.dataset.xfRest;
			this.token = null;
			this.tokenPromise = null;
			this.busy = false;

			this.fields = [...root.querySelectorAll('[data-xf-key]')].filter((el) => el.dataset.xfKey !== '');

			this.applyUrlPresets();
			this.bind();
			this.evaluateConditions();

			root.classList.add('is-ready');
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

		isVisible(field) {
			return !field.hidden && field.dataset.xfKey !== undefined && !field.classList.contains('xf-type-heading');
		}

		/* -- Fel ---------------------------------------------------------- */

		showError(field, message) {
			const target = field.querySelector('.xf-error');
			if (target) target.textContent = message;
			field.classList.add('has-error');
		}

		clearError(field) {
			const target = field.querySelector('.xf-error');
			if (target) target.textContent = '';
			field.classList.remove('has-error');
		}

		clearAllErrors() {
			for (const field of this.root.querySelectorAll('.xf-field')) this.clearError(field);
			if (this.formError) this.formError.textContent = '';
		}

		/* -- Validering --------------------------------------------------- */

		validate() {
			let firstBad = null;

			for (const field of this.fields) {
				if (!this.isVisible(field)) continue;

				const key = field.dataset.xfKey;
				const type = [...field.classList].find((c) => c.startsWith('xf-type-'))?.replace('xf-type-', '') ?? 'text';
				if (type === 'heading' || type === 'hidden') continue;

				const value = String(this.valueOf(key)).trim();
				const required = this.inputs(field).some((el) => el.required);

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
				} else if (type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
					this.showError(field, MESSAGES.date);
					firstBad ??= field;
				}
			}

			const consent = this.root.querySelector('.xf-type-consent .xf-check-input');
			if (consent && !consent.checked) {
				const field = consent.closest('.xf-field');
				this.showError(field, MESSAGES.consent);
				firstBad ??= field;
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
				if (!this.isVisible(field)) continue;

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

			const utm = { ...source };
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
					for (const [key, message] of Object.entries(data.errors)) {
						const field = this.wrapper(key) ?? this.root.querySelector('.xf-type-consent');
						if (field) this.showError(field, message);
					}
					this.root.querySelector('.has-error input, .has-error select, .has-error textarea')?.focus();
					return;
				}

				// Utgången nonce – hämta en ny och låt besökaren trycka igen.
				if (data?.code === 'nonce') {
					this.token = null;
					await this.fetchToken();
				}

				if (this.formError) this.formError.textContent = data?.message || MESSAGES.generic;
			} catch {
				if (this.formError) this.formError.textContent = MESSAGES.generic;
			} finally {
				this.setBusy(false);
			}
		}

		succeed(data) {
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

			// Låter GTM eller annan spårning hänga på utan att vi bakar in den.
			document.dispatchEvent(
				new CustomEvent('relativt-form:success', {
					bubbles: true,
					detail: { formId: this.id, root: this.root },
				})
			);

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

	window.relativtForm = { init, source: () => ({ ...source }) };
})();
