<?php

declare(strict_types=1);

namespace OCA\Reservas\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier
{
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return 'reservas';
    }

    public function getName(): string
    {
        return $this->l10nFactory->get('reservas')->t('Reservas de espacios');
    }

    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== 'reservas' || $notification->getSubject() !== 'collision') {
            throw new UnknownNotificationException('notification');
        }

        $subjectParameters = $notification->getSubjectParameters();
        $messageParameters = $notification->getMessageParameters();
        $roomName = (string)($subjectParameters[0] ?? '');
        $requestedSubject = (string)($subjectParameters[1] ?? '');
        $existingSubject = (string)($messageParameters[0] ?? '');
        $start = (string)($messageParameters[1] ?? '');
        $end = (string)($messageParameters[2] ?? '');

        $notification->setParsedSubject(
            'Conflicto de reserva en ' . $roomName,
        )->setParsedMessage(
            'Se intentó reservar "' . $roomName . '" para "' . $requestedSubject
            . '". Tu reserva "' . $existingSubject . '" sigue vigente (' . $start . ' – ' . $end . ').',
        )->setIcon(
            $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'places/calendar.svg')),
        );

        return $notification;
    }
}
