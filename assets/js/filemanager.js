// assets/js/filemanager.js

import { showResultMessage, initGroupTypeahead, updatePassword, escapeHtml } from './utils.js';

let $groupAccessRowTemplate = null;

export function init() {
    initDataTable();
    initDropzone();

    document.querySelector('#createFolderButton')?.addEventListener('click', createFolder);
    document.querySelector('#sendInviteButton')?.addEventListener('click', sendInvite);
    document.querySelector('#addGroupForm')?.addEventListener('submit', handleAddGroup);
    document.querySelector('#groupAccessTableBody')?.addEventListener('click', (e) => {
        if (e.target.closest('.remove-group')) {
            handleAccessRightsRemoveGroup(e);
        }
    });
    document.querySelector('#groupAccessTableBody')?.addEventListener('change', (e) => {
        if (e.target.matches('input[type="checkbox"]')) {
            handleAccessRightsCheckboxToggle(e);
        }
    });

    $('#accessRightsModal').on('show.bs.modal', function () {
        loadAccessRights();
        initGroupTypeahead($('#groupNameInput'));
    });

    // Enter in password field is sufficient to submit form
    $('#newPassword').on('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            updatePassword();
        }
    });

    // Enter in new folder field is sufficient to submit form
    $('#folderName').on('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            createFolder();
        }
    });

    // Handle the delete button click (in each row)
    $('#main-table').on('click', '.delete-action', function () {
        const $cell = $(this).closest('.inline-actions');
        const id = $cell.data('id');
        const type = $cell.data('type');
        const name = $cell.data('name');
    
        // Store in modal attributes
        const $modal = $('#deleteConfirmModal');
        $modal.data('id', id);
        $modal.data('type', type);
        $modal.data('name', name);
    
        // Update modal UI
        $('#deleteItemName').text(name);
    });
    
    // Handle the confirm delete button click
    $('#confirmDeleteButton').on('click', handleDelete);

    // Handle the update profile (password change) button click
    $('#updateProfileButton').on('click', updatePassword);
}

function initDataTable() {
    if (!$.fn.DataTable.isDataTable('#main-table')) {
        $('#main-table').DataTable({
            searching: false,
            autoWidth: false,
            paging: false,
            info: false,
            ordering: true,
            order: [[0, 'asc']],
            columnDefs: [
                { targets: 0, name: 'name', width: '30%', className: 'file-name' },
                { targets: 1, name: 'size', width: '4%', className: 'file-size text-right' },
                { targets: 2, name: 'created_at', width: '12%' },
                { targets: 3, name: 'created_by_from', width: '12%' },
                { targets: 4, name: 'sha256', width: '10%', orderable: false, className: 'checksum' },
                { targets: 5, name: 'actions', width: '4%', orderable: false, className: 'text-center inline-actions' }
            ]
        });
    }
}

function initDropzone() {    
    if (!document.getElementById('fileUploader')) return;

    Dropzone.autoDiscover = false;
    $('#fileUploader').addClass('dropzone');

    new Dropzone("#fileUploader", {
        url: "upload",
        method: "post",
        chunking: true,
        forceChunking: true,
        chunkSize: 2 * 1024 * 1024,
        parallelChunkUploads: false,
        retryChunks: true,
        retryChunksLimit: 3,

        accept: function (file, done) {
            const cwd = $('#cwd').val();
            $.ajax({
                url: "upload",
                method: "POST",
                data: { cwd: cwd, a: "checkRights" },
                success: function (response) {
                    if (response.hasPermission) {
                        done();
                    } else {
                        done("You don't have permission to upload files in this directory.");
                    }
                },
                error: function () {
                    done("An error occurred while validating upload permissions.");
                }
            });
        },

        init: function () {
            this.on("success", function (file, response) {
                // If server responded with an error despite HTTP 200/500
                if (response.status && response.status !== 'success') {
                    this.emit("error", file, response.message || "Unknown server error");
                    return;
                }
                const newFile = response.file;

                const encodedPath = encodeURIComponent($('#cwd').val() + '/' + newFile.name);
                const table = $('#main-table').DataTable();
                const $tr=$('<tr>');
                $tr.append(`<td><a href="dl?p=${encodedPath}">${escapeHtml(newFile.name)}</a></td>`);
                $tr.append(`<td data-order="${newFile.size}">${newFile.size}</td>`);
                $tr.append(`<td data-order="${newFile.uploaded_at}">${escapeHtml(newFile.uploaded_at)}</td>`);
                $tr.append(`<td>${escapeHtml(newFile.uploaded_by)}<br>${escapeHtml(newFile.uploaded_from)}</td>`);
                $tr.append(`<td class="checksum" title="${newFile.sha256}">${newFile.sha256}</td>`);
                $tr.append(`
                    <td class="inline-actions"
                        data-id="${newFile.id}"
                        data-type="f"
                        data-name="${escapeHtml(newFile.name)}">
                        <a href="#" class="delete-action" data-toggle="modal" data-target="#deleteConfirmModal">
                            <i class="fa fa-trash"></i>
                        </a>
                    </td>
                `);
                
                table.row.add($tr).draw(false);
            });

            this.on("error", function (file, errorMessage) {
                console.error("Error uploading file:", errorMessage);
            });
        }
    });
}


