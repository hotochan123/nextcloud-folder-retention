<template>
	<NcSettingsSection :name="t('folder_retention', 'Settings')">
		<NcCheckboxRadioSwitch type="switch"
			:model-value="state.settings.simulation"
			:loading="busy === 'simulation'"
			:disabled="!!busy"
			@update:model-value="set('simulation', $event)">
			{{ t('folder_retention', 'Simulation mode – delete nothing, only log') }}
		</NcCheckboxRadioSwitch>
		<p class="fr-hint">
			{{ t('folder_retention', 'Before switching it off, check the simulation log and disable the old flows or files_retention rules (see README, section “Migration”). Changes require password confirmation.') }}
		</p>

		<NcCheckboxRadioSwitch type="switch"
			:model-value="state.settings.tags"
			:loading="busy === 'tags'"
			:disabled="!!busy"
			@update:model-value="set('tags', $event)">
			{{ t('folder_retention', 'Show the retention period as a tag on files and folders') }}
		</NcCheckboxRadioSwitch>
		<p class="fr-hint">
			{{ t('folder_retention', 'Every file and folder in the managed areas gets a tag such as “Retention: 2 weeks” or “Retention: unlimited” – for folders, the period for the files directly inside. The tags are for information only: deletion follows the folder rules exclusively, and only admins can assign the tags. New and moved files are tagged immediately; after rule changes, the tags follow within a few minutes.') }}
			<template v-if="state.settings.tags && state.settings.tagSyncPending">
				{{ t('folder_retention', 'The tags are currently being distributed.') }}
			</template>
		</p>

		<NcCheckboxRadioSwitch type="switch"
			:model-value="state.settings.filesInfo"
			:loading="busy === 'filesInfo'"
			:disabled="!!busy"
			@update:model-value="set('filesInfo', $event)">
			{{ t('folder_retention', 'Show the deletion date in the Files app') }}
		</NcCheckboxRadioSwitch>
		<p class="fr-hint">
			{{ t('folder_retention', 'Files with a deletion date get a badge next to their name, and the sidebar gets a “Deletion” tab with date, rule and reference date. Every account sees this for the files it can access. The date is a forecast computed from the folder rules.') }}
		</p>

		<div class="fr-retention">
			<label for="fr-tag-language">{{ t('folder_retention', 'Language of tags and log entries') }}</label>
			<select id="fr-tag-language"
				:value="state.settings.tagLanguage"
				:disabled="!!busy"
				@change="setLanguage($event.target)">
				<option v-for="code in state.settings.tagLanguageChoices ?? []" :key="code" :value="code">
					{{ languageLabel(code) }}
				</option>
			</select>
		</div>
		<p class="fr-hint">
			{{ t('folder_retention', 'A system tag has one name for all accounts, whatever language they use. Choose the main language of your instance – or “Neutral” if your accounts use different languages: tags then read “⌛ 2 w” or “⌛ ∞”, new log entries are written in English. After a change, the tags are renamed within a few minutes; earlier log entries keep their language.') }}
		</p>

		<div class="fr-retention">
			<label for="fr-log-retention">{{ t('folder_retention', 'Keep log entries for') }}</label>
			<select id="fr-log-retention"
				:value="state.settings.logRetentionDays"
				:disabled="!!busy"
				@change="setFromInput('logRetentionDays', $event.target)">
				<option v-for="days in state.settings.logRetentionChoices ?? []" :key="days" :value="days">
					{{ retentionLabel(days) }}
				</option>
			</select>
		</div>
		<p class="fr-hint">
			{{ t('folder_retention', 'Older entries are removed after each daily run. Entries about files that were moved to the trash bin stay as long as the file still exists – the app needs them so that a restored file starts its retention period anew. When an account is deleted, the entries for its personal folder are removed as well.') }}
		</p>

		<div class="fr-retention">
			<label for="fr-deletion-limit">{{ t('folder_retention', 'Maximum deletions per run') }}</label>
			<input id="fr-deletion-limit"
				type="number"
				min="0"
				step="1"
				class="fr-limit"
				:value="state.settings.deletionLimit ?? 0"
				:disabled="!!busy"
				@change="setFromInput('deletionLimit', $event.target)">
			<span class="fr-hint fr-hint--inline">{{ t('folder_retention', '0 = no limit') }}</span>
		</div>
		<p class="fr-hint">
			{{ t('folder_retention', 'Optional emergency brake against mass deletion, e.g. after a period set far too short on a large folder or a wrong server clock. Once a run has moved this many files to the trash bin, deletion stops until you resume it here.') }}
		</p>
		<NcNoteCard v-if="state.settings.deletionLimit > 0" type="warning">
			{{ t('folder_retention', 'While deletion is halted, due files are not deleted either. When simulation mode is switched off for the first time, everything that has accumulated is due at once – set the limit above that number (see the simulation log) or resume deletion after checking.') }}
		</NcNoteCard>

		<p class="fr-hint">
			{{ t('folder_retention', 'Last complete run: {date}', { date: state.settings.lastCycleCompleted ? formatDate(state.settings.lastCycleCompleted) : t('folder_retention', 'none yet') }) }}
			<template v-if="state.settings.cycleInProgress">
				· {{ t('folder_retention', 'a run is currently in progress') }}
			</template>
			· {{ t('folder_retention', 'Time zone {timezone}', { timezone: state.settings.timezone }) }}
		</p>
	</NcSettingsSection>
</template>

<script setup>
import { ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { showError } from '@nextcloud/dialogs'
import { state, updateSettings } from '../store.js'
import { formatDate } from '../format.js'
import { t } from '../l10n.js'

const busy = ref(null)

/** Settings::LOG_RETENTION_CHOICES as readable periods */
function retentionLabel(days) {
	return {
		30: t('folder_retention', '30 days'),
		90: t('folder_retention', '3 months'),
		180: t('folder_retention', '6 months'),
		365: t('folder_retention', '1 year'),
		730: t('folder_retention', '2 years'),
		1825: t('folder_retention', '5 years'),
	}[days] ?? t('folder_retention', '{days} days', { days })
}

/** Language names in their own language; "neutral" = tags without words */
function languageLabel(code) {
	return {
		en: 'English',
		de: 'Deutsch',
		neutral: t('folder_retention', 'Neutral (⌛ 2 w)'),
	}[code] ?? code
}

/** Afterwards the field shows the stored choice again – also after a cancelled confirmation */
async function setLanguage(el) {
	if (el.value !== state.settings.tagLanguage) {
		await set('tagLanguage', el.value)
	}
	el.value = state.settings.tagLanguage
}

/**
 * Number from a select or input field. Afterwards the field shows the stored value again –
 * also after a rejected value or cancelled password confirmation.
 */
async function setFromInput(field, el) {
	const value = Number(el.value)
	if (Number.isInteger(value) && value >= 0) {
		await set(field, value)
	}
	el.value = String(state.settings[field] ?? 0)
}

async function set(field, value) {
	busy.value = field
	try {
		await updateSettings({ [field]: value })
	} catch (e) {
		// Cancelling the password confirmation is not an error
		if (e?.response) {
			showError(e.response.data?.message ?? t('folder_retention', 'Setting could not be saved'))
		}
	} finally {
		busy.value = null
	}
}
</script>

<style scoped>
.fr-hint {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.fr-hint--inline {
	margin: 0;
}

.fr-limit {
	width: 8em;
}

.fr-retention {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}
</style>
