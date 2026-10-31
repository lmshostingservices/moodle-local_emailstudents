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
 * External function: live recipient count for the compose page.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_emailstudents\recipients;

/**
 * Count and sample recipients for a set of filters.
 */
class count_recipients extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course the sender works in (0 = site)', VALUE_DEFAULT, 0),
            'filters' => new external_value(PARAM_RAW, 'JSON encoded filter values'), // pipeline-ignore: PARAM_RAW — JSON is decoded then normalised by recipients before use.
        ]);
    }

    /**
     * Count.
     *
     * @param int $courseid
     * @param string $filters
     * @return array
     */
    public static function execute(int $courseid, string $filters): array {
        global $PAGE;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'filters' => $filters]);
        $courseid = $params['courseid'];

        if ($courseid) {
            $context = \context_course::instance($courseid);
            self::validate_context($context);
            if (!has_capability('local/emailstudents:sendsite', \context_system::instance())) {
                require_capability('local/emailstudents:sendcourse', $context);
            }
        } else {
            $context = \context_system::instance();
            self::validate_context($context);
            require_capability('local/emailstudents:sendsite', $context);
        }

        $data = json_decode($params['filters'], true) ?: [];
        $normalised = recipients::normalise($data, $courseid);
        $count = recipients::count($normalised);

        $sample = [];
        foreach (recipients::sample($normalised, 6) as $user) {
            $name = fullname($user);
            $initials = \core_text::strtoupper(\core_text::substr($user->firstname ?? '', 0, 1) .
                \core_text::substr($user->lastname ?? '', 0, 1));
            $sample[] = ['fullname' => $name, 'email' => $user->email, 'initials' => $initials ?: '?'];
        }
        $max = (int) get_config('local_emailstudents', 'maxrecipients');
        return [
            'count' => $count,
            'countlabel' => get_string('recipientcount', 'local_emailstudents', number_format($count)),
            'overlimit' => $max > 0 && $count > $max,
            'limit' => $max,
            'sample' => $sample,
            'more' => max(0, $count - count($sample)),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Recipients'),
            'countlabel' => new external_value(PARAM_TEXT, 'Label'),
            'overlimit' => new external_value(PARAM_BOOL, 'Over the per-message limit'),
            'limit' => new external_value(PARAM_INT, 'Limit, 0 = none'),
            'sample' => new external_multiple_structure(new external_single_structure([
                'fullname' => new external_value(PARAM_TEXT, 'Name'),
                'email' => new external_value(PARAM_TEXT, 'Email'),
                'initials' => new external_value(PARAM_TEXT, 'Initials'),
            ])),
            'more' => new external_value(PARAM_INT, 'Recipients not shown'),
        ]);
    }
}
