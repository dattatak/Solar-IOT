
<?php

function startPhp() {

    require('config.php');
    foreach( $devices as $deviceName => $devicePin ) {
        exec( "/usr/local/bin/gpio mode $devicePin[0] out"); // set up gpio pins
        usleep(200000);
    }
}

function wifiCheck($pin,$onoff) {

    global  $wifi1, $wifi2, $wifi3, $wifi4;

   require ('config.php');
    if ( $pin == $wifi1[0]) {   // is it a  wifi appliance
        $json_string = file_get_contents($wifi1[1]."2/0");   // .$onoff);
        $start = microtime_float();
        while (microtime_float() <= $start + 0.75) {}     // delay added to ensure wireless switching is sequential

    } elseif ( $pin == $wifi2[0]) {   // is it a wifi appliance
        $json_string = file_get_contents($wifi2[1]."2/0); // ".$onoff);
        $start = microtime_float();
        while (microtime_float() <= $start + 0.75) {}     // delay added to ensure wireless switching is sequential

    } elseif ( $pin == $wifi3[0]) {   // is it a wifi appliance
        $json_string = file_get_contents($wifi3[1].$onoff."/");
        $start = microtime_float();
        while (microtime_float() <= $start + 0.75) {}     // delay added to ensure wireless switching is sequential

    } elseif ( $pin == $wifi4[0]) {   // is it a wifi appliance
        $json_string = file_get_contents($wifi4[1].$onoff."/");
        $start = microtime_float();
        while (microtime_float() <= $start + 0.75) {}     // delay added to ensure wireless switching is sequential
    }
return;
}

function microtime_float() {

    list($usec, $sec) = explode(" ", microtime());
    return ((float) $usec + (float) $sec);
}

function setserial($speed) {

    global $serial;

    if(!isset($_SESSION['first_run'])){ // run once to set up serial port
        $_SESSION['first_run'] = 1;

        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        include 'php_serial.class.php';
        $prevpower = "";
        $serial = new PhpSerial;    // set the class

        $serial->deviceClose();
        $serial->deviceSet("/dev/ttyAMA0");
        $serial->confBaudRate($speed);
        $serial->confParity("none");
        $serial->confCharacterLength(8);
        $serial->confStopBits(1);
        $serial->confFlowControl("none");
  }
    return;
}

function getserial($rxchars) {
/*
global $serial;
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    include ('PhpSerial.php');
//    require('PhpSerial.php');
//    $serial = new PhpSerial;
    $serial->deviceOpen();
    $read = '';
    $theResult = '';
    //$start = microtime_float();
    // sleep(3); //delay
    // $serial->sendMessage("Hello There");
    $loops = 128;
    while ( $rxchars > 1 || $loops > 1) {    //)read == '') && (microtime_float() <= $start + 1.1) ) {
        $read = $serial->readPort();
        if ($read != '') {
            $theResult .= $read;
            $read = '';
        }
        $loops--;
        $rxchars--;
    }
    $serial->deviceClose();
    $theResult="";
    return $theResult;*/
}

function saveresult() {

global $prevpower;

 $GLOBALS['prevpower'] = $GLOBALS['theResult'];
    return $prevpower;
}

function logEvent( $pin, $event ) {
    global $logFile;

    if( isset( $logFile )) {
        $fh = @fopen( $logFile, "a" );
        if( isset( $fh )) {
            fprintf( $fh, "%s\t%s\t%s\n", strftime( "%Y-%m-%d %H:%M:%S" ), $pin, $event );
            fclose( $fh );
        }
    }
}

function arrayCopy( array $array ) {
        $result = array();
        foreach( $array as $key => $val ) {         // used in testing - makes a copy of an existing array
            if( is_array( $val ) ) {
                $result[$key] = arrayCopy( $val );
            } elseif ( is_object( $val ) ) {
                $result[$key] = clone $val;
            } else {
                $result[$key] = $val;
            }
        }
        return $result;
}

function getSmaPower() {

 global $powerNow;

    $source = "/home/pi/sbfspot.log";   // get the current solar power available
    $handle = fopen($source, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if (substr($line,1,5) == 'Total') {
                $powerNow = substr($line,15,-3);     // strip out the bits we don't want
                break;
            }
        }
    }   else {
      // error opening the file.
    }
    fclose($handle);

    if (!$powerNow) {        // try one more time
        $handle = fopen($source, 'r');
        if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if (substr($line,1,5) == 'Total') {
               $powerNow = substr($line,15,-3);
                break;
                }
            }
        }   else {
            // error opening the file.
        }
        fclose($handle);
    }
 return $powerNow;
}


