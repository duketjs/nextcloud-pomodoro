<?php

declare(strict_types=1);

namespace Controller;

use OCA\Pomodoro\AppInfo\Application;
use OCA\Pomodoro\Controller\ApiController;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	public function testIndex(): void {
		$request = $this->createMock(IRequest::class);
		$controller = new ApiController(Application::APP_ID, $request);

		$this->assertEquals('Hello world!', $controller->index()->getData()['message']);
	}
}
