<!--
  SPDX-FileCopyrightText: 2026 Jokersmile
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcButton from '@nextcloud/vue/components/NcButton'
import { fetchFocusItems } from './api/items'
import type { FocusItem } from './api/types'
import {
	advancePhase,
	clearSession,
	fetchSession,
	formatDuration,
	remainingMs,
	setPaused,
	startSession,
} from './api/session'
import type { PomodoroSession, TimerSettings } from './api/session'

const items = ref<FocusItem[]>([])
const loading = ref(true)
const failed = ref(false)
const selected = ref<FocusItem | null>(null)

const session = ref<PomodoroSession | null>(null)
const settings = ref<TimerSettings>({ focusMinutes: 25, breakMinutes: 5, cyclesPerTask: 4 })
const busy = ref(false)

// Ticks once a second so the countdown re-renders. The remaining time is always
// derived from the server's deadline, never decremented locally, so a reload
// mid-phase shows the correct value.
const now = ref(Date.now())
let ticker: number | undefined

const tasks = computed(() => items.value.filter((i) => i.source === 'tasks'))
const deck = computed(() => items.value.filter((i) => i.source === 'deck'))

const left = computed(() => (session.value ? remainingMs(session.value, now.value) : 0))
const clock = computed(() => formatDuration(left.value))
const phaseLabel = computed(() => {
	switch (session.value?.phase) {
		case 'focus': return 'Focus'
		case 'break': return 'Rest'
		case 'done': return 'Complete'
		default: return ''
	}
})
// cyclesCompleted counts *finished* focus phases, so during a focus phase the
// cycle under way is the next one.
const cycleNumber = computed(() => {
	if (!session.value) {
		return 0
	}

	return Math.min(
		session.value.cyclesCompleted + (session.value.phase === 'focus' ? 1 : 0),
		session.value.cyclesPerTask,
	)
})

async function load(): Promise<void> {
	loading.value = true
	failed.value = false
	try {
		const state = await fetchSession()
		settings.value = state.settings
		session.value = state.session
		items.value = await fetchFocusItems()
	} catch (error) {
		failed.value = true
	} finally {
		loading.value = false
	}
}

async function start(): Promise<void> {
	if (!selected.value) {
		return
	}
	busy.value = true
	try {
		session.value = await startSession(selected.value, settings.value)
	} finally {
		busy.value = false
	}
}

async function togglePause(): Promise<void> {
	if (!session.value) {
		return
	}
	busy.value = true
	try {
		// Running -> pause it; paused -> resume it.
		session.value = await setPaused(session.value.running)
	} finally {
		busy.value = false
	}
}

async function skip(): Promise<void> {
	busy.value = true
	try {
		session.value = await advancePhase()
	} finally {
		busy.value = false
	}
}

async function stop(): Promise<void> {
	busy.value = true
	try {
		await clearSession()
		session.value = null
		selected.value = null
	} finally {
		busy.value = false
	}
}

async function tick(): Promise<void> {
	now.value = Date.now()
	// Let the server move the phase on. `busy` guards against a slow request
	// firing the same transition twice.
	if (session.value?.running && session.value.phase !== 'done' && left.value <= 0 && !busy.value) {
		busy.value = true
		try {
			session.value = await advancePhase()
		} finally {
			busy.value = false
		}
	}
}

