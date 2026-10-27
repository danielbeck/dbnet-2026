const pageDialog = document.querySelector('#page-dialog');
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        for (const modal of document.querySelectorAll('#page-dialog[open], #pool-dialog[open]')) {
            event.preventDefault();
            modal.close();
        }
    }
});
if (pageDialog) {
    document.querySelector('[data-open-page]').addEventListener('click', event => {
        event.preventDefault();
        pageDialog.showModal();
    });
    pageDialog.querySelector('[data-page-cancel]').addEventListener('click', event => {
        event.preventDefault();
        pageDialog.close();
    });
    pageDialog.addEventListener('close', () => pageDialog.querySelector('form').reset());
}

const dialog = document.querySelector('#pool-dialog');
if (dialog) {
    document.querySelector('[data-open-pool]')?.addEventListener('click', event => {
        event.preventDefault();
        dialog.showModal();
    });
    dialog.querySelector('[data-pool-cancel]').addEventListener('click', event => {
        event.preventDefault();
        dialog.close();
    });
    dialog.addEventListener('close', () => dialog.querySelector('form').reset());
}

for (const form of document.querySelectorAll('.pool-editor')) {
    const title = form.elements.title;
    const slug = form.elements.slug;
    let autoSlug = !form.elements.id.value;
    slug.addEventListener('input', () => { autoSlug = false; });
    title.addEventListener('input', () => {
        if (autoSlug) {
            slug.value = title.value.toLowerCase().replace(/[^\w\s-]/g, '').trim().replace(/[\s_-]+/g, '-');
        }
    });
    const updateType = () => {
        for (const field of form.querySelectorAll('[data-type-field]')) {
            field.hidden = field.dataset.typeField !== form.elements.item_type.value;
            field.disabled = field.hidden;
        }
    };
    form.elements.item_type.addEventListener('change', updateType);
    updateType();

    const tags = form.querySelector('[data-tags]');
    const originalTags = tags.innerHTML;
    const addTag = form.querySelector('[data-add-tag]');
    addTag.hidden = false;
    const updateTagButtons = () => {
        for (const button of tags.querySelectorAll('[data-remove-tag]')) {
            button.hidden = tags.children.length < 2;
        }
    };
    addTag.addEventListener('click', () => {
        const row = tags.firstElementChild.cloneNode(true);
        row.querySelector('input').value = '';
        tags.append(row);
        updateTagButtons();
        row.querySelector('input').focus();
    });
    tags.addEventListener('click', event => {
        if (event.target.closest('[data-remove-tag]') && tags.children.length > 1) {
            event.target.closest('.tag-row').remove();
            updateTagButtons();
        }
    });
    updateTagButtons();

    const fix = form.querySelector('[data-fix-html]');
    const updateFormat = () => { fix.hidden = form.elements.format.value !== 'html'; };
    form.elements.format.addEventListener('change', updateFormat);
    updateFormat();
    fix.addEventListener('click', () => {
        const template = document.createElement('template');
        template.innerHTML = form.elements.body.value;
        form.elements.body.value = template.innerHTML;
    });
    const previews = [...form.querySelectorAll('[data-image-preview], [data-thumbnail-preview]')];
    const originals = previews.map(image => image.getAttribute('src'));
    let previewUrl = null;
    const updateImage = () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = form.elements.image.files[0] ? URL.createObjectURL(form.elements.image.files[0]) : null;
        previews.forEach((image, index) => {
            const src = previewUrl || (form.elements.remove_image.checked ? '' : originals[index]);
            if (src) image.src = src;
            else image.removeAttribute('src');
            image.hidden = !src;
        });
    };
    form.elements.image.addEventListener('change', () => {
        form.elements.remove_image.checked = false;
        updateImage();
    });
    form.elements.remove_image.addEventListener('change', () => {
        if (form.elements.remove_image.checked) form.elements.image.value = '';
        updateImage();
    });
    form.addEventListener('reset', () => {
        queueMicrotask(() => {
            tags.innerHTML = originalTags;
            autoSlug = !form.elements.id.value;
            updateTagButtons();
            updateType();
            updateFormat();
            updateImage();
        });
    });
    form.addEventListener('submit', event => {
        if (event.submitter?.matches('[data-delete-pool]') && !confirm('Delete this pool item?')) {
            event.preventDefault();
        }
    });
}
