<template>
	<div class="fr-upcoming">
		<p class="fr-upcoming__intro">
			{{ t('folder_retention', 'Files the daily run will delete under the current rules, by due day and folder. Calculated live – a rule change shows up here at once.') }}
		</p>
		<NcNoteCard v-if="state.settings.simulation" type="warning">
			{{ t('folder_retention', 'Simulation mode active: nothing is actually deleted; the log records these files as “would delete”.') }}
		</NcNoteCard>
		<form class="fr-upcoming__filters" @submit.prevent="load">
			<input v-model="search"
				class="fr-upcoming__search"
				type="search"
				:aria-label="t('folder_retention', 'File contains')"
				:placeholder="t('folder_retention', 'Search files, e.g. Reports or .pdf')">
			<select v-model.number="days" :aria-label="t('folder_retention', 'Time span')" @change="load">
				<option :value="0">
					{{ t('folder_retention', 'Already due') }}
				</option>
				<option :value="7">
					{{ t('folder_retention', 'Next 7 days') }}
				</option>
				<option :value="30">
					{{ t('folder_retention', 'Next 30 days') }}
				</option>
				<option :value="90">
					{{ t('folder_retention', 'Next 90 days') }}
				</option>
				<option :value="365">
					{{ t('folder_retention', 'Next 365 days') }}
				</option>
			</select>
			<NcButton variant="tertiary"
				:aria-label="t('folder_retention', 'Refresh')"
				:title="t('folder_retention', 'Refresh')"
				:disabled="loading"
				@click="load">
				<template #icon>
					<NcIconSvgWrapper :path="mdiRefresh" />
				</template>
			</NcButton>
		</form>

		<p v-if="loading" class="fr-muted" role="status">
			{{ t('folder_retention', 'Loading …') }}
		</p>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else-if="result">
			<p class="fr-upcoming__summary" role="status">
				{{ summary }}
				<template v-if="result.truncated">
					{{ t('folder_retention', 'Showing the {count} earliest.', { count: result.items.length }) }}
				</template>
			</p>
			<NcNoteCard v-if="result.incomplete" type="warning">
				{{ t('folder_retention', 'The preview was aborted for time reasons and is incomplete.') }}
			</NcNoteCard>

			<div v-if="groups.length" class="fr-upcoming__days">
				<details v-for="(day, i) in groups"
					:key="day.key"
					class="fr-day"
					:open="open[day.key] ?? i === 0"
					@toggle="open[day.key] = $event.target.open">
					<summary class="fr-group fr-group--day">
						<NcIconSvgWrapper class="fr-chevron" :path="mdiChevronRight" :size="20" />
						<span class="fr-group__name">{{ day.overdue ? t('folder_retention', 'Already due – next run') : formatDay(day.key) }}</span>
						<span class="fr-chips">
							<StatusChip :tone="state.settings.simulation ? 'sim' : 'delete'" :label="filesLabel(day.total)" />
							<span class="fr-muted">{{ formatSize(day.size) }}</span>
						</span>
					</summary>
					<div class="fr-day__body">
						<details v-for="f in day.folders"
							:key="f.key"
							class="fr-folder"
							:open="open[`${day.key}\n${f.key}`] ?? day.folders.length === 1"
							@toggle="open[`${day.key}\n${f.key}`] = $event.target.open">
							<summary class="fr-group">
								<NcIconSvgWrapper class="fr-chevron" :path="mdiChevronRight" :size="20" />
								<NcIconSvgWrapper class="fr-folder__icon" :path="mdiFolderOutline" :size="20" />
								<span class="fr-group__name fr-path">{{ f.folder || t('folder_retention', 'No folder') }}</span>
								<span class="fr-chips">
									<span class="fr-muted">{{ filesLabel(f.total) }} · {{ formatSize(f.size) }}</span>
								</span>
							</summary>
							<div class="fr-files">
								<table class="fr-files__table">
									<caption class="hidden-visually">
										{{ t('folder_retention', 'Files that would be deleted') }}
									</caption>
									<colgroup>
										<col>
										<col class="fr-col--rule">
										<col class="fr-col--date">
										<col class="fr-col--due">
										<col class="fr-col--size">
									</colgroup>
									<thead>
										<tr>
											<th scope="col">
												{{ t('folder_retention', 'File') }}
											</th>
											<th scope="col">
												{{ t('folder_retention', 'Rule') }}
											</th>
											<th scope="col">
												{{ t('folder_retention', 'Reference date') }}
											</th>
											<th scope="col">
												{{ t('folder_retention', 'Deletion from') }}
											</th>
											<th scope="col" class="fr-num">
												{{ t('folder_retention', 'Size') }}
											</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="item in f.items" :key="item.fileId">
											<td class="fr-path">
												{{ baseName(item.path) }}
											</td>
											<td>
												{{ item.ruleLabel }}
												<div class="fr-muted fr-small">
													{{ t('folder_retention', 'from {source}', { source: item.ruleSource }) }}
												</div>
											</td>
											<td>
												{{ formatDate(item.referenceDate) }}
												<div class="fr-muted fr-small">
													{{ sourceLabel(item.referenceSource) }}
												</div>
											</td>
											<td>{{ item.overdue ? t('folder_retention', 'due') : formatDate(item.expiresAt) }}</td>
											<td class="fr-num">
												{{ formatSize(item.size) }}
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</details>
					</div>
				</details>
			</div>
			<p v-else class="fr-muted">
				{{ search.trim() ? t('folder_retention', 'No entries for these filters.') : t('folder_retention', 'Nothing is due in this time span.') }}
			</p>
		</template>
	</div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { mdiChevronRight, mdiFolderOutline, mdiRefresh } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import StatusChip from './StatusChip.vue'
