#!/usr/bin/env php
<?php

declare(strict_types=1);

function usage(): never
{
    fwrite(STDERR, <<<'USAGE'
Usage:
  NEXTCLOUD_APP_PASSWORD='app-password' php run-reservation-collision.php \
    --base-url=https://cloud.example.org \
    --username=user-id \
    --room-key=calendar_resource_management-room-id \
    --date=YYYY-MM-DD \
    --time=10:00 \
    [--timezone=America/Argentina/Buenos_Aires]

The script creates two test reservations, verifies a colliding request is
rejected and always attempts to delete its test events afterward.

USAGE);
    exit(2);
}

function request(string $baseUrl, string $username, string $password, string $method, string $path, ?string $body = null, array $headers = []): array
{
    $handle = curl_init(rtrim($baseUrl, '/') . $path);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize cURL.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($handle, $options);

    $response = curl_exec($handle);
    if ($response === false) {
        $message = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('HTTP request failed: ' . $message);
    }

    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return [$status, $response];
}

function apiRequest(string $baseUrl, string $username, string $password, string $method, string $path, ?array $payload = null): array
{
    $headers = ['OCS-APIRequest: true', 'Accept: application/json'];
    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    [$status, $response] = request($baseUrl, $username, $password, $method, $path, $body, $headers);
    $decoded = json_decode($response, true);
    return [$status, is_array($decoded) ? $decoded : [], $response];
}

function requireStatus(int $actual, int $expected, string $step, array $response = []): void
{
    if ($actual !== $expected) {
        $details = $response === [] ? '' : ' Response: ' . json_encode($response, JSON_UNESCAPED_SLASHES);
        throw new RuntimeException(sprintf('%s: expected HTTP %d, received %d.%s', $step, $expected, $actual, $details));
    }
}

function apiEvents(string $baseUrl, string $username, string $password, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $query = http_build_query([
        'start' => $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'end' => $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
    ]);
    [$status, $response] = apiRequest($baseUrl, $username, $password, 'GET', '/apps/reservas/api/events?' . $query);
    requireStatus($status, 200, 'Load Reservas events', $response);
    $events = $response['events'] ?? null;
    if (!is_array($events)) {
        throw new RuntimeException('The events API response does not contain an events list.');
    }
    return $events;
}

function findOwnedTestEvent(array $events, string $title): ?array
{
    foreach ($events as $event) {
        if (($event['title'] ?? null) === $title
            && ($event['canDelete'] ?? false) === true
            && is_string($event['calendarUri'] ?? null)
            && is_string($event['objectUri'] ?? null)) {
            return $event;
        }
    }
    return null;
}

function findEventByTitle(array $events, string $title): ?array
{
    foreach ($events as $event) {
        if (($event['title'] ?? null) === $title) {
            return $event;
        }
    }
    return null;
}

$options = getopt('', [
    'base-url:',
    'username:',
    'room-key:',
    'date:',
    'time:',
    'timezone::',
    'help',
]);
if (isset($options['help'])) {
    usage();
}

$baseUrl = $options['base-url'] ?? '';
$username = $options['username'] ?? '';
$roomKey = $options['room-key'] ?? '';
$date = $options['date'] ?? '';
$time = $options['time'] ?? '';
$timezoneName = $options['timezone'] ?? 'UTC';
$password = getenv('NEXTCLOUD_APP_PASSWORD') ?: '';

