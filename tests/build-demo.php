<?php
/**
 * Genererar demo-form.html med markup från den RIKTIGA renderaren, inbäddad i
 * en simulerad Oxygen-DOM. Demon kan alltså aldrig glida ifrån PHP-koden.
 *
 * Körs: php tests/build-demo.php
 */

require __DIR__ . '/harness.php';

$engine = Relativt_Form::instance();

$reflection = new ReflectionClass( $engine );
$render     = $reflection->getMethod( 'render_form' );
$render->setAccessible( true );

/** Kontaktsidan: förvalt Företag + dolt fält satt via "shortcoden". Modalen: förvalt Kandidat. */
$page_form  = $render->invoke( $engine, 12, [ 'jagar' => 'foretag', 'audit' => 'Webb' ], '' );
$modal_form = $render->invoke( $engine, 12, [ 'jagar' => 'kandidat' ], '' );
/** Tredje instansen används bara av regressionstestet för Oxygens id-regler. */
$regression_form = $render->invoke( $engine, 12, [ 'jagar' => 'foretag' ], '' );

$css = file_get_contents( __DIR__ . '/../assets/css/relativt-formular.css' );
$js  = file_get_contents( __DIR__ . '/../assets/js/relativt-formular.js' );

$html = <<<HTML
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relativt Formulär – demo</title>

<style>
/* =============================================================================
   SIMULERAD OXYGEN-DOM
   Speglar fällorna i arbetssättsanteckningen: inner-wrap är flex column med
   max-width och noll sidopadding, ct-div-block är flex column med
   align-items: flex-start, och id-regler skrivs så fort man rör Layout.
   ========================================================================== */
* { box-sizing: border-box; }
body {
	margin: 0;
	font-family: "Helvetica Neue", Arial, sans-serif;
	color: #2b2a28;
	background: #eceae6;
	-webkit-font-smoothing: antialiased;
}
.ct-section { width: 100%; padding: 60px 0; }
.ct-section-inner-wrap {
	display: flex;
	flex-direction: column;
	max-width: 1200px;
	margin: 0 auto;
	padding: 0 clamp(20px, -21.667px + 8.681vw, 45px);
	width: 100%;
}
.ct-div-block { display: flex; flex-direction: column; align-items: flex-start; }
.ct-text-block, .ct-fancy-icon { display: inline-block; }

/* Oxygen skriver id-regler på ELEMENT så fort Layout rörs. Regressionstest. */
#div_block-42-9 { display: flex; flex-direction: row; align-items: center; }

