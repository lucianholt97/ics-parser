<?php
/**
 * This PHP class will read an ICS (`.ics`, `.ical`, `.ifb`) file, parse it and return an
 * array of its contents.
 *
 * PHP 5 (≥ 5.3.0)
 *
 * @author  Jonathan Goode <https://github.com/u01jmg3>, John Grogg <john.grogg@gmail.com>, Martin Thoma <info@martin-thoma.de>
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 * @version 2.0.5
 */

namespace ICal;

class ICal
{
    const DATE_TIME_FORMAT  = 'Ymd\THis';
    const RECURRENCE_EVENT  = 'Generated recurrence event';
    const SECONDS_IN_A_WEEK = 604800;
    const TIME_FORMAT       = 'His';
    const UNIX_MIN_YEAR     = 1970;

    /**
     * Track the number of events in the current iCal feed
     *
     * @var integer
     */
    public $eventCount = 0;

    /**
     * Track the free/busy count in the current iCal feed
     *
     * @var integer
     */
    public $freeBusyCount = 0;

    /**
     * Track the number of todos in the current iCal feed
     *
     * @var integer
     */
    public $todoCount = 0;

    /**
     * The value in years to use for indefinite, recurring events
     *
     * @var integer
     */
    public $defaultSpan = 2;

    /**
     * Customise the default time zone used by the parser
     *
     * @var string
     */
    public $defaultTimeZone;

    /**
     * The two letter representation of the first day of the week
     *
     * @var string
     */
    public $defaultWeekStart = 'MO';

    /**
     * Toggle whether to skip the parsing recurrence rules
     *
     * @var boolean
     */
    public $skipRecurrence = false;

    /**
     * Toggle whether to use time zone info when parsing recurrence rules
     *
     * @var boolean
     */
    public $useTimeZoneWithRRules = false;

    /**
     * The parsed calendar
     *
     * @var array
     */
    public $cal = array();

    /**
     * Track the VFREEBUSY component
     *
     * @var integer
     */
    protected $freeBusyIndex = 0;

    /**
     * Variable to track the previous keyword
     *
     * @var string
     */
    protected $lastKeyword;

    /**
     * Event recurrence instances that have been altered
     *
     * @var array
     */
    protected $alteredRecurrenceInstances = array();

    /**
     * An associative array containing ordinal data
     *
     * @var array
     */
    protected $dayOrdinals = array(
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth',
        5 => 'fifth',
    );

    /**
     * An associative array containing weekday conversion data
     *
     * @var array
     */
    protected $weekdays = array(
        'SU' => 'sunday',
        'MO' => 'monday',
        'TU' => 'tuesday',
        'WE' => 'wednesday',
        'TH' => 'thursday',
        'FR' => 'friday',
        'SA' => 'saturday',
    );

    /**
     * An associative array containing week conversion data
     * (UK = SU, Europe = MO)
     *
     * @var array
     */
    protected $weeks = array(
        'SA' => array('SA', 'SU', 'MO', 'TU', 'WE', 'TH', 'FR'),
        'SU' => array('SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'),
        'MO' => array('MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'),
    );

    /**
     * An associative array containing month names
     *
     * @var array
     */
    protected $monthNames = array(
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    );

    /**
     * An associative array containing frequency conversion terms
     *
     * @var array
     */
    protected $frequencyConversion = array(
        'DAILY'   => 'day',
        'WEEKLY'  => 'week',
        'MONTHLY' => 'month',
        'YEARLY'  => 'year',
    );

    /**
     * Define which variables can be configured
     *
     * @var array
     */
    private static $configurableOptions = array(
        'defaultSpan',
        'defaultTimeZone',
        'defaultWeekStart',
        'skipRecurrence',
        'useTimeZoneWithRRules',
    );

    /**
     * Creates the ICal object
     *
     * @param  mixed $files   The path or URL to each ICS file to parse
     *                        or iCal content provided as an array
     * @param  array $options Default options to be used by the parser
     * @return void
     */
    public function __construct($files = false, array $options = array())
    {
        ini_set('auto_detect_line_endings', '1');

        foreach ($options as $option => $value) {
            if (in_array($option, self::$configurableOptions)) {
                $this->{$option} = $value;
            }
        }

        // Fallback to use the system default time zone
        if (!isset($this->defaultTimeZone) || !$this->isValidTimeZoneId($this->defaultTimeZone)) {
            $this->defaultTimeZone = date_default_timezone_get();
        }

        if ($files !== false) {
            $files = is_array($files) ? $files : array($files);

            foreach ($files as $file) {
                if ($this->isFileOrUrl($file)) {
                    $lines = $this->fileOrUrl($file);
                } else {
                    $lines = is_array($file) ? $file : array($file);
                }

                $this->initLines($lines);
            }
        }
    }

    /**
     * Initialises lines from a string
     *
     * @param  string $string The contents of the ICS file to initialise
     * @return ICal
     */
    public function initString($string)
    {
        if (empty($this->cal)) {
            $lines = explode(PHP_EOL, $string);

            $this->initLines($lines);
        } else {
            trigger_error('ICal::initString: Calendar already initialised in constructor', E_USER_NOTICE);
        }

        return $this;
    }

    /**
     * Initialises lines from a file
     *
     * @param  string $file The file path or URL of the ICS to use
     * @return ICal
     */
    public function initFile($file)
    {
        if (empty($this->cal)) {
            $lines = $this->fileOrUrl($file);

            $this->initLines($lines);
        } else {
            trigger_error('ICal::initFile: Calendar already initialised in constructor', E_USER_NOTICE);
        }

        return $this;
    }

    /**
     * Initialises lines from a URL
     *
     * @param  string $url The url of the ICS file to download and initialise from
     * @return ICal
     */
    public function initUrl($url)
    {
        $this->initFile($url);

        return $this;
    }

    /**
     * Initialises the parser using an array
     * containing each line of iCal content
     *
     * @param  array $lines The lines to initialise
     * @return void
     */
    protected function initLines(array $lines)
    {
        $lines = $this->unfold($lines);

        if (stristr($lines[0], 'BEGIN:VCALENDAR') !== false) {
            $component = '';
            foreach ($lines as $line) {
                $line = rtrim($line); // Trim trailing whitespace
                $line = $this->removeUnprintableChars($line);
                $line = $this->cleanData($line);
                $add  = $this->keyValueFromString($line);

                $keyword = $add[0];
                $values  = $add[1]; // May be an array containing multiple values

                if (!is_array($values)) {
                    if (!empty($values)) {
                        $values = array($values); // Make an array as not already
                        $blankArray = array(); // Empty placeholder array
                        array_push($values, $blankArray);
                    } else {
                        $values = array(); // Use blank array to ignore this line
                    }
                } elseif (empty($values[0])) {
                    $values = array(); // Use blank array to ignore this line
                }

                $values = array_reverse($values); // Reverse so that our array of properties is processed first

                foreach ($values as $value) {
                    switch ($line) {
                        // http://www.kanzaki.com/docs/ical/vtodo.html
                        case 'BEGIN:VTODO':
                            if (!is_array($value)) {
                                $this->todoCount++;
                            }
                            $component = 'VTODO';
                            break;

                        // http://www.kanzaki.com/docs/ical/vevent.html
                        case 'BEGIN:VEVENT':
                            if (!is_array($value)) {
                                $this->eventCount++;
                            }
                            $component = 'VEVENT';
                            break;

                        // http://www.kanzaki.com/docs/ical/vfreebusy.html
                        case 'BEGIN:VFREEBUSY':
                            if (!is_array($value)) {
                                $this->freeBusyIndex++;
                            }
                            $component = 'VFREEBUSY';
                            break;

                        case 'BEGIN:DAYLIGHT':
                        case 'BEGIN:STANDARD':
                        case 'BEGIN:VALARM':
                        case 'BEGIN:VCALENDAR':
                        case 'BEGIN:VTIMEZONE':
                            $component = $value;
                            break;

                        case 'END:DAYLIGHT':
                        case 'END:STANDARD':
                        case 'END:VALARM':
                        case 'END:VCALENDAR':
                        case 'END:VEVENT':
                        case 'END:VFREEBUSY':
                        case 'END:VTIMEZONE':
                        case 'END:VTODO':
                            $component = 'VCALENDAR';
                            break;

                        default:
                            $this->addCalendarComponentWithKeyAndValue($component, $keyword, $value);
                            break;
                    }
                }
            }

            $this->processEvents();

            if (!$this->skipRecurrence) {
                $this->processRecurrences();
            }

            $this->processDateConversions();
        }
    }

