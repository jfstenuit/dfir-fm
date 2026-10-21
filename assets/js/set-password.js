// assets/js/set-password.js - The page of an invitation link: set a password, then log in
//
// An expired CSRF token makes api() reload the page, which shows the form
// again: the GET never uses up the invitation.

import { api } from './utils.js';

const MIN_LENGTH = 12;   // Core\PasswordPolicy::MIN_LENGTH, also checked by the server

const byId = id => document.getElementById(id);
const form = byId('setPasswordForm');
const button = byId('setPasswordBtn');
const say = text => { byId('loginMessage').textContent = text; };

form.addEventListener('submit', async event => {
    event.preventDefault();
    say('');
    const password = byId('password').value;
    if ([...password].length < MIN_LENGTH) {   // characters, as the server counts them
        say(`The password must have at least ${MIN_LENGTH} characters.`);
        return;
    }
    if (password !== byId('confirmation').value) {
        say('The two passwords differ.');
        return;
    }

    button.disabled = true;
    try {
        const result = await api('login', { data: {
            action: 'setPassword',
            email: byId('email').value,
            token: byId('token').value,
            directory: byId('directory').value,
            password,
        }});
        window.location.href = result.redirect || '.';
    } catch (error) {
        if (error.code !== 'csrf_invalid') {
            say(error.message);
            button.disabled = false;
        }
    }
});

// Ready: the button is rendered disabled so that nothing submits natively before this point
button.disabled = false;
