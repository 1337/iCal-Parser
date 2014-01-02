# PHP iCal Parser

This project *parses* and *outputs* valid iCalendar files, as per 
[RFC 2445](https://tools.ietf.org/html/rfc2445) (1998) and 
[RFC 5545](https://tools.ietf.org/html/rfc5545) (2009).

## Versions

iCal-Parser supports PHP versions 5.2 and up.


## Installation

```
git clone --recursive git@github.com:1337/iCal-Parser.git
```


## Usage

### Add one item

    <?php
        require_once('ical.class.php');

        $a = new iCal();

        $a->addEvent('Lunch with Friends',          // event name
                     'It will be Vietnamese!',      // event description
                     strtotime('Thursday 12pm'),    // start time
                     strtotime('Thursday 12:55'));  // end time

        $a->outputFile();
    ?>

### Add many items

Consider using a MySQL database and call `addEvent` in a loop.


## Feature support

This is a simple project, designed for current and older PHP versions. 

As such, there is *deliberately* no namespacing, anonymous functions, and Composer support.

### Forking

Welcome to fork! Feel free to contribute. Add any feature you wish.


## Alternatives

* [eluceo/iCal](https://github.com/eluceo/iCal/): iCal for higher PHP versions and larger frameworks


## Licence

[MIT](LICENSE)