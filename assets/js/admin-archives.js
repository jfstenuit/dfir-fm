// assets/js/admin-archives.js - Admin page, Logs tab: archive events, then purge them (D28)
//
// Archiving downloads every event before a date (form POST: the browser
// streams the file to disk). The server records the archive only once the
// file is sent completely, so the list is polled until it appears. Purging
// deletes exactly the events of one archive, after a confirmation that the
// file is kept; the server refuses if those events changed since.

import { api, h, on, postDownload } from './utils.js';

const byId = id => document.getElementById(id);
let bound = false;
let onPurged = () => {};
let known = new Map();   // archive id => archive, as last listed
let polling = null;

/** Bind the dialog's handlers (once); purged() runs after a purge */
export function init(purged) {
    if (bound) return;
    bound = true;
    onPurged = purged;
    byId('archiveModal').addEventListener('show.bs.modal', () => {
        showMain();
        message(null);
        loadArchives();
    });
    byId('archiveModal').addEventListener('hidden.bs.modal', stopPolling);
    byId('archiveForm').addEventListener('submit', event => {
        event.preventDefault();
        archive();
    });
    on('#archivesTable', 'click', '.purge-archive', (event, button) => confirmPurge(known.get(Number(button.dataset.id))));
    byId('purgeSaved').addEventListener('change', event => { byId('purgeGo').disabled = !event.target.checked; });
    byId('purgeCancel').addEventListener('click', showMain);
    byId('purgeGo').addEventListener('click', purge);
}

/** An inline message (a result dialog can't open over this one); null hides it */
function message(text, kind = 'info') {
    const box = byId('archiveMessage');
    box.className = `alert alert-${kind}` + (text === null ? ' d-none' : '');
    box.textContent = text ?? '';
}

function showMain() {
    byId('purgeConfirm').classList.add('d-none');
    byId('archiveMain').classList.remove('d-none');
}

async function loadArchives() {
    try {
        const { archives } = await api('admin', { data: { a: 'listArchives' } });
        known = new Map(archives.map(archive => [archive.id, archive]));
        byId('archivesTable').querySelector('tbody').replaceChildren(
            ...(archives.length ? archives.map(archiveRow) : [h('tr', {}, h('td', { colspan: 6, class: 'text-muted' }, 'No archives yet.'))]));
        return archives;
    } catch (error) {
        message('Failed to load the archives: ' + error.message, 'danger');
        return null;
    }
}

const utc = ts => (ts ?? '').replace('T', ' ').replace('+00:00', '');

function archiveRow(archive) {
    return h('tr', { dataset: { id: archive.id } },
        h('td', {}, String(archive.id)),
        h('td', { class: 'text-nowrap' }, utc(archive.created_at), h('div', { class: 'small text-muted' }, archive.created_by ?? '')),
        h('td', { class: 'text-nowrap' }, utc(archive.before_ts)),
        h('td', { class: 'text-end' }, String(archive.events)),
        h('td', {}, h('code', { class: 'small text-break', title: archive.file }, archive.sha256)),
        h('td', { class: 'text-nowrap' }, archive.purged_at
            ? [utc(archive.purged_at), h('div', { class: 'small text-muted' }, archive.purged_by ?? '')]
            : h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger purge-archive', dataset: { id: archive.id } }, 'Purge…')));
}

async function archive() {
    const before = byId('archiveBefore').value;
    message(null);
    try {
        const preview = await api('admin', { data: { a: 'archivePreview', before } });
        if (preview.events === 0) {
            message(`There is no event before ${utc(preview.before_ts)}.`, 'warning');
            return;
        }
        if (!confirm(`Archive ${preview.events} event(s) before ${utc(preview.before_ts)} UTC?\n\n`
                     + 'The file is downloaded now. Nothing is deleted until you purge the archive.')) {
            return;
        }
    } catch (error) {
        message(error.message, 'danger');
        return;
    }
    const newest = Math.max(0, ...known.keys());
    postDownload('admin', { a: 'archiveEvents', before });
    message('Downloading the archive… It appears below once the file is sent completely.');
    poll(newest);
}

/** List the archives every 2 s (up to 10 min) until one newer than `newest` appears */
function poll(newest) {
    stopPolling();
    const until = Date.now() + 600_000;
    polling = setInterval(async () => {
        const archives = await loadArchives();
        const made = archives?.find(archive => archive.id > newest);
        if (made) {
            stopPolling();
            message(`Archive #${made.id} saved as ${made.file}: ${made.events} event(s), SHA-256 ${made.sha256}. `
                    + 'Check the file against this SHA-256 and keep it before purging.', 'success');
        } else if (Date.now() > until) {
            stopPolling();
            message('No archive was recorded: the download may have been interrupted. Archive again.', 'warning');
        }
    }, 2000);
}

function stopPolling() {
    clearInterval(polling);
    polling = null;
}

function confirmPurge(archive) {
    if (!archive) return;
    message(null);
    byId('purgeId').textContent = archive.id;
    byId('purgeEvents').textContent = archive.events;
    byId('purgeBefore').textContent = utc(archive.before_ts) + ' UTC';
    byId('purgeFile').textContent = archive.file;
    byId('purgeSha256').textContent = archive.sha256;
    byId('purgeSaved').checked = false;
    byId('purgeGo').disabled = true;
    byId('purgeGo').dataset.id = archive.id;
    byId('archiveMain').classList.add('d-none');
    byId('purgeConfirm').classList.remove('d-none');
}

async function purge() {
    const id = byId('purgeGo').dataset.id;
    byId('purgeGo').disabled = true;
    try {
        const { archive } = await api('admin', { data: { a: 'purgeArchive', id } });
        showMain();
        message(`Archive #${archive.id} purged: ${archive.events} event(s) deleted.`, 'success');
        loadArchives();
        onPurged();
    } catch (error) {
        showMain();
        message(error.message, 'danger');
        loadArchives();
    }
}
