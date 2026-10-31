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
 * Scheduled task that deletes old student email history.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents\task;

/**
 * Removes finished messages older than the retention setting.
 */
class cleanup extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcleanup', 'local_emailstudents');
    }

    /**
     * Delete old history.
     */
    public function execute() {
        global $DB;
        $days = (int) get_config('local_emailstudents', 'retentiondays');
        if ($days <= 0) {
            return;
        }
        $cutoff = time() - ($days * DAYSECS);
        $select = "status IN ('complete', 'cancelled') AND timecreated < :cutoff";
        $messages = $DB->get_records_select('local_emailstudents_msg', $select, ['cutoff' => $cutoff], '', 'id, contextid');
        $fs = get_file_storage();
        foreach ($messages as $message) {
            $fs->delete_area_files($message->contextid, 'local_emailstudents', false, $message->id);
            $DB->delete_records('local_emailstudents_rcpt', ['messageid' => $message->id]);
            $DB->delete_records('local_emailstudents_msg', ['id' => $message->id]);
        }
        mtrace('Deleted ' . count($messages) . ' old student email(s).');
    }
}
