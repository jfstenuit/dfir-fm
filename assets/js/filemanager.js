// assets/js/filemanager.js - The file manager page: listing, folders, uploads,
// access rights, invitations, password change, refresh from storage

import Dropzone from '../../vendor/enyo/dropzone/dist/dropzone.mjs';
import { api, h, icon, on, hideModal, showResultMessage, updatePassword, groupAutocomplete, copyField } from './utils.js';
import { sortableTable } from './sortable-table.js';

const byId = id => document.getElementById(id);

let table = null;           // sortableTable() of #main-table
let pendingDelete = null;   // {id, type, name}: picked in a row, confirmed in the dialog

/**
 * The current directory as the server names it: "/" at the root, "/a/b"
 * below. The page's #cwd is "" at the root, which the access-rights actions
 * don't accept.
 */
function currentDirectory() {
    return byId('cwd')?.value || '/';
}

const childPath = name => currentDirectory().replace(/\/$/, '') + '/' + name;

export function init() {
    table = sortableTable('#main-table', { column: 0 });
    initDropzone();

    // The dialogs' buttons submit their forms: Enter in a field does the same
    byId('newFolderForm')?.addEventListener('submit', createFolder);
    byId('inviteForm')?.addEventListener('submit', sendInvite);
    byId('copyInviteLink')?.addEventListener('click', event => copyField(byId('inviteLink'), event.currentTarget));
    // A manual invitation link is shown once: gone when the dialog closes
    byId('inviteModal')?.addEventListener('hidden.bs.modal', () => showInviteLink(null));
    byId('profileForm')?.addEventListener('submit', event => {
        event.preventDefault();
        updatePassword();
    });

    // Delete: the row's link opens the dialog (data-bs-toggle), this remembers the item
    on('#main-table', 'click', '.delete-action', (event, link) => {
        event.preventDefault();
        const cell = link.closest('.inline-actions');
        pendingDelete = { id: cell.dataset.id, type: cell.dataset.type, name: cell.dataset.name };
        byId('deleteItemName').textContent = pendingDelete.name;
    });
    byId('confirmDeleteButton')?.addEventListener('click', deleteItem);

    // Access rights dialog
    byId('accessRightsModal')?.addEventListener('show.bs.modal', loadAccessRights);
    groupAutocomplete('#groupNameInput');
    byId('addGroupForm')?.addEventListener('submit', addGroup);
    on('#groupAccessTableBody', 'change', 'input[type="checkbox"]', toggleRight);
    on('#groupAccessTableBody', 'click', '.remove-group', removeGroup);

    initRefresh();
}

// ---------------------------------------------------------------------------
// Listing rows (same markup as FileManagerView)
// ---------------------------------------------------------------------------

/** item: {type: 'f'|'d', id, name, size, date, by, from, sha256} */
function itemRow(item) {
    const path = encodeURIComponent(childPath(item.name));
    const canDelete = byId('main-table').dataset.canDelete === '1';
    return h('tr', {},
        h('td', {}, h('div', { class: 'filename' },
            h('a', { href: item.type === 'd' ? `?p=${path}` : `dl?p=${path}` },
                item.type === 'd' ? icon('folder', 'me-1') : null, item.name))),
        h('td', { class: 'text-end', dataset: { order: item.size ?? '' } }, item.size ?? ''),
        h('td', { dataset: { order: item.date } }, item.date),
        h('td', {}, item.by, h('br'), item.from),
        h('td', { class: 'checksum', title: item.sha256 || null }, item.sha256 ?? ''),
        h('td', { class: 'inline-actions text-center', dataset: { id: item.id, type: item.type, name: item.name } },
            canDelete ? h('a', { href: '#', class: 'delete-action', title: 'Delete', 'data-bs-toggle': 'modal', 'data-bs-target': '#deleteConfirmModal' }, icon('trash')) : null,
            ' ',
            h('a', { title: 'Rename', href: '#' }, icon('pencil-square'))));
}

function addRow(item) {
    byId('main-table').tBodies[0].append(itemRow(item));
    table.refresh();
}

// ---------------------------------------------------------------------------
// Folders and deletion
// ---------------------------------------------------------------------------

