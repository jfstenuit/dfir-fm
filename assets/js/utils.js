// assets/js/utils.js - Helpers shared by the page scripts (no dependencies
// beyond Bootstrap 5's window.bootstrap for the modal helpers)

// Escape plain text to be safely inserted into innerHTML
export function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Escape a string to be safely used inside an HTML attribute
export function escapeAttribute(value) {
    const div = document.createElement('div');
    div.setAttribute('data-x', value);
    return div.getAttribute('data-x');
}

// Escape URL for use in href, src, etc. (with optional strict whitelist)
export function escapeUrl(url) {
    try {
        const parsed = new URL(url, window.location.origin);
        // Optionally whitelist only same-origin or safe schemes
        if (!['http:', 'https:'].includes(parsed.protocol)) {
            throw new Error('Unsafe URL scheme');
        }
        return parsed.href;
    } catch (e) {
        console.warn('Blocked unsafe URL:', url);
        return '#';
    }
}

/** The page's CSRF token (meta csrf-token), sent by api() as X-CSRF-TOKEN */
export function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

/** A request refused by the server or that never reached it (status 0) */
export class ApiError extends Error {
    constructor(message, { status = 0, code = null, body = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;   // HTTP status; 0: network failure
        this.code = code;       // server's machine-readable code, if any
        this.body = body;       // parsed JSON body, if any
    }
}

// What to do when the session is gone (expired, or CSRF token no longer
// valid): reload, which leads to the login page or a fresh token
let onSessionLost = () => window.location.reload();

/** Replace the session-lost handler (tests) */
export function setSessionLostHandler(handler) {
    onSessionLost = handler;
}

/**
 * Form-encode a plain object the way the PHP side expects: arrays as
 * key[]=a&key[]=b, booleans as "true"/"false", null/undefined left out.
 */
export function formData(fields) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(fields)) {
        if (value === null || value === undefined) continue;
        if (Array.isArray(value)) {
            const name = key.endsWith('[]') ? key : key + '[]';
            value.forEach(item => params.append(name, item));
        } else {
            params.append(key, value);
        }
    }
    return params;
}

/**
 * fetch() wrapper for the application's JSON endpoints.
 *   data: object sent form-encoded (query string for GET)
 *   json: value sent as a JSON body
 *   body: anything fetch() accepts (FormData, Blob, ...)
 * Sends the CSRF token, resolves with the parsed JSON body (null if empty).
 * Throws an ApiError on a network failure, a non-2xx answer, a body that is
 * not JSON, or {success: false}, with the server's message when it gave one.
 * An AbortSignal's AbortError is rethrown as is.
 */
export async function api(url, { method = 'POST', data, json, body, signal } = {}) {
    const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() ?? '' };
    if (data !== undefined) {
        if (method === 'GET') {
            url += (url.includes('?') ? '&' : '?') + formData(data);
        } else {
            body = formData(data);
        }
    } else if (json !== undefined) {
        body = JSON.stringify(json);
        headers['Content-Type'] = 'application/json';
    }

    let response;
    try {
        response = await fetch(url, { method, headers, body, signal, credentials: 'same-origin' });
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new ApiError('Network error: the server could not be reached.', { code: 'network' });
    }

    // Session expired: the server redirected the request to the login page
    if (response.redirected && new URL(response.url).pathname.endsWith('/login')) {
        onSessionLost();
        throw new ApiError('Your session has expired.', { status: 401, code: 'session_expired' });
    }

    const text = await response.text();
    let payload = null;
    try {
        payload = text === '' ? null : JSON.parse(text);
    } catch {
        throw new ApiError(response.ok ? 'Unexpected response from the server.' : `Server error (HTTP ${response.status}).`,
            { status: response.status, code: 'not_json' });
    }

    if (response.status === 403 && payload?.code === 'csrf_invalid') {
        onSessionLost();
    }
    if (!response.ok || payload?.success === false) {
        const message = payload?.message || (response.ok ? 'The operation failed.' : `Server error (HTTP ${response.status}).`);
        throw new ApiError(message, { status: response.status, code: payload?.code ?? null, body: payload });
    }
    return payload;
}

/** An element or a selector for one */
function resolve(target) {
    return typeof target === 'string' ? document.querySelector(target) : target;
}