import { fetchPreview, state } from '../store.js'
import { baseName, formatDate, formatDay, formatSize, groupUpcoming, sourceLabel } from '../format.js'
import { n, t } from '../l10n.js'

const days = ref(30)
const search = ref('')
const loading = ref(false)
const error = ref(null)
const result = ref(null)
/** expanded state per day and per day + folder; unset = default (first day, single folder) */
const open = reactive({})
/** Discard responses from before a newer request */
let generation = 0

const filesLabel = (count) => n('folder_retention', '%n file', '%n files', count)

const items = computed(() => {
	const q = search.value.trim().toLowerCase()
	const all = result.value?.items ?? []
	return q ? all.filter(i => i.path.toLowerCase().includes(q)) : all
})
const groups = computed(() => groupUpcoming(items.value))

const summary = computed(() => {
	const total = result.value?.total ?? 0
	const size = (result.value?.items ?? []).reduce((sum, i) => sum + i.size, 0)
	const text = days.value === 0
		? n('folder_retention', '%n file is already due.', '%n files are already due.', total)
		: n('folder_retention', '%n file becomes due in the next {days} days (including those already due).', '%n files become due in the next {days} days (including those already due).', total, { days: days.value })
	return total > 0 && !result.value.truncated ? `${text} ${formatSize(size)}` : text
})

async function load() {
	const gen = ++generation
	loading.value = true
	error.value = null
	try {
		const data = await fetchPreview('all', days.value)
		if (gen !== generation) return
		result.value = data
		for (const k of Object.keys(open)) delete open[k]
	} catch (e) {
		if (gen === generation) error.value = e?.response?.data?.message ?? t('folder_retention', 'Preview could not be loaded')
	} finally {
		if (gen === generation) loading.value = false
	}
}

onMounted(load)
</script>

<style scoped lang="scss">
.fr-upcoming {
	&__intro {
		margin-bottom: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__filters {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin: 8px 0;

		input,
		select {
			margin: 0;
		}
	}

	&__search {
		flex: 1 1 16em;
		max-width: 32em;
	}

	&__summary {
		margin: 8px 0;
	}
}

.fr-day {
	border-bottom: 1px solid var(--color-border);
}

.fr-day__body {
	padding: 0 0 8px 28px;
}

.fr-group {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 8px;
	padding: 6px 4px;
	border-radius: var(--border-radius-element, 8px);
	cursor: pointer;
	list-style: none;

	&::-webkit-details-marker {
		display: none;
	}

	&:hover {
		background: var(--color-background-hover);
	}

	&:focus-visible {
		outline: 2px solid var(--color-main-text);
		outline-offset: -2px;
	}

	&--day .fr-group__name {
		font-weight: bold;
	}
}

.fr-chevron {
	transition: transform 0.1s ease;
}

details[open] > summary .fr-chevron {
	transform: rotate(90deg);
}

.fr-folder__icon {
	color: var(--color-text-maxcontrast);
}

.fr-chips {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 8px;
}

.fr-files {
	padding: 0 0 8px 28px;
	overflow-x: auto;

	&__table {
		width: 100%;
		min-width: 640px;
		table-layout: fixed;
		border-collapse: collapse;

		th,
		td {
			padding: 4px 8px;
			border-bottom: 1px solid var(--color-border);
			text-align: start;
			vertical-align: top;
			white-space: normal;
		}

		th {
			font-weight: normal;
			color: var(--color-text-maxcontrast);
		}

		tbody tr:last-child td {
			border-bottom: none;
		}
	}
}

.fr-col--rule {
	width: 12em;
}

// date and time on one line ("Jun 15, 2026, 5:35 AM")
.fr-col--date {
	width: 14em;
}

.fr-col--due {
	width: 12em;
}

.fr-col--size {
	width: 6em;
}

.fr-num {
	text-align: end !important;
}

.fr-path {
	overflow-wrap: anywhere;
}

.fr-muted {
	color: var(--color-text-maxcontrast);
}

.fr-small {
	font-size: 0.85em;
}
</style>
