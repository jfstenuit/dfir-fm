// assets/js/admin-logs.js - Admin page, Logs tab: events from the events database
//
// Newest first, a page at a time: "Load more" asks for the events older than
// the last one shown (keyset paging), so events logged meanwhile never shift
// a page. A new search is logged by the server (logs_viewed); paging isn't.

import { api, h, icon, on, showModal, showResultMessage } from './utils.js';

const byId = id => document.getElementById(id);
let bound = false;
let filters = {};      // of the search shown
let next = null;       // `before` of the next page, null after the last
let shown = 0;
const records = new Map();   // event id => record (JSON text, as stored)

/** Bind the tab's handlers (once) */
export function init() {
    if (bound) return;
    bound = true;
    byId('logsFilterForm').addEventListener('submit', event => {
        event.preventDefault();
        search();
    });
    // After the form has cleared its fields
    byId('logsFilterForm').addEventListener('reset', () => setTimeout(search));
    byId('logsMore').addEventListener('click', () => loadPage());
    on('#logsTable', 'click', 'tbody tr', (event, row) => showRecord(Number(row.dataset.id)));
    on('#logsTable', 'keydown', 'tbody tr', (event, row) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            showRecord(Number(row.dataset.id));
        }
    });
    byId('copyEventRecord').addEventListener('click', copyRecord);
}

/** Called each time the tab is shown */
export function load() {
    init();
    loadActions();
    search();
}

async function loadActions() {
    try {
        const { actions } = await api('admin', { data: { a: 'listEventActions' } });
        const select = byId('logsAction');
        const current = select.value;
        select.replaceChildren(h('option', { value: '' }, 'All actions'),
            ...actions.map(action => h('option', { value: action }, action)));
        select.value = actions.includes(current) ? current : '';
    } catch (error) {
        showResultMessage('Failed to load the actions: ' + error.message, false);
    }
}

/** A new search with the form's filters: the first page */
function search() {
    filters = Object.fromEntries([...new FormData(byId('logsFilterForm'))].filter(([, value]) => value.trim() !== ''));
    next = null;
    shown = 0;
    records.clear();
    document.querySelector('#logsTable tbody').replaceChildren();
    loadPage(true);
}

async function loadPage(first = false) {
    const more = byId('logsMore');
    more.disabled = true;
    try {
        const data = { a: 'listEvents', ...filters };
        if (!first) data.before = next;
        const page = await api('admin', { data });
        document.querySelector('#logsTable tbody').append(...page.events.map(eventRow));
        shown += page.events.length;
        next = page.next;
    } catch (error) {
        showResultMessage('Failed to load the events: ' + error.message, false);
    } finally {
        more.disabled = false;
        more.classList.toggle('d-none', next === null);
        byId('logsCount').textContent = shown === 0 ? 'No events.'
            : `${shown} event${shown === 1 ? '' : 's'} shown${next === null ? '' : ', more available'}.`;
    }
}

/** Fields shown in their own column, left out of the details */
const COLUMNS = ['ts', 'action', 'username', 'ip', 'peer_ip'];

function eventRow(event) {
    records.set(event.id, event.record);
    let record = {};
    try {
        record = JSON.parse(event.record);
    } catch {
        // Shown as stored in the record dialog
    }
    const details = Object.entries(record)
        .filter(([key]) => !COLUMNS.includes(key))
        .map(([key, value]) => `${key}: ${typeof value === 'object' && value !== null ? JSON.stringify(value) : value}`)
        .join(', ');

    return h('tr', { class: 'event-row', tabindex: '0', dataset: { id: event.id }, title: 'Show the full record' },
        h('td', { class: 'text-nowrap' }, formatTime(event.ts)),
        h('td', { class: 'text-nowrap' }, event.action),
        h('td', {}, event.username ?? ''),
        h('td', { class: 'text-nowrap' }, event.ip ?? '',
            event.peer_ip ? h('span', { class: 'text-muted small', title: 'Relayed by this proxy' }, ` via ${event.peer_ip}`) : null),
        h('td', { class: 'event-details' }, details),
        h('td', { class: 'text-center' }, icon('box-arrow-up-right')));
}

/** 2026-10-30T17:56:49+00:00 → 2026-10-30 17:56:49 (the records are UTC) */
function formatTime(ts) {
    const match = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})\+00:00$/.exec(ts ?? '');
    return match ? `${match[1]} ${match[2]}` : (ts ?? '');
}

function showRecord(id) {
    const text = records.get(id);
    if (text === undefined) return;
    let pretty = text;
    try {
        pretty = JSON.stringify(JSON.parse(text), null, 2);
    } catch {
        // Not JSON: as stored
    }
    byId('eventRecordModalLabel').textContent = `Event #${id}`;
    byId('eventRecord').textContent = pretty;
    showModal('#eventRecordModal');
}

async function copyRecord(event) {
    const button = event.currentTarget;
    try {
        await navigator.clipboard.writeText(byId('eventRecord').textContent);
        button.replaceChildren(icon('check-lg'), ' Copied');
    } catch {
        getSelection().selectAllChildren(byId('eventRecord'));
        button.replaceChildren(icon('clipboard'), ' Press Ctrl+C');
    }
    setTimeout(() => button.replaceChildren(icon('clipboard'), ' Copy'), 2000);
}