/** Bootstrap 5 modals, by element or selector */
export function showModal(target) {
    bootstrap.Modal.getOrCreateInstance(resolve(target)).show();
}

/** Resolves once the modal is hidden (at once if it isn't shown) */
export function hideModal(target) {
    const element = resolve(target);
    return new Promise(done => {
        if (!element?.classList.contains('show')) {
            done();
            return;
        }
        element.addEventListener('hidden.bs.modal', () => done(), { once: true });
        setTimeout(done, 1000); // a modal still opening ignores hide(): don't wait forever
        bootstrap.Modal.getOrCreateInstance(element).hide();
    });
}

/** The operation-result dialog (on top of any open dialog) */
export function showResultMessage(message, isSuccess) {
    const body = document.getElementById('operationResultMessage');
    body.textContent = message;
    body.classList.remove('text-success', 'text-danger');
    body.classList.add(isSuccess ? 'text-success' : 'text-danger');
    showModal('#operationResultModal');
}

/** The profile dialog's password change */
export async function updatePassword() {
    const input = document.getElementById('newPassword');
    if (!input.value) {
        showResultMessage('Please enter a new password.', false);
        return;
    }
    try {
        const response = await api('profile', { data: { a: 'updatePassword', password: input.value } });
        await hideModal('#profileModal');
        input.value = '';
        showResultMessage(response?.message || 'Password updated.', true);
    } catch (error) {
        showResultMessage(error.status === 200 ? error.message : 'Failed to update password: ' + error.message, false);
    }
}

/**
 * Event delegation: call handler(event, matched) for events on root whose
 * target is inside an element matching selector (within root). Works for
 * rows added after the call.
 */
export function on(root, type, selector, handler) {
    const container = resolve(root);
    container?.addEventListener(type, event => {
        const origin = event.target instanceof Element ? event.target : event.target.parentElement;
        const matched = origin?.closest(selector);
        if (matched && container.contains(matched)) {
            handler(event, matched);
        }
    });
}

/**
 * Build an element without HTML strings: text children become text nodes,
 * so nothing is ever parsed as markup.
 *   h('a', {href: url, class: 'x', dataset: {id: 3}}, icon('trash'), ' ', name)
 * Attributes that are null, undefined or false are left out; true sets an
 * empty attribute; on<event> functions become listeners.
 */
export function h(tag, attributes = {}, ...children) {
    const element = document.createElement(tag);
    for (const [name, value] of Object.entries(attributes ?? {})) {
        if (value === null || value === undefined || value === false) continue;
        if (name === 'class') {
            element.className = value;
        } else if (name === 'dataset') {
            Object.assign(element.dataset, value);
        } else if (name.startsWith('on') && typeof value === 'function') {
            element.addEventListener(name.slice(2), value);
        } else {
            element.setAttribute(name, value === true ? '' : value);
        }
    }
    element.append(...children.flat().filter(child => child !== null && child !== undefined && child !== false));
    return element;
}

/** A Bootstrap Icons glyph: icon('trash') */
export function icon(name, extraClass = '') {
    return h('i', { class: `bi bi-${name}${extraClass ? ' ' + extraClass : ''}`, 'aria-hidden': 'true' });
}

export function debounce(fn, delay) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

/**
 * Group-name suggestions for a text input: a native <datalist> filled from
 * admin?a=listGroups as the user types. Calling it again on the same input
 * does nothing.
 */
export function groupAutocomplete(input, { delay = 250 } = {}) {
    input = resolve(input);
    if (!input || input.dataset.groupAutocomplete) return;
    input.dataset.groupAutocomplete = 'on';

    const list = h('datalist', { id: `${input.id}-suggestions` });
    input.after(list);
    input.setAttribute('list', list.id);

    let requested = null;
    const refresh = debounce(async () => {
        const query = input.value.trim();
        if (query === requested) return;
        requested = query;
        try {
            const names = await api('admin', { data: { a: 'listGroups', q: query } });
            if (query !== input.value.trim()) return; // the user typed on: a newer request follows
            list.replaceChildren(...(names ?? []).map(name => h('option', { value: name })));
        } catch (error) {
            requested = null;
            console.warn('Group suggestions unavailable:', error.message);
        }
    }, delay);
    input.addEventListener('input', refresh);
    input.addEventListener('focus', refresh);
}
