<?php
// Function to format time to 12-hour format with AM/PM// This function will format the time value in 'HH:MM' format
function formatTime($time) {
    if (empty($time)) {
        return '';
    }
    $time = new DateTime($time);
    return $time->format('g:i A'); // This will return time with AM/PM
}


?>
