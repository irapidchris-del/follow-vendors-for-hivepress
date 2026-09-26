<?php
/**
 * Runs the logic tests once per meaningful combination of companion plugins.
 *
 * The plugin behaves differently depending on which of Action Bar for HivePress, the Favorites
 * extension, the Search Alerts extension and the shared icon library are present, so the same
 * suite is run with each of them absent in turn rather than only in the everything-present case.
 *
 * Usage, from the plugin folder: php tests/run.php
 *
 * @package Follow_Vendors\Tests
 */

$runs = [
	'logic: everything present' => [],
	'logic: no Action Bar'      => [ 'HPFV_BAR' => 'absent' ],
	'logic: no Favorites'       => [ 'HPFV_FAVORITES' => 'absent' ],
	'logic: no Search Alerts'   => [ 'HPFV_ALERTS' => 'absent' ],
	'logic: no icon library'    => [ 'HPFV_FAFH' => 'absent' ],
	'logic: nothing optional'   => [ 'HPFV_BAR' => 'absent', 'HPFV_FAVORITES' => 'absent', 'HPFV_ALERTS' => 'absent', 'HPFV_FAFH' => 'absent' ],
];

$total_passed = 0;
$total_failed = 0;
$failed_runs  = [];

foreach ( $runs as $label => $env ) {

	// Environment is passed through putenv() rather than a `NAME=value cmd` shell prefix: that
	// syntax is bash-only, and on Windows cmd.exe it makes every run die with "'HPFV_BAR' is not
	// recognized as an internal or external command". putenv() is inherited by the child on both.
	foreach ( $env as $key => $value ) {
		putenv( $key . '=' . $value );
	}

	$output = [];
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/logic-tests.php' ) . ' 2>&1', $output, $status );

	foreach ( array_keys( $env ) as $key ) {
		putenv( $key );
	}

	$summary = '';

	foreach ( $output as $line ) {
		if ( 0 === strpos( $line, 'RESULT:' ) ) {
			$summary = $line;
		}
	}

	if ( preg_match( '/(\d+) passed, (\d+) failed/', $summary, $m ) ) {
		$total_passed += (int) $m[1];
		$total_failed += (int) $m[2];
	} else {
		$total_failed++;
		$summary = 'RESULT: no summary line (fatal?)';
	}

	printf( "%-32s %s\n", $label, $summary );

	if ( 0 !== $status ) {
		$failed_runs[] = $label;

		foreach ( $output as $line ) {
			if ( 0 === strpos( $line, '  FAIL' ) || false !== strpos( $line, 'Fatal' ) || false !== strpos( $line, 'Warning' ) ) {
				echo "    {$line}\n";
			}
		}
	}
}

echo "\n----------------------------------------\n";
echo "RESULT: {$total_passed} passed, {$total_failed} failed across " . count( $runs ) . " runs\n";

exit( $failed_runs ? 1 : 0 );
