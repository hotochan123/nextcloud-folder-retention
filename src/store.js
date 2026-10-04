import { computed, reactive } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { resolve } from './resolver.js'
import { t } from './l10n.js'

export const DEFAULT_KEY = 'default'
export const PERSONAL_KEY = 'personal'

const url = (path) => generateUrl('/apps/folder_retention/api' + path)

/** Only the fields that make up a rule semantically (for comparison + PUT) */
export function ruleFields(rule) {
	if (!rule) {
		return null
	}
	return {
		folderId: rule.folderId ?? null,
		periodUnit: rule.periodUnit,
		periodValue: rule.periodUnit === 'never' ? null : Number(rule.periodValue),
		scope: rule.folderId === null ? null : (rule.scope ?? 'inherit'),
		basis: rule.basis ?? 'created',
		notify: !!rule.notify,
	}
}

function sameRule(a, b) {
	return JSON.stringify(ruleFields(a)) === JSON.stringify(ruleFields(b))
}

/**
 * State of the admin UI. Key: folder ID (number) or DEFAULT_KEY.
 */
export const state = reactive({
	loading: true,
	error: null,
	settings: { simulation: true, tags: false },
	/** @type {Record<number, object>} id → node {id, parentId, name, path, kind, isRoot, childCount} */
	nodes: {},
	/** @type {Record<string, number[]>} key → child IDs (only when loaded) */
	children: {},
	/** @type {Record<string, boolean>} */
	expanded: { [DEFAULT_KEY]: true },
	/** @type {Record<string, boolean>} */
	loadingChildren: {},
	/** @type {Record<string, object>} key → saved rule */
	saved: {},
	/** @type {Record<string, object|null>} key → draft (null = remove rule / inherit) */
	drafts: {},
	/** @type {Record<number, boolean>} subtree fully loaded */
	subtreeLoaded: {},
	selected: DEFAULT_KEY,
	saving: false,
})

/** Keys of the two default rules (not folders) */
export function isDefaultKey(key) {
	return key === DEFAULT_KEY || key === PERSONAL_KEY
}

/** Rules including unsaved drafts – basis of all displays */
const view = computed(() => {
	const byFolder = new Map()
	let def = state.saved[DEFAULT_KEY]
	let personal = state.saved[PERSONAL_KEY]
	const keys = new Set([...Object.keys(state.saved), ...Object.keys(state.drafts)])
	for (const key of keys) {
		const rule = key in state.drafts ? state.drafts[key] : state.saved[key]
		if (key === DEFAULT_KEY) {
			def = rule ?? def
		} else if (key === PERSONAL_KEY) {
			personal = rule ?? personal
		} else if (rule) {
			byFolder.set(Number(key), rule)
		}
	}
	return { def, personal, byFolder }
})

export function currentRule(key) {
	return key in state.drafts ? state.drafts[key] : (state.saved[key] ?? null)
}

export function isDirty(key) {
	return key in state.drafts
}

export const dirtyCount = computed(() => Object.keys(state.drafts).length)

export function setDraft(key, rule) {
	const saved = state.saved[key] ?? null
	if ((rule === null && saved === null) || (rule !== null && saved !== null && sameRule(rule, saved))) {
		delete state.drafts[key]
	} else {
		state.drafts[key] = rule === null ? null : { ...ruleFields(rule), folderId: isDefaultKey(key) ? null : Number(key) }
	}
}

export function discard(key) {
	delete state.drafts[key]
}

/** [folder, parent, …, area root] */
export function chainOf(id) {
	const chain = []
	let cur = id
	while (cur !== undefined && cur !== null) {
		chain.push(cur)
		const node = state.nodes[cur]
		if (!node || node.isRoot) {
			break
		}
		cur = node.parentId
	}
	return chain
}

/**
 * Effective rule for files directly in a folder (or for the default rule).
 *
 * @return {{rule, isOwn, isDefault, sourceId, inactive}}
 */
