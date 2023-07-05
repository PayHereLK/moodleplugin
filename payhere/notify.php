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
 * Listens for Payment Notification from PayHere
 *
 * This script waits for Payment notification from PayHere,
 * validates the payment using hashes and finally,
 * sets up the enrolment for that user.
 *
 * @package    enrol_payhere
 * @copyright  2020 PayHere (Pvt.) Ltd.
 * @author     PayHere (Pvt.) Ltd. - based on code by Eugene Venter, Martin Dougiamas and others
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Disable moodle specific debug messages and any errors in output,
// comment out when debugging or better look into error log!
define('NO_DEBUG_DISPLAY', true);

// @codingStandardsIgnoreLine This script does not require login.
require("../../config.php");
require_once("lib.php");
if(file_exists($CFG->libdir.'/eventslib.php'))
    require_once($CFG->libdir.'/eventslib.php');
require_once($CFG->libdir.'/enrollib.php');
require_once($CFG->libdir . '/filelib.php');

// PayHere does not like when we return error messages here,
// the custom handler just logs exceptions and stops.
set_exception_handler(\enrol_payhere\util::get_exception_handler());

/// Keep out casual intruders
if (empty($_POST) or !empty($_GET)) {
    http_response_code(400);
    throw new moodle_exception('invalidrequest', 'core_error');
}

/// Read all the data from PayHere and get it ready for later;
/// we expect only valid UTF-8 encoding, it is the responsibility
/// of user to set it up properly in PayHere business account,
/// it is documented in docs wiki.

$req = 'cmd=_notify-validate';

$data = new stdClass();

foreach ($_POST as $key => $value) {
    if ($key !== clean_param($key, PARAM_ALPHANUMEXT)) {
        throw new moodle_exception('invalidrequest', 'core_error', '', null, $key);
    }
    if (is_array($value)) {
        throw new moodle_exception('invalidrequest', 'core_error', '', null, 'Unexpected array param: '.$key);
    }
    $req .= "&$key=".urlencode($value);
    $data->$key = fix_utf8($value);
}

if (empty($data->custom_1)) {
    throw new moodle_exception('invalidrequest', 'core_error', '', null, 'Missing request param: custom');
}

$custom = explode('-', $data->custom_1);
unset($data->custom_1);

if (empty($custom) || count($custom) < 3) {
    throw new moodle_exception('invalidrequest', 'core_error', '', null, 'Invalid value of the request param: custom');
}

$data->userid           = (int)$custom[0];
$data->courseid         = (int)$custom[1];
$data->instanceid       = (int)$custom[2];
$data->timeupdated      = time();
$data->plugin_processed = 'reached-no';

// Required for message_send.
$PAGE->set_context(context_system::instance());

/// get the user and course records
$user = $DB->get_record("user", array("id" => $data->userid));
$course = $DB->get_record("course", array("id" => $data->courseid));
$context = context_course::instance($course->id, IGNORE_MISSING);


// Now that the course/context has been validated, we can set it. Not that it's wonderful
// to set contexts more than once but system->course switches are accepted.
// Required for message_send.
$PAGE->set_context($context);

$plugin_instance = $DB->get_record("enrol", array("id" => $data->instanceid, "enrol" => "payhere", "status" => 0));

$plugin = enrol_get_plugin('payhere');

/// Perform local md5 validation

$result = 'INVALID';
$merchant_secret = $plugin->get_config('payheremerchantsecret');
$local_md5sig = strtoupper (md5 ( 
    $data->merchant_id . 
    $data->order_id . 
    $data->payhere_amount . 
    $data->payhere_currency . 
    $data->status_code . 
    strtoupper(md5($merchant_secret)) 
    ) 
);
$data->local_md5sig = $local_md5sig;
$remote_mdg5sig = $data->md5sig;

if ($local_md5sig == $remote_mdg5sig){
    $result = 'VERIFIED';
}

