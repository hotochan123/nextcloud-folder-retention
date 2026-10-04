<template>
	<!-- eigene section statt NcSettingsSection: deren feste Breite (900 px) ist für die Tabelle zu schmal -->
	<section class="section fr-log" aria-labelledby="fr-log-title">
		<h2 id="fr-log-title">
			{{ t('folder_retention', 'Log') }}
		</h2>
		<p class="fr-log__intro">
			{{ t('folder_retention', 'All deletions and – in simulation mode – all files that would have been deleted. Simulated hits are recorded only once per file and rule.') }}
		</p>
		<form class="fr-log__filters" @submit.prevent="reload(0)">
			<div class="fr-field">
				<label for="fr-log-mode">{{ t('folder_retention', 'Mode') }}</label>
				<select id="fr-log-mode" v-model="filter.mode" @change="reload(0)">
					<option value="">
						{{ t('folder_retention', 'All') }}
					</option>
					<option value="real">
						{{ t('folder_retention', 'Real') }}
					</option>
					<option value="simulation">
						{{ t('folder_retention', 'Simulation') }}
					</option>
				</select>
			</div>
			<div class="fr-field">
				<label for="fr-log-status">{{ t('folder_retention', 'Status') }}</label>
				<select id="fr-log-status" v-model="filter.status" @change="reload(0)">
					<option value="">
						{{ t('folder_retention', 'All') }}
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
			</div>
			<div class="fr-field">
				<label for="fr-log-from">{{ t('folder_retention', 'From') }}</label>
				<input id="fr-log-from" v-model="filter.from" type="date" @change="reload(0)">
			</div>
			<div class="fr-field">
				<label for="fr-log-to">{{ t('folder_retention', 'To') }}</label>
				<input id="fr-log-to" v-model="filter.to" type="date" @change="reload(0)">
			</div>
			<div class="fr-field fr-field--grow">
				<label for="fr-log-search">{{ t('folder_retention', 'File contains') }}</label>
				<input id="fr-log-search"
					v-model="filter.search"
					type="search"
					:placeholder="t('folder_retention', 'e.g. Reports or .pdf')"
					@input="onSearch">
			</div>
			<div class="fr-log__actions">
				<NcButton variant="tertiary" :disabled="loading" @click="reload(offset)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
					{{ t('folder_retention', 'Refresh') }}
				</NcButton>
				<NcButton variant="secondary" :disabled="exporting || !total" @click="onExport">
					<template #icon>
						<NcLoadingIcon v-if="exporting" :size="20" />
						<NcIconSvgWrapper v-else :path="mdiDownload" />
					</template>
					{{ t('folder_retention', 'Export CSV') }}
				</NcButton>
			</div>
		</form>

		<p class="fr-log__count" role="status">
			<template v-if="loading">
				{{ t('folder_retention', 'Loading …') }}
			</template>
			<template v-else-if="total === 0">
				{{ filtered ? t('folder_retention', 'No entries for these filters.') : t('folder_retention', 'No entries.') }}
			</template>
			<template v-else>
				{{ t('folder_retention', 'Entries {from}–{to} of {total}', { from: offset + 1, to: offset + entries.length, total }) }}
			</template>
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="entries.length" class="fr-log__wrap">
			<table class="fr-log__table">
				<caption class="hidden-visually">
					{{ t('folder_retention', 'Log of deletions') }}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('folder_retention', 'Time') }}
						</th>
						<th scope="col">
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
					<tr v-for="e in entries" :key="e.id">
						<td class="fr-nowrap">
							{{ formatDate(e.deletedAt) }}
						</td>
						<td>
							<span class="fr-status" :class="`fr-status--${statusTone(e.status)}`">{{ statusLabel(e.status) }}</span>
							<span v-if="e.mode === 'simulation'" class="fr-sim">{{ t('folder_retention', 'Simulation') }}</span>
							<div v-if="e.message" class="fr-msg">
								{{ e.message }}
							</div>
						</td>
						<td class="fr-path">
							{{ e.path }}
						</td>
						<td>{{ e.ruleLabel ?? '–' }}</td>
						<td class="fr-nowrap">
							{{ formatDate(e.referenceDate) }}
							<span class="fr-muted">({{ sourceLabel(e.referenceSource) }})</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<nav v-if="total > PAGE" class="fr-log__pager" :aria-label="t('folder_retention', 'Log pages')">
			<NcButton variant="tertiary" :disabled="offset === 0 || loading" @click="reload(Math.max(0, offset - PAGE))">
				{{ t('folder_retention', 'Previous') }}
			</NcButton>
			<span>{{ t('folder_retention', 'Page {page} of {pages}', { page: Math.floor(offset / PAGE) + 1, pages: Math.ceil(total / PAGE) }) }}</span>
			<NcButton variant="tertiary" :disabled="offset + PAGE >= total || loading" @click="reload(offset + PAGE)">
				{{ t('folder_retention', 'Next') }}
			</NcButton>
		</nav>
	</section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { mdiDownload, mdiRefresh } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError } from '@nextcloud/dialogs'
