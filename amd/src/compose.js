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
 * Compose page: live recipient count, placeholder chips and send confirmation.
 *
 * @module     local_emailstudents/compose
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import Ajax from 'core/ajax';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import {getString} from 'core/str';

const FILTERS = ['audience', 'categories', 'subcats', 'courses', 'groups', 'cohorts', 'roles', 'lastaccess',
    'completion', 'completedfrom', 'completedto', 'progressop', 'progress', 'gradeop', 'grade', 'courseinactive',
    'enrolwindow', 'assignments', 'enrolmethods', 'profilefield', 'profileop', 'profilevalue'];

let lastCount = null;
let timer = null;
let requestNo = 0;
let lastField = null;

/**
 * Read the current filter values from the form.
 *
 * @param {HTMLFormElement} form
 * @returns {Object}
 */
const readFilters = (form) => {
    const out = {};
    for (const [name, value] of new FormData(form).entries()) {
        const m = name.match(/^(\w+)(?:\[(\w*)\])?$/);
        if (!m || !FILTERS.includes(m[1]) || value === '_qf__force_multiselect_submission') {
            continue;
        }
        const [, base, sub] = m;
        if (sub === undefined) {
            out[base] = value;
        } else if (sub === '') {
            out[base] = Array.isArray(out[base]) ? out[base] : [];
            if (value !== '') {
                out[base].push(value);
            }
        } else {
            out[base] = (out[base] && typeof out[base] === 'object' && !Array.isArray(out[base])) ? out[base] : {};
            out[base][sub] = value;
        }
    }
    return out;
};

/**
 * Escape text for HTML.
 *
 * @param {String} text
 * @returns {String}
 */
const esc = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};

/**
 * Refresh the summary panel.
 *
 * @param {HTMLFormElement} form
 * @param {Number} courseid
 */
const refresh = (form, courseid) => {
    const thisRequest = ++requestNo;
    const panel = document.querySelector('.lbe-summary');
    panel?.classList.add('is-loading');
    Ajax.call([{
        methodname: 'local_emailstudents_count_recipients',
        args: {courseid: courseid, filters: JSON.stringify(readFilters(form))},
    }])[0].then((result) => {
        if (thisRequest !== requestNo) {
            return result;
        }
        lastCount = result.count;
        const number = document.querySelector('[data-lbe-count]');
        if (number) {
            number.textContent = result.count.toLocaleString();
        }
        const people = document.querySelector('[data-lbe-people]');
        if (people) {
            people.innerHTML = result.sample.map((p) => '<li><span class="lbe-avatar">' + esc(p.initials) + '</span>' +
                '<span class="lbe-person"><span class="lbe-person-name">' + esc(p.fullname) + '</span>' +
                '<span class="lbe-person-email">' + esc(p.email) + '</span></span></li>').join('');
        }
        const more = document.querySelector('[data-lbe-more]');
        if (more) {
            if (result.more > 0) {
                getString('andmore', 'local_emailstudents', result.more.toLocaleString()).then((s) => {
                    more.textContent = s;
                    return s;
                }).catch(() => null);
            } else {
                more.textContent = '';
            }
        }
        const warning = document.querySelector('[data-lbe-warning]');
        if (warning) {
            if (result.count === 0) {
                getString('norecipients', 'local_emailstudents').then((s) => {
                    warning.textContent = s;
                    warning.hidden = false;
                    return s;
                }).catch(() => null);
            } else if (result.overlimit) {
                getString('toomanyrecipients', 'local_emailstudents', {count: result.count, max: result.limit}).then((s) => {
                    warning.textContent = s;
                    warning.hidden = false;
                    return s;
                }).catch(() => null);
            } else {
                warning.hidden = true;
            }
        }
        panel?.classList.remove('is-loading');
        return result;
    }).catch(() => {
        panel?.classList.remove('is-loading');
    });
};

/**
 * Insert text where the user was last typing.
 *
 * @param {String} text
 */
const insertPlaceholder = (text) => {
    const editorId = 'id_body_editor';
    if (lastField && lastField.tagName === 'INPUT') {
        const start = lastField.selectionStart ?? lastField.value.length;
        const end = lastField.selectionEnd ?? start;
        lastField.value = lastField.value.slice(0, start) + text + lastField.value.slice(end);
        lastField.focus();
        lastField.setSelectionRange(start + text.length, start + text.length);
        return;
    }
    if (window.tinymce && window.tinymce.get(editorId)) {
        window.tinymce.get(editorId).insertContent(text);
        window.tinymce.get(editorId).focus();
        return;
    }
    const textarea = document.getElementById(editorId);
    if (textarea) {
        const start = textarea.selectionStart ?? textarea.value.length;
        textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(start);
        textarea.focus();
    }
};

/**
 * Confirm before sending.
 *
 * @param {HTMLFormElement} form
 * @param {HTMLElement} button
 */
