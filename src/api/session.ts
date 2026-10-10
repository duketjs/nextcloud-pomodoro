/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import type { FocusItem } from './types'

const BASE = '/ocs/v2.php/apps/pomodoro'

export type Phase = 'focus' | 'break' | 'done'

/** Timer defaults the user can change. The cycle target is per item, not here. */
export interface TimerSettings {
	focusMinutes: number
	breakMinutes: number
	/** When true the timer moves to the next phase by itself. Manual by default. */
	autoTransition: boolean
}

/** Per-item configuration and tally, keyed by FocusItem.id. */
export interface TaskConfig {
	cyclesPerTask: number
	completed: number
	skipped: number
}

export type TaskConfigMap = Record<string, TaskConfig>

export interface PomodoroSession {
	task: {
		id: string
		source: string
		title: string
		list: string
	}
	focusMinutes: number
	breakMinutes: number
	cyclesPerTask: number
	cyclesCompleted: number
	phase: Phase
	phaseStartedAt: string
	/** null once the session is done */
	phaseEndsAt: string | null
	running: boolean
	/** remaining ms while paused, else null */
	pausedRemainingMs: number | null
}

export interface AppState {
	session: PomodoroSession | null
	settings: TimerSettings
	tasks: TaskConfigMap
}

export const DEFAULT_CYCLES_PER_TASK = 1

export const DEFAULT_SETTINGS: TimerSettings = {
	focusMinutes: 25,
	breakMinutes: 5,
	autoTransition: false,
}

/**
 * Everything the app needs on load: running session, settings and per-item config.
 */
export async function fetchState(): Promise<AppState> {
	const response = await axios.get(`${BASE}/state`)

	return {
		session: response.data?.ocs?.data?.session ?? null,
		settings: response.data?.ocs?.data?.settings ?? DEFAULT_SETTINGS,
		tasks: response.data?.ocs?.data?.tasks ?? {},
	}
}

/**
 * Persist the timer preferences.
 */
export async function saveSettings(settings: TimerSettings): Promise<TimerSettings> {
	const response = await axios.post(`${BASE}/settings`, {
		focusMinutes: settings.focusMinutes,
		breakMinutes: settings.breakMinutes,
		autoTransition: settings.autoTransition,
	})

	return response.data?.ocs?.data?.settings ?? settings
}

/**
 * Start a session for the given item, replacing any session in progress.
 *
 * The cycle target is not sent: the server reads it from the item's own config.
 */
export async function startSession(item: FocusItem, settings: TimerSettings): Promise<PomodoroSession> {
	const response = await axios.post(`${BASE}/session`, {
		taskId: item.id,
		taskSource: item.source,
		taskTitle: item.title,
		taskList: item.list,
		focusMinutes: settings.focusMinutes,
		breakMinutes: settings.breakMinutes,
	})

	return response.data.ocs.data.session
}

/**
 * Set how many focus cycles a given item is expected to take.
 */
export async function setTaskCycles(taskId: string, cyclesPerTask: number): Promise<TaskConfigMap> {
	const response = await axios.post(`${BASE}/task-config`, { taskId, cyclesPerTask })

	return response.data?.ocs?.data?.tasks ?? {}
}

/**
 * Move to the next phase. The server decides what that is, and returns the
 * updated per-item counters.
 *
 * @param skipped true when the user pressed Skip rather than letting the phase
 *                run out. A skipped focus phase counts as skipped, not completed.
 */
export async function advancePhase(skipped = false): Promise<{ session: PomodoroSession | null, tasks: TaskConfigMap }> {
	const response = await axios.put(`${BASE}/session/phase`, { skipped })

	return {
		session: response.data?.ocs?.data?.session ?? null,
		tasks: response.data?.ocs?.data?.tasks ?? {},
	}
}

/**
 * Finish the current focus cycle now, ahead of its cycle target. The server
 * counts the cycle as completed and moves the session to its finished state.
 */
export async function completeSessionEarly(): Promise<{ session: PomodoroSession | null, tasks: TaskConfigMap }> {
	const response = await axios.put(`${BASE}/session/complete`)

	return {
		session: response.data?.ocs?.data?.session ?? null,
		tasks: response.data?.ocs?.data?.tasks ?? {},
	}
}

export async function setPaused(paused: boolean): Promise<PomodoroSession | null> {
	const response = await axios.put(`${BASE}/session/pause`, { paused })

	return response.data?.ocs?.data?.session ?? null
}

export async function clearSession(): Promise<void> {
	await axios.delete(`${BASE}/session`)
}

/**
 * Tell the server this phase has already been announced, so its background job
 * does not raise a second notification for the same phase.
 */
export async function markPhaseAnnounced(): Promise<void> {
	await axios.post(`${BASE}/session/announced`)
}

/**
 * Remaining milliseconds in the current phase, computed from the server's
 * deadline so a reload always shows the correct time.
 */
export function remainingMs(session: PomodoroSession, now: number): number {
	if (!session.running) {
		return session.pausedRemainingMs ?? 0
	}
	if (session.phaseEndsAt === null) {
		return 0
	}

	return Math.max(0, new Date(session.phaseEndsAt).getTime() - now)
}

export function formatDuration(ms: number): string {
	const totalSeconds = Math.max(0, Math.ceil(ms / 1000))
	const minutes = Math.floor(totalSeconds / 60)
	const seconds = totalSeconds % 60

	return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}
