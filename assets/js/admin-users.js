// assets/js/admin-users.js - Admin page, Users tab: list, invite, groups, lock, delete

import { api, h, icon, on, hideModal, showModal, showResultMessage, groupAutocomplete } from './utils.js';

const byId = id => document.getElementById(id);
let bound = false;

/** Bind the tab's handlers (once) */
export function init() {
    if (bound) return;
    bound = true;
    byId('inviteUserForm')?.addEventListener('submit', inviteUser);
    byId('assignGroupForm')?.addEventListener('submit', addToGroup);
    groupAutocomplete('#groupAssignInput');

    on('#usersTable', 'click', '.resend-invite', (event, button) => resendInvite(userOf(button)));
    on('#usersTable', 'click', '.lock-user', (event, button) => lockUser(userOf(button)));
    on('#usersTable', 'click', '.delete-user', (event, button) => deleteUser(userOf(button)));
    on('#usersTable', 'click', '.add-group', (event, button) => openAddToGroup(userOf(button)));
    on('#usersTable', 'click', '.remove-group', (event, button) =>
        removeFromGroup(userOf(button), button.closest('[data-group]').dataset.group));
}

/** Called each time the tab is shown */
export function load() {
    init();
    loadUserTable();
}

const userOf = element => element.closest('tr').dataset.user;

async function loadUserTable() {
    try {
        const users = await api('admin', { data: { a: 'listUsers' } });
        document.querySelector('#usersTable tbody').replaceChildren(...(users ?? []).map(userRow));
    } catch (error) {
        showResultMessage('Failed to load users: ' + error.message, false);
    }
}

function userRow(user) {
    const active = user.status === 'active';
    const groupBadge = group => h('span', { class: 'badge text-bg-primary me-1', dataset: { group } },
        group,
        h('button', { type: 'button', class: 'badge-action remove-group', title: 'Remove from group',
                      'aria-label': `Remove ${user.email} from ${group}` }, icon('x-lg')));

    return h('tr', { dataset: { user: user.email } },
        h('td', { class: 'user-email' }, user.email),
        h('td', { class: 'user-groups' },
            user.groups.map(groupBadge),
            h('button', { type: 'button', class: 'badge text-bg-secondary border-0 add-group', title: 'Add to a group',
                          'aria-label': `Add ${user.email} to a group` }, icon('plus-lg'))),
        h('td', { class: 'status text-center' },
            h('span', { class: `badge ${active ? 'text-bg-success' : 'text-bg-secondary'}`,
                        title: active ? 'User has a password or valid token' : 'User has no valid credentials' },
                active ? 'Active' : 'Locked')),
        h('td', { class: 'actions text-center' },
            h('button', { type: 'button', class: 'btn btn-sm btn-link resend-invite', title: 'Re-send invitation email' }, icon('envelope-open')),
            h('button', { type: 'button', class: 'btn btn-sm btn-link lock-user', disabled: !active,
                          title: active ? 'Lock this user' : 'This user is already locked' }, icon('lock')),
            h('button', { type: 'button', class: 'btn btn-sm btn-link text-danger delete-user', title: 'Delete user' }, icon('trash'))));
}

/**
 * Run an action and report it. On success: message (the server's, else
 * `done`), then reload the table if asked.
 */
async function act(fields, done, { reload = true } = {}) {
    try {
        const response = await api('admin', { data: fields });
        showResultMessage(response?.message || done, true);
        if (reload) loadUserTable();
        return true;
    } catch (error) {
        showResultMessage(error.message, false);
        return false;
    }
}

async function inviteUser(event) {
    event.preventDefault();
    const input = byId('inviteEmailInput');
    const email = input.value.trim();
    if (!email) {
        showResultMessage('Email address cannot be empty.', false);
        return;
    }
    // Creates the user only: no folder, no rights, no e-mail
    if (await act({ a: 'invite', email, sendLink: false }, 'User created.')) {
        input.value = '';
    }
}

function resendInvite(email) {
    act({ a: 'resendInvite', email }, 'Invitation resent.', { reload: false });
}

function lockUser(email) {
    if (confirm(`Lock user ${email}?`)) {
        act({ a: 'lockUser', email }, 'User locked.');
    }
}

function deleteUser(email) {
    if (confirm(`Delete user ${email}?\n\nThe account is disabled for good and its address is replaced by a pseudonym. `
                + 'Its files, folders and log entries are kept.')) {
        act({ a: 'deleteUser', email }, 'User deleted.');
    }
}

function openAddToGroup(email) {
    byId('targetUserEmail').value = email;
    byId('groupAssignInput').value = '';
    showModal('#addUserToGroupModal');
}

async function addToGroup(event) {
    event.preventDefault();
    const email = byId('targetUserEmail').value;
    const group = byId('groupAssignInput').value.trim();
    if (!group || !email) {
        showResultMessage('Missing group or email.', false);
        return;
    }
    try {
        await api('admin', { data: { a: 'addUserToGroup', email, group } });
        await hideModal('#addUserToGroupModal');
        loadUserTable();
    } catch (error) {
        showResultMessage(error.message, false);
    }
}

async function removeFromGroup(email, group) {
    try {
        await api('admin', { data: { a: 'removeUserFromGroup', email, group } });
        loadUserTable();
    } catch (error) {
        showResultMessage(error.message, false);
    }
}
