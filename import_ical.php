<?php

function ics_import_parse ( $cal_file, $uid = NULL ) {
	return parse_ical($cal_file);
}

// taken from ICS Importer Wordpress Plugin
// Replace RFC 2445 escape characters
function format_ical_text($value) {
  $output = str_replace(
    array('\\\\', '\;', '\,', '\N', '\n'),
    array('\\',   ';',  ',',  "\n", "\n"),
    $value
  );

  return $output;
}

// taken from WebCalendar project
/**
* All of WebCalendar's ical/vcal functions
*
* @author Craig Knudsen <cknudsen@cknudsen.com>
* @copyright Craig Knudsen, <cknudsen@cknudsen.com>, http://www.k5n.us/cknudsen
* @license http://www.gnu.org/licenses/gpl.html GNU GPL
* @version $Id: xcal.php,v 1.79.2.19 2008/09/27 15:00:06 cknudsen Exp $
* @package WebCalendar
*/



// IMPORT FUNCTIONS BELOW HERE
/* Import the data structure
$Entry[CalendarType]       =  VEVENT, VTODO, VTIMEZONE
$Entry[RecordID]           =  Record ID (in the Palm) ** palm desktop only
$Entry[StartTime]          =  In seconds since 1970 (Unix Epoch)
$Entry[EndTime]            =  In seconds since 1970 (Unix Epoch)
$Entry[Summary]            =  Summary of event (string)
$Entry[Duration]           =  How long the event lasts (in minutes)
$Entry[Description]        =  Full Description (string)
$Entry[Untimed]            =  1 = true  0 = false
$Entry[Class]              =  R = PRIVATE,C = CONFIDENTIAL  P = PUBLIC
$Entry[Location]           =  Location of event
$Entry[Priority]           =  1 = Highest 5=Normal 9=Lowest
$Entry[Tranparency]        =  1 = Transparent, 0 = Opaque (Used for Free/Busy)
$Entry[Categories]         =  String containing Categories
$Entry[Due]                =  UTC datetime when VTODO is due
$Entry[Completed]          =  Datetime when VTODO was completed
$Entry[Percent]            =  Percentage of VTODO complete 0-100
$Entry[AlarmSet]           =  1 = true  0 = false
$Entry[Alarm]              =  String containg VALARM TRIGGERS
$Entry[Repeat]             =  Array containing repeat information (if repeat)
$Entry[Repeat][Frequency]  =  1=daily,2=weekly,3=MonthlyByDay,4=MonthlyByDate,
                              5=MonthBySetPos,6=Yearly,7=manual
$Entry[Repeat][Interval]   =  How often event occurs.
$Entry[Repeat][Until]      =  When the repeat ends (Unix Epoch)
$Entry[Repeat][Exceptions] =  Exceptions to the repeat  (Unix Epoch)
$Entry[Repeat][Inclusions] =  Inclusions to the repeat (Unix Epoch)
$Entry[Repeat][ByDay]      =  What days to repeat on
$Entry[Repeat][ByMonthDay] =  Days of month events fall on
$Entry[Repeat][ByMonth]    =  Months that event will occur (12 chars y or n)
$Entry[Repeat][BySetPos]   =  Position in other ByXxxx that events occur
$Entry[Repeat][ByYearDay]  =  Days in year that event occurs
$Entry[Repeat][ByWeekNo]   =  Week that a yearly event repeats
$Entry[Repeat][WkSt]       =  Day that week starts on (default MO)
$Entry[Repeat][Count]      =  Number of occurances, may be used instead of UNTIL
*/


/*Functions from import_ical.php
 * This file incudes functions for parsing iCal data files during
 * an import.
 *
 * It will be included by import_handler.php.
 *
 * The iCal specification is available online at:
 * http://www.ietf.org/rfc/rfc2445.txt
 *
 */
