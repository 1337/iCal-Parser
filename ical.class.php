<?php

/**
 * iCal generator classes V1.11 (MIT) 2014 Brian Lai
 *
 * Check README.md for examples
 */

/**
 * iCal "thing" base class
 *
 * Everything is a thing.
 * Everything extends off iCalComponent.
 */
class iCalComponent {
    // event, to-do, journal, or...
    // see 'Objects' in http://goo.gl/klqvs(as per RFC 2445 p.9)

    protected $props = array (),
                $timezone = null;

    public function __construct($type='VCALENDAR') {
        // set the type if you call it with one.
        $this->type = $type;

        // global defaults
        // $this->props['UID'] = substr(md5(rand()),0,8) . '@autonomous.com'; // no actual use
        $this->props['sequence'] = '0'; // how many times this event has been modified
        $this->props['UID'] = md5(time() . rand()) . '@ohai.ca'; // remove this if you plan to do any serious work
    }

    public function setType($type) {
        // allow only defined component values
        static $allowed_types = array ('VCALENDAR', 'VEVENT', 'VTODO',
                                        'VJOURNAL', 'VFREEBUSY',
                                        'VTIMEZONE', 'VALARM', '/X-(.)+/i',
                                        'iana-token');
        $matches = array ();
        foreach ($allowed_types as $allowed_type) {
            if (preg_match($allowed_type, $type, $matches, true) > 0) {
                return $this->props['type'] = $type;
            }
        }
        throw new Exception('Type ' . $type . ' is not allowed in specification');
    }

    public function addProperty($name, $value) {
        // add a property for this iCalComponent.
        // there is no removal.

        if ($name === 'type') {
            // special case: type setting
            $this->setType($value);
        }

        // replace newlines with \n, then a new line, then a space in front
        $value = str_replace("\r", '\r', $value);
        $value = str_replace("\n", '\n', $value);
        $value = chunk_split($value, 65, "\r\n "); // chunk no more than 75 chars per line
        $this->props[strtoupper($name)] = $value;
    }

    /**
        * Removes a property completely
        */
    public function removeProperty($name) {
        if (isset($this->props[strtoupper($name)])) {
            unset $this->props[strtoupper($name)];
        }
    }

    public function __get($name) {
        // PHP5 magic
        return $this->getProperty($name);
    }

    public function __set($name, $value) {
        // PHP5 magic
        $this->addProperty($name, $value);
    }

    public function addProperties(array $props) {
        // same effect as addProperty. supply an array of
        //    ($name => $value, $name => $value).
        if (sizeof($props) > 0) {
            foreach ($props as $name => $value) {
                $this->addProperty($name, $value);
            }
        }
    }

    public function hasProperty($name) {
        // returns true if
        // this object has the virtual property called $name.
        return in_array($name, $this->props);
    }

    public function getProperty($name, $default=null) {
        if ($this->hasProperty($name)) {
            return $this->props[$name];
        }
        return $default;
    }


    /*  === peripheral property helpers ===
        they don't all apply to subclasses, but here they are.
        function names should be easy enough to read. */

    public function setAllDay($is_it_all_day) {
        if ($is_it_all_day) { // if you gave it true
            $this->addProperties(array (
                    'X-FUNAMBOL-ALLDAY' => 'TRUE',
                    'X-MICROSOFT-CDO-ALLDAYEVENT' => 'TRUE'
                ));
        } else { // if you gave it false
            unset($this->props['X-FUNAMBOL-ALLDAY']);
            unset($this->props['X-MICROSOFT-CDO-ALLDAYEVENT']);
        }
    }

    public function setDescription($what) {
        $this->DESCRIPTION = $what;
    }

