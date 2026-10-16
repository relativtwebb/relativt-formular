<?php
/**
 * Ikonbibliotek för skicka-knappen.
 *
 * Ett urval av vanliga ikoner ur Lucide (https://lucide.dev, ISC-licens – se
 * includes/LUCIDE-LICENSE.txt), inbyggda som SVG så att pluginet inte hämtar
 * något från tredje part vid sidvisning och fungerar på sajter utan
 * utgående nätverk. Valet sparas per formulär som en nyckel (t.ex. "send"),
 * aldrig som SVG-kod, så en importerad formulärdefinition inte kan smuggla in
 * markup.
 *
 * Två nycklar är inte ur Lucide:
 *  - "standard" är pilen som knappen alltid haft (Material Symbols). Den är
 *    förvalet, så att befintliga formulär ser exakt likadana ut efter
 *    uppdateringen.
 *  - "none" ritar ingen ikon alls.
 *
 * Lägga till en ikon: kopiera elementen ur SVG-filen på lucide.dev, lägg en
 * rad i ICONS nedan. Inget annat behöver ändras – väljaren i byggaren,
 * renderingen och importvitlistan läser alla härifrån.
 *
 * @package Relativt_Formular
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Relativt_Form_Icons', false ) ) :

final class Relativt_Form_Icons {

	public const STANDARD = 'standard';
	public const NONE     = 'none';

	/** Pilen som knappen haft sedan start. Samma byte-för-byte som tidigare. */
	private const STANDARD_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="m531.69-480-184-184L376-692.31 588.31-480 376-267.69 347.69-296l184-184Z"/></svg>';

	/**
	 * nyckel => [ etikett, inre SVG-element ]
	 * Lucide ritas på ett 24 × 24-rutnät med linjer, inte fyllda former.
	 */
	private const ICONS = [
		'arrow-right' => [ 'Pil höger', '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>' ],
		'arrow-up-right' => [ 'Pil snett uppåt', '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>' ],
		'chevron-right' => [ 'Vinkel höger', '<path d="m9 18 6-6-6-6"/>' ],
		'chevrons-right' => [ 'Dubbel vinkel', '<path d="m6 17 5-5-5-5"/><path d="m13 17 5-5-5-5"/>' ],
		'circle-arrow-right' => [ 'Pil i cirkel', '<circle cx="12" cy="12" r="10"/><path d="m12 16 4-4-4-4"/><path d="M8 12h8"/>' ],
		'send' => [ 'Skicka', '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>' ],
		'send-horizontal' => [ 'Skicka, rakt', '<path d="M3.714 3.048a.498.498 0 0 0-.683.627l2.843 7.627a2 2 0 0 1 0 1.396l-2.842 7.627a.498.498 0 0 0 .682.627l18-8.5a.5.5 0 0 0 0-.904z"/><path d="M6 12h16"/>' ],
		'mail' => [ 'Brev', '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/>' ],
		'mail-check' => [ 'Brev med bock', '<path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h8"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/><path d="m16 19 2 2 4-4"/>' ],
		'message-square' => [ 'Pratbubbla', '<path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/>' ],
		'message-circle' => [ 'Rund pratbubbla', '<path d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719"/>' ],
		'check' => [ 'Bock', '<path d="M20 6 9 17l-5-5"/>' ],
		'circle-check' => [ 'Bock i cirkel', '<circle cx="12" cy="12" r="10"/><path d="m16 9-5.5 5.5L8 12"/>' ],
		'clipboard-check' => [ 'Checklista', '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>' ],
		'phone' => [ 'Telefon', '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/>' ],
		'headset' => [ 'Headset', '<path d="M3 11h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5Zm0 0a9 9 0 1 1 18 0m0 0v5a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3Z"/><path d="M21 16v2a4 4 0 0 1-4 4h-5"/>' ],
		'calendar' => [ 'Kalender', '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/>' ],
		'calendar-check' => [ 'Bokad tid', '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="m9 15 2 2 4-4"/>' ],
		'map-pin' => [ 'Kartnål', '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>' ],
		'download' => [ 'Ladda ner', '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>' ],
		'file-down' => [ 'Ladda ner fil', '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/>' ],
		'external-link' => [ 'Extern länk', '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>' ],
		'plus' => [ 'Plus', '<path d="M5 12h14"/><path d="M12 5v14"/>' ],
		'search' => [ 'Sök', '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>' ],
		'user-plus' => [ 'Lägg till person', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>' ],
		'shopping-cart' => [ 'Varukorg', '<path d="m2.05 2.05 1.099-.028a1 1 0 0 1 1.008.815l2.69 14.347A1 1 0 0 0 7.83 18H18"/><path d="M4.563 5h16.435a1 1 0 0 1 .981 1.204l-1.026 6.226A2 2 0 0 1 18.962 14H6.25"/><circle cx="18" cy="20" r="2"/><circle cx="8" cy="20" r="2"/>' ],
		'shopping-bag' => [ 'Shoppingkasse', '<path d="M16 10a4 4 0 0 1-8 0"/><path d="M3.103 6.034h17.794"/><path d="M3.4 5.467a2 2 0 0 0-.4 1.2V20a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6.667a2 2 0 0 0-.4-1.2l-2-2.667A2 2 0 0 0 17 2H7a2 2 0 0 0-1.6.8z"/>' ],
		'package' => [ 'Paket', '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/>' ],
		'handshake' => [ 'Handslag', '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>' ],
		'thumbs-up' => [ 'Tummen upp', '<path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/><path d="M7 10v12"/>' ],
		'heart' => [ 'Hjärta', '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/>' ],
		'star' => [ 'Stjärna', '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>' ],
		'rocket' => [ 'Raket', '<path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09"/><path d="M9 12a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.4 22.4 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 .05 5 .05"/>' ],
		'sparkles' => [ 'Gnistor', '<path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/>' ],
		'lock' => [ 'Hänglås', '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>' ],
	];

	/** Bara för förhandsvisningen av "Ingen ikon" i väljaren – renderas aldrig på sajten. */
	private const PREVIEW = [
		'ban' => '<circle cx="12" cy="12" r="10"/><path d="M4.929 4.929 19.07 19.071"/>',
	];

	/** Giltig nyckel, annars standard. Okänt värde (gammal export, borttagen ikon) ger alltså pilen. */
	public static function normalize( $key ): string {
		if ( ! is_string( $key ) || '' === $key ) {
			return self::STANDARD;
		}

		if ( self::NONE === $key || self::STANDARD === $key || isset( self::ICONS[ $key ] ) ) {
			return $key;
		}

		return self::STANDARD;
	}

	/** Alla nycklar, för vitlistor och tester. */
	public static function keys(): array {
		return array_merge( [ self::STANDARD, self::NONE ], array_keys( self::ICONS ) );
	}

	/** nyckel => etikett, i den ordning de visas i väljaren. */
	public static function choices(): array {
		$choices = [
			self::STANDARD => 'Standard (pil)',
			self::NONE     => 'Ingen ikon',
		];

		foreach ( self::ICONS as $key => $icon ) {
			$choices[ $key ] = $icon[0];
		}

		return $choices;
	}

	/** SVG-koden för en nyckel. Tom sträng för "none" – då ritas ingen ikon. */
	public static function svg( $key ): string {
		$key = self::normalize( $key );

		if ( self::NONE === $key ) {
			return '';
		}

		if ( self::STANDARD === $key ) {
			return self::STANDARD_SVG;
		}

		return self::lucide( self::ICONS[ $key ][1], 'currentColor', 'aria-hidden="true" focusable="false"' );
	}

	private static function lucide( string $inner, string $color, string $extra ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="' . $color
			. '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' . $extra . '>' . $inner . '</svg>';
	}

	/**
	 * CSS som gör ACF:s radioknappar till ett rutnät av ikoner.
	 *
	 * Fältet är en vanlig ACF-radio, så sparning, tangentbord och
	 * skärmläsare sköts av ACF. Varje <input> ritas om till själva ikonen
	 * med en mask – färgen följer då etikettens färg och markeringen blir
	 * en ram runt rutan. Ingen JavaScript.
	 */
	public static function picker_css( string $field_key ): string {
		$f = '.acf-field[data-key="' . $field_key . '"] .acf-radio-list';

		$css  = "{$f} { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px; margin: 0; padding: 0; }\n";
		$css .= "{$f} li { margin: 0 !important; float: none !important; }\n";
		$css .= "{$f} label { display: flex; flex-direction: column; align-items: center; gap: 6px; box-sizing: border-box; height: 100%; padding: 12px 6px 8px; border: 1px solid #c3c4c7; border-radius: 6px; background: #fff; color: #50575e; font-size: 11px; line-height: 1.3; text-align: center; cursor: pointer; }\n";
		$css .= "{$f} label:hover { border-color: #2271b1; }\n";
		$css .= "{$f} label:focus-within { outline: 2px solid #2271b1; outline-offset: 1px; }\n";
		$css .= "{$f} label.selected { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; background: #f0f6fc; color: #2271b1; }\n";
		$css .= "{$f} label:has(input:checked) { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; background: #f0f6fc; color: #2271b1; }\n";
		$css .= "{$f} input[type=\"radio\"] { -webkit-appearance: none; appearance: none; display: block; position: relative; box-sizing: border-box; flex: none; width: 26px; height: 26px; min-width: 26px; margin: 0; padding: 0; border: 0; border-radius: 0; background: transparent; box-shadow: none; outline: 0; color: inherit; }\n";
		$css .= "{$f} input[type=\"radio\"]::before { content: \"\"; display: block; position: absolute; top: 0; right: 0; bottom: 0; left: 0; width: auto; height: auto; margin: 0; border-radius: 0; background: currentColor; line-height: 1; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; -webkit-mask-size: 24px 24px; mask-size: 24px 24px; }\n";

		$masks = [
			self::STANDARD => str_replace( 'fill="currentColor"', 'fill="#000"', self::STANDARD_SVG ),
			self::NONE     => self::lucide( self::PREVIEW['ban'], '#000', '' ),
		];
		foreach ( self::ICONS as $key => $icon ) {
			$masks[ $key ] = self::lucide( $icon[1], '#000', '' );
		}

		foreach ( $masks as $key => $svg ) {
			$url  = 'url("data:image/svg+xml,' . rawurlencode( $svg ) . '")';
			$css .= "{$f} input[value=\"{$key}\"]::before { -webkit-mask-image: {$url}; mask-image: {$url}; }\n";
		}

		return $css;
	}
}

endif;