export function effective(key, forChildren = false) {
	const { def, personal, byFolder } = view.value
	if (key === DEFAULT_KEY) {
		return { rule: def, isOwn: true, isDefault: true, isPersonal: false, sourceId: null }
	}
	if (key === PERSONAL_KEY) {
		return { rule: personal ?? def, isOwn: true, isDefault: true, isPersonal: true, sourceId: null }
	}
	const chain = chainOf(Number(key))
	// Read the kind from the area root – it changes when an account is marked as a workspace account
	const root = state.nodes[chain[chain.length - 1]]
	const isPersonal = root?.kind === 'home' && !!personal
	const res = resolve(chain, byFolder, isPersonal ? personal : def, forChildren)
	return { ...res, isPersonal: res.isDefault && isPersonal }
}

export function nameOf(id) {
	return state.nodes[id]?.name ?? state.saved[id]?.path?.split('/').pop() ?? `#${id}`
}

export function pathOf(id) {
	return state.nodes[id]?.path ?? state.saved[id]?.path ?? `#${id}`
}

/**
 * Is a display path inside a personal folder? Matches against the names of the loaded
 * area roots (kind = home) instead of the prefix "Persönlich · " – that one is translated.
 */
export function isPersonalPath(path) {
	return Object.values(state.nodes).some(n => n.isRoot && n.kind === 'home'
		&& (path === n.path || path.startsWith(n.path + '/')))
}

// --- Loading ---------------------------------------------------------------

function addNodes(nodes) {
	for (const n of nodes) {
		state.nodes[n.id] = {
			id: n.id,
			parentId: n.parentId,
			name: n.name,
			path: n.path,
			kind: n.kind,
			isRoot: n.isRoot,
			accountId: n.accountId ?? null,
			childCount: n.childCount,
		}
	}
}

export async function loadAll() {
	state.loading = true
	state.error = null
	try {
		const [settings, rules, tree] = await Promise.all([
			axios.get(url('/settings')),
			axios.get(url('/rules')),
			axios.get(url('/tree')),
		])
		state.settings = settings.data
		state.saved = {}
		for (const rule of rules.data) {
			state.saved[rule.isPersonalDefault ? PERSONAL_KEY : rule.isDefault ? DEFAULT_KEY : rule.folderId] = rule
		}
		addNodes(tree.data.nodes)
		// Team folders and workspace accounts directly under the default rule, personal folders grouped
		state.children[DEFAULT_KEY] = tree.data.nodes.filter(n => n.kind !== 'home').map(n => n.id)
		state.children[PERSONAL_KEY] = tree.data.nodes.filter(n => n.kind === 'home').map(n => n.id)
	} catch (e) {
		state.error = e?.response?.data?.message ?? e.message
	} finally {
		state.loading = false
	}
}

export async function loadChildren(id) {
	if (state.children[id] || state.loadingChildren[id]) {
		return
	}
	state.loadingChildren[id] = true
	try {
		const { data } = await axios.get(url('/tree'), { params: { parent: id } })
		addNodes(data.nodes)
		state.children[id] = data.nodes.map(n => n.id)
	} finally {
		delete state.loadingChildren[id]
	}
}

/** Loads the complete subtree below id in one go. */
export async function loadSubtree(id) {
	if (state.subtreeLoaded[id]) {
		return true
	}
	const { data } = await axios.get(url(`/folders/${id}/descendants`))
	if (data.truncated) {
		return false
	}
	addNodes(data.nodes)
	const byParent = {}
	for (const n of data.nodes) {
		(byParent[n.parentId] ??= []).push(n.id)
	}
	const ids = [id, ...data.nodes.map(n => n.id)]
	for (const nodeId of ids) {
		state.children[nodeId] = byParent[nodeId] ?? []
		state.subtreeLoaded[nodeId] = true
	}
	return true
}

/** All folders below id (only when loaded) */
export function descendantIds(id) {
	const out = []
	const stack = [...(state.children[id] ?? [])]
	while (stack.length) {
		const cur = stack.pop()
		out.push(cur)
		stack.push(...(state.children[cur] ?? []))
	}
	return out
}

export async function toggle(key) {
	if (state.expanded[key]) {
		delete state.expanded[key]
		return
	}
	state.expanded[key] = true
	if (typeof key === 'number') {
		await loadChildren(key)
	}
}

