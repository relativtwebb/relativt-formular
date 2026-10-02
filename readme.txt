=== Relativt Formulär ===
Contributors: relativt
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Formulärmotor för WordPress. Bygg formulär i wp-admin, varje formulär får en egen shortcode.

== Description ==

Formulärbyggare med villkorliga fält som utvärderas på servern, mottagarregler,
validering av e-post, telefon och URL, spamskydd utan CAPTCHA (valfritt med
Cloudflare Turnstile), UTM-attribution, inskickslagring med gallring, export
och import av formulär mellan sajter, statistik över inskicken samt ett REST-API
för headless-frontends.

Kräver Advanced Custom Fields Pro.

Fullständig dokumentation: https://github.com/relativtwebb/relativt-formular

== Upgrade Notice ==

= 1.7.0 =
Utan samtyckesverktyg skrivs kampanjkakan inte längre. Se CHANGELOG för hur
det gamla beteendet behålls.

== Changelog ==

= 1.7.0 =
BETEENDEÄNDRING: på sajter utan samtyckesverktyg skrivs kampanjkakan xf_src
inte längre – attributionen hålls bara i minnet på landningssidan, och en
befintlig kaka tas bort. Vill ni ha det gamla beteendet: filtret
relativt_form_utm_cookie → 'always'. Klick-id (gclid, fbclid) kräver nu
samtycke till marknadsföring; statistik räcker för UTM, landningssida och
hänvisare. Nytt: stöd för WP Consent API, en JS-krok för egna
samtyckeslösningar och filtrerbara samtyckeskategorier.

= 1.6.1 =
Statistiksidan: listor där inte alla inskick har ett värde – hänvisande
webbplatser, UTM-taggar, annonsklick, sidor och följda fält – får en grå
sista rad med resten, t.ex. "Ingen extern webbplats", så att andelarna går
jämnt upp i 100 %.

= 1.6.0 =
Nytt fältval "Visa i statistiken" för rullistor, val-knappar, radioknappar,
flerval, kryssrutor och dolda fält. Följda fält får ett eget kort under
Formulär → Statistik med fördelningen av svaren. Fritext kan aldrig följas.
Av som standard – ingenting ändras förrän valet slås på.

= 1.5.2 =
Statistiksidan: inskick som bara har ett annonsklick-id (gclid från Google
Ads, fbclid från Facebook/Instagram) men inga UTM-taggar redovisas nu för
sig, i ett nytt kort Annonsklick. UTM-korten förklarar varför de är tomma
när kampanjdatan bara består av klick-id.

= 1.5.1 =
Statistiksidans kod läses bara in i wp-admin, inte vid sidvisningar på
sajten. Ingen ändring i funktion.

= 1.5.0 =
Ny sida Formulär → Statistik: inskick över tid, kanaler (sök, social, annonser,
e-post, AI-assistenter m.fl.), topplistor för kampanjparametrar, hänvisande
webbplatser, landningssidor och sidorna formulären skickades från, samt enhet,
webbläsare och veckodag × klockslag. Räknar på sparade inskick och visar inga
personuppgifter. Ingenting ändras i formulären, mailen eller den data som sparas.

= 1.4.0 =
Headless-stöd: nytt publikt endpoint GET /wp-json/relativt-form/v1/form/<id>
med formulärdefinitionen (fält, texter, meddelanden), så att en frontend kan
rendera formuläret själv och skicka till det befintliga REST-API:et.
Mottagare, avsändare och nycklar följer aldrig med. Nytt valfritt spamskydd:
Cloudflare Turnstile per formulär, nycklar under Standardvärden eller i
wp-config.php. Avstängt som standard – ingenting ändras för sajter som inte
slår på det.

= 1.3.0 =
Nytt fältval: "Visa etikett". Ikryssad som standard; kryssas den ur döljs
etiketten visuellt på sajten men ligger kvar för skärmläsare, så fältet
behåller sitt tillgängliga namn. Befintliga formulär påverkas inte –
etiketten visas som förut tills valet stängs av uttryckligen.

= 1.2.0 =
Ny fälttyp: URL. Valideras och normaliseras (https:// läggs på om schemat
saknas) på samma sätt som e-post och telefon, och renderas som en klickbar
länk i mailet som skickas vid inskick i stället för ren text.

= 1.1.3 =
Rättat: fälttypen Dolt fält skickades aldrig med i inskicket, så värden
satta via shortcode-attribut nådde varken mail, ämnesrad eller inskick.

= 1.1.2 =
Standard-CSS:en städad: ingen !important i utseende-regler, ingen påtvingad
typografi (versaler/letter-spacing), skicka-knappen innehållsbred på desktop
och full bredd först under 768px, kryssrutor centrerade. OBS: syns på sajter
som förlitat sig på de gamla standardvärdena.

= 1.1.1 =
Tack-sida per formulär: omdirigering vid lyckat inskick, gjord som mål
för konverteringsspårning.

= 1.1.0 =
Kampanjkakan respekterar Relativt Cookie Consent, tidsspärren gör om inskicket
tyst i stället för att svälja det, länkspärr mot spam, hjälptext per fält,
varning i wp-admin när mail inte kunnat skickas, CSV-exporten skyddad mot
formelinjektion, tillgänglighetsförbättringar och rättad uppdaterings-URL.

= 1.0.0 =
Första releasen som fristående plugin.