    /**
     * Unfold an ICS file in preparation for parsing
     * https://icalendar.org/iCalendar-RFC-5545/3-1-content-lines.html
     *
     * @param  array $lines The contents of the iCal string to unfold
     * @return string
     */
    protected function unfold(array $lines)
    {
        $string = implode(PHP_EOL, $lines);
        $string = preg_replace('/' . PHP_EOL . '[ \t]/', '', $string);
        $lines  = explode(PHP_EOL, $string);

        return $lines;
    }

    /**
     * Add to `$this->ical` array one value and key
     *
     * @param  string         $component This could be VTODO, VEVENT, VCALENDAR, ...
     * @param  string|boolean $keyword   The keyword, for example DTSTART
     * @param  string         $value     The value, for example 20110105T090000Z
     * @return void
     */
    protected function addCalendarComponentWithKeyAndValue($component, $keyword, $value)
    {
        if ($keyword == false) {
            $keyword = $this->lastKeyword;
        }

        switch ($component) {
            case 'VTODO':
                $this->cal[$component][$this->todoCount - 1][$keyword] = $value;
                break;

            case 'VEVENT':
                if (!isset($this->cal[$component][$this->eventCount - 1][$keyword . '_array'])) {
                    $this->cal[$component][$this->eventCount - 1][$keyword . '_array'] = array();
                }

                if (is_array($value)) {
                    // Add array of properties to the end
                    array_push($this->cal[$component][$this->eventCount - 1][$keyword . '_array'], $value);
                } else {
                    if (!isset($this->cal[$component][$this->eventCount - 1][$keyword])) {
                        $this->cal[$component][$this->eventCount - 1][$keyword] = $value;
                    }

                    if ($keyword === 'EXDATE') {
                        if (trim($value) === $value) {
                            $array = array_filter(explode(',', $value));
                            $this->cal[$component][$this->eventCount - 1][$keyword . '_array'][] = $array;
                        } else {
                            $value = explode(',', implode(',', $this->cal[$component][$this->eventCount - 1][$keyword . '_array'][1]) . trim($value));
                            $this->cal[$component][$this->eventCount - 1][$keyword . '_array'][1] = $value;
                        }
                    } else {
                        $this->cal[$component][$this->eventCount - 1][$keyword . '_array'][] = $value;

                        if ($keyword === 'DURATION') {
                            $duration = new \DateInterval($value);
                            array_push($this->cal[$component][$this->eventCount - 1][$keyword . '_array'], $duration);
                        }
                    }

                    if ($this->cal[$component][$this->eventCount - 1][$keyword] !== $value) {
                        $this->cal[$component][$this->eventCount - 1][$keyword] .= ',' . $value;
                    }
                }
                break;

            case 'VFREEBUSY':
                if ($keyword === 'FREEBUSY') {
                    if (is_array($value)) {
                        $this->cal[$component][$this->freeBusyIndex - 1][$keyword][][] = $value;
                    } else {
                        $this->freeBusyCount++;

                        end($this->cal[$component][$this->freeBusyIndex - 1][$keyword]);
                        $key = key($this->cal[$component][$this->freeBusyIndex - 1][$keyword]);

                        $value = explode('/', $value);
                        $this->cal[$component][$this->freeBusyIndex - 1][$keyword][$key][] = $value;
                    }
                } else {
                    $this->cal[$component][$this->freeBusyIndex - 1][$keyword][] = $value;
                }
                break;

            default:
                $this->cal[$component][$keyword] = $value;
                break;
        }

        $this->lastKeyword = $keyword;
    }

    /**
     * Get the key-value pair from an iCal string
     *
     * @param  string $text
     * @return array
     */
    protected function keyValueFromString($text)
    {
        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        $colon = strpos($text, ':');
        $quote = strpos($text, '"');
        if ($colon === false) {
            $matches = array();
        } elseif ($quote === false || $colon < $quote) {
            list($before, $after) = explode(':', $text, 2);
            $matches              = array($text, $before, $after);
        } else {
            list($before, $text) = explode('"', $text, 2);
            $text                = '"' . $text;
            $matches             = str_getcsv($text, ':');
            $combinedValue       = '';

            foreach ($matches as $key => $match) {
                if ($key === 0) {
                    if (!empty($before)) {
                        $matches[$key] = $before . '"' . $matches[$key] . '"';
                    }
                } else {
                    if ($key > 1) {
                        $combinedValue .= ':';
                    }

                    $combinedValue .= $matches[$key];
                }
            }
            $matches    = array_slice($matches, 0, 2);
            $matches[1] = $combinedValue;
            array_unshift($matches, $before . $text);
        }

        if (count($matches) === 0) {
            return false;
        }

        if (preg_match('/^([A-Z-]+)([;][\w\W]*)?$/', $matches[1])) {
            $matches = array_splice($matches, 1, 2); // Remove first match and re-align ordering

            // Process properties
            if (preg_match('/([A-Z-]+)[;]([\w\W]*)/', $matches[0], $properties)) {
                // Remove first match
                array_shift($properties);
                // Fix to ignore everything in keyword after a ; (e.g. Language, TZID, etc.)
                $matches[0] = $properties[0];
                array_shift($properties); // Repeat removing first match

                $formatted = array();
                foreach ($properties as $property) {
                    // Match semicolon separator outside of quoted substrings
                    preg_match_all('~[^' . PHP_EOL . '";]+(?:"[^"\\\]*(?:\\\.[^"\\\]*)*"[^' . PHP_EOL . '";]*)*~', $property, $attributes);
                    // Remove multi-dimensional array and use the first key
                    $attributes = (sizeof($attributes) === 0) ? array($property) : reset($attributes);

                    if (is_array($attributes)) {
                        foreach ($attributes as $attribute) {
                            // Match equals sign separator outside of quoted substrings
                            preg_match_all(
                                '~[^' . PHP_EOL . '"=]+(?:"[^"\\\]*(?:\\\.[^"\\\]*)*"[^' . PHP_EOL . '"=]*)*~',
                                $attribute,
                                $values
                            );
                            // Remove multi-dimensional array and use the first key
                            $value = (sizeof($values) === 0) ? null : reset($values);

                            if (is_array($value) && isset($value[1])) {
                                // Remove double quotes from beginning and end only
                                $formatted[$value[0]] = trim($value[1], '"');
                            }
                        }
                    }
                }

                // Assign the keyword property information
                $properties[0] = $formatted;

                // Add match to beginning of array
                array_unshift($properties, $matches[1]);
                $matches[1] = $properties;
            }

            return $matches;
        } else {
            return false; // Ignore this match
        }
    }

