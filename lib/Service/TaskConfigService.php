<?php

declare(strict_types=1);

namespace OCA\Pomodoro\Service;

use OCP\IConfig;
use OCP\IUserSession;

/**
 * Per-item Pomodoro configuration and counters, keyed by the item id thrown up
 * by the import layer (e.g. `tasks:<uid>` or `deck:<id>`).
 *
 * Two things live here:
 *  - cyclesPerTask: how many focus cycles the user intends to spend on that
 *    item. Defaults to 1 until the user says otherwise.
 *  - completed / skipped: running tallies of finished vs abandoned focus
 *    cycles, so the list can show progress at a glance.
 *
 * A skipped cycle does not count towards cyclesPerTask - abandoning a cycle is
 * not the same as finishing it.
 */
class TaskConfigService {
	private const APP_ID = 'pomodoro';
	private const KEY = 'taskConfig';

	public const DEFAULT_CYCLES = 1;
	public const MAX_CYCLES = 24;

	public function __construct(
		private IConfig $config,
		private IUserSession $userSession,
	) {
	}

	/**
	 * @return array<string, array{cyclesPerTask: int, completed: int, skipped: int}>
	 */
	public function getAll(): array {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return [];
		}

		$raw = $this->config->getUserValue($uid, self::APP_ID, self::KEY, '');
		$decoded = $raw === '' ? null : json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		$out = [];
		foreach ($decoded as $id => $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$out[(string)$id] = [
				'cyclesPerTask' => $this->normaliseCycles((int)($entry['cyclesPerTask'] ?? self::DEFAULT_CYCLES)),
				'completed' => max(0, (int)($entry['completed'] ?? 0)),
				'skipped' => max(0, (int)($entry['skipped'] ?? 0)),
			];
		}

		return $out;
	}

	/**
	 * @return array{cyclesPerTask: int, completed: int, skipped: int}
	 */
	public function get(string $taskId): array {
		$all = $this->getAll();

		return $all[$taskId] ?? [
			'cyclesPerTask' => self::DEFAULT_CYCLES,
			'completed' => 0,
			'skipped' => 0,
		];
	}

	/**
	 * @return array{cyclesPerTask: int, completed: int, skipped: int}
	 */
	public function setCycles(string $taskId, int $cycles): array {
		$all = $this->getAll();
		$entry = $all[$taskId] ?? ['cyclesPerTask' => self::DEFAULT_CYCLES, 'completed' => 0, 'skipped' => 0];
		$entry['cyclesPerTask'] = $this->normaliseCycles($cycles);
		$all[$taskId] = $entry;
		$this->save($all);

		return $entry;
	}

	public function recordCompleted(string $taskId): void {
		$this->bump($taskId, 'completed');
	}

	public function recordSkipped(string $taskId): void {
		$this->bump($taskId, 'skipped');
	}

	public function normaliseCycles(int $cycles): int {
		if ($cycles < 1) {
			return self::DEFAULT_CYCLES;
		}

		return min($cycles, self::MAX_CYCLES);
	}

	private function bump(string $taskId, string $field): void {
		if ($taskId === '') {
			return;
		}

		$all = $this->getAll();
		$entry = $all[$taskId] ?? ['cyclesPerTask' => self::DEFAULT_CYCLES, 'completed' => 0, 'skipped' => 0];
		$entry[$field] = max(0, (int)$entry[$field]) + 1;
		$all[$taskId] = $entry;
		$this->save($all);
	}

	/**
	 * @param array<string, array{cyclesPerTask: int, completed: int, skipped: int}> $all
	 */
	private function save(array $all): void {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return;
		}

		$this->config->setUserValue($uid, self::APP_ID, self::KEY, json_encode($all, JSON_THROW_ON_ERROR));
	}
}
