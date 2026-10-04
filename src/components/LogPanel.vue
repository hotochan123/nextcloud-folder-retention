<template>
	<!-- eigene section statt NcSettingsSection: deren feste Breite (900 px) ist für die Tabelle zu schmal -->
	<section class="section fr-log" aria-labelledby="fr-log-title">
		<h2 id="fr-log-title">
			{{ t('folder_retention', 'Log') }}
		</h2>
		<p class="fr-log__intro">
			{{ t('folder_retention', 'Deleted files and – in simulation mode – files that would be deleted, by day and folder. A simulated hit is recorded once per file and rule.') }}
		</p>
		<form class="fr-log__filters" @submit.prevent="reload">
			<input v-model="filter.search"
				class="fr-log__search"
				type="search"
				:aria-label="t('folder_retention', 'File contains')"
				:placeholder="t('folder_retention', 'Search files, e.g. Reports or .pdf')"
				@input="onSearch">
			<select v-model="filter.status" :aria-label="t('folder_retention', 'Status')" @change="reload">
				<option value="">
					{{ t('folder_retention', 'All states') }}
				</option>
				<option value="deleted">
					{{ t('folder_retention', 'Deleted') }}
				</option>
				<option value="would_delete">
					{{ t('folder_retention', 'Would delete') }}
				</option>
				<option value="skipped">
					{{ t('folder_retention', 'Skipped') }}
				</option>
				<option value="error">
					{{ t('folder_retention', 'Error') }}
				</option>
			</select>
			<NcButton variant="tertiary"
				:pressed="showDates"
				aria-controls="fr-log-dates"
				@update:pressed="showDates = $event">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCalendarRange" />
				</template>
				{{ datesActive ? t('folder_retention', 'Date range (active)') : t('folder_retention', 'Date range') }}
			</NcButton>
			<span class="fr-log__actions">
				<NcButton variant="tertiary"
					:aria-label="t('folder_retention', 'Refresh')"
					:title="t('folder_retention', 'Refresh')"
					:disabled="loading"
					@click="reload">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
				</NcButton>
				<NcButton variant="secondary" :disabled="exporting || !totalDays" @click="onExport">
					<template #icon>
						<NcLoadingIcon v-if="exporting" :size="20" />
						<NcIconSvgWrapper v-else :path="mdiDownload" />
					</template>
					{{ t('folder_retention', 'Export CSV') }}
				</NcButton>
			</span>
		</form>
		<div v-show="showDates" id="fr-log-dates" class="fr-log__dates">
			<label for="fr-log-from">{{ t('folder_retention', 'From') }}</label>
			<input id="fr-log-from" v-model="filter.from" type="date" @change="reload">
			<label for="fr-log-to">{{ t('folder_retention', 'To') }}</label>
			<input id="fr-log-to" v-model="filter.to" type="date" @change="reload">
			<NcButton v-if="datesActive" variant="tertiary" @click="clearDates">
				{{ t('folder_retention', 'Clear dates') }}
			</NcButton>
		</div>

		<p v-if="loading || !days.length" class="fr-log__count" role="status">
			<template v-if="loading">
				{{ t('folder_retention', 'Loading …') }}
			</template>
			<template v-else-if="!error">
				{{ filtered ? t('folder_retention', 'No entries for these filters.') : t('folder_retention', 'No entries.') }}
			</template>
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="days.length" class="fr-log__days">
			<details v-for="day in days"
				:key="day.date"
				class="fr-day"
				:open="dayState[day.date]?.open"
				@toggle="onDayToggle(day, $event)">
				<summary class="fr-group fr-group--day">
					<NcIconSvgWrapper class="fr-chevron" :path="mdiChevronRight" :size="20" />
					<span class="fr-group__name">{{ formatDay(day.date) }}</span>
					<span class="fr-chips">
						<span v-for="c in countChips(day.counts)" :key="c.key" class="fr-status" :class="`fr-status--${c.tone}`">{{ c.label }}</span>
					</span>
				</summary>
				<div class="fr-day__body">
					<p v-if="dayState[day.date]?.loading" class="fr-muted">
						{{ t('folder_retention', 'Loading …') }}
					</p>
					<NcNoteCard v-else-if="dayState[day.date]?.error" type="error">
						{{ dayState[day.date].error }}
					</NcNoteCard>
					<details v-for="f in dayState[day.date]?.folders ?? []"
						:key="f.folder"
						class="fr-folder"
						:open="folderState[key(day, f)]?.open"
						@toggle="onFolderToggle(day, f, $event)">
						<summary class="fr-group">
							<NcIconSvgWrapper class="fr-chevron" :path="mdiChevronRight" :size="20" />
							<NcIconSvgWrapper class="fr-folder__icon" :path="mdiFolderOutline" :size="20" />
							<span class="fr-group__name fr-path">{{ f.folder || t('folder_retention', 'No folder') }}</span>
							<span class="fr-chips">
								<span v-for="c in countChips(f.counts)" :key="c.key" class="fr-status" :class="`fr-status--${c.tone}`">{{ c.label }}</span>
							</span>
						</summary>
						<div v-if="folderState[key(day, f)]" class="fr-files">
							<div v-if="folderState[key(day, f)].entries.length" class="fr-log__wrap">
								<table class="fr-log__table">
									<caption class="hidden-visually">
										{{ t('folder_retention', 'Log of deletions') }}
									</caption>
									<!-- feste Spaltenbreiten: die Tabellen der Ordner stehen untereinander und fluchten -->
									<colgroup>
										<col class="fr-col--time">
										<col v-if="mixed(f.counts)" class="fr-col--status">
										<col>
										<col class="fr-col--rule">
										<col class="fr-col--date">
									</colgroup>
									<thead>
										<tr>
											<th scope="col">
												{{ t('folder_retention', 'Time') }}
											</th>
											<th v-if="mixed(f.counts)" scope="col">
												{{ t('folder_retention', 'Status') }}
											</th>
											<th scope="col">
												{{ t('folder_retention', 'File') }}
											</th>
											<th scope="col">
												{{ t('folder_retention', 'Rule') }}
											</th>
											<th scope="col">
												{{ t('folder_retention', 'Reference date') }}
											</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="e in folderState[key(day, f)].entries" :key="e.id">
											<td class="fr-nowrap">
												{{ formatTime(e.deletedAt) }}
											</td>
											<td v-if="mixed(f.counts)">
												<span class="fr-status" :class="`fr-status--${statusTone(e.status)}`">{{ statusLabel(e.status) }}</span>
												<!-- „würde löschen“ gibt es nur in der Simulation – sonst den Modus dazusagen -->
												<span v-if="e.mode === 'simulation' && e.status !== 'would_delete'" class="fr-sim">{{ t('folder_retention', 'Simulation') }}</span>
												<div v-if="e.message" class="fr-msg">
													{{ e.message }}
												</div>
											</td>
											<td class="fr-path">
												{{ baseName(e.path) }}
											</td>
											<td>{{ e.ruleLabel ?? '–' }}</td>
											<td :title="sourceLabel(e.referenceSource)">
												{{ formatDate(e.referenceDate) }}
												<span v-if="unusualSource(e.referenceSource)" class="fr-muted">({{ sourceLabel(e.referenceSource) }})</span>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							<NcNoteCard v-if="folderState[key(day, f)].error" type="error">
								{{ folderState[key(day, f)].error }}
							</NcNoteCard>
							<p v-if="folderState[key(day, f)].loading" class="fr-muted">
								{{ t('folder_retention', 'Loading …') }}
							</p>
							<NcButton v-else-if="folderState[key(day, f)].entries.length < folderState[key(day, f)].total"
								variant="tertiary"
								@click="moreFiles(day, f)">
								{{ t('folder_retention', 'Show more ({shown} of {total})', { shown: folderState[key(day, f)].entries.length, total: folderState[key(day, f)].total }) }}
							</NcButton>
						</div>
					</details>
				</div>
			</details>
		</div>

		<div v-if="days.length < totalDays" class="fr-log__more">
			<NcButton variant="tertiary" :disabled="loadingMore" @click="moreDays">
				{{ t('folder_retention', 'Show older days') }}
			</NcButton>
		</div>
	</section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { mdiCalendarRange, mdiChevronRight, mdiDownload, mdiFolderOutline, mdiRefresh } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError } from '@nextcloud/dialogs'
