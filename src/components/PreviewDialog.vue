<template>
	<NcDialog :name="t('folder_retention', 'Affected files · {title}', { title })"
		size="large"
		:open="true"
		@update:open="(v) => !v && emit('close')">
		<div class="fr-preview">
			<div class="fr-preview__bar">
				<label for="fr-preview-days">{{ t('folder_retention', 'Time span') }}</label>
				<select id="fr-preview-days" v-model.number="days">
					<option :value="0">
						{{ t('folder_retention', 'Already due') }}
					</option>
					<option :value="1">
						{{ t('folder_retention', 'Next day') }}
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
				</select>
			</div>

			<NcNoteCard v-if="dirty" type="info">
				{{ t('folder_retention', 'The preview shows the') }} <strong>{{ t('folder_retention', 'saved') }}</strong> {{ t('folder_retention', 'state. Unsaved changes are not yet taken into account.') }}
			</NcNoteCard>
			<NcNoteCard v-if="state.settings.simulation" type="warning">
				{{ t('folder_retention', 'Simulation mode active: these files would be deleted – nothing is actually deleted.') }}
			</NcNoteCard>

			<NcLoadingIcon v-if="loading" :size="32" />
			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<template v-else-if="result">
				<p>
					<strong>{{ result.total }}</strong> {{ summary }}
					<span v-if="result.truncated">{{ t('folder_retention', 'Showing the first {count}.', { count: result.items.length }) }}</span>
				</p>
				<NcNoteCard v-if="otherRules" type="info">
					{{ otherRules }}
				</NcNoteCard>
				<NcNoteCard v-if="result.incomplete" type="warning">
					{{ t('folder_retention', 'The preview was aborted for time reasons and is incomplete.') }}
				</NcNoteCard>
				<div v-if="result.items.length" class="fr-table-wrap">
					<table class="fr-table">
						<caption class="hidden-visually">
							{{ t('folder_retention', 'Files that would be deleted') }}
						</caption>
						<colgroup>
							<col class="fr-col-path">
							<col class="fr-col-rule">
							<col class="fr-col-date">
							<col class="fr-col-due">
							<col class="fr-col-size">
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
							<tr v-for="item in result.items" :key="item.fileId">
								<td class="fr-path">
									{{ item.path }}
								</td>
								<td>
									{{ item.ruleLabel }}
									<span class="fr-muted fr-source">{{ t('folder_retention', 'from {source}', { source: item.ruleSource }) }}</span>
								</td>
								<td>
									{{ formatDate(item.referenceDate) }}
									<span class="fr-muted fr-source">({{ sourceLabel(item.referenceSource) }})</span>
								</td>
								<td :class="{ 'fr-overdue': item.overdue }">
									{{ item.overdue ? t('folder_retention', 'due') : formatDate(item.expiresAt) }}
								</td>
								<td class="fr-num">
									{{ formatSize(item.size) }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>
		</div>
	</NcDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { effective, fetchPreview, isDefaultKey, state } from '../store.js'
import { formatDate, formatSize, isNever, sourceLabel } from '../format.js'
import { n, t } from '../l10n.js'

const props = defineProps({
	nodeKey: { type: [Number, String], required: true },
	title: { type: String, required: true },
	dirty: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const days = ref(7)
const loading = ref(false)
const error = ref(null)
const result = ref(null)

const summary = computed(() => {
	// the number is shown in bold before it; the sentence only agrees with it in grammatical number
	const total = result.value?.total ?? 0
	if (days.value === 0) {
		return n('folder_retention', 'file is already due.', 'files are already due.', total)
	}
	if (days.value === 1) {
		return n('folder_retention', 'file becomes due on the next day (including those already due).', 'files become due on the next day (including those already due).', total)
	}
	return n('folder_retention', 'file becomes due in the next {days} days (including those already due).', 'files become due in the next {days} days (including those already due).', total, { days: days.value })
})

/**
 * Notice when files fall under a different rule than this folder's – typical for
 * "Never delete · this level only": subfolders then inherit from further up.
 */
const otherRules = computed(() => {
	if (!result.value?.items.length || props.dirty || isDefaultKey(props.nodeKey)) {
		return ''
	}
	const own = effective(props.nodeKey)
	const others = result.value.items.filter(i => i.ruleId !== own.rule?.id).length
	if (others === 0) {
		return ''
	}
	const scopeHere = own.isOwn && own.rule?.scope === 'here'
	const lead = isNever(own.rule)
		? t('folder_retention', 'Files directly in “{name}” are never deleted.', { name: props.title })
		: t('folder_retention', 'Not all files fall under the rule of “{name}”.', { name: props.title })
	const why = scopeHere
		? t('folder_retention', 'The rule applies to this level only – subfolders inherit from further up.')
		: t('folder_retention', 'Some subfolders have their own rule.')
	const where = others === result.value.items.length
		? t('folder_retention', 'All listed files are in subfolders with a different rule (see column “Rule”).')
		: n('folder_retention', '%n of the listed files is in a subfolder with a different rule (see column “Rule”).', '%n of the listed files are in subfolders with a different rule (see column “Rule”).', others)
	return `${lead} ${why} ${where}`
})

watch(days, async (d) => {
	loading.value = true
	error.value = null
	try {
		result.value = await fetchPreview(props.nodeKey, d)
	} catch (e) {
		error.value = e?.response?.data?.message ?? t('folder_retention', 'Preview could not be loaded')
	} finally {
		loading.value = false
	}
}, { immediate: true })
</script>

<style scoped lang="scss">
.fr-preview {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 12px;

	&__bar {
		display: flex;
		align-items: center;
		gap: 8px;
	}
}

.fr-table-wrap {
	overflow-x: auto;
}

.fr-table {
	width: 100%;
	min-width: 640px;
	// fixed: a long path wraps instead of pushing the due date and size out of view
	table-layout: fixed;
	border-collapse: collapse;

	th,
	td {
		padding: 6px 8px;
		border-bottom: 1px solid var(--color-border);
		text-align: start;
		vertical-align: top;
		// Nextcloud's table styles keep cells on one line; with the fixed layout that would overlap
		white-space: normal;
		overflow-wrap: anywhere;
	}

	th {
		font-weight: bold;
		color: var(--color-text-maxcontrast);
	}
}

.fr-path {
	overflow-wrap: anywhere;
}

.fr-col-path {
	width: 36%;
}

.fr-col-rule {
	width: 17%;
}

.fr-col-date {
	width: 21%;
}

.fr-col-due {
	width: 14%;
}

.fr-col-size {
	width: 12%;
}

.fr-num {
	text-align: end !important;
	white-space: nowrap;
}

.fr-overdue {
	color: var(--color-error-text, #c00);
	font-weight: bold;
}

.fr-muted {
	color: var(--color-text-maxcontrast);
}

.fr-source {
	display: block;
	font-size: 0.9em;
}
</style>