async function createFolder(event) {
    event.preventDefault();
    const input = byId('folderName');
    const name = input.value.trim();
    if (!name) {
        showResultMessage('Folder name cannot be empty.', false);
        return;
    }
    try {
        const { folder } = await api('./', { data: { a: 'createFolder', name, cwd: currentDirectory() } });
        await hideModal('#newFolderModal');
        input.value = '';
        addRow({ type: 'd', id: folder.id, name: folder.name, size: null, date: folder.created_at,
                 by: folder.created_by, from: folder.created_from, sha256: null });
    } catch (error) {
        // The dialog stays open, to correct the name
        showResultMessage(error.message, false);
    }
}

async function deleteItem() {
    if (!pendingDelete) return;
    const { id, type } = pendingDelete;
    try {
        await api('./', { data: { a: 'deleteItem', id, type } });
        await hideModal('#deleteConfirmModal');
        // Files and folders have separate ids: match both
        document.querySelector(`#main-table .inline-actions[data-type="${CSS.escape(type)}"][data-id="${CSS.escape(id)}"]`)
            ?.closest('tr').remove();
    } catch (error) {
        await hideModal('#deleteConfirmModal');
        showResultMessage(error.message, false);
    }
    pendingDelete = null;
}

// ---------------------------------------------------------------------------
// Invitations
// ---------------------------------------------------------------------------

/**
 * Invite into the current folder (never the root, D14). Mail: the dialog
 * closes. mail_engine=none (data-manual): the link comes back in the answer
 * and stays in the dialog until it closes, to be copied and passed on.
 */
async function sendInvite(event) {
    event.preventDefault();
    const emailInput = byId('inviteEmail');
    const email = emailInput.value.trim();
    if (!email) {
        showResultMessage('Email address cannot be empty.', false);
        return;
    }
    const button = byId('sendInviteButton');
    button.disabled = true;
    showInviteLink(null);
    try {
        const response = await api('admin', { data: {
            a: 'invite', cwd: currentDirectory(), email, addToDefaultGroup: byId('addToDefaultGroup').checked,
        }});
        emailInput.value = '';
        if (response?.link) {
            showInviteLink(response.link);
        } else {
            await hideModal('#inviteModal');
            showResultMessage(response?.message || 'Invitation sent.', true);
        }
    } catch (error) {
        showResultMessage(error.message, false);
    } finally {
        button.disabled = false;
    }
}

/** Show a manual invitation link in the dialog, or clear it (null) */
function showInviteLink(link) {
    const input = byId('inviteLink');
    if (!input) return;
    input.value = link ?? '';
    byId('inviteLinkResult').classList.toggle('d-none', !link);
    if (link) input.select();
}

// ---------------------------------------------------------------------------
// Access rights dialog
// ---------------------------------------------------------------------------

function rightsRow(group, rights = {}) {
    const box = (name, checked) => h('td', { class: 'text-center' },
        h('input', { type: 'checkbox', class: `form-check-input access-${name}`, checked: !!checked, 'aria-label': `${name}: ${group}` }));
    return h('tr', { dataset: { group } },
        h('td', { class: 'group-name' }, group),
        box('read', rights.can_read),
        box('write', rights.can_write),
        box('upload', rights.can_upload),
        h('td', { class: 'text-center' },
            h('button', { type: 'button', class: 'btn btn-sm btn-danger remove-group', title: 'Remove group access' }, icon('trash'))));
}

async function loadAccessRights() {
    const body = byId('groupAccessTableBody');
    body.replaceChildren();
    try {
        const groups = await api('admin', { data: { a: 'listGroupPermissions', cwd: currentDirectory() } });
        body.replaceChildren(...(groups ?? []).map(group => rightsRow(group.group, group)));
    } catch (error) {
        showResultMessage('Failed to load access rights: ' + error.message, false);
    }
}

async function addGroup(event) {
    event.preventDefault();
    const input = byId('groupNameInput');
    const name = input.value.trim();
    const body = byId('groupAccessTableBody');
    if (!name) {
        showResultMessage('Group name cannot be empty.', false);
        return;
    }
    if ([...body.rows].some(row => row.dataset.group === name)) {
        showResultMessage('Group already assigned.', false);
        return;
    }
    try {
        await api('admin', { data: { a: 'createGroupIfNotExists', name, cwd: currentDirectory() } });
        body.append(rightsRow(name));
        input.value = '';
    } catch (error) {
        showResultMessage(error.message, false);
    }
}