import { downloadLog, fetchLog, fetchLogDays, fetchLogFolders } from '../store.js'
import { baseName, countChips, formatDate, formatDay, formatTime, sourceLabel, unusualSource } from '../format.js'
import { t } from '../l10n.js'

const DAY_PAGE = 10
const FILE_PAGE = 50

const filter = reactive({ status: '', from: '', to: '', search: '' })
const showDates = ref(false)
const days = ref([])
const totalDays = ref(0)
const loading = ref(false)
const loadingMore = ref(false)
const exporting = ref(false)
const error = ref(null)
/** Tag → { open, loading, error, folders } */
const dayState = reactive({})
/** Tag + Ordner → { open, loading, error, entries, total } */
const folderState = reactive({})
/** Antworten von vor einem Filterwechsel verwerfen */
let generation = 0

const filtered = computed(() => Object.values(filter).some(v => v !== ''))
const datesActive = computed(() => filter.from !== '' || filter.to !== '')

const loadError = (e) => e?.response?.data?.message ?? t('folder_retention', 'Log could not be loaded')

/** Datumsfelder (lokaler Tag) → Unix-Zeitstempel; „Bis“ schließt den ganzen Tag ein */
function params() {
	const day = (s, end) => s ? Math.floor(new Date(`${s}T${end ? '23:59:59' : '00:00:00'}`).getTime() / 1000) : undefined
	return {
		status: filter.status || undefined,
		search: filter.search.trim() || undefined,
		from: day(filter.from, false),
		to: day(filter.to, true),
	}
}

