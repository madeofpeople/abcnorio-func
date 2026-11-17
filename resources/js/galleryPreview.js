const TRANSITION_MS = 200;

export function getGallerySlideOffsets(starts, viewportWidth, contentWidth, mode) {
    const maximum = Math.max(0, contentWidth - viewportWidth);
    const offsets = [...new Set([0, ...starts.map((start) => Math.min(maximum, Math.max(0, start))), maximum])];
    if (mode !== 'page' || maximum === 0) {
        return offsets;
    }

    const pages = [0];
    while (pages[pages.length - 1] < maximum) {
        const current = pages[pages.length - 1];
        const candidates = offsets.filter((offset) => offset > current);
        const fitting = candidates.filter((offset) => offset <= current + viewportWidth);
        pages.push(fitting.length ? fitting[fitting.length - 1] : candidates[0]);
    }
    return pages;
}

export function showGalleryPage(gallery, page, pageSize, previousPage, animate) {
    const view = gallery.ownerDocument.defaultView;
    const items = [...gallery.querySelectorAll('.images > [data-type="core/image"]')];
    const timers = [];
    const clearClasses = () => items.forEach((item) => item.classList.remove('is-leaving', 'is-entering'));
    const show = () => items.forEach((item, index) => {
        const visible = page === null || (index >= (page - 1) * pageSize && index < page * pageSize);
        item.toggleAttribute('hidden', !visible);
        item.setAttribute('aria-hidden', visible ? 'false' : 'true');
    });

    if (!animate || page === null || page === previousPage || view.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        show();
    } else {
        gallery.dataset.slide = page > previousPage ? 'next' : 'prev';
        items.filter((item) => !item.hidden).forEach((item) => item.classList.add('is-leaving'));
        timers.push(view.setTimeout(() => {
            clearClasses();
            show();
            items.filter((item) => !item.hidden).forEach((item) => item.classList.add('is-entering'));
            timers.push(view.setTimeout(clearClasses, TRANSITION_MS));
        }, TRANSITION_MS));
    }

    return () => {
        timers.forEach((timer) => view.clearTimeout(timer));
        clearClasses();
        delete gallery.dataset.slide;
    };
}

// Scroll the editable track; never let a frontend carousel clone or reparent InnerBlocks.
export function createGallerySliderPreview(gallery, navigationMode, onChange) {
    const track = gallery.querySelector('.images');
    const viewport = track.parentElement;
    const view = gallery.ownerDocument.defaultView;
    let offsets = [0];
    let navigation;
    let frame;
    const items = () => [...track.querySelectorAll(':scope > [data-type="core/image"]')];
    const publish = () => {
        const index = offsets.reduce((nearest, offset, candidate) =>
            Math.abs(offset - viewport.scrollLeft) < Math.abs(offsets[nearest] - viewport.scrollLeft) ? candidate : nearest, 0);
        if (navigation?.index !== index || navigation?.count !== offsets.length) {
            navigation = { index, count: offsets.length };
            onChange(navigation);
        }
    };
    const measure = () => {
        const left = viewport.getBoundingClientRect().left;
        const slides = items();
        const firstLeft = slides[0]?.getBoundingClientRect().left ?? left;
        const starts = slides.map((item) => Math.round(item.getBoundingClientRect().left - firstLeft));
        const last = slides[slides.length - 1];
        const contentWidth = last ? Math.ceil(last.getBoundingClientRect().right - left + viewport.scrollLeft) : 0;
        offsets = getGallerySlideOffsets(starts, viewport.clientWidth, contentWidth, navigationMode);
        publish();
    };
    const scheduleMeasure = () => {
        view.cancelAnimationFrame(frame);
        frame = view.requestAnimationFrame(measure);
    };
    const observer = new view.ResizeObserver(scheduleMeasure);
    observer.observe(viewport);
    items().forEach((item) => observer.observe(item));
    viewport.addEventListener('scroll', publish, { passive: true });
    track.addEventListener('load', scheduleMeasure, true);
    measure();

    return {
        goTo(index) {
            viewport.scrollTo({
                left: offsets[Math.max(0, Math.min(index, offsets.length - 1))],
                behavior: view.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
            });
        },
        reveal(clientId) {
            const item = items().find((element) => element.dataset.block === clientId);
            if (!item) {
                return;
            }
            const rect = item.getBoundingClientRect();
            const bounds = viewport.getBoundingClientRect();
            if (rect.left < bounds.left || rect.right > bounds.right) {
                viewport.scrollTo({ left: rect.left - bounds.left + viewport.scrollLeft, behavior: 'instant' });
            }
        },
        destroy() {
            observer.disconnect();
            view.cancelAnimationFrame(frame);
            viewport.removeEventListener('scroll', publish);
            track.removeEventListener('load', scheduleMeasure, true);
        },
    };
}