function formatDue(due: string | null): string {
	if (!due) {
		return ''
	}
	const date = new Date(due)
	if (Number.isNaN(date.getTime())) {
		return ''
	}

	return date.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

onMounted(async () => {
	await load()
	ticker = window.setInterval(tick, 1000)
})

onUnmounted(() => {
	if (ticker !== undefined) {
		window.clearInterval(ticker)
	}
})
</script>

<template>
	<NcContent app-name="pomodoro">
		<NcAppContent>
			<div class="pomodoro">
				<NcLoadingIcon v-if="loading" :size="44" class="pomodoro__loading" />

				<NcEmptyContent
					v-else-if="failed"
					name="Could not load your tasks">
					<template #description>
						Something went wrong talking to the server.
					</template>
				</NcEmptyContent>

				<!-- A session is running (or the task is finished) -->
				<template v-else-if="session">
					<header class="pomodoro__header">
						<h1>{{ session.task.title }}</h1>
						<p v-if="session.task.list" class="pomodoro__subtitle">
							{{ session.task.list }}
						</p>
					</header>

					<div class="timer" :class="`timer--${session.phase}`">
						<span class="timer__phase">{{ phaseLabel }}</span>
						<span class="timer__clock">{{ clock }}</span>
						<span class="timer__cycle">
							<template v-if="session.phase === 'done'">
								{{ session.cyclesCompleted }} of {{ session.cyclesPerTask }} focus cycles done
							</template>
							<template v-else>
								Cycle {{ cycleNumber }} of {{ session.cyclesPerTask }}
							</template>
						</span>
						<span v-if="!session.running && session.phase !== 'done'" class="timer__paused">
							Paused
						</span>
					</div>

					<div class="controls">
						<NcButton
							v-if="session.phase !== 'done'"
							variant="primary"
							:disabled="busy"
							@click="togglePause">
							{{ session.running ? 'Pause' : 'Resume' }}
						</NcButton>
						<NcButton
							v-if="session.phase !== 'done'"
							variant="secondary"
							:disabled="busy"
							@click="skip">
							{{ session.phase === 'focus' ? 'Skip to rest' : 'Skip to focus' }}
						</NcButton>
						<NcButton
							variant="secondary"
							:disabled="busy"
							@click="stop">
							{{ session.phase === 'done' ? 'Pick another task' : 'Stop' }}
						</NcButton>
					</div>
				</template>

				<!-- No session: pick a task and configure the timer -->
				<template v-else>
					<header class="pomodoro__header">
						<h1>Focus</h1>
						<p class="pomodoro__subtitle">
							Pick something outstanding to work on.
						</p>
						<NcButton
							variant="secondary"
							:disabled="loading"
							@click="load">
							Refresh
						</NcButton>
					</header>

					<fieldset class="settings">
						<legend>Pomodoro settings</legend>
						<label class="settings__field">
							Focus (min)
							<input v-model.number="settings.focusMinutes" type="number" min="1" max="180">
						</label>
						<label class="settings__field">
							Rest (min)
							<input v-model.number="settings.breakMinutes" type="number" min="1" max="60">
						</label>
						<label class="settings__field">
							Cycles per task
							<input v-model.number="settings.cyclesPerTask" type="number" min="1" max="24">
						</label>
					</fieldset>

					<NcEmptyContent
						v-if="items.length === 0"
						name="Nothing outstanding">
						<template #description>
							No open Deck cards or Tasks were found.
						</template>
					</NcEmptyContent>

					<template v-else>
						<section
							v-if="tasks.length"
							class="pomodoro__group">
							<h2>Tasks</h2>
							<ul class="pomodoro__list">
								<li
									v-for="item in tasks"
									:key="item.id"
									class="pomodoro__item"
									:class="{ 'pomodoro__item--selected': selected?.id === item.id }"
									@click="selected = item">
									<span class="pomodoro__title">{{ item.title }}</span>
									<span class="pomodoro__meta">
										<span v-if="item.list" class="pomodoro__list-name">{{ item.list }}</span>
										<span v-if="item.due" class="pomodoro__due">due {{ formatDue(item.due) }}</span>
									</span>
								</li>
							</ul>
						</section>

						<section
							v-if="deck.length"
							class="pomodoro__group">
							<h2>Deck</h2>
							<ul class="pomodoro__list">
								<li
									v-for="item in deck"
									:key="item.id"
									class="pomodoro__item"
									:class="{ 'pomodoro__item--selected': selected?.id === item.id }"
									@click="selected = item">
									<span class="pomodoro__title">{{ item.title }}</span>
									<span class="pomodoro__meta">
										<span v-if="item.list" class="pomodoro__list-name">{{ item.list }}</span>
										<span v-if="item.due" class="pomodoro__due">due {{ formatDue(item.due) }}</span>
									</span>
								</li>
							</ul>
						</section>
					</template>

					<div class="controls">
						<NcButton
							variant="primary"
							:disabled="!selected || busy"
							@click="start">
							Start focus
						</NcButton>
						<span v-if="selected" class="controls__hint">
							Selected: {{ selected.title }}
						</span>
					</div>
				</template>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.pomodoro {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
	max-width: 720px;
}

.pomodoro__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}

.pomodoro__header h1 {
	font-size: 22px;
	font-weight: bold;
	margin: 0;
}

.pomodoro__subtitle {
	flex: 1 1 100%;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.pomodoro__loading {
	align-self: center;
	margin-top: 40px;
}

.settings {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 14px;
}

.settings legend {
	font-weight: bold;
	padding: 0 6px;
}

.settings__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.settings__field input {
	width: 72px;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.pomodoro__group h2 {
	font-size: 15px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
	margin: 12px 0 4px;
}

.pomodoro__list {
	list-style: none;
	margin: 0;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.pomodoro__item {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 10px 14px;
	cursor: pointer;
	border-bottom: 1px solid var(--color-border);
}

.pomodoro__item:last-child {
	border-bottom: none;
}

.pomodoro__item:hover {
	background-color: var(--color-background-hover);
}

.pomodoro__item--selected {
	background-color: var(--color-primary-element-light);
}

.pomodoro__meta {
	display: flex;
	gap: 10px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.pomodoro__due {
	color: var(--color-warning);
}

.timer {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
	padding: 28px 20px;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
	background-color: var(--color-background-dark);
}

.timer--break {
	background-color: var(--color-primary-element-light);
}

.timer--done {
	background-color: var(--color-success);
}

.timer__phase {
	text-transform: uppercase;
	letter-spacing: 0.08em;
	font-size: 13px;
}

.timer__clock {
	font-size: 64px;
	font-weight: bold;
	font-variant-numeric: tabular-nums;
	line-height: 1;
}

.timer__cycle {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.timer__paused {
	font-size: 13px;
	font-weight: bold;
	color: var(--color-warning);
}

.controls {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;
}

.controls__hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
