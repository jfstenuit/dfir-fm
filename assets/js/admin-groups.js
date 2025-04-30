import { showResultMessage } from './utils.js';

let $groupRowTemplateCache = null;

export function init() {
    document.querySelector('#groupsTable')?.addEventListener('click', (event) => {
        if (event.target.closest('.delete-group')) {
            handleDeleteGroup(event);
        }
    });
}

export function load() {
    init(); // Bind once
    loadGroupTable();
}

function loadGroupTable() {
    $.ajax({ url: 'admin', method: 'POST', dataType: 'json', data: { a: 'listGroupsWithDetails' }})
        .done(groups => {
            if (!$groupRowTemplateCache) {
                const $template = $('#groupRowTemplate');
                $groupRowTemplateCache = $template.clone().removeAttr('id').removeClass('d-none');

                $template.remove(); // Clean original from DOM
            }
            const $tbody = $('#groupsTable tbody').empty();
            groups.forEach(group => {
                const $row = renderGroupRow(group);
                $tbody.append($row);
            });
        })
        .fail(xhr => {
            showResultMessage('Failed to load groups: ' + xhr.statusText, false);
        });
}

function renderGroupRow(group) {

    const $row = $groupRowTemplateCache.clone();
    $row.find('.group-name').text(group.name);

    // Members
    const $members = $row.find('.group-members').empty();
    group.members.forEach(email => {
        $members.append(
            $('<span>').addClass('badge badge-primary mr-1').text(email)
        );
    });

    // Directories
    const $dirs = $row.find('.group-directories').empty();
    group.directories.forEach(dir => {
        let label = 'unknown', badgeClass = 'badge-secondary';
        if (dir.can_write) {
            label = 'write';
            badgeClass = 'badge-success';
        } else if (dir.can_upload) {
            label = 'upload';
            badgeClass = 'badge-warning';
        } else if (dir.can_view) {
            label = 'read';
            badgeClass = 'badge-info';
        }

        $dirs.append(
            $('<span>')
                .addClass(`badge ${badgeClass} mr-1`)
                .text(`${dir.path} (${label})`)
        );
    });

    // Set delete button's group name
    $row.find('.delete-group').attr('data-group', group.name);

    return $row;
}

function handleDeleteGroup(event) {
    const $btn = $(event.target).closest('.delete-group');
    const group = $btn.data('group');
    if (!group || !confirm(`Delete group "${group}"?`)) return;

    $.ajax({ url: 'admin', method: 'POST', dataType: 'json', data: { a: 'deleteGroup', name: group }})
        .done(data => {
            showResultMessage(data.message || 'Group deleted.', data.success);
            if (data.success) loadGroupTable();
        })
        .fail(xhr => {
            showResultMessage('Error deleting group: ' + xhr.statusText, false);
        });
}
