<template>
	<div class="fr-files">
		<div v-if="state.entries.length" class="fr-files__wrap">
			<table class="fr-files__table">
				<caption class="hidden-visually">
					{{ t('folder_retention', 'Log of deletions') }}
				</caption>
				<!-- feste Spaltenbreiten: die Tabellen der Ordner stehen untereinander und fluchten -->
				<colgroup>
					<col class="fr-col--time">
					<col v-if="mixed" class="fr-col--status">
					<col>
					<col class="fr-col--rule">
					<col class="fr-col--date">
				</colgroup>
				<thead>
					<tr>
						<th scope="col">
							{{ t('folder_retention', 'Time') }}
						</th>
						<th v-if="mixed" scope="col">
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
					<tr v-for="e in state.entries" :key="e.id">
						<td class="fr-nowrap">
							{{ formatTime(e.deletedAt) }}
						</td>
						<td v-if="mixed">
							<StatusChip :tone="CATEGORY_TONE[categoryOf(e.status)]" :label="statusLabel(e.status)" />
							<!-- „würde löschen“ gibt es nur in der Simulation – sonst den Modus dazusagen -->
							<span v-if="e.mode === 'simulation' && e.status !== 'would_delete'" class="fr-sim">{{ t('folder_retention', 'Simulation') }}</span>
						</td>
						<td class="fr-path">
							{{ baseName(e.path) }}
							<!-- auch ohne Statusspalte: echte Löschungen nennen hier das Konto des Papierkorbs -->
							<div v-if="e.message" class="fr-msg">
								{{ e.message }}
							</div>
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
		<NcNoteCard v-if="state.error" type="error">
			{{ state.error }}
		</NcNoteCard>
		<p v-if="state.loading" class="fr-muted">
			{{ t('folder_retention', 'Loading …') }}
		</p>
		<NcButton v-else-if="state.entries.length < state.total"
			variant="tertiary"
			@click="emit('more')">
			{{ t('folder_retention', 'Show more ({shown} of {total})', { shown: state.entries.length, total: state.total }) }}
		</NcButton>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import StatusChip from './StatusChip.vue'
import { baseName, CATEGORY_TONE, categoryOf, countChips, formatDate, formatTime, sourceLabel, unusualSource } from '../format.js'
import { t } from '../l10n.js'

const props = defineProps({
	/** { loading, error, entries, total } eines Ordners */
	state: { type: Object, required: true },
	/** Zahlen je Statusgruppe des Ordners */
	counts: { type: Object, required: true },
})
const emit = defineEmits(['more'])

/** Statusspalte nur, wenn der Ordner nicht ausschließlich „gelöscht“ oder „würde löschen“ enthält */
const mixed = computed(() => countChips(props.counts).length > 1 || props.counts.skipped > 0 || props.counts.error > 0)

// Texte als Funktion: erst beim Anzeigen übersetzen
const STATUS = {
	deleted: () => t('folder_retention', 'Deleted'),
	would_delete: () => t('folder_retention', 'Would delete'),
	skipped_locked: () => t('folder_retention', 'Skipped – locked'),
	skipped_changed: () => t('folder_retention', 'Skipped – changed'),
	error: () => t('folder_retention', 'Error'),
	deleted_final: () => t('folder_retention', 'Permanently deleted – trash bypassed'),
}
const statusLabel = (s) => STATUS[s]?.() ?? s
</script>

<style scoped lang="scss">
.fr-files {
	padding: 0 0 8px 28px;

	&__wrap {
		overflow-x: auto;
	}

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
</style>