function createFolder() {
    const folderName = $('#folderName').val().trim();
    const cwd = $('#cwd').val();

    if (!folderName) {
        showResultMessage('Folder name cannot be empty.', false);
        return;
    }

    $.post('admin', { a: 'createFolder', name: folderName, cwd })
        .done(data => {
            $('#newFolderModal').modal('hide');
            if (data.success && data.folder) {
                const table = $('#main-table').DataTable();
                const fullPath = cwd.replace(/\/$/, '') + '/' + folderName;
                const encodedPath = encodeURIComponent(fullPath);

                const $tr = $('<tr>');
                $tr.append(`<td><a href="?p=${encodedPath}"><i class="fa fa-folder mr-1"></i>${escapeHtml(folderName)}</a></td>`);
                $tr.append(`<td></td>`);
                $tr.append(`<td>${escapeHtml(data.folder.created_at)}</td>`);
                $tr.append(`<td>${escapeHtml(data.folder.created_by)}<br>${escapeHtml(data.folder.created_from)}</td>`);
                $tr.append(`<td class="checksum"></td>`);
                $tr.append(`
                    <td class="inline-actions"
                        data-id="${data.folder.id}"
                        data-type="d"
                        data-name="${escapeHtml(folderName)}">
                        <a href="#" class="delete-action" data-toggle="modal" data-target="#deleteConfirmModal">
                            <i class="fa fa-trash"></i>
                        </a>
                    </td>
                `);

                table.row.add($tr).draw(false);
            } else {
                showResultMessage(data.message || 'Failed to create folder.', false);
            }
        })
        .fail(xhr => {
            $('#newFolderModal').modal('hide');
            showResultMessage('Error: ' + xhr.statusText, false);
        });
}

function sendInvite() {
    const email = $('#inviteEmail').val().trim();
    const cwd = $('#cwd').val();

    if (!email) {
        showResultMessage('Email address cannot be empty.', false);
        return;
    }

    const accessRights = [];
    $('input[name="accessRights[]"]:checked').each(function () {
        accessRights.push($(this).val());
    });

    if (accessRights.length === 0) {
        showResultMessage('Select at least one access right.', false);
        return;
    }

    const sendLink = $('#sendLink').is(':checked');

    $.post('admin', {
        a: 'invite',
        cwd,
        email,
        accessRights,
        sendLink
    })
    .done(data => {
        $('#inviteModal').modal('hide');
        showResultMessage(data.success ? 'Invitation sent.' : data.message || 'Failed.', data.success);
    })
    .fail(xhr => {
        $('#inviteModal').modal('hide');
        showResultMessage('Error: ' + xhr.statusText, false);
    });
}

