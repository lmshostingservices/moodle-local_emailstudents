<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Shared helpers for local_emailstudents.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents;

use context;
use context_course;
use context_system;
use core_user;
use stdClass;

/**
 * Helper functions.
 */
class helper {
    /** Recipient row: waiting to be sent. */
    const RCPT_PENDING = 0;
    /** Recipient row: sent. */
    const RCPT_SENT = 1;
    /** Recipient row: the mail server refused it. */
    const RCPT_FAILED = 2;
    /** Recipient row: user no longer valid (deleted, suspended) when its turn came. */
    const RCPT_SKIPPED = 3;

    /** File areas. */
    const AREA_BODY = 'body';
    /** File areas. */
    const AREA_ATTACH = 'attachment';

    /**
     * Resolve the context a sender is working in and check they may send there.
     *
     * @param int $courseid 0 for site level.
     * @return array [context $context, stdClass|null $course]
     */
    public static function require_send_access(int $courseid): array {
        if ($courseid && $courseid != SITEID) {
            $course = get_course($courseid);
            require_login($course);
            $context = context_course::instance($course->id);
            if (!has_capability('local/emailstudents:sendsite', context_system::instance())) {
                require_capability('local/emailstudents:sendcourse', $context);
            }
            return [$context, $course];
        }
        require_login();
        $context = context_system::instance();
        require_capability('local/emailstudents:sendsite', $context);
        return [$context, null];
    }

    /**
     * Can the current user see this message in the history?
     *
     * @param stdClass $message
     * @return bool
     */
    public static function can_view_message(stdClass $message): bool {
        global $USER;
        if (has_capability('local/emailstudents:viewallhistory', context_system::instance())) {
            return true;
        }
        if ($message->senderid == $USER->id) {
            return true;
        }
        if ($message->courseid) {
            $context = context_course::instance($message->courseid, IGNORE_MISSING);
            return $context && has_capability('local/emailstudents:sendcourse', $context)
                && has_capability('moodle/course:viewparticipants', $context);
        }
        return false;
    }

    /**
     * Roles the admin allows as recipients, keyed by id with localised names.
     *
     * @return string[]
     */
    public static function get_allowed_roles(): array {
        $config = get_config('local_emailstudents', 'allowedroles');
        $all = self::get_course_roles();
        if ($config === false || $config === '') {
            return $all;
        }
        $ids = array_map('intval', explode(',', $config));
        return array_intersect_key($all, array_flip($ids));
    }

    /**
     * Roles that can be assigned in a course.
     *
     * @return string[] id => name
     */
    public static function get_course_roles(): array {
        $roles = get_roles_for_contextlevels(CONTEXT_COURSE);
        $names = role_fix_names(get_all_roles(), context_system::instance(), ROLENAME_ORIGINAL, true);
        $out = [];
        foreach ($roles as $roleid) {
            if (isset($names[$roleid])) {
                $out[(int) $roleid] = $names[$roleid];
            }
        }
        return $out;
    }

    /**
     * Ids of student-archetype roles, used when no role is chosen.
     *
     * @return int[]
     */
    public static function get_default_role_ids(): array {
        $ids = array_map('intval', array_keys(get_archetype_roles('student')));
        $allowed = array_keys(self::get_allowed_roles());
        $ids = array_values(array_intersect($ids, $allowed));
        return $ids ?: array_slice($allowed, 0, 1);
    }

    /**
     * Options for the last access filter.
     *
     * @return string[]
     */
    public static function lastaccess_options(): array {
        $options = [0 => get_string('lastaccess_any', 'local_emailstudents')];
        foreach ([7, 14, 30, 60, 90, 180] as $days) {
            $options[$days] = get_string('lastaccess_days', 'local_emailstudents', $days);
        }
        $options[recipients::LASTACCESS_NEVER] = get_string('lastaccess_never', 'local_emailstudents');
        return $options;
    }

    /**
     * From address choices.
     *
     * @return string[]
     */
    public static function from_options(): array {
        return [
            'noreply' => get_string('from_noreply', 'local_emailstudents', core_user::get_noreply_user()->email),
            'support' => get_string('from_support', 'local_emailstudents', core_user::get_support_user()->email),
            'sender' => get_string('from_sender', 'local_emailstudents'),
        ];
    }

