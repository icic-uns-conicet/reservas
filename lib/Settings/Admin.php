<?php

declare(strict_types=1);

namespace OCA\Reservas\Settings;

use OCA\Reservas\AppInfo\Application;
use OCA\Reservas\AccessService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\Settings\ISettings;

class Admin implements ISettings
{
    public function __construct(
        private IGroupManager $groupManager,
        private AccessService $accessService,
    ) {
    }

    public function getForm(): TemplateResponse
    {
        $groups = array_map(
            static fn ($group): array => [
                'gid' => $group->getGID(),
                'displayName' => $group->getDisplayName(),
            ],
            $this->groupManager->search('', -1, 0),
        );

        usort($groups, static fn (array $first, array $second): int =>
            strcasecmp($first['displayName'], $second['displayName']));

        return new TemplateResponse(
            Application::APP_ID,
            'settings-admin',
            [
                'groups' => $groups,
                'recurringGroups' => $this->accessService->getConfiguredGroups('recurring_group'),
                'applicationGroups' => $this->accessService->getConfiguredGroups('application_group'),
            ],
        );
    }

    public function getSection(): string
    {
        return Application::APP_ID;
    }

    public function getPriority(): int
    {
        return 50;
    }
}
