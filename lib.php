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
 * Library callbacks for local_emailstudents.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add "Email students" to the course navigation (the course "More" menu).
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context $context
 */
function local_emailstudents_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context) {
    if ($course->id == SITEID) {
        return;
    }
    if (!has_capability('local/emailstudents:sendcourse', $context)
            && !has_capability('local/emailstudents:sendsite', context_system::instance())) {
        return;
    }
    $navigation->add(
        get_string('coursenav', 'local_emailstudents'),
        new moodle_url('/local/emailstudents/compose.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_emailstudents',
        new pix_icon('t/email', '')
    );
}

/**
 * Serve body images (public, so they show in recipients' mail clients) and
 * attachments (only to the sender, managers and the people it was sent to).
 *
 * @param stdClass $course
 * @param stdClass|null $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_emailstudents_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if (!in_array($context->contextlevel, [CONTEXT_SYSTEM, CONTEXT_COURSE])) {
        return false;
    }
    if (!in_array($filearea, ['body', 'attachment', 'template'])) {
        return false;
    }
    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    if ($filearea === 'template') {
        // Template images: only images, so they render in the editor and in sent emails.
        $file = get_file_storage()->get_file($context->id, 'local_emailstudents', 'template', $itemid, $filepath, $filename);
        if (!$file || $file->is_directory() || strpos((string) $file->get_mimetype(), 'image/') !== 0) {
            return false;
        }
        send_stored_file($file, DAYSECS, 0, false, $options);
        return true;
    }

    $message = $DB->get_record('local_emailstudents_msg', ['id' => $itemid, 'contextid' => $context->id]);
    if (!$message) {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_emailstudents', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    if ($filearea === 'body') {
        // Images inside the email must load in Gmail, Outlook and phones without a Moodle login.
        if (strpos((string) $file->get_mimetype(), 'image/') !== 0) {
            return false;
        }
        send_stored_file($file, DAYSECS, 0, false, $options);
        return true;
    }

    require_login(null, false);
    $allowed = $message->senderid == $USER->id
        || has_capability('local/emailstudents:viewallhistory', context_system::instance())
        || $DB->record_exists('local_emailstudents_rcpt', ['messageid' => $itemid, 'userid' => $USER->id]);
    if (!$allowed && $message->courseid) {
        $coursecontext = context_course::instance($message->courseid, IGNORE_MISSING);
        $allowed = $coursecontext && has_capability('local/emailstudents:sendcourse', $coursecontext);
    }
    if (!$allowed) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
    return true;
}
