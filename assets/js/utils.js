export function showResultMessage(message, isSuccess) {
    $('#operationResultMessage')
        .text(message)
        .removeClass('text-success text-danger')
        .addClass(isSuccess ? 'text-success' : 'text-danger');
    $('#operationResultModal').modal('show');
}

export function initGroupTypeahead($input) {
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
                return response;
            }
        }
    });

    $input.typeahead({
        hint: true,
        highlight: true,
        minLength: 1
    }, {
        name: 'groups',
        source: groupNames
    });
}

export function updatePassword() {
    const newPwd = $('#newPassword').val();

    if (!newPwd) {
        showResultMessage('Please enter a new password.', false);
        return;
    }

    $.post('profile', {
        a: 'updatePassword',
        password: newPwd
    })
    .done(response => {
        if (response.success) {
            showResultMessage(response.message || 'Password updated.', true);
            $('#profileModal').modal('hide');
            $('#newPassword').val('');
        } else {
            showResultMessage(response.message || 'Failed to update password.', false);
        }
    })
    .fail(xhr => {
        showResultMessage('Failed to update password: ' + xhr.statusText, false);
    });
}

// Escape plain text to be safely inserted into innerHTML
export function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Escape a string to be safely used inside an HTML attribute
export function escapeAttribute(value) {
    const div = document.createElement('div');
    div.setAttribute('data-x', value);
    return div.getAttribute('data-x');
}

// Escape URL for use in href, src, etc. (with optional strict whitelist)
export function escapeUrl(url) {
    try {
        const parsed = new URL(url, window.location.origin);
        // Optionally whitelist only same-origin or safe schemes
        if (!['http:', 'https:'].includes(parsed.protocol)) {
            throw new Error('Unsafe URL scheme');
        }
        return parsed.href;
    } catch (e) {
        console.warn('Blocked unsafe URL:', url);
        return '#';
    }
}
