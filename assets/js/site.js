import { setupCsrfHeader } from './utils.js';

document.addEventListener('DOMContentLoaded', async () => {
    setupCsrfHeader();  // Ensure CSRF token is applied to all AJAX calls

    const isFileManager = document.getElementById('fileUploader');
    const isAdminPage = document.querySelector('[data-target="#usersTab"], [data-target="#groupsTab"], [data-target="#logsTab"]');

    if (isFileManager) {
        const { init } = await import('./filemanager.js');
        init();
    }

    if (isAdminPage) {
        const { init } = await import('./admin.js');
        init();
    }
});
