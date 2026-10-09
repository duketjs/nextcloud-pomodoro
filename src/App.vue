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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { fetchDeckBoards, markCardDone } from './api/deck'
import type { DeckBoard } from './api/deck'
import { fetchFocusItems } from './api/items'
import { ensureNotificationPermission, notificationsSupported, notify } from './api/notify'
import type { FocusItem } from './api/types'
import {
	advancePhase,
	clearSession,
	DEFAULT_CYCLES_PER_TASK,
	DEFAULT_SETTINGS,
	fetchState,
	formatDuration,
	markPhaseAnnounced,
	remainingMs,
	saveSettings,
	setPaused,
	setTaskCycles,
	startSession,
} from './api/session'
import type { PomodoroSession, TaskConfig, TaskConfigMap, TimerSettings } from './api/session'

const items = ref<FocusItem[]>([])
const boards = ref<DeckBoard[]>([])
const selectedBoard = ref<DeckBoard | null>(null)
const loading = ref(true)
const failed = ref(false)
const selected = ref<FocusItem | null>(null)
// Set when an action on Deck failed, so the user is not left guessing.
const actionError = ref<string | null>(null)

const session = ref<PomodoroSession | null>(null)
const settings = ref<TimerSettings>({ ...DEFAULT_SETTINGS })
// Per-item config and counters, keyed by item id.
const taskConfig = ref<TaskConfigMap>({})
const busy = ref(false)

const notificationsAvailable = ref(notificationsSupported())
// The phase we have already announced, so each phase pops up exactly once.
let announcedPhase: string | null = null

/**
 * Pop up a notification for the phase whose time has just run out.
 */
function announcePhaseEnd(): void {
	const current = session.value
	if (!current) {
		return
	}
	const task = current.task.title

	let shown: boolean
	if (current.phase === 'break') {
		shown = notify('Rest over', `Back to focus on "${task}".`)
	} else if (finalFocus.value) {
		// A focus phase is over: either a rest is due, or the session is finished.
		shown = notify('All cycles done', `"${task}" — ${current.cyclesPerTask} focus cycles completed.`)
	} else {
		shown = notify('Focus finished', `Time for a ${current.breakMinutes} minute rest.`)
	}

	// Tell the server when we showed a pop-up ourselves, so its background job
	// does not raise a second notification for the same phase. When we could not
	// show one (no permission, insecure connection), leave it to the server.
	if (shown) {
		void markPhaseAnnounced().catch(() => undefined)
	}
}

/**
 * Hand the focused card back to Deck as done: Deck stamps the completion date
 * and, when the board has a done column, moves the card into it. The session is
 * over either way, so it is cleared and the list reloaded.
 */
async function markDone(): Promise<void> {
	if (!session.value) {
		return
	}
	const cardId = Number(session.value.task.id.replace(/^deck:/, ''))
	if (!Number.isFinite(cardId)) {
		actionError.value = 'This item is not a Deck card.'
		return
	}

	busy.value = true
	actionError.value = null
	try {
		await markCardDone(cardId)
		await clearSession()
		session.value = null
		selected.value = null
		await refreshCounters()
		await loadItems()
	} catch (error) {
		actionError.value = 'Could not mark the card as done in Deck.'
	} finally {
		busy.value = false
	}
}

// Ticks once a second so the countdown re-renders. The remaining time is always
// derived from the server's deadline, never decremented locally, so a reload
// mid-phase shows the correct value.
const now = ref(Date.now())
let ticker: number | undefined

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
// cycle under way is the next one. During a rest it shows the cycle just
// worked, and never drops below 1 (a rest after a skipped first cycle would
// otherwise read "Cycle 0").
const cycleNumber = computed(() => {
	if (!session.value) {
		return 0
	}
	const s = session.value
	const current = s.phase === 'focus' ? s.cyclesCompleted + 1 : s.cyclesCompleted

	return Math.min(Math.max(1, current), s.cyclesPerTask)
})
// The last focus cycle of a session: once it runs out the session is over, so
// there is no next phase to offer - the timer goes straight to Complete.
const finalFocus = computed(() => {
	const s = session.value

	return !!s && s.phase === 'focus' && s.cyclesCompleted + 1 >= s.cyclesPerTask
})
// A phase whose time is up, waiting for the user because auto-transition is off.
const waiting = computed(() => {
	const s = session.value

	return !settings.value.autoTransition
		&& !!s
		&& s.phase !== 'done'
		&& s.running
		&& left.value <= 0
		&& !finalFocus.value
})
const waitingMessage = computed(() => (session.value?.phase === 'focus' ? 'Focus finished' : 'Rest over'))
const waitingAction = computed(() => (session.value?.phase === 'focus' ? 'Start rest' : 'Start focus'))
// Which colour the timer wears: focus red, rest green, an ended phase waiting on
// the user blue, paused white, and a finished session on the theme's success.
const timerState = computed(() => {
	const s = session.value
	if (!s) {
		return ''
	}
	if (s.phase === 'done') {
		return 'done'
	}
	if (waiting.value) {
		return 'waiting'
	}
	if (!s.running) {
		return 'paused'
	}

	return s.phase === 'focus' ? 'focus' : 'rest'
})