// Parse the ical file and return the data hash.
// NOTE!!!!!
// There seems to be a bug in certain versions of PHP where the fgets ()
// returns a blank string when reading stdin. I found this to be
// a problem with PHP 4.1.2 on Linux.
// It did work correctly with PHP 5.0.2.
function parse_ical ( $cal_file, $source = 'file' ) {
  global $tz, $errormsg;
  $ical_data = array ();
  if ( $source == 'file' || $source == 'remoteics' ) {
    if ( ! $fd = @fopen ( $cal_file, 'r' ) ) {
      $errormsg .= "Can't read temporary file: $cal_file\n";
      exit ();
    } else {
      // Read in contents of entire file first
      $data = '';
      $line = 0;
      while ( ! feof( $fd ) && empty ( $error ) ) {
        $line++;
        $data .= fgets( $fd, 4096 );
      }
      fclose ( $fd );
    }
  } else if ( $source == 'icalclient' ) {
    //do_debug ( "before fopen on stdin..." );
    $stdin = fopen ( 'php://input', 'rb' );
    // $stdin = fopen ("/dev/stdin", "r");
    // $stdin = fopen ("/dev/fd/0", "r");
    //do_debug ( "after fopen on stdin..." );
    // Read in contents of entire file first
    $data = '';
    $cnt = 0;
    while ( ! feof ( $stdin ) ) {
      $line = fgets ( $stdin, 1024 );
      $cnt++;
      // do_debug ( "cnt = " . ( ++$cnt ) );
      $data .= $line;
      if ( $cnt > 10 && strlen ( $data ) == 0 ) {
        // do_debug ( "Read $cnt lines of data, but got no data :-(" );
        // do_debug ( "Informing user of PHP server bug (PHP v" . phpversion () . ")" );
        // Note: Mozilla Calendar does not display this error for some reason.
        echo '<br /><b>Error:</b> Your PHP server ' . phpversion ()
         . ' seems to have a bug reading stdin. '
         . 'Try upgrading to a newer PHP release.<br />';
        exit;
      }
    }
    fclose ( $stdin );
    // do_debug ( "strlen (data)=" . strlen ($data) );
    // Check for PHP stdin bug
    if ( $cnt > 5 && strlen ( $data ) < 10 ) {
       //do_debug ( "Read $cnt lines of data, but got no data :-(" );
       //do_debug ( "Informing user of PHP server bug" );
      header ( 'Content-Type: text/plain' );
      echo 'Error: Your PHP server ' . phpversion ()
       . ' seems to have a bug reading stdin.' . "\n"
       . 'Try upgrading to a newer release.' . "\n";
      exit;
    }
  }
  // Now fix folding. According to RFC, lines can fold by having
  // a CRLF and then a single white space character.
  // We will allow it to be CRLF, CR or LF or any repeated sequence
  // so long as there is a single white space character next.
  // echo "Orig:<br /><pre>$data</pre><br /><br />\n";
  // Special cases for  stupid Sunbird wrapping every line!
  $data = preg_replace ( "/[\r\n]+[\t ];/", ";", $data );
  $data = preg_replace ( "/[\r\n]+[\t ]:/", ":", $data );

  $data = preg_replace ( "/[\r\n]+[\t ]/", " ", $data );
  $data = preg_replace ( "/[\r\n]+/", "\n", $data );
  // echo "Data:<br /><pre>$data</pre><p>";
  // reflect the section where we are in the file:
  // VEVENT, VTODO, VJORNAL, VFREEBUSY, VTIMEZONE
  $state = 'NONE';
  $substate = 'none'; // reflect the sub section
  $subsubstate = ''; // reflect the sub-sub section
  $error = false;
  $line = 0;
  $event = '';
  $lines = explode ( "\n", $data );
  $linecnt = count ( $lines );
  for ( $n = 0; $n < $linecnt && ! $error; $n++ ) {
    $line++;
    if ( $line > 5 && $line < 10 && $state == 'NONE' ) {
      // we are probably not reading an ics file
      return false;
    }
    $buff = trim( $lines[$n] );
 
    if ( preg_match ( "/^PRODID:(.+)$/i", $buff, $match ) ) {
      $prodid = $match[1];
      $prodid = str_replace ( "-//", "", $prodid );
      $prodid = str_replace ( "\,", ",", $prodid );
      $event['prodid'] = $prodid;
      // do_debug ( "Product ID: " . $prodid );
    }
    // parser debugging code...
    // echo "line = $line<br />";
    // echo "state = $state<br />";
    // echo "substate = $substate<br />";
    // echo "subsubstate = $subsubstate<br />";
    // echo "buff = " . htmlspecialchars ( $buff ) . "<br /><br />\n";
    if ( $state == 'VEVENT' || $state == 'VTODO' ) {
      if ( ! empty ( $subsubstate ) ) {
        if ( preg_match ( '/^END.*:(.+)$/i', $buff, $match ) ) {
          if ( $match[1] == $subsubstate )
            $subsubstate = '';

        } else if ( $subsubstate == 'VALARM' ) {
          if ( preg_match ( "/TRIGGER(.+)$/i", $buff, $match ) ) {
            // Example: TRIGGER;VALUE=DATE-TIME:19970317T133000Z
            $substate = 'alarm_trigger';
            $event[$substate] = $match[1];
          } else if ( preg_match ( "/ACTION.*:(.+)$/i", $buff, $match ) ) {
            $substate = 'alarm_action';
            $event[$substate] = $match[1];
          } else if ( preg_match ( "/REPEAT.*:(.+)$/i", $buff, $match ) ) {
            $substate = 'alarm_repeat';
            $event[$substate] = $match[1];
          } else if ( preg_match ( "/DURATION.*:(.+)$/i", $buff, $match ) ) {
            $substate = 'alarm_duration';
            $event[$substate] = $match[1];
          } else if ( preg_match ( "/RELATED.*:(.+)$/i", $buff, $match ) ) {
            $substate = 'alarm_related';
            $event[$substate] = $match[1];
          }
        }
      } else if ( preg_match ( '/^BEGIN.*:(.+)$/i', $buff, $match ) )
        $subsubstate = $match[1];
      //.
      // we suppose ': ' is on the same line as property name,
      // this can perhaps cause problems
      else if ( preg_match ( "/^SUMMARY\s*(;.+)?:(.+)$/iU", $buff, $match ) ) {
        $substate = 'summary';
        if ( stristr( $match[1], 'ENCODING=QUOTED-PRINTABLE' ) )
          $match[2] = quoted_printable_decode( $match[2] );
        $event[$substate] = $match[2];
      } elseif ( preg_match ( "/^DESCRIPTION\s*(;.+)?:(.+)$/iU", $buff, $match ) ) {
        $substate = 'description';
        if ( stristr( $match[1], 'ENCODING=QUOTED-PRINTABLE' ) )
          $match[2] = quoted_printable_decode( $match[2] );
        $event[$substate] = $match[2];
      } elseif ( preg_match ( "/^CLASS.*:(.*)$/i", $buff, $match ) ) {
        $substate = 'class';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( "/^LOCATION.*?:(.+)$/i", $buff, $match ) ) {
        $substate = 'location';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( "/^URL.*?:(.+)$/i", $buff, $match ) ) {
        $substate = 'url';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( "/^TRANSP.*:(.+)$/i", $buff, $match ) ) {
        $substate = 'transparency';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( "/^STATUS.*:(.*)$/i", $buff, $match ) ) {
        $substate = 'status';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( "/^PRIORITY.*:(.*)$/i", $buff, $match ) ) {
        $substate = 'priority';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( "/^DTSTART\s*(.*):\s*(.*)\s*$/i", $buff, $match ) ) {
        $substate = 'dtstart';
        $event[$substate] = $match[2];
        if ( preg_match ( "/TZID=(.*)$/i", $match[1], $submatch ) ) {
          $substate = 'dtstartTzid';
          $event[$substate] = $submatch[1];
        } else if ( preg_match ( "/VALUE=\"{0,1}DATE-TIME\"{0,1}(.*)$/i", $match[1], $submatch ) ) {
          $substate = 'dtstartDATETIME';
          $event[$substate] = true;
        } else if ( preg_match ( "/VALUE=\"{0,1}DATE\"{0,1}(.*)$/i", $match[1], $submatch ) ) {
          $substate = 'dtstartDATE';
          $event[$substate] = true;
        }
      } elseif ( preg_match ( "/^DTEND\s*(.*):\s*(.*)\s*$/i", $buff, $match ) ) {
        $substate = 'dtend';
        $event[$substate] = $match[2];
        if ( preg_match ( "/TZID=(.*)$/i", $match[1], $submatch ) ) {
          $substate = 'dtendTzid';
          $event[$substate] = $submatch[1];
        } else if ( preg_match ( "/VALUE=DATE-TIME(.*)$/i", $match[1], $submatch ) ) {
          $substate = 'dtendDATETIME';
          $event[$substate] = true;
        } else if ( preg_match ( "/VALUE=DATE(.*)$/i", $match[1], $submatch ) ) {
          $substate = 'dtendDATE';
          $event[$substate] = true;
        }
      } elseif ( preg_match ( "/^DUE.*:\s*(.*)\s*$/i", $buff, $match ) ) {
        $substate = 'due';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( "/^COMPLETED.*:\s*(.*)\s*$/i", $buff, $match ) ) {
        $substate = 'completed';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( "/^PERCENT-COMPLETE.*:\s*(.*)\s*$/i", $buff, $match ) ) {
        $substate = 'percent';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( "/^DURATION.*:(.+)\s*$/i", $buff, $match ) ) {
        $substate = 'duration';
        $event[$substate] = parse_ISO8601_duration ( $match[1] );
      } elseif ( preg_match ( '/^RRULE.*:(.+)$/i', $buff, $match ) ) {
        $substate = 'rrule';
        $event[$substate] = $match[1];
      } elseif ( preg_match ( '/^EXDATE.*:(.+)$/i', $buff, $match ) ) {
        $substate = 'exdate';
        // allows multiple ocurrances of EXDATE to be processed
        if ( isset ( $event[$substate] ) )
          $event[$substate] .= ',' . $match[1];
         else
          $event[$substate] = $match[1];

      } elseif ( preg_match ( '/^RDATE.*:(.+)$/i', $buff, $match ) ) {
        $substate = 'rdate';
        // allows multiple ocurrances of RDATE to be processed
        if ( isset ( $event[$substate] ) )
          $event[$substate] .= ',' . $match[1];
         else
          $event[$substate] = $match[1];

      } elseif ( preg_match ( '/^CATEGORIES.*:(.+)$/i', $buff, $match ) ) {
        $substate = 'categories';
        // allows multiple ocurrances of CATEGORIES to be processed
        if ( isset ( $event[$substate] ) )
          $event[$substate] .= ',' . $match[1];
         else
          $event[$substate] = $match[1];

      } elseif ( preg_match ( '/^UID.*:(.+)$/i', $buff, $match ) ) {
        $substate = 'uid';
        $event[$substate] = $match[1];
      } else if ( preg_match ( "/^BEGIN:VALARM/i", $buff ) ) {
        $subsubstate = 'VALARM';
      } elseif ( preg_match ( '/^END:VEVENT$/i', $buff, $match ) ) {
        if ( $tmp_data = format_ical ( $event ) ) $ical_data[] = $tmp_data;
        $state = 'VCALENDAR';
        $substate = 'none';
        $subsubstate = '';
        // clear out data for new event
        $event = '';
      } elseif ( preg_match ( "/^END:VTODO$/i", $buff, $match ) ) {
        if ( $tmp_data = format_ical ( $event ) ) $ical_data[] = $tmp_data;
        $state = 'VCALENDAR';
        $substate = 'none';
        $subsubstate = '';
        // clear out data for new event
        $event = '';
        // folded lines?, this shouldn't happen
      } elseif ( preg_match ( '/^\s(\S.*)$/', $buff, $match ) ) {
        if ( $substate != 'none' ) {
          $event[$substate] .= $match[1];
        } else {
          $errormsg .= "iCal parse error on line $line:<br />$buff\n";
          $error = true;
        }
        // For unsupported properties
      } else {
        $substate = 'none';
      }
    } elseif ( $state == 'VCALENDAR' ) {
      if ( preg_match ( "/^BEGIN:VEVENT/i", $buff ) ) {
        $state = 'VEVENT';
      } elseif ( preg_match ( "/^END:VCALENDAR/i", $buff ) ) {
        $state = 'NONE';
      } else if ( preg_match ( "/^BEGIN:VTIMEZONE/i", $buff ) ) {
        $state = 'VTIMEZONE';
        $event['VTIMEZONE'] = $buff;
      } else if ( preg_match ( "/^BEGIN:VTODO/i", $buff ) ) {
        $state = 'VTODO';
       } else if ( preg_match ( "/^BEGIN:VFREEBUSY/i", $buff ) ) {
         $state = 'VFREEBUSY';
         $freebusycount=0;
         $event['organizer'] = 'unknown_organizer';
      }
      $event['state'] = $state;
    } elseif ( $state == 'VTIMEZONE' ) {
      // We don't do much with timezone info yet...
      if ( preg_match ( '/^TZID.*:(.+)$/i', $buff, $match ) ) {
        $substate = 'tzid';
        $event[$substate] = parse_tzid ( $match[1] );
        $buff = 'TZID:' . $event[$substate];
      }
      if ( preg_match ( '/^X-LIC-LOCATION.*:(.+)$/i', $buff, $match ) ) {
        $substate = 'tzlocation';
        $event[$substate] = $match[1];
      }
      if ( preg_match ( "/^DTSTART.*:(.+)$/i", $buff, $match ) ) {
        $substate = 'dtstart';
        if ( empty ( $event[$substate] ) || $match[1] < $event[$substate] )
          $event[$substate] = $match[1];
      }
      if ( preg_match ( "/^DTEND.*:(.+)$/i", $buff, $match ) ) {
        $substate = 'dtend';
        if ( empty ( $event[$substate] ) || $match[1] < $event[$substate] )
          $event[$substate] = $match[1];
      }
      $event['VTIMEZONE'] .= "\n" . $buff;
      if ( preg_match ( '/^END:VTIMEZONE$/i', $buff ) ) {
        //save_vtimezone ( $event );
        $state = 'VCALENDAR';
      }
     }elseif ( $state == 'VFREEBUSY' ) {
       if ( preg_match ( '/^END:VFREEBUSY$/i', $buff, $match ) ) {
         $state = 'VCALENDAR';
         $substate = 'none';
         $subsubstate = '';
         $event = '';
       } elseif ( preg_match ( '/^ORGANIZER.*:(.+)$/i', $buff, $match ) ) {
         $substate = 'organizer';
         $event[$substate] = $match[1];
       } elseif ( preg_match ( '/^UID.*:(.+)$/i', $buff, $match ) ) {
         $substate = 'uid';
         $event[$substate] = $match[1];
       } elseif ( preg_match ( '/^FREEBUSY\s*(.*):\s*(.*)\/(.*)\s*$/i',
        $buff, $match ) ) {
         $substate = 'freebusy';
         $event['dtstart']=$match[2];
         $event['dtend']  =$match[3];
         if ( empty ($event['uid']) )
          $event['uid']=$freebusycount++.'-' . $event['organizer'];
 #
 # Let's save the FREEBUSY data as an event. While not a perfect solution, it's better
 # than nothing and allows Outlook users to store Free/Busy times in WebCalendar
 #
 # If not provided, UID is auto-generaated in an attempt to use WebCalendar's duplicate
 # prevention feature. There could be left-over events if the number of free/busy
 # entries decreases, but those entries will hopefullly be in the past so it won't matter.
 # Not a great solution, but I suspect it will work well.
 #
         if ( $tmp_data = format_ical ( $event ) ) $ical_data[] = $tmp_data;
         $event['dtstart']='';
         $event['dtend']  ='';
         $event['uid']  ='';
       } else {
         $substate = 'none';
       }
    } elseif ( $state == 'NONE' ) {
      if ( preg_match ( '/^BEGIN:VCALENDAR$/i', $buff ) )
        $state = 'VCALENDAR';
    }
  } // End while
  return $ical_data;
}

