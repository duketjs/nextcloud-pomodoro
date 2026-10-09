/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import type { FocusItem } from './types'

/**
 * Our own OCS endpoint. The reading happens server-side in TaskService via the
 * public calendar API (see lib/Service/TaskService.php) because Tasks stores
 * its data as CalDAV VTODO objects.
 */
const ENDPOINT = '/ocs/v2.php/apps/pomodoro/tasks'

/**
 * Fetch outstanding tasks from the Tasks app.
 */
export async function fetchTaskItems(): Promise<FocusItem[]> {
	let tasks: Array<Record<string, unknown>>
	try {
		const response = await axios.get(ENDPOINT)
		tasks = response.data?.ocs?.data?.tasks ?? []
	} catch (error) {
		return []
	}

	return tasks.map((task) => ({
		id: `tasks:${String(task.id)}`,
		source: 'tasks' as const,
		title: String(task.title ?? '(untitled task)'),
		due: (task.due as string | null) ?? null,
		priority: (task.priority as number | null) ?? null,
		status: (task.status as string | null) ?? null,
		list: String(task.calendar ?? ''),
	}))
}