async function toggleRight(event, checkbox) {
    const group = checkbox.closest('tr').dataset.group;
    const right = ['read', 'write', 'upload'].find(name => checkbox.classList.contains(`access-${name}`));
    checkbox.disabled = true;
    try {
        await api('admin', { data: { a: 'setAccessRight', group, cwd: currentDirectory(), right, value: checkbox.checked ? 1 : 0 } });
    } catch (error) {
        checkbox.checked = !checkbox.checked; // not saved: show the stored state
        showResultMessage('Error updating right: ' + error.message, false);
    } finally {
        checkbox.disabled = false;
    }
}

async function removeGroup(event, button) {
    const row = button.closest('tr');
    button.disabled = true;
    try {
        await api('admin', { data: { a: 'removeAccessRight', group: row.dataset.group, cwd: currentDirectory() } });
        row.remove();
    } catch (error) {
        button.disabled = false;
        showResultMessage('Error removing group: ' + error.message, false);
    }
}

// ---------------------------------------------------------------------------
// Uploads (Dropzone; protocol in PLAN.md §3.4)
// ---------------------------------------------------------------------------

function initDropzone() {
    const form = byId('fileUploader');
    if (!form) return;
    form.classList.add('dropzone');

    const dropzone = new Dropzone(form, {
        url: "upload",
        method: "post",
        // Every file goes through the chunked protocol, even a one-chunk or
        // empty one: the server verifies and commits it in a separate step
        chunking: true,
        forceChunking: true,
        chunkSize: 2 * 1024 * 1024,
        // No client-side limit (Dropzone's default is 256 MiB): evidence has
        // no size limit by default (D16); the server's probe checks
        // upload_max_size and the free space
        maxFilesize: null,
        parallelChunkUploads: false,
        // Safe: the server writes each chunk at its own offset
        retryChunks: true,
        retryChunksLimit: 3,
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
        addRemoveLinks: true,
        dictRemoveFile: "Dismiss",
        dictCancelUploadConfirmation: "Cancel this upload?",

        // Refused before any byte is sent: rights, size, name already taken
        accept: function (file, done) {
            uploadRequest({ a: 'checkRights', cwd: currentDirectory(), name: file.name, size: file.size })
                .then(() => done(), error => done(error.message));
        },

        // All chunks are in: the server hashes the stored file and commits it
        chunksUploaded: function (file, done) {
            completeUpload(file)
                .then(state => {
                    if (file.status === Dropzone.CANCELED) return;
                    file.uploadResult = state.file;
                    done();
                })
                .catch(error => {
                    if (file.status === Dropzone.CANCELED) return;
                    file.status = Dropzone.ERROR;
                    dropzone.emit("error", file, error.message);
                    dropzone.emit("complete", file);
                    dropzone.processQueue();
                });
        },

        init: function () {
            this.on("success", function (file) {
                setUploadStage(file, 'Stored and verified');
                const stored = file.uploadResult;
                if (stored) {
                    addRow({ type: 'f', id: stored.id, name: stored.name, size: stored.size, date: stored.uploaded_at,
                             by: stored.uploaded_by, from: stored.uploaded_from, sha256: stored.sha256 });
                }
            });

            this.on("canceled", function (file) {
                // Removes the server's staging copy; nothing to do if no chunk was sent
                uploadRequest({ a: 'abort', dzuuid: file.upload.uuid }).catch(() => {});
            });

            // Server errors are JSON objects: show their message, not "[object Object]"
            this.on("error", function (file, message) {
                const text = typeof message === 'string' ? message : (message && message.message) || 'Upload failed.';
                file.previewElement?.querySelectorAll('[data-dz-errormessage]').forEach(node => { node.textContent = text; });
                // Dropzone shows the message on hover only: keep the reason visible
                setUploadStage(file, text);
                file.previewElement?.querySelector('.dz-stage')?.classList.add('text-danger');
                console.warn("Upload refused or failed:", text);
            });
        }
    });
}