// Convert ical format (yyyymmddThhmmssZ) to epoch time
function icaldate_to_timestamp ( $vdate, $tzid = '', $plus_d = '0',
  $plus_m = '0', $plus_y = '0' ) {
  global $SERVER_TIMEZONE, $calUser;
  $this_TIMEZONE = $Z = '';
  // Just in case, trim off leading/trailing whitespace.
  $vdate = trim ( $vdate );

  //$user_TIMEZONE = get_pref_setting ( $calUser, 'TIMEZONE' );
  
  //Get timezone from WordPress settings
  $user_TIMEZONE = get_option('timezone_string');

  $H = $M = $S = 0;
  $y = substr ( $vdate, 0, 4 ) + $plus_y;
  $m = substr ( $vdate, 4, 2 ) + $plus_m;
  $d = substr ( $vdate, 6, 2 ) + $plus_d;
  if ( strlen ( $vdate ) > 8 ) {
    $H = substr ( $vdate, 9, 2 );
    $M = substr ( $vdate, 11, 2 );
    $S = substr ( $vdate, 13, 2 );
    $Z = substr ( $vdate, 15, 1 );
  }
  // if we get a Mozilla TZID we try to parse it
  $tzid = parse_tzid ( $tzid );

  // Sunbird does not do Timezone right so...
  // We'll just hardcode their GMT timezone def here
  switch ( $tzid ) {
    case '/Mozilla.org/BasicTimezones/GMT':
    case 'GMT':
      // I think this is the only real timezone set to UTC...since 1972 at least
      $this_TIMEZONE = 'Africa/Monrovia';
      $Z = 'Z';
      break;
    case 'US-Eastern':
    case 'US/Eastern':
      $this_TIMEZONE = 'America/New_York';
      break;
    case 'US-Central':
    case 'US/Central':
      $this_TIMEZONE = 'America/America/Chicago';
      break;
    case 'US-Pacific':
    case 'US/Pacific':
      $this_TIMEZONE = 'America/Los_Angeles';
      break;
    case '':
      break;
    default:
      $this_TIMEZONE = $tzid;
      break;
  } //end switch
  // Convert time from user's timezone to GMT if datetime value
  if ( empty ( $this_TIMEZONE ) ) {
    $this_TIMEZONE = ( ! empty ( $user_TIMEZONE ) ? $user_TIMEZONE : $SERVER_TIMEZONE );
  }
  if ( empty ( $Z ) ) {
    putenv ( "TZ=$this_TIMEZONE" );
    $TS = mktime ( $H, $M, $S, $m, $d, $y );
  } else {
    $TS = gmmktime ( $H, $M, $S, $m, $d, $y );
  }
  //set_env ( 'TZ', $user_TIMEZONE );
  return $TS;
}
// Put all ical data into import hash structure
function format_ical ( $event ) {
  global $login;

  // Set Product ID
  $fevent['Prodid'] = ( ! empty ( $event['prodid'] ) ? $event['prodid'] : '' );

  // Set Calendar Type for easier processing later
  $fevent['CalendarType'] = $event['state'];

  $fevent['Untimed'] = $fevent['AllDay'] = 0;
  // Categories
  if ( isset ( $event['categories'] ) ) {
    // $fevent['Categories']  will contain an array of cat_id(s) that match the
    // category_names
    //$fevent['Categories'] = get_categories_id_byname ( utf8_decode ( $event['categories'] ) );
  }
  // Start and end time
  /* Snippet from RFC2445
  For cases where a "VEVENT" calendar component specifies a "DTSTART"
   property with a DATE data type but no "DTEND" property, the events
   non-inclusive end is the end of the calendar date specified by the
   "DTSTART" property. For cases where a "VEVENT" calendar component
   specifies a "DTSTART" property with a DATE-TIME data type but no
   "DTEND" property, the event ends on the same calendar date and time
   of day specified by the "DTSTART" property. */

  $dtstartTzid = ( ! empty ( $event['dtstartTzid'] )?$event['dtstartTzid'] : '' );
  $fevent['StartTime'] = icaldate_to_timestamp ( $event['dtstart'], $dtstartTzid );
  if ( isset ( $event['dtend'] ) ) {
    $dtendTzid = ( ! empty ( $event['dtendTzid'] )?$event['dtendTzid'] : '' );
    $fevent['EndTime'] = icaldate_to_timestamp ( $event['dtend'], $dtendTzid );
    if ( $fevent['StartTime'] == $fevent['EndTime'] ) {
      $fevent['Untimed'] = 1;
      $fevent['Duration'] = 0;
    } else {
      $fevent['Duration'] = ( $fevent['EndTime'] - $fevent['StartTime'] ) / 60;
    }
  } else if ( isset ( $event['duration'] ) ) {
    $fevent['EndTime'] = $fevent['StartTime'] + $event['duration'] * 60;
    $fevent['Duration'] = $event['duration'];
  } else if ( isset ( $event['dtstartDATETIME'] ) ) {
    // Untimed
    $fevent['EndTime'] = $fevent['StartTime'];
    $fevent['Untimed'] = 1;
    $fevent['Duration'] = 0;
  }

  if ( isset ( $event['dtstartDATE'] ) && ! isset ( $event['dtendDATE'] ) ) {
    // Untimed
    $fevent['StartTime'] = icaldate_to_timestamp ( $event['dtstart'], 'GMT' );
    $fevent['EndTime'] = $fevent['StartTime'];
    $fevent['Untimed'] = 1;
    $fevent['Duration'] = 0;
    //$fevent['EndTime'] = $fevent['StartTime'] + 86400;
    //$fevent['AllDay'] = 1;
    //$fevent['Duration'] = 1440;
  } else if ( isset ( $event['dtstartDATE'] ) && isset ( $event['dtendDATE'] ) ) {
    $fevent['StartTime'] = icaldate_to_timestamp ( $event['dtstart'], 'GMT' );
    // This is an untimed event
    if ( $event['dtstart']  == $event['dtend'] ) {
      $fevent['EndTime'] = $fevent['StartTime'];
      $fevent['Untimed'] = 1;
      $fevent['Duration'] = 0;
    } else {
      $fevent['EndTime'] = icaldate_to_timestamp ( $event['dtend'], 'GMT' );
      $fevent['Duration'] = ( $fevent['EndTime'] - $fevent['StartTime'] ) / 60;
      if ( $fevent['Duration'] == 1440 ) $fevent['AllDay'] = 1;
    }
  }
  // catch 22
  if ( ! isset ( $fevent['EndTime'] ) ) {
    $fevent['EndTime'] = $fevent['StartTime'];
  }
  if ( ! isset ( $fevent['Duration'] ) ) {
    $fevent['Duration'] = 0;
  }
  if ( empty ( $event['summary'] ) )
    $event['summary'] = translate ( 'Unnamed Event' );
  $fevent['Summary'] = utf8_decode ( $event['summary'] );
  if ( ! empty ( $event['description'] ) ) {
    $fevent['Description'] = utf8_decode ( $event['description'] );
  } else {
    $fevent['Description'] = $fevent['Summary'];
  }

  if ( ! empty ( $event['class'] ) ) {
    // Added  Confidential as new CLASS
    if ( preg_match ( '/private/i', $event['class'] ) ) {
      $fevent['Class'] = 'R';
    } elseif ( preg_match ( '/confidential/i', $event['class'] ) ) {
      $fevent['Class'] = 'C';
    } else {
      $fevent['Class'] = 'P';
    }
  }

  $fevent['UID'] = $event['uid'];
  // Process VALARM stuff
  if ( ! empty ( $event['alarm_trigger'] ) ) {
    $fevent['AlarmSet'] = 1;
    if ( preg_match ( "/VALUE=DATE-TIME:(.*)$/i", $event['alarm_trigger'], $match ) ) {
      $fevent['ADate'] = icaldate_to_timestamp ( $match[1] );
    } else {
      $duration = parse_ISO8601_duration ( $event['alarm_trigger'] );
      $fevent['AOffset'] = abs ( $duration );
      $fevent['ABefore'] = ( $duration < 0 ? 'N':'Y' );
    }

    if ( ! empty ( $event['alarm_action'] ) ) {
      $fevent['AAction'] = $event['alarm_action'];
    }
    if ( ! empty ( $event['alarm_repeat'] ) ) {
      $fevent['ARepeat'] = $event['alarm_repeat'];
    }
    if ( ! empty ( $event['alarm_duration'] ) ) {
      $fevent['ADuration'] = abs ( parse_ISO8601_duration ( $event['alarm_duration'] ) );
    }
    if ( ! empty ( $event['alarm_related'] ) ) {
      $fevent['ARelated'] = ( $event['alarm_related'] == 'END'? 'E':'S' );
    }
  }

  if ( ! empty ( $event['status'] ) ) {
    switch ( $event['status'] ) {
      case 'TENTATIVE':
        // case 'NEEDS-ACTION': Sunbird sets this if you touch task without
        // changing anything else. Not sure about other clients yet
        $fevent['Status'] = 'W';
        break;
      case 'CONFIRMED':
      case 'ACCEPTED':
        $fevent['Status'] = 'A';
        break;
      case 'CANCELLED';
        $fevent['Status'] = 'D';
        break;
      case 'DECLINED':
        $fevent['Status'] = 'R';
        break;
      case 'COMPLETED':
        $fevent['Status'] = 'C';
        break;
      case 'IN-PROGRESS':
        $fevent['Status'] = 'P';
        break;
      default:
        $fevent['Status'] = 'A';
        break;
    } //end switch
  } else {
    $fevent['Status'] = 'A';
  }

  if ( ! empty ( $event['location'] ) ) {
    $fevent['Location'] = utf8_decode ( $event['location'] );
  }

  if ( ! empty ( $event['url'] ) ) {
    $fevent['URL'] = utf8_decode ( $event['url'] );
  }

  if ( ! empty ( $event['priority'] ) ) {
    $fevent['PRIORITY'] = $event['priority'];
  }

  if ( ! empty ( $event['transparency'] ) ) {
    if ( preg_match ( '/TRANSPARENT/i', $event['transparency'] )
        OR $event['transparency'] == 1 ) {
      $fevent['Transparency'] = 1;
    } else {
      $fevent['Transparency'] = 0;
    }
  } else {
    $fevent['Transparency'] = 0;
  }
  // VTODO specific items
  if ( ! empty ( $event['due'] ) ) {
    $fevent['Due'] = $event['due'];
  }

  if ( ! empty ( $event['completed'] ) ) {
    $fevent['Completed'] = $event['completed'];
  }

  if ( ! empty ( $event['percent'] ) ) {
    $fevent['Percent'] = $event['percent'];
  }
  // Repeating exceptions
  $fevent['Repeat']['Exceptions'] = array ();
  if ( ! empty ( $event['exdate'] ) && $event['exdate'] ) {
    $EX = explode ( ',', $event['exdate'] );
    foreach ( $EX as $exdate ) {
      $fevent['Repeat']['Exceptions'][] = icaldate_to_timestamp ( $exdate );
    }
    $fevent['Repeat']['Frequency'] = 7; //manual, this can be changed later
  } // Repeating inclusions
  $fevent['Repeat']['Inclusions'] = array ();
  if ( ! empty ( $event['rdate'] ) && $event['rdate'] ) {
    $R = explode ( ',', $event['rdate'] );
    foreach ( $R as $rdate ) {
      $fevent['Repeat']['Inclusions'][] = icaldate_to_timestamp ( $rdate );
    }
    $fevent['Repeat']['Frequency'] = 7; //manual, this can be changed later
  }
  /* Repeats
  Snippet from RFC2445
 If multiple BYxxx rule parts are specified, then after evaluating the
   specified FREQ and INTERVAL rule parts, the BYxxx rule parts are
   applied to the current set of evaluated occurrences in the following
   order: BYMONTH, BYWEEKNO, BYYEARDAY, BYMONTHDAY, BYDAY, BYHOUR,
   BYMINUTE, BYSECOND and BYSETPOS; then COUNT and UNTIL are evaluated.
 */
  // Handle RRULE
  if ( ! empty ( $event['rrule'] ) ) {
    // default value
    // first remove any UNTIL that may have been calculated above
    unset ( $fevent['Repeat']['Until'] );
    // split into pieces
    // echo "RRULE line: $event[rrule]<br />\n";
    $RR = explode ( ';', $event['rrule'] );
    // create an associative array of key-value pairs in $RR2[]
    $rrcnt = count ( $RR );
    for ( $i = 0; $i < $rrcnt; $i++ ) {
      $ar = explode ( '=', $RR[$i] );
      $RR2[$ar[0]] = $ar[1];
    }
    for ( $i = 0; $i < $rrcnt; $i++ ) {
      if ( preg_match ( "/^FREQ=(.+)$/i", $RR[$i], $match ) ) {
        if ( preg_match ( "/YEARLY/i", $match[1], $submatch ) ) {
          $fevent['Repeat']['Frequency'] = 6;
        } else if ( preg_match ( "/MONTHLY/i", $match[1], $submatch ) ) {
          $fevent['Repeat']['Frequency'] = 3; //MonthByDay
        } else if ( preg_match ( "/WEEKLY/i", $match[1], $submatch ) ) {
          $fevent['Repeat']['Frequency'] = 2;
        } else if ( preg_match ( "/DAILY/i", $match[1], $submatch ) ) {
          $fevent['Repeat']['Frequency'] = 1;
        } else {
          // not supported :-(
          // but don't overwrite Manual setting from above
          if ( $fevent['Repeat']['Frequency'] != 7 )
            $fevent['Repeat']['Frequency'] = 0;
          echo "Unsupported iCal FREQ value \"$match[1]\"<br />\n";
          // Abort this import
          return;
        }
      } else if ( preg_match ( "/^INTERVAL=(.+)$/i", $RR[$i], $match ) ) {
        $fevent['Repeat']['Interval'] = $match[1];
      } else if ( preg_match ( "/^UNTIL=(.+)$/i", $RR[$i], $match ) ) {
        // specifies an end date
        $fevent['Repeat']['Until'] = icaldate_to_timestamp ( $match[1] );
      } else if ( preg_match ( "/^COUNT=(.+)$/i", $RR[$i], $match ) ) {
        // specifies the number of repeats
        // We convert this to a true UNTIL after we parse exceptions
        $fevent['Repeat']['Count'] = $match[1];
      } else if ( preg_match ( "/^BYSECOND=(.+)$/i", $RR[$i], $match ) ) {
        // NOT YET SUPPORTED -- TODO
        echo "Unsupported iCal BYSECOND value \"$RR[$i]\"<br />\n";
      } else if ( preg_match ( "/^BYMINUTE=(.+)$/i", $RR[$i], $match ) ) {
        // NOT YET SUPPORTED -- TODO
        echo "Unsupported iCal BYMINUTE value \"$RR[$i]\"<br />\n";
      } else if ( preg_match ( "/^BYHOUR=(.+)$/i", $RR[$i], $match ) ) {
        // NOT YET SUPPORTED -- TODO
        echo "Unsupported iCal BYHOUR value \"$RR[$i]\"<br />\n";
      } else if ( preg_match ( "/^BYMONTH=(.+)$/i", $RR[$i], $match ) ) {
        // this event repeats during the specified months
        $fevent['Repeat']['ByMonth'] = $match[1];
      } else if ( preg_match ( "/^BYDAY=(.+)$/i", $RR[$i], $match ) ) {
        // this array contains integer offset (i.e. 1SU,1MO,1TU)
        $fevent['Repeat']['ByDay'] = $match[1];
      } else if ( preg_match ( "/^BYMONTHDAY=(.+)$/i", $RR[$i], $match ) ) {
        $fevent['Repeat']['ByMonthDay'] = $match[1];
        // $fevent['Repeat']['Frequency'] = 3; //MonthlyByDay
      } else if ( preg_match ( "/^BYSETPOS=(.+)$/i", $RR[$i], $match ) ) {
        // if not already Yearly, mark as MonthlyBySetPos
        if ( $fevent['Repeat']['Frequency'] != 6 )
          $fevent['Repeat']['Frequency'] = 5;
        $fevent['Repeat']['BySetPos'] = $match[1];
      } else if ( preg_match ( "/^BYWEEKNO=(.+)$/i", $RR[$i], $match ) ) {
        $fevent['Repeat']['ByWeekNo'] = $match[1];
      } else if ( preg_match ( "/^BYYEARDAY=(.+)$/i", $RR[$i], $match ) ) {
        $fevent['Repeat']['ByYearDay'] = $match[1];
      } else if ( preg_match ( "/^WKST=(.+)$/i", $RR[$i], $match ) ) {
        $fevent['Repeat']['Wkst'] = $match[1];
      }
    }
  } // end if rrule
  
  // remove escape characters
  $fevent['Summary']= utf8_encode(format_ical_text($fevent['Summary']));
  $fevent['Description']=utf8_encode(format_ical_text($fevent['Description']));
  $fevent['Location'] = utf8_encode(format_ical_text($fevent['Location']));
  
  /* 
   * bugfix in order to avoid ICS Calendar plugin
   * error -- unknown repeat interval --
   * 
   */
   if( empty($event['rrule'] )){
	   $fevent['Repeat']=NULL;
   } 
  
  return $fevent;
}
// Figure out days of week for BYDAY values
// If value has no numeric offset, then set it's corresponding
// day value to  f. This selection is arbritary but gives
// plenty of room on either side to adjust because we need
// to allow values from -5 to +5
// For example  MO = f, -1MO = e, -2MO = d, +2MO - g, +3MO =h
// Note: f = chr(102) and 'n' is still a not present value
function rrule_repeat_days ( $RA ) {
  global $byday_names;
  
  $ret = array ();
  foreach ( $RA as $item ) {
    $item = strtoupper ( $item );
    if ( in_array ( $item, $byday_names ) )
      $ret[] = $item;
  }
  
  return ( empty ( $ret ) ? false : implode ( ',', $ret ) );
}
// Convert PYMDTHMS format to minutes
function parse_ISO8601_duration ( $duration ) {
  // we'll skip Years and Months
  $const = array ( 'M' => 1,
    'H' => 60,
    'D' => 1440,
    'W' => 10080
    );
  $ret = 0;
  $result = preg_split ( '/(P|D|T|H|M)/', $duration, -1,
    PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
  $resultcnt = count ( $result );
  for ( $i = 0; $i < $resultcnt; $i++ ) {
    if ( is_numeric ( $result[$i] ) && isset ( $result[$i + 1] ) ) {
      $ret += ( $result[$i] * $const[$result[$i + 1]] );
    }
  }
  if ( $result[0] == '-' ) $ret = - $ret;
  return $ret;
}

// Generate the FREEBUSY line of text for a single event
function fb_export_time ( $date, $duration, $time, $texport ) {
  $ret = '';
  $time = sprintf ( "%06d", $time );
  $allday = ( $time == -1 || $duration == 1440 );
  $year = ( int ) substr ( $date, 0, -4 );
  $month = ( int ) substr ( $date, - 4, 2 );
  $day = ( int ) substr ( $date, -2, 2 );
  // No time, or an "All day" event"
  if ( $allday ) {
    // untimed event - consider this to not be busy
  } else {
    // normal/timed event (or all-day event)
    $hour = ( int ) substr ( $time, 0, -4 );
    $min = ( int ) substr ( $time, -4, 2 );
    $sec = ( int ) substr ( $time, -2, 2 );
    $duration = $duration * 60;

    $start_tmstamp = mktime ( $hour, $min, $sec, $month, $day, $year );

    $utc_start = export_get_utc_date ( $date, $time );

    $end_tmstamp = $start_tmstamp + $duration;
    $utc_end = export_get_utc_date ( date ( 'Ymd', $end_tmstamp ),
      date ( 'His', $end_tmstamp ) );
    $ret .= "FREEBUSY:$utc_start/$utc_end\r\n";
  }
  return $ret;
}
// Generate export select.
function generate_export_select ( $jsaction = '', $name = 'exformat' ) {
  $palmStr = translate ( 'Palm Pilot' );
  return '
      <select name="format" id="' . $name . '"'
   . ( empty ( $jsaction ) ? '' : 'onchange="' . $jsaction . '();"' ) . '>
        <option value="ical">iCalendar</option>
        <option value="vcal">vCalendar</option>
        <option value="pilot-csv">Pilot-datebook CSV (' . $palmStr . ')</option>
        <option value="pilot-text">Install-datebook (' . $palmStr . ')</option>
      </select>';
}


function parse_tzid ( $tzid ) {
  // if we get a complex TZID we try to parse it
  if ( strstr ( $tzid, 'ozilla.org' ) or strstr ( $tzid, 'softwarestudio.org' ) ) {
    $tzAr = explode ( '/', $tzid );
    $tzArCnt = count ( $tzAr );
    $tzid = $tzAr[3];
    // we may recieve a 2 word tzid
    if ( $tzArCnt == 5 ) $tzid .= '/' . $tzAr[4];
    // and even maybe a 3 word tzid
    if ( $tzArCnt == 6 ) $tzid .= '/' . $tzAr[4] . '/' . $tzAr[5];
  }
  return $tzid;
}

?>
