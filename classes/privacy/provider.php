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
 * Privacy provider for local_emailstudents.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_emailstudents_msg', [
            'senderid' => 'privacy:metadata:msg:senderid',
            'subject' => 'privacy:metadata:msg:subject',
            'body' => 'privacy:metadata:msg:body',
            'replyto' => 'privacy:metadata:msg:replyto',
            'timecreated' => 'privacy:metadata:msg:timecreated',
        ], 'privacy:metadata:msg');
        $collection->add_database_table('local_emailstudents_rcpt', [
            'userid' => 'privacy:metadata:rcpt:userid',
            'status' => 'privacy:metadata:rcpt:status',
            'timesent' => 'privacy:metadata:rcpt:timesent',
        ], 'privacy:metadata:rcpt');
        $collection->add_database_table('local_emailstudents_tpl', [
            'userid' => 'privacy:metadata:tpl:userid',
            'name' => 'privacy:metadata:tpl:name',
            'subject' => 'privacy:metadata:tpl:subject',
            'body' => 'privacy:metadata:tpl:body',
        ], 'privacy:metadata:tpl');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');
        $collection->add_external_location_link('email', [
            'email' => 'privacy:metadata:email:email',
            'firstname' => 'privacy:metadata:email:firstname',
            'lastname' => 'privacy:metadata:email:lastname',
        ], 'privacy:metadata:email');
        return $collection;
    }

    /**
     * Contexts holding data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_from_sql('SELECT contextid FROM {local_emailstudents_msg} WHERE senderid = :userid',
            ['userid' => $userid]);
        $list->add_from_sql('SELECT m.contextid
                               FROM {local_emailstudents_msg} m
                               JOIN {local_emailstudents_rcpt} r ON r.messageid = m.id
                              WHERE r.userid = :userid', ['userid' => $userid]);
        $list->add_from_sql('SELECT ctx.id FROM {context} ctx
                              WHERE ctx.contextlevel = :sys
                                AND EXISTS (SELECT 1 FROM {local_emailstudents_tpl} t WHERE t.userid = :userid)',
            ['sys' => CONTEXT_SYSTEM, 'userid' => $userid]);
        return $list;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $params = ['contextid' => $userlist->get_context()->id];
        $userlist->add_from_sql('senderid', 'SELECT senderid FROM {local_emailstudents_msg} WHERE contextid = :contextid',
            $params);
        $userlist->add_from_sql('userid', 'SELECT r.userid
                                             FROM {local_emailstudents_rcpt} r
                                             JOIN {local_emailstudents_msg} m ON m.id = r.messageid
                                            WHERE m.contextid = :contextid', $params);
        if ($userlist->get_context()->contextlevel == CONTEXT_SYSTEM) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_emailstudents_tpl} WHERE userid > 0', []);
        }
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $sent = $DB->get_records('local_emailstudents_msg', ['contextid' => $context->id, 'senderid' => $userid]);
            $out = [];
            foreach ($sent as $m) {
                $out[] = [
                    'subject' => $m->subject,
                    'body' => writer::with_context($context)->rewrite_pluginfile_urls(
                        [get_string('pluginname', 'local_emailstudents'), $m->id], 'local_emailstudents', 'body', $m->id, $m->body),
                    'recipients' => $m->total,
                    'timecreated' => transform::datetime($m->timecreated),
                ];
                writer::with_context($context)->export_area_files(
                    [get_string('pluginname', 'local_emailstudents'), $m->id], 'local_emailstudents', 'body', $m->id);
                writer::with_context($context)->export_area_files(
                    [get_string('pluginname', 'local_emailstudents'), $m->id], 'local_emailstudents', 'attachment', $m->id);
            }
            $received = $DB->get_records_sql('SELECT r.id, m.subject, r.status, r.timesent
                                                FROM {local_emailstudents_rcpt} r
                                                JOIN {local_emailstudents_msg} m ON m.id = r.messageid
                                               WHERE m.contextid = :contextid AND r.userid = :userid',
                ['contextid' => $context->id, 'userid' => $userid]);
            $in = [];
            foreach ($received as $r) {
                $in[] = [
                    'subject' => $r->subject,
                    'status' => $r->status,
                    'timesent' => $r->timesent ? transform::datetime($r->timesent) : '-',
                ];
            }
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $tpls = $DB->get_records('local_emailstudents_tpl', ['userid' => $userid]);
                foreach ($tpls as $t) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_emailstudents'), get_string('tab_templates', 'local_emailstudents'), $t->id],
                        (object) ['name' => $t->name, 'subject' => $t->subject, 'body' => $t->body,
                            'timecreated' => transform::datetime($t->timecreated)]);
                }
            }
            if ($out || $in) {
                writer::with_context($context)->export_data([get_string('pluginname', 'local_emailstudents')],
                    (object) ['sent' => $out, 'received' => $in]);
            }
        }
    }

    /**
     * Delete everything in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        $ids = $DB->get_fieldset_select('local_emailstudents_msg', 'id', 'contextid = ?', [$context->id]);
        self::delete_messages($context, $ids);
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            foreach ($DB->get_fieldset_select('local_emailstudents_tpl', 'id', 'userid > 0') as $tid) {
                \local_emailstudents\templates::delete($tid);
            }
        }
    }

    /**
     * Delete one user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                foreach ($DB->get_fieldset_select('local_emailstudents_tpl', 'id', 'userid = ?', [$userid]) as $tid) {
                    \local_emailstudents\templates::delete($tid);
                }
            }
            $ids = $DB->get_fieldset_select('local_emailstudents_msg', 'id', 'contextid = ? AND senderid = ?',
                [$context->id, $userid]);
            self::delete_messages($context, $ids);
            $msgids = $DB->get_fieldset_select('local_emailstudents_msg', 'id', 'contextid = ?', [$context->id]);
            if ($msgids) {
                [$insql, $params] = $DB->get_in_or_equal($msgids, SQL_PARAMS_NAMED);
                $params['userid'] = $userid;
                $DB->delete_records_select('local_emailstudents_rcpt', "userid = :userid AND messageid $insql", $params);
            }
        }
    }

    /**
     * Delete several users' data in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            foreach ($DB->get_fieldset_select('local_emailstudents_tpl', 'id', "userid $usql", $uparams) as $tid) {
                \local_emailstudents\templates::delete($tid);
            }
        }
        $ids = $DB->get_fieldset_select('local_emailstudents_msg', 'id', "contextid = :ctx AND senderid $usql",
            ['ctx' => $context->id] + $uparams);
        self::delete_messages($context, $ids);
        $msgids = $DB->get_fieldset_select('local_emailstudents_msg', 'id', 'contextid = ?', [$context->id]);
        if ($msgids) {
            [$msql, $mparams] = $DB->get_in_or_equal($msgids, SQL_PARAMS_NAMED, 'm');
            $DB->delete_records_select('local_emailstudents_rcpt', "userid $usql AND messageid $msql", $uparams + $mparams);
        }
    }

    /**
     * Delete whole messages.
     *
     * @param \context $context
     * @param int[] $ids
     */
    protected static function delete_messages(\context $context, array $ids): void {
        global $DB;
        if (!$ids) {
            return;
        }
        $fs = get_file_storage();
        foreach ($ids as $id) {
            $fs->delete_area_files($context->id, 'local_emailstudents', false, $id);
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $DB->delete_records_select('local_emailstudents_rcpt', "messageid $insql", $params);
        $DB->delete_records_select('local_emailstudents_msg', "id $insql", $params);
    }
}
