import { showResultMessage, initGroupTypeahead } from './utils.js';

let $userRowTemplateCache = null;

export function init() {
    document.querySelector('#inviteUserForm')?.addEventListener('submit', handleInviteUser);
    document.querySelector('#assignGroupForm')?.addEventListener('submit', handleConfirmAddGroup);
    document.querySelector('#usersTable')?.addEventListener('click', handleUserTableClick);
}

export function load() {
    init(); // bind events (idempotent)
    loadUserTable();
}

function loadUserTable() {
    if (!$userRowTemplateCache) {
        const $template = $('#userRowTemplate');
        $userRowTemplateCache = $template.clone().removeAttr('id').addClass('d-none');
        $template.remove(); // clean from DOM
    }

    $.ajax({ url: 'admin', method: 'POST', dataType: 'json', data: { a: 'listUsers' }})
        .done(users => {
            const $tbody = $('#usersTable tbody');
            $tbody.empty();

            users.forEach(user => {
                const row = renderUserRow(user, $userRowTemplateCache.clone());
                $tbody.append(row);
            });
        })
        .fail(xhr => {
            showResultMessage('Failed to load users: ' + xhr.statusText, false);
        });
}

function renderUserRow(user, $template) {
    const $row = $template.clone().removeClass('d-none');
    $row.attr('data-user', user.email);
    $row.find('.user-email').text(user.email);

    const $groupCell = $row.find('.user-groups').empty();
    user.groups.forEach(group => {
        const $badge = $('<span>')
            .addClass('badge badge-primary mr-1')
            .text(group + ' ')
            .append($('<i class="fa fa-times remove-group ml-1">'));
        $groupCell.append($badge).append(' ');
    });
    $groupCell.append(
        $('<span>')
            .addClass('badge badge-secondary add-group')
            .append($('<i class="fa fa-plus">'))
    );

    const $statusCell = $row.find('.status').empty();
    $statusCell.append(
        $('<span>')
            .addClass('badge')
            .addClass(user.status === 'active' ? 'badge-success' : 'badge-secondary')
            .attr('title', user.status === 'active'
                ? 'User has a password or valid token'
                : 'User has no valid credentials')
            .text(user.status === 'active' ? 'Active' : 'Locked')
    );

    if (user.status !== 'active') {
        $row.find('.lock-user').prop('disabled', true).attr('title', 'This user is already locked');
    }

    return $row;
}

// -------------------------
// Event Handlers
// -------------------------

function handleInviteUser(event) {
    event.preventDefault();
    const email = $('#inviteEmailInput').val().trim();

    if (!email) {
        showResultMessage('Email address cannot be empty.', false);
        return;
    }

    $.ajax({ url: 'admin', method: 'POST', dataType: 'json', data: {
        a: 'invite',
        email: email,
        sendLink: false // just create user
    }}).done(data => {
        showResultMessage(data.message || 'User created.', data.success);
        if (data.success) {
            $('#inviteEmailInput').val('');
            loadUserTable();
        }
    }).fail(xhr => {
        showResultMessage('Failed to invite user: ' + xhr.statusText, false);
    });
}

function handleUserTableClick(event) {
    const $target = $(event.target);
    if ($target.closest('.delete-user').length) handleDeleteUser(event);
    else if ($target.closest('.lock-user').length) handleLockUser(event);
    else if ($target.closest('.resend-invite').length) handleResendInvite(event);
    else if ($target.closest('.add-group').length) handleAddToGroup(event);
    else if ($target.closest('.remove-group').length) handleRemoveFromGroup(event);
}

function handleDeleteUser(event) {
    const email = $(event.target).closest('tr').data('user');
    if (!email || !confirm(`Delete user ${email}?`)) return;

    $.ajax({ url: 'admin', method: 'POST', dataType: 'json', data: { a: 'deleteUser', email }})
        .done(data => {
            showResultMessage(data.message || 'User deleted.', data.success);
            if (data.success) loadUserTable();
        })
        .fail(xhr => {
            showResultMessage('Error deleting user: ' + xhr.statusText, false);
        });
}

function handleLockUser(event) {
    const email = $(event.target).closest('tr').data('user');
    if (!email || !confirm(`Lock user ${email}?`)) return;

    $.ajax({ url: 'admin', method: 'POST', dataType: 'json', data: { a: 'lockUser', email }})
        .done(data => {
            showResultMessage(data.message || 'User locked.', data.success);
            if (data.success) loadUserTable();
        })
        .fail(xhr => {
            showResultMessage('Error locking user: ' + xhr.statusText, false);
        });
}

function handleResendInvite(event) {
    const email = $(event.target).closest('tr').data('user');
    if (!email) return;

    $.ajax({ url: 'admin', method: 'POST', dataType: 'json', data: { a: 'resendInvite', email }})
        .done(data => {
            showResultMessage(data.message || 'Invitation resent.', data.success);
        })
        .fail(xhr => {
            showResultMessage('Error resending invite: ' + xhr.statusText, false);
        });
}

function handleAddToGroup(event) {
    const email = $(event.target).closest('tr').data('user');
    if (!email) return;

    $('#targetUserEmail').val(email);
    $('#groupAssignInput').typeahead('val', '');
    $('#addUserToGroupModal').modal('show');
}

function handleConfirmAddGroup(event) {
    event.preventDefault();
    const email = $('#targetUserEmail').val();
    const group = $('#groupAssignInput').val().trim();

    if (!group || !email) {
        showResultMessage('Missing group or email.', false);
        return;
    }

    $.ajax({ url: 'admin', method: 'POST', dataType: 'json', data: { a: 'addUserToGroup', email, group }})
        .done(data => {
            if (data.success) {
                $('#addUserToGroupModal').modal('hide');
                loadUserTable();
            } else {
                showResultMessage(data.message || 'Failed to add group.', false);
            }
        })
        .fail(xhr => {
            showResultMessage('Error adding to group: ' + xhr.statusText, false);
        });
}

function handleRemoveFromGroup(event) {
    const $row = $(event.target).closest('tr');
    const email = $row.data('user');
    const $badge = $(event.target).closest('.badge');
    const group = $badge.text().trim().replace(/\s*×$/, '');

    if (!email || !group) return;

    $.ajax({ url: 'admin', method: 'POST', dataType: 'json', data: { a: 'removeUserFromGroup', email, group }})
        .done(data => {
            if (data.success) {
                loadUserTable();
            } else {
                showResultMessage(data.message || 'Failed to remove group.', false);
            }
        })
        .fail(xhr => {
            showResultMessage('Error removing group: ' + xhr.statusText, false);
        });
}