function checkPowerTargets($currentpower) {

    global $devices,$schedules;

    require( 'config.php');
    require( 'config2.php');
//    $currentpower = strip_tags($currentpower); // strip out HTML junk
    static $airdelay1 = 0;
    $a = $currentpower." ";
//    $currentpower += $powerReserve;     // first deduct any power reserve (for fridges, computer equip  etc)
    $b = $currentpower." ";
    $prioritylog = array();
    $devicelog = array();               // reset everything
    $totp = 0;
    $totd = 0;
    $devicepower = 0;
    $timeNow = (date("H")*60) + date("i");      // get the current time in seconds
    $sp = "* * *";
    foreach( $devices as $deviceName => $devicePin ) {      // first calculate power wattage left
        $status = NULL;
        $out = NULL;
        $pin = $devicePin[0];
        exec( "/usr/local/bin/gpio read $pin", $out, $status );     // check whether device active
        if ( $out[0] && !$devicePin[5] ) {

            for( $x = 1; $x <= 4; ++$x) {
                $timenowps = ( ($schedules["Schedule-".$x][$deviceName][3])*60) +
                  $schedules["Schedule-".$x][$deviceName][4];                       //start time of schedule
                $timenowpe = $timenowps + (($schedules["Schedule-".$x][$deviceName][5])*60) +
                  $schedules["Schedule-".$x][$deviceName][6];                       // end time of schedule
                if ( ($timenowps <= $timeNow && $timeNow <= $timenowpe) &&  $devices[$deviceName][5+(date("N"))+
                  (($x-1)*10)] && !$devices[$deviceName][4+(($x-1)*10)] ) {   // within time now, indexed DOW and NOT suspend
                    $devicepower += $devicePin[3+(($x-1)*10)];          // build devices ON list
                    $devicelog[$totd][0] = $devicePin[0];   // Device Wiring Pin number
                  //  $devicelog[$totd][1] = $devicePin[3+(($x-1)*10)];   // Power requirement
                    $devicelog[$totd][1] = $devicePin[3];   // Power requirement

                    ++$totd;
                    break;
                }
            }
        } elseif ( $devicePin[5] ) {    // scan for active time slot and build priority auto list
            for( $x = 1; $x <= 4; ++$x) {
                $timenowps = ( ($schedules["Schedule-".$x][$deviceName][3])*60) +
                  $schedules["Schedule-".$x][$deviceName][4];                       //start time of schedule
                $timenowpe = $timenowps + (($schedules["Schedule-".$x][$deviceName][5])*60) +
                  $schedules["Schedule-".$x][$deviceName][6];                       // end time of schedule
                if ( ($timenowps <= $timeNow && $timeNow <= $timenowpe) &&  $devices[$deviceName][5+(date("N"))+
                  (($x-1)*10)] && !$devices[$deviceName][4+(($x-1)*10)] ) {   // within time now, indexed DOW and suspend
                    $prioritylog[$totp][0] = $devicePin[5];   // Priority setting
                   $prioritylog[$totp][1] = $devicePin[3];  // power requirement
                    //  $prioritylog[$totp][1] = $devicePin[3+(($x-1)*10)];   // Power requirement
                    $prioritylog[$totp][2] = $devicePin[0];   // Pin Number
                    ++$totp;
                    break;
                }
            }
        }
    }

    $y = count($prioritylog);
    if ($y) {
        for($x = 0; $x < $y; ++$x) {        // scan through priorities than are ON and add to currentpower
            $pin =  $prioritylog[$x][2];
            if(exec( "/usr/local/bin/gpio read $pin")) {
                $currentpower += $prioritylog[$x][1];   // power requirement
            }
        }
    }
$c = $currentpower." ";

// ********* nothing switched ON or OFF at this stage ***************************

// Priority 1 is any bonus dev such as heating or cooling - Priority 2 is a 2nd choice device and so on. The HWS heater is ..
// ...a special case as it can also have a feedback indicating it's thermostat status - i.e. hot enough, thermostat OFF
// 1 record ALL current devices that do NOT have priority and are switched ON - record any prioriy ON - if ANY priority set > NO > RETURN
// 2 is there surplus power > NO > check if power tolerance is within param set (-10%?) NO > switch OFF priority dev and update records > RETURN
// 4 Check is any priority fits surplus power pick the BIGGEST power need first check if more can fit AND SWITCH ON - record which ones are on > NO > RETURN
// 5 IF priority 2 dev hot water check thermostat feedback if hot enough if so DO NOT switch on
// 5 cross check old priorities with new priorities and switch OFF any mismatch *** care with priority 2  hot water??- copy new array to old array?

    if(!count($prioritylog)) return (" No Active Priority set");    // no priority set
    sort($prioritylog);        // reorder priorities with highest first
/*    if( $currentpower + $powerReserve <= 0 ) {          // calculate power surplus if any
        $y = count($prioritylog);
        if ($y) {
            for($x = 0; $x < $y; ++$x) {        // scan through priorities
                $pin =  $prioritylog[$x][2];
                if(exec( "/usr/local/bin/gpio read $pin")) {
                    exec( "/usr/local/bin/gpio write $pin 0");       // switch OFF all priority devices - no more power
                    wifiCheck($pin,0);        // pulse the aircon off
                    logEvent( $pin, 0 );
//                    if ($y > 1 && $x <> $y){
//                        $start = microtime_float();
//                        while (microtime_float() <= $start + 0.75) {}     // delay added to ensure wireless switching is sequential
//                    }
//                    if ($y > 1 && $x <> $y) usleep(600000);          // delay added to ensure wireless switching is sequential
                }
            }
        }
    return(" No surplus power available");
    }
*/
    $y = count($prioritylog);
    if ($y) {
        for($x = 0; $x < $y; ++$x) {        // scan  Auto to see iif any appl can be switched on
            $pin =  $prioritylog[$x][2];
            if($currentpower - $prioritylog[$x][1] > 0) {
                $currentpower -= $prioritylog[$x][1];
                if( !exec( "/usr/local/bin/gpio read $pin")) {
                    exec( "/usr/local/bin/gpio write $pin 1");       // switch on device surplus power available
                    wifiCheck($pin,1);
                    logEvent( $pin, 1 );
                }
                $devcount = count($devicelog);
                $devicelog[$devcount][0] = $prioritylog[$x][2];     // pin# update $device list to include this priority  device
                $devicelog[$devcount][1] = $prioritylog[$x][1];     // pwr - priority required to trigger logevent
            } else {
                if($currentpower + $powerReserve - $prioritylog[$x][1] < 0) {   // include hysteresis to switch off
                    if (exec( "/usr/local/bin/gpio read $pin")) {
                        exec( "/usr/local/bin/gpio write $pin 0");       // make sure device is switched OFF
                        wifiCheck($pin,0);        // pulse the aircon off
                        logEvent( $pin, 0 );
                    }
                }
            }
        }
    }
$d = $currentpower." ";
  $y = count($devicelog);
    foreach( $devices as $deviceName => $devicePin ) {
        $deviceOn = 0;
        $pin = $devicePin[0];
        if ($y) {
            for($x = 0; $x < $y; ++$x) {                // now scan for devices that are not required
                if($devicePin[0] == $devicelog[$x][0]) $deviceOn = 1; // check if in device list (priorities is now in devicelog)
            }
        }
        if(!$deviceOn) {
            if( $devicePin[5]) {    // Auto - priority device
                if (exec( "/usr/local/bin/gpio read $pin")) {
                    exec( "/usr/local/bin/gpio write $pin 0");      // switch OFF device
                    wifiCheck($devicePin[0],0);        // pulse the aircon switch
                    logEvent( $devicePin[0],0);
                }
//            }else {
//                if (exec( "/usr/local/bin/gpio read $pin")) {
//                    exec( "/usr/local/bin/gpio write $pin 0");      // switch OFF device
//                    wifiCheck($pin,0);        // pulse the aircon switch
//                    logEvent($pin,0);
//                    if ($y > 1 && $x <> $y){
//                      $start = microtime_float();
//                        while (microtime_float() <= $start + 0.75) {}     // delay added to ensure wireless switching is sequential
//                    }
//                }
            }
        }
    }
$k = " cr".$b."crp".$c."rm".$d.$airdelay1;
return $k;
}

