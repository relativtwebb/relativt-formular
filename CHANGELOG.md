# Ändringslogg

Formatet följer [Keep a Changelog](https://keepachangelog.com/sv/1.1.0/).
Versionerna följer [semantisk versionshantering](https://semver.org/lang/sv/).

## [1.0.0] – 2026-08-27

Första releasen.

### Ingår
- Formulärbyggare i wp-admin byggd på ACF Pro. Varje formulär får en egen
  shortcode, och shortcode-attribut kan förvälja värden per sida.
- Villkorliga fält som utvärderas på servern, så att formuläret ser likadant
  ut med och utan JavaScript.
- Mottagarregler som träffar på både etikett och tekniskt värde.
- Validering av e-post och telefon, spegelvänd mellan PHP och JavaScript.
- Spamskydd utan CAPTCHA: honungsfälla, HMAC-signerad tidsstämpel med minsta
  tid, och frekvensspärr per IP.
- UTM-attribution via förstapartskaka.
- Inskickslagring med konfigurerbar gallring och CSV-export.
- Export och import av formulärdefinitioner som JSON, med vitlistad import.
- Inställningssida för globala standardvärden som nya formulär ärver.
- Uppdateringar från publikt GitHub-repo, direkt i wp-admin.
- Filter för att skjuta in temats egna knappklasser och byta eller ta bort
  knappikonen.
- Admin-notis när ACF Pro saknas, i stället för att ingenting händer.
- `uninstall.php` som medvetet inte raderar data utan att bli tillsagd.

### Tester
- 146 serverassertions och 64 webbläsartester, alla gröna.