if ($baseUrl === '' || $username === '' || $roomKey === '' || $date === '' || $time === '' || $password === '') {
    usage();
}
$parsedBaseUrl = parse_url($baseUrl);
if (!is_array($parsedBaseUrl)
    || !isset($parsedBaseUrl['scheme'], $parsedBaseUrl['host'])
    || !in_array($parsedBaseUrl['scheme'], ['https', 'http'], true)) {
    throw new RuntimeException('--base-url must be an absolute HTTP(S) URL.');
}
if ($parsedBaseUrl['scheme'] !== 'https'
    && !in_array($parsedBaseUrl['host'], ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('HTTP is only permitted for localhost; use HTTPS to protect the app password.');
}

try {
    $timezone = new DateTimeZone($timezoneName);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
} catch (Throwable $exception) {
    throw new RuntimeException('Invalid --date or --timezone: ' . $exception->getMessage(), 0, $exception);
}
if ($start === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
    || $start->format('Y-m-d H:i') !== $date . ' ' . $time) {
    throw new RuntimeException('Use --date=YYYY-MM-DD and --time=HH:MM.');
}
if ($start <= new DateTimeImmutable('now', $timezone)) {
    throw new RuntimeException('Choose a future date and time.');
}

try {
    [$status, $roomResponse] = apiRequest($baseUrl, $username, $password, 'GET', '/apps/reservas/api/rooms');
    requireStatus($status, 200, 'Load rooms', $roomResponse);
    $room = null;
    foreach ($roomResponse['rooms'] ?? [] as $candidate) {
        if (($candidate['key'] ?? null) === $roomKey) {
            $room = $candidate;
            break;
        }
    }
    if ($room === null) {
        throw new RuntimeException('The requested room is not visible to this user.');
    }
    $roomPrincipal = 'principals/calendar-rooms/' . $roomKey;

    $titles = [
        'first' => 'Reservas CLI ' . bin2hex(random_bytes(5)) . ' A',
    ];
    $titles['overlap'] = substr($titles['first'], 0, -1) . ' OVERLAP';
    $titles['nonoverlap'] = substr($titles['first'], 0, -1) . ' B';

    $firstEnd = $start->modify('+60 minutes');
    $nonoverlapStart = $start->modify('+90 minutes');
    $testEnd = $nonoverlapStart->modify('+60 minutes');
    $existingRoomEvents = apiEvents($baseUrl, $username, $password, $start, $testEnd);
    foreach ($existingRoomEvents as $event) {
        if (($event['roomKey'] ?? null) === $roomKey
            && ($event['sourcePrincipal'] ?? null) === $roomPrincipal) {
            throw new RuntimeException('The chosen room/time range is not empty. Pick another date or time.');
        }
    }
    $reservation = static function (string $title, DateTimeImmutable $eventStart) use (
        $baseUrl,
        $username,
        $password,
        $roomKey,
        $timezoneName,
    ): array {
        return apiRequest($baseUrl, $username, $password, 'POST', '/apps/reservas/api/reservations', [
            'roomKey' => $roomKey,
            'subject' => $title,
            'start' => $eventStart->format('Y-m-d\TH:i:s'),
            'durationMinutes' => 60,
            'reservationType' => 'punctual',
            'timeZone' => $timezoneName,
        ]);
    };

    echo "Room: {$room['displayName']} ({$roomKey})\n";
    echo "Room calendar: calendar; test start: " . $start->format(DATE_ATOM) . "\n";

    [$status, $response] = $reservation($titles['first'], $start);
    requireStatus($status, 201, 'Create first reservation', $response);
    echo "PASS: first reservation created\n";

    $firstEvent = findEventByTitle(apiEvents($baseUrl, $username, $password, $start, $firstEnd), $titles['first']);
    if (($firstEvent['sourcePrincipal'] ?? null) !== $roomPrincipal) {
        throw new RuntimeException('The first reservation was not found in the room principal calendar.');
    }
    echo "PASS: first reservation is stored in the room principal calendar\n";

    [$status, $response] = $reservation($titles['overlap'], $start->modify('+30 minutes'));
    requireStatus($status, 409, 'Reject overlapping reservation', $response);
    foreach (apiEvents($baseUrl, $username, $password, $fromDay, $toDay) as $event) {
        if (($event['title'] ?? null) === $titles['overlap']) {
            throw new RuntimeException('The rejected overlapping reservation unexpectedly exists in the events API.');
        }
    }
    $overlapEvent = findEventByTitle(apiEvents($baseUrl, $username, $password, $start, $testEnd), $titles['overlap']);
    if ($overlapEvent !== null) {
        throw new RuntimeException('The overlapping reservation unexpectedly exists in the room calendar.');
    }
    echo "PASS: overlapping reservation rejected with HTTP 409\n";

    [$status, $response] = $reservation($titles['nonoverlap'], $nonoverlapStart);
    requireStatus($status, 201, 'Create non-overlapping reservation', $response);
    $nonoverlapEvent = findEventByTitle(apiEvents($baseUrl, $username, $password, $start, $testEnd), $titles['nonoverlap']);
    if (($nonoverlapEvent['sourcePrincipal'] ?? null) !== $roomPrincipal) {
        throw new RuntimeException('The non-overlapping reservation was not found in the room principal calendar.');
    }
    echo "PASS: non-overlapping reservation created and found in the room calendar\n";
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $cleanupErrors = [];
    if (isset($titles, $start, $testEnd, $baseUrl, $username, $password)) {
        foreach ($titles as $title) {
            try {
                foreach (apiEvents($baseUrl, $username, $password, $start, $testEnd) as $event) {
                    $ownedEvent = findOwnedTestEvent([$event], $title);
                    if ($ownedEvent === null) {
                        continue;
                    }
                    [$deleteStatus, $deleteResponse] = apiRequest(
                        $baseUrl,
                        $username,
                        $password,
                        'POST',
                        '/apps/reservas/api/reservations/delete',
                        [
                            'calendarUri' => $ownedEvent['calendarUri'],
                            'objectUri' => $ownedEvent['objectUri'],
                            'roomKey' => $ownedEvent['roomKey'] ?? '',
                        ],
                    );
                    requireStatus($deleteStatus, 200, 'Delete test reservation', $deleteResponse);
                    echo "CLEANUP: deleted {$title}\n";
                }
            } catch (Throwable $exception) {
                $cleanupErrors[] = $title . ': ' . $exception->getMessage();
            }
        }
    }
}

if (isset($failure)) {
    fwrite(STDERR, "FAIL: {$failure->getMessage()}\n");
    if ($cleanupErrors !== []) {
        fwrite(STDERR, "Cleanup needs attention:\n - " . implode("\n - ", $cleanupErrors) . "\n");
    }
    exit(1);
}
if ($cleanupErrors !== []) {
    fwrite(STDERR, "FAIL: cleanup incomplete:\n - " . implode("\n - ", $cleanupErrors) . "\n");
    exit(1);
}

try {
    $remainingEvents = apiEvents($baseUrl, $username, $password, $start, $testEnd);
    foreach ($titles as $title) {
        foreach ($remainingEvents as $event) {
            if (($event['title'] ?? null) === $title) {
                throw new RuntimeException("Test event still exists in the user's calendar: {$title}");
            }
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: final cleanup verification: {$exception->getMessage()}\n");
    exit(1);
}

echo "PASS: test reservations no longer exist in the room calendar\n";
echo "All reservation collision checks passed.\n";