function checkSchedules(array $nextSchedule ) {

    global $schedules,$devices;

// this function can be called in 3 ways - from - a manual entry to a schedule, cron-run when timer-On or timer-Off,
// or from zero hour generated by the auto refresh function on the home page but the timer must be on the home page,
// the scheduler relies on these 3 methods to bump the schedules along although the midnight rollover is an added  bonus.

//ini_set('display_errors', 'On');  // uncomment for error reporting
//error_reporting(E_ALL | E_STRICT);
    require( 'config2.php' );
    require( 'config.php' );
    $timeNow = (date("H")*60) + date("i");
    $lastSched = True;
    $sch = 0;
    $datenow = date("N");
    foreach( $schedules as $scheduleNums => $scheduleKey ) {        // nextSchedule holds the current crontab...
        $offset = $sch * 10;
        $sch++;
        foreach( $scheduleKey as $deviceNames => $devicePins ) {
            $on = false;
            $runOn = $devicePins[2];      // the runOn bit is set to the schedule to indicate Run Regularly
            if($devices[$deviceNames][$datenow+5+$offset] && !$devices[$deviceNames][4+$offset]) $on = true;
            if( isset( $nextSchedule[$deviceNames]['timeOn'] )&& $runOn) {// check crontab exists, DayOfWeek and Suspend
                $schedTime=($devicePins[3]*60) + $devicePins[4];                    // get schedule times in minutes
                $nextSched=($nextSchedule[$deviceNames]['timeOn']['hour'] * 60) +
                  $nextSchedule[$deviceNames]['timeOn']['min']; // schedTime is taken from config file nextSched is from crontab
                if( ($schedTime >= $timeNow && $on) || $nextSched >= $timeNow ||
                    exec( "/usr/local/bin/gpio read $devicePins[0]")) $lastSched = False;  // still some schedules left

                if( ( $schedTime >= $timeNow && $on) && (($schedTime <= $nextSched && $on) || ($nextSched < $timeNow ) )) { // look for best time
                    if(!exec( "/usr/local/bin/gpio read $devicePins[0]")) {    // runGpio( "read", $devicePins[0] )) {   // status check is slow so put here - is device running?
                        $nextSchedule[$deviceNames]['timeOn']['hour'] = strval($devicePins[3]);
                        $nextSchedule[$deviceNames]['timeOn']['min'] = strval($devicePins[4]);
                        $nextSchedule[$deviceNames]['duration']['hour'] = $devicePins[5];
                        $nextSchedule[$deviceNames]['duration']['min'] = $devicePins[6];
                    }
                }
            }
        }
    }
    if( $lastSched ) {       // no schedules left so bump all schedules for the next day
        $sch = 0;
        $timeNow = 0;
        $day = $datenow + 1;    // increment and correct the day
        if ($day == 8) $day = 1;
        foreach( $devices as $deviceName => $devicePin ) {      // add a phantom schedule to ensure proper calculation
            $nextSchedule[$deviceName]['timeOn']['hour'] = "23";
            $nextSchedule[$deviceName]['timeOn']['min'] = "58";
            $nextSchedule[$deviceName]['duration']['hour'] = 0;
            $nextSchedule[$deviceName]['duration']['min'] = 1;
        }

        foreach( $schedules as $scheduleNums => $scheduleKey ) {
            $offset = $sch * 10;
            $sch++;
            foreach( $scheduleKey as $deviceNames => $devicePins ) {
            $on = false;
            $runOn = $devicePins[2];      // the runOn bit is set to the schedule to indicate Run Regularly
            if($devices[$deviceNames][5+$day+$offset] && !$devices[$deviceNames][4+$offset]) $on = true;
                if( isset( $nextSchedule[$deviceNames]['timeOn'] )&& $runOn ) { // check crontab exists, DayOfWeek and Suspend
                    $schedTime=($devicePins[3]*60) + $devicePins[4];                    // get schedule times in minutes
                    $nextSched=($nextSchedule[$deviceNames]['timeOn']['hour'] * 60) +
                      $nextSchedule[$deviceNames]['timeOn']['min'];
                    if( ( $schedTime > $timeNow && $on ) && ( $schedTime < $nextSched && $on) ) {
                        if(! exec( "/usr/local/bin/gpio read $devicePins[0]")) {  //  runGpio( "read", $devicePins[0] )) {   // status check is slow so put here
                            $nextSchedule[$deviceNames]['timeOn']['hour'] = strval($devicePins[3]);
                            $nextSchedule[$deviceNames]['timeOn']['min'] = strval($devicePins[4]);
                            $nextSchedule[$deviceNames]['duration']['hour'] = $devicePins[5];
                            $nextSchedule[$deviceNames]['duration']['min'] = $devicePins[6];
                        }
                    }
                }
            }
        }
    }

return $nextSchedule;
}


