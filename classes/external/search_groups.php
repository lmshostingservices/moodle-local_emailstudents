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
 * External function: search groups for the recipient filter.
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

/**
 * Search groups by name or course name.
 */
class search_groups extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search text'),
            'courseid' => new external_value(PARAM_INT, 'Limit to this course (0 = site)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Search.
     *
     * @param string $query
     * @param int $courseid
     * @return array
     */
    public static function execute(string $query, int $courseid = 0): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query, 'courseid' => $courseid]);
        $query = trim($params['query']);
        $courseid = $params['courseid'];

        if ($courseid) {
            $context = \context_course::instance($courseid);
            self::validate_context($context);
            if (!has_capability('local/emailstudents:sendsite', \context_system::instance())) {
                require_capability('local/emailstudents:sendcourse', $context);
            }
            $allowed = \local_emailstudents\recipients::restrict_groups_to_course($courseid, [], $USER->id);
            $groups = groups_get_all_groups($courseid);
            if ($allowed) {
                $groups = array_intersect_key($groups, array_flip($allowed));
            }
            $course = get_course($courseid);
            $out = [];
            foreach ($groups as $g) {
                if ($query === '' || \core_text::strpos(\core_text::strtolower($g->name), \core_text::strtolower($query)) !== false) {
                    $out[] = ['id' => $g->id, 'name' => format_string($g->name, true, ['context' => $context]),
                        'coursename' => format_string($course->shortname, true, ['context' => $context])];
                }
            }
            return array_slice($out, 0, 100);
        }

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/emailstudents:sendsite', $context);

        $like1 = $DB->sql_like('g.name', ':q1', false);
        $like2 = $DB->sql_like('c.shortname', ':q2', false);
        $like3 = $DB->sql_like('c.fullname', ':q3', false);
        $q = '%' . $DB->sql_like_escape($query) . '%';
        $records = $DB->get_records_sql("SELECT g.id, g.name, c.shortname
                                           FROM {groups} g
                                           JOIN {course} c ON c.id = g.courseid
                                          WHERE ($like1 OR $like2 OR $like3)
                                       ORDER BY c.shortname, g.name",
            ['q1' => $q, 'q2' => $q, 'q3' => $q], 0, 100);
        $out = [];
        foreach ($records as $r) {
            $out[] = ['id' => $r->id, 'name' => format_string($r->name), 'coursename' => format_string($r->shortname)];
        }
        return $out;
    }

    /**
     * Return structure.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Group id'),
            'name' => new external_value(PARAM_TEXT, 'Group name'),
            'coursename' => new external_value(PARAM_TEXT, 'Course short name'),
        ]));
    }
}
