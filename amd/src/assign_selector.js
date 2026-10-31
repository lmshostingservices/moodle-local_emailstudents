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
 * Ajax transport for the assignment autocomplete in the student email form.
 *
 * @module     local_emailstudents/assign_selector
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Fetch matching assignments.
 *
 * @param {String} selector Original select element selector.
 * @param {String} query Search text.
 * @param {Function} success
 * @param {Function} failure
 */
export const transport = (selector, query, success, failure) => {
    Ajax.call([{
        methodname: 'local_emailstudents_search_assignments',
        args: {query: query},
    }])[0].then(success).catch(failure);
};

/**
 * Turn results into autocomplete options.
 *
 * @param {String} selector
 * @param {Array} results
 * @returns {Array}
 */
export const processResults = (selector, results) => results.map((assign) => ({
    value: assign.id,
    label: assign.coursename + ': ' + assign.name,
}));