/**
 * Config for an item, with defaults for anything the user has not set yet.
 */
function cfg(id: string): TaskConfig {
	return taskConfig.value[id] ?? { cyclesPerTask: DEFAULT_CYCLES_PER_TASK, completed: 0, skipped: 0 }
}

/**
 * Load the outstanding cards of the currently selected board.
 */
async function loadItems(): Promise<void> {
	items.value = selectedBoard.value ? await fetchFocusItems(selectedBoard.value.id) : []
}

async function load(): Promise<void> {
	loading.value = true
	failed.value = false
	try {
		const state = await fetchState()
		settings.value = state.settings
		session.value = state.session
		taskConfig.value = state.tasks
		// A phase that is already over must not fire a pop-up just because the
		// page was (re)loaded.
		announcedPhase = state.session && remainingMs(state.session, Date.now()) <= 0
			? state.session.phaseStartedAt
			: null
		notificationsAvailable.value = notificationsSupported()

		boards.value = await fetchDeckBoards()
		// Keep the chosen board across a refresh; fall back to the first one.
		const stillThere = boards.value.find((board) => board.id === selectedBoard.value?.id)
		selectedBoard.value = stillThere ?? boards.value[0] ?? null
		await loadItems()
	} catch (error) {
		failed.value = true
	} finally {
		loading.value = false
	}
}

/**
 * Switching boards changes which cards are listed. Any previously selected item
 * belongs to the old board, so drop it.
 */
async function onBoardChange(board: DeckBoard | null): Promise<void> {
	selectedBoard.value = board ?? null
	selected.value = null
	await loadItems()
}

async function refreshCounters(): Promise<void> {
	const state = await fetchState()
	taskConfig.value = state.tasks
	session.value = state.session
}

async function start(): Promise<void> {
	if (!selected.value) {
		return
	}
	busy.value = true
	try {
		// Asked here rather than on load: browsers only allow the permission
		// prompt to follow a user gesture.
		const permitted = await ensureNotificationPermission()
		session.value = await startSession(selected.value, settings.value)
		announcedPhase = null

		if (!notificationsAvailable.value) {
			actionError.value = 'Pop-up alerts need an HTTPS connection - this page is not served securely.'
		} else if (!permitted) {
			actionError.value = 'Pop-up alerts are blocked in your browser settings.'
		} else {
			actionError.value = null
		}
	} finally {
		busy.value = false
	}
}

/**
 * Persist the timer preferences (focus/rest minutes and auto-transition).
 *
 * The response is deliberately not written back into `settings`: two quick
 * edits would otherwise race, each response overwriting the other's value.
 * The inputs already constrain the ranges to what the server accepts.
 */
async function onSettingsChange(): Promise<void> {
	busy.value = true
	try {
		await saveSettings(settings.value)
	} finally {
		busy.value = false
	}
}

async function onAutoTransitionChange(event: Event): Promise<void> {
	settings.value.autoTransition = (event.target as HTMLInputElement).checked
	await onSettingsChange()
}

/**
 * Persist how many cycles an item is expected to take.
 */
