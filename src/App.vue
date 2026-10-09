<!--
  SPDX-FileCopyrightText: 2026 Jokersmile
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcButton from '@nextcloud/vue/components/NcButton'
import { fetchFocusItems } from './api/items'
import type { FocusItem } from './api/types'

const items = ref<FocusItem[]>([])
const loading = ref(true)
const failed = ref(false)
const selected = ref<FocusItem | null>(null)

const tasks = computed(() => items.value.filter((i) => i.source === 'tasks'))
const deck = computed(() => items.value.filter((i) => i.source === 'deck'))

async function load(): Promise<void> {
	loading.value = true
	failed.value = false
	try {
		items.value = await fetchFocusItems()
	} catch (error) {
		failed.value = true
	} finally {
		loading.value = false
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

onMounted(load)
</script>

<template>
	<NcContent app-name="pomodoro">
		<NcAppContent>
			<div class="pomodoro">
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

				<NcLoadingIcon v-if="loading" :size="44" class="pomodoro__loading" />

				<NcEmptyContent
					v-else-if="failed"
					name="Could not load your tasks">
					<template #description>
						Something went wrong talking to the server.
					</template>
				</NcEmptyContent>

				<NcEmptyContent
					v-else-if="items.length === 0"
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

				<div v-if="selected" class="pomodoro__selected">
					<strong>Focusing on:</strong> {{ selected.title }}
					<em v-if="selected.list">({{ selected.list }})</em>
				</div>
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

.pomodoro__selected {
	margin-top: 8px;
	padding: 10px 14px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-primary-element-light);
}
</style>
