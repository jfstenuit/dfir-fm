document.addEventListener('DOMContentLoaded', async () => {
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
