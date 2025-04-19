
// Dropzone initialization
Dropzone.options.fileUploader = {
    chunking: true, // Enable chunking
    forceChunking: true, // Ensure chunking is used even for small files
    chunkSize: 2 * 1024 * 1024, // Set chunk size to 2MB
    parallelChunkUploads: false,
    retryChunks: true,
    retryChunksLimit: 3,
    accept: function(file, done) {
        // Get the current working directory from the input field
        const cwd = $('#cwd').val();

        // Make an AJAX call to the web API for validation
        $.ajax({
            url: "upload",
            method: "POST",
            data: { cwd: cwd, a: "checkRights" },
            success: function(response) {
                // Check the API response
                if (response.hasPermission) {
                    // Permission granted
                    done();
                } else {
                    // Permission denied
                    done("You don't have permission to upload files in this directory.");
                }
            },
            error: function() {
                // Handle API errors
                done("An error occurred while validating upload permissions.");
            }
        });
    },
    init: function () {
        this.on("success", function (file, response) {
            console.log("File uploaded successfully:", response);

            // Parse the uploaded file's details
            const newFile = response.file; // Assume the server returns details about the uploaded file

            // Update the DataTable
            const table = $('#main-table').DataTable(); // Initialize or get reference to DataTable
            table.row.add([
                newFile.name,
                newFile.size,
                newFile.uploaded_at,
                newFile.uploaded_by + '<br>' + newFile.uploaded_from,
                newFile.sha256,
                ''
            ]).draw(false); // Draw the table without resetting the pagination

        });
        this.on("error", function (file, errorMessage) {
            console.error("Error uploading file:", errorMessage);
        });
    }
};