    public function setTime($start, $end=null) {
        // give one or two PHP mktime()s
        // if $end is left null, it will be the same as $start
        // times are added as local time.

        if (is_null($end)) {
            $end = $start;
        }

        if ($tz = $this->timezone or $tz = date_default_timezone_get()) {
            $this->addProperties(array (
                // DTSTART with timezone
                'DTSTART;TZID=' . $tz => $this->makeIcalTime($start),
                // 'I created this event one second before it starts'
                'DTSTAMP;TZID=' . $tz => $this->makeIcalTime($start - 1),
                // DTEND = ending time
                'DTEND;TZID=' . $tz => $this->makeIcalTime($end)
            ));
        } else {
            $this->addProperties(array (
                // DTSTART = starting time
                'DTSTART' => $this->makeIcalTime($start),
                // 'I created this event one second before it starts'
                'DTSTAMP' => $this->makeIcalTime($start - 1),
                // DTEND = ending time
                'DTEND' => $this->makeIcalTime($end)
            ));
        }

        // make sure all day flags are still correctly set
        // that would be if the event starts 00:00:00 on one day
        // and ends 00:00:00 on the next.
        $time_ss = $this->splitTime($start);
        $time_se = $this->splitTime($end);
        if ($time_ss['hour'] === '0' &&
            $time_ss['minute'] === '0' &&
            $time_ss['second'] === '0' &&
            $time_se['hour'] === '0' &&
            $time_se['minute'] === '0' &&
            $time_se['second'] === '0' &&
            (   // see if day is more ahead
                $time_se['day'] > $time_ss['day'] ||
                $time_se['month'] > $time_ss['month'] ||
                $time_se['year'] > $time_ss['year']
            )) {
            $this->setAllDay(true);
        } else {
            $this->setAllDay(false);
        }
    }

    public function setTimezone($timezone) {
        // $timezone be one of them timezone strings
        $this->timezone = $timezone;
    }

    public function setTitle($what) {
        // dynamic variable
        $this->SUMMARY = $what;
    }

    public function setOwner($whom) {
        $this->ORGANIZER = $whom;
    }

    public function setStatus($what) {
        // if $what is integer, the indexed status is used(not recommended)
        // if $what is a string, the string will be used as status, BUT
        //     only if the string is one of the allowed values


        // can be one of the following
        $allowed_statuses = array ('TENTATIVE', 'CONFIRMED', 'CANCELLED',
                                    'NEEDS-ACTION', 'COMPLETED',
                                    'IN-PROCESS', 'DRAFT', 'FINAL');
        if (is_int($what)) {
            $this->STATUS = $allowed_statuses[$what];
        } elseif (in_array($what, $allowed_statuses)) {
            $this->STATUS = $what;
        }
    }

    public function setAlarm($text='', $days=0, $hours=0, $minutes=0,
                                $seconds=0) {
        // set an alarm for this event - so many days/hours/minutes/seconds in advance.
        // reminder text will be $text.
        // actually creates a VALARM object as a child of the current object.
        $alarm = new iCalAlarm();
        $alarm->addProperties(array (
            'ACTION' => 'DISPLAY',
            'DESCRIPTION' => $text,
            'TRIGGER' => "-P{$days}DT{$hours}H{$minutes}M{$seconds}S"
        ));
        $this->addChild($alarm);
    }

    public function setRecurrence() {
        // "I'll leave it to you as a take-home exercise" - Robert J. Le Roy
    }

    /*  === END peripheral property helpers === */


    public function addChild($child) {
        // add an iCalComponent within this one. example would be
        // adding a VEVENT iCalComponent within a VCALENDAR iCalComponent.
        // child can be both an iCalComponent or a subclass of it.
        // there is no removal.
        if (get_class($child) == 'iCalComponent' ||
            is_subclass_of($child, 'iCalComponent')) {
            $this->props['children'][] = $child;
        } else {
            throw new Exception('Child added is not an iCalComponent');
        }
    }

    public function addChildren($children) {
        // same effect as addChild. supply an array of
        //    ($child, $child, $child).
        if (sizeof($children) > 0) {
            foreach ($children as $child) {
                $this->addChild($child);
            }
        }
    }

    public function toString() {
        // returns a string representation of the iCalComponent.

        // construct the object.
        $buffer = 'BEGIN:' . $this->props['type'];

        // export properties of this object(upper case ones only)
        if (sizeof($this->props) > 0) {
            foreach ($this->props as $key => $value) {
                if (strtoupper($key) == $key) {
                    if ($key === 'DTSTART') {// && $this->timezone !== null) {
                        $key = $this->timezone;
                    }
                    $buffer .= "\r\n" . $key . ':' . $value;
                }
            }
        }

        // export children.
        if (array_key_exists('children', $this->props) && sizeof($this->props['children']) > 0) {
            foreach ($this->props['children'] as $child) {
                // BEGIN: line does not have \r\n, so add it for the child
                $buffer .= "\r\n" . $child->toString();
            }
        }

        // end the object
        $buffer .= "\r\nEND:" . $this->props['type'];
        return $buffer;
    }

