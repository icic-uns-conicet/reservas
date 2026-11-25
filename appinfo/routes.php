<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'page#index',
            'url' => '/',
            'verb' => 'GET',
        ],
        [
            'name' => 'calendar#calendars',
            'url' => '/api/calendars',
            'verb' => 'GET',
        ],
        [
            'name' => 'calendar#rooms',
            'url' => '/api/rooms',
            'verb' => 'GET',
        ],
        [
            'name' => 'calendar#events',
            'url' => '/api/events',
            'verb' => 'GET',
        ],
        [
            'name' => 'calendar#reserve',
            'url' => '/api/reservations',
            'verb' => 'POST',
        ],
        [
            'name' => 'calendar#updateReservation',
            'url' => '/api/reservations/update',
            'verb' => 'POST',
        ],
        [
            'name' => 'calendar#deleteReservation',
            'url' => '/api/reservations/delete',
            'verb' => 'POST',
        ],
        [
            'name' => 'calendar#access',
            'url' => '/api/access',
            'verb' => 'GET',
        ],
        [
            'name' => 'settings#save',
            'url' => '/api/settings',
            'verb' => 'POST',
        ],
        [
            'name' => 'settings#saveLegacy',
            'url' => '/settings',
            'verb' => 'POST',
        ],
    ],
];