/**
 * POST to the upload endpoint. Resolves with the JSON body of a 2xx answer,
 * rejects with an ApiError carrying the server's message and `status` (0 for
 * a network failure).
 */
function uploadRequest(fields) {
    return api('upload', { data: fields });
}

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

/**
 * Ask the server to verify and commit, and follow the verification with
 * status polls. The complete request runs for as long as the hashing takes;
 * if it is cut (proxy timeout, network), the verification goes on on the
 * server and the polls pick up the outcome.
 */
async function completeUpload(file) {
    const uuid = file.upload.uuid;
    let settled = false;
    setUploadStage(file, 'Verifying stored file…');

    // Resolves with the final state, or null when the outcome must come from the polls
    // (202: verifying elsewhere; 5xx or network: the request was cut)
    const completion = uploadRequest({ a: 'complete', dzuuid: uuid })
        .then(state => state.status === 'completed' ? state : null,
              error => { if (error.status >= 400 && error.status < 500) throw error; return null; });
    completion.then(state => { settled = state !== null; }, () => { settled = true; });

    const polling = (async () => {
        let failures = 0;
        for (;;) {
            await sleep(1000);
            if (settled || file.status === Dropzone.CANCELED) return null;
            try {
                const state = await uploadRequest({ a: 'status', dzuuid: uuid });
                failures = 0;
                if (state.status === 'completed') return state;
                if (state.status !== 'verifying') throw Object.assign(new Error(state.message || `Upload ${state.status}.`), { status: 409 });
                if (state.queued) {
                    setUploadStage(file, 'Waiting for your other uploads to be verified…');
                    continue;
                }
                const percent = state.size ? Math.floor(100 * state.verified_bytes / state.size) : 100;
                setUploadStage(file, `Verifying stored file… ${percent}%`);
            } catch (error) {
                if (error.status >= 400 && error.status < 500) throw error;
                if (++failures >= 30) throw new Error('Lost contact with the server during verification.');
            }
        }
    })();
    polling.catch(() => {}); // awaited below, only if needed

    const state = (await completion) ?? (await polling);
    if (!state) throw new Error('Upload canceled.');
    return state;
}

/** A line of text under the file's preview: the upload stage */
function setUploadStage(file, text) {
    const preview = file.previewElement;
    if (!preview) return;
    let stage = preview.querySelector('.dz-stage');
    if (!stage) {
        stage = h('div', { class: 'dz-stage' });
        preview.appendChild(stage);
    }
    stage.textContent = text;
}

// ---------------------------------------------------------------------------
// Refresh from storage (administrators; protocol in RefreshController)
// ---------------------------------------------------------------------------

const refresh = {
    running: false,     // a job of this page is in progress
    stopping: false,    // Stop pressed: the job is canceled on the server
    started: false,     // records may have changed: the listing reloads when the dialog closes
    id: null,           // the job's refresh_id
    after: 0,           // highest file position shown (refreshStatus' `after`)
};

const RESULTS = {
    pending: ['Waiting', 'text-bg-light'],
    hashing: ['Hashing…', 'text-bg-info'],
    imported: ['Imported', 'text-bg-success'],
    verified: ['Verified', 'text-bg-success'],
    mismatch: ['Differs from record', 'text-bg-danger'],
    skipped: ['Skipped', 'text-bg-secondary'],
    failed: ['Failed', 'text-bg-danger'],
};

function initRefresh() {
    const modal = byId('refreshModal');
    if (!modal) return;
    byId('refreshStartButton').addEventListener('click', startRefresh);
    byId('refreshCancelButton').addEventListener('click', stopRefresh);
    modal.addEventListener('show.bs.modal', () => { if (!refresh.running) resetRefresh(); });
    // The records changed: show them
    modal.addEventListener('hidden.bs.modal', () => { if (refresh.started) window.location.reload(); });
    // Leaving the page doesn't stop the server's step, but nothing would start the next one
    window.addEventListener('beforeunload', event => { if (refresh.running) event.preventDefault(); });
}