    /**
     * Return a DateTime object from an iCal date time format
     *
     * @param  string  $icalDate      A Date in the format YYYYMMDD[T]HHMMSS[Z],
     *                                YYYYMMDD[T]HHMMSS or
     *                                TZID={Time Zone}:YYYYMMDD[T]HHMMSS
     * @param  boolean $forceTimeZone Whether to force the time zone; the event's or the default
     * @param  boolean $forceUtc      Whether to force the time zone as UTC
     * @return DateTime
     */
    public function iCalDateToDateTime($icalDate, $forceTimeZone = false, $forceUtc = false)
    {
        /**
         * iCal times may be in 3 formats, (http://www.kanzaki.com/docs/ical/dateTime.html)
         *
         * UTC:      Has a trailing 'Z'
         * Floating: No time zone reference specified, no trailing 'Z', use local time
         * TZID:     Set time zone as specified
         *
         * Use DateTime class objects to get around limitations with `mktime` and `gmmktime`.
         * Must have a local time zone set to process floating times.
         */
        $pattern  = '/\AT?Z?I?D?=?(.*):?'; // [1]: Time zone
        $pattern .= '([0-9]{4})';          // [2]: YYYY
        $pattern .= '([0-9]{2})';          // [3]: MM
        $pattern .= '([0-9]{2})';          // [4]: DD
        $pattern .= 'T?';                  //      Time delimiter
        $pattern .= '([0-9]{0,2})';        // [5]: HH
        $pattern .= '([0-9]{0,2})';        // [6]: MM
        $pattern .= '([0-9]{0,2})';        // [7]: SS
        $pattern .= '(Z?)/';               // [8]: UTC flag

        preg_match($pattern, $icalDate, $date);

        if (empty($date)) {
            // Default to the initial
            $dateTime = $icalDate;
        } else {
            // A Unix timestamp cannot represent a date prior to 1 Jan 1970
            $year = $date[2];
            if ($year <= self::UNIX_MIN_YEAR) {
                $dateTime = new \DateTime($icalDate, new \DateTimeZone($this->defaultTimeZone));
            } else {
                if ($forceTimeZone) {
                    // TZID={Time Zone}:
                    if (isset($date[1])) {
                        $eventTimeZone = rtrim($date[1], ':');
                    }

                    if ($date[8] === 'Z') {
                        $dateTime = new \DateTime('now', new \DateTimeZone('UTC'));
                    } elseif (isset($eventTimeZone) && $this->isValidTimeZoneId($eventTimeZone)) {
                        $dateTime = new \DateTime('now', new \DateTimeZone($eventTimeZone));
                    } else {
                        $dateTime = new \DateTime('now', new \DateTimeZone($this->defaultTimeZone));
                    }
                } else {
                    $dateTime = new \DateTime('now');
                }

                $dateTime->setDate((int) $date[2], (int) $date[3], (int) $date[4]);
                $dateTime->setTime((int) $date[5], (int) $date[6], (int) $date[7]);
            }

            if ($forceUtc) {
                $dateTime->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        return $dateTime;
    }

    /**
     * Return a Unix timestamp from an iCal date time format
     *
     * @param  string  $icalDate      A Date in the format YYYYMMDD[T]HHMMSS[Z],
     *                                YYYYMMDD[T]HHMMSS or
     *                                TZID={Time Zone}:YYYYMMDD[T]HHMMSS
     * @param  boolean $forceTimeZone Whether to force the time zone; the event's or the default
     * @param  boolean $forceUtc      Whether to force the time zone as UTC
     * @return integer
     */
    public function iCalDateToUnixTimestamp($icalDate, $forceTimeZone = false, $forceUtc = false)
    {
        $dateTime = $this->iCalDateToDateTime($icalDate, $forceTimeZone, $forceUtc);

        return $dateTime->getTimestamp();
    }

    /**
     * Return a date adapted to the calendar
     * time zone depending on the event TZID
     *
     * @param  array  $event         An event
     * @param  string $key           An event parameter (DTSTART or DTEND)
     * @param  string $forceTimeZone Whether to force a time zone even if Zulu time is specified
     * @return string Ymd\THis date
     */
    public function iCalDateWithTimeZone(array $event, $key, $forceTimeZone = false)
    {
        if (!isset($event[$key . '_array']) || !isset($event[$key])) {
            return false;
        }

        $dateArray = $event[$key . '_array'];
        $date      = $event[$key];

        if ($key === 'DURATION') {
            $duration = end($dateArray);
            $dateTime = $this->parseDuration($event['DTSTART'], $duration, null);
        } else {
            $dateTime = $this->iCalDateToDateTime($dateArray[3], true);
        }

        return $dateTime->format(self::DATE_TIME_FORMAT);
    }

    /**
     * Performs some admin tasks on all events as taken straight from the ics file.
     * Adds a Unix timestamp to all `{DTSTART|DTEND|RECURRENCE-ID}_array` arrays
     * Makes a note of modified recurrence-instances
     *
     * @return mixed
     */
    protected function processEvents()
    {
        $events = (isset($this->cal['VEVENT'])) ? $this->cal['VEVENT'] : array();

        if (empty($events)) {
            return false;
        }

        foreach ($events as $key => $anEvent) {
            foreach (array('DTSTART', 'DTEND', 'RECURRENCE-ID') as $type) {
                if (isset($anEvent[$type])) {
                    $date = $anEvent[$type . '_array'][1];
                    if (isset($anEvent[$type . '_array'][0]['TZID'])) {
                        $date = 'TZID=' . $anEvent[$type . '_array'][0]['TZID'] . ':' . $date;
                    }
                    $anEvent[$type . '_array'][2] = $this->iCalDateToUnixTimestamp($date);
                    $anEvent[$type . '_array'][3] = $date;
                }
            }

            if (isset($anEvent['RECURRENCE-ID'])) {
                $uid = $anEvent['UID'];
                if (!isset($this->alteredRecurrenceInstances[$uid])) {
                    $this->alteredRecurrenceInstances[$uid] = array();
                }
                $recurrenceDateUtc = $this->iCalDateToUnixTimestamp($anEvent['RECURRENCE-ID_array'][3], true, true);
                $this->alteredRecurrenceInstances[$uid][] = $recurrenceDateUtc;
            }

            $events[$key] = $anEvent;
        }

        $this->cal['VEVENT'] = $events;
    }

    /**
     * Processes recurrence rules
     *
     * @return mixed
     */
    protected function processRecurrences()
    {
        $events = (isset($this->cal['VEVENT'])) ? $this->cal['VEVENT'] : array();

        if (empty($events)) {
            return false;
        }

        foreach ($events as $anEvent) {
            if (isset($anEvent['RRULE']) && $anEvent['RRULE'] !== '') {
                // Tag as generated by a recurrence rule
                $anEvent['RRULE_array'][2] = self::RECURRENCE_EVENT;

                $isAllDayEvent = (strlen($anEvent['DTSTART_array'][1]) === 8) ? true : false;

                $initialStart             = new \DateTime($anEvent['DTSTART_array'][1]);
                $initialStartOffset       = $initialStart->getOffset();
                $initialStartTimeZoneName = $initialStart->getTimezone()->getName();

                if (isset($anEvent['DTEND'])) {
                    $initialEnd             = new \DateTime($anEvent['DTEND_array'][1]);
                    $initialEndOffset       = $initialEnd->getOffset();
                    $initialEndTimeZoneName = $initialEnd->getTimezone()->getName();
                } else {
                    $initialEndTimeZoneName = $initialStartTimeZoneName;
                }

                // Recurring event, parse RRULE and add appropriate duplicate events
                $rrules = array();
                $rruleStrings = explode(';', $anEvent['RRULE']);
                foreach ($rruleStrings as $s) {
                    list($k, $v) = explode('=', $s);
                    $rrules[$k] = $v;
                }
                // Get frequency
                $frequency = $rrules['FREQ'];
                // Get Start timestamp
                $startTimestamp = $initialStart->getTimestamp();
                if (isset($anEvent['DTEND'])) {
                    $endTimestamp = $initialEnd->getTimestamp();
                } elseif (isset($anEvent['DURATION'])) {
                    $duration = end($anEvent['DURATION_array']);
                    $endTimestamp = $this->parseDuration($anEvent['DTSTART'], $duration);
                } else {
                    $endTimestamp = $anEvent['DTSTART_array'][2];
                }
                $eventTimestampOffset = $endTimestamp - $startTimestamp;
                // Get Interval
                $interval = (isset($rrules['INTERVAL']) && $rrules['INTERVAL'] !== '') ? $rrules['INTERVAL'] : 1;

                $dayNumber = null;
                $weekday   = null;

                if (in_array($frequency, array('MONTHLY', 'YEARLY')) && isset($rrules['BYDAY']) && $rrules['BYDAY'] !== '') {
                    // Deal with BYDAY
                    $byDay     = $rrules['BYDAY'];
                    $dayNumber = intval($byDay);

                    if (empty($dayNumber)) { // Returns 0 when no number defined in BYDAY
                        if (!isset($rrules['BYSETPOS'])) {
                            $dayNumber = 1; // Set first as default
                        } elseif (is_numeric($rrules['BYSETPOS'])) {
                            $dayNumber = $rrules['BYSETPOS'];
                        }
                    }

                    $weekday = substr($byDay, -2);
                }

                $untilDefault = date_create('now');
                $untilDefault->modify($this->defaultSpan . ' year');
                $untilDefault->setTime(23, 59, 59); // End of the day

                // Compute EXDATEs
                $exdates = $this->parseExdates($anEvent);

                if (isset($rrules['UNTIL'])) {
                    // Get Until
                    $until = strtotime($rrules['UNTIL']);
                } elseif (isset($rrules['COUNT'])) {
                    $countOrig  = (is_numeric($rrules['COUNT']) && $rrules['COUNT'] > 1) ? $rrules['COUNT'] : 0;

                    // Increment count by the number of excluded dates
                    $countOrig += sizeof($exdates);

                    // Remove one to exclude the occurrence that initialises the rule
                    $count = ($countOrig - 1);

                    if ($interval >= 2) {
                        $count += ($count > 0) ? ($count * $interval) : 0;
                    }

                    $countNb = 1;
                    $offset = "+{$count} " . $this->frequencyConversion[$frequency];
                    $until = strtotime($offset, $startTimestamp);

                    if (in_array($frequency, array('MONTHLY', 'YEARLY'))
                        && isset($rrules['BYDAY']) && $rrules['BYDAY'] !== ''
                    ) {
                        $dtstart = date_create($anEvent['DTSTART']);

                        if (!$dtstart) {
                            continue;
                        }

                        for ($i = 1; $i <= $count; $i++) {
                            $dtstartClone = clone $dtstart;
                            $dtstartClone->modify('next ' . $this->frequencyConversion[$frequency]);
                            $offset = "{$this->convertDayOrdinalToPositive($dayNumber, $weekday, $dtstartClone)} {$this->weekdays[$weekday]} of " . $dtstartClone->format('F Y H:i:01');
                            $dtstart->modify($offset);
                        }

                        // Jumping X months forwards doesn't mean
                        // the end date will fall on the same day defined in BYDAY
                        // Use the largest of these to ensure we are going far enough
                        // in the future to capture our final end day
                        $until = max($until, $dtstart->format('U'));
                    }

                    unset($offset);
                } else {
                    $until = $untilDefault->getTimestamp();
                }

                // Decide how often to add events and do so
                switch ($frequency) {
                    case 'DAILY':
                        // Simply add a new event each interval of days until UNTIL is reached
                        $offset = "+{$interval} day";
                        $recurringTimestamp = strtotime($offset, $startTimestamp);

                        while ($recurringTimestamp <= $until) {
                            $dayRecurringTimestamp = $recurringTimestamp;

                            // Adjust time zone from initial event
                            $dayRecurringOffset = 0;
                            if ($this->useTimeZoneWithRRules) {
                                $recurringTimeZone = \DateTime::createFromFormat('U', $dayRecurringTimestamp);
                                $recurringTimeZone->setTimezone($initialStart->getTimezone());
                                $dayRecurringOffset = $recurringTimeZone->getOffset();
                                $dayRecurringTimestamp += $dayRecurringOffset;
                            }

                            // Add event
                            $anEvent['DTSTART'] = date(self::DATE_TIME_FORMAT, $dayRecurringTimestamp) . ($isAllDayEvent || ($initialStartTimeZoneName === 'Z') ? 'Z' : '');
                            $anEvent['DTSTART_array'][1] = $anEvent['DTSTART'];
                            $anEvent['DTSTART_array'][2] = $dayRecurringTimestamp;
                            $anEvent['DTEND_array']      = $anEvent['DTSTART_array'];
                            $anEvent['DTEND_array'][2]  += $eventTimestampOffset;
                            $anEvent['DTEND'] = date(
                                    self::DATE_TIME_FORMAT,
                                    $anEvent['DTEND_array'][2]
                                ) . ($isAllDayEvent || ($initialEndTimeZoneName === 'Z') ? 'Z' : '');
                            $anEvent['DTEND_array'][1] = $anEvent['DTEND'];

                            // Exclusions
                            $searchDate = $anEvent['DTSTART'];
                            if (isset($anEvent['DTSTART_array'][0]['TZID'])) {
                                $searchDate = 'TZID=' . $anEvent['DTSTART_array'][0]['TZID'] . ':' . $searchDate;
                            }
                            $isExcluded = array_filter($exdates, function ($exdate) use ($searchDate, $dayRecurringOffset) {
                                $a = $this->iCalDateToUnixTimestamp($searchDate);
                                $b = ($exdate + $dayRecurringOffset);

                                return $a === $b;
                            });

                            if (isset($anEvent['UID'])) {
                                if (isset($this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                    $searchDateUtc = $this->iCalDateToUnixTimestamp($searchDate, true, true);
                                    if (in_array($searchDateUtc, $this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                        $isExcluded = true;
                                    }
                                }
                            }

                            if (!$isExcluded) {
                                $events[] = $anEvent;
                                $this->eventCount++;

                                // If RRULE[COUNT] is reached then break
                                if (isset($rrules['COUNT'])) {
                                    $countNb++;

                                    if ($countNb >= $countOrig) {
                                        break;
                                    }
                                }
                            }

                            // Move forwards
                            $recurringTimestamp = strtotime($offset, $recurringTimestamp);
                        }
                        break;

                    case 'WEEKLY':
                        // Create offset
                        $offset = "+{$interval} week";

                        $wkst  = (isset($rrules['WKST']) && in_array($rrules['WKST'], array('SA', 'SU', 'MO'))) ? $rrules['WKST'] : $this->defaultWeekStart;
                        $aWeek = $this->weeks[$wkst];
                        $days  = array('SA' => 'Saturday', 'SU' => 'Sunday', 'MO' => 'Monday');

                        // Build list of days of week to add events
                        $weekdays = $aWeek;

                        if (isset($rrules['BYDAY']) && $rrules['BYDAY'] !== '') {
                            $byDays = explode(',', $rrules['BYDAY']);
                        } else {
                            // A textual representation of a day, two letters (e.g. SU)
                            $byDays = array(mb_substr(strtoupper($initialStart->format('D')), 0, 2));
                        }

                        // Get timestamp of first day of start week
                        $weekRecurringTimestamp = (strcasecmp($initialStart->format('l'), $this->weekdays[$wkst]) === 0)
                            ? $startTimestamp
                            : strtotime("last {$days[$wkst]} " . $initialStart->format('H:i:s'), $startTimestamp);

                        // Step through weeks
                        while ($weekRecurringTimestamp <= $until) {
                            $dayRecurringTimestamp = $weekRecurringTimestamp;

                            // Adjust time zone from initial event
                            $dayRecurringOffset = 0;
                            if ($this->useTimeZoneWithRRules) {
                                $dayRecurringTimeZone = \DateTime::createFromFormat('U', $dayRecurringTimestamp);
                                $dayRecurringTimeZone->setTimezone($initialStart->getTimezone());
                                $dayRecurringOffset = $dayRecurringTimeZone->getOffset();
                                $dayRecurringTimestamp += $dayRecurringOffset;
                            }

                            foreach ($weekdays as $day) {
                                // Check if day should be added
                                if (in_array($day, $byDays) && $dayRecurringTimestamp > $startTimestamp
                                    && $dayRecurringTimestamp <= $until
                                ) {
                                    // Add event
                                    $anEvent['DTSTART'] = date(self::DATE_TIME_FORMAT, $dayRecurringTimestamp) . ($isAllDayEvent || ($initialStartTimeZoneName === 'Z') ? 'Z' : '');
                                    $anEvent['DTSTART_array'][1] = $anEvent['DTSTART'];
                                    $anEvent['DTSTART_array'][2] = $dayRecurringTimestamp;
                                    $anEvent['DTEND_array']      = $anEvent['DTSTART_array'];
                                    $anEvent['DTEND_array'][2]  += $eventTimestampOffset;
                                    $anEvent['DTEND'] = date(
                                            self::DATE_TIME_FORMAT,
                                            $anEvent['DTEND_array'][2]
                                        ) . ($isAllDayEvent || ($initialEndTimeZoneName === 'Z') ? 'Z' : '');
                                    $anEvent['DTEND_array'][1] = $anEvent['DTEND'];

                                    // Exclusions
                                    $searchDate = $anEvent['DTSTART'];
                                    if (isset($anEvent['DTSTART_array'][0]['TZID'])) {
                                        $searchDate = 'TZID=' . $anEvent['DTSTART_array'][0]['TZID'] . ':' . $searchDate;
                                    }
                                    $isExcluded = array_filter($exdates, function ($exdate) use ($searchDate, $dayRecurringOffset) {
                                        $a = $this->iCalDateToUnixTimestamp($searchDate);
                                        $b = ($exdate + $dayRecurringOffset);

                                        return $a === $b;
                                    });

                                    if (isset($anEvent['UID'])) {
                                        if (isset($this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                            $searchDateUtc = $this->iCalDateToUnixTimestamp($searchDate, true, true);
                                            if (in_array($searchDateUtc, $this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                                $isExcluded = true;
                                            }
                                        }
                                    }

                                    if (!$isExcluded) {
                                        $events[] = $anEvent;
                                        $this->eventCount++;

                                        // If RRULE[COUNT] is reached then break
                                        if (isset($rrules['COUNT'])) {
                                            $countNb++;

                                            if ($countNb >= $countOrig) {
                                                break 2;
                                            }
                                        }
                                    }
                                }

                                // Move forwards a day
                                $dayRecurringTimestamp = strtotime('+1 day', $dayRecurringTimestamp);
                            }

                            // Move forwards $interval weeks
                            $weekRecurringTimestamp = strtotime($offset, $weekRecurringTimestamp);
                        }
                        break;

                    case 'MONTHLY':
                        // Create offset
                        $recurringTimestamp = $startTimestamp;
                        $offset = "+{$interval} month";

                        if (isset($rrules['BYMONTHDAY']) && $rrules['BYMONTHDAY'] !== '') {
                            // Deal with BYMONTHDAY
                            $monthdays = explode(',', $rrules['BYMONTHDAY']);

                            while ($recurringTimestamp <= $until) {
                                foreach ($monthdays as $key => $monthday) {
                                    if ($key === 0) {
                                        // Ensure original event conforms to monthday rule
                                        $anEvent['DTSTART'] = gmdate(
                                                'Ym' . sprintf('%02d', $monthday) . '\T' . self::TIME_FORMAT,
                                                strtotime($anEvent['DTSTART'])
                                            ) . ($isAllDayEvent || ($initialStartTimeZoneName === 'Z') ? 'Z' : '');

                                        $anEvent['DTEND'] = gmdate(
                                                'Ym' . sprintf('%02d', $monthday) . '\T' . self::TIME_FORMAT,
                                                isset($anEvent['DURATION'])
                                                    ? $this->parseDuration($anEvent['DTSTART'], end($anEvent['DURATION_array']))
                                                    : strtotime($anEvent['DTEND'])
                                            ) . ($isAllDayEvent || ($initialEndTimeZoneName === 'Z') ? 'Z' : '');

                                        $anEvent['DTSTART_array'][1] = $anEvent['DTSTART'];
                                        $anEvent['DTSTART_array'][2] = $this->iCalDateToUnixTimestamp($anEvent['DTSTART']);
                                        $anEvent['DTEND_array'][1]   = $anEvent['DTEND'];
                                        $anEvent['DTEND_array'][2]   = $this->iCalDateToUnixTimestamp($anEvent['DTEND']);

                                        // Ensure recurring timestamp confirms to BYMONTHDAY rule
                                        $monthRecurringTimestamp = $this->iCalDateToUnixTimestamp(
                                            gmdate(
                                                'Ym' . sprintf('%02d', $monthday) . '\T' . self::TIME_FORMAT,
                                                $recurringTimestamp
                                            ) . ($isAllDayEvent || ($initialStartTimeZoneName === 'Z') ? 'Z' : '')
                                        );
                                    }

                                    // Adjust time zone from initial event
                                    $monthRecurringOffset = 0;
                                    if ($this->useTimeZoneWithRRules) {
                                        $recurringTimeZone = \DateTime::createFromFormat('U', $monthRecurringTimestamp);
                                        $recurringTimeZone->setTimezone($initialStart->getTimezone());
                                        $monthRecurringOffset = $recurringTimeZone->getOffset();
                                        $monthRecurringTimestamp += $monthRecurringOffset;
                                    }

                                    // Add event
                                    $anEvent['DTSTART'] = date(
                                            'Ym' . sprintf('%02d', $monthday) . '\T' . self::TIME_FORMAT,
                                            $monthRecurringTimestamp
                                        ) . ($isAllDayEvent || ($initialStartTimeZoneName === 'Z') ? 'Z' : '');
                                    $anEvent['DTSTART_array'][1] = $anEvent['DTSTART'];
                                    $anEvent['DTSTART_array'][2] = $monthRecurringTimestamp;
                                    $anEvent['DTEND_array']      = $anEvent['DTSTART_array'];
                                    $anEvent['DTEND_array'][2]  += $eventTimestampOffset;
                                    $anEvent['DTEND'] = date(
                                            self::DATE_TIME_FORMAT,
                                            $anEvent['DTEND_array'][2]
                                        ) . ($isAllDayEvent || ($initialEndTimeZoneName === 'Z') ? 'Z' : '');
                                    $anEvent['DTEND_array'][1] = $anEvent['DTEND'];

                                    // Exclusions
                                    $searchDate = $anEvent['DTSTART'];
                                    if (isset($anEvent['DTSTART_array'][0]['TZID'])) {
                                        $searchDate = 'TZID=' . $anEvent['DTSTART_array'][0]['TZID'] . ':' . $searchDate;
                                    }
                                    $isExcluded = array_filter($exdates, function ($exdate) use ($searchDate, $monthRecurringOffset) {
                                        $a = $this->iCalDateToUnixTimestamp($searchDate);
                                        $b = ($exdate + $monthRecurringOffset);

                                        return $a === $b;
                                    });

                                    if (isset($anEvent['UID'])) {
                                        if (isset($this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                            $searchDateUtc = $this->iCalDateToUnixTimestamp($searchDate, true, true);
                                            if (in_array($searchDateUtc, $this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                                $isExcluded = true;
                                            }
                                        }
                                    }

                                    if (!$isExcluded) {
                                        $events[] = $anEvent;
                                        $this->eventCount++;

                                        // If RRULE[COUNT] is reached then break
                                        if (isset($rrules['COUNT'])) {
                                            $countNb++;

                                            if ($countNb >= $countOrig) {
                                                break 2;
                                            }
                                        }
                                    }
                                }

                                // Move forwards
                                $recurringTimestamp = strtotime($offset, $recurringTimestamp);
                            }
                        } elseif (isset($rrules['BYDAY']) && $rrules['BYDAY'] !== '') {
                            while ($recurringTimestamp <= $until) {
                                $monthRecurringTimestamp = $recurringTimestamp;

                                // Adjust time zone from initial event
                                $monthRecurringOffset = 0;
                                if ($this->useTimeZoneWithRRules) {
                                    $recurringTimeZone = \DateTime::createFromFormat('U', $monthRecurringTimestamp);
                                    $recurringTimeZone->setTimezone($initialStart->getTimezone());
                                    $monthRecurringOffset = $recurringTimeZone->getOffset();
                                    $monthRecurringTimestamp += $monthRecurringOffset;
                                }

                                $eventStartDesc = "{$this->convertDayOrdinalToPositive($dayNumber, $weekday, $monthRecurringTimestamp)} {$this->weekdays[$weekday]} of "
                                    . date('F Y H:i:s', $monthRecurringTimestamp);
                                $eventStartTimestamp = strtotime($eventStartDesc);

                                if (intval($rrules['BYDAY']) === 0) {
                                    $lastDayDesc = "last {$this->weekdays[$weekday]} of "
                                        . date('F Y H:i:s', $monthRecurringTimestamp);
                                } else {
                                    $lastDayDesc = "{$this->convertDayOrdinalToPositive($dayNumber, $weekday, $monthRecurringTimestamp)} {$this->weekdays[$weekday]} of "
                                        . date('F Y H:i:s', $monthRecurringTimestamp);
                                }
                                $lastDayTimestamp = strtotime($lastDayDesc);

                                do {
                                    // Prevent 5th day of a month from showing up on the next month
                                    // If BYDAY and the event falls outside the current month, skip the event

                                    $compareCurrentMonth = date('F', $monthRecurringTimestamp);
                                    $compareEventMonth   = date('F', $eventStartTimestamp);

                                    if ($eventStartTimestamp > $startTimestamp && $eventStartTimestamp < $until) {
                                        $anEvent['DTSTART'] = date(self::DATE_TIME_FORMAT, $eventStartTimestamp) . ($isAllDayEvent || ($initialStartTimeZoneName === 'Z') ? 'Z' : '');
                                        $anEvent['DTSTART_array'][1] = $anEvent['DTSTART'];
                                        $anEvent['DTSTART_array'][2] = $eventStartTimestamp;
                                        $anEvent['DTEND_array']      = $anEvent['DTSTART_array'];
                                        $anEvent['DTEND_array'][2]  += $eventTimestampOffset;
                                        $anEvent['DTEND'] = date(
                                                self::DATE_TIME_FORMAT,
                                                $anEvent['DTEND_array'][2]
                                            ) . ($isAllDayEvent || ($initialEndTimeZoneName === 'Z') ? 'Z' : '');
                                        $anEvent['DTEND_array'][1] = $anEvent['DTEND'];

                                        // Exclusions
                                        $searchDate = $anEvent['DTSTART'];
                                        if (isset($anEvent['DTSTART_array'][0]['TZID'])) {
                                            $searchDate = 'TZID=' . $anEvent['DTSTART_array'][0]['TZID'] . ':' . $searchDate;
                                        }
                                        $isExcluded = array_filter($exdates, function ($exdate) use ($searchDate, $monthRecurringOffset) {
                                            $a = $this->iCalDateToUnixTimestamp($searchDate);
                                            $b = ($exdate + $monthRecurringOffset);

                                            return $a === $b;
                                        });

                                        if (isset($anEvent['UID'])) {
                                            if (isset($this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                                $searchDateUtc = $this->iCalDateToUnixTimestamp($searchDate, true, true);
                                                if (in_array($searchDateUtc, $this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                                    $isExcluded = true;
                                                }
                                            }
                                        }

                                        if (!$isExcluded) {
                                            $events[] = $anEvent;
                                            $this->eventCount++;

                                            // If RRULE[COUNT] is reached then break
                                            if (isset($rrules['COUNT'])) {
                                                $countNb++;

                                                if ($countNb >= $countOrig) {
                                                    break 2;
                                                }
                                            }
                                        }
                                    }

                                    if (isset($rrules['BYSETPOS'])) {
                                        // BYSETPOS is defined so skip
                                        // looping through each week
                                        $lastDayTimestamp = $eventStartTimestamp;
                                    }

                                    $eventStartTimestamp += self::SECONDS_IN_A_WEEK;
                                } while ($eventStartTimestamp <= $lastDayTimestamp);

                                // Move forwards
                                $recurringTimestamp = strtotime($offset, $recurringTimestamp);
                            }
                        }
                        break;

                    case 'YEARLY':
                        // Create offset
                        $recurringTimestamp = $startTimestamp;
                        $offset = "+{$interval} year";

                        // Deal with BYMONTH
                        if (isset($rrules['BYMONTH']) && $rrules['BYMONTH'] !== '') {
                            $bymonths = explode(',', $rrules['BYMONTH']);
                        }

                        // Check if BYDAY rule exists
                        if (isset($rrules['BYDAY']) && $rrules['BYDAY'] !== '') {
                            while ($recurringTimestamp <= $until) {
                                $yearRecurringTimestamp = $recurringTimestamp;

                                // Adjust time zone from initial event
                                $yearRecurringOffset = 0;
                                if ($this->useTimeZoneWithRRules) {
                                    $recurringTimeZone = \DateTime::createFromFormat('U', $yearRecurringTimestamp);
                                    $recurringTimeZone->setTimezone($initialStart->getTimezone());
                                    $yearRecurringOffset = $recurringTimeZone->getOffset();
                                    $yearRecurringTimestamp += $yearRecurringOffset;
                                }

                                foreach ($bymonths as $bymonth) {
                                    $eventStartDesc = "{$this->convertDayOrdinalToPositive($dayNumber, $weekday, $yearRecurringTimestamp)} {$this->weekdays[$weekday]}"
                                        . " of {$this->monthNames[$bymonth]} "
                                        . gmdate('Y H:i:s', $yearRecurringTimestamp);
                                    $eventStartTimestamp = strtotime($eventStartDesc);

                                    if (intval($rrules['BYDAY']) === 0) {
                                        $lastDayDesc = "last {$this->weekdays[$weekday]}"
                                            . " of {$this->monthNames[$bymonth]} "
                                            . gmdate('Y H:i:s', $yearRecurringTimestamp);
                                    } else {
                                        $lastDayDesc = "{$this->convertDayOrdinalToPositive($dayNumber, $weekday, $yearRecurringTimestamp)} {$this->weekdays[$weekday]}"
                                            . " of {$this->monthNames[$bymonth]} "
                                            . gmdate('Y H:i:s', $yearRecurringTimestamp);
                                    }
                                    $lastDayTimestamp = strtotime($lastDayDesc);

                                    do {
                                        if ($eventStartTimestamp > $startTimestamp && $eventStartTimestamp < $until) {
                                            $anEvent['DTSTART'] = date(self::DATE_TIME_FORMAT, $eventStartTimestamp) . ($isAllDayEvent || ($initialStartTimeZoneName === 'Z') ? 'Z' : '');
                                            $anEvent['DTSTART_array'][1] = $anEvent['DTSTART'];
                                            $anEvent['DTSTART_array'][2] = $eventStartTimestamp;
                                            $anEvent['DTEND_array']      = $anEvent['DTSTART_array'];
                                            $anEvent['DTEND_array'][2]  += $eventTimestampOffset;
                                            $anEvent['DTEND'] = date(
                                                    self::DATE_TIME_FORMAT,
                                                    $anEvent['DTEND_array'][2]
                                                ) . ($isAllDayEvent || ($initialEndTimeZoneName === 'Z') ? 'Z' : '');
                                            $anEvent['DTEND_array'][1] = $anEvent['DTEND'];

                                            // Exclusions
                                            $searchDate = $anEvent['DTSTART'];
                                            if (isset($anEvent['DTSTART_array'][0]['TZID'])) {
                                                $searchDate = 'TZID=' . $anEvent['DTSTART_array'][0]['TZID'] . ':' . $searchDate;
                                            }
                                            $isExcluded = array_filter($exdates, function ($exdate) use ($searchDate, $yearRecurringOffset) {
                                                $a = $this->iCalDateToUnixTimestamp($searchDate);
                                                $b = ($exdate + $yearRecurringOffset);

                                                return $a === $b;
                                            });

                                            if (isset($anEvent['UID'])) {
                                                if (isset($this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                                    $searchDateUtc = $this->iCalDateToUnixTimestamp($searchDate, true, true);
                                                    if (in_array($searchDateUtc, $this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                                        $isExcluded = true;
                                                    }
                                                }
                                            }

                                            if (!$isExcluded) {
                                                $events[] = $anEvent;
                                                $this->eventCount++;

                                                // If RRULE[COUNT] is reached then break
                                                if (isset($rrules['COUNT'])) {
                                                    $countNb++;

                                                    if ($countNb >= $countOrig) {
                                                        break 3;
                                                    }
                                                }
                                            }
                                        }

                                        $eventStartTimestamp += self::SECONDS_IN_A_WEEK;
                                    } while ($eventStartTimestamp <= $lastDayTimestamp);
                                }

                                // Move forwards
                                $recurringTimestamp = strtotime($offset, $recurringTimestamp);
                            }
                        } else {
                            $day = $initialStart->format('d');

                            // Step through years
                            while ($recurringTimestamp <= $until) {
                                $yearRecurringTimestamp = $recurringTimestamp;

                                // Adjust time zone from initial event
                                $yearRecurringOffset = 0;
                                if ($this->useTimeZoneWithRRules) {
                                    $recurringTimeZone = \DateTime::createFromFormat('U', $yearRecurringTimestamp);
                                    $recurringTimeZone->setTimezone($initialStart->getTimezone());
                                    $yearRecurringOffset = $recurringTimeZone->getOffset();
                                    $yearRecurringTimestamp += $yearRecurringOffset;
                                }

                                $eventStartDescs = array();
                                if (isset($rrules['BYMONTH']) && $rrules['BYMONTH'] !== '') {
                                    foreach ($bymonths as $bymonth) {
                                        array_push($eventStartDescs, "$day {$this->monthNames[$bymonth]} " . gmdate('Y H:i:s', $yearRecurringTimestamp));
                                    }
                                } else {
                                    array_push($eventStartDescs, $day . gmdate('F Y H:i:s', $yearRecurringTimestamp));
                                }

                                foreach ($eventStartDescs as $eventStartDesc) {
                                    $eventStartTimestamp = strtotime($eventStartDesc);

                                    if ($eventStartTimestamp > $startTimestamp && $eventStartTimestamp < $until) {
                                        $anEvent['DTSTART'] = date(self::DATE_TIME_FORMAT, $eventStartTimestamp) . ($isAllDayEvent || ($initialStartTimeZoneName === 'Z') ? 'Z' : '');
                                        $anEvent['DTSTART_array'][1] = $anEvent['DTSTART'];
                                        $anEvent['DTSTART_array'][2] = $eventStartTimestamp;
                                        $anEvent['DTEND_array']      = $anEvent['DTSTART_array'];
                                        $anEvent['DTEND_array'][2]  += $eventTimestampOffset;
                                        $anEvent['DTEND'] = date(
                                                self::DATE_TIME_FORMAT,
                                                $anEvent['DTEND_array'][2]
                                            ) . ($isAllDayEvent || ($initialEndTimeZoneName === 'Z') ? 'Z' : '');
                                        $anEvent['DTEND_array'][1] = $anEvent['DTEND'];

                                        // Exclusions
                                        $searchDate = $anEvent['DTSTART'];
                                        if (isset($anEvent['DTSTART_array'][0]['TZID'])) {
                                            $searchDate = 'TZID=' . $anEvent['DTSTART_array'][0]['TZID'] . ':' . $searchDate;
                                        }
                                        $isExcluded = array_filter($exdates, function ($exdate) use ($searchDate, $yearRecurringOffset) {
                                            $a = $this->iCalDateToUnixTimestamp($searchDate);
                                            $b = ($exdate + $yearRecurringOffset);

                                            return $a === $b;
                                        });

                                        if (isset($anEvent['UID'])) {
                                            if (isset($this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                                $searchDateUtc = $this->iCalDateToUnixTimestamp($searchDate, true, true);
                                                if (in_array($searchDateUtc, $this->alteredRecurrenceInstances[$anEvent['UID']])) {
                                                    $isExcluded = true;
                                                }
                                            }
                                        }

                                        if (!$isExcluded) {
                                            $events[] = $anEvent;
                                            $this->eventCount++;

                                            // If RRULE[COUNT] is reached then break
                                            if (isset($rrules['COUNT'])) {
                                                $countNb++;

                                                if ($countNb >= $countOrig) {
                                                    break 2;
                                                }
                                            }
                                        }
                                    }
                                }

                                // Move forwards
                                $recurringTimestamp = strtotime($offset, $recurringTimestamp);
                            }
                        }
                        break;

                        $events = (isset($countOrig) && sizeof($events) > $countOrig) ? array_slice($events, 0, $countOrig) : $events; // Ensure we abide by COUNT if defined
                }
            }
        }

        $this->cal['VEVENT'] = $events;
    }

    /**
     * Processes date conversions using the time zone
     *
     * Add fields DTSTART_tz and DTEND_tz to each Event
     * These fields contain dates adapted to the calendar
     * time zone depending on the event TZID.
     *
     * @return mixed
     */
    protected function processDateConversions()
    {
        $events = (isset($this->cal['VEVENT'])) ? $this->cal['VEVENT'] : array();

        if (empty($events)) {
            return false;
        }

        foreach ($events as $key => $anEvent) {
            if (!$this->isValidDate($anEvent['DTSTART'])) {
                unset($events[$key]);
                $this->eventCount--;

                continue;
            }

            if ($this->useTimeZoneWithRRules && isset($anEvent['RRULE_array'][2]) && $anEvent['RRULE_array'][2] === self::RECURRENCE_EVENT) {
                $events[$key]['DTSTART_tz'] = $anEvent['DTSTART'];
                $events[$key]['DTEND_tz']   = $anEvent['DTEND'];
            } else {
                $forceTimeZone = true;
                $events[$key]['DTSTART_tz'] = $this->iCalDateWithTimeZone($anEvent, 'DTSTART', $forceTimeZone);

                if ($this->iCalDateWithTimeZone($anEvent, 'DTEND', $forceTimeZone)) {
                    $events[$key]['DTEND_tz'] = $this->iCalDateWithTimeZone($anEvent, 'DTEND', $forceTimeZone);
                } elseif ($this->iCalDateWithTimeZone($anEvent, 'DURATION', $forceTimeZone)) {
                    $events[$key]['DTEND_tz'] = $this->iCalDateWithTimeZone($anEvent, 'DURATION', $forceTimeZone);
                }
            }
        }

        $this->cal['VEVENT'] = $events;
    }

    /**
     * Returns an array of Events.
     * Every event is a class with the event
     * details being properties within it.
     *
     * @return array
     */
    public function events()
    {
        $array = $this->cal;
        $array = isset($array['VEVENT']) ? $array['VEVENT'] : array();
        $events = array();

        if (!empty($array)) {
            foreach ($array as $event) {
                $events[] = new Event($event);
            }
        }

        return $events;
    }

    /**
     * Returns the calendar name
     *
     * @return string
     */
    public function calendarName()
    {
        return isset($this->cal['VCALENDAR']['X-WR-CALNAME']) ? $this->cal['VCALENDAR']['X-WR-CALNAME'] : '';
    }

    /**
     * Returns the calendar description
     *
     * @return string
     */
    public function calendarDescription()
    {
        return isset($this->cal['VCALENDAR']['X-WR-CALDESC']) ? $this->cal['VCALENDAR']['X-WR-CALDESC'] : '';
    }

    /**
     * Returns the calendar time zone
     *
     * @return boolean $ignoreUtc
     * @return string
     */
    public function calendarTimeZone($ignoreUtc = false)
    {
        if (isset($this->cal['VCALENDAR']['X-WR-TIMEZONE'])) {
            $timeZone = $this->cal['VCALENDAR']['X-WR-TIMEZONE'];
        } elseif (isset($this->cal['VTIMEZONE']['TZID'])) {
            $timeZone = $this->cal['VTIMEZONE']['TZID'];
        } else {
            $timeZone = $this->defaultTimeZone;
        }

        // Use default time zone if the calendar's is invalid
        if (!$this->isValidTimeZoneId($timeZone)) {
            $timeZone = $this->defaultTimeZone;
        }

        if ($ignoreUtc && $timeZone === 'UTC') {
            return null;
        }

        return $timeZone;
    }

    /**
     * Returns an array of arrays with all free/busy events.
     * Every event is an associative array and each property
     * is an element it.
     *
     * @return array
     */
    public function freeBusyEvents()
    {
        $array = $this->cal;

        return isset($array['VFREEBUSY']) ? $array['VFREEBUSY'] : '';
    }

    /**
     * Returns a boolean value whether the
     * current calendar has events or not
     *
     * @return boolean
     */
    public function hasEvents()
    {
        return (count($this->events()) > 0) ? true : false;
    }

    /**
     * Returns a sorted array of the events in a given range,
     * or false if no events exist in the range.
     *
     * Events will be returned if the start or end date is contained within the
     * range (inclusive), or if the event starts before and end after the range.
     *
     * If a start date is not specified or of a valid format, then the start
     * of the range will default to the current time and date of the server.
     *
     * If an end date is not specified or of a valid format, then the end of
     * the range will default to the current time and date of the server,
     * plus 20 years.
     *
     * Note that this function makes use of Unix timestamps. This might be a
     * problem for events on, during, or after 29 Jan 2038.
     * See http://en.wikipedia.org/wiki/Unix_time#Representing_the_number
     *
     * @param  string $rangeStart Start date of the search range.
     * @param  string $rangeEnd   End date of the search range.
     * @return array
     * @throws Exception
     */
    public function eventsFromRange($rangeStart = false, $rangeEnd = false)
    {
        // Sort events before processing range
        $events = $this->sortEventsWithOrder($this->events(), SORT_ASC);

        if (empty($events)) {
            return array();
        }

        $extendedEvents = array();

        if ($rangeStart) {
            try {
                $rangeStart = new \DateTime($rangeStart, new \DateTimeZone($this->defaultTimeZone));
            } catch (\Exception $e) {
                error_log("ICal::eventsFromRange: Invalid date passed ({$rangeStart})");
                $rangeStart = false;
            }
        } else {
            $rangeStart = new \DateTime('now', new \DateTimeZone($this->defaultTimeZone));
        }

        if ($rangeEnd) {
            try {
                $rangeEnd = new \DateTime($rangeEnd, new \DateTimeZone($this->defaultTimeZone));
            } catch (\Exception $e) {
                error_log("ICal::eventsFromRange: Invalid date passed ({$rangeEnd})");
                $rangeEnd = false;
            }
        } else {
            $rangeEnd = new \DateTime('now', new \DateTimeZone($this->defaultTimeZone));
            $rangeEnd->modify('+20 years');
        }

        // If start and end are identical and are dates with no times...
        if ($rangeEnd->format('His') == 0 && $rangeStart->getTimestamp() == $rangeEnd->getTimestamp()) {
            $rangeEnd->modify('+1 day');
        }

        $rangeStart = $rangeStart->getTimestamp();
        $rangeEnd   = $rangeEnd->getTimestamp();

        foreach ($events as $anEvent) {
            $eventStart = $anEvent->dtstart_array[2];
            $eventEnd   = (isset($anEvent->dtend_array[2])) ? $anEvent->dtend_array[2] : null;

            if (($eventStart >= $rangeStart && $eventStart < $rangeEnd)         // Event start date contained in the range
                || ($eventEnd !== null
                    && (
                        ($eventEnd > $rangeStart && $eventEnd <= $rangeEnd)     // Event end date contained in the range
                        || ($eventStart < $rangeStart && $eventEnd > $rangeEnd) // Event starts before and finishes after range
                    )
                )
            ) {
                $extendedEvents[] = $anEvent;
            }
        }

        if (empty($extendedEvents)) {
            return array();
        }

        return $extendedEvents;
    }

    /**
     * Returns a sorted array of the events following a given string,
     * or false if no events exist in the range.
     *
     * @param  string $interval
     * @return array
     */
    public function eventsFromInterval($interval)
    {
        $rangeStart = new \DateTime('now', new \DateTimeZone($this->defaultTimeZone));
        $rangeEnd   = new \DateTime('now', new \DateTimeZone($this->defaultTimeZone));

        $dateInterval = \DateInterval::createFromDateString($interval);
        $rangeEnd->add($dateInterval);

        return $this->eventsFromRange($rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'));
    }

    /**
     * Sort events based on a given sort order
     *
     * @param  array   $events    An array of Events
     * @param  integer $sortOrder Either SORT_ASC, SORT_DESC, SORT_REGULAR, SORT_NUMERIC, SORT_STRING
     * @return array
     */
    public function sortEventsWithOrder(array $events, $sortOrder = SORT_ASC)
    {
        $extendedEvents = array();
        $timestamp      = array();

        foreach ($events as $key => $anEvent) {
            $extendedEvents[] = $anEvent;
            $timestamp[$key]  = $anEvent->dtstart_array[2];
        }

        array_multisort($timestamp, $sortOrder, $extendedEvents);

        return $extendedEvents;
    }

    /**
     * Check if a time zone is valid
     *
     * @param  string $timeZone
     * @return boolean
     */
    protected function isValidTimeZoneId($timeZone)
    {
        $valid = array();
        $tza   = timezone_abbreviations_list();

        foreach ($tza as $zone) {
            foreach ($zone as $item) {
                $valid[$item['timezone_id']] = true;
            }
        }

        unset($valid['']);

        if (isset($valid[$timeZone]) || in_array($timeZone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC))) {
            return true;
        }

        return false;
    }

    /**
     * Parse a duration and apply it to a date
     *
     * @param  string $date     A date to add a duration to
     * @param  string $duration A duration to parse
     * @param  string $format   The format to apply to the DateTime object
     * @return integer|DateTime
     */
    protected function parseDuration($date, $duration, $format = 'U')
    {
        $dateTime = date_create($date);
        $dateTime->modify($duration->y . ' year');
        $dateTime->modify($duration->m . ' month');
        $dateTime->modify($duration->d . ' day');
        $dateTime->modify($duration->h . ' hour');
        $dateTime->modify($duration->i . ' minute');
        $dateTime->modify($duration->s . ' second');

        if (is_null($format)) {
            $output = $dateTime;
        } else {
            $output = $dateTime->format($format);

            if ($format === 'U') {
                $output = intval($output);
            }
        }

        return $output;
    }

    /**
     * Get the number of days between a
     * start and end date
     *
     * @param  integer $days
     * @param  integer $start
     * @param  integer $end
     * @return integer
     */
    protected function numberOfDays($days, $start, $end)
    {
        $w       = array(date('w', $start), date('w', $end));
        $oneWeek = self::SECONDS_IN_A_WEEK;
        $x       = floor(($end - $start) / $oneWeek);
        $sum     = 0;

        for ($day = 0; $day < 7; ++$day) {
            if ($days & pow(2, $day)) {
                $sum += $x + (($w[0] > $w[1]) ? $w[0] <= $day || $day <= $w[1] : $w[0] <= $day && $day <= $w[1]);
            }
        }

        return $sum;
    }

    /**
     * Convert a negative day ordinal to
     * its equivalent positive form
     *
     * @param  integer $dayNumber
     * @param  integer $weekday
     * @param  integer $timestamp
     * @return string
     */
    protected function convertDayOrdinalToPositive($dayNumber, $weekday, $timestamp)
    {
        $dayNumber = empty($dayNumber) ? 1 : $dayNumber; // Returns 0 when no number defined in BYDAY

        $dayOrdinals = $this->dayOrdinals;

        // We only care about negative BYDAY values
        if ($dayNumber >= 1) {
            return $dayOrdinals[$dayNumber];
        }

        $timestamp = (is_object($timestamp)) ? $timestamp : \DateTime::createFromFormat('U', $timestamp);
        $start     = strtotime('first day of ' . $timestamp->format('F Y H:i:s'));
        $end       = strtotime('last day of '  . $timestamp->format('F Y H:i:s'));

        // Used with pow(2, X) so pow(2, 4) is THURSDAY
        $weekdays = array_flip(array_keys($this->weekdays));

        $numberOfDays = $this->numberOfDays(pow(2, $weekdays[$weekday]), $start, $end);

        // Create subset
        $dayOrdinals = array_slice($dayOrdinals, 0, $numberOfDays, true);

        // Reverse only the values
        $dayOrdinals = array_combine(array_keys($dayOrdinals), array_reverse(array_values($dayOrdinals)));

        return $dayOrdinals[$dayNumber * -1];
    }

    /**
     * Remove unprintable ASCII and UTF-8 characters
     *
     * @param  string $data
     * @return string
     */
    protected function removeUnprintableChars($data)
    {
        return preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $data);
    }

    /**
     * Replace all occurrences of the search string with the replacement string. Multibyte safe.
     *
     * @param  string|array $search  The value being searched for, otherwise known as the needle. An array may be used to designate multiple needles.
     * @param  string|array $replace The replacement value that replaces found search values. An array may be used to designate multiple replacements.
     * @param  string|array $subject The string or array being searched and replaced on, otherwise known as the haystack.
     *                               If subject is an array, then the search and replace is performed with every entry of subject, and the return value is an array as well.
     * @param  integer      $count   If passed, this will be set to the number of replacements performed.
     * @return array|string
     */
    protected function mb_str_replace($search, $replace, $subject, &$count = 0)
    {
        if (!is_array($subject)) {
            // Normalize $search and $replace so they are both arrays of the same length
            $searches     = is_array($search)  ? array_values($search)  : array($search);
            $replacements = is_array($replace) ? array_values($replace) : array($replace);
            $replacements = array_pad($replacements, count($searches), '');

            foreach ($searches as $key => $search) {
                $parts   = mb_split(preg_quote($search), $subject);
                $count  += count($parts) - 1;
                $subject = implode($replacements[$key], $parts);
            }
        } else {
            // Call mb_str_replace for each subject in array, recursively
            foreach ($subject as $key => $value) {
                $subject[$key] = $this->mb_str_replace($search, $replace, $value, $count);
            }
        }

        return $subject;
    }

    /**
     * Replace curly quotes and other special characters
     * with their standard equivalents
     *
     * @param  string $data
     * @return string
     */
    protected function cleanData($data)
    {
        $replacementChars = array(
            "\xe2\x80\x98" => "'",   // ‘
            "\xe2\x80\x99" => "'",   // ’
            "\xe2\x80\x9a" => "'",   // ‚
            "\xe2\x80\x9b" => "'",   // ‛
            "\xe2\x80\x9c" => '"',   // “
            "\xe2\x80\x9d" => '"',   // ”
            "\xe2\x80\x9e" => '"',   // „
            "\xe2\x80\x9f" => '"',   // ‟
            "\xe2\x80\x93" => '-',   // –
            "\xe2\x80\x94" => '--',  // —
            "\xe2\x80\xa6" => '...', // …
            "\xc2\xa0"     => ' ',
        );
        // Replace UTF-8 characters
        $cleanedData = strtr($data, $replacementChars);

        // Replace Windows-1252 equivalents
        $cleanedData = $this->mb_str_replace(array(chr(145), chr(146), chr(147), chr(148), chr(150), chr(151), chr(133), chr(194)), $replacementChars, $cleanedData);

        return $cleanedData;
    }

    /**
     * Parse a list of excluded dates
     * to be applied to an Event
     *
     * @param  array $event
     * @return array
     */
    public function parseExdates(array $event)
    {
        if (empty($event['EXDATE_array'])) {
            return array();
        } else {
            $exdates = $event['EXDATE_array'];
        }

        $output          = array();
        $currentTimeZone = $this->defaultTimeZone;

        foreach ($exdates as $subArray) {
            end($subArray);
            $finalKey = key($subArray);

            foreach ($subArray as $key => $value) {
                if ($key === 'TZID') {
                    $currentTimeZone = $subArray[$key];
                } else {
                    $icalDate = 'TZID=' . $currentTimeZone . ':' . $subArray[$key];
                    $output[] = $this->iCalDateToUnixTimestamp($icalDate);

                    if ($key === $finalKey) {
                        // Reset to default
                        $currentTimeZone = $this->defaultTimeZone;
                    }
                }
            }
        }

        return $output;
    }

    /**
     * Check if a date string is a valid date
     *
     * @param  string $value
     * @return boolean
     * @throws Exception
     */
    public function isValidDate($value)
    {
        if (!$value) {
            return false;
        }

        try {
            new \DateTime($value);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if filename exists as a file or URL
     *
     * @param  string $filename
     * @return boolean
     */
    protected function isFileOrUrl($filename)
    {
        return (file_exists($filename) || filter_var($filename, FILTER_VALIDATE_URL)) ?: false;
    }

    /**
     * Reads an entire file or URL into an array
     *
     * @param  string $filename
     * @return array
     * @throws Exception
     */
    protected function fileOrUrl($filename)
    {
        if (!$lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
            throw new \Exception("The file path or URL '{$filename}' does not exist.");
        }

        return $lines;
    }
}