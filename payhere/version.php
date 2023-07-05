<?php
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.

/**
 * PayHere enrolment plugin version specification.
 *
 * @package    enrol_payhere
 * @copyright  2020 PayHere (Pvt.) Ltd.
 * @author     PayHere (Pvt.) Ltd. - based on code by Eugene Venter, Martin Dougiamas and others
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2023011001;        // The current plugin version (Date: YYYYMMDDXX)
$plugin->requires  = 2017051500;        // Requires this Moodle version
$plugin->component = 'enrol_payhere';    // Full name of the plugin (used for diagnostics)
$plugin->cron      = 60;