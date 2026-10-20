import { setupCsrfHeader } from './utils.js';

document.addEventListener('DOMContentLoaded', async () => {
    setupCsrfHeader();  // Ensure CSRF token is applied to all AJAX calls

    const isFileManager = document.getElementById('fileUploader');
    const isAdminPage = document.querySelector('[data-bs-target="#usersTab"], [data-bs-target="#groupsTab"], [data-bs-target="#logsTab"]');

    if (isFileManager) {
        const { init } = await import('./filemanager.js');
        init();
    }

    if (isAdminPage) {
        const { init } = await import('./admin.js');
        init();
    }
});
