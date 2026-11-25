<?php

declare(strict_types=1);

namespace OCA\Reservas\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\Reservas\Notification\Notifier;
use OCA\Reservas\Settings\Admin;
use OCA\Reservas\Settings\AdminSection;
use OCA\Reservas\AccessService;
use OCP\Settings\IManager as ISettingsManager;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'reservas';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerNotifierService(Notifier::class);
    }

    public function boot(IBootContext $context): void
    {
        $container = $context->getAppContainer();
        $settingsManager = $container->get(ISettingsManager::class);
        $settingsManager->registerSetting('admin', Admin::class);
        $settingsManager->registerSection('admin', AdminSection::class);

        $context->injectFn(function (
            AccessService $accessService,
            INavigationManager $navigationManager,
            IURLGenerator $urlGenerator,
            IL10N $l10n,
            IUserSession $userSession,
        ): void {
            $navigationManager->add(function () use ($accessService, $urlGenerator, $l10n, $userSession): ?array {
                $user = $userSession->getUser();
                if ($user === null || !$accessService->canUseApplication($user)) {
                    return null;
                }

                return [
                    'id' => self::APP_ID,
                    'app' => self::APP_ID,
                    'order' => 10,
                    'href' => $urlGenerator->linkToRoute(self::APP_ID . '.page.index'),
                    'icon' => $urlGenerator->imagePath(self::APP_ID, 'reservas.svg'),
                    'name' => $l10n->t('Reservas'),
                ];
            });
        });
    }
}
