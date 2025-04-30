$(function() {
    $('#nextBtn').on('click', function () {
        const email = $('#username').val().trim();
        $('#loginMessage').text('');

        if (!email) return $('#loginMessage').text('Please enter your email.');

        $.ajax({ url: 'login', method: 'POST', dataType: 'json', data: { action: 'checkUser', email }})
            .done(data => {
                $('#step-login').addClass('slide-out-left');
                setTimeout(() => {
                    $('#step-login').addClass('d-none');
                    $('#step-password').removeClass('d-none').addClass('slide-in-right');
                    $('#password').focus();
                }, 300);

                // For future use
                if (data.login_type === 'oidc') {
                    $('#step-login').addClass('slide-out-left');
                    setTimeout(() => {
                        $('#step-login').addClass('d-none');
                        $('#step-password').removeClass('d-none').addClass('slide-in-right');
                    }, 300);
                }
            })
            .fail(() => {
                $('#loginMessage').text('Server error. Please try again.');
            });
    });

    $('#username').on('keypress', function (e) {
        if (e.which === 13) {
            $('#nextBtn').click();
        }
    });

    $('#password').on('keypress', function (e) {
        if (e.which === 13) {
            $('#loginBtn').click();
        }
    });

    $('#loginBtn').on('click', function () {
        const action = 'login';
        const username = $('#username').val();
        const password = $('#password').val();
        const redirect = $('#redirect').val();

        $('#loginMessage').text('');
        $('#loginBtn, #password').prop('disabled', true);

        $.ajax({ url: 'login', method: 'POST', dataType: 'json', data: { action, username, password, redirect }})
            .done(data => {
                if (data.success) {
                    window.location.href = data.redirect || '.';
                } else {
                    $('#loginMessage').text(data.message || 'Login failed.');
                    setTimeout(() => {
                        $('#loginMessage').text('');
                        $('#step-password').addClass('slide-out-right');
                        setTimeout(() => {
                            $('#step-password').addClass('d-none').removeClass('slide-in-right slide-out-right');
                            $('#step-login').removeClass('d-none').addClass('slide-in-left');
                            $('#username').focus();
                            $('#loginBtn, #password').prop('disabled', false);
                        }, 300);
                    }, 1000);
                }
            })
            .fail(() => {
                $('#loginMessage').text('Network error.');
                $('#loginBtn, #password').prop('disabled', false);
            });
    });

    $('#oidcBtn').on('click', function () {
        window.location.href = '/login/oidc';
    });
});
