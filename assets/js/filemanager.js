// assets/js/filemanager.js - The file manager page: listing, folders, uploads,
// access rights, invitations, password change

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