async function onItemCyclesChange(item: FocusItem, event: Event): Promise<void> {
	const value = Number((event.target as HTMLInputElement).value)
	busy.value = true
	try {
		taskConfig.value = await setTaskCycles(item.id, value)
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

/**
 * Move on without finishing the phase: recorded as a skipped cycle.
 */
async function skip(): Promise<void> {
	busy.value = true
	try {
		const result = await advancePhase(true)
		session.value = result.session
		taskConfig.value = result.tasks
	} finally {
		busy.value = false
	}
}

/**
 * Start the next phase by hand. The phase that just ended ran its full course,
 * so it counts as completed rather than skipped.
 */
async function advanceNow(): Promise<void> {
	busy.value = true
	try {
		const result = await advancePhase(false)
		session.value = result.session
		taskConfig.value = result.tasks
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
		await refreshCounters()
	} finally {
		busy.value = false
	}
}

async function tick(): Promise<void> {
	now.value = Date.now()
	if (!session.value || busy.value) {
		return
	}
	const s = session.value
	if (s.phase === 'done' || !s.running || left.value > 0) {
		return
	}

	// The phase's time is up. Tell the user once - whether we then move on by
	// ourselves or wait for them to start the next phase.
	if (announcedPhase !== s.phaseStartedAt) {
		announcedPhase = s.phaseStartedAt
		announcePhaseEnd()
	}

	// Move on by ourselves when auto-transition is on. Also when this was the
	// session's final focus cycle: the session is over, so there is nothing to
	// ask the user about - it goes straight to Complete.
	if (!settings.value.autoTransition && !finalFocus.value) {
		return
	}
	busy.value = true
	try {
		const result = await advancePhase(false)
		session.value = result.session
		taskConfig.value = result.tasks
	} finally {
		busy.value = false
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

				<div v-else class="pomodoro__columns">
					<!-- Left: everything outstanding -->
					<section class="pomodoro__col">
						<header class="pomodoro__header">
							<h1>Focus</h1>
							<p class="pomodoro__subtitle">
								Pick something outstanding to work on.
							</p>
						</header>

						<div class="toolbar">
							<NcButton
								variant="secondary"
								:disabled="loading"
								@click="load">
								Refresh
							</NcButton>
							<NcButton
								variant="primary"
								:disabled="!selected || busy"
								@click="start">
								Start focus
							</NcButton>
						</div>

						<fieldset class="settings">
							<legend>Pomodoro settings</legend>
							<label class="settings__field">
								Focus (min)
								<input
									v-model.number="settings.focusMinutes"
									type="number"
									min="1"
									max="180"
									:disabled="busy"
									@change="onSettingsChange">
							</label>
							<label class="settings__field">
								Rest (min)
								<input
									v-model.number="settings.breakMinutes"
									type="number"
									min="1"
									max="60"
									:disabled="busy"
									@change="onSettingsChange">
							</label>
							<label class="settings__field settings__field--check">
								<input
									type="checkbox"
									:checked="settings.autoTransition"
									:disabled="busy"
									@change="onAutoTransitionChange">
								Auto-transition
							</label>
						</fieldset>

						<div v-if="boards.length" class="board-picker">
							<span class="board-picker__label">Deck board</span>
							<NcSelect
								class="board-picker__select"
								:model-value="selectedBoard"
								:options="boards"
								label="title"
								:clearable="false"
								:searchable="false"
								placeholder="Select a board"
								@update:model-value="onBoardChange" />
						</div>

						<!-- Long lists scroll here rather than pushing the page down -->
						<div class="pomodoro__scroll">
							<NcEmptyContent
								v-if="boards.length === 0"
								name="No Deck boards">
								<template #description>
									There are no Deck boards available to you.
								</template>
							</NcEmptyContent>

							<NcEmptyContent
								v-else-if="items.length === 0"
								name="Nothing outstanding">
								<template #description>
									This board has no open cards.
								</template>
							</NcEmptyContent>

							<ul v-else class="pomodoro__list">
								<li
									v-for="item in items"
									:key="item.id"
									class="pomodoro__item"
									:class="{ 'pomodoro__item--selected': selected?.id === item.id }"
									@click="selected = item">
									<span class="pomodoro__item-main">
										<span class="pomodoro__title">{{ item.title }}</span>
										<span class="pomodoro__meta">
											<span v-if="item.list" class="pomodoro__list-name">{{ item.list }}</span>
											<span v-if="item.due" class="pomodoro__due">due {{ formatDue(item.due) }}</span>
											<span v-if="cfg(item.id).completed" class="pomodoro__completed">{{ cfg(item.id).completed }} completed</span>
											<span v-if="cfg(item.id).skipped" class="pomodoro__skipped">{{ cfg(item.id).skipped }} skipped</span>
										</span>
									</span>
									<!-- Editing the count must not double as selecting the row -->
									<label
										class="pomodoro__cycles"
										title="Cycles for this task"
										@click.stop>
										Cycles
										<input
											:value="cfg(item.id).cyclesPerTask"
											type="number"
											min="1"
											max="24"
											:disabled="busy"
											@click.stop
											@change="onItemCyclesChange(item, $event)">
									</label>
								</li>
							</ul>
						</div>
					</section>

					<!-- Right: the timer, once something has been started -->
					<section class="pomodoro__col pomodoro__col--timer">
						<header v-if="!session" class="pomodoro__header">
							<h1>Timer</h1>
						</header>

						<template v-if="session">
							<header class="pomodoro__header">
								<h1>{{ session.task.title }}</h1>
								<p v-if="session.task.list" class="pomodoro__subtitle">
									{{ session.task.list }}
								</p>
							</header>

							<p class="timer-scope">
								<template v-if="session.phase === 'done'">
									{{ session.cyclesCompleted }} of {{ session.cyclesPerTask }} focus cycles done
								</template>
								<template v-else>
									Cycle {{ cycleNumber }} of {{ session.cyclesPerTask }}
								</template>
							</p>

							<div class="timer" :class="`timer--${timerState}`">
								<span class="timer__phase">{{ phaseLabel }}</span>
								<span class="timer__clock">{{ clock }}</span>
								<span v-if="waiting" class="timer__waiting">{{ waitingMessage }}</span>
								<span v-else-if="!session.running && session.phase !== 'done'" class="timer__paused">
									Paused
								</span>
							</div>

							<div class="controls">
								<!-- Phase over, manual mode: the user starts the next one -->
								<template v-if="waiting">
									<NcButton
										variant="primary"
										:disabled="busy"
										@click="advanceNow">
										{{ waitingAction }}
									</NcButton>
								</template>
								<!-- Session finished: offer to close the card out in Deck -->
								<template v-else-if="session.phase === 'done'">
									<NcButton
										variant="primary"
										:disabled="busy"
										@click="markDone">
										Mark as Done
									</NcButton>
								</template>
								<template v-else>
									<NcButton
										variant="primary"
										:disabled="busy"
										@click="togglePause">
										{{ session.running ? 'Pause' : 'Resume' }}
									</NcButton>
									<NcButton
										variant="secondary"
										:disabled="busy"
										@click="skip">
										{{ session.phase === 'focus' ? 'Skip to rest' : 'Skip to focus' }}
									</NcButton>
								</template>
								<NcButton
									variant="secondary"
									:disabled="busy"
									@click="stop">
									{{ session.phase === 'done' ? 'Pick another task' : 'Stop' }}
								</NcButton>
							</div>

							<p v-if="actionError" class="timer-error">
								{{ actionError }}
							</p>
						</template>

						<p v-else class="timer-placeholder">
							Select a task and press Start focus.
						</p>
					</section>
				</div>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.pomodoro {
	padding: 20px;
}

/* Two halves: outstanding work on the left, the timer on the right. */
.pomodoro__columns {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 24px;
	align-items: start;
}

@media (max-width: 900px) {
	.pomodoro__columns {
		grid-template-columns: minmax(0, 1fr);
	}
}

.pomodoro__col {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 0;
}

.pomodoro__col--timer {
	position: sticky;
	top: 12px;
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
	overflow-wrap: anywhere;
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
	align-items: flex-end;
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

.settings__field input[type="number"] {
	width: 72px;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.settings__field--check {
	flex-direction: row;
	align-items: center;
	gap: 8px;
	padding-bottom: 8px;
}

/* Refresh / Start focus, kept together under the title. */
.toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

/* Board picker sitting directly above the item list. */
.board-picker {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;
}

.board-picker__label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.board-picker__select {
	flex: 1 1 200px;
	max-width: 320px;
}

/* The list scrolls on its own so a long backlog cannot push the page down. */
.pomodoro__scroll {
	display: flex;
	flex-direction: column;
	gap: 12px;
	overflow-y: auto;
	max-height: 60vh;
	padding-right: 4px;
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
	flex-direction: row;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 14px;
	cursor: pointer;
	border-bottom: 1px solid var(--color-border);
}

.pomodoro__item-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

/* Per-item cycle count, editable straight from the row. */
.pomodoro__cycles {
	display: flex;
	align-items: center;
	gap: 6px;
	flex: 0 0 auto;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.pomodoro__cycles input {
	width: 56px;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
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
	flex-wrap: wrap;
	gap: 10px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.pomodoro__due {
	color: var(--color-warning);
}

/* Explicit colours so they stay bright regardless of the theme's success/
   warning palette (which is muted, especially on dark themes). */
.pomodoro__completed {
	color: #00c853;
	font-weight: 600;
}

.pomodoro__skipped {
	color: #ff9100;
	font-weight: 600;
}

/* Cycle progress sits above the timer, bold, so it is easy to spot. */
.timer-scope {
	font-size: 15px;
	font-weight: bold;
	margin: 0 0 4px;
}

.timer {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 20px;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
}

/* Explicit state colours, independent of the theme: focus is red with white
   text, the remaining states are light with black text. */
.timer--focus {
	background-color: #e53935;
	color: #ffffff;
}

.timer--rest {
	background-color: #00e676;
	color: #000000;
}

.timer--waiting {
	background-color: #4da3ff;
	color: #000000;
}

.timer--paused {
	background-color: #ffffff;
	color: #000000;
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
	font-size: 72px;
	font-weight: bold;
	font-variant-numeric: tabular-nums;
	line-height: 1;
}

/* Inherit the state colour set on .timer so the text stays legible on every
   background. */
.timer__waiting,
.timer__paused {
	font-size: 14px;
	font-weight: bold;
}

.timer-placeholder {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin: 24px 0 0;
}

.controls {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 14px;
}

.timer-error {
	color: var(--color-error);
	font-size: 13px;
	margin: 0;
}
</style>