function resetRefresh() {
    Object.assign(refresh, { running: false, stopping: false, started: false, id: null, after: 0 });
    byId('refreshIntro').classList.remove('d-none');
    for (const id of ['refreshSummary', 'refreshMissing', 'refreshSkipped', 'refreshProgress', 'refreshOutcome', 'refreshResults']) {
        byId(id).classList.add('d-none');
    }
    byId('refreshResults').tBodies[0].replaceChildren();
    byId('refreshStartButton').classList.remove('d-none');
    setRefreshRunning(false);
}

/** While running, the dialog can only be stopped, not closed */
function setRefreshRunning(running) {
    refresh.running = running;
    byId('refreshCloseButton').classList.toggle('d-none', running);
    byId('refreshCloseX').classList.toggle('d-none', running);
    const stop = byId('refreshCancelButton');
    stop.classList.toggle('d-none', !running);
    stop.disabled = false;
}

async function startRefresh() {
    byId('refreshStartButton').classList.add('d-none');
    byId('refreshIntro').classList.add('d-none');
    setRefreshRunning(true);

    let state;
    try {
        state = await api('./', { data: { a: 'refresh', cwd: currentDirectory() } });
    } catch (error) {
        setRefreshRunning(false);
        showRefreshOutcome(error.message, 'danger');
        return;
    }
    refresh.started = true;
    refresh.id = state.refresh_id;
    showComparison(state);
    try {
        state = await followRefresh(state);
    } catch (error) {
        setRefreshRunning(false);
        showRefreshOutcome(error.message, 'danger');
        return;
    }
    setRefreshRunning(false);
    showRefreshEnd(state);
}

/** The comparison: what was recorded at once, and the files to hash (one row each, by position) */
function showComparison(state) {
    const { comparison: c, counts } = state;
    const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;
    const parts = [`${plural(c.new_files.length, 'file')} to import, ${plural(c.existing_files.length, 'file')} to verify.`];
    if (c.new_folders.length) {
        parts.unshift(`${plural(counts.new_folders, 'new folder')} recorded: ${c.new_folders.join(', ')}.`);
    }
    byId('refreshCounts').textContent = parts.join(' ');

    const missing = byId('refreshMissing');
    missing.replaceChildren(...(c.missing_files.length ? [
        h('strong', {}, `Missing from storage (${c.missing_files.length}): `),
        c.missing_files.map(f => f.name).join(', '),
        '. Their records are kept and flagged.'] : []));
    missing.classList.toggle('d-none', !c.missing_files.length);

    const skipped = byId('refreshSkipped');
    skipped.replaceChildren(...(c.skipped.length ? [
        h('strong', {}, `Ignored in storage (${c.skipped.length}):`),
        h('ul', { class: 'mb-0' }, c.skipped.map(entry => h('li', {}, h('code', {}, entry.name), ` — ${entry.reason}`)))] : []));
    skipped.classList.toggle('d-none', !c.skipped.length);
    byId('refreshSummary').classList.remove('d-none');

    const rows = [...c.new_files.map(f => [f, 'new']), ...c.existing_files.map(f => [f, 'existing'])]
        .map(([file, kind], index) => h('tr', { dataset: { position: index + 1 } },
            h('td', { class: 'text-break' }, file.name, kind === 'new' ? h('span', { class: 'text-secondary' }, ' (new)') : null),
            h('td', { class: 'text-end text-nowrap' }, file.size.toLocaleString('en')),
            h('td', { class: 'refresh-result' }, resultBadge('pending'))));
    byId('refreshResults').tBodies[0].replaceChildren(...rows);
    byId('refreshResults').classList.toggle('d-none', !rows.length);
}

const resultBadge = status => h('span', { class: `badge ${RESULTS[status]?.[1] ?? 'text-bg-light'}` }, RESULTS[status]?.[0] ?? status);

/** One file's result in its row (each position once: steps and polls may both report it) */
function showFileResult(file) {
    const row = byId('refreshResults').querySelector(`tr[data-position="${file.position}"]`);
    refresh.after = Math.max(refresh.after, file.position);
    if (!row) return;
    const cell = row.querySelector('.refresh-result');
    cell.replaceChildren(resultBadge(file.status));
    if (file.reason) {
        cell.append(h('div', { class: 'small text-secondary' }, file.reason));
    } else if (file.sha256) {
        cell.append(h('div', { class: 'small text-secondary checksum', title: file.sha256 }, file.sha256));
    }
}

