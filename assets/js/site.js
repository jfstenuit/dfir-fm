// assets/js/site.js - Entry point of the application pages: loads the page's
// script. A module runs once the document is parsed, so the elements exist.
// Requests carry the CSRF token through api() (utils.js).

if (document.getElementById('fileUploader')) {
    const { init } = await import('./filemanager.js');
    init();
}

if (document.querySelector('[data-bs-target="#usersTab"], [data-bs-target="#groupsTab"], [data-bs-target="#logsTab"]')) {
    const { init } = await import('./admin.js');
    init();
}