/** Up to this many personal folders, "Expand all" also expands their contents */
const EXPAND_PERSONAL_MAX = 50

export async function expandAll() {
	const roots = [...(state.children[DEFAULT_KEY] ?? [])]
	const personal = state.children[PERSONAL_KEY] ?? []
	if (personal.length > 0) {
		state.expanded[PERSONAL_KEY] = true
		// with very many accounts only show the list, do not load every folder tree
		if (personal.length <= EXPAND_PERSONAL_MAX) {
			roots.push(...personal)
		}
	}
	// subtree too large (truncated): at least the first level; one missing folder does not hold up the others
	await Promise.allSettled(roots.map(async id => (await loadSubtree(id)) || loadChildren(id)))
	for (const id of roots) {
		for (const key of [id, ...descendantIds(id)]) {
			if ((state.children[key] ?? []).length > 0) {
				state.expanded[key] = true
			}
		}
	}
}

export function collapseAll() {
	state.expanded = { [DEFAULT_KEY]: true }
}

// --- Saving ----------------------------------------------------------------

/**
 * Password confirmation (the server requires it for rules and settings).
 * If the user closes the dialog, the error carries cancelled = true.
 */
async function confirm() {
	try {
		await confirmPassword()
	} catch (e) {
		if (!e?.response) {
			e.cancelled = true
		}
		throw e
	}
}

export async function save(key) {
	if (!isDirty(key)) {
		return
	}
	// Changing rules can trigger deletions – like the simulation toggle, password only
	await confirm()
	state.saving = true
	try {
		const draft = state.drafts[key]
		// DEFAULT_KEY/PERSONAL_KEY correspond to the API paths "default"/"personal"
		const id = key
		if (draft === null) {
			await axios.delete(url(`/rules/${id}`))
			delete state.saved[key]
		} else {
			// folderId is in the URL – in the body it would override the route parameter
			const { folderId, ...body } = ruleFields(draft)
			const { data } = await axios.put(url(`/rules/${id}`), body)
			state.saved[key] = { ...data, path: isDefaultKey(key) ? null : pathOf(Number(key)) }
		}
		delete state.drafts[key]
	} finally {
		state.saving = false
	}
}

export async function updateSettings(patch) {
	await confirm()
	const { data } = await axios.put(url('/settings'), patch)
	state.settings = data
}

/** Mark an account as a workspace account or back to personal; reloads the tree afterwards */
export async function setWorkspace(uid, workspace) {
	await confirm()
	const { data } = await axios.put(url(`/accounts/${encodeURIComponent(uid)}/workspace`), { workspace })
	state.settings = data
	// Kind and name of the root change; expanded subtrees stay valid
	const { data: tree } = await axios.get(url('/tree'))
	addNodes(tree.nodes)
	state.children[DEFAULT_KEY] = tree.nodes.filter(n => n.kind !== 'home').map(n => n.id)
	state.children[PERSONAL_KEY] = tree.nodes.filter(n => n.kind === 'home').map(n => n.id)
}

export async function fetchPreview(key, days) {
	const { data } = await axios.get(url('/preview'), { params: { folderId: key, days } })
	return data
}

export async function fetchLog(params) {
	const { data } = await axios.get(url('/log'), { params })
	return data
}

/** Days with entries (newest first) including counts per status group */
export async function fetchLogDays(params) {
	const { data } = await axios.get(url('/log/days'), { params })
	return data
}

/** Folders with entries including counts, usually for one day (from/to) */
export async function fetchLogFolders(params) {
	const { data } = await axios.get(url('/log/folders'), { params })
	return data.folders
}

/** CSV export with the same filters; via axios so the CSRF token is sent along */
export async function downloadLog(params) {
	const res = await axios.get(url('/log/export'), { params, responseType: 'blob' })
	const name = /filename="?([^";]+)"?/.exec(res.headers['content-disposition'] ?? '')?.[1] ?? t('folder_retention', 'log.csv')
	const href = URL.createObjectURL(res.data)
	const a = document.createElement('a')
	a.href = href
	a.download = name
	document.body.appendChild(a)
	a.click()
	a.remove()
	URL.revokeObjectURL(href)
}
