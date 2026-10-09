<?php

declare(strict_types=1);

namespace OCA\Pomodoro\Notification;

use OCA\Pomodoro\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Turns the notifications our background job raises into text.
 *
 * The job stores only a subject plus parameters; this is where they become
 * something a person reads, in their own language.
 */
class Notifier implements INotifier {
	public const SUBJECT_FOCUS_FINISHED = 'focus_finished';
	public const SUBJECT_REST_OVER = 'rest_over';
	public const SUBJECT_ALL_CYCLES_DONE = 'all_cycles_done';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Pomodoro';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		$task = (string)($parameters['task'] ?? '');
		$minutes = (int)($parameters['minutes'] ?? 0);

		switch ($notification->getSubject()) {
			case self::SUBJECT_FOCUS_FINISHED:
				$subject = $l->t('Focus finished');
				$message = $l->t('Time for a %s minute rest.', [(string)$minutes]);
				break;
			case self::SUBJECT_REST_OVER:
				$subject = $l->t('Rest over');
				$message = $l->t('Back to focus on "%s".', [$task]);
				break;
			case self::SUBJECT_ALL_CYCLES_DONE:
				$subject = $l->t('All cycles done');
				$message = $l->t('"%s" has had all of its focus cycles.', [$task]);
				break;
			default:
				throw new UnknownNotificationException();
		}

		$notification->setParsedSubject($subject)
			->setParsedMessage($message);

		// setLink()/setIcon() reject an empty string with an exception, and both
		// helpers can come back empty (a missing image, an unresolvable route),
		// so only set what we actually got.
		$link = $this->url->linkToRoute('pomodoro.page.index');
		if ($link !== '') {
			$notification->setLink($link);
		}

		$icon = $this->url->imagePath(Application::APP_ID, 'app.svg');
		if ($icon !== '') {
			$notification->setIcon($icon);
		}

		return $notification;
	}
}
