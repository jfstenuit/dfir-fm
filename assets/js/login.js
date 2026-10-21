// assets/js/login.js - Two-step login: email, then password
//
// An expired session or CSRF token makes api() reload the page (a fresh
// token); nothing else to do here for that case.

import { api } from './utils.js';

const SLIDE_MS = 300;                // the slide-* animations in login.css
const SLIDES = ['slide-in-left', 'slide-in-right', 'slide-out-left', 'slide-out-right'];

const byId = id => document.getElementById(id);
const stepLogin = byId('step-login');
const stepPassword = byId('step-password');
const username = byId('username');
const password = byId('password');
const nextBtn = byId('nextBtn');
const loginBtn = byId('loginBtn');
const message = byId('loginMessage');

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const say = text => { message.textContent = text; };

/** Slide one step out and the other in: 'forward' (to the password) or 'back' */
async function slide(from, to, direction) {
    const [out, into] = direction === 'forward' ? ['slide-out-left', 'slide-in-right'] : ['slide-out-right', 'slide-in-left'];
    from.classList.remove(...SLIDES);
    from.classList.add(out);
    await sleep(SLIDE_MS);
    from.classList.add('d-none');
    from.classList.remove(out);
    to.classList.remove('d-none', ...SLIDES);
    to.classList.add(into);
}

function setPasswordStepEnabled(enabled) {
    password.disabled = !enabled;
    loginBtn.disabled = !enabled;
}

// Step 1: the email. The answer doesn't say whether the account exists.
stepLogin.addEventListener('submit', async event => {
    event.preventDefault();
    say('');
    const email = username.value.trim();
    if (!email) {
        say('Please enter your email.');
        return;
    }

    nextBtn.disabled = true;
    try {
        // login_type is always "password" for now (OIDC: PLAN.md F1)
        await api('login', { data: { action: 'checkUser', email } });
        await slide(stepLogin, stepPassword, 'forward');
        password.focus();
    } catch (error) {
        if (error.status === 429) {
            say(error.message);
        } else if (error.code !== 'csrf_invalid') {
            say('Server error. Please try again.');
        }
    } finally {
        nextBtn.disabled = false;
    }
});

// Step 2: the password. A refusal shows the server's message for a second,
// then goes back to step 1.
stepPassword.addEventListener('submit', async event => {
    event.preventDefault();
    say('');
    setPasswordStepEnabled(false);

    let result;
    try {
        result = await api('login', { data: {
            action: 'login',
            username: username.value,
            password: password.value,
            redirect: byId('redirect').value,
        }});
    } catch (error) {
        if (error.code === 'csrf_invalid') {
            return; // reloading
        }
        if (error.status !== 200) { // not a refusal: network or server failure
            say(error.status === 0 ? 'Network error.' : error.message);
            setPasswordStepEnabled(true);
            return;
        }
        say(error.message || 'Login failed.');
        await sleep(1000);
        say('');
        await slide(stepPassword, stepLogin, 'back');
        username.focus();
        setPasswordStepEnabled(true);
        return;
    }
    window.location.href = result.redirect || '.';
});

byId('oidcBtn')?.addEventListener('click', () => {
    window.location.href = 'login/oidc';
});

// Ready: the buttons are rendered disabled so that nothing submits natively before this point
nextBtn.disabled = false;
loginBtn.disabled = false;