function runGpio( $cmd, $pin, $args = '' ) {
    global $devices, $schedules, $schedule;

    require( 'config.php' );
    $run_today = False;
    $offset = 0;
    $breaknow = False;
    $thisdate = date("N");
    if ( $cmd == "write" || $args != 1 ) {
        $run_today = True;
         if ( $cmd == "cron-write") {
            $cmd = "write";
            }
    } else
    {
    $dateNow = (date("H")*60) + date("i");   // get current minute value now
    foreach( $devices as $deviceName => $devicePin ) {
	    if( $devicePin[0] == $pin ) {
            for( $sch = 1;  $sch <= 4; $sch++ ) {
                $schedTime = ($schedules["Schedule-".$sch][$deviceName][3] * 60) +
                  $schedules["Schedule-".$sch][$deviceName][4];                     // calculate & find target schedule day
                if( $schedTime == $dateNow) {
                    $offset = ($sch - 1) * 10;      // index into DOW array
                    $run_today = True;
                    $breaknow = True;
                    break;
                }
            }

//        if ($devicePin[1] && $cloudPin) {        // cloud set?    // not used
//            exec( "/usr/local/bin/gpio mode $cloudPin input");
//		    $in = exec( "/usr/local/bin/gpio read 0");
//        } else {
//            $in = 0;
//        }
        if( $cmd == "cron-write") {
            $cmd = "write";
            if ( !$devicePin[$thisdate+5+$offset] || $devicePin[4+$offset] || $devicePin[5] ) {    // not DOW or Suspend or Auto
                $run_today = False;             // suspend or day of week suspend using offset into dow array + todays day
                $breaknow = False;
            } else {
                  $schedules["Schedule-1"][$deviceName][1] = 1; // set running bit - dont think this is used any more
                  $run_today = True;
                 }
            }
        }
        if ($breaknow) break;
      }
    }
    if($run_today) {
	    if( $cmd == 'write' ) {
            if ( exec( "/usr/local/bin/gpio read $pin" ) xor  $args ) {      // opposites
                wifiCheck($pin,$args);                                            // pulse the aircon switch
    		    logEvent( $pin, $args );
	        }
        }

	    exec( "/usr/local/bin/gpio mode $pin out", $out, $status );
	    $status = NULL;
	    $out    = NULL;
	    exec( "/usr/local/bin/gpio $cmd $pin $args", $out, $status );
	    if( $status ) {
		    print( "<p class='error'>Failed to execute /usr/local/bin/gpio $cmd $pin $args: Status $status</p>\n" );
	    }

/*        if ( $cmd == 'write' && $args == 1 ) {      // set the running bit (used with Auto)
            foreach( $schedules as $scheduleNums => $scheduleKey ) {
                foreach( $scheduleKey as $deviceNames => $devicePins ) {
                    if ($devicePins[0] == $pin) {
                        $devicePins[1] = 1;
                        break;
                    }
                }
            }
        }
*/
	    if( is_array( $out ) && count( $out ) > 0  ) {
		    return $out[0];
	    } else {
		    return NULL;
	    }
    }
}

