<?php
declare(strict_types=1);
namespace Civ13\Calendar;

final class Projection
{
    private static function text(mixed $value, int $bytes): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) { throw new \InvalidArgumentException('Unknown event text'); }
        $value = preg_replace('/<(@[!&]?|#)[0-9]+>/', '[mention]', $value);
        $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value);
        if (strlen($value) > $bytes) { throw new \InvalidArgumentException('Oversized event'); }
        return $value;
    }

    public static function instant(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value) || str_ends_with($value, '-00:00')) { throw new \InvalidArgumentException('Unknown event instant'); }
        preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})/', $value, $civil);
        if (!checkdate((int)$civil[2], (int)$civil[3], (int)$civil[1]) || (int)$civil[4] > 23 || (int)$civil[5] > 59 || (int)$civil[6] > 59) { throw new \InvalidArgumentException('Invalid civil instant'); }
        $time = new \DateTimeImmutable($value);
        if (preg_match('/([+-])([0-9]{2}):([0-9]{2})$/D', $value, $offset) && ((int)$offset[2] > 14 || (int)$offset[3] > 59 || ((int)$offset[2] === 14 && (int)$offset[3] !== 0))) { throw new \InvalidArgumentException('Invalid timezone offset'); }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) { throw new \InvalidArgumentException('Invalid civil date'); }
        $iso = $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        if (!str_starts_with($iso, '20')) { throw new \InvalidArgumentException('Unsupported instant'); }
        return $iso;
    }

    /** Only input scalars are selected; no creator, attendees, user counts, images or channel names. */
    public static function events(string $guild, array $roles, array $channels, array $events, array $onboarding): array
    {
        $baseline = Visibility::baseline($guild, $roles, $onboarding);
        $channelIndex = [];
        foreach ($channels as $channel) {
            if (!is_array($channel) || !Visibility::id($channel['id'] ?? null) || isset($channelIndex[$channel['id']])) { throw new \InvalidArgumentException('Unknown channels'); }
            $channelIndex[$channel['id']] = $channel;
        }
        if (count($events) > 1000) { throw new \InvalidArgumentException('Event inventory bound'); }
        $result = []; $notes = []; $seen = [];
        foreach ($events as $event) {
            if (!is_array($event) || ($event['guild_id'] ?? null) !== $guild || !Visibility::id($event['id'] ?? null) || isset($seen[$event['id']])) { throw new \InvalidArgumentException('Unknown event inventory'); }
            $seen[$event['id']] = true;
            $visible = null;
            if (($event['privacy_level'] ?? null) === 2) {
                if (($event['entity_type'] ?? null) === 3 && array_key_exists('channel_id', $event) && $event['channel_id'] === null) { $visible = true; }
                elseif (in_array($event['entity_type'] ?? null, [1, 2], true)) {
                    $channel = $channelIndex[$event['channel_id'] ?? ''] ?? null;
                    if ($channel !== null) { $visible = Visibility::channel($channel, $guild, $event['entity_type'] === 1 ? 13 : 2, $baseline); }
                }
            }
            if ($visible !== true) { if ($visible === null) { $notes['visibility_unknown'] = true; } continue; }
            try {
                if (!in_array($event['status'] ?? null, [1, 2, 3, 4], true)) { throw new \InvalidArgumentException('Unknown status'); }
                $start = self::instant($event['scheduled_start_time'] ?? null);
                $end = isset($event['scheduled_end_time']) ? self::instant($event['scheduled_end_time']) : null;
                if ($end !== null && $end <= $start) { throw new \InvalidArgumentException('Invalid interval'); }
                $recurrence = !array_key_exists('recurrence_rule', $event) ? 'unknown' : ($event['recurrence_rule'] === null ? 'none' : 'discord_only');
                if ($recurrence !== 'none') { $notes['recurrence_not_expanded'] = true; }
                $title = trim(self::text($event['name'] ?? null, 400));
                if ($title === '') { throw new \InvalidArgumentException('Missing title'); }
                $metadata = (array)($event['entity_metadata'] ?? []);
                $result[] = ['event_id' => $event['id'], 'start_at' => $start, 'end_at' => $end,
                    'title' => $title, 'description' => self::text($event['description'] ?? '', 4000),
                    'location' => $event['entity_type'] === 3 ? self::text($metadata['location'] ?? 'External event', 400) : 'Discord voice or stage',
                    'url' => 'https://discord.com/events/'.$guild.'/'.$event['id'],
                    'status' => [1 => 'confirmed', 2 => 'active', 3 => 'completed', 4 => 'cancelled'][$event['status']], 'recurrence' => $recurrence];
            } catch (\Throwable) { $notes['event_not_representable'] = true; }
        }
        usort($result, static fn (array $a, array $b): int => strcmp($a['event_id'], $b['event_id']));
        $notes = array_keys($notes); sort($notes);
        return ['events' => $result, 'notes' => $notes];
    }
}
