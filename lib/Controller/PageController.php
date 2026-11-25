<?php

declare(strict_types=1);

namespace OCA\Reservas\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\Reservas\AccessService;

class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private AccessService $accessService,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new TemplateResponse('core', '403', [], 'guest');
        }

        if (!$this->accessService->canUseApplication($user)) {
            return new TemplateResponse('core', '403', [
                'message' => 'No tienes permiso para utilizar Reservas.',
            ], 'guest');
        }

        return new TemplateResponse(
            'reservas',
            'main'
        );
    }
}