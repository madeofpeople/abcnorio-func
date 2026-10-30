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

    const venueColors = {};
    config.venues.forEach(function (venue) {
        venueColors[venue.id] = venue.color;
    });

    const detail = document.getElementById('abcnorio-cal-detail');
    const venueNames = {};
    config.venues.forEach(function (venue) {
        venueNames[venue.id] = venue.name;
    });

    const feedNames = {};
    config.feeds.forEach(function (feed) {
        feedNames[feed.id] = feed.name;
    });

    function showDetail(event) {
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
            dd.textContent = value;
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
        height: 'auto',
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            showDetail(info.event);
        },
        eventSources: [{
            id: 'events',
            url: config.eventsUrl,
            extraParams: { _wpnonce: config.nonce },
            eventDataTransform: function (event) {
                const color = venueColors[event.extendedProps.venue_id];
                if (color) {
                    event.color = color;
                }
                if (hidden.has('venue:' + event.extendedProps.venue_id)) {
                    event.display = 'none';
                }
                return event;
            },
        }],
    });

    config.feeds.forEach(function (feed) {
        feed.source = { id: feed.id, url: feed.url, format: 'ics', color: feed.color };
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
