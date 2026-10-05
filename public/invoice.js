const supplier = document.getElementById('form_supplier');
if (supplier) supplier.addEventListener('change', () => {
    const option = supplier.selectedOptions[0];
    if (!option?.value) return;
    document.getElementById('form_name').value = option.dataset.name;
    document.getElementById('form_label').value = option.dataset.label;
});

const form = document.getElementById('invoice-validation');
if (form) {
    const total = document.getElementById('form_total');
    const confirmed = document.getElementById('form_confirmed');
    const save = document.getElementById('invoice-save');
    const arithmetic = document.getElementById('invoice-arithmetic');
    const fields = [...form.querySelectorAll('input:not([type=hidden]), select')];

    const update = () => {
        const parts = /^(0|[1-9][0-9]{0,6})(?:[.,]([0-9]{1,2}))?$/.exec(total.value.trim());
        const cents = parts ? Number(parts[1]) * 100 + Number((parts[2] || '').padEnd(2, '0')) : null;
        total.setCustomValidity(cents !== null && cents > 0 && cents <= 100000000 ? '' : 'Saisir un TTC positif, au maximum 1 000 000 MAD, avec deux décimales au plus.');
        if (arithmetic) arithmetic.hidden = cents === null || cents === Number(arithmetic.dataset.expectedCents);

        const valid = fields.map(field => {
            const invalid = !field.validity.valid || (field.maxLength >= 0 && [...field.value].length > field.maxLength);
            field.classList.toggle('invoice-field-invalid', invalid);
            field.classList.toggle('invoice-field-warning', !invalid && !confirmed.checked && field.type !== 'checkbox' && field.value.trim() !== '');
            field.setAttribute('aria-invalid', String(invalid));

            return !invalid;
        });
        save.classList.toggle('invoice-ready', valid.every(Boolean) && confirmed.checked);
    };

    const edit = event => {
        if (fields.includes(event.target) && event.target !== confirmed) confirmed.checked = false;
        update();
    };
    form.addEventListener('input', edit);
    form.addEventListener('change', edit);
    update();
}