// Dom Ready Events
$(document).ready(function() {

    let deleteItemId = null;
    let deleteItemType = null;
    let deleteItemName = null;

    function showResultMessage(message, isSuccess) {
        $('#operationResultMessage')
            .text(message)
            .removeClass('text-success text-danger')
            .addClass(isSuccess ? 'text-success' : 'text-danger');
        $('#operationResultModal').modal('show');
    }

    function createFolder() {
        const folderName = $('#folderName').val().trim();
        const cwd = $('#cwd').val();

        if (!folderName) {
            showResultMessage('Folder name cannot be empty.', false);
            return;
        }

        $.post('admin', {
            a: 'createFolder',
            name: folderName,
            cwd: cwd
        })
            .done(function (data) {
                $('#newFolderModal').modal('hide');
                if (data.success) {
                    showResultMessage('Folder created successfully.', true);
                } else {
                    showResultMessage(data.message || 'Failed to create folder.', false);
                }
            })
            .fail(function (xhr) {
                $('#newFolderModal').modal('hide');
                showResultMessage('An error occurred: ' + xhr.statusText, false);
            });
    }

    function sendInvite() {
        const cwd = $('#cwd').val();
        const email = $('#inviteEmail').val().trim();
    
        if (!email) {
            showResultMessage('Email address cannot be empty.', false);
            return;
        }
    
        // Collect selected access rights
        const accessRights = [];
        $('input[name="accessRights[]"]:checked').each(function () {
            accessRights.push($(this).val());
        });
    
        // Check if at least one access right is selected
        if (accessRights.length === 0) {
            showResultMessage('You must select at least one access right.', false);
            return;
        }
    
        // Determine if the invitation link should be sent
        const sendLink = $('#sendLink').is(':checked');
    
        // Make the AJAX call
        $.post('admin', {
            a: 'invite',
            cwd: cwd,
            email: email,
            accessRights: accessRights,
            sendLink: sendLink
        })
            .done(function (data) {
                $('#inviteModal').modal('hide');
                if (data.success) {
                    showResultMessage('Invitation sent successfully.', true);
                } else {
                    showResultMessage(data.message || 'Failed to send invitation.', false);
                }
            })
            .fail(function (xhr) {
                $('#inviteModal').modal('hide');
                showResultMessage('An error occurred: ' + xhr.statusText, false);
            });
    }

    function handleDelete() {
        if (!deleteItemId || !deleteItemType || !deleteItemName) {
            showResultMessage('Invalid deletion request.', false);
            return;
        }

        $.post('admin', {
            a: 'deleteItem',
            id: deleteItemId,
            type: deleteItemType
        })
            .done(function (data) {
                $('#deleteConfirmModal').modal('hide');
                if (data.success) {
                    showResultMessage('Item deleted successfully.', true);
                    // Optionally remove the item from the DOM
                    $(`td.inline-actions[data-id="${deleteItemId}"]`).closest('tr').remove();
                } else {
                    showResultMessage(data.message || 'Failed to delete the item.', false);
                }
            })
            .fail(function (xhr) {
                $('#deleteConfirmModal').modal('hide');
                showResultMessage('An error occurred: ' + xhr.statusText, false);
            });
    }

    function updatePassword() {
        const newPassword = $('#newPassword').val().trim();
    
        if (!newPassword) {
            $('#profileModal').modal('hide');
            showResultMessage('Password cannot be empty.', false);
            return;
        }
    
        $.post('profile', {
            a: 'updatePassword',
            password: newPassword
        })
        .done(function (data) {
            $('#profileModal').modal('hide');
            if (data.success) {
                showResultMessage('Password updated successfully.', true);
            } else {
                showResultMessage(data.message || 'Failed to update password.', false);
            }
        })
        .fail(function (xhr) {
            $('#profileModal').modal('hide');
            showResultMessage('An error occurred: ' + xhr.statusText, false);
        });
    }

    function loadAccessRights() {
        const cwd = $('#cwd').val();
        const $tbody = $('#groupAccessTableBody');
        const $template = $('#groupAccessRowTemplate');

        $tbody.empty();
    
        $.post('admin', { a: 'listGroupPermissions', cwd: cwd })
            .done(function (groups) {
                groups.forEach(group => {
                    const $row = $template.clone().removeClass('d-none').removeAttr('id');
    
                    $row.attr('data-group', group.group);
                    $row.find('.group-name').text(group.group);
                    $row.find('.access-read').prop('checked', group.can_read);
                    $row.find('.access-write').prop('checked', group.can_write);
                    $row.find('.access-upload').prop('checked', group.can_upload);
    
                    $tbody.append($row);
                });
            })
            .fail(function (xhr) {
                showResultMessage('Failed to load access rights: ' + xhr.statusText, false);
            });
    }

    function initGroupTypeahead() {
        const groupNames = new Bloodhound({
            datumTokenizer: Bloodhound.tokenizers.whitespace,
            queryTokenizer: Bloodhound.tokenizers.whitespace,
            remote: {
                url: 'admin',
                prepare: function (query, settings) {
                    settings.type = 'POST';
                    settings.data = { a: 'listGroups', q: query };
                    return settings;
                },
                transform: function (response) {
                    return response; // expects an array of group name strings
                }
            }
        });
    
        $('#groupNameInput').typeahead(
            {
                hint: true,
                highlight: true,
                minLength: 1
            },
            {
                name: 'groups',
                source: groupNames
            }
        );
    }

    function handleAddGroup(event) {
        event.preventDefault();
    
        const groupName = $('#groupNameInput').val().trim();
        const currentdir = $('#cwdInput').val().trim();
        const $tbody = $('#groupAccessTableBody');
    
        if (!groupName) {
            showResultMessage('Group name cannot be empty.', false);
            return;
        }
    
        // Check if the group is already assigned
        if ($tbody.find(`tr[data-group="${groupName}"]`).length > 0) {
            showResultMessage('This group is already assigned.', false);
            return;
        }
    
        // Attempt to create the group (idempotent - no issue if it already exists)
        $.post('admin', {
            a: 'createGroupIfNotExists',
            name: groupName,
            cwd: currentdir
        })
        .done(function (data) {
            if (!data.success) {
                showResultMessage(data.message || 'Failed to add group.', false);
                return;
            }
    
            // Clone template and add to table
            const $template = $('#groupAccessRowTemplate');
            const $row = $template.clone().removeClass('d-none').removeAttr('id');
            $row.attr('data-group', groupName);
            $row.find('.group-name').text(groupName);
    
            // Set all checkboxes to false by default
            $row.find('.access-read').prop('checked', false);
            $row.find('.access-write').prop('checked', false);
            $row.find('.access-upload').prop('checked', false);
    
            $tbody.append($row);
            $('#groupNameInput').typeahead('val', ''); // Clear input field
        })
        .fail(function (xhr) {
            showResultMessage('An error occurred: ' + xhr.statusText, false);
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
    
        $.post('admin', {
            a: 'setAccessRight',
            group: group,
            cwd: cwd,
            right: right,
            value: value
        })
        .fail(function (xhr) {
            showResultMessage('Error updating access right: ' + xhr.statusText, false);
        });
    }

    function handleAccessRightsRemoveGroup(event) {
        const $btn = $(event.target).closest('button');
        const $row = $btn.closest('tr');
        const group = $row.data('group');
        const cwd = $('#cwd').val();
    
        if (!group) {
            showResultMessage('Invalid group selected.', false);
            return;
        }
    
        $.post('admin', {
            a: 'removeAccessRight',
            group: group,
            cwd: cwd
        })
        .done(function (data) {
            if (data.success) {
                $row.remove();
            } else {
                showResultMessage(data.message || 'Failed to remove group access.', false);
            }
        })
        .fail(function (xhr) {
            showResultMessage('Error removing group access: ' + xhr.statusText, false);
        });
    }
    
    // Attach click listener to delete action links
    $(document).on('click', '.delete-action', function () {
        const $actionElement = $(this).closest('td.inline-actions');
        deleteItemId = $actionElement.data('id');
        deleteItemType = $actionElement.data('type');
        deleteItemName = $actionElement.data('name');

        $('#deleteItemName').text(deleteItemName);
        $('#deleteConfirmModal').modal('show');
    });

    // Enter in password field is sufficient to submit form
    $('#newPassword').on('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            updatePassword();
        }
    });
    
    // Handle the update profile (password change) button click
    $('#updateProfileButton').on('click', updatePassword);

    // Handle the access rights button click
    $('#accessRightsModal').on('show.bs.modal', function () {
        loadAccessRights();
        initGroupTypeahead();
    });

    // Handle creating a new group or assigning group rights to a directory
    $('#addGroupForm').on('submit', handleAddGroup);

    $('#groupAccessTableBody').on('change', 'input[type="checkbox"]', handleAccessRightsCheckboxToggle);
    $('#groupAccessTableBody').on('click', '.remove-group', handleAccessRightsRemoveGroup);

    // Handle the confirm delete button click
    $('#confirmDeleteButton').on('click', handleDelete);

    // Handle the send invite button click
    $('#sendInviteButton').on('click', sendInvite);

    // dataTable init
    $('#main-table').DataTable({
        searching: false,
        autoWidth: false,
        paging: false,
        info: false,
        ordering: true,
        order: [[0, 'asc']],
        columnDefs: [
            { targets: 0, name: 'name', width: '30%' },
            { targets: 1, name: 'size', width: '4%' },
            { targets: 2, name: 'created_at', width: '12%' },
            { targets: 3, name: 'created_by_from', width: '12%' },
            { targets: 4, name: 'sha256', width: '10%', orderable: false },
            { targets: 5, name: 'actions', width: '4%', orderable: false }
        ]
    });

    // Create Folder logic
    $('#createFolderButton').on('click', createFolder);

    // Bypass default action for text fields in forms
    $('#folderName').on('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            createFolder();
        }
    });
    $('#inviteEmail').on('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendInvite();
        }
    });
})