function issueAt( $deviceName, $minFromNow, $onOff ) {
    global $devices, $schedules;

    $script = $_SERVER['SCRIPT_FILENAME'];
    $script = substr( $script, 0, strrpos( $script, "/" )) . "/at-run.php";

    $devicePin = $devices[$deviceName];

    exec( "echo /usr/bin/php $script $devicePin[0] $onOff | /usr/bin/at 'now + $minFromNow min'" );
}

function readCrontab() {
    global $devices, $schedules;

    exec( "/usr/bin/crontab -l", $out, $status );
    # ignore status; it returns 1 if no crontab has been set yet

    $ret = array();
    foreach( $out as $line ) {
        if( preg_match( '!^(\d+) (\d+) .*/cron-run\.php (\d+) ([01])$!', $line, $matches )) {
            foreach( $devices as $deviceName => $devicePin ) {
                if( $devicePin[0] != $matches[3] ) {
                    continue;
                }
                if( $matches[4] == 1 ) {
                    $ret[$deviceName]['timeOn']['hour'] = $matches[2];
                    $ret[$deviceName]['timeOn']['min']  = $matches[1];
                } else {
                    # we write the on's before the off's, so it's here
                    $ret[$deviceName]['duration']['hour'] = $matches[2] - $ret[$deviceName]['timeOn']['hour'];
                    $ret[$deviceName]['duration']['min']  = $matches[1] - $ret[$deviceName]['timeOn']['min'];
                    while( $ret[$deviceName]['duration']['min'] < 0 ) {
                        $ret[$deviceName]['duration']['min'] += 60;
                        $ret[$deviceName]['duration']['hour']--;
                    }
		    if( $ret[$deviceName]['duration']['hour'] < 0 ) {
		    	$ret[$deviceName]['duration']['hour'] += 24;
		    }
                }
            }
        }
    }
    return $ret;
}