function handleDelete() {
    const $modal = $('#deleteConfirmModal');
    const id = $modal.data('id');
    const type = $modal.data('type');
    const name = $modal.data('name');
    const cwd = $('#cwd').val();

    $.post('admin', {
        a: 'deleteItem',
        id: id,
        type: type
    })
        .done(function (data) {
            $('#deleteConfirmModal').modal('hide');
            if (data.success) {
                // Remove the item from the DOM
                const table = $('#main-table').DataTable();
                table.rows().every(function () {
                    const $row = $(this.node());
                    const $actions = $row.find('.inline-actions');
                    if ($actions.data('id') == id) {
                        this.remove();
                    }
                });
                table.draw();
            } else {
                showResultMessage(data.message || 'Failed to delete the item.', false);
            }
        })
        .fail(function (xhr) {
            $('#deleteConfirmModal').modal('hide');
            showResultMessage('An error occurred: ' + xhr.statusText, false);
        });
}

function loadAccessRights() {
    const cwd = $('#cwd').val();
    const $tbody = $('#groupAccessTableBody');

    if (!$groupAccessRowTemplate) {
        $groupAccessRowTemplate = $('#groupAccessRowTemplate').clone();
    }

    $tbody.empty();

    $.post('admin', { a: 'listGroupPermissions', cwd })
        .done(groups => {
            groups.forEach(group => {
                const $row = $groupAccessRowTemplate.clone().removeClass('d-none').removeAttr('id');
                $row.attr('data-group', group.group);
                $row.find('.group-name').text(group.group);
                $row.find('.access-read').prop('checked', group.can_read);
                $row.find('.access-write').prop('checked', group.can_write);
                $row.find('.access-upload').prop('checked', group.can_upload);
                $tbody.append($row);
            });
        })
        .fail(xhr => {
            showResultMessage('Failed to load access rights: ' + xhr.statusText, false);
        });
}

function handleAddGroup(event) {
    event.preventDefault();

    const groupName = $('#groupNameInput').val().trim();
    const cwd = $('#cwdInput').val().trim();
    const $tbody = $('#groupAccessTableBody');

    if (!groupName) {
        showResultMessage('Group name cannot be empty.', false);
        return;
    }

    if ($tbody.find(`tr[data-group="${groupName}"]`).length > 0) {
        showResultMessage('Group already assigned.', false);
        return;
    }

    $.post('admin', { a: 'createGroupIfNotExists', name: groupName, cwd })
        .done(data => {
            if (!data.success) {
                showResultMessage(data.message || 'Failed to add group.', false);
                return;
            }

            const $row = $groupAccessRowTemplate.clone().removeClass('d-none').removeAttr('id');
            $row.attr('data-group', groupName);
            $row.find('.group-name').text(groupName);
            $row.find('.access-read, .access-write, .access-upload').prop('checked', false);

            $tbody.append($row);
            $('#groupNameInput').typeahead('val', '');
        })
        .fail(xhr => {
            showResultMessage('Error: ' + xhr.statusText, false);
        });
}

function handleAccessRightsCheckboxToggle(event) {
    const $checkbox = $(event.target);
    const $row = $checkbox.closest('tr');
    const group = $row.data('group');
    const cwd = $('#cwd').val();

    let right = '';
    if ($checkbox.hasClass('access-read')) right = 'read';
    else if ($checkbox.hasClass('access-write')) right = 'write';
    else if ($checkbox.hasClass('access-upload')) right = 'upload';

    const value = $checkbox.is(':checked') ? 1 : 0;

    if (!group || !right) {
        showResultMessage('Invalid access right toggle.', false);
        return;
    }

    $.post('admin', { a: 'setAccessRight', group, cwd, right, value })
        .fail(xhr => {
            showResultMessage('Error updating right: ' + xhr.statusText, false);
        });
}

function handleAccessRightsRemoveGroup(event) {
    const $btn = $(event.target).closest('button');
    const $row = $btn.closest('tr');
    const group = $row.data('group');
    const cwd = $('#cwd').val();

    if (!group) {
        showResultMessage('Invalid group.', false);
        return;
    }

    $.post('admin', { a: 'removeAccessRight', group, cwd })
        .done(data => {
            if (data.success) {
                $row.remove();
            } else {
                showResultMessage(data.message || 'Failed to remove group.', false);
            }
        })
        .fail(xhr => {
            showResultMessage('Error removing group: ' + xhr.statusText, false);
        });
}