import { downloadLog, fetchLog } from '../store.js'
import { formatDate, sourceLabel } from '../format.js'
import { t } from '../l10n.js'

const PAGE = 50

const filter = reactive({ mode: '', status: '', from: '', to: '', search: '' })
const entries = ref([])
const total = ref(0)
const offset = ref(0)
const loading = ref(false)
const exporting = ref(false)
const error = ref(null)

const filtered = computed(() => Object.values(filter).some(v => v !== ''))

/** Datumsfelder (lokaler Tag) → Unix-Zeitstempel; „Bis“ schließt den ganzen Tag ein */
function params() {
	const day = (s, end) => s ? Math.floor(new Date(`${s}T${end ? '23:59:59' : '00:00:00'}`).getTime() / 1000) : undefined
	return {
		mode: filter.mode || undefined,
		status: filter.status || undefined,
		search: filter.search.trim() || undefined,
		from: day(filter.from, false),
		to: day(filter.to, true),
	}
}

async function reload(newOffset) {
	loading.value = true
	error.value = null
	try {
		const data = await fetchLog({ ...params(), limit: PAGE, offset: newOffset })
		entries.value = data.entries
		total.value = data.total
		offset.value = newOffset
	} catch (e) {
		error.value = e?.response?.data?.message ?? t('folder_retention', 'Log could not be loaded')
	} finally {
		loading.value = false
	}
}

let searchTimer = null
function onSearch() {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(() => reload(0), 350)
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

onMounted(() => reload(0))
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
	align-items: flex-end;
	gap: 8px 12px;
	margin-bottom: 8px;
}

.fr-field {
	display: flex;
	flex-direction: column;
	gap: 2px;

	label {
		font-size: 0.9em;
		color: var(--color-text-maxcontrast);
	}

	input,
	select {
		margin: 0;
	}

	&--grow {
		flex: 1 1 14em;

		input {
			width: 100%;
		}
	}
}

.fr-log__actions {
	display: flex;
	gap: 4px;
}

.fr-log__count {
	margin: 8px 0;
	color: var(--color-text-maxcontrast);
}

.fr-log__wrap {
	overflow-x: auto;
}

.fr-log__table {
	width: 100%;
	border-collapse: collapse;

	th,
	td {
		padding: 6px 8px;
		border-bottom: 1px solid var(--color-border);
		text-align: start;
		vertical-align: top;
	}

	th {
		font-weight: bold;
		color: var(--color-text-maxcontrast);
	}
}

.fr-nowrap {
	white-space: nowrap;
}

.fr-path {
	overflow-wrap: anywhere;
	min-width: 16em;
}

.fr-status {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.9em;
	white-space: nowrap;

	&--delete {
		background: #a84300;
		color: #fff;
	}

	&--sim {
		border: 1px solid #a84300;
		color: #8a3700;
	}

	&--skip {
		background: var(--color-background-dark);
		color: var(--color-main-text);
	}

	&--error {
		background: var(--color-error, #c00);
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

.fr-log__pager {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 8px;
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
