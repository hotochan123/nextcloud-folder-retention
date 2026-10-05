<template>
	<!-- own section instead of NcSettingsSection: its fixed width (900 px) is too narrow for the table -->
	<section class="section fr-log" aria-labelledby="fr-log-title">
		<h2 id="fr-log-title">
			{{ t('folder_retention', 'Deletions') }}
		</h2>
		<!-- upcoming = live from the current rules, log = what the runs did -->
		<div class="fr-tabs" role="tablist" :aria-label="t('folder_retention', 'Deletions')">
			<button v-for="tb in TABS"
				:id="`fr-tab-${tb.key}`"
				:key="tb.key"
				type="button"
				role="tab"
				class="fr-tabs__tab"
				:class="{ 'fr-tabs__tab--active': tab === tb.key }"
				:aria-selected="tab === tb.key ? 'true' : 'false'"
				:aria-controls="`fr-tabpanel-${tb.key}`"
				:tabindex="tab === tb.key ? 0 : -1"
				@click="selectTab(tb.key)"
				@keydown.right.prevent="selectTab(otherTab(), true)"
				@keydown.left.prevent="selectTab(otherTab(), true)">
				{{ tb.label() }}
			</button>
		</div>
		<UpcomingPanel v-if="tab === 'upcoming'"
			id="fr-tabpanel-upcoming"
			role="tabpanel"
			aria-labelledby="fr-tab-upcoming" />
		<div v-show="tab === 'log'"
			id="fr-tabpanel-log"
			role="tabpanel"
			aria-labelledby="fr-tab-log">
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
					<option value="superseded">
						{{ t('folder_retention', 'Superseded') }}
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
							<StatusChip v-for="c in countChips(day.counts)" :key="c.key" :tone="c.tone" :label="c.label" />
						</span>
					</summary>
					<div class="fr-day__body">
						<p v-if="dayState[day.date]?.loading" class="fr-muted">
							{{ t('folder_retention', 'Loading …') }}
						</p>
						<NcNoteCard v-else-if="dayState[day.date]?.error" type="error">
							{{ dayState[day.date].error }}
						</NcNoteCard>
						<template v-for="(f, i) in dayState[day.date]?.folders ?? []" :key="key(day, f)">
							<!-- superseded hits come last (LogSummary::folders), under a heading of their own -->
							<p v-if="f.superseded && !dayState[day.date].folders[i - 1]?.superseded" class="fr-superseded">
								<strong>{{ t('folder_retention', 'No longer applies') }}</strong>
								{{ t('folder_retention', 'Simulated hits superseded by a later rule change, run or deletion – they do not lead to a deletion.') }}
							</p>
							<details class="fr-folder"
								:class="{ 'fr-folder--superseded': f.superseded }"
								:open="folderState[key(day, f)]?.open"
								@toggle="onFolderToggle(day, f, $event)">
								<summary class="fr-group">
									<NcIconSvgWrapper class="fr-chevron" :path="mdiChevronRight" :size="20" />
									<NcIconSvgWrapper class="fr-folder__icon" :path="mdiFolderOutline" :size="20" />
									<span class="fr-group__name fr-path">{{ f.folder || t('folder_retention', 'No folder') }}</span>
									<span class="fr-chips">
										<StatusChip v-for="c in countChips(f.counts)" :key="c.key" :tone="c.tone" :label="c.label" />
									</span>
								</summary>
								<LogFileTable v-if="folderState[key(day, f)]"
									:state="folderState[key(day, f)]"
									:counts="f.counts"
									@more="moreFiles(day, f)" />
							</details>
						</template>
					</div>
				</details>
			</div>

			<div v-if="days.length < totalDays" class="fr-log__more">
				<NcButton variant="tertiary" :disabled="loadingMore" @click="moreDays">
					{{ t('folder_retention', 'Show older days') }}
				</NcButton>
			</div>
		</div>
	</section>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { mdiCalendarRange, mdiChevronRight, mdiDownload, mdiFolderOutline, mdiRefresh } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError } from '@nextcloud/dialogs'
import LogFileTable from './LogFileTable.vue'
import StatusChip from './StatusChip.vue'
import UpcomingPanel from './UpcomingPanel.vue'
import { downloadLog, fetchLog, fetchLogDays, fetchLogFolders } from '../store.js'
import { countChips, formatDay } from '../format.js'
import { t } from '../l10n.js'

const TABS = [
	{ key: 'upcoming', label: () => t('folder_retention', 'Upcoming') },
	{ key: 'log', label: () => t('folder_retention', 'Log') },
]
const TAB_KEY = 'folder_retention.deletionsTab'

