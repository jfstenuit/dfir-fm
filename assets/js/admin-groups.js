// assets/js/admin-groups.js - Admin page, Groups tab: groups, members, folders, delete

import { api, h, icon, on, showResultMessage } from './utils.js';

let bound = false;

/** Bind the tab's handlers (once) */
export function init() {
    if (bound) return;
    bound = true;
    on('#groupsTable', 'click', '.delete-group', (event, button) => deleteGroup(button.dataset.group));
}

/** Called each time the tab is shown */
export function load() {
    init();
    loadGroupTable();
}

async function loadGroupTable() {
    try {
        const groups = await api('admin', { data: { a: 'listGroupsWithDetails' } });
        document.querySelector('#groupsTable tbody').replaceChildren(...(groups ?? []).map(groupRow));
    } catch (error) {
        showResultMessage('Failed to load groups: ' + error.message, false);
    }
}

/** The strongest right a group has on a folder, and its badge colour */
function strongestRight(folder) {
    if (folder.can_write) return ['write', 'text-bg-success'];
    if (folder.can_upload) return ['upload', 'text-bg-warning'];
    if (folder.can_view) return ['read', 'text-bg-info'];
    return ['unknown', 'text-bg-secondary'];
}

function groupRow(group) {
    return h('tr', {},
        h('td', { class: 'group-name' }, group.name),
        h('td', { class: 'group-members' },
            group.members.map(email => h('span', { class: 'badge text-bg-primary me-1' }, email))),
        h('td', { class: 'group-directories' },
            group.directories.map(folder => {
                const [label, colour] = strongestRight(folder);
                return h('span', { class: `badge ${colour} me-1` }, `${folder.path} (${label})`);
            })),
        h('td', { class: 'group-actions text-center' },
            h('button', { type: 'button', class: 'btn btn-sm btn-danger delete-group', dataset: { group: group.name },
                          title: 'Delete group', 'aria-label': `Delete group ${group.name}` }, icon('trash'))));
}

async function deleteGroup(name) {
    if (!name || !confirm(`Delete group "${name}"?\n\nIts members lose the access rights it grants.`)) return;
    try {
        const response = await api('admin', { data: { a: 'deleteGroup', name } });
        showResultMessage(response?.message || 'Group deleted.', true);
        loadGroupTable();
    } catch (error) {
        showResultMessage(error.message, false);
    }
}
