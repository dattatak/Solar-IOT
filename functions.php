<?php
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

 global $powerReserve;

    $source = "/home/pi/sbfspot.log";   // get the current solar power available
    $handle = fopen($source, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if (substr($line,1,5) == 'Total') {
                       $powerNow = substr($line,15,-3);     // strip out the bits we don't want
            }
        }
    }   else {
        // error opening the file.
    }
    fclose($handle);
    unlink($source);
    return $powerNow;
    }

function checkPowerTargets($currentpower) {

    global $devices;

    require( 'config.php');
    $currentpower -= $powerReserve;     // first deduct any power reserve (for fridges, computer equip  etc)
    $prioritylog = array();
    $devicelog = array();               // reset everything
    $totp = 0;
    $totd = 0;
    $devicepower = 0;
    $prioritypower = 0;
    foreach( $devices as $deviceName => $devicePin ) {      // first calculate power wattage left
        $status = NULL;
        $out = NULL;
        $pin = $devicePin[0];
        exec( "/usr/local/bin/gpio read $pin", $out, $status );     // check whether device active
        if ($out[0]) {
          if(!$devicePin[1]) {            // ie NOT a priority device
                $devicepower += $devicePin[3];          // build devices ON list
                $devicelog[$totd][0] = $devicePin[0];   // Device Wiring Pin number
                $devicelog[$totd][1] = $devicePin[3];   // Power Requirement
                ++$totd;
            }
        }
        if($devicePin[1]) {                             // build priority 1 & 2 devices list
            $prioritypower += $devicePin[3];
            $prioritylog[$totp][0] = $devicePin[3];   // Power requirement
            $prioritylog[$totp][1] = $devicePin[1];   // Priority setting
            $prioritylog[$totp][2] = $devicePin[0];   // Pin number
            ++$totp;
        }
    }

// Priority 1 is any bonus dev such as heating or cooling - Priority 2 is a dev that has a timer function as well - such as water heater - the water heater is ..
// a special case as it will also have a feedback indicating it's thermo state - i.e. hot enough, thermostat OFF
// 1 record ALL current devices that do NOT have priority and are switched ON - record any prioriy ON - if ANY priority set > NO > RETURN
// 2 is there surplus power > NO > check if power tolerance is within param set (-10%?) NO > switch OFF priority dev and update records > RETURN
// 4 Check is any priority fits surplus power pick the BIGGEST power need first check if more can fit AND SWITCH ON - record which ones are on > NO > RETURN
// 5 IF priority 2 dev hot water check thermostat feedback if hot enough if so DO NOT switch on
// 5 cross check old prioritys with new prioritys and switch OFF any mismatch *** care with priority 2  hot water??- copy new array to old array?

    if(!$prioritylog[0][0]) return;    // no priority set
    rsort($prioritylog);        // reorder prioitys with highest power first and priority as last choice
    $currentpower -= $devicepower;
    if( $currentpower <= 0 ) {          // calculate power suplus if any
        $y = count($prioritylog);
        if ($y) {
            for($x = 0; $x < $y; ++$x) {        // scan through prioritys
                $pin =  $prioritylog[$x][2];
                exec( "/usr/local/bin/gpio write $pin 0");       // switch OFF all priority devices - no more power
            }
        }
    return;
    }
    $y = count($prioritylog);
//var_dump($prioritylog);
    if ($y) {
        for($x = 0; $x < $y; ++$x) {        // scan through prioritys ( yes I DO know how to spell priorities)
            $pin =  $prioritylog[$x][2];
            if( $prioritylog[$x][1] == 1) {
                if($currentpower - $prioritylog[$x][0] > 0) {
                    $currentpower -= $prioritylog[$x][0];
                    exec( "/usr/local/bin/gpio write $pin 1");       // switch on device
                    $devicelog[count($devicelog)][0] = $prioritylog[$x][2];     // update $device list to include required priority 1 devices
                } else {
                    exec( "/usr/local/bin/gpio write $pin 0");      // make sure device is switched OFF
                }
            }
        }
        for($x = 0; $x < $y; ++$x) {        // scan through prioritys ( yes I DO know how to spell priorities)
            $pin =  $prioritylog[$x][2];
            if( $prioritylog[$x][1] == 2) {
                if($currentpower - $prioritylog[$x][0] > 0) {
                    $currentpower -= $prioritylog[$x][0];
                    exec( "/usr/local/bin/gpio write $pin 1");       // switch on device
                    $devicelog[count($devicelog)][0] = $prioritylog[$x][2];     // update $device list to include required priority 2 devices
                } else {
                    exec( "/usr/local/bin/gpio write $pin 0");      // make sure device is switched OFF
                }
            }
        }
    }
    foreach( $devices as $deviceName => $devicePin ) {
        $deviceOn = 0;
        $y = count($devicelog);
        if ($y) {
            for($x = 0; $x < $y; ++$x) {                // now scan for devices that are not required
                if($devicePin[0] == $devicelog[$x][0]) $deviceOn = 1;       // check if in device list
            }
        }
        if(!$deviceOn) exec( "/usr/local/bin/gpio write $devicePin[0] 0");      // switch OFF device
    }
return;
}


