<?php

declare(strict_types=1);

namespace Service;

use OCA\Pomodoro\Service\TaskConfigService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class TaskConfigServiceTest extends TestCase {
	/** @var array<string, string> */
	private array $stored = [];

	private function service(): TaskConfigService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): void {
				$this->stored[$key] = $value;
			}
		);

		return new TaskConfigService($config, $userSession);
	}

	public function testUnknownItemDefaultsToOneCycle(): void {
		$entry = $this->service()->get('deck:1');

		$this->assertSame(1, $entry['cyclesPerTask']);
		$this->assertSame(0, $entry['completed']);
		$this->assertSame(0, $entry['skipped']);
	}

	public function testCyclesAreClamped(): void {
		$service = $this->service();

		$this->assertSame(1, $service->setCycles('deck:1', 0)['cyclesPerTask'], 'zero falls back to the default');
		$this->assertSame(1, $service->setCycles('deck:1', -5)['cyclesPerTask'], 'negatives fall back to the default');
		$this->assertSame(24, $service->setCycles('deck:1', 99)['cyclesPerTask'], 'capped at the maximum');
		$this->assertSame(3, $service->setCycles('deck:1', 3)['cyclesPerTask']);
	}

	public function testCountersAccumulateWithoutTouchingTheTarget(): void {
		$service = $this->service();
		$service->setCycles('deck:1', 2);
		$service->recordCompleted('deck:1');
		$service->recordCompleted('deck:1');
		$service->recordSkipped('deck:1');

		$entry = $service->get('deck:1');

		$this->assertSame(2, $entry['completed']);
		$this->assertSame(1, $entry['skipped']);
		$this->assertSame(2, $entry['cyclesPerTask']);
	}

	public function testCountersAreKeptPerItem(): void {
		$service = $this->service();
		$service->recordCompleted('deck:1');

		$this->assertSame(1, $service->get('deck:1')['completed']);
		$this->assertSame(0, $service->get('deck:2')['completed']);
	}
}