function writeCrontab( $data ) {
    global $devices, $schedules;

    $script = $_SERVER['SCRIPT_FILENAME'];
    $script = substr( $script, 0, strrpos( $script, "/" )) . "/cron-run.php";

    $file = <<<END
# Crontab automatically generated by rasptimer.
# Do not make manual changes, they will be overwritten.
# See https://github.com/jernst/rasptimer

END;

 foreach( $devices as $deviceName => $devicePin ) {
        if( !isset( $data[$deviceName] )) {
            continue;
        }
        $p = $data[$deviceName];
        if( isset( $p['timeOn'] )) {
            if( isset( $p['timeOn']['hour'] )) {
                $hourOn = $p['timeOn']['hour'];
            } else {
                $hourOn = 0;
            }
            $minOn  = $p['timeOn']['min'];
            if( isset( $p['duration'] )) {
                if( isset( $p['duration']['hour'] )) {
                    $hourOff = $hourOn + $p['duration']['hour'];
                } else {
                    $hourOff = $hourOn + 1; # 1hr default
                }
                if( isset( $p['duration']['min'] )) {
                    $minOff = $minOn + $p['duration']['min'];
                } else {
                    $minOff = $minOn;
                }
            }
            while( $minOff > 59 ) {
                $minOff = $minOff - 60;
                $hourOff++;
            }
            $hourOff = $hourOff % 24; # runs daily

            $file .= "$minOn $hourOn * * * /usr/bin/php $script $devicePin[0] 1\n";
            $file .= "$minOff $hourOff * * * /usr/bin/php $script $devicePin[0] 0\n";
        }
    }
    $tmp       = tempnam( '/tmp', 'rasptimer' );
    $tmpHandle = fopen( $tmp, "w" );
    fwrite( $tmpHandle, $file );
    fclose( $tmpHandle );
    exec( "/usr/bin/crontab $tmp" );
    unlink( $tmp );
}

function parseLogLine( $line ) {
    // 2013-01-13 07:00:01     11      1
    if( preg_match( '!^(\d+)-(\d+)-(\d+) (\d+):(\d+):(\d+).*\t([^\t]*)\t([^\t]*)$!', $line, $matches )) {
        return $matches;
    } else {
        return NULL;
    }
}

function printLogFileLines( $url, $page ) {
    global $logFile;
    global $logFilesGlob;
    global $oldLogFilesPattern;

    $logFiles = glob( $logFilesGlob );
    if( count( $logFiles ) > 1  ) {
        print "<ul class=\"log-files\">\n";

        for( $i=0 ; $i<count( $logFiles ) ; ++$i ) {
            if( $logFile == $logFiles[$i] ) {
                $selected = !isset( $page ) ? " class=\"selected\"" : "";
                print "<li$selected><a href=\"$url\">Current</a></li>\n";

            } elseif( preg_match( "#$oldLogFilesPattern#", $logFiles[$i], $matches )) {
                $selected = ( $page == $matches[1] ) ? " class=\"selected\"" : "";
                print "<li$selected><a href=\"$url?page=$matches[1]\">$matches[1]</a></li>\n";
            }
        }
        print "</ul>\n";
    }
}
