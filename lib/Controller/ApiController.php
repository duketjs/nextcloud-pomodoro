<?php

declare(strict_types=1);

namespace OCA\Pomodoro\Controller;

use OCA\Pomodoro\Service\TaskService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private TaskService $taskService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Outstanding tasks imported from the Tasks app (CalDAV VTODO objects).
	 *
	 * @return DataResponse<Http::STATUS_OK, array{tasks: list<array<string, mixed>>}, array{}>
	 *
	 * 200: Tasks returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/tasks')]
	public function tasks(): DataResponse {
		return new DataResponse([
			'tasks' => $this->taskService->getOpenTasks(),
		]);
	}
}
