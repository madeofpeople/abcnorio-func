(function () {
    const config = window.abcnorioScheduling;
    const storageKey = 'abcnorioSchedulingHidden';
    const hidden = new Set(JSON.parse(localStorage.getItem(storageKey) || '[]'));

    function saveHidden() {
        localStorage.setItem(storageKey, JSON.stringify([...hidden]));
    }

    function addToggle(container, key, name, color, onChange) {
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
        container.append(label);
    }

    const venueColors = {};
    config.venues.forEach(function (venue) {
        venueColors[venue.id] = venue.color;
    });

    const calendar = new FullCalendar.Calendar(document.getElementById('abcnorio-cal'), {
        initialView: 'dayGridMonth',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listMonth' },
        height: 'auto',
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
        addToggle(document.getElementById('abcnorio-cal-venues'), 'venue:' + venue.id, venue.name, venue.color, function (visible) {
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

    calendar.render();
}());
