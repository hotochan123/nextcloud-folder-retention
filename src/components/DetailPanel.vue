<template>
	<section class="fr-detail" aria-labelledby="fr-detail-title">
		<header class="fr-detail__head">
			<h3 id="fr-detail-title">
				{{ title }}
			</h3>
			<p class="fr-detail__path">
				{{ subtitle }}
			</p>
		</header>

		<div class="fr-effect" :class="`fr-effect--${tone}`" role="status">
			<RuleBadge :eff="eff" />
			<p>
				{{ effectText(eff) }}
				<template v-if="!eff.isOwn">
					<br><span class="fr-muted">{{ inheritedFrom(eff) }}</span>
				</template>
				<template v-else-if="own?.scope === 'here' && hasChildren">
					<br><strong>{{ t('folder_retention', 'This level only:') }}</strong> {{ t('folder_retention', 'This rule does not apply to files in subfolders.') }}
				</template>
			</p>
		</div>

		<!-- Account: personal or workspace -->
		<div v-if="node?.isRoot && node.accountId" class="fr-account">
			<NcCheckboxRadioSwitch type="switch"
				:model-value="node.kind === 'workspace'"
				:loading="accountBusy"
				:disabled="accountBusy"
				@update:model-value="onWorkspace">
				{{ t('folder_retention', 'Workspace account') }}
			</NcCheckboxRadioSwitch>
			<p class="fr-muted">
				<template v-if="node.kind === 'workspace'">
					{{ t('folder_retention', 'The files of this account (account “{account}”) count as shared storage: the default rule applies unless a folder rule takes effect. Deleted files end up in the trash of this account.', { account: node.accountId }) }}
				</template>
				<template v-else>
					{{ t('folder_retention', 'For functional accounts whose folders are shared with groups. Their files are then governed by the default rule like team folders instead of being treated as personal files.') }}
				</template>
			</p>
		</div>

		<!-- Rule for this folder -->
		<fieldset class="fr-group">
			<legend>{{ ruleLegend }}</legend>
			<div class="fr-toggles" role="group" :aria-label="ruleLegend">
				<button v-for="p in presets"
					:key="p.id"
					type="button"
					class="fr-toggle"
					:class="{ 'fr-toggle--never': p.unit === 'never' }"
					:aria-pressed="pressed === p.id ? 'true' : 'false'"
					@click="choosePreset(p)">
					{{ presetLabel(p) }}
				</button>
			</div>
			<div class="fr-custom">
				<label :for="`fr-custom-value`">{{ t('folder_retention', 'Custom period') }}</label>
				<input id="fr-custom-value"
					v-model.number="customValue"
					type="number"
					min="1"
					max="3650"
					step="1"
					inputmode="numeric"
					:aria-invalid="customInvalid ? 'true' : 'false'"
					aria-describedby="fr-custom-hint"
					@change="applyCustom">
				<select v-model="customUnit" :aria-label="t('folder_retention', 'Unit')" @change="applyCustom">
					<option value="day">
						{{ t('folder_retention', 'Days') }}
					</option>
					<option value="week">
						{{ t('folder_retention', 'Weeks') }}
					</option>
					<option value="month">
						{{ t('folder_retention', 'Months') }}
					</option>
				</select>
				<span id="fr-custom-hint" class="fr-muted" :class="{ 'fr-error': customInvalid }">
					{{ customInvalid ? t('folder_retention', 'Please enter a whole number from 1 to 3650.') : (pressed === 'custom' ? t('folder_retention', 'Custom period active') : '') }}
				</span>
			</div>
		</fieldset>

		<!-- Applies to -->
		<fieldset v-if="own && !isDefault && hasChildren" class="fr-group">
			<legend>{{ t('folder_retention', 'Applies to') }}</legend>
			<div class="fr-toggles" role="group" :aria-label="t('folder_retention', 'Applies to')">
				<button type="button"
					class="fr-toggle"
					:aria-pressed="own.scope === 'inherit' ? 'true' : 'false'"
					@click="patch({ scope: 'inherit' })">
					{{ t('folder_retention', 'Subfolders too') }}
				</button>
				<button type="button"
					class="fr-toggle"
					:aria-pressed="own.scope === 'here' ? 'true' : 'false'"
					@click="patch({ scope: 'here' })">
					{{ t('folder_retention', 'This level only') }}
				</button>
			</div>
		</fieldset>

		<p v-if="impactLine" class="fr-impact">
			{{ impactLine }}
		</p>

		<div v-if="overrides.length" class="fr-overrides">
			<h4>{{ t('folder_retention', 'Deviating rules below') }}</h4>
			<ul>
				<li v-for="o in overrides" :key="o.key">
					<button type="button" class="fr-link" @click="select(o.key)">
						<!-- Prefer breaking after "/", not in the middle of a folder name -->
						<!-- one line: line breaks in the template would be rendered as spaces -->
						<template v-for="(part, i) in o.path.split('/')" :key="i">{{ i > 0 ? '/' : '' }}<wbr v-if="i > 0">{{ part }}</template>
					</button>
					<RuleBadge :eff="o.eff" />
					<span class="fr-muted">{{ o.scope === 'here' ? t('folder_retention', 'this level only') : '' }}{{ o.dirty ? ' · ' + t('folder_retention', 'unsaved') : '' }}</span>
				</li>
			</ul>
		</div>

		<!-- Period counts from -->
		<fieldset v-if="own && !neverOwn" class="fr-group">
			<legend>{{ t('folder_retention', 'Period counts from') }}</legend>
			<div class="fr-toggles" role="group" :aria-label="t('folder_retention', 'Period counts from')">
				<button type="button"
					class="fr-toggle"
					:aria-pressed="own.basis !== 'modified' ? 'true' : 'false'"
					@click="patch({ basis: 'created' })">
					{{ t('folder_retention', 'Upload to Nextcloud') }}
				</button>
				<button type="button"
					class="fr-toggle"
					:aria-pressed="own.basis === 'modified' ? 'true' : 'false'"
					@click="patch({ basis: 'modified' })">
					{{ t('folder_retention', 'Last modification') }}
				</button>
			</div>
		</fieldset>

		<NcCheckboxRadioSwitch v-if="own && !neverOwn"
			:model-value="!!own.notify"
			@update:model-value="patch({ notify: $event })">
			{{ t('folder_retention', 'Notify the owner one day before deletion') }}
		</NcCheckboxRadioSwitch>

		<NcNoteCard v-if="saveError" type="error" class="fr-save-error">
			<strong>{{ t('folder_retention', 'Not saved:') }}</strong> {{ saveError }}
		</NcNoteCard>

		<div class="fr-actions">
			<NcButton variant="secondary" @click="previewOpen = true">
				{{ t('folder_retention', 'Show affected files') }}
			</NcButton>
			<NcButton v-if="dirty" variant="tertiary" @click="discard(key)">
				{{ t('folder_retention', 'Discard') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="!dirty || state.saving || customInvalid"
				@click="onSave">
				<template #icon>
					<NcLoadingIcon v-if="state.saving" :size="20" />
				</template>
				{{ t('folder_retention', 'Save') }}
			</NcButton>
		</div>

		<PreviewDialog v-if="previewOpen"
			:node-key="key"
			:title="title"
			:dirty="dirty"
			@close="previewOpen = false" />
	</section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError, showSuccess } from '@nextcloud/dialogs'
import RuleBadge from './RuleBadge.vue'
import PreviewDialog from './PreviewDialog.vue'
import {
	PERSONAL_KEY, currentRule, isDefaultKey, descendantIds, discard, effective, isDirty, loadSubtree,
	isPersonalPath, nameOf, pathOf, save, setDraft, setWorkspace, state,
} from '../store.js'
import { effectText, isNever, periodLabel } from '../format.js'
import { locale, t } from '../l10n.js'

const PRESETS = [
	{ id: 'inherit' },
	{ id: 'day-1', unit: 'day', value: 1 },
	{ id: 'week-1', unit: 'week', value: 1 },
	{ id: 'week-2', unit: 'week', value: 2 },
	{ id: 'month-1', unit: 'month', value: 1 },
	{ id: 'never', unit: 'never' },
]

/** "Inherit", "1 day", … "Never delete" */
function presetLabel(p) {
	return p.id === 'inherit'
		? t('folder_retention', 'Inherit')
		: periodLabel({ periodUnit: p.unit, periodValue: p.value ?? null })
}

const key = computed(() => state.selected)
/** one of the two default rules (general or personal folders) */
const isDefault = computed(() => isDefaultKey(key.value))
const isPersonalDefault = computed(() => key.value === PERSONAL_KEY)
const node = computed(() => isDefault.value ? null : state.nodes[key.value])
const own = computed(() => currentRule(key.value))
const neverOwn = computed(() => own.value && isNever(own.value))
const eff = computed(() => effective(key.value))
const dirty = computed(() => isDirty(key.value))
const hasChildren = computed(() => (node.value?.childCount ?? 0) > 0)
const presets = computed(() => isDefault.value ? PRESETS.filter(p => p.id !== 'inherit') : PRESETS)
const previewOpen = ref(false)

const title = computed(() => isPersonalDefault.value
	? t('folder_retention', 'Default rule · personal folders')
	: isDefault.value ? t('folder_retention', 'Default rule') : node.value?.name ?? '')
const ruleLegend = computed(() => isDefault.value
	? t('folder_retention', 'Default period')
	: t('folder_retention', 'Rule for this folder'))
const subtitle = computed(() => {
	if (isPersonalDefault.value) {
		return t('folder_retention', 'Applies to all files in personal folders that are not covered by a folder rule')
	}
	if (isDefault.value) {
		return t('folder_retention', 'Applies to all files in team folders and workspaces that are not covered by a folder rule')
	}
	return node.value?.path ?? ''
})

const tone = computed(() => isNever(eff.value.rule) ? 'keep' : 'delete')

/** "Inherited from the default rule." / "… for personal folders." / "Inherited from "Folder"." */
function inheritedFrom(e) {
	if (e.isDefault) {
		return e.isPersonal
			? t('folder_retention', 'Inherited from the default rule for personal folders.')
			: t('folder_retention', 'Inherited from the default rule.')
	}
	return t('folder_retention', 'Inherited from “{name}”.', { name: nameOf(e.sourceId) })
}

const pressed = computed(() => {
	const r = own.value
	if (!r) {
		return 'inherit'
	}
	if (r.periodUnit === 'never') {
		return 'never'
	}
	const match = PRESETS.find(p => p.unit === r.periodUnit && p.value === Number(r.periodValue))
	return match ? match.id : 'custom'
})

// Custom input follows the current rule
const customValue = ref(1)
const customUnit = ref('month')
watch(own, (r) => {
	if (r && r.periodUnit !== 'never') {
		customValue.value = Number(r.periodValue)
		customUnit.value = r.periodUnit
	}
}, { immediate: true })
const customInvalid = computed(() => !Number.isInteger(customValue.value) || customValue.value < 1 || customValue.value > 3650)

/** Initial values for a new own rule: taken over from what applied so far */
function baseRule() {
	return own.value ?? {
		scope: 'inherit',
		basis: eff.value.rule?.basis ?? 'created',
		notify: eff.value.rule?.notify ?? false,
	}
}

function choosePreset(p) {
	if (p.id === 'inherit') {
		setDraft(key.value, null)
		return
	}
	setDraft(key.value, { ...baseRule(), periodUnit: p.unit, periodValue: p.value ?? null })
}

function applyCustom() {
	if (customInvalid.value) {
		return
	}
	setDraft(key.value, { ...baseRule(), periodUnit: customUnit.value, periodValue: customValue.value })
}

function patch(fields) {
	if (own.value) {
		setDraft(key.value, { ...own.value, ...fields })
	}
}

function select(k) {
	state.selected = k
}

// Load the subtree so that counts and exceptions can be computed live
watch(key, async (k) => {
	previewOpen.value = false
	if (!isDefaultKey(k) && (state.nodes[k]?.childCount ?? 0) > 0) {
		try {
			await loadSubtree(k)
		} catch (e) {
			// the count is then omitted – editing is not aborted
		}
	}
}, { immediate: true })

const descendants = computed(() => isDefault.value ? [] : descendantIds(key.value))

const impactLine = computed(() => {
	if (isDefault.value || !hasChildren.value) {
		return ''
	}
	if (!state.subtreeLoaded[key.value]) {
		return t('folder_retention', 'Counting subfolders …')
	}
	const total = descendants.value.length
	if (own.value?.scope === 'here') {
		const inherited = effective(key.value, true)
		const period = periodLabel(inherited.rule)
		if (!inherited.isDefault) {
			return t('folder_retention', 'Subfolders continue to inherit from “{name}” ({period}).', { name: nameOf(inherited.sourceId), period })
		}
		return inherited.isPersonal
			? t('folder_retention', 'Subfolders continue to inherit from the default rule for personal folders ({period}).', { period })
			: t('folder_retention', 'Subfolders continue to inherit from the default rule ({period}).', { period })
	}
	const target = Number(key.value)
	const n = descendants.value.filter(id => {
		const e = effective(id)
		return !e.isDefault && e.sourceId === target
	}).length
	if (!own.value) {
		return ''
	}
	return t('folder_retention', '{count} of {total} subfolders adopt this rule.', { count: n, total })
})

const overrides = computed(() => {
	// For the default rules: all folder rules of the respective area (personal or the rest)
	const keys = isDefault.value
		? [...new Set([...Object.keys(state.saved), ...Object.keys(state.drafts)])]
			.filter(k => !isDefaultKey(k))
			.map(Number)
			.filter(id => isPersonalPath(pathOf(id)) === isPersonalDefault.value)
		: descendants.value
	return keys
		.filter(id => currentRule(id))
		.map(id => ({
			key: id,
			path: pathOf(id),
			scope: currentRule(id).scope,
			dirty: isDirty(id),
			eff: { rule: currentRule(id), isOwn: true, isDefault: false },
		}))
		.sort((a, b) => a.path.localeCompare(b.path, locale()))
})

const accountBusy = ref(false)

async function onWorkspace(value) {
	accountBusy.value = true
	try {
		await setWorkspace(node.value.accountId, value)
		showSuccess(value ? t('folder_retention', 'Account is treated as a workspace') : t('folder_retention', 'Account is treated as personal again'))
	} catch (e) {
		// Cancelling the password confirmation is not an error
		if (e?.response) {
			showError(e.response.data?.message ?? t('folder_retention', 'Setting could not be saved'))
		}
	} finally {
		accountBusy.value = false
	}
}

/** Error of the last save attempt – stays visible until saving again or switching */
const saveError = ref(null)
watch([key, dirty], () => {
	saveError.value = null
})

async function onSave() {
	saveError.value = null
	try {
		await save(key.value)
		showSuccess(t('folder_retention', 'Rule saved'))
	} catch (e) {
		if (e?.cancelled) {
			return // password dialog closed – draft is kept
		}
		const status = e?.response?.status
		saveError.value = e?.response?.data?.message
			?? (status === 403 ? t('folder_retention', 'No permission – has the session expired? Reload the page.') : null)
			?? (e?.response ? t('folder_retention', 'Server error (HTTP {status}).', { status }) : t('folder_retention', 'No connection to the server.'))
		showError(t('folder_retention', 'Saving failed'))
	}
}
</script>

<style scoped lang="scss">
.fr-detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);

	h3 {
		margin: 0;
		font-size: 1.3em;
		font-weight: bold;
		overflow-wrap: anywhere;
	}

	h4 {
		margin: 0 0 4px;
		font-weight: bold;
	}

	&__path {
		margin: 2px 0 0;
		color: var(--color-text-maxcontrast);
		overflow-wrap: anywhere;
	}
}

