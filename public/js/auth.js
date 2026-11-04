document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const input = document.getElementById(toggle.dataset.passwordToggle);
            if (!input) {
                return;
            }

            const showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', String(showPassword));
            toggle.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
            toggle.querySelector('use')?.setAttribute('href', showPassword ? '#icon-eye-off' : '#icon-eye');
        });
    });

    document.querySelectorAll('form[data-loading-label]').forEach((form) => {
        form.addEventListener('submit', () => {
            const submitButton = form.querySelector('button[type="submit"]');
            if (!submitButton) {
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = form.dataset.loadingLabel;
        });
    });

    // "Remember me" is only offered to administrators. Athletes sign in on
    // shared school computers, so a long-lived login cookie is kept off their
    // screen. The checkbox itself is only shown when Administrator is chosen.
    const roleSelect = document.getElementById('role');
    const rememberRow = document.getElementById('remember-row');

    if (roleSelect && rememberRow) {
        const syncRememberVisibility = () => {
            rememberRow.hidden = roleSelect.value !== 'Administrator';
        };

        roleSelect.addEventListener('change', syncRememberVisibility);
        syncRememberVisibility();
    }
});