if (strlen($result) > 0) {
    if (strcmp($result, "VERIFIED") == 0) {          // VALID PAYMENT!

        // check the payment_status and payment_reason

        // If status is not completed or pending then unenrol the student if already enrolled
        // and notify admin

        $payment_status_success = 2;
        $payment_status_pending = 0;
        $payment_status_canceled = -1;
        $payment_status_failed = -2;
        $payment_status_chargedback = -3;

        if ($data->status_code != $payment_status_success and $data->status_code != $payment_status_pending) {
            $plugin->unenrol_user($plugin_instance, $data->userid);
            \enrol_payherel\util::message_payhere_error_to_admin(
                "Status not completed or pending. User unenrolled from course",
                $data
            );
            die;
        }

        // If currency is incorrectly set then someone maybe trying to cheat the system

        if ($data->payhere_currency != $plugin_instance->currency) {
            \enrol_payhere\util::message_payhere_error_to_admin(
                "Currency does not match course settings, received: ".$data->payhere_currency,
                $data);
            die;
        }

        // If status is pending and reason is other than echeck then we are on hold until further notice
        // Email user to let them know. Email admin.

        if ($data->status_code == $payment_status_pending) {
            $eventdata = new \core\message\message();
            $eventdata->courseid          = empty($data->courseid) ? SITEID : $data->courseid;
            $eventdata->modulename        = 'moodle';
            $eventdata->component         = 'enrol_payhere';
            $eventdata->name              = 'payhere_enrolment';
            $eventdata->userfrom          = get_admin();
            $eventdata->userto            = $user;
            $eventdata->subject           = "Moodle: PayHere payment";
            $eventdata->fullmessage       = "Your PayHere payment is pending.";
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml   = '';
            $eventdata->smallmessage      = '';
            message_send($eventdata);

            \enrol_payhere\util::message_payhere_error_to_admin("Payment pending", $data);
            die;
        }

        // If our status is not completed or not pending on an echeck clearance then ignore and die
        // This check is redundant at present but may be useful if payhere extend the return codes in the future

        if (! ( $data->status_code == $payment_status_success or
               ($data->status_code == $payment_status_pending) ) ) {
            die;
        }

        // At this point we only proceed with a status of completed

        // Make sure this transaction doesn't exist already.
        if ($existing = $DB->get_record("enrol_payhere", array("payment_id" => $data->payment_id))) {
            \enrol_payhere\util::message_payhere_error_to_admin("Transaction $data->payment_id is being repeated!", $data);
            die;
        }

        // Check that the receiver email is the one we want it to be.
        if (isset($data->merchant_id)) {
            $recipient = $data->business;
        } else if (isset($data->receiver_email)) {
            $recipient = $data->receiver_email;
        } else {
            $recipient = 'empty';
        }

        if (core_text::strtolower($data->merchant_id) !== core_text::strtolower($plugin->get_config('payheremerchantid'))) {
            \enrol_payhere\util::message_payhere_error_to_admin("Merchant ID {$data->merchant_id} (not ".
                    $plugin->get_config('payheremerchantid').")", $data);
            die;
        }

        if (!$user = $DB->get_record('user', array('id'=>$data->userid))) {   // Check that user exists
            \enrol_payhere\util::message_payhere_error_to_admin("User $data->userid doesn't exist", $data);
            die;
        }

        if (!$course = $DB->get_record('course', array('id'=>$data->courseid))) { // Check that course exists
            \enrol_payhere\util::message_payhere_error_to_admin("Course $data->courseid doesn't exist", $data);
            die;
        }

        $coursecontext = context_course::instance($course->id, IGNORE_MISSING);

        // Check that amount paid is the correct amount
        if ( (float) $plugin_instance->cost <= 0 ) {
            $cost = (float) $plugin->get_config('cost');
        } else {
            $cost = (float) $plugin_instance->cost;
        }

        // Use the same rounding of floats as on the enrol form.
        $cost = format_float($cost, 2, false);

        if ($data->payhere_amount < $cost) {
            \enrol_payhere\util::message_payhere_error_to_admin("Amount paid is not enough ($data->payhere_amount < $cost))", $data);
            die;

        }
        // Use the queried course's full name for the item_name field.
        $data->item_name = $course->fullname;

        // ALL CLEAR !

        $data->plugin_processed = 'yes';
        $DB->insert_record("enrol_payhere", $data);

        if ($plugin_instance->enrolperiod) {
            $timestart = time();
            $timeend   = $timestart + $plugin_instance->enrolperiod;
        } else {
            $timestart = 0;
            $timeend   = 0;
        }

        // Enrol user
        $plugin->enrol_user($plugin_instance, $user->id, $plugin_instance->roleid, $timestart, $timeend);

        // Pass $view=true to filter hidden caps if the user cannot see them
        if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
                                             '', '', '', '', false, true)) {
            $users = sort_by_roleassignment_authority($users, $context);
            $teacher = array_shift($users);
        } else {
            $teacher = false;
        }

        $mailstudents = $plugin->get_config('mailstudents');
        $mailteachers = $plugin->get_config('mailteachers');
        $mailadmins   = $plugin->get_config('mailadmins');
        $shortname = format_string($course->shortname, true, array('context' => $context));


        if (!empty($mailstudents)) {
            $a = new stdClass();
            $a->coursename = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id";

            $eventdata = new \core\message\message();
            $eventdata->courseid          = $course->id;
            $eventdata->modulename        = 'moodle';
            $eventdata->component         = 'enrol_payhere';
            $eventdata->name              = 'payhere_enrolment';
            $eventdata->userfrom          = empty($teacher) ? core_user::get_noreply_user() : $teacher;
            $eventdata->userto            = $user;
            $eventdata->subject           = get_string("enrolmentnew", 'enrol', $shortname);
            $eventdata->fullmessage       = get_string('welcometocoursetext', '', $a);
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml   = '';
            $eventdata->smallmessage      = '';
            message_send($eventdata);

        }

        if (!empty($mailteachers) && !empty($teacher)) {
            $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->user = fullname($user);

            $eventdata = new \core\message\message();
            $eventdata->courseid          = $course->id;
            $eventdata->modulename        = 'moodle';
            $eventdata->component         = 'enrol_payhere';
            $eventdata->name              = 'payhere_enrolment';
            $eventdata->userfrom          = $user;
            $eventdata->userto            = $teacher;
            $eventdata->subject           = get_string("enrolmentnew", 'enrol', $shortname);
            $eventdata->fullmessage       = get_string('enrolmentnewuser', 'enrol', $a);
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml   = '';
            $eventdata->smallmessage      = '';
            message_send($eventdata);
        }

        if (!empty($mailadmins)) {
            $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->user = fullname($user);
            $admins = get_admins();
            foreach ($admins as $admin) {
                $eventdata = new \core\message\message();
                $eventdata->courseid          = $course->id;
                $eventdata->modulename        = 'moodle';
                $eventdata->component         = 'enrol_payhere';
                $eventdata->name              = 'payhere_enrolment';
                $eventdata->userfrom          = $user;
                $eventdata->userto            = $admin;
                $eventdata->subject           = get_string("enrolmentnew", 'enrol', $shortname);
                $eventdata->fullmessage       = get_string('enrolmentnewuser', 'enrol', $a);
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml   = '';
                $eventdata->smallmessage      = '';
                message_send($eventdata);
            }
        }

    } else if (strcmp ($result, "INVALID") == 0) { // ERROR
        $DB->insert_record("enrol_payhere", $data, false);
        throw new moodle_exception('erripninvalid', 'enrol_payhere', '', null, json_encode($data));
    }
}

exit;