function showProgress(state) {
    const box = byId('refreshProgress');
    box.classList.toggle('d-none', state.status !== 'in_progress' || !state.total);
    const done = state.total - state.counts.remaining;
    byId('refreshFileCount').textContent = `${done} / ${state.total} files`;
    const current = state.current;
    const percent = current?.size ? Math.floor(100 * current.hashed_bytes / current.size) : 0;
    byId('refreshCurrent').textContent = current ? `Hashing ${current.name}… ${percent}%` : '';
    byId('refreshBar').style.width = `${current ? percent : 0}%`;
    box.querySelector('.progress').setAttribute('aria-valuenow', String(current ? percent : 0));
    if (current) {
        const row = byId('refreshResults').querySelector(`tr[data-position] .refresh-result .badge.text-bg-light`);
        row?.replaceWith(resultBadge('hashing'));
    }
}

/** The job's state, with the results not shown yet */
async function pollRefresh(id) {
    const state = await api('./', { data: { a: 'refreshStatus', refresh_id: id, after: refresh.after } });
    state.files.forEach(showFileResult);
    showProgress(state);
    return state;
}

/**
 * Run the job's steps one after the other. A step runs for as long as its
 * file takes to hash; polls show the progress meanwhile. If a step's request
 * is cut (proxy timeout, network), the step goes on on the server: the polls
 * follow it, then the next step starts (or waits, `busy`, while it ends).
 */
async function followRefresh(state) {
    const id = state.refresh_id;
    showProgress(state);
    let failures = 0;
    while (state.status === 'in_progress' && !refresh.stopping) {
        let settled = false;
        const step = api('./', { data: { a: 'refreshStep', refresh_id: id } })
            .then(result => result, error => { if (error.status >= 400 && error.status < 500) throw error; return null; })
            .finally(() => { settled = true; });
        step.catch(() => {}); // awaited below

        while (!settled) {
            await Promise.race([sleep(1000), step.catch(() => {})]);
            if (settled) break;
            try {
                state = await pollRefresh(id);
                failures = 0;
            } catch (error) {
                if (error.status >= 400 && error.status < 500) throw error;
            }
        }

        const result = await step;
        if (result) {
            failures = 0;
            state = result;
            if (result.file) showFileResult(result.file);
            showProgress(state);
            if (result.busy) await sleep(1000);
            continue;
        }
        // The request was cut: the server's step goes on
        await sleep(1000);
        try {
            state = await pollRefresh(id);
            failures = 0;
        } catch (error) {
            if (error.status >= 400 && error.status < 500) throw error;
            if (++failures >= 30) throw new Error('Lost contact with the server during the refresh.');
        }
    }
    // Stopped, or ended: the last results
    return pollRefresh(id);
}

async function stopRefresh() {
    const button = byId('refreshCancelButton');
    button.disabled = true;
    refresh.stopping = true;
    try {
        await api('./', { data: { a: 'refreshCancel', refresh_id: refresh.id } });
    } catch (error) {
        showResultMessage('Could not stop the refresh: ' + error.message, false);
    }
}

function showRefreshEnd(state) {
    byId('refreshProgress').classList.add('d-none');
    const c = state.counts;
    const found = [
        `${c.imported} imported`, `${c.verified} verified`,
        c.mismatches ? `${c.mismatches} differ from their record` : null,
        c.missing ? `${c.missing} missing` : null,
        c.skipped ? `${c.skipped} skipped` : null,
        c.failed ? `${c.failed} failed` : null,
    ].filter(Boolean).join(', ');
    const trouble = c.mismatches || c.missing || c.failed;
    if (state.status === 'completed') {
        showRefreshOutcome(`Refresh completed: ${found}.`, trouble ? 'warning' : 'success');
    } else if (state.status === 'canceled') {
        showRefreshOutcome(`Refresh stopped: ${found}; ${c.remaining} file(s) not processed, left as they are.`, 'secondary');
    } else {
        showRefreshOutcome(`Refresh ${state.status}: ${found}.`, 'secondary');
    }
}

function showRefreshOutcome(text, kind) {
    const outcome = byId('refreshOutcome');
    outcome.className = `alert alert-${kind} py-2`;
    outcome.textContent = text;
}