    public function __toString() {
        // PHP5 magic
        return $this->toString();
    }

    public function toJSON() {
        return json_encode($this->props);
    }

    private function splitTime($time) {
        // given $time(made by time()), return an array of it
        return array (
            'day' => str_pad(date('j', $time), 2, '0', STR_PAD_LEFT),
            'month' => str_pad(date('n', $time), 2, '0', STR_PAD_LEFT),
            'year' => str_pad(date('Y', $time), 4, '0', STR_PAD_LEFT),
            'hour' => str_pad(date('H', $time), 2, '0', STR_PAD_LEFT),
            'minute' => str_pad(date('i', $time), 2, '0', STR_PAD_LEFT),
            'second' => str_pad(date('s', $time), 2, '0', STR_PAD_LEFT)
        );
    }

    public function makeIcalTime($time) {
        // create an iCal time(i.e. '20110713T185610Z' based on a given time.
        $tz = $this->splitTime($time);
        // return($tz['year'] . $tz['month'] . $tz['day'] . 'T' . $tz['hour'] . $tz['minute'] . $tz['second'] . 'Z');
        return ($tz['year'] . $tz['month'] . $tz['day'] . 'T' . $tz['hour'] . $tz['minute'] . $tz['second']);
    }

    public function outputFile($filename='cal.ics') {
        header('Content-type: text/calendar');
        header('Cache-Control: public');
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo $this->toString();
    }
}


class iCal extends iCalComponent {
    // so, VCALENDAR.

    function __construct($props=null) {
        // your object declaration has changed.
        // supply optional properties here instead of the object type.

        $this->props['VERSION'] = '2.0'; // 'The VERSION property should be the first property on the calendar'

        parent::__construct();

        if (!is_array($props)) {
            $props = array (); // null needs to become array for later code
        }

        // build properties array
        $this->props = array_merge(
            array_change_key_case($props, CASE_UPPER),
            $this->props,
            array (
                // required defaults
                'PRODID' => '-//Google Inc//Google Calendar 70.9054//EN',
                // of course
                'X-PUBLISHED-TTL' => '1',
                // update interval, in some kind of format
                'CALSCALE' => 'GREGORIAN' /*,
                'METHOD' => 'PUBLISH', */
                'X-WR-CALNAME' => $props->name || 'Calendar',
                'X-WR-TIMEZONE' => $props->timezone || 'Calendar' /*,
                'CREATED' => $this->makeIcalTime(time() - 1),
                'LAST-MODIFIED' => $this->makeIcalTime(time() - 1) */
            )
        );
    }

    /**
     * @returns {iCal}
     */
    public function parse($contents) {
        // "I'll leave it to you as a take-home exercise" - Robert J. Le Roy
        $this_class = get_class($this);
        return new $this_class($contents);
    }

    public function addEvent($title, $description, $start_time,
                        $end_time=null, $timezone=null) {
        // making my life easier
        if (class_exists('iCalEvent')) {
            $b = new iCalEvent();
            $b->setTitle($title);
            $b->setDescription($description);
            $b->setTimezone($timezone);
            $b->setTime($start_time, $end_time);

            $this->addChild($b);
        } else {
            throw new Exception('Cannot find iCalEvent class');
        }
    }
}


class iCalEvent extends iCalComponent {
    // so, VEVENT.

    function __construct($props=null) {
        // your object declaration has changed.
        // supply optional properties here instead of the object type.
        parent::__construct('VEVENT');

        if (!is_array($props)) {
            $props = array (); // null needs to become array for later code
        }

        // build properties array
        $this->props = array_merge(
            array_change_key_case($props, CASE_UPPER),
            $this->props,
            array (
                    // required defaults
                    'STATUS' => 'CONFIRMED',
                    'SEQUENCE' => '0' /*,
                'CREATED' =>  $this->makeIcalTime(time() - 1), // 'it was created a second ago'
                'TRANSP' => 'OPAQUE',
                'CLASS' => 'PRIVATE'*/
            )
        );
    }
}


