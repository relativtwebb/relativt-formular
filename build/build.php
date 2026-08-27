<?php
/**
 * Byggskript.
 *
 *   php build/build.php   → dist/relativt-formular-X.Y.Z.zip
 *
 * Zip-filen är den som laddas upp som release-tillgång på GitHub. Att den
 * heter rätt spelar roll: WordPress packar upp arkivet till mappnamnet det
 * bär, och en zipball från GitHub heter "repo-1.0.1" – den installeras då som
 * ett separat plugin bredvid det befintliga i stället för att uppdatera det.
 *
 * @package Relativt_Formular
 */

const ROOT = __DIR__ . '/..';
const DIST = __DIR__ . '/../dist';

/** Bara det som körs på en kundsajt. Tester och byggskript stannar i repot. */
const SHIP = [
	'relativt-formular.php',
	'uninstall.php',
	'readme.txt',
	'README.md',
	'CHANGELOG.md',
	'includes/class-relativt-form.php',
	'includes/class-relativt-form-portability.php',
	'includes/class-relativt-form-settings.php',
	'includes/class-relativt-form-updater.php',
	'assets/css/relativt-formular.css',
	'assets/js/relativt-formular.js',
];

function version(): string {
	$main = (string) file_get_contents( ROOT . '/relativt-formular.php' );
	preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', $main, $m );

	return $m[1] ?? '0.0.0';
}

$version = version();
$slug    = 'relativt-formular';
$target  = DIST . "/{$slug}-{$version}.zip";

if ( ! is_dir( DIST ) ) {
	mkdir( DIST, 0775, true );
}

if ( file_exists( $target ) ) {
	unlink( $target );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Kunde inte skapa {$target}\n" );
	exit( 1 );
}

foreach ( SHIP as $file ) {
	$path = ROOT . '/' . $file;

	if ( ! file_exists( $path ) ) {
		fwrite( STDERR, "Saknas: {$file}\n" );
		exit( 1 );
	}

	/*
	 * Syntaxkontroll på vägen in. En trasig PHP-fil ska fastna här, inte på
	 * en kundsajt efter en automatisk uppdatering.
	 */
	if ( str_ends_with( $file, '.php' ) ) {
		exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $out, $status );

		if ( 0 !== $status ) {
			fwrite( STDERR, implode( "\n", $out ) . "\n" );
			exit( 1 );
		}
	}

	$zip->addFile( $path, $slug . '/' . $file );
}

$zip->close();

printf( "%s  (%d filer, %d kB)\n", $target, count( SHIP ), (int) ( filesize( $target ) / 1024 ) );
