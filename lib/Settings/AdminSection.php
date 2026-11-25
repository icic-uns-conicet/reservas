<?php

declare(strict_types=1);

namespace OCA\Reservas\Settings;

use OCA\Reservas\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
    public function __construct(
        private IL10N $l,
        private IURLGenerator $url,
    ) {
    }

    public function getID(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return $this->l->t('Reservas');
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function getIcon(): string
    {
        return $this->url->imagePath(Application::APP_ID, 'reservas-dark.svg');
    }
}
