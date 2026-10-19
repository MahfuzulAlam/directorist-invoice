<?php
/**
 * Build a compact, installable WordPress plugin ZIP.
 *
 * Usage: php scripts/build-release.php [output.zip]
 *
 * @package Directorist_Invoice
 */

declare( strict_types=1 );

$plugin_dir = dirname( __DIR__ );
$main_file  = $plugin_dir . '/directorist-invoice.php';
$contents   = file_get_contents( $main_file );

if ( false === $contents || ! preg_match( '/^\s*\* Version:\s*([0-9.]+)/m', $contents, $matches ) ) {
	fwrite( STDERR, "Could not read the plugin version.\n" );
	exit( 1 );
}

if ( ! class_exists( ZipArchive::class ) ) {
	fwrite( STDERR, "PHP's Zip extension is required to build the release.\n" );
	exit( 1 );
}

$version    = $matches[1];
$output     = $argv[1] ?? $plugin_dir . '/dist/directorist-invoice-' . $version . '.zip';
$output_dir = dirname( $output );

if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0755, true ) && ! is_dir( $output_dir ) ) {
	fwrite( STDERR, "Could not create the output directory.\n" );
	exit( 1 );
}

$include = array( 'assets', 'includes', 'templates', 'vendor' );
$files   = array(
	$plugin_dir . '/directorist-invoice.php',
	$plugin_dir . '/README.md',
	$plugin_dir . '/DOCUMENTATION.md',
	$plugin_dir . '/composer.json',
	$plugin_dir . '/composer.lock',
);

foreach ( $include as $directory ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $plugin_dir . '/' . $directory, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $entry ) {
		if ( $entry->isFile() && ! $entry->isLink() ) {
			$files[] = $entry->getPathname();
		}
	}
}

sort( $files, SORT_STRING );
$zip = new ZipArchive();

if ( true !== $zip->open( $output, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Could not open the release ZIP.\n" );
	exit( 1 );
}

$font_map = 'vendor/dompdf/dompdf/lib/fonts/installed-fonts.dist.json';
$count    = 0;

foreach ( $files as $file ) {
	$relative = substr( $file, strlen( $plugin_dir ) + 1 );
	$relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );

	// Invoices use DejaVu Sans. Keep its four styles for Unicode text.
	if ( 0 === strpos( $relative, 'vendor/dompdf/dompdf/lib/fonts/DejaVuSansMono' )
		|| 0 === strpos( $relative, 'vendor/dompdf/dompdf/lib/fonts/DejaVuSerif' ) ) {
		continue;
	}

	// Vendor documentation is not needed at runtime. Preserve all licenses.
	if ( 0 === strpos( $relative, 'vendor/' )
		&& ( in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), array( 'md', 'yml', 'yaml' ), true )
			|| in_array( basename( $relative ), array( 'phpunit.xml', 'phpunit.xml.dist', '.gitignore' ), true ) ) ) {
		continue;
	}

	$archive_path = 'directorist-invoice/' . $relative;

	if ( $font_map === $relative ) {
		$fonts = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $fonts ) || ! isset( $fonts['dejavu sans'] ) ) {
			$zip->close();
			unlink( $output );
			fwrite( STDERR, "Unexpected Dompdf font map.\n" );
			exit( 1 );
		}

		unset( $fonts['dejavu sans mono'], $fonts['dejavu serif'] );
		$added = $zip->addFromString( $archive_path, (string) json_encode( $fonts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	} else {
		$added = $zip->addFile( $file, $archive_path );
	}

	if ( ! $added ) {
		$zip->close();
		unlink( $output );
		fwrite( STDERR, "Could not add {$relative} to the release ZIP.\n" );
		exit( 1 );
	}

	++$count;
}

if ( ! $zip->close() ) {
	fwrite( STDERR, "Could not finish the release ZIP.\n" );
	exit( 1 );
}

$bytes = filesize( $output );
if ( false === $bytes || $bytes >= 5 * 1000 * 1000 ) {
	unlink( $output );
	fwrite( STDERR, "Release ZIP exceeds the 5 MB limit.\n" );
	exit( 1 );
}

echo sprintf( "%s: %d files, %d bytes (%.2f MB)\n", $output, $count, $bytes, $bytes / 1000000 );