    /**
     * Get the user object email_to_user() should send from.
     *
     * @param stdClass $message
     * @return stdClass
     */
    public static function get_from_user(stdClass $message): stdClass {
        global $DB;
        switch ($message->fromtype) {
            case 'support':
                return core_user::get_support_user();
            case 'sender':
                $sender = $DB->get_record('user', ['id' => $message->senderid, 'deleted' => 0]);
                if ($sender) {
                    return $sender;
                }
                return core_user::get_noreply_user();
            default:
                $noreply = core_user::get_noreply_user();
                $sender = $DB->get_record('user', ['id' => $message->senderid]);
                if ($sender) {
                    // Show the teacher's name with the no-reply address, as Moodle forums do.
                    $noreply->firstname = $sender->firstname;
                    $noreply->lastname = $sender->lastname;
                    foreach (\core_user\fields::get_name_fields() as $field) {
                        $noreply->$field = $sender->$field ?? '';
                    }
                }
                return $noreply;
        }
    }

    /**
     * Editor options for the message body.
     *
     * @param context $context
     * @return array
     */
    public static function editor_options(context $context): array {
        return [
            'maxfiles' => 50,
            'maxbytes' => self::max_attachment_bytes(),
            'trusttext' => false,
            'noclean' => false,
            'context' => $context,
            'subdirs' => false,
            'accepted_types' => ['web_image'],
        ];
    }

    /**
     * File manager options for attachments.
     *
     * @return array
     */
    public static function attachment_options(): array {
        return [
            'maxfiles' => (int) (get_config('local_emailstudents', 'maxattachments') ?: 5),
            'maxbytes' => self::max_attachment_bytes(),
            'subdirs' => 0,
            'accepted_types' => '*',
        ];
    }

    /**
     * Largest total attachment size allowed.
     *
     * @return int
     */
    public static function max_attachment_bytes(): int {
        $max = (int) get_config('local_emailstudents', 'maxattachbytes');
        return $max > 0 ? $max : 10 * 1024 * 1024;
    }

    /**
     * Placeholders, grouped for the compose page.
     *
     * @return array group label => tokens
     */
    public static function placeholder_groups(): array {
        $groups = [
            get_string('ph_learner', 'local_emailstudents') => ['{firstname}', '{lastname}', '{fullname}', '{email}',
                '{username}', '{idnumber}', '{city}', '{institution}', '{department}', '{groupname}'],
            get_string('ph_course', 'local_emailstudents') => ['{coursename}', '{courseurl}', '{coursestartdate}',
                '{courseenddate}', '{enrolstart}', '{enrolend}'],
            get_string('ph_progress', 'local_emailstudents') => ['{progress}', '{coursegrade}', '{completiondate}',
                '{lastaccess}', '{daysinactive}'],
            get_string('ph_sender', 'local_emailstudents') => ['{sendername}', '{senderemail}', '{sitename}', '{siteurl}',
                '{loginurl}'],
        ];
        $custom = [];
        foreach (self::custom_profile_fields() as $field) {
            $custom[] = '{profile_' . $field->shortname . '}';
        }
        if ($custom) {
            $groups[get_string('ph_profile', 'local_emailstudents')] = $custom;
        }
        return $groups;
    }

    /**
     * All placeholder tokens.
     *
     * @return string[]
     */
    public static function placeholders(): array {
        return array_merge(...array_values(self::placeholder_groups()));
    }

    /**
     * Custom user profile fields.
     *
     * @return \stdClass[]
     */
    public static function custom_profile_fields(): array {
        global $DB;
        static $fields = null;
        if ($fields === null) {
            $fields = $DB->get_records('user_info_field', null, 'sortorder', 'id, shortname, name, datatype');
        }
        return $fields;
    }

    /**
     * Options for the profile field filter.
     *
     * @return string[]
     */
    public static function profile_field_options(): array {
        $options = ['' => get_string('choosedots')];
        foreach (['city', 'institution', 'department', 'idnumber', 'country'] as $f) {
            $options['core:' . $f] = get_string($f);
        }
        foreach (self::custom_profile_fields() as $field) {
            $options['custom:' . $field->id] = format_string($field->name);
        }
        return $options;
    }

    /**
     * Options for the enrolment window filter.
     *
     * @return string[]
     */
    public static function enrolwindow_options(): array {
        $options = ['' => get_string('enrolwindow_any', 'local_emailstudents')];
        foreach ([7, 14, 30] as $d) {
            $options['start' . $d] = get_string('enrolwindow_start', 'local_emailstudents', $d);
        }
        foreach ([7, 14, 30, 60] as $d) {
            $options['end' . $d] = get_string('enrolwindow_end', 'local_emailstudents', $d);
        }
        return $options;
    }

