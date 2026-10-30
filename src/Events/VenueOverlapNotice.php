<?php

namespace abcnorio\CustomFunc\Events;

use abcnorio\CustomFunc\Settings\EventScheduleSettings;

final class VenueOverlapNotice
{
    // Multi-day runs (exhibitions) would overlap everything in the venue, so they are skipped.
    private const MAX_SPAN_SECONDS = DAY_IN_SECONDS;

    public static function registerHooks(): void
    {
        add_action('admin_notices', [self::class, 'renderNotice']);
    }

    public static function renderNotice(): void
    {
        $screen = get_current_screen();
        if ($screen->base !== 'post' || $screen->post_type !== 'event') {
            return;
        }

        $messages = self::warnings((int) get_the_ID());
        if ($messages === []) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e('Venue scheduling warning', 'abcnorio-func'); ?></strong></p>
            <ul>
                <?php foreach ($messages as $message) : ?>
                    <li><?php echo esc_html($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * @return string[]
     */
    public static function warnings(int $eventId): array
    {
        $venueId = (int) get_post_meta($eventId, 'event_venue_id', true);
        if ($venueId === 0 || get_post_status($eventId) !== 'publish') {
            return [];
        }

        $event = self::window($eventId);
        if ($event === null) {
            return [];
        }

        $leadSeconds = EventScheduleSettings::leadTimeMinutes() * MINUTE_IN_SECONDS;
        $maxPerDay = EventScheduleSettings::maxPerDay();
        $messages = [];
        $sameDayCount = 1;

        $otherIds = get_posts([
            'post_type'    => 'event',
            'post_status'  => 'publish',
            'numberposts'  => -1,
            'fields'       => 'ids',
            'post__not_in' => [$eventId],
        ]);

        foreach ($otherIds as $otherId) {
            $other = self::window($otherId);
            if ($other === null) {
                continue;
            }

            $sameVenue = (int) get_post_meta($otherId, 'event_venue_id', true) === $venueId;
            $overlaps = $sameVenue
                && $event['start'] < $other['end'] + $leadSeconds
                && $other['start'] < $event['end'] + $leadSeconds;
            if ($overlaps) {
                $messages[] = sprintf(
                    /* translators: 1: event title, 2: start time, 3: lead time in minutes */
                    __('Overlaps "%1$s" (%2$s) in this venue, including a %3$d minute lead time.', 'abcnorio-func'),
                    get_the_title($otherId),
                    wp_date('M j, g:i a', $other['start'], $other['zone']),
                    EventScheduleSettings::leadTimeMinutes()
                );
            }

            $sameDay = wp_date('Y-m-d', $other['start'], $event['zone']) === wp_date('Y-m-d', $event['start'], $event['zone']);
            if ($sameDay) {
                $sameDayCount++;
            }
        }

        if ($maxPerDay !== -1 && $sameDayCount > $maxPerDay) {
            $messages[] = sprintf(
                /* translators: 1: number of events that day, 2: configured maximum */
                __('%1$d events scheduled on this day (limit %2$d).', 'abcnorio-func'),
                $sameDayCount,
                $maxPerDay
            );
        }

        return $messages;
    }

    /**
     * @return array{start: int, end: int, zone: \DateTimeZone}|null
     */
    private static function window(int $eventId): ?array
    {
        $startValue = (string) get_post_meta($eventId, 'event_start_date', true);
        if ($startValue === '') {
            return null;
        }

        $zone = new \DateTimeZone((string) get_post_meta($eventId, 'event_timezone', true));
        $start = (new \DateTimeImmutable($startValue, $zone))->getTimestamp();

        $endValue = (string) get_post_meta($eventId, 'event_end_date', true);
        $end = $endValue === ''
            ? $start + EventScheduleSettings::defaultDurationMinutes() * MINUTE_IN_SECONDS
            : (new \DateTimeImmutable($endValue, $zone))->getTimestamp();

        if ($end - $start > self::MAX_SPAN_SECONDS) {
            return null;
        }

        return ['start' => $start, 'end' => $end, 'zone' => $zone];
    }
}