.card {
	width: 100%;
	max-width: 900px;
	margin: 0 auto;
	padding: clamp(24px, 4vw, 56px);
	background: #fff;
	border-radius: 4px;
	box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
h1, h2 { font-size: 18px; margin: 0 0 20px; letter-spacing: .04em; text-transform: uppercase; }
#xf-regression { background: #e4e1db; }
#xf-regression .card { overflow: hidden; }
.regression-sibling { flex: 0 0 auto; padding-right: 16px; font-size: 12px; color: #999; }

/* Enkel .btn-stub som liknar Oxygens UI-styling */
.btn { text-decoration: none; }

/* Modal-stub – bara det som behövs för att testa integrationen */
.site-modal { position: fixed; inset: 0; z-index: 10000; display: none; }
.site-modal.is-open { display: block; }
.site-modal-overlay { position: absolute; inset: 0; background: rgba(0,0,0,.4); }
.site-modal-panel {
	position: absolute; left: 0; right: 0; bottom: 0;
	height: 90dvh; background: #fff; overflow: auto; padding: 32px;
}
.site-modal.is-submitted .site-modal-form { display: none; }
.demo-open { margin: 20px 0 0; padding: 12px 20px; cursor: pointer; }
</style>

<style>
/* ===== relativt-formular.css ===== */
{$css}
</style>
</head>
<body>

<div class="ct-section">
	<div class="ct-section-inner-wrap">
		<h1>Kontaktsidan</h1>

		<div class="ct-div-block card" id="xf-page">
			{$page_form}
		</div>

		<button class="demo-open" data-modal-target="kontakt" type="button">Öppna kontaktmodalen</button>
	</div>
</div>

<!--
	Regressionsyta: föräldern har en id-regel som gör den till flex ROW med ett
	syskon. Utan flex-shrink: 0 på roten trycks formuläret ihop till innehållets
	bredd. Ligger separat så den inte förstör den visuella demon ovan.
-->
<div class="ct-section" id="xf-regression">
	<div class="ct-section-inner-wrap">
		<h2>Regressionstest: Oxygen-id-regel gör föräldern till flex row</h2>
		<div class="ct-div-block card" id="div_block-42-9">
			<span class="regression-sibling">Syskon</span>
			{$regression_form}
		</div>
	</div>
</div>

<!-- Modal-stub, med formuläret förvalt på Kandidat -->
<div class="site-modal" data-modal="kontakt">
	<div class="site-modal-overlay" data-modal-close></div>
	<div class="site-modal-panel">
		<div class="site-modal-inner">
			<div class="site-modal-form">
				<h1>Kontaktmodalen</h1>
				{$modal_form}
			</div>
			<div class="site-modal-thanks" hidden>Tack!</div>
		</div>
	</div>
</div>

<script>
/* =============================================================================
   MOCKAD SERVER
   Efterliknar REST-endpointen: token, honungsfälla, tidsspärr, validering.
   Testerna läser window.__mockCalls för att kontrollera vad som skickades.
   ========================================================================== */
window.__mockCalls = [];
// Sätt till {status, payload} för att tvinga ett svar. once: true nollställer
// mocken efter ETT svar – så retry-testerna kan låta första försöket falla
// och det andra lyckas, precis som mot en riktig server.
window.__mockFail = null;
// Sätt till en URL för att efterlikna ett formulär med tack-sida angiven.
window.__mockRedirect = null;

const realFetch = window.fetch.bind(window);

window.fetch = async (url, options = {}) => {
	const href = String(url);

	if (href.includes('/token')) {
		const ts = Math.floor(Date.now() / 1000) - 60; // äldre än tidsspärren
		return new Response(JSON.stringify({ nonce: 'test-nonce', ts, sig: 'test-sig' }), {
			status: 200, headers: { 'Content-Type': 'application/json' },
		});
	}

	if (href.includes('/submit')) {
		const body = JSON.parse(options.body || '{}');
		window.__mockCalls.push(body);

		if (window.__mockFail) {
			const { status, payload, once } = window.__mockFail;
			if (once) window.__mockFail = null;
			return new Response(JSON.stringify(payload), {
				status, headers: { 'Content-Type': 'application/json' },
			});
		}

		// Honungsfällan: låtsas att det gick bra.
		if ((body.xf_website || '').trim() !== '') {
			return new Response(JSON.stringify({ ok: true, title: 'Tack!', text: '' }), { status: 200 });
		}

		const errors = {};
		if (!body.fields.namn) errors.namn = 'Fyll i detta fält.';
		if (!body.fields.epost) errors.epost = 'Fyll i detta fält.';

		if (Object.keys(errors).length) {
			return new Response(JSON.stringify({ ok: false, errors }), {
				status: 422, headers: { 'Content-Type': 'application/json' },
			});
		}

		return new Response(JSON.stringify({
			ok: true,
			title: 'Tack för ditt meddelande!',
			text: 'Vi återkommer till dig så snart vi kan.',
			...(window.__mockRedirect ? { redirect: window.__mockRedirect } : {}),
		}), { status: 200, headers: { 'Content-Type': 'application/json' } });
	}

	return realFetch(url, options);
};

/* Modal-stub med samma API som temats modal */
window.relativtFormModal = {
	opened: [],
	submitted: [],
	open(id) {
		this.opened.push(id);
		document.querySelector(`.site-modal[data-modal="\${id}"]`)?.classList.add('is-open');
		document.dispatchEvent(new CustomEvent('modal:open', { detail: { id } }));
	},
	onSubmitSuccess(id) {
		this.submitted.push(id);
		document.querySelector(`.site-modal[data-modal="\${id}"]`)?.classList.add('is-submitted');
	},
};

document.addEventListener('click', (e) => {
	const trigger = e.target.closest('[data-modal-target]');
	if (trigger) window.relativtFormModal.open(trigger.dataset.modalTarget);
});
</script>

<script>
/* ===== relativt-formular.js ===== */
{$js}
</script>
</body>
</html>
HTML;

file_put_contents( __DIR__ . '/../demo-form.html', $html );
echo "demo-form.html skriven (" . number_format( strlen( $html ) ) . " tecken)\n";
