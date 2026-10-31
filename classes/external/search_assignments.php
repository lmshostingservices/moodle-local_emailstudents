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
 * External function: search assignments for the "not submitted" filter.
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
 * Search assignments by name or course name.
 */
class search_assignments extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search text'),
        ]);
    }

    /**
     * Search (site-level senders only; course senders get a fixed list).
     *
     * @param string $query
     * @return array
     */
    public static function execute(string $query): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/emailstudents:sendsite', $context);

        $q = '%' . $DB->sql_like_escape(trim($params['query'])) . '%';
        $like1 = $DB->sql_like('a.name', ':q1', false);
        $like2 = $DB->sql_like('c.shortname', ':q2', false);
        $like3 = $DB->sql_like('c.fullname', ':q3', false);
        $records = $DB->get_records_sql("SELECT a.id, a.name, c.shortname
                                           FROM {assign} a
                                           JOIN {course} c ON c.id = a.course
                                          WHERE ($like1 OR $like2 OR $like3)
                                       ORDER BY c.shortname, a.name",
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
            'id' => new external_value(PARAM_INT, 'Assignment id'),
            'name' => new external_value(PARAM_TEXT, 'Assignment name'),
            'coursename' => new external_value(PARAM_TEXT, 'Course short name'),
        ]));
    }
}