/** Filter, eingeschränkt auf einen Tag der Übersicht */
function dayParams(day) {
	const p = params()
	return { ...p, from: Math.max(p.from ?? 0, day.from), to: Math.min(p.to ?? day.to, day.to) }
}

const key = (day, f) => `${day.date}\n${f.folder}`

/** Statusspalte nur, wenn der Ordner nicht ausschließlich „gelöscht“ oder „würde löschen“ enthält */
const mixed = (counts) => countChips(counts).length > 1 || counts.skipped > 0 || counts.error > 0

async function reload() {
	const gen = ++generation
	loading.value = true
	error.value = null
	for (const k of Object.keys(dayState)) delete dayState[k]
	for (const k of Object.keys(folderState)) delete folderState[k]
	try {
		const data = await fetchLogDays({ ...params(), limit: DAY_PAGE, offset: 0 })
		if (gen !== generation) return
		days.value = data.days
		totalDays.value = data.total
		// der neueste Tag ist aufgeklappt
		if (data.days.length) {
			openDay(data.days[0])
		}
	} catch (e) {
		if (gen === generation) {
			days.value = []
			totalDays.value = 0
			error.value = loadError(e)
		}
	} finally {
		if (gen === generation) loading.value = false
	}
}

async function moreDays() {
	const gen = generation
	loadingMore.value = true
	try {
		const data = await fetchLogDays({ ...params(), limit: DAY_PAGE, offset: days.value.length })
		if (gen !== generation) return
		days.value = [...days.value, ...data.days.filter(d => !days.value.some(o => o.date === d.date))]
		totalDays.value = data.total
	} catch (e) {
		showError(loadError(e))
	} finally {
		loadingMore.value = false
	}
}

function onDayToggle(day, event) {
	if (event.target.open) {
		openDay(day)
	} else if (dayState[day.date]) {
		dayState[day.date].open = false
	}
}

/** Aufklappen und Ordner einmal laden – über das toggle-Ereignis oder direkt */
async function openDay(day) {
	if (!dayState[day.date]) {
		dayState[day.date] = { open: false, loading: false, error: null, folders: null }
	}
	const st = dayState[day.date]
	st.open = true
	if (st.folders || st.loading) return
	const gen = generation
	st.loading = true
	st.error = null
	try {
		const folders = await fetchLogFolders(dayParams(day))
		if (gen !== generation) return
		st.folders = folders
		// ein einzelner Ordner klappt gleich mit auf
		if (folders.length === 1) {
			openFolder(day, folders[0])
		}
	} catch (e) {
		st.error = loadError(e)
	} finally {
		st.loading = false
	}
}

function onFolderToggle(day, f, event) {
	if (event.target.open) {
		openFolder(day, f)
	} else if (folderState[key(day, f)]) {
		folderState[key(day, f)].open = false
	}
}

async function openFolder(day, f) {
	const k = key(day, f)
	if (!folderState[k]) {
		folderState[k] = { open: false, loading: false, error: null, entries: [], total: 0, loaded: false }
	}
	const st = folderState[k]
	st.open = true
	if (!st.loaded && !st.loading) {
		await loadFiles(day, f, st)
	}
}

function moreFiles(day, f) {
	return loadFiles(day, f, folderState[key(day, f)])
}

