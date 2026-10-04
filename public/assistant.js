(() => {
    const form = document.getElementById('assistant-form');
    const button = document.getElementById('assistant-submit');
    const spinner = button.querySelector('.spinner-border');
    const label = button.querySelector('.assistant-submit-label');
    const initiallyDisabled = button.disabled;
    let waiting = false;

    form.addEventListener('submit', event => {
        if (waiting || initiallyDisabled) {
            event.preventDefault();
            return;
        }
        if (!form.checkValidity()) return;
        waiting = true;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        spinner.classList.remove('d-none');
        label.textContent = 'Réponse en cours…';
    });

    window.addEventListener('pageshow', () => {
        waiting = false;
        button.disabled = initiallyDisabled;
        button.removeAttribute('aria-busy');
        spinner.classList.add('d-none');
        label.textContent = 'Poser la question';
    });
})();