const confirmSend = async(form, button) => {
    const count = lastCount === null ? '?' : lastCount.toLocaleString();
    const schedEnabled = form.querySelector('[name="timescheduled[enabled]"]');
    const scheduled = schedEnabled && schedEnabled.checked;
    const [title, body, save] = await Promise.all([
        getString('confirmtitle', 'local_emailstudents'),
        getString(scheduled ? 'confirmbodyscheduled' : 'confirmbody', 'local_emailstudents', count),
        getString(scheduled ? 'schedulebutton' : 'sendnow', 'local_emailstudents'),
    ]);
    const modal = await ModalSaveCancel.create({
        title: title,
        body: '<div class="lbe-confirm"><div class="lbe-confirm-icon"><i class="fa-solid fa-paper-plane"></i></div>' +
            '<p>' + body + '</p></div>',
        buttons: {save: save},
        removeOnClose: true,
        show: true,
    });
    modal.getRoot().on(ModalEvents.save, () => {
        form.querySelector('input[name="confirmed"]').value = 1;
        button.click();
    });
};

/**
 * Ask for a template name, then save.
 *
 * @param {HTMLFormElement} form
 * @param {HTMLElement} button
 * @param {Boolean} canshare
 */
const askTemplateName = async(form, button, canshare) => {
    const subject = form.querySelector('[data-lbe-field="subject"]')?.value || '';
    const [title, label, share, save] = await Promise.all([
        getString('saveastemplate', 'local_emailstudents'),
        getString('templatename', 'local_emailstudents'),
        getString('templateshare', 'local_emailstudents'),
        getString('save', 'core'),
    ]);
    const modal = await ModalSaveCancel.create({
        title: title,
        body: '<label class="lbe-modal-label" for="lbe-tplname">' + esc(label) + '</label>' +
            '<input type="text" class="form-control lbe-modal-input" id="lbe-tplname" maxlength="255" value="' +
            esc(subject).replace(/"/g, '&quot;') + '">' +
            (canshare ? '<label class="lbe-modal-check"><input type="checkbox" id="lbe-tplshare"> ' + esc(share) + '</label>' : ''),
        buttons: {save: save},
        removeOnClose: true,
        show: true,
    });
    modal.getRoot().on(ModalEvents.save, () => {
        const root = modal.getRoot()[0];
        const name = root.querySelector('#lbe-tplname').value.trim() || subject || '-';
        form.querySelector('input[name="templatename"]').value = name;
        form.querySelector('input[name="templateshared"]').value = root.querySelector('#lbe-tplshare')?.checked ? 1 : 0;
        button.click();
    });
};

/**
 * Initialise.
 *
 * @param {Object} config
 */
export const init = (config) => {
    const form = document.querySelector('form[data-lbe-form]');
    if (!form) {
        return;
    }
    const courseid = config.courseid || 0;
    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(() => refresh(form, courseid), 300);
    };
    // Autocomplete fields trigger jQuery change events, so listen through jQuery.
    $(form).on('change input', 'select, input', (e) => {
        const name = (e.target.name || '').replace(/\[.*$/, '');
        if (FILTERS.includes(name)) {
            schedule();
        }
    });

    // Templates: fill subject and message from the chosen template.
    const picker = form.querySelector('[data-lbe-template]');
    const payloadEl = document.getElementById('lbe-templates');
    const templates = payloadEl ? JSON.parse(payloadEl.textContent || '{}') : {};
    if (picker) {
        picker.addEventListener('change', () => {
            const tpl = templates[picker.value];
            if (!tpl) {
                return;
            }
            const subject = form.querySelector('[data-lbe-field="subject"]');
            if (subject) {
                subject.value = tpl.subject;
            }
            const ed = window.tinymce && window.tinymce.get('id_body_editor');
            if (ed) {
                ed.setContent(tpl.body);
                ed.fire('change');
            } else {
                const ta = document.getElementById('id_body_editor');
                if (ta) {
                    ta.value = tpl.body;
                }
            }
        });
    }

    // Save as template: ask for a name first.
    const saveButton = form.querySelector('[data-lbe-savetemplate]');
    if (saveButton) {
        saveButton.addEventListener('click', (e) => {
            if (form.querySelector('input[name="templatename"]').value !== '') {
                return;
            }
            e.preventDefault();
            askTemplateName(form, saveButton, config.canshare);
        });
    }
    refresh(form, courseid);

    form.addEventListener('focusin', (e) => {
        if (e.target.matches('[data-lbe-field="subject"], textarea')) {
            lastField = e.target;
        }
    });
    document.addEventListener('click', (e) => {
        const chip = e.target.closest('[data-lbe-placeholder]');
        if (chip) {
            e.preventDefault();
            insertPlaceholder(chip.dataset.lbePlaceholder);
            chip.classList.add('is-inserted');
            setTimeout(() => chip.classList.remove('is-inserted'), 600);
        }
    });
    // Editor focus means the next chip goes into the body.
    const watchEditor = setInterval(() => {
        const ed = window.tinymce && window.tinymce.get('id_body_editor');
        if (ed) {
            ed.on('focus', () => {
                lastField = null;
            });
            clearInterval(watchEditor);
        }
    }, 500);
    setTimeout(() => clearInterval(watchEditor), 15000);

    const sendButton = form.querySelector('[data-lbe-send]');
    if (sendButton) {
        sendButton.addEventListener('click', (e) => {
            if (form.querySelector('input[name="confirmed"]').value === '1') {
                return;
            }
            e.preventDefault();
            confirmSend(form, sendButton);
        });
    }
};