async function loadFiles(day, f, st) {
	const gen = generation
	st.loading = true
	st.error = null
	try {
		const data = await fetchLog({ ...dayParams(day), folder: f.folder, limit: FILE_PAGE, offset: st.entries.length })
		if (gen !== generation) return
		st.entries = [...st.entries, ...data.entries]
		st.total = data.total
		st.loaded = true
	} catch (e) {
		st.error = loadError(e)
	} finally {
		st.loading = false
	}
}

let searchTimer = null
function onSearch() {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(reload, 350)
}

function clearDates() {
	filter.from = ''
	filter.to = ''
	reload()
}

async function onExport() {
	exporting.value = true
	try {
		await downloadLog(params())
	} catch (e) {
		showError(t('folder_retention', 'Export failed'))
	} finally {
		exporting.value = false
	}
}

// Texte als Funktion: erst beim Anzeigen übersetzen
const STATUS = {
	deleted: [() => t('folder_retention', 'Deleted'), 'delete'],
	would_delete: [() => t('folder_retention', 'Would delete'), 'sim'],
	skipped_locked: [() => t('folder_retention', 'Skipped – locked'), 'skip'],
	skipped_changed: [() => t('folder_retention', 'Skipped – changed'), 'skip'],
	error: [() => t('folder_retention', 'Error'), 'error'],
	deleted_final: [() => t('folder_retention', 'Permanently deleted – trash bypassed'), 'error'],
}
const statusLabel = (s) => STATUS[s]?.[0]() ?? s
const statusTone = (s) => STATUS[s]?.[1] ?? 'skip'

onMounted(reload)
</script>

<style scoped lang="scss">
.fr-log {
	max-width: 1500px;

	h2 {
		margin-top: 0;
		font-size: 1.3em;
		font-weight: bold;
	}

	&__intro {
		margin-bottom: 12px;
		color: var(--color-text-maxcontrast);
	}
}

.fr-log__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;

	input,
	select {
		margin: 0;
	}
}

.fr-log__search {
	flex: 1 1 16em;
	max-width: 32em;
}

.fr-log__actions {
	display: flex;
	gap: 4px;
	margin-inline-start: auto;
}

.fr-log__dates {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;

	label {
		color: var(--color-text-maxcontrast);
	}

	input {
		margin: 0;
	}
}

.fr-log__count {
	margin: 8px 0;
	color: var(--color-text-maxcontrast);
}

.fr-log__more {
	margin-top: 8px;
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

.fr-files {
	padding: 0 0 8px 28px;
}

.fr-log__wrap {
	overflow-x: auto;
}

.fr-log__table {
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
	}

	th {
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}

	tbody tr:last-child td {
		border-bottom: none;
	}

	// Nextcloud setzt in Tabellen teils nowrap – Datum samt Quelle darf umbrechen
	td:not(.fr-nowrap) {
		white-space: normal;
	}

	// lange Status wie „endgültig gelöscht – Papierkorb umgangen“ umbrechen statt überragen
	.fr-status {
		white-space: normal;
	}
}

.fr-col--time {
	width: 7em;
}

.fr-col--status {
	width: 17em;
}

.fr-col--rule {
	width: 8em;
}

.fr-col--date {
	width: 17em;
}

.fr-nowrap {
	white-space: nowrap;
}

.fr-path {
	overflow-wrap: anywhere;
}

.fr-chips {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 4px;
}

.fr-status {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.9em;
	font-weight: normal;
	white-space: nowrap;

	&.fr-status--delete {
		background: #a84300;
		color: #fff;
	}

	&.fr-status--sim {
		border: 1px solid #a84300;
		color: #8a3700;
	}

	&.fr-status--skip {
		background: var(--color-background-dark);
		color: var(--color-main-text);
	}

	&.fr-status--error {
		// --color-error ist in Nextcloud 34 ein heller Hintergrundton, weiße Schrift darauf unlesbar
		background: var(--color-element-error, #c00);
		color: #fff;
	}
}

.fr-sim {
	margin-inline-start: 4px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.fr-msg {
	margin-top: 2px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.fr-muted {
	color: var(--color-text-maxcontrast);
}

// Dunkles Theme wie bei RuleBadge: Orange heller
:global(body[data-themes*='dark']) .fr-status,
:global(body[data-theme-dark]) .fr-status {
	&.fr-status--delete {
		background: #ff8a3d;
		color: #1f0d00;
	}

	&.fr-status--sim {
		border-color: #ff8a3d;
		color: #ffa466;
	}
}
</style>