class iCalTimezone extends iCalComponent {
    // so, VTIMEZONE.

    /*
    This is an example showing all the time zone rules for New York
      City since April 30, 1967 at 03:00:00 EDT.

       BEGIN:VTIMEZONE
       TZID:America/New_York
       LAST-MODIFIED:20050809T050000Z
       BEGIN:DAYLIGHT
       DTSTART:19670430T020000
       RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=-1SU;UNTIL=19730429T070000Z
       TZOFFSETFROM:-0500
       TZOFFSETTO:-0400
       TZNAME:EDT
       END:DAYLIGHT
       BEGIN:STANDARD
       DTSTART:19671029T020000
       RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU;UNTIL=20061029T060000Z
       TZOFFSETFROM:-0400
       TZOFFSETTO:-0500
       TZNAME:EST
       END:STANDARD
       BEGIN:DAYLIGHT
       DTSTART:19740106T020000
       RDATE:19750223T020000
       TZOFFSETFROM:-0500
       TZOFFSETTO:-0400
       TZNAME:EDT
       END:DAYLIGHT
       BEGIN:DAYLIGHT
       DTSTART:19760425T020000
       RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=-1SU;UNTIL=19860427T070000Z
       TZOFFSETFROM:-0500
       TZOFFSETTO:-0400
       TZNAME:EDT
       END:DAYLIGHT
       BEGIN:DAYLIGHT
       DTSTART:19870405T020000
       RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=1SU;UNTIL=20060402T070000Z
       TZOFFSETFROM:-0500
       TZOFFSETTO:-0400
       TZNAME:EDT
       END:DAYLIGHT
       BEGIN:DAYLIGHT
       DTSTART:20070311T020000
       RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU
       TZOFFSETFROM:-0500
       TZOFFSETTO:-0400
       TZNAME:EDT
       END:DAYLIGHT
       BEGIN:STANDARD
       DTSTART:20071104T020000
       RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU
       TZOFFSETFROM:-0400
       TZOFFSETTO:-0500
       TZNAME:EST
       END:STANDARD
       END:VTIMEZONE

       Note that this is only
       suitable for a recurring event that starts on or later than March
       11, 2007 at 03:00:00 EDT (i.e., the earliest effective transition
       date and time) and ends no later than March 9, 2008 at 01:59:59
       EST
    */

    function __construct($props=null) {
        // your object declaration has changed.
        // supply optional properties here instead of the object type.
        parent::__construct('VTIMEZONE');

        if (!is_array($props)) {
            $props = array (); // null needs to become array for later code
        }

        // build properties array
        $this->props = array_merge(
            array_change_key_case($props, CASE_UPPER),
            $this->props
        );
    }
}


class iCalTodo extends iCalComponent {
    // so, VTODO.

    function __construct($props=null) {
        // your object declaration has changed.
        // supply optional properties here instead of the object type.
        parent::__construct('VTODO');

        if (!is_array($props)) {
            $props = array (); // null needs to become array for later code
        }

        // build properties array
        $this->props = array_merge(
            array_change_key_case($props, CASE_UPPER),
            $this->props
        );
    }
}


class iCalAlarm extends iCalComponent {
    // so, VALARM.
    // https://tools.ietf.org/html/rfc5545#section-3.6.6

    function __construct($props=null) {
        // your object declaration has changed.
        // supply optional properties here instead of the object type.
        parent::__construct('VALARM');

        if (!is_array($props)) {
            $props = array (); // null needs to become array for later code
        }

        // build properties array
        $this->props = array_merge(
            array_change_key_case($props, CASE_UPPER),
            $this->props
        );
    }
}


class iCalJournal extends iCalComponent {
    // so, VJOURNAL.

    function __construct($props=null) {
        // your object declaration has changed.
        // supply optional properties here instead of the object type.
        parent::__construct('VJOURNAL');

        if (!is_array($props)) {
            $props = array (); // null needs to become array for later code
        }

        // build properties array
        $this->props = array_merge(
            array_change_key_case($props, CASE_UPPER),
            $this->props
        );
    }
}
