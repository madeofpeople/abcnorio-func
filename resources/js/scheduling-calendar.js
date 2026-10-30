(function () {
    const config = window.abcnorioScheduling;
    const storageKey = 'abcnorioSchedulingHidden';
    const hidden = new Set(JSON.parse(localStorage.getItem(storageKey) || '[]'));

    function saveHidden() {
        localStorage.setItem(storageKey, JSON.stringify([...hidden]));
    }

    function addToggle(container, key, name, color, onChange) {
        const item = document.createElement('li');
        const label = document.createElement('label');
        const input = document.createElement('input');
        const swatch = document.createElement('span');

        input.type = 'checkbox';
        input.checked = !hidden.has(key);
        swatch.className = 'abcnorio-cal-swatch';
        swatch.style.background = color;
        swatch.setAttribute('aria-hidden', 'true');

        input.addEventListener('change', function () {
            if (input.checked) {
                hidden.delete(key);
            } else {
                hidden.add(key);
            }
            saveHidden();
            onChange(input.checked);
        });

        label.append(input, swatch, name);
        item.append(label);
        container.append(item);
    }

    const venueStyles = {};
    config.venues.forEach(function (venue) {
        venueStyles[venue.id] = venue;
    });

    const notice = document.getElementById('abcnorio-cal-notice');
    const failed = new Set();

    function warnFailed(name) {
        if (failed.has(name)) {
            return;
        }
        failed.add(name);
        const message = document.createElement('p');
        message.textContent = 'Could not load: ' + name;
        notice.append(message);
        notice.hidden = false;
    }

    const detail = document.getElementById('abcnorio-cal-detail');
    const venueNames = {};
    config.venues.forEach(function (venue) {
        venueNames[venue.id] = venue.name;
    });

    const feedNames = {};
    config.feeds.forEach(function (feed) {
        feedNames[feed.id] = feed.name;
    });

    let currentEvent = null;

    function toLocalInput(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
            + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function addDateInput(form, text, id, date) {
        const label = document.createElement('label');
        const input = document.createElement('input');
        label.htmlFor = id;
        label.textContent = text;
        input.type = 'datetime-local';
        input.id = id;
        input.required = true;
        input.value = toLocalInput(date);
        form.append(label, input);
        return input;
    }

    function buildDateForm(event) {
        const form = document.createElement('form');
        const start = addDateInput(form, 'Start', 'abcnorio-detail-start', event.start);
        const end = addDateInput(form, 'End', 'abcnorio-detail-end', event.end);
        const save = document.createElement('button');
        save.type = 'submit';
        save.className = 'button';
        save.textContent = 'Save dates';
        form.append(save);

        form.addEventListener('submit', function (submitEvent) {
            submitEvent.preventDefault();
            const newStart = new Date(start.value);
            const newEnd = new Date(end.value);
            if (newEnd <= newStart) {
                end.setCustomValidity('End must be after start.');
                end.reportValidity();
                end.addEventListener('input', function () {
                    end.setCustomValidity('');
                }, { once: true });
                return;
            }
            postDates(event.id, newStart, newEnd)
                .then(function (result) {
                    event.setDates(newStart, newEnd);
                    showDetail(event);
                    result.warnings.forEach(function (warning) {
                        showMessage(event.title + ': ' + warning);
                    });
                })
                .catch(function () {
                    showMessage('Could not reschedule: ' + event.title);
                });
        });

        return form;
    }

    function showDetail(event) {
        currentEvent = event;
        const isInternal = event.extendedProps.status !== undefined;
        const fields = {};
        fields.When = event.allDay
            ? event.start.toLocaleDateString()
            : event.start.toLocaleString() + (event.end ? ' – ' + event.end.toLocaleString() : '');
        if (isInternal) {
            fields.Venue = venueNames[event.extendedProps.venue_id];
            fields.Status = event.extendedProps.status;
        } else {
            fields.Calendar = feedNames[event.source.id];
            fields.Location = event.extendedProps.location;
            fields.Description = event.extendedProps.description;
        }

        document.getElementById('abcnorio-cal-detail-title').textContent = event.title;
        const list = document.getElementById('abcnorio-detail-fields');
        list.replaceChildren();
        Object.entries(fields).forEach(function ([label, value]) {
            if (!value) {
                return;
            }
            const dt = document.createElement('dt');
            const dd = document.createElement('dd');
            dt.textContent = label;
            if (label === 'When' && isInternal && calendar.getOption('editable')) {
                dd.append(buildDateForm(event));
            } else {
                dd.textContent = value;
            }
            list.append(dt, dd);
        });

        const edit = document.getElementById('abcnorio-detail-edit');
        edit.hidden = !isInternal;
        edit.style.display = isInternal ? '' : 'none';
        edit.href = isInternal ? event.url : '#';
        detail.hidden = false;
        document.getElementById('abcnorio-cal-detail-title').focus();
    }

    document.getElementById('abcnorio-detail-close').addEventListener('click', function () {
        detail.hidden = true;
    });

    const params = new URLSearchParams(window.location.search);

    function showMessage(text) {
        const message = document.createElement('p');
        message.textContent = text;
        notice.append(message);
        notice.hidden = false;
    }

    const editKey = 'abcnorioSchedulingEdit';
    const confirmDialog = document.getElementById('abcnorio-reschedule-dialog');

    function formatRange(event) {
        return event.start.toLocaleString() + ' – ' + event.end.toLocaleString();
    }

    function confirmDates(info) {
        document.getElementById('abcnorio-reschedule-title').textContent = info.event.title;
        document.getElementById('abcnorio-reschedule-from').textContent = formatRange(info.oldEvent);
        document.getElementById('abcnorio-reschedule-to').textContent = formatRange(info.event);

        confirmDialog.returnValue = '';
        confirmDialog.addEventListener('close', function () {
            if (confirmDialog.returnValue === 'save') {
                saveDates(info);
            } else {
                info.revert();
            }
        }, { once: true });
        confirmDialog.showModal();
    }

    function postDates(id, start, end) {
        return fetch(config.eventsUrl + '/' + id, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            body: JSON.stringify({ start: start.toISOString(), end: end.toISOString() }),
        }).then(function (response) {
            if (!response.ok) {
                throw new Error(response.status);
            }
            return response.json();
        });
    }

    function saveDates(info) {
        postDates(info.event.id, info.event.start, info.event.end)
            .then(function (result) {
                result.warnings.forEach(function (warning) {
                    showMessage(info.event.title + ': ' + warning);
                });
            })
            .catch(function () {
                info.revert();
                showMessage('Could not reschedule: ' + info.event.title);
            });
    }

    function pad(number) {
        return String(number).padStart(2, '0');
    }

    const calendar = new FullCalendar.Calendar(document.getElementById('abcnorio-cal'), {
        initialView: params.get('view') || 'dayGridMonth',
        initialDate: params.get('date') || undefined,
        datesSet: function (info) {
            const date = calendar.getDate();
            const url = new URL(window.location.href);
            url.searchParams.set('view', info.view.type);
            url.searchParams.set('date', date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()));
            history.replaceState(null, '', url);
        },
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listMonth' },
        editable: localStorage.getItem(editKey) === '1',
        height: 'auto',
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            showDetail(info.event);
        },
        eventDrop: confirmDates,
        eventResize: confirmDates,
        eventSources: [{
            id: 'events',
            url: config.eventsUrl,
            extraParams: { _wpnonce: config.nonce },
            failure: function () {
                warnFailed('events');
            },
            eventDataTransform: function (event) {
                const venue = venueStyles[event.extendedProps.venue_id] || venueStyles[0];
                event.color = venue.color;
                event.textColor = venue.textColor;
                if (hidden.has('venue:' + event.extendedProps.venue_id)) {
                    event.display = 'none';
                }
                return event;
            },
        }],
    });

    const modeEl = document.getElementById('abcnorio-cal-mode');
    const { createElement, createRoot } = wp.element;
    const ToggleGroupControl = wp.components.__experimentalToggleGroupControl;
    const ToggleGroupControlOption = wp.components.__experimentalToggleGroupControlOption;
    const modeRoot = createRoot(modeEl);

    function renderMode(mode) {
        modeEl.classList.toggle('is-edit', mode === 'edit');
        modeRoot.render(createElement(ToggleGroupControl, {
            label: 'Calendar mode',
            hideLabelFromVision: true,
            isAdaptiveWidth: true,
            value: mode,
            onChange: function (value) {
                const on = value === 'edit';
                calendar.setOption('editable', on);
                localStorage.setItem(editKey, on ? '1' : '0');
                renderMode(value);
                if (currentEvent && !detail.hidden) {
                    showDetail(currentEvent);
                }
            },
        },
        createElement(ToggleGroupControlOption, { value: 'view', label: 'View' }),
        createElement(ToggleGroupControlOption, { value: 'edit', label: 'Edit' })));
    }

    renderMode(calendar.getOption('editable') ? 'edit' : 'view');

    config.feeds.forEach(function (feed) {
        feed.source = {
            id: feed.id,
            url: feed.url,
            format: 'ics',
            color: feed.color,
            textColor: feed.textColor,
            editable: false,
            failure: function () {
                warnFailed(feed.name);
            },
        };
        if (!hidden.has('feed:' + feed.id)) {
            calendar.addEventSource(feed.source);
        }
    });

    config.venues.forEach(function (venue) {
        addToggle(document.getElementById(venue.list), 'venue:' + venue.id, venue.name, venue.color, function (visible) {
            calendar.getEvents().forEach(function (event) {
                if (event.extendedProps.venue_id === venue.id) {
                    event.setProp('display', visible ? 'auto' : 'none');
                }
            });
        });
    });

    config.feeds.forEach(function (feed) {
        addToggle(document.getElementById('abcnorio-cal-feeds'), 'feed:' + feed.id, feed.name, feed.color, function (visible) {
            if (visible) {
                calendar.addEventSource(feed.source);
            } else {
                calendar.getEventSourceById(feed.id).remove();
            }
        });
    });

    ['abcnorio-cal-venues-abc', 'abcnorio-cal-venues-other', 'abcnorio-cal-feeds'].forEach(function (listId) {
        const list = document.getElementById(listId);
        const inputs = list.querySelectorAll('input[type="checkbox"]');
        if (!inputs.length) {
            return;
        }

        const bulk = document.createElement('p');
        [['Select all', true], ['Deselect all', false]].forEach(function ([text, checked], index) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button-link';
            button.textContent = text;
            button.addEventListener('click', function () {
                inputs.forEach(function (input) {
                    if (input.checked !== checked) {
                        input.checked = checked;
                        input.dispatchEvent(new Event('change'));
                    }
                });
            });
            if (index) {
                bulk.append(' | ');
            }
            bulk.append(button);
        });
        list.before(bulk);
    });

    const dialog = document.getElementById('abcnorio-feed-dialog');
    document.getElementById('abcnorio-feed-open').addEventListener('click', function () {
        dialog.showModal();
    });
    document.getElementById('abcnorio-feed-cancel').addEventListener('click', function () {
        dialog.close();
    });

    calendar.render();
}());
