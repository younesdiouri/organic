const lines = document.getElementById('delivery-lines');
document.getElementById('add-line').addEventListener('click', () => {
    if (lines.children.length >= 100) return;
    const row = document.createElement('div');
    row.className = 'delivery-line border rounded p-3 mb-3';
    // Symfony generates this escaped prototype; it contains no user supplied HTML.
    row.innerHTML = lines.dataset.prototype.replaceAll('__name__', lines.dataset.index++);
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'remove-line btn btn-outline-danger btn-sm';
    remove.textContent = 'Retirer la ligne';
    row.append(remove);
    lines.append(row);
    updateTotal();
});
lines.addEventListener('click', event => {
    if (event.target.classList.contains('remove-line')) {
        event.target.closest('.delivery-line').remove();
        updateTotal();
    }
});


function updateTotal() {
    let total = 0;
    let valid = lines.children.length > 0;
    for (const row of lines.children) {
        const product = row.querySelector('select');
        const quantity = row.querySelector('input[type="number"]').value;
        const rawPrice = row.querySelector('input[inputmode="decimal"]').value.trim().replace(',', '.');
        const parts = /^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/.exec(rawPrice);
        const price = rawPrice === '' ? Number(product.selectedOptions[0]?.dataset.priceCents) :
            parts ? Number(parts[1]) * 100 + Number((parts[2] || '').padEnd(2, '0')) : NaN;
        if (!product.value || !/^[1-9][0-9]*$/.test(quantity) || Number(quantity) > 100000 || !Number.isFinite(price) || price > 100000000) valid = false;
        total += Number(quantity) * price;
    }
    document.getElementById('delivery-total').textContent = valid ?
        Math.floor(total / 100) + ',' + String(total % 100).padStart(2, '0') + ' MAD' : '—';
}
lines.addEventListener('input', updateTotal);
lines.addEventListener('change', updateTotal);
updateTotal();
