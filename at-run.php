
<?php
require_once( 'config.php' );
require_once( 'config2.php' );
require_once( 'functions.php' );
$prevpower = "";
if( count( $argv ) != 3 ) {
    exit( 1 );
}
runGpio( "write", $argv[1], $argv[2] );
