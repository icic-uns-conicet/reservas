<?php

declare(strict_types=1);

namespace OCA\Reservas\Controller;

use OCA\Reservas\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,    
    ) {
        parent::__construct($appName, $request);
    }

    public function save(
        ?array $recurringGroups = null,
        ?array $applicationGroups = null,
        string $recurringGroup = '',
        string $applicationGroup = '',
    ): JSONResponse {
        $this->logger->debug(
          'Reservas: ingreso al metodo save',
            ['app' => 'reservas']
        );

        $recurringGroups = $this->normalizeGroups($recurringGroups ?? ($recurringGroup === '' ? [] : [$recurringGroup]));
        $applicationGroups = $this->normalizeGroups($applicationGroups ?? ($applicationGroup === '' ? [] : [$applicationGroup]));
        if ($recurringGroups === null || $applicationGroups === null) {
            return new JSONResponse(['error' => 'La selección de grupos no es válida.'], Http::STATUS_BAD_REQUEST);
        }

        foreach ([...$recurringGroups, ...$applicationGroups] as $gid) {
            if ($gid !== '' && $this->groupManager->get($gid) === null) {
                return new JSONResponse([
                    'error' => 'El grupo seleccionado no existe.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        $this->config->setAppValue(Application::APP_ID, 'recurring_group', json_encode($recurringGroups, JSON_THROW_ON_ERROR));
        $this->config->setAppValue(Application::APP_ID, 'application_group', json_encode($applicationGroups, JSON_THROW_ON_ERROR));

        return new JSONResponse(['success' => true]);
    }

    public function saveLegacy(
        string $recurringGroup = '',
        string $applicationGroup = '',
    ): JSONResponse {
        return $this->save(null, null, $recurringGroup, $applicationGroup);
    }

    /**
     * @return list<string>|null
     */
    private function normalizeGroups(array $groups): ?array
    {
        foreach ($groups as $group) {
            if (!is_string($group)) {
                return null;
            }
        }

        return array_values(array_unique(array_filter(
            array_map('trim', $groups),
            static fn (string $group): bool => $group !== '',
        )));
    }
}
