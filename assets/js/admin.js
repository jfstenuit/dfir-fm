let AdminUsers, AdminGroups, AdminLogs;

export async function init() {
    AdminUsers = await import('./admin-users.js');
    AdminGroups = await import('./admin-groups.js');
    // AdminLogs = await import('./admin-logs.js'); (when ready)

    bindTabSwitch();
    loadInitialTab();
}

function bindTabSwitch() {
    $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        const target = $(e.target).attr('data-target');
        if (target === '#usersTab') AdminUsers.load();
        else if (target === '#groupsTab') AdminGroups.load();
        // else if (target === '#logsTab') AdminLogs.load();
    });
}

function loadInitialTab() {
    if (document.getElementById('usersTab')?.classList.contains('active')) AdminUsers.load();
    else if (document.getElementById('groupsTab')?.classList.contains('active')) AdminGroups.load();
    // else if (document.getElementById('logsTab')?.classList.contains('active')) AdminLogs.load();
}
