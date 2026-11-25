<?php

declare(strict_types=1);

namespace OCA\Reservas\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\CalendarHome;
use OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin;
use OCA\DAV\CalDAV\EmbeddedCalDavServer;
use OCA\Reservas\AppInfo\Application;
use OCA\Reservas\AccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Calendar\CalendarEventStatus;
use OCP\Calendar\Room\IRoom;
use OCP\Calendar\Room\IManager as IRoomManager;
use OCP\Constants;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Parameter;
use Sabre\VObject\Reader;

class CalendarController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IManager $calendarManager,
        private IUserSession $userSession,
        private IRoomManager $roomManager,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private ILockingProvider $lockingProvider,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
        private AccessService $accessService,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function access(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->unauthenticated();
        }

        $canUse = $this->canUserUseApplication($user);
        $canCreateRecurring = $canUse && $this->canUserCreateRecurringReservations($user);
        $response = [
            'canUse' => $canUse,
            'canCreateRecurring' => $canCreateRecurring,
        ];
        if ($canCreateRecurring) {
            $response['eligibleUsers'] = $this->getEligibleUsersFor($user);
        }

        return new JSONResponse($response, $canUse ? Http::STATUS_OK : Http::STATUS_FORBIDDEN);
    }

    /**
     * @return list<array{uid: string, displayName: string}>
     */
    private function getEligibleUsersFor(IUser $user): array
    {
        $users = [];
        $this->userManager->callForAllUsers(function (IUser $candidate) use (&$users, $user): void {
            if ($candidate->getUID() === $user->getUID() || !$this->canUserUseApplication($candidate)) {
                return;
            }

            $users[] = [
                'uid' => $candidate->getUID(),
                'displayName' => $candidate->getDisplayName(),
            ];
        });
        usort($users, static fn (array $left, array $right): int =>
            strnatcasecmp($left['displayName'], $right['displayName']) ?: strcmp($left['uid'], $right['uid'])
        );

        return $users;
    }

    #[NoAdminRequired]
    public function calendars(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->unauthenticated();
        }
        if (!$this->canUserUseApplication($user)) {
            return new JSONResponse(['error' => 'No tienes permiso para utilizar la aplicación.'], Http::STATUS_FORBIDDEN);
        }

        $principal = 'principals/users/' . $user->getUID();
        $calendars = [];

        foreach ($this->calendarManager->getCalendarsForPrincipal($principal) as $calendar) {
            $calendars[] = [
                'uri' => $calendar->getUri(),
                'displayName' => $calendar->getDisplayName(),
                'color' => $calendar->getDisplayColor(),
                'writable' => $calendar instanceof ICreateFromString
                    && ($calendar->getPermissions() & Constants::PERMISSION_CREATE) !== 0,
            ];
        }

        return new JSONResponse(['calendars' => $calendars]);
    }

    #[NoAdminRequired]
    public function rooms(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->unauthenticated();
        }
        if (!$this->canUserUseApplication($user)) {
            return new JSONResponse(['error' => 'No tienes permiso para utilizar la aplicación.'], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse(['rooms' => $this->getRoomsForUser($user)]);
    }

    #[NoAdminRequired]
    public function events(?string $start = null, ?string $end = null): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->unauthenticated();
        }
        if (!$this->canUserUseApplication($user)) {
            return new JSONResponse(['error' => 'No tienes permiso para utilizar la aplicación.'], Http::STATUS_FORBIDDEN);
        }

        try {
            $from = $start === null
                ? (new DateTimeImmutable('first day of this month'))->setTime(0, 0)
                : new DateTimeImmutable($start);
            $to = $end === null
                ? $from->modify('first day of next month')
                : new DateTimeImmutable($end);
        } catch (\DateMalformedStringException|\InvalidArgumentException $exception) {
            return new JSONResponse(['error' => 'El rango de fechas no es válido.'], Http::STATUS_BAD_REQUEST);
        }

        if ($to <= $from) {
            return new JSONResponse(['error' => 'La fecha de fin debe ser posterior al inicio.'], Http::STATUS_BAD_REQUEST);
        }

        $eventsById = [];
        $seriesEndDates = [];
        $rooms = $this->getRoomsForUser($user);
        $roomsByName = [];
        foreach ($rooms as $room) {
            $roomsByName[mb_strtolower(trim($room['displayName']))] = $room;
/*
            $this->logger->debug('Found room for user', [
                'roomKey' => $room['key'],
                'roomName' => $room['displayName'],
                'principal' => $room['principal'],
            ]);*/
        }

        $principal = 'principals/users/' . $user->getUID();
        $calendarsByKey = [];
        foreach ($this->calendarManager->getCalendarsForPrincipal($principal) as $calendar) {
            $calendarsByKey[$calendar->getKey()] = $calendar;
        }

        $query = $this->calendarManager->newQuery($principal);
        $query->addType('VEVENT');
        $query->setTimerangeStart($from);
        $query->setTimerangeEnd($to);

        foreach ($this->calendarManager->searchForPrincipal($query) as $object) {
            foreach ($object['objects'] ?? [] as $component) {
                if (!is_array($component)) {
                    continue;
                }

                $eventObject = $object;
                $eventObject['objects'] = [$component];
                $location = (string)($this->propertyValue($component, 'LOCATION') ?? '');
                $room = $roomsByName[mb_strtolower(trim($location))] ?? null;
                $seriesEndDate = null;
                $description = (string)($this->propertyValue($component, 'DESCRIPTION') ?? '');
                $calendarKey = (string)($object['calendar-key'] ?? '');
                $objectUri = (string)($object['uri'] ?? '');
                $seriesCacheKey = $calendarKey . ':' . $objectUri;
                if (str_contains(mb_strtolower($description), 'tipo: período continuo')
                    && isset($calendarsByKey[$calendarKey])
                    && $objectUri !== '') {
                    if (!array_key_exists($seriesCacheKey, $seriesEndDates)) {
                        $masterEvents = $calendarsByKey[$calendarKey]->search('', [], ['uri' => $objectUri]);
                        $seriesEndDates[$seriesCacheKey] = $this->recurrenceEndDate($masterEvents[0]['objects'][0] ?? []);
                    }
                    $seriesEndDate = $seriesEndDates[$seriesCacheKey];
                }

                $event = $this->mapEvent(
                    $eventObject,
                    $room['displayName'] ?? null,
                    $room['key'] ?? null,
                    $room['color'] ?? null,
                    $this->isEventOrganizer($eventObject, $user),
                    $seriesEndDate,
                    true,
                );
                if ($event !== null) {
                    $event['sourcePrincipal'] = $principal;
                    $eventsById[$event['id']] = $event;
                }
            }
        }

        foreach ($rooms as $room) {
/*
            error_log(sprintf(
            '[reservas] events principal=%s from=%s to=%s ',
            $room['principal'],
            $from->format(DATE_ATOM),
            $to->format(DATE_ATOM)
            
        ));
*/
            $roomEvents = $this->getRoomEvents($room['principal'], $from, $to);
            foreach ($roomEvents as $object) {
                foreach ($object['objects'] ?? [] as $component) {
                    if (!is_array($component)) {
                        continue;
                    }

                    $eventObject = $object;
                    $eventObject['objects'] = [$component];
                    $event = $this->mapEvent(
                        $eventObject,
                        $room['displayName'],
                        $room['key'],
                        $room['color'],
                        $this->isEventOrganizer($eventObject, $user),
                        $this->recurrenceEndDate($component),
                        true,
                    );
                    if ($event !== null) {
                        $event['sourcePrincipal'] = $room['principal'];
                        $eventsById[$event['id']] = $event;
                    }
                }
            }
        }

        return new JSONResponse(['events' => array_values($eventsById)]);
    }

    #[NoAdminRequired]
    public function reserve(
        string $roomKey = '',
        string $subject = '',
        string $start = '',
        int $durationMinutes = 60,
        string $reservationType = 'punctual',
        ?string $lastOccurrenceDate = null,
        string $timeZone = 'UTC',
        string $forUser = '',
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->unauthenticated();
        }
        if (!$this->canUserUseApplication($user)) {
            return new JSONResponse(['error' => 'No tienes permiso para utilizar la aplicación.'], Http::STATUS_FORBIDDEN);
        }
        if ($reservationType === 'continuous' && !$this->canUserCreateRecurringReservations($user)) {
            return new JSONResponse([
                'error' => 'Tu grupo no tiene permiso para crear reservas recurrentes.',
            ], Http::STATUS_FORBIDDEN);
        }
        $recipient = null;
        if ($forUser !== '' && $forUser !== $user->getUID()) {
            if (!$this->canUserCreateRecurringReservations($user)) {
                return new JSONResponse([
                    'error' => 'Tu grupo no tiene permiso para crear reservas para otras personas.',
                ], Http::STATUS_FORBIDDEN);
            }
            $recipient = $this->userManager->get($forUser);
            if ($recipient === null || !$this->canUserUseApplication($recipient)) {
                return new JSONResponse([
                    'error' => 'El usuario seleccionado no puede utilizar la aplicación.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        $subject = trim($subject);
        if ($subject === '' || mb_strlen($subject) > 255 || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            return new JSONResponse(['error' => 'Indica una materia de hasta 255 caracteres.'], Http::STATUS_BAD_REQUEST);
        }

        if (!in_array($durationMinutes, [30, 60, 90, 120, 150, 180, 210, 240, 270, 300, 330, 360, 390, 420, 450, 480], true)) {
            return new JSONResponse([
                'error' => 'La duración seleccionada no es válida.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!in_array($reservationType, ['punctual', 'continuous'], true)) {
            return new JSONResponse(['error' => 'El tipo de reserva no es válido.'], Http::STATUS_BAD_REQUEST);
        }
        $this->logger->debug('Checking for conflicts', [
                'timezone' => $timeZone,
                'start' => $start,
        ]);

        $timeZone = match ($timeZone) {
            'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
            default => $timeZone,
        };

        try {
            $timezone = new DateTimeZone($timeZone);
            $startDate = new DateTimeImmutable($start, $timezone);
        } catch (\Exception $exception) {
            return new JSONResponse(['error' => 'El intervalo de reserva no es válido.'], Http::STATUS_BAD_REQUEST);
        }

        $lastStartDate = null;
        if ($reservationType === 'continuous') {
            $lastStartDate = $lastOccurrenceDate === null
                ? false
                : DateTimeImmutable::createFromFormat('!Y-m-d', $lastOccurrenceDate, $timezone);

            if ($lastStartDate === false || $lastStartDate->format('Y-m-d') !== $lastOccurrenceDate
                || $lastStartDate->format('N') !== $startDate->format('N')
                || $lastStartDate < $startDate->setTime(0, 0)) {
                return new JSONResponse([
                    'error' => 'El último día debe ser igual o posterior al inicio y coincidir con el mismo día de la semana.',
                ], Http::STATUS_BAD_REQUEST);
            }

            $lastStartDate = $lastStartDate->setTime(
                (int)$startDate->format('H'),
                (int)$startDate->format('i'),
                (int)$startDate->format('s'),
            );

            $weeks = intdiv($startDate->diff($lastStartDate)->days, 7) + 1;
            if ($weeks > 104) {
                return new JSONResponse(['error' => 'El período continuo no puede superar 104 semanas.'], Http::STATUS_BAD_REQUEST);
            }
        }

        $endDate = ($lastStartDate ?? $startDate)->modify('+' . $durationMinutes . ' minutes');
        $occurrenceEndDate = $startDate->modify('+' . $durationMinutes . ' minutes');

        $room = $this->findRoomForUser($roomKey, $user);
        if ($room === null) {
            return new JSONResponse(['error' => 'El espacio no existe o no está disponible para tu usuario.'], Http::STATUS_NOT_FOUND);
        }

        $userEmail = $user->getEMailAddress();
        if ($userEmail === null || filter_var($userEmail, FILTER_VALIDATE_EMAIL) === false) {
            return new JSONResponse([
                'error' => 'Configura un correo electrónico válido en tu perfil para reservar una sala.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $lockPath = 'reservas/room/' . hash('sha256', $roomKey);
        try {
            $this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            return new JSONResponse([
                'error' => 'Ya se está procesando una reserva para este espacio. Inténtalo nuevamente.',
            ], Http::STATUS_CONFLICT);
        }

        try {/*
            $this->logger->debug('Checking for conflicts', [
                'room' => $room->getDisplayName(),
                'start' => $startDate->format(DATE_ATOM),
                'end' => $endDate->format(DATE_ATOM),
                'durationMinutes' => $durationMinutes,
                'reservationType' => $reservationType,
            ]);*/
            $conflicts = $this->findConflicts(
                $room,
                $startDate,
                $endDate,
                $durationMinutes,
                $reservationType === 'continuous',
            );
            if ($conflicts !== []) {
                $this->notifyExistingOwners($conflicts, $room->getDisplayName(), $subject);
/*
                $this->logger->debug('Found conflicts for reservation', [
                    'room' => $room->getDisplayName(),
                    'start' => $startDate->format(DATE_ATOM),
                    'end' => $endDate->format(DATE_ATOM),
                    'durationMinutes' => $durationMinutes,
                    'reservationType' => $reservationType,
                    'conflicts' => $conflicts,
                ]);
*/
                return new JSONResponse([
                    'error' => 'El espacio ya tiene una reserva que se superpone con ese intervalo.',
                    'conflicts' => array_map(static fn (array $conflict): array => [
                        'subject' => $conflict['subject'],
                        'owner' => $conflict['owner'],
                        'ownerEmail' => $conflict['ownerEmail'],
                        'start' => $conflict['start'],
                        'end' => $conflict['end'],
                    ], $conflicts),
                ], Http::STATUS_CONFLICT);
            }
/*
            $this->logger->debug('No conflicts found for reservation', [
                'room' => $room->getDisplayName(),
                'start' => $startDate->format(DATE_ATOM),
                'end' => $endDate->format(DATE_ATOM),
                'durationMinutes' => $durationMinutes,
                'reservationType' => $reservationType,
            ]);
*/
            try {
                $calendarPrincipal = 'principals/calendar-rooms/' . $roomKey;
                $builder = $this->calendarManager->createEventBuilder()
                    ->setStartDate($startDate)
                    ->setEndDate($occurrenceEndDate)
                    ->setSummary($subject)
                    ->setStatus(CalendarEventStatus::CONFIRMED)
                    ->setLocation($room->getDisplayName())
                    ->setDescription($this->reservationDescription(
                        $subject,
                        $user->getDisplayName(),
                        $recipient?->getDisplayName(),
                        $recipient?->getEMailAddress(),
                        $reservationType,
                    ))
                    ->setOrganizer($userEmail, $user->getDisplayName());

                if ($lastStartDate !== null) {
                    $calendarData = Reader::read($builder->toIcs());
                    $calendarData->VEVENT->add(
                        'RRULE',
                        'FREQ=WEEKLY;UNTIL=' . $lastStartDate
                            ->setTimezone(new DateTimeZone('UTC'))
                            ->format('Ymd\THis\Z'),
                    );
                    $objectUri = $calendarData->VEVENT->UID->getValue() . '.ics';
                    $calendarData = $calendarData->serialize();
                } else {
                    $calendarData = $builder->toIcs();
                    $parsedEvent = Reader::read($calendarData);
                    $objectUri = $parsedEvent->VEVENT->UID->getValue() . '.ics';
                }
                // debug
                /*
                $roomCalendars = $this->calendarManager->getCalendarsForPrincipal($calendarPrincipal);
                
                $this->logger->warning('Reservas: calendars found for room principal', [
                    'roomKey' => $roomKey,
                    'principal' => $calendarPrincipal,
                    'calendars' => array_map(static fn ($calendar): array => [
                        'uri' => $calendar->getUri(),
                        'key' => $calendar->getKey(),
                        'displayName' => $calendar->getDisplayName(),
                    ], $roomCalendars),
                ]);
                */
                // end debug

                $this->writeRoomCalendarObject($roomKey, $calendarPrincipal, $objectUri, $calendarData);
                /*
                // log in apache php error log
                error_log(sprintf(
                    '[reservas] Reservation stored principal=%s calendarUri=%s calendarKey=%s objectUri=%s',
                    $calendarPrincipal,
                    'calendar',
                    $roomKey,
                    $objectUri,
                ));
                */
                
            } catch (\OCP\Calendar\Exceptions\CalendarException|\InvalidArgumentException|\RuntimeException|\Sabre\DAV\Exception|\Sabre\VObject\ParseException $exception) {
                $this->logger->error('Could not create Reservas reservation', ['exception' => $exception]);
                return new JSONResponse([
                    'error' => 'No se pudo crear el evento de reserva en Calendar.',
                ], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            return new JSONResponse(['success' => true], Http::STATUS_CREATED);
        } finally {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    #[NoAdminRequired]
    public function updateReservation(
        string $roomKey = '',
        string $objectUri = '',
        string $subject = '',
        string $start = '',
        int $durationMinutes = 60,
        string $reservationType = 'punctual',
        ?string $lastOccurrenceDate = null,
        string $timeZone = 'UTC',
        string $calendarUri = '',
        bool $allDay = false,
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->unauthenticated();
        }
        if (!$this->canUserUseApplication($user)) {
            return new JSONResponse(['error' => 'No tienes permiso para utilizar la aplicación.'], Http::STATUS_FORBIDDEN);
        }
        if ($roomKey !== '' && $reservationType === 'continuous'
            && !$this->canUserCreateRecurringReservations($user)) {
            return new JSONResponse([
                'error' => 'Tu grupo no tiene permiso para crear reservas recurrentes.',
            ], Http::STATUS_FORBIDDEN);
        }

        $subject = trim($subject);
        if ($subject === '' || mb_strlen($subject) > 255 || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            return new JSONResponse(['error' => 'Indica una materia de hasta 255 caracteres.'], Http::STATUS_BAD_REQUEST);
        }
        if (($roomKey !== '' && !in_array($durationMinutes, [30, 60, 90, 120, 150, 180, 210, 240, 270, 300, 330, 360, 390, 420, 450, 480], true))
            || ($roomKey === '' && ($durationMinutes < 1 || $durationMinutes > 10080))) {
            return new JSONResponse(['error' => 'La duración seleccionada no es válida.'], Http::STATUS_BAD_REQUEST);
        }
        if ($roomKey !== '' && !in_array($reservationType, ['punctual', 'continuous'], true)) {
            return new JSONResponse(['error' => 'El tipo de reserva no es válido.'], Http::STATUS_BAD_REQUEST);
        }
        if ($objectUri === ''
            || str_contains($objectUri, '/') || str_contains($objectUri, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $objectUri) === 1
            || $objectUri === '.' || $objectUri === '..') {
            return new JSONResponse(['error' => 'La reserva seleccionada no es válida.'], Http::STATUS_BAD_REQUEST);
        }

        $timeZone = match ($timeZone) {
            'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
            default => $timeZone,
        };
        try {
            $timezone = new DateTimeZone($timeZone);
            $startDate = new DateTimeImmutable($start, $timezone);
        } catch (\Exception) {
            return new JSONResponse(['error' => 'El intervalo de reserva no es válido.'], Http::STATUS_BAD_REQUEST);
        }

        if ($roomKey === '') {
            return $this->updateOwnCalendarEvent(
                $user,
                $calendarUri,
                $objectUri,
                $subject,
                $startDate,
                $durationMinutes,
                $allDay,
            );
        }

        if ($allDay) {
            return new JSONResponse(['error' => 'Las reservas de sala deben tener horario.'], Http::STATUS_BAD_REQUEST);
        }

        $lastStartDate = null;
        if ($reservationType === 'continuous') {
            $lastStartDate = $lastOccurrenceDate === null
                ? false
                : DateTimeImmutable::createFromFormat('!Y-m-d', $lastOccurrenceDate, $timezone);
            if ($lastStartDate === false || $lastStartDate->format('Y-m-d') !== $lastOccurrenceDate
                || $lastStartDate->format('N') !== $startDate->format('N')
                || $lastStartDate < $startDate->setTime(0, 0)) {
                return new JSONResponse([
                    'error' => 'El último día debe ser igual o posterior al inicio y coincidir con el mismo día de la semana.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $lastStartDate = $lastStartDate->setTime(
                (int)$startDate->format('H'),
                (int)$startDate->format('i'),
                (int)$startDate->format('s'),
            );
            if (intdiv($startDate->diff($lastStartDate)->days, 7) + 1 > 104) {
                return new JSONResponse(['error' => 'El período continuo no puede superar 104 semanas.'], Http::STATUS_BAD_REQUEST);
            }
        }

        $endDate = ($lastStartDate ?? $startDate)->modify('+' . $durationMinutes . ' minutes');
        $occurrenceEndDate = $startDate->modify('+' . $durationMinutes . ' minutes');
        $room = $this->findRoomForUser($roomKey, $user);
        if ($room === null) {
            return new JSONResponse(['error' => 'El espacio no existe o no está disponible para tu usuario.'], Http::STATUS_NOT_FOUND);
        }

        $userEmail = $user->getEMailAddress();
        if ($userEmail === null || filter_var($userEmail, FILTER_VALIDATE_EMAIL) === false) {
            return new JSONResponse([
                'error' => 'Configura un correo electrónico válido en tu perfil para editar una reserva.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $lockPath = 'reservas/room/' . hash('sha256', $roomKey);
        try {
            $this->lockingProvider->acquireLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            return new JSONResponse([
                'error' => 'Ya se está procesando una reserva para este espacio. Inténtalo nuevamente.',
            ], Http::STATUS_CONFLICT);
        }

        try {
            $roomPrincipal = 'principals/calendar-rooms/' . $roomKey;
            $roomQuery = $this->calendarManager->newQuery($roomPrincipal);
            $roomQuery->addSearchCalendar('calendar');
            $roomQuery->addType('VEVENT');
            $eventToUpdate = null;
            foreach ($this->calendarManager->searchForPrincipal($roomQuery) as $object) {
                if (($object['uri'] ?? null) === $objectUri) {
                    $eventToUpdate = $object;
                    break;
                }
            }
            if ($eventToUpdate === null) {
                return new JSONResponse(['error' => 'La reserva ya no existe o no está disponible.'], Http::STATUS_NOT_FOUND);
            }
            $component = $eventToUpdate['objects'][0] ?? [];
            $existingDescription = (string)($this->propertyValue($component, 'DESCRIPTION') ?? '');
            $existingIsRecurring = $this->propertyValue($component, 'RRULE') !== null
                || str_contains(mb_strtolower($existingDescription), 'tipo: período continuo');
            if ($existingIsRecurring && !$this->canUserCreateRecurringReservations($user)) {
                return new JSONResponse([
                    'error' => 'Tu grupo no tiene permiso para modificar reservas recurrentes.',
                ], Http::STATUS_FORBIDDEN);
            }
            if (!$this->isEventOrganizer($eventToUpdate, $user)) {
                return new JSONResponse(['error' => 'Solo quien creó la reserva puede editarla.'], Http::STATUS_FORBIDDEN);
            }

            $conflicts = $this->findConflicts(
                $room,
                $startDate,
                $endDate,
                $durationMinutes,
                $reservationType === 'continuous',
                $objectUri,
            );
            if ($conflicts !== []) {
                return new JSONResponse([
                    'error' => 'El espacio ya tiene una reserva que se superpone con ese intervalo.',
                    'conflicts' => array_map(static fn (array $conflict): array => [
                        'subject' => $conflict['subject'],
                        'owner' => $conflict['owner'],
                        'ownerEmail' => $conflict['ownerEmail'],
                        'start' => $conflict['start'],
                        'end' => $conflict['end'],
                    ], $conflicts),
                ], Http::STATUS_CONFLICT);
            }

            try {
                $builder = $this->calendarManager->createEventBuilder()
                    ->setStartDate($startDate)
                    ->setEndDate($occurrenceEndDate)
                    ->setSummary($subject)
                    ->setStatus(CalendarEventStatus::CONFIRMED)
                    ->setLocation($room->getDisplayName())
                    ->setDescription($this->reservationDescription(
                        $subject,
                        $user->getDisplayName(),
                        $this->eventRecipient($existingDescription),
                        $this->eventRecipientEmail($existingDescription),
                        $reservationType,
                    ))
                    ->setOrganizer($userEmail, $user->getDisplayName());
                $calendarData = Reader::read($builder->toIcs());
                $uid = (string)($eventToUpdate['uid'] ?? '');
                if ($uid === '') {
                    throw new \RuntimeException('The existing room reservation has no UID.');
                }
                $calendarData->VEVENT->UID->setValue($uid);
                if ($lastStartDate !== null) {
                    $calendarData->VEVENT->add(
                        'RRULE',
                        'FREQ=WEEKLY;UNTIL=' . $lastStartDate
                            ->setTimezone(new DateTimeZone('UTC'))
                            ->format('Ymd\THis\Z'),
                    );
                }
                $this->updateRoomCalendarObject(
                    $roomKey,
                    $roomPrincipal,
                    $objectUri,
                    $calendarData->serialize(),
                );
            } catch (\OCP\Calendar\Exceptions\CalendarException|\InvalidArgumentException|\RuntimeException|\Sabre\DAV\Exception|\Sabre\VObject\ParseException $exception) {
                $this->logger->error('Could not update Reservas reservation', ['exception' => $exception]);
                return new JSONResponse([
                    'error' => 'No se pudo actualizar la reserva en Calendar.',
                ], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            return new JSONResponse(['success' => true]);
        } finally {
            $this->lockingProvider->releaseLock($lockPath, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    #[NoAdminRequired]
    public function deleteReservation(string $calendarUri = '', string $objectUri = '', string $roomKey = ''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->unauthenticated();
        }
        if (!$this->canUserUseApplication($user)) {
            return new JSONResponse(['error' => 'No tienes permiso para utilizar la aplicación.'], Http::STATUS_FORBIDDEN);
        }
        // debug        
/* $this->logger->warning('Reservas: delete reservation requested', [
            'userId' => $user->getUID(),
            'userEmail' => $user->getEMailAddress(),
            'roomKey' => $roomKey,
            'calendarUri' => $calendarUri,
            'objectUri' => $objectUri,
        ]);*/
        // end debug

        if ($calendarUri === '' || $objectUri === ''
            || str_contains($objectUri, '/')
            || str_contains($objectUri, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $objectUri) === 1
            || $objectUri === '.' || $objectUri === '..') {
            return new JSONResponse(['error' => 'La reserva seleccionada no es válida.'], Http::STATUS_BAD_REQUEST);
        }

        if ($roomKey !== '') {
            $room = $this->findRoomForUser($roomKey, $user);
            if ($room === null) {
                return new JSONResponse(['error' => 'El espacio no existe o no está disponible para tu usuario.'], Http::STATUS_NOT_FOUND);
            }

            $roomPrincipal = 'principals/calendar-rooms/' . $roomKey;
            $roomQuery = $this->calendarManager->newQuery($roomPrincipal);
            $roomQuery->addSearchCalendar('calendar');
            $roomQuery->addType('VEVENT');
            foreach ($this->calendarManager->searchForPrincipal($roomQuery) as $object) {
                // debug
/* $this->logger->warning('Reservas: delete reservation requested', [
                    'userId' => $user->getUID(),
                    'userEmail' => $user->getEMailAddress(),
                    'roomKey' => $roomKey,
                    'calendarUri' => $calendarUri,
                    'objectUri' => $objectUri,
                ]);                */
                // end debug
                if (($object['uri'] ?? null) !== $objectUri) {
                    continue;
                }
                $component = $object['objects'][0] ?? [];
                $description = (string)($this->propertyValue($component, 'DESCRIPTION') ?? '');
                $isRecurring = $this->propertyValue($component, 'RRULE') !== null
                    || str_contains(mb_strtolower($description), 'tipo: período continuo');
                if ($isRecurring && !$this->canUserCreateRecurringReservations($user)) {
                    return new JSONResponse([
                        'error' => 'Tu grupo no tiene permiso para eliminar reservas recurrentes.',
                    ], Http::STATUS_FORBIDDEN);
                }
                if (!$this->isEventOrganizer($object, $user)) {
                    return new JSONResponse(['error' => 'Solo quien creó la reserva puede eliminarla.'], Http::STATUS_FORBIDDEN);
                }

                try {
                    $davServer = $this->getRoomDavServer($roomPrincipal);
                    $path = 'system-calendars/calendar-rooms/' . $roomKey . '/calendar/' . $objectUri;
                    $davServer->tree->getNodeForPath($path)->delete();
                } catch (\Sabre\DAV\Exception $exception) {
                    $this->logger->error('Could not delete Reservas room reservation', ['exception' => $exception]);
                    return new JSONResponse(['error' => 'No se pudo eliminar la reserva del espacio.'], Http::STATUS_INTERNAL_SERVER_ERROR);
                }

                return new JSONResponse(['success' => true]);
            }
        }
        // debug
        $this->logger->warning('Reservas: room reservation not found for deletion', [
            'userId' => $user->getUID(),
            'roomKey' => $roomKey,
           // 'principal' => $roomPrincipal,
            'calendarUri' => 'calendar',
            'objectUri' => $objectUri,
        ]);
        // end debug

        $calendar = $this->findWritableCalendar($user, $calendarUri);
        if ($calendar === null || ($calendar->getPermissions() & Constants::PERMISSION_UPDATE) === 0) {
            return new JSONResponse(['error' => 'No tienes permiso para eliminar esta reserva.'], Http::STATUS_FORBIDDEN);
        }

        $principal = 'principals/users/' . $user->getUID();
        $query = $this->calendarManager->newQuery($principal);
        $query->addSearchCalendar($calendarUri);
        $query->addType('VEVENT');

        $ownedEventFound = false;
        foreach ($this->calendarManager->searchForPrincipal($query) as $object) {
            if (($object['uri'] ?? null) !== $objectUri) {
                continue;
            }

            if (!$this->isEventOrganizer($object, $user)) {
                return new JSONResponse(['error' => 'Solo quien creó la reserva puede eliminarla.'], Http::STATUS_FORBIDDEN);
            }

            $ownedEventFound = true;
            break;
        }

        if (!$ownedEventFound) {
            return new JSONResponse(['error' => 'La reserva ya no existe o no está disponible.'], Http::STATUS_NOT_FOUND);
        }

        try {
            $server = new EmbeddedCalDavServer(false);
            $davServer = $server->getServer();
            $authPlugin = $davServer->getPlugin('auth');
            if (!$authPlugin instanceof CustomPrincipalPlugin) {
                $this->logger->error('Could not set the principal for deleting a Reservas reservation');
                return new JSONResponse(['error' => 'No se pudo eliminar la reserva.'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
            $authPlugin->setCurrentPrincipal($principal);

            $path = '/calendars/' . $user->getUID() . '/' . $calendar->getUri() . '/' . $objectUri;
            $event = $davServer->tree->getNodeForPath($path);
            $event->delete();
        } catch (\Sabre\DAV\Exception $exception) {
            $this->logger->error('Could not delete Reservas reservation', ['exception' => $exception]);
            return new JSONResponse(['error' => 'No se pudo eliminar la reserva.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['success' => true]);
    }

    private function updateOwnCalendarEvent(
        IUser $user,
        string $calendarUri,
        string $objectUri,
        string $subject,
        DateTimeImmutable $startDate,
        int $durationMinutes,
        bool $allDay,
    ): JSONResponse {
        if ($calendarUri === '') {
            return new JSONResponse(['error' => 'El calendario seleccionado no es válido.'], Http::STATUS_BAD_REQUEST);
        }

        $calendar = $this->findWritableCalendar($user, $calendarUri);
        if ($calendar === null || ($calendar->getPermissions() & Constants::PERMISSION_UPDATE) === 0) {
            return new JSONResponse(['error' => 'No tienes permiso para editar este evento.'], Http::STATUS_FORBIDDEN);
        }

        $principal = 'principals/users/' . $user->getUID();
        $query = $this->calendarManager->newQuery($principal);
        $query->addSearchCalendar($calendarUri);
        $query->addType('VEVENT');
        $ownedEventFound = false;
        foreach ($this->calendarManager->searchForPrincipal($query) as $object) {
            if (($object['uri'] ?? null) !== $objectUri) {
                continue;
            }
            if (!$this->isEventOrganizer($object, $user)) {
                return new JSONResponse(['error' => 'Solo quien creó el evento puede editarlo.'], Http::STATUS_FORBIDDEN);
            }
            $ownedEventFound = true;
            break;
        }
        if (!$ownedEventFound) {
            return new JSONResponse(['error' => 'El evento ya no existe o no está disponible.'], Http::STATUS_NOT_FOUND);
        }

        try {
            $davServer = (new EmbeddedCalDavServer(false))->getServer();
            $authPlugin = $davServer->getPlugin('auth');
            if (!$authPlugin instanceof CustomPrincipalPlugin) {
                throw new \RuntimeException('Could not set the principal for updating a calendar event.');
            }
            $authPlugin->setCurrentPrincipal($principal);

            $path = '/calendars/' . $user->getUID() . '/' . $calendar->getUri() . '/' . $objectUri;
            $eventNode = $davServer->tree->getNodeForPath($path);
            $calendarData = Reader::read($eventNode->get());
            $eventComponents = $calendarData->select('VEVENT');
            $eventToUpdate = null;
            foreach ($eventComponents as $component) {
                if (!isset($component->{'RECURRENCE-ID'})) {
                    $eventToUpdate = $component;
                    break;
                }
            }
            if ($eventToUpdate === null) {
                throw new \RuntimeException('The calendar event has no master VEVENT.');
            }
            if (!isset($eventToUpdate->DTSTART)) {
                throw new \RuntimeException('The calendar event has no start date.');
            }

            if (isset($eventToUpdate->SUMMARY)) {
                $eventToUpdate->SUMMARY->setValue($subject);
            } else {
                $eventToUpdate->add('SUMMARY', $subject);
            }
            $endDate = $allDay ? $startDate->setTime(0, 0)->modify('+1 day') : $startDate->modify('+' . $durationMinutes . ' minutes');
            $startProperty = $eventToUpdate->DTSTART;
            $endProperty = isset($eventToUpdate->DTEND) ? $eventToUpdate->DTEND : null;
            if ($endProperty === null) {
                if (isset($eventToUpdate->DURATION)) {
                    $eventToUpdate->remove('DURATION');
                }
                $endProperty = $eventToUpdate->add(
                    'DTEND',
                    $allDay ? $endDate->format('Ymd') : $endDate,
                    $allDay ? ['VALUE' => 'DATE'] : [],
                );
            }
            foreach ([[$startProperty, $startDate], [$endProperty, $endDate]] as [$property, $date]) {
                if ($allDay) {
                    $property['VALUE'] = 'DATE';
                    unset($property['TZID']);
                    $property->setValue($date->format('Ymd'));
                } else {
                    unset($property['VALUE']);
                    $property->setDateTime($date);
                }
            }

            $schedulePlugin = $davServer->getPlugin('caldav-schedule');
            if (!$schedulePlugin instanceof \OCA\DAV\CalDAV\Schedule\Plugin) {
                throw new \RuntimeException('The CalDAV scheduling plugin is unavailable.');
            }
            $schedulePlugin->setPathOfCalendarObjectChange($path);
            $stream = fopen('php://memory', 'rb+');
            if ($stream === false) {
                throw new \RuntimeException('Could not allocate a stream for the calendar event update.');
            }
            try {
                if (fwrite($stream, $calendarData->serialize()) === false) {
                    throw new \RuntimeException('Could not prepare the calendar event update.');
                }
                rewind($stream);
                $eventNode->put($stream);
            } finally {
                fclose($stream);
            }
        } catch (\OCP\Calendar\Exceptions\CalendarException|\InvalidArgumentException|\RuntimeException|\Sabre\DAV\Exception|\Sabre\VObject\ParseException $exception) {
            $this->logger->error('Could not update Reservas event', ['exception' => $exception]);
            return new JSONResponse(['error' => 'No se pudo actualizar el evento.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['success' => true]);
    }

    private function writeRoomCalendarObject(
        string $roomKey,
        string $roomPrincipal,
        string $objectUri,
        string $calendarData,
    ): void {
        $davServer = $this->getRoomDavServer($roomPrincipal);
        $this->ensureRoomCalendarExists($davServer, $roomKey, $roomPrincipal);
        $schedulePlugin = $davServer->getPlugin('caldav-schedule');
        if (!$schedulePlugin instanceof \OCA\DAV\CalDAV\Schedule\Plugin) {
            throw new \RuntimeException('The CalDAV scheduling plugin is unavailable.');
        }

        $path = 'system-calendars/calendar-rooms/' . $roomKey . '/calendar/' . $objectUri;
        $schedulePlugin->setPathOfCalendarObjectChange($path);

        $stream = fopen('php://memory', 'rb+');
        if ($stream === false) {
            throw new \RuntimeException('Could not allocate a stream for the room reservation.');
        }

        try {
            if (fwrite($stream, $calendarData) === false) {
                throw new \RuntimeException('Could not prepare the room reservation data.');
            }
            rewind($stream);
            $davServer->createFile($path, $stream);
        } finally {
            fclose($stream);
        }
    }

    private function updateRoomCalendarObject(
        string $roomKey,
        string $roomPrincipal,
        string $objectUri,
        string $calendarData,
    ): void {
        $davServer = $this->getRoomDavServer($roomPrincipal);
        $path = 'system-calendars/calendar-rooms/' . $roomKey . '/calendar/' . $objectUri;
        $schedulePlugin = $davServer->getPlugin('caldav-schedule');
        if (!$schedulePlugin instanceof \OCA\DAV\CalDAV\Schedule\Plugin) {
            throw new \RuntimeException('The CalDAV scheduling plugin is unavailable.');
        }
        $schedulePlugin->setPathOfCalendarObjectChange($path);

        $stream = fopen('php://memory', 'rb+');
        if ($stream === false) {
            throw new \RuntimeException('Could not allocate a stream for the room reservation update.');
        }

        try {
            if (fwrite($stream, $calendarData) === false) {
                throw new \RuntimeException('Could not prepare the room reservation update.');
            }
            rewind($stream);
            $davServer->tree->getNodeForPath($path)->put($stream);
        } finally {
            fclose($stream);
        }
    }

    private function ensureRoomCalendarExists(
        \OCA\DAV\Connector\Sabre\Server $davServer,
        string $roomKey,
        string $roomPrincipal,
    ): void {
        $calendarHomePath = 'system-calendars/calendar-rooms/' . $roomKey;
        $calendarHome = $davServer->tree->getNodeForPath($calendarHomePath);
        if (!$calendarHome instanceof CalendarHome) {
            throw new \RuntimeException('The room calendar home could not be resolved.');
        }

        $calendarUri = CalDavBackend::RESOURCE_BOOKING_CALENDAR_URI;
        if ($calendarHome->childExists($calendarUri)) {
            return;
        }

        $backend = $calendarHome->getCalDAVBackend();
        if (!$backend instanceof CalDavBackend) {
            throw new \RuntimeException('The room calendar backend is unavailable.');
        }

        $backend->createCalendar($roomPrincipal, $calendarUri, [
            '{DAV:}displayname' => CalDavBackend::RESOURCE_BOOKING_CALENDAR_NAME,
        ]);
        $this->logger->info('Created missing Reservas room calendar', [
            'roomKey' => $roomKey,
            'principal' => $roomPrincipal,
            'calendarUri' => $calendarUri,
        ]);
    }

    private function getRoomDavServer(string $roomPrincipal): \OCA\DAV\Connector\Sabre\Server
    {
        $davServer = (new EmbeddedCalDavServer(false))->getServer();
        $authPlugin = $davServer->getPlugin('auth');
        if (!$authPlugin instanceof CustomPrincipalPlugin) {
            throw new \RuntimeException('Could not set the room principal for the CalDAV request.');
        }
        $authPlugin->setCurrentPrincipal($roomPrincipal);

        $aclPlugin = $davServer->getPlugin('acl');
        if (!$aclPlugin instanceof \Sabre\DAVACL\Plugin) {
            throw new \RuntimeException('The CalDAV ACL plugin is unavailable.');
        }
        $aclPlugin->principalCollectionSet = array_values(array_unique(array_merge(
            $aclPlugin->principalCollectionSet,
            ['principals/calendar-rooms'],
        )));

        return $davServer;
    }

    private function unauthenticated(): JSONResponse
    {
        return new JSONResponse(['error' => 'No autenticado.'], Http::STATUS_UNAUTHORIZED);
    }

    private function canUserUseApplication(IUser $user): bool
    {
        return $this->accessService->canUseApplication($user);
    }

    private function canUserCreateRecurringReservations(IUser $user): bool
    {
        return $this->accessService->canCreateRecurringReservations($user);
    }

    /**
     * @return list<array{key: string, displayName: string, principal: string, color: string}>
     */
    private function getRoomsForUser(IUser $user): array
    {
        $userGroups = $this->groupManager->getUserGroupIds($user);
        $rooms = [];

        foreach ($this->roomManager->getBackends() as $backend) {
            foreach ($backend->listAllRooms() as $roomId) {
                $room = $backend->getRoom($roomId);
                if ($room === null || !$this->canUserAccessRoom($room, $userGroups)) {
                    continue;
                }

                $key = $backend->getBackendIdentifier() . '-' . $room->getId();
                $rooms[] = [
                    'key' => $key,
                    'displayName' => $room->getDisplayName(),
                    'principal' => 'principals/calendar-rooms/' . $key,
                ];
            }
        }

        usort($rooms, static fn (array $first, array $second): int => strcasecmp(
            $first['displayName'],
            $second['displayName'],
        ));

        foreach ($rooms as $index => &$room) {
            $hue = (int)round(fmod($index * 137.508, 360));
            $room['color'] = sprintf('hsl(%d, 68%%, 42%%)', $hue);
        }
        unset($room);

        return $rooms;
    }

    private function findRoomForUser(string $key, IUser $user): ?IRoom
    {
        $userGroups = $this->groupManager->getUserGroupIds($user);

        foreach ($this->roomManager->getBackends() as $backend) {
            foreach ($backend->listAllRooms() as $roomId) {
                $room = $backend->getRoom($roomId);
                if ($room !== null
                    && $key === $backend->getBackendIdentifier() . '-' . $room->getId()
                    && $this->canUserAccessRoom($room, $userGroups)) {
                    return $room;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $userGroups
     */
    private function canUserAccessRoom(IRoom $room, array $userGroups): bool
    {
        $restrictions = $room->getGroupRestrictions();

        return $restrictions === [] || array_intersect($restrictions, $userGroups) !== [];
    }

    private function findWritableCalendar(IUser $user, string $calendarUri): ?ICreateFromString
    {
        $principal = 'principals/users/' . $user->getUID();
        foreach ($this->calendarManager->getCalendarsForPrincipal($principal) as $calendar) {
            if ($calendar->getUri() === $calendarUri
                && $calendar instanceof ICreateFromString
                && ($calendar->getPermissions() & Constants::PERMISSION_CREATE) !== 0) {
                return $calendar;
            }
        }

        return null;
    }

    /**
     * The time range makes Nextcloud's CalDAV backend expand recurring VEVENTs
     * into their effective instances before returning them.
     *
     * @return list<array>
     */
    private function getRoomEvents(string $principal, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $query = $this->calendarManager->newQuery($principal);
        $query->addSearchCalendar('calendar');
        $query->addType('VEVENT');
        $query->setTimerangeStart($from);
        $query->setTimerangeEnd($to);

        $events = $this->calendarManager->searchForPrincipal($query);
        

/*
        $this->logger->debug('Found room events', [
            'principal' => $principal,
            'from' => $from->format(DATE_ATOM),
            'to' => $to->format(DATE_ATOM),
            'eventCount' => count($events),
        ]);
*/
$date = new DateTimeImmutable();
/*
        error_log(sprintf(
            '[reservas] getRoomEvents principal=%s from=%s to=%s count=%d in time %s',
            $principal,
            $from->format(DATE_ATOM),
            $to->format(DATE_ATOM),
            count($events),
            $date->format(DateTimeInterface::RFC2822)
        ));
*/
        return $events;
    }

    /**
     * @return list<array{uid: string, subject: string, owner: string, start: string, end: string, organizer: string}>
     */
/*    private function findConflicts(
        IRoom $room,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $durationMinutes,
        bool $weekly,
    ): array
    {
        $principal = 'principals/calendar-rooms/' . $room->getBackend()->getBackendIdentifier() . '-' . $room->getId();
  
        $conflicts = [];
        $reservationSlots = [];
        $lastOccurrenceStart = $weekly
            ? $end->modify('-' . $durationMinutes . ' minutes')
            : $start;

        for ($occurrenceStart = $start; $occurrenceStart <= $lastOccurrenceStart; $occurrenceStart = $occurrenceStart->modify('+1 week')) {
            $reservationSlots[] = [
                $occurrenceStart,
                $occurrenceStart->modify('+' . $durationMinutes . ' minutes'),
            ];
        }

        foreach ($this->getRoomEvents($principal, $start, $end) as $object) {
            $this->logger->debug('Checking event for conflicts in room', [
                'uid' => (string)($object['uid'] ?? ''),
                'calendarUri' => (string)($object['calendar-uri'] ?? ''),
                'objectUri' => (string)($object['uri'] ?? ''),
            ]);

            foreach ($object['objects'] ?? [] as $component) {
                if (!is_array($component)
                    || strtoupper((string)($this->propertyValue($component, 'STATUS') ?? '')) === 'CANCELLED') {
                    continue;
                }

                $eventStart = $this->parseCalendarDate($this->propertyValue($component, 'DTSTART'));
                $eventEnd = $this->parseCalendarDate($this->propertyValue($component, 'DTEND'));
                if ($eventStart === null || $eventEnd === null) {
                    continue;
                }

                // Compare the effective occurrence returned by the time-range query.
                $overlaps = false;
                foreach ($reservationSlots as [$slotStart, $slotEnd]) {
                    if ($slotStart < $eventEnd && $slotEnd > $eventStart) {
                        $overlaps = true;
                        break;
                    }
                }
                if (!$overlaps) {
                    continue;
                }

                $organizer = (string)($this->propertyValue($component, 'ORGANIZER') ?? '');
                $description = (string)($this->propertyValue($component, 'DESCRIPTION') ?? '');
                $conflicts[] = [
                    'uid' => (string)($object['uid'] ?? ''),
                    'subject' => (string)($this->propertyValue($component, 'SUMMARY') ?? ''),
                    'owner' => $this->eventOwner($description, $organizer),
                    'start' => $eventStart->format(DATE_ATOM),
                    'end' => $eventEnd->format(DATE_ATOM),
                    'organizer' => $organizer,
                ];
            }
        }

        return $conflicts;
    }*/

    /**
     * @return list<array{uid: string, subject: string, owner: string, start: string, end: string, organizer: string}>
     */
    private function findConflicts(
        IRoom $room,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $durationMinutes,
        bool $weekly,
        ?string $excludeObjectUri = null,
    ): array {
        $principal = 'principals/calendar-rooms/' . $room->getBackend()->getBackendIdentifier() . '-' . $room->getId();

        $conflicts = [];
        $reservationSlots = [];
        $lastOccurrenceStart = $weekly
            ? $end->modify('-' . $durationMinutes . ' minutes')
            : $start;

        for ($occurrenceStart = $start; $occurrenceStart <= $lastOccurrenceStart; $occurrenceStart = $occurrenceStart->modify('+1 week')) {
            $reservationSlots[] = [
                $occurrenceStart,
                $occurrenceStart->modify('+' . $durationMinutes . ' minutes'),
            ];
        }
/*                    error_log(sprintf(
            '[reservas] findConflicts before principal=%s from=%s to=%s ',
            $room->getDisplayName(),
            $start->format(DATE_ATOM),
            $end->format(DATE_ATOM)
            
        ));
*/
        $eventosEnSala = $this->getRoomEvents($principal, $start, $end);
/*
        error_log("eventos " . print_r($eventosEnSala, true));

        error_log(sprintf(
            '[reservas] Checking for conflicts in room=%s principal=%s from=%s to=%s durationMinutes=%d weekly=%s en %d events' ,
            $room->getDisplayName(),
            $principal,
            $start->format(DATE_ATOM),
            $end->format(DATE_ATOM),
            $durationMinutes,
            $weekly ? 'true' : 'false',
            count($eventosEnSala)
        ));
*/
        foreach ($eventosEnSala as $object) {
            if ($excludeObjectUri !== null && ($object['uri'] ?? null) === $excludeObjectUri) {
                continue;
            }
   /*
            error_log(sprintf(
                '[reservas] findConflicts Found room events principal=%s from=%s to=%s count=%d',
                $principal,
                $start->format(DATE_ATOM),
                $end->format(DATE_ATOM),
                count($eventosEnSala),
            ));
*/
            foreach ($object['objects'] ?? [] as $component) {
                if (!is_array($component)
                    || strtoupper((string)($this->propertyValue($component, 'STATUS') ?? '')) === 'CANCELLED') {
                    continue;
                }

                $eventStart = $this->parseCalendarDate($this->propertyValue($component, 'DTSTART'));
                $eventEnd = $this->parseCalendarDate($this->propertyValue($component, 'DTEND'));
                if ($eventStart === null || $eventEnd === null) {
                    continue;
                }

                // Parseamos la regla de recurrencia (RRULE) correctamente
                $rrule = (string)($this->propertyValue($component, 'RRULE') ?? '');
                $freq = null;
                $until = null;
                $interval = 1;

                if ($rrule !== '') {
                    $parts = explode(';', strtoupper($rrule));
                    foreach ($parts as $part) {
                        if (str_starts_with($part, 'FREQ=')) {
                            $freq = substr($part, 5);
                        } elseif (str_starts_with($part, 'UNTIL=')) {
                            $until = $this->parseCalendarDate(substr($part, 6));
                        } elseif (str_starts_with($part, 'INTERVAL=')) {
                            $interval = max(1, (int)substr($part, 9));
                        }
                    }
                }

                $overlaps = false;
                $conflictStart = $eventStart;
                $conflictEnd = $eventEnd;

                foreach ($reservationSlots as [$slotStart, $slotEnd]) {
                    if ($freq === null) {
                        // Evento puntual: chequeo de superposición estándar
                        if ($slotStart < $eventEnd && $slotEnd > $eventStart) {
                            $overlaps = true;
                            break;
                        }
                        continue;
                    }

                    // Evento recurrente: verificamos si el slot cae en una ocurrencia válida
                    if ($slotStart < $eventStart) {
                        continue;
                    }
                    if ($until !== null && $slotStart > $until) {
                        continue;
                    }

                    $diff = $eventStart->diff($slotStart);
                    $matchesFreq = false;

                    // Comprobamos si la diferencia de tiempo encaja con la frecuencia (DAILY, WEEKLY, etc.)
                    if ($freq === 'DAILY') {
                        $matchesFreq = ($diff->days % $interval === 0);
                    } elseif ($freq === 'WEEKLY') {
                        $matchesFreq = ($diff->days % 7 === 0) && (intdiv($diff->days, 7) % $interval === 0);
                    } elseif ($freq === 'MONTHLY') {
                        $monthsDiff = ($diff->y * 12) + $diff->m;
                        $matchesFreq = ($diff->d === 0) && ($monthsDiff % $interval === 0);
                    } elseif ($freq === 'YEARLY') {
                        $matchesFreq = ($diff->m === 0 && $diff->d === 0) && ($diff->y % $interval === 0);
                    }

                    if ($matchesFreq) {
                        // Si es una ocurrencia válida, calculamos sus horas exactas de inicio y fin
                        $conflictStart = $eventStart->setDate(
                            (int)$slotStart->format('Y'),
                            (int)$slotStart->format('m'),
                            (int)$slotStart->format('d')
                        );
                        $conflictEnd = $conflictStart->modify('+' . ($eventEnd->getTimestamp() - $eventStart->getTimestamp()) . ' seconds');

                        if ($slotStart < $conflictEnd && $slotEnd > $conflictStart) {
                            $overlaps = true;
                            break;
                        }
                    }
                }

                if (!$overlaps) {
                    continue;
                }

                $description = (string)($this->propertyValue($component, 'DESCRIPTION') ?? '');
                $organizer = (string)($this->propertyValue($component, 'ORGANIZER') ?? '');
                $recipient = $this->eventRecipient($description);
                $recipientEmail = $this->eventRecipientEmail($description);
                $organizerEmail = $this->organizerEmail($organizer);
                $owner = $recipient ?? $this->eventOwner($description, $organizer);
                $ownerEmail = $recipientEmail !== ''
                    ? $recipientEmail
                    : (filter_var($organizerEmail, FILTER_VALIDATE_EMAIL) !== false ? $organizerEmail : '');
                $conflicts[] = [
                    'uid' => (string)($object['uid'] ?? ''),
                    'subject' => (string)($this->propertyValue($component, 'SUMMARY') ?? ''),
                    'owner' => $owner,
                    'ownerEmail' => $ownerEmail,
                    // Devolvemos la fecha exacta de la ocurrencia que genera el conflicto, no la del evento maestro
                    'start' => $conflictStart->format(DATE_ATOM),
                    'end' => $conflictEnd->format(DATE_ATOM),
                    'organizer' => $organizer,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param list<array{uid: string, subject: string, owner: string, start: string, end: string, organizer: string}> $conflicts
     */
    private function notifyExistingOwners(array $conflicts, string $roomName, string $requestedSubject): void
    {
        $notified = [];

        foreach ($conflicts as $conflict) {
            $email = $this->organizerEmail($conflict['organizer']);
            if ($email === '') {
                continue;
            }

            foreach ($this->userManager->getByEmail($email) as $owner) {
                if (!$owner instanceof IUser || isset($notified[$owner->getUID()])) {
                    continue;
                }

                $notification = $this->notificationManager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setUser($owner->getUID())
                    ->setDateTime(new \DateTime())
                    ->setObject('reservation', hash('sha256', $conflict['uid'] . $conflict['start']))
                    ->setSubject('collision', [$roomName, $requestedSubject])
                    ->setMessage('collision', [$conflict['subject'], $conflict['start'], $conflict['end']]);
                $this->notificationManager->notify($notification);
                $notified[$owner->getUID()] = true;
            }
        }
    }

    /**
     * @param array $object
     * @return array|null
     */
    private function mapEvent(
        array $object,
        ?string $roomName = null,
        ?string $roomKey = null,
        ?string $roomColor = null,
        bool $canDelete = false,
        ?string $seriesEndDate = null,
        bool $canEdit = false,
    ): ?array
    {
        $component = $object['objects'][0] ?? [];
        if (!is_array($component)) {
            return null;
        }

        $startProperty = $component['DTSTART'] ?? null;
        $allDay = $this->isDateProperty($startProperty);
        $startValue = $this->propertyValue($component, 'DTSTART');
        $eventStart = $this->formatEventDate($startValue, $allDay);
        if ($eventStart === null) {
            return null;
        }

        $endProperty = $component['DTEND'] ?? null;
        $eventEnd = $this->formatEventDate(
            $this->propertyValue($component, 'DTEND'),
            $this->isDateProperty($endProperty),
        );
        $uid = (string)($object['uid'] ?? '');
        $description = (string)($this->propertyValue($component, 'DESCRIPTION') ?? '');
        $organizer = (string)($this->propertyValue($component, 'ORGANIZER') ?? '');
        $organizerEmail = $this->organizerEmail($organizer);
        $reservationType = match (true) {
            str_contains(mb_strtolower($description), 'tipo: puntual') => 'Puntual',
            str_contains(mb_strtolower($description), 'tipo: período continuo') => 'Período continuo',
            default => '',
        };

        if ($reservationType === 'Período continuo' && $eventEnd !== null) {
            $startDate = $this->parseCalendarDate($startValue);
            $endDate = $this->parseCalendarDate($this->propertyValue($component, 'DTEND'));
            if ($startDate !== null && $endDate !== null) {
                $eventDuration = $endDate->getTimestamp() - $startDate->getTimestamp();
                $weekDuration = 7 * 24 * 60 * 60;

                if ($eventDuration > $weekDuration) {
                    $occurrenceDuration = $eventDuration % $weekDuration;
                    if ($occurrenceDuration > 0) {
                        $eventEnd = $startDate
                            ->modify('+' . $occurrenceDuration . ' seconds')
                            ->format(DATE_ATOM);
                    }
                }
            }
        }

        $event = [
            'id' => implode(':', [
                $uid,
                $eventStart,
            ]),
            'title' => (string)($this->propertyValue($component, 'SUMMARY') ?? ''),
            'start' => $eventStart,
            'allDay' => $allDay,
            'owner' => $this->eventOwner(
                $description,
                $organizer,
            ),
            'ownerEmail' => filter_var($organizerEmail, FILTER_VALIDATE_EMAIL) !== false
                ? $organizerEmail
                : '',
            'recipient' => $this->eventRecipient($description) ?? '',
            'recipientEmail' => $this->eventRecipientEmail($description),
            'location' => $roomName ?? (string)($this->propertyValue($component, 'LOCATION') ?? ''),
            'roomKey' => $roomKey,
            'reservationType' => $reservationType,
            'isRecurring' => $this->propertyValue($component, 'RRULE') !== null,
        ];

        if ($roomColor !== null) {
            $event['backgroundColor'] = $roomColor;
            $event['borderColor'] = $roomColor;
            $event['textColor'] = '#fff';
        }

        if ($eventEnd !== null) {
            $event['end'] = $eventEnd;
        }

        if ($reservationType === 'Período continuo' && $seriesEndDate !== null) {
            $event['seriesEndDate'] = $seriesEndDate;
        }

        if ($canDelete && isset($object['calendar-uri'], $object['uri'])) {
            $event['calendarUri'] = (string)$object['calendar-uri'];
            $event['objectUri'] = (string)$object['uri'];
            $event['canDelete'] = true;
        }
        if ($canEdit && $canDelete && isset($object['uri'])) {
            $event['canEdit'] = true;
        }

        return $event;
    }

    private function recurrenceEndDate(mixed $component): ?string
    {
        if (!is_array($component)) {
            return null;
        }

        $rule = $this->propertyValue($component, 'RRULE');
        if (!is_string($rule) || preg_match('/(?:^|;)UNTIL=(\d{8})(?:T(\d{6})(Z)?)?(?:;|$)/', $rule, $matches) !== 1) {
            return null;
        }

        if (!isset($matches[2])) {
            return substr($matches[1], 0, 4) . '-' . substr($matches[1], 4, 2) . '-' . substr($matches[1], 6, 2);
        }

        $startDate = $this->parseCalendarDate($this->propertyValue($component, 'DTSTART'));
        $timezone = $startDate?->getTimezone() ?? new DateTimeZone('UTC');
        $until = DateTimeImmutable::createFromFormat(
            '!Ymd\THis\Z',
            $matches[1] . 'T' . $matches[2] . 'Z',
            new DateTimeZone('UTC'),
        );
        if ($until === false) {
            return null;
        }

        return $until->setTimezone($timezone)->format('Y-m-d');
    }

    private function isEventOrganizer(array $object, IUser $user): bool
    {
        $component = $object['objects'][0] ?? [];
        if (!is_array($component)) {
            return false;
        }

        $organizerEmail = $this->organizerEmail((string)($this->propertyValue($component, 'ORGANIZER') ?? ''));
        $userEmail = $user->getEMailAddress();

        return $organizerEmail !== ''
            && $userEmail !== null
            && strcasecmp($organizerEmail, $userEmail) === 0;
    }

    private function propertyValue(array $component, string $name): mixed
    {
        $property = $component[$name] ?? null;

        return is_array($property) ? ($property[0] ?? null) : $property;
    }

    private function isDateProperty(mixed $property): bool
    {
        if (!is_array($property)) {
            return false;
        }

        $valueParameter = $property[1]['VALUE'] ?? null;

        return $valueParameter instanceof Parameter
            && strtoupper($valueParameter->getValue()) === 'DATE';
    }

    private function formatEventDate(mixed $value, bool $allDay): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($allDay ? 'Y-m-d' : DATE_ATOM);
        }

        $date = $this->parseCalendarDate($value);
        return $date?->format($allDay ? 'Y-m-d' : DATE_ATOM);
    }

    private function parseCalendarDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/^\d{8}$/D', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Ymd', $value);
            return $date === false ? null : $date;
        }
        if (preg_match('/^\d{8}T\d{6}Z$/D', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $value, new \DateTimeZone('UTC'));
            return $date === false ? null : $date;
        }
        if (preg_match('/^\d{8}T\d{6}$/D', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Ymd\THis', $value);
            return $date === false ? null : $date;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\DateMalformedStringException|\InvalidArgumentException) {
            return null;
        }
    }

    private function reservationOwner(string $description): ?string
    {
        if (preg_match('/^Reservado por:\s*(.+)$/m', $description, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function reservationDescription(
        string $subject,
        string $owner,
        ?string $recipient,
        ?string $recipientEmail,
        string $reservationType,
    ): string {
        $lines = ['Materia: ' . $subject];
        if ($recipient !== null && $recipient !== '') {
            $recipientEmail = $recipientEmail !== null && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) !== false
                ? $recipientEmail
                : null;
            $lines[] = 'Para: ' . $recipient
                . ($recipientEmail === null ? '' : ' (' . $recipientEmail . ')');
        }
        $lines[] = 'Reservado por: ' . $owner;
        $lines[] = $reservationType === 'continuous'
            ? 'Tipo: Período continuo (semanal)'
            : 'Tipo: Puntual';

        return implode("\n", $lines);
    }

    private function eventRecipient(string $description): ?string
    {
        if (preg_match('/^Para:\s*(.+)$/m', $description, $matches) !== 1) {
            return null;
        }

        $recipient = trim($matches[1]);
        if (preg_match('/^(.*?)\s+\(([^()]+)\)$/', $recipient, $nameMatches) === 1
            && filter_var(trim($nameMatches[2]), FILTER_VALIDATE_EMAIL) !== false) {
            $recipient = trim($nameMatches[1]);
        }

        return $recipient === '' ? null : $recipient;
    }

    private function eventRecipientEmail(string $description): string
    {
        if (preg_match('/^Para:\s*.+\(([^()]+)\)\s*$/m', $description, $matches) === 1
            && filter_var(trim($matches[1]), FILTER_VALIDATE_EMAIL) !== false) {
            return trim($matches[1]);
        }

        return '';
    }

    private function eventOwner(string $description, string $organizer): string
    {
        $reservationOwner = $this->reservationOwner($description);
        if ($reservationOwner !== null) {
            return $reservationOwner;
        }

        $email = $this->organizerEmail($organizer);
        if ($email === '') {
            return '';
        }

        foreach ($this->userManager->getByEmail($email) as $user) {
            if ($user instanceof IUser) {
                return $user->getDisplayName();
            }
        }

        return $email;
    }

    private function organizerEmail(string $organizer): string
    {
        $organizer = trim($organizer);
        if (str_starts_with(strtolower($organizer), 'mailto:')) {
            return substr($organizer, 7);
        }

        return $organizer;
    }
}