/** Last tab per browser; storage may be blocked (private window) – then "Upcoming" */
function storedTab() {
	try {
		return window.localStorage.getItem(TAB_KEY) === 'log' ? 'log' : 'upcoming'
	} catch {
		return 'upcoming'
	}
}
const tab = ref(storedTab())
const otherTab = () => (tab.value === 'log' ? 'upcoming' : 'log')

function selectTab(key, focus = false) {
	tab.value = key
	try {
		window.localStorage.setItem(TAB_KEY, key)
	} catch {
		// only a convenience
	}
	if (key === 'log' && !logLoaded) {
		reload()
	}
	if (focus) {
		nextTick(() => document.getElementById(`fr-tab-${key}`)?.focus())
	}
}

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
/** day → { open, loading, error, folders } */
const dayState = reactive({})
/** day + folder → { open, loading, error, entries, total } */
const folderState = reactive({})
/** Discard responses from before a filter change */
let generation = 0

const filtered = computed(() => Object.values(filter).some(v => v !== ''))
const datesActive = computed(() => filter.from !== '' || filter.to !== '')

const loadError = (e) => e?.response?.data?.message ?? t('folder_retention', 'Log could not be loaded')

/** Date fields (local day) → Unix timestamp; "To" includes the whole day */
function params() {
	const day = (s, end) => s ? Math.floor(new Date(`${s}T${end ? '23:59:59' : '00:00:00'}`).getTime() / 1000) : undefined
	return {
		status: filter.status || undefined,
		search: filter.search.trim() || undefined,
		from: day(filter.from, false),
		to: day(filter.to, true),
	}
}

/** Filters, restricted to one day of the overview */
function dayParams(day) {
	const p = params()
	return { ...p, from: Math.max(p.from ?? 0, day.from), to: Math.min(p.to ?? day.to, day.to) }
}

/** A folder group is area key + folder: two areas with the same name stay apart; superseded hits are a group of their own */
const key = (day, f) => `${day.date}\n${f.superseded ? 1 : 0}\n${f.root}\n${f.folder}`

/** The log loads when its tab is first shown */
let logLoaded = false

async function reload() {
	logLoaded = true
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
		// the newest day is expanded
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
		if (gen === generation) showError(loadError(e))
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

/** Expand and load the folders once – via the toggle event or directly */
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
		// a single folder is expanded right away as well
		if (folders.length === 1) {
			openFolder(day, folders[0])
		}
	} catch (e) {
		if (gen === generation) st.error = loadError(e)
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
		const data = await fetchLog({ ...dayParams(day), folder: f.folder, root: f.root, superseded: f.superseded ? 1 : 0, limit: FILE_PAGE, offset: st.entries.length })
		if (gen !== generation) return
		st.entries = [...st.entries, ...data.entries]
		st.total = data.total
		st.loaded = true
	} catch (e) {
		if (gen === generation) st.error = loadError(e)
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

onMounted(() => {
	if (tab.value === 'log') reload()
})
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

.fr-tabs {
	display: flex;
	gap: 4px;
	margin-bottom: 12px;
	border-bottom: 1px solid var(--color-border);

	&__tab {
		margin: 0 0 -1px;
		padding: 8px 14px;
		border: none !important;
		border-bottom: 3px solid transparent !important;
		border-radius: 0 !important;
		background: transparent !important;
		color: var(--color-text-maxcontrast);
		font-weight: normal;
		cursor: pointer;

		&:hover {
			color: var(--color-main-text);
		}

		&:focus-visible {
			outline: 2px solid var(--color-main-text);
			outline-offset: -2px;
		}

		&--active {
			border-bottom-color: var(--color-primary-element) !important;
			color: var(--color-main-text);
			font-weight: bold;
		}
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

// heading above the superseded groups of a day
.fr-superseded {
	margin: 12px 0 4px;
	padding-top: 8px;
	border-top: 1px dashed var(--color-border-maxcontrast);
	color: var(--color-text-maxcontrast);

	strong {
		margin-inline-end: 4px;
		color: var(--color-main-text);
	}
}

.fr-folder--superseded > summary .fr-group__name {
	color: var(--color-text-maxcontrast);
}

.fr-path {
	overflow-wrap: anywhere;
}

.fr-chips {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 4px;

	.fr-status {
		white-space: nowrap;
	}
}

.fr-muted {
	color: var(--color-text-maxcontrast);
}
</style>
