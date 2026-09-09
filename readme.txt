=== Relativt Formulär ===
Contributors: relativt
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Formulärmotor för WordPress. Bygg formulär i wp-admin, varje formulär får en egen shortcode.

== Description ==

Formulärbyggare med villkorliga fält som utvärderas på servern, mottagarregler,
validering av e-post, telefon och URL, spamskydd utan CAPTCHA, UTM-attribution,
inskickslagring med gallring samt export och import av formulär mellan sajter.

Kräver Advanced Custom Fields Pro.

Fullständig dokumentation: https://github.com/relativtwebb/relativt-formular

== Changelog ==

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
