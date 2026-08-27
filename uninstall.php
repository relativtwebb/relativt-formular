<?php
/**
 * Avinstallation.
 *
 * Raderar INGENTING som standard. Formulär och inskick är kundens data, och
 * ett plugin som tömmer databasen för att någon råkade klicka fel är inte ett
 * plugin man vågar installera.
 *
 * Vill man verkligen städa bort allt sätter man optionen medvetet innan
 * avinstallationen:
 *
 *     update_option( 'relativt_form_delete_data', 1 );
 *
 * @package Relativt_Formular
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'relativt_form_delete_data' ) ) {
	return;
}

foreach ( [ 'relativt_form', 'relativt_entry' ] as $post_type ) {
	$posts = get_posts(
		[
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);

	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}

delete_option( 'relativt_form_defaults' );
delete_option( 'relativt_form_delete_data' );
wp_clear_scheduled_hook( 'relativt_form_cleanup' );
