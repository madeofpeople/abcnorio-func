(function () {
    const list = document.getElementById('social-links-list');
    const template = document.getElementById('social-links-template');
    let dragged = null;

    document.getElementById('social-links-add').addEventListener('click', () => {
        list.appendChild(template.content.cloneNode(true));
    });

    list.addEventListener('click', (e) => {
        const row = e.target.closest('.social-link-row');
        if (e.target.closest('.social-link-remove')) {
            row.remove();
        }
        if (e.target.closest('.social-link-upload')) {
            const frame = wp.media({ title: 'Select SVG icon', library: { type: 'image/svg+xml' }, multiple: false });
            frame.on('select', () => {
                const attachment = frame.state().get('selection').first().toJSON();
                row.querySelector('[name="icon_id[]"]').value = attachment.id;
                const img = row.querySelector('.social-link-icon');
                img.src = attachment.url;
                img.hidden = false;
            });
            frame.open();
        }
    });

    list.addEventListener('dragstart', (e) => {
        dragged = e.target.closest('.social-link-row');
    });

    list.addEventListener('dragover', (e) => {
        e.preventDefault();
        const target = e.target.closest('.social-link-row');
        if (!dragged || !target || target === dragged) return;
        const after = e.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2;
        list.insertBefore(dragged, after ? target.nextSibling : target);
    });
})();
