// assets/js/admin.js - Admin page: tabs (users, groups, logs), password change
//
// Each tab's data is (re)loaded whenever the tab is shown, and once for the
// tab active on arrival.

import { updatePassword } from './utils.js';

const loaders = {};   // tab pane id => load()

export async function init() {
    const [users, groups] = await Promise.all([import('./admin-users.js'), import('./admin-groups.js')]);
    loaders.usersTab = users.load;
    loaders.groupsTab = groups.load;
    // logsTab: not implemented yet

    const links = document.querySelectorAll('[data-bs-toggle="tab"]');
    links.forEach(link => link.addEventListener('shown.bs.tab', event => loadTab(event.target)));
    const active = [...links].find(link => link.classList.contains('active'));
    if (active) loadTab(active);

    document.getElementById('profileForm')?.addEventListener('submit', event => {
        event.preventDefault();
        updatePassword();
    });
}

function loadTab(link) {
    const pane = (link.dataset.bsTarget ?? '').replace(/^#/, '');
    loaders[pane]?.();
}
