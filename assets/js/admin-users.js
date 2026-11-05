// assets/js/admin-users.js - Admin page, Users tab: list, resend invitation, groups, lock, delete
//
// Invitations are made from a folder of the file manager (D22).

import { api, h, icon, on, hideModal, showModal, showResultMessage, groupAutocomplete, copyField } from './utils.js';

const byId = id => document.getElementById(id);
let bound = false;

/** Bind the tab's handlers (once) */
export function init() {
    if (bound) return;
    bound = true;
    byId('assignGroupForm')?.addEventListener('submit', addToGroup);
    groupAutocomplete('#groupAssignInput');

    on('#usersTable', 'click', '.resend-invite', (event, button) =>
        resendInvite(userOf(button), button.closest('tr').dataset.sso === 'true'));
    on('#usersTable', 'click', '.lock-user', (event, button) => lockUser(userOf(button)));
    on('#usersTable', 'click', '.unlock-user', (event, button) => unlockUser(userOf(button)));
    byId('copyInvitationLink')?.addEventListener('click', event => copyField(byId('invitationLink'), event.currentTarget));
    // A manual invitation link is shown once: gone when the dialog closes
    byId('invitationLinkModal')?.addEventListener('hidden.bs.modal', () => { byId('invitationLink').value = ''; });
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
/** oidc_display_name, or undefined while OIDC is disabled */
const ssoName = () => byId('usersTable').dataset.sso;

async function loadUserTable() {
    try {
        const users = await api('admin', { data: { a: 'listUsers' } });
        document.querySelector('#usersTable tbody').replaceChildren(...(users ?? []).map(userRow));
    } catch (error) {
        showResultMessage('Failed to load users: ' + error.message, false);
    }
}

const STATUS = {
    active: { label: 'Active', badge: 'text-bg-success', title: 'Has a password' },
    invited: { label: 'Invited', badge: 'text-bg-info', title: 'No password yet' },
    locked: { label: 'Locked', badge: 'text-bg-secondary', title: 'Locked by an administrator: unlock, then resend the invitation' },
};

function userRow(user) {
    const status = STATUS[user.status] ?? STATUS.invited;
    const locked = user.status === 'locked';
    const sso = user.sso && ssoName();
    let title = status.title;
    if (sso && user.status !== 'locked') {
        title = user.status === 'active' ? `Signs in through ${sso}` : `Has not signed in through ${sso} yet`;
    } else if (user.status === 'invited') {
        title = user.invitation_pending ? 'Invitation link pending (valid one hour)' : 'Invitation link expired or used: resend it';
    }
    // Admin groups of an OIDC account follow the IdP's admin claim: no remove button
    const groupBadge = group => (user.managed_groups ?? []).includes(group)
        ? h('span', { class: 'badge text-bg-primary me-1 managed-group', dataset: { group }, title: `Managed by ${ssoName() ?? 'the identity provider'}` },
            group, ' ', icon('lock-fill'))
        : h('span', { class: 'badge text-bg-primary me-1', dataset: { group } },
            group,
            h('button', { type: 'button', class: 'badge-action remove-group', title: 'Remove from group',
                          'aria-label': `Remove ${user.email} from ${group}` }, icon('x-lg')));
    const resendTitle = sso
        ? `Send a notice: the user signs in with their ${sso} account (no password here; sessions are kept)`
        : 'Send a new invitation link: the user sets a new password with it (also a password reset); their open sessions end';

    return h('tr', { dataset: { user: user.email, sso: sso ? 'true' : 'false' } },
        h('td', { class: 'user-email', title: user.display_name ?? '' }, user.email),
        h('td', { class: 'user-groups' },
            user.groups.map(groupBadge),
            h('button', { type: 'button', class: 'badge text-bg-secondary border-0 add-group', title: 'Add to a group',
                          'aria-label': `Add ${user.email} to a group` }, icon('plus-lg'))),
        h('td', { class: 'sign-in' }, sso || 'Password'),
        h('td', { class: 'last-login', title: user.last_login_at ? 'UTC' : '' }, user.last_login_at?.slice(0, 16) ?? '—'),
        h('td', { class: 'status text-center' },
            h('span', { class: `badge ${status.badge}`, title }, status.label)),
        h('td', { class: 'actions text-center' },
            locked
                ? h('button', { type: 'button', class: 'btn btn-sm btn-link unlock-user', title: 'Unlock this user (no credentials are issued: resend the invitation afterwards)' }, icon('unlock'))
                : [h('button', { type: 'button', class: 'btn btn-sm btn-link resend-invite', title: resendTitle }, icon('envelope-open')),
                   h('button', { type: 'button', class: 'btn btn-sm btn-link lock-user', title: 'Lock this user' }, icon('lock'))],
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

/**
 * A new link to "/" (D22). Mail: a message. mail_engine=none: the link, shown
 * once in a dialog. The account's open sessions end now.
 */
async function resendInvite(email, sso) {
    const question = sso
        ? `Send ${email} a notice to sign in with their ${ssoName()} account?\n\nThere is no password to reset; their sessions are kept.`
        : `Send a new invitation to ${email}?\n\nThe link lets them set a new password (it also serves as a password reset). `
          + 'Their open sessions end now; groups and rights are unchanged.';
    if (!confirm(question)) {
        return;
    }
    try {
        const response = await api('admin', { data: { a: 'resendInvite', email } });
        if (response?.link) {
            byId('invitationLinkUser').textContent = email;
            byId('invitationLink').value = response.link;
            byId('invitationLinkModal').addEventListener('shown.bs.modal', () => byId('invitationLink').select(), { once: true });
            showModal('#invitationLinkModal');
        } else {
            showResultMessage(response?.message || 'Invitation resent.', true);
        }
        loadUserTable();
    } catch (error) {
        showResultMessage(error.message, false);
    }
}

function unlockUser(email) {
    act({ a: 'unlockUser', email }, 'User unlocked.');
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