function checkSchedules(array $nextSchedule ) {

    global $schedules;

// this function can be called in 3 ways - from - a manual entry to a schedule, cron-run when timer-On or timer-Off,
// or from zero hour generated by the auto refresh function on the home page but the timer must be on the home page,
// the scheduler relies on these 3 methods to bump the schedules along although the midnight rollover is an added  bonus.

    require( 'config2.php' );
    $timeNow = (date("H")*60) + date("i");
    $lastSched = 1;
        foreach( $schedules as $scheduleNums => $scheduleKey ) {        // nextSchedule holds the current crontab
            foreach( $scheduleKey as $deviceNames => $devicePins ) {
                $runOn = $devicePins[2];                  // the runOn bit is set to the schedule to indicate Run Regularly
                if( isset( $nextSchedule[$deviceNames]['timeOn'] )&& $runOn ) {  // check if crontab exists for this device
                    $schedTime=($devicePins[3]*60) + $devicePins[4];                    // get schedule times in minutes
                    $nextSched=($nextSchedule[$deviceNames]['timeOn']['hour'] * 60) +
                      $nextSchedule[$deviceNames]['timeOn']['min'];
                    if( $schedTime >= $timeNow || $nextSched >= $timeNow )
                        $lastSched = 0;                 // still some schedules left
                    if( ( $schedTime >= $timeNow ) && (($schedTime <= $nextSched ) || ($nextSched < $timeNow ) )) {
                        if(!runGpio( "read", $devicePins[0] )) {   // status check is slow so put here - is device running?
                            $nextSchedule[$deviceNames]['timeOn']['hour'] = strval($devicePins[3]);
                            $nextSchedule[$deviceNames]['timeOn']['min'] = strval($devicePins[4]);
                            $nextSchedule[$deviceNames]['duration']['hour'] = $devicePins[5];
                            $nextSchedule[$deviceNames]['duration']['min'] = $devicePins[6];
                        }
                    }
                }
            }
        if( $lastSched ) {       // no schedules left so bump all schedules for the next day
            $timeNow = 0;
            foreach( $schedules as $scheduleNums => $scheduleKey ) {
                foreach( $scheduleKey as $deviceNames => $devicePins ) {
                $runOn = $devicePins[2];                  // the runOn bit is set to the schedule to indicate Run Regularly
                if( isset( $nextSchedule[$deviceNames]['timeOn'] )&& $runOn ) {  // check is crontab is set for this device
                    $schedTime=($devicePins[3]*60) + $devicePins[4];                    // get schedule times in minutes
                    $nextSched=($nextSchedule[$deviceNames]['timeOn']['hour'] * 60) +
                      $nextSchedule[$deviceNames]['timeOn']['min'];
                    if( ( $schedTime > $timeNow ) && ( ($schedTime < $nextSched ) || ($nextSched < $timeNow ) )) {
                        if(!runGpio( "read", $devicePins[0] )) {   // status check is slow so put here
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
 }
return $nextSchedule;
}


function runGpio( $cmd, $pin, $args = '' ) {
    global $devices, $schedules, $schedule;

    $run_today = True;

    foreach( $devices as $deviceName => $devicePin ) {
	    if( $devicePin[0] == $pin) {
            $dateNow = (date("H")*60) + date("i");   // pre-checks for DOW, suspend and weather
            for( $sch = 1;  $sch <= 4; $sch++ ) {
                $schedTime = ($schedules["Schedule-".$sch][$deviceName][3] * 60) +
                  $schedules["Schedule-".$sch][$deviceName][4];                     // calculate & find target schedule day
                if( $schedTime == $dateNow) {
                    $offset = ($sch - 1) * 10;      // index into DOW array
                    break;
                }
            }
        if ($devicePin[5+$offset]) {        // cloud set?
            exec( "/usr/local/bin/gpio mode 0 input");
		    $in = exec( "/usr/local/bin/gpio read 0");
        } else {
            $in = 0;
        }

        if( $cmd == "cron-write") {
			if ( !$devicePin[date("N")+5+$offset] || $devicePin[4+$offset] || $in ) {
				$run_today = False;             // suspend or day of week suspend using offset into dow array + todays day
            }
		}
		if( $cmd == "cron-write") {
			$cmd = "write";
		}
    }
  }

    if($run_today) {
	    if( $cmd == 'write' ) {
		    logEvent( $pin, $args );
	    }

// If we are turning on an exclusive/exclude pin, lets go through and turn off all the other
// exclusive pins before we turn this one on.

	    if ($cmd == "write" && $args == 1) {
		    foreach( $devices as $deviceName => $devicePin ) {
			    if( $devicePin[0] == $pin && $devicePin[1] == 1 ) {
				    foreach( $devices as $deviceName1 => $devicePin1 ) {
					    if ($devicePin1[1] == 1 && $devicePin1[0] != $pin) {
						    runGpio( "write", $devicePin1[0], 0 );
					    }
				    }
			    }
		    }
	    }

	    exec( "/usr/local/bin/gpio mode $pin out", $out, $status );
	    $status = NULL;
	    $out    = NULL;
	    exec( "/usr/local/bin/gpio $cmd $pin $args", $out, $status );
	    if( $status ) {
		    print( "<p class='error'>Failed to execute /usr/local/bin/gpio $cmd $pin $args: Status $status</p>\n" );
	    }
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
