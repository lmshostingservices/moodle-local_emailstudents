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
 * Small UI building blocks shared by the plugin pages.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents\output;

use html_writer;
use moodle_url;

/**
 * UI helpers.
 */
class ui {
    /**
     * A Font Awesome icon.
     *
     * @param string $name Icon name without the fa- prefix.
     * @param string $extra Extra classes.
     * @return string
     */
    public static function icon(string $name, string $extra = ''): string {
        return html_writer::tag('i', '', ['class' => trim("fa-solid fa-$name $extra"), 'aria-hidden' => 'true']);
    }

    /**
     * Page hero with title, subtitle and navigation tabs.
     *
     * @param string $title
     * @param string $subtitle
     * @param string $active compose|history
     * @param int $courseid
     * @param bool $cancompose
     * @return string
     */
    public static function hero(string $title, string $subtitle, string $active, int $courseid, bool $cancompose = true): string {
        $params = $courseid ? ['courseid' => $courseid] : [];
        $tabs = '';
        if ($cancompose) {
            $tabs .= html_writer::link(new moodle_url('/local/emailstudents/compose.php', $params),
                self::icon('pen-nib') . html_writer::span(get_string('tab_compose', 'local_emailstudents')),
                ['class' => 'lbe-tab' . ($active === 'compose' ? ' is-active' : '')]);
        }
        if ($cancompose) {
            $tabs .= html_writer::link(new moodle_url('/local/emailstudents/templates.php', $params),
                self::icon('layer-group') . html_writer::span(get_string('tab_templates', 'local_emailstudents')),
                ['class' => 'lbe-tab' . ($active === 'templates' ? ' is-active' : '')]);
        }
        $tabs .= html_writer::link(new moodle_url('/local/emailstudents/history.php', $params),
            self::icon('clock-rotate-left') . html_writer::span(get_string('tab_history', 'local_emailstudents')),
            ['class' => 'lbe-tab' . ($active === 'history' ? ' is-active' : '')]);

        $mark = html_writer::div(self::icon('paper-plane'), 'lbe-hero-mark');
        $text = html_writer::div(
            html_writer::tag('h2', s($title), ['class' => 'lbe-hero-title']) .
            html_writer::tag('p', $subtitle, ['class' => 'lbe-hero-sub']), 'lbe-hero-text');
        return html_writer::div(
            html_writer::div($mark . $text, 'lbe-hero-main') .
            html_writer::tag('nav', $tabs, ['class' => 'lbe-tabs', 'aria-label' => get_string('pluginname', 'local_emailstudents')]),
            'lbe-hero');
    }

    /**
     * A small stat tile.
     *
     * @param string $icon
     * @param string $label
     * @param string $value
     * @param string $tone neutral|accent|good|warn|bad
     * @return string
     */
    public static function stat(string $icon, string $label, string $value, string $tone = 'neutral'): string {
        return html_writer::div(
            html_writer::div(self::icon($icon), "lbe-stat-icon tone-$tone") .
            html_writer::div(
                html_writer::div($value, 'lbe-stat-value') .
                html_writer::div($label, 'lbe-stat-label'), 'lbe-stat-text'),
            'lbe-stat');
    }

    /**
     * Status pill.
     *
     * @param string $status
     * @return string
     */
    public static function pill(string $status): string {
        $icons = [
            'queued' => 'hourglass-half',
            'scheduled' => 'calendar-days',
            'sending' => 'paper-plane',
            'complete' => 'circle-check',
            'cancelled' => 'ban',
        ];
        return html_writer::span(self::icon($icons[$status] ?? 'circle') .
            get_string('status_' . $status, 'local_emailstudents'), "lbe-pill is-$status");
    }

    /**
     * Thin progress bar.
     *
     * @param int $done
     * @param int $failed
     * @param int $total
     * @return string
     */
    public static function progress(int $done, int $failed, int $total): string {
        $total = max(1, $total);
        $okw = round($done / $total * 100, 1);
        $badw = round($failed / $total * 100, 1);
        return html_writer::div(
            html_writer::span('', 'lbe-progress-ok', ['style' => "width: {$okw}%"]) .
            html_writer::span('', 'lbe-progress-bad', ['style' => "width: {$badw}%"]),
            'lbe-progress', ['role' => 'img', 'aria-label' => "$done / $total"]);
    }

    /**
     * Initials avatar.
     *
     * @param \stdClass $user
     * @return string
     */
    public static function avatar(\stdClass $user): string {
        $initials = \core_text::strtoupper(\core_text::substr($user->firstname ?? '', 0, 1) .
            \core_text::substr($user->lastname ?? '', 0, 1));
        $hue = crc32((string) ($user->id ?? $user->email ?? '')) % 360;
        return html_writer::span(s($initials ?: '?'), 'lbe-avatar', ['style' => "--lbe-hue: {$hue}"]);
    }
}