.fr-effect {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px;
	border-radius: var(--border-radius-large, 8px);
	border-inline-start: 4px solid;

	p {
		margin: 0;
	}

	&--delete {
		border-color: var(--fr-delete);
		background: rgba(168, 67, 0, 0.08);
	}

	&--keep {
		border-color: var(--fr-keep-border);
		background: rgba(47, 99, 184, 0.08);
	}

	&--off {
		border-color: var(--color-border-dark);
		background: var(--color-background-dark);
	}
}

.fr-account {
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);

	p {
		margin: 4px 0 0;
		font-size: 0.9em;
	}
}

.fr-group {
	margin: 0;
	padding: 0;
	border: none;

	legend {
		margin-bottom: 6px;
		font-weight: bold;
	}
}

.fr-toggles {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.fr-toggle {
	min-height: 34px;
	margin: 0;
	padding: 4px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: normal;
	cursor: pointer;

	&:hover {
		border-color: var(--color-primary-element);
	}

	&:focus-visible {
		outline: 2px solid var(--color-main-text);
		outline-offset: 2px;
	}

	&[aria-pressed='true'] {
		border-color: var(--color-primary-element);
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
		font-weight: bold;
	}
}

.fr-custom {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-top: 10px;

	input {
		width: 6em;
		margin: 0;
	}

	select {
		margin: 0;
	}
}

.fr-impact {
	margin: 0;
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-dark);
}

.fr-overrides ul {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;

	li {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto;
		align-items: center;
		gap: 2px 8px;
	}

	.fr-muted:not(:empty) {
		grid-column: 1 / -1;
		font-size: 0.85em;
	}
}

.fr-link {
	margin: 0;
	padding: 2px 4px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
	text-decoration: underline;
	cursor: pointer;
	overflow-wrap: break-word;
}

.fr-save-error {
	margin: 0 !important;
}

.fr-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.fr-muted {
	color: var(--color-text-maxcontrast);
}

.fr-error {
	color: var(--color-error-text, #c00);
}
</style>
