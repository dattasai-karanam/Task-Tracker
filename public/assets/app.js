document.documentElement.classList.add('js');

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (form.matches('[data-task-form]')) {
        const title = form.querySelector('[name="title"]');
        const titleError = form.querySelector('[data-validation-for="title"]');
        if (title && titleError) {
            title.value = title.value.trim();
            const isEmpty = title.value === '';
            titleError.hidden = !isEmpty;
            titleError.textContent = isEmpty ? 'Please enter a task title.' : '';
            title.setAttribute('aria-invalid', String(isEmpty));
            if (isEmpty) {
                event.preventDefault();
                title.focus();
                return;
            }
        }
    }

    if (form.dataset.authForm !== undefined) {
        const password = form.querySelector('#password');
        const confirmation = form.querySelector('#password-confirmation');
        if (password && confirmation) {
            confirmation.setCustomValidity(
                password.value === confirmation.value ? '' : 'The passwords do not match.'
            );
            if (!form.reportValidity()) {
                event.preventDefault();
                return;
            }
        }
    }

    const button = form.querySelector('button[type="submit"]:not([data-keep-label])');
    if (button && !button.disabled) {
        button.disabled = true;
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        button.dataset.originalLabel = button.textContent.trim();
        button.textContent = button.dataset.loadingLabel || 'Saving…';
    }
});

document.querySelectorAll('[data-task-form] [name="title"]').forEach((title) => {
    title.addEventListener('input', () => {
        const error = title.form?.querySelector('[data-validation-for="title"]');
        if (error && title.value.trim() !== '') {
            error.hidden = true;
            error.textContent = '';
            title.setAttribute('aria-invalid', 'false');
        }
    });
});

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-password-toggle]');
    if (!toggle) {
        return;
    }

    const input = document.getElementById(toggle.dataset.passwordToggle);
    if (!input) {
        return;
    }

    const showPassword = input.type === 'password';
    input.type = showPassword ? 'text' : 'password';
    toggle.textContent = showPassword ? 'Hide' : 'Show';
    toggle.setAttribute('aria-label', `${showPassword ? 'Hide' : 'Show'} ${input.id === 'password' ? 'password' : 'confirmation password'}`);
    toggle.setAttribute('aria-pressed', String(showPassword));
    input.focus();
});

document.querySelectorAll('[data-auth-form]').forEach((form) => {
    const password = form.querySelector('#password');
    const confirmation = form.querySelector('#password-confirmation');
    if (password && confirmation) {
        const checkMatch = () => {
            confirmation.setCustomValidity(
                confirmation.value !== '' && confirmation.value !== password.value
                    ? 'The passwords do not match.'
                    : ''
            );
        };
        password.addEventListener('input', checkMatch);
        confirmation.addEventListener('input', checkMatch);
    }

    const meter = form.querySelector('[data-password-meter]');
    if (password && meter) {
        const fill = meter.querySelector('.password-meter-track span');
        const message = meter.querySelector('small');
        password.addEventListener('input', () => {
            const length = Array.from(password.value).length;
            meter.hidden = password.value.length === 0;
            const level = length >= 16 ? 3 : length >= 12 ? 2 : length > 0 ? 1 : 0;
            meter.dataset.level = String(level);
            fill.style.width = `${level * 33.333}%`;
            message.textContent = level === 3
                ? 'Good length — memorable passphrases work well.'
                : level === 2
                    ? 'Good length. You can use up to 72 bytes.'
                    : 'Add at least 12 characters.';
        });
    }
});

document.querySelectorAll('[data-count-input]').forEach((input) => {
    const counter = document.querySelector(`[data-count-for="${input.id}"]`);
    if (!counter) {
        return;
    }

    const maxLength = Number(input.getAttribute('maxlength')) || 0;
    const updateCount = () => {
        const count = input.hasAttribute('data-count-bytes')
            ? new TextEncoder().encode(input.value).length
            : Array.from(input.value).length;
        counter.textContent = `${count.toLocaleString()} / ${maxLength.toLocaleString()}`;
        counter.classList.toggle('is-near-limit', maxLength > 0 && count / maxLength >= 0.9);
    };
    input.addEventListener('input', updateCount);
    updateCount();
});

document.querySelectorAll('[data-quick-status]').forEach((select) => {
    select.addEventListener('change', () => {
        if (select.form && typeof select.form.requestSubmit === 'function') {
            select.form.requestSubmit();
        }
    });
});

const filterForm = document.querySelector('[data-live-filter]');
if (filterForm) {
    const search = filterForm.querySelector('[data-live-search]');
    const status = filterForm.querySelector('[data-live-status]');
    let searchTimer;

    if (search) {
        if (search.value !== '') {
            search.focus();
            search.setSelectionRange(search.value.length, search.value.length);
        }
        search.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => filterForm.requestSubmit(), 300);
        });
    }

    if (status) {
        status.addEventListener('change', () => filterForm.requestSubmit());
    }

    document.addEventListener('keydown', (event) => {
        if (
            event.key === '/'
            && !event.ctrlKey
            && !event.metaKey
            && !event.altKey
            && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)
        ) {
            event.preventDefault();
            search?.focus();
        }
    });
}
