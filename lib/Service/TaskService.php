<?php

declare(strict_types=1);

namespace OCA\Pomodoro\Service;

use OCP\Calendar\IManager as CalendarManager;
use OCP\IUserSession;

/**
 * Reads outstanding VTODO items from the user's CalDAV calendars.
 *
 * This goes through the public calendar API (\OCP\Calendar\IManager) rather
 * than talking CalDAV by hand, so it works with whatever backend stores the
 * calendars and requires no credentials of its own.
 *
 * The Tasks app stores its tasks as VTODO objects, so filtering on the VTODO
 * type is what "import from Tasks" means in practice.
 *
 * Shape of each result (see OCA\DAV\CalDAV\CalDavBackend::search()):
 *   [
 *     'id' => int, 'type' => 'VTODO', 'uid' => string, 'uri' => string,
 *     'objects' => [ 0 => ['SUMMARY' => '...', 'DUE' => DateTimeImmutable, ...] ],
 *     'timezones' => [...],
 *     'calendar-uri' => string, 'calendar-key' => int,
 *   ]
 */
class TaskService {
	/** Hard cap so a huge calendar cannot blow up the focus list. */
	private const MAX_RESULTS = 200;

	public function __construct(
		private CalendarManager $calendarManager,
		private IUserSession $userSession,
	) {
	}

	/**
	 * @return list<array{
	 *     id: string,
	 *     source: 'tasks',
	 *     title: string,
	 *     due: ?string,
	 *     priority: ?int,
	 *     status: ?string,
	 *     percentComplete: ?int,
	 *     calendar: string,
	 * }>
	 */
	public function getOpenTasks(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$principal = 'principals/users/' . $user->getUID();

		try {
			$query = $this->calendarManager->newQuery($principal);
			$query->addType('VTODO');
			$query->setLimit(self::MAX_RESULTS);
			$objects = $this->calendarManager->searchForPrincipal($query);
		} catch (\Throwable $e) {
			// A broken/unavailable calendar backend should not take the whole
			// focus list down; Deck items still render.
			return [];
		}

		$items = [];
		foreach ($objects as $object) {
			$item = $this->normalise($object);
			if ($item !== null && $this->isOpen($item)) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * @param array<string, mixed> $object raw search result
	 * @return array{
	 *     id: string,
	 *     source: 'tasks',
	 *     title: string,
	 *     due: ?string,
	 *     priority: ?int,
	 *     status: ?string,
	 *     percentComplete: ?int,
	 *     calendar: string,
	 * }|null
	 */
	private function normalise(array $object): ?array {
		// Only VTODO concerns us; the query filters for it but be defensive.
		if (isset($object['type']) && $object['type'] !== 'VTODO') {
			return null;
		}

		$uid = $this->scalar($object['uid'] ?? null);
		if ($uid === null) {
			return null;
		}

		/** @var array<string, mixed> $props */
		$props = $object['objects'][0] ?? [];

		$status = $this->scalar($props['STATUS'] ?? null);
		$percentRaw = $this->scalar($props['PERCENT-COMPLETE'] ?? null);
		$priorityRaw = $this->scalar($props['PRIORITY'] ?? null);
		$dueRaw = $props['DUE'] ?? null;
		if (is_array($dueRaw)) {
			$dueRaw = $dueRaw[0] ?? null;
		}

		return [
			'id' => $uid,
			'source' => 'tasks',
			'title' => $this->scalar($props['SUMMARY'] ?? null) ?? '(untitled task)',
			'due' => $dueRaw instanceof \DateTimeInterface ? $dueRaw->format(\DateTimeInterface::ATOM) : null,
			'priority' => is_numeric($priorityRaw) ? (int)$priorityRaw : null,
			'status' => $status,
			'percentComplete' => is_numeric($percentRaw) ? (int)$percentRaw : null,
			'calendar' => $this->scalar($object['calendar-uri'] ?? null) ?? '',
		];
	}

	/**
	 * A task counts as done when its status says so or its progress is 100%.
	 *
	 * @param array{status: ?string, percentComplete: ?int} $item
	 */
	private function isOpen(array $item): bool {
		$status = strtoupper((string)$item['status']);
		if ($status === 'COMPLETED' || $status === 'CANCELLED') {
			return false;
		}
		if ($item['percentComplete'] !== null && $item['percentComplete'] >= 100) {
			return false;
		}

		return true;
	}

	/**
	 * Property values are scalars when the iCalendar property is single-valued
	 * and arrays when it is multi-valued; normalise both to a string.
	 */
	private function scalar(mixed $value): ?string {
		if (is_array($value)) {
			$value = $value[0] ?? null;
		}
		if ($value === null || $value === '' || $value instanceof \DateTimeInterface) {
			return null;
		}

		return (string)$value;
	}
}
