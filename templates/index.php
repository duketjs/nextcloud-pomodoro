<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Pomodoro\AppInfo\Application::APP_ID, OCA\Pomodoro\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Pomodoro\AppInfo\Application::APP_ID, OCA\Pomodoro\AppInfo\Application::APP_ID . '-main');

?>

<div id="pomodoro"></div>