    /**
     * Options for the "not active in the course" filter.
     *
     * @return string[]
     */
    public static function courseinactive_options(): array {
        $options = [0 => get_string('lastaccess_any', 'local_emailstudents')];
        foreach ([7, 14, 21, 30, 60] as $d) {
            $options[$d] = get_string('courseinactive_days', 'local_emailstudents', $d);
        }
        return $options;
    }

    /**
     * Enabled enrolment methods.
     *
     * @return string[]
     */
    public static function enrol_method_options(): array {
        $out = [];
        foreach (array_keys(enrol_get_plugins(true)) as $name) {
            if ($name === 'guest') {
                continue;
            }
            $out[$name] = get_string('pluginname', 'enrol_' . $name);
        }
        return $out;
    }

    /**
     * Show where outgoing mail goes, so the sender knows the site SMTP settings are used.
     *
     * @return string HTML
     */
    public static function mail_status(): string {
        global $CFG, $OUTPUT;
        $link = '';
        if (has_capability('moodle/site:config', context_system::instance())) {
            $link = ' ' . \html_writer::link(new \moodle_url('/admin/settings.php', ['section' => 'outgoingmailconfig']),
                get_string('outgoingmailconfig', 'core_admin'));
        }
        if (!empty($CFG->noemailever)) {
            return $OUTPUT->notification(get_string('mailstatus_disabled', 'local_emailstudents') . $link, 'error');
        }
        if (!empty($CFG->divertallemailsto)) {
            return $OUTPUT->notification(get_string('mailstatus_diverted', 'local_emailstudents',
                s($CFG->divertallemailsto)) . $link, 'warning');
        }
        if (empty($CFG->smtphosts)) {
            return $OUTPUT->notification(get_string('mailstatus_phpmail', 'local_emailstudents') . $link, 'warning');
        }
        if ($CFG->smtphosts === 'qmail') {
            return $OUTPUT->notification(get_string('mailstatus_qmail', 'local_emailstudents') . $link, 'info');
        }
        $hosts = s(preg_replace('/\s+/', '', $CFG->smtphosts));
        return $OUTPUT->notification(get_string('mailstatus_smtp', 'local_emailstudents', $hosts) . $link, 'info');
    }

    /**
     * Status label.
     *
     * @param string $status
     * @return string HTML badge
     */
    public static function status_badge(string $status): string {
        $classes = [
            'queued' => 'bg-info text-white',
            'scheduled' => 'bg-secondary text-white',
            'sending' => 'bg-primary text-white',
            'complete' => 'bg-success text-white',
            'cancelled' => 'bg-dark text-white',
        ];
        $class = $classes[$status] ?? 'bg-secondary text-white';
        return \html_writer::span(get_string('status_' . $status, 'local_emailstudents'), "badge $class");
    }

    /**
     * Display status of a message, treating future queued messages as scheduled.
     *
     * @param stdClass $message
     * @return string
     */
    public static function display_status(stdClass $message): string {
        if ($message->status === 'queued' && $message->timescheduled > time()) {
            return 'scheduled';
        }
        return $message->status;
    }

    /**
     * Queue the sending task for a message.
     *
     * @param stdClass $message
     * @param int|null $runat Time to run, defaults to the scheduled time or now.
     * @param bool $dedupe Skip if an identical task is already queued.
     */
    public static function queue(stdClass $message, ?int $runat = null, bool $dedupe = true): void {
        $task = new task\send_message();
        $task->set_custom_data(['messageid' => (int) $message->id]);
        $task->set_component('local_emailstudents');
        $runat = $runat ?? max(time(), (int) $message->timescheduled);
        // Cron picks up tasks whose run time is strictly in the past, so "now" means a second ago.
        $task->set_next_run_time($runat <= time() ? time() - 1 : $runat);
        \core\task\manager::queue_adhoc_task($task, $dedupe);
    }

    /**
     * Refresh the sent and failed totals on a message.
     *
     * @param int $messageid
     * @return stdClass The updated message.
     */
    public static function refresh_counts(int $messageid): stdClass {
        global $DB;
        $counts = $DB->get_records_sql_menu(
            'SELECT status, COUNT(1) FROM {local_emailstudents_rcpt} WHERE messageid = ? GROUP BY status', [$messageid]);
        $message = $DB->get_record('local_emailstudents_msg', ['id' => $messageid], '*', MUST_EXIST);
        $message->sentcount = (int) ($counts[self::RCPT_SENT] ?? 0);
        $message->failcount = (int) ($counts[self::RCPT_FAILED] ?? 0) + (int) ($counts[self::RCPT_SKIPPED] ?? 0);
        $message->timemodified = time();
        $DB->update_record('local_emailstudents_msg', $message);
        return $message;
    }
}
