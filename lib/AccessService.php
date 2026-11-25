<?php

declare(strict_types=1);

namespace OCA\Reservas;

use OCA\Reservas\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;

class AccessService
{
    public function __construct(
        private IConfig $config,
        private IGroupManager $groupManager,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getConfiguredGroups(string $setting): array
    {
        $value = $this->config->getAppValue(Application::APP_ID, $setting, '');
        $groups = json_decode($value, true);
        if (!is_array($groups)) {
            $groups = $value === '' ? [] : [$value];
        }

        return array_values(array_unique(array_filter(
            $groups,
            static fn (mixed $group): bool => is_string($group) && $group !== '',
        )));
    }

    public function canUseApplication(IUser $user): bool
    {
        if ($this->groupManager->isAdmin($user->getUID())) {
            return true;
        }

        return $this->isInAnyGroup($user, [
            ...$this->getConfiguredGroups('application_group'),
            ...$this->getConfiguredGroups('recurring_group'),
        ]);
    }

    public function canCreateRecurringReservations(IUser $user): bool
    {
        return $this->isInAnyGroup($user, $this->getConfiguredGroups('recurring_group'));
    }

    /**
     * @param list<string> $groups
     */
    private function isInAnyGroup(IUser $user, array $groups): bool
    {
        foreach ($groups as $group) {
            if ($this->groupManager->isInGroup($user->getUID(), $group)) {
                return true;
            }
        }

        return false;
    }
}
