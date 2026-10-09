/**
 * SPDX-FileCopyrightText: 2026 Jokersmile
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import type { FocusItem } from './types'

const BASE = '/ocs/v2.php/apps/pomodoro'

export type Phase = 'focus' | 'break' | 'done'

export interface TimerSettings {
	focusMinutes: number
	breakMinutes: number
	cyclesPerTask: number
}

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

export interface SessionState {
	session: PomodoroSession | null
	settings: TimerSettings
}

/**
 * The running session plus the user's saved preferences.
 */
export async function fetchSession(): Promise<SessionState> {
	const response = await axios.get(`${BASE}/session`)

	return {
		session: response.data?.ocs?.data?.session ?? null,
		settings: response.data?.ocs?.data?.settings ?? { focusMinutes: 25, breakMinutes: 5, cyclesPerTask: 4 },
	}
}

/**
 * Start a session for the given item, replacing any session in progress.
 */
export async function startSession(item: FocusItem, settings: TimerSettings): Promise<PomodoroSession> {
	const response = await axios.post(`${BASE}/session`, {
		taskId: item.id,
		taskSource: item.source,
		taskTitle: item.title,
		taskList: item.list,
		focusMinutes: settings.focusMinutes,
		breakMinutes: settings.breakMinutes,
		cyclesPerTask: settings.cyclesPerTask,
	})

	return response.data.ocs.data.session
}

/**
 * Move to the next phase. The server decides what that is.
 */
export async function advancePhase(): Promise<PomodoroSession | null> {
	const response = await axios.put(`${BASE}/session/phase`)

	return response.data?.ocs?.data?.session ?? null
}

export async function setPaused(paused: boolean): Promise<PomodoroSession | null> {
	const response = await axios.put(`${BASE}/session/pause`, { paused })

	return response.data?.ocs?.data?.session ?? null
}

export async function clearSession(): Promise<void> {
	await axios.delete(`${BASE}/session`)
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
