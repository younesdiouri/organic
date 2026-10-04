const supplier = document.getElementById('form_supplier');
if (supplier) supplier.addEventListener('change', () => {
    const option = supplier.selectedOptions[0];
    if (!option?.value) return;
    document.getElementById('form_name').value = option.dataset.name;
    document.getElementById('form_label').value = option.dataset.label;
});

const arithmetic = document.getElementById('invoice-arithmetic');
const total = document.getElementById('form_total');
if (arithmetic && total) total.addEventListener('input', () => {
    const parts = /^(0|[1-9][0-9]{0,6})(?:[.,]([0-9]{1,2}))?$/.exec(total.value.trim());
    const cents = parts ? Number(parts[1]) * 100 + Number((parts[2] || '').padEnd(2, '0')) : null;
    arithmetic.hidden = cents === null || cents === Number(arithmetic.dataset.expectedCents);
});
