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
 * Web service definitions for local_emailstudents.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_emailstudents_search_groups' => [
        'classname' => \local_emailstudents\external\search_groups::class,
        'description' => 'Search groups the current user may target with a student email.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_emailstudents_count_recipients' => [
        'classname' => \local_emailstudents\external\count_recipients::class,
        'description' => 'Count recipients matching student email filters.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_emailstudents_search_assignments' => [
        'classname' => \local_emailstudents\external\search_assignments::class,
        'description' => 'Search assignments for the student email "not submitted" filter.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
