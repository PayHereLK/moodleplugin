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
 * PayHere enrolment plugin.
 *
 * This plugin allows you to set up paid courses.
 *
 * @package    enrol_payhere
 * @copyright  2020 PayHere (Pvt.) Ltd.
 * @author     PayHere (Pvt.) Ltd. - based on code by Eugene Venter, Martin Dougiamas and others
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    //--- settings ------------------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_payhere_settings', '', get_string('pluginname_desc', 'enrol_payhere')));

    $settings->add(new admin_setting_configtext('enrol_payhere/payheremerchantid', get_string('merchant_id', 'enrol_payhere'), get_string('merchant_id_desc', 'enrol_payhere'), '', PARAM_INT));
    $settings->add(new admin_setting_configtext('enrol_payhere/payheremerchantsecret', get_string('merchant_secret', 'enrol_payhere'), get_string('merchant_secret_desc', 'enrol_payhere'), '', PARAM_ALPHANUM));
    $settings->add(new admin_setting_configcheckbox('enrol_payhere/payheresandbox', get_string('payheresandbox', 'enrol_payhere'), '', 0));

    $settings->add(new admin_setting_configcheckbox('enrol_payhere/mailstudents', get_string('mailstudents', 'enrol_payhere'), '', 0));

    $settings->add(new admin_setting_configcheckbox('enrol_payhere/mailteachers', get_string('mailteachers', 'enrol_payhere'), '', 0));

    $settings->add(new admin_setting_configcheckbox('enrol_payhere/mailadmins', get_string('mailadmins', 'enrol_payhere'), '', 0));

    $options = array(
        "0" => get_string('yes'),
        "1" => get_string('no'),
    );
    $safe_description = get_string('allowreenrol_desc', 'enrol_payhere');
    if ($safe_description == '[[ allowreenrol_desc ]]'){
        $safe_description = 'Allow users to re-enrol, even after their enrolment has expired. Only applies to users who\'s records are not removed after enrolment has expired';
    }
    $settings->add(new admin_setting_configselect(
            'enrol_payhere/allowreenrol', 
            get_string('allowreenrol', 'enrol_payhere'), 
            $safe_description,
            "1",
            $options
        )
    );

    // Note: let's reuse the ext sync constants and strings here, internally it is very similar,
    //       it describes what should happen when users are not supposed to be enrolled any more.
    $options = array(
        ENROL_EXT_REMOVED_KEEP           => get_string('extremovedkeep', 'enrol'),
        ENROL_EXT_REMOVED_SUSPENDNOROLES => get_string('extremovedsuspendnoroles', 'enrol'),
        ENROL_EXT_REMOVED_UNENROL        => get_string('extremovedunenrol', 'enrol'),
    );
    $settings->add(new admin_setting_configselect('enrol_payhere/expiredaction', get_string('expiredaction', 'enrol_payhere'), get_string('expiredaction_help', 'enrol_payhere'), ENROL_EXT_REMOVED_SUSPENDNOROLES, $options));

    //--- enrol instance defaults ----------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_payhere_defaults',
        get_string('enrolinstancedefaults', 'admin'), get_string('enrolinstancedefaults_desc', 'admin')));

    $options = array(ENROL_INSTANCE_ENABLED  => get_string('yes'),
                     ENROL_INSTANCE_DISABLED => get_string('no'));
    $settings->add(new admin_setting_configselect('enrol_payhere/status',
        get_string('status', 'enrol_payhere'), get_string('status_desc', 'enrol_payhere'), ENROL_INSTANCE_DISABLED, $options));

    $settings->add(new admin_setting_configtext('enrol_payhere/cost', get_string('cost', 'enrol_payhere'), '', 0, PARAM_FLOAT, 4));

    $payherecurrencies = enrol_get_plugin('payhere')->get_currencies();
    $settings->add(new admin_setting_configselect('enrol_payhere/currency', get_string('currency', 'enrol_payhere'), '', 'LKR', $payherecurrencies));

    if (!during_initial_install()) {
        $options = get_default_enrol_roles(context_system::instance());
        $student = get_archetype_roles('student');
        $student = reset($student);
        $settings->add(new admin_setting_configselect('enrol_payhere/roleid',
            get_string('defaultrole', 'enrol_payhere'), get_string('defaultrole_desc', 'enrol_payhere'), $student->id, $options));
    }

    $settings->add(new admin_setting_configduration('enrol_payhere/enrolperiod',
        get_string('enrolperiod', 'enrol_payhere'), get_string('enrolperiod_desc', 'enrol_payhere'), 0));
}
