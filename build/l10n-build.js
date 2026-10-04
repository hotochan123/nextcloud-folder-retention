/**
 * SPDX-FileCopyrightText: 2026 hotochan123
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/*
 * Builds l10n/<lang>.json and l10n/<lang>.js.
 *
 * The app's source language is English; the German translation lives in
 * l10n/de.json. Nextcloud reads both formats: PHP takes the .json, the browser
 * gets the .js, which Nextcloud loads automatically before the app bundle. Only
 * the .json is maintained — the .js is generated from it so the two never
 * drift apart.
 *
 * Fragments: l10n/.php-<lang>.json (strings of lib/ and templates/) and
 * l10n/.js-<lang>.json (strings of src/) are merged into l10n/<lang>.json
 * first, if they exist. A fragment is either a plain object {"source":
 * "translation"} or a Nextcloud l10n file {"translations": {…}, "pluralForm":
 * "…"}. Entries already in l10n/<lang>.json stay; a fragment entry replaces an
 * entry with the same key. Two fragments that translate one key differently
 * are an error. Fragments are never shipped (scripts/package.sh leaves them
 * out).
 *
 *   npm run l10n
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const APP_ID = 'folder_retention'
const DEFAULT_PLURAL_FORM = 'nplurals=2; plural=(n != 1);'

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'l10n')

/** Reads a JSON file; exits with a readable message if it is broken. */
function readJson(file) {
	try {
		return JSON.parse(fs.readFileSync(file, 'utf8'))
	} catch (e) {
		console.error(`Error: cannot read ${path.relative(process.cwd(), file)}: ${e.message}`)
		process.exit(1)
	}
}

/** Accepts both fragment shapes; returns {translations, pluralForm?}. */
function normalise(data, file) {
	if (data === null || typeof data !== 'object' || Array.isArray(data)) {
		console.error(`Error: ${file} is not a JSON object`)
		process.exit(1)
	}
	if (data.translations && typeof data.translations === 'object') {
		return { translations: data.translations, pluralForm: data.pluralForm }
	}
	return { translations: data }
}

if (!fs.existsSync(dir)) {
	console.log('l10n/ does not exist yet — nothing to build')
	process.exit(0)
}

const names = fs.readdirSync(dir)
const langs = new Set()
for (const f of names) {
	let m
	if ((m = /^\.(?:php|js)-([A-Za-z_]+)\.json$/.exec(f))) langs.add(m[1])
	else if ((m = /^([A-Za-z_]+)\.json$/.exec(f))) langs.add(m[1])
}
if (langs.size === 0) {
	console.log('No l10n/<lang>.json and no fragments — nothing to build')
	process.exit(0)
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b)
let failed = false

for (const lang of [...langs].sort()) {
	const target = path.join(dir, lang + '.json')
	const base = fs.existsSync(target) ? normalise(readJson(target), target) : { translations: {} }
	const merged = { ...base.translations }
	let pluralForm = base.pluralForm
	const origin = new Map() // key -> fragment that set it
	let fromFragments = 0
	let replaced = 0

	for (const kind of ['php', 'js']) {
		const name = `.${kind}-${lang}.json`
		const file = path.join(dir, name)
		if (!fs.existsSync(file)) continue
		const frag = normalise(readJson(file), name)
		pluralForm = pluralForm || frag.pluralForm
		for (const [key, value] of Object.entries(frag.translations)) {
			if (typeof value !== 'string' && !Array.isArray(value)) {
				console.error(`Error: ${name}: ${JSON.stringify(key)} is neither a string nor a plural array`)
				failed = true
				continue
			}
			if (origin.has(key) && !same(merged[key], value)) {
				console.error(`Error: ${JSON.stringify(key)} is translated differently in ${origin.get(key)} and ${name}:`)
				console.error(`  ${JSON.stringify(merged[key])}\n  ${JSON.stringify(value)}`)
				failed = true
				continue
			}
			if (!origin.has(key) && key in merged && !same(merged[key], value)) replaced++
			merged[key] = value
			origin.set(key, name)
			fromFragments++
		}
	}
	if (failed) continue

	// Sorted keys keep the diff of de.json small and stable.
	const translations = Object.fromEntries(
		Object.keys(merged).sort((a, b) => a.localeCompare(b, 'en')).map((k) => [k, merged[k]]))
	const data = { translations, pluralForm: pluralForm || DEFAULT_PLURAL_FORM }
	fs.writeFileSync(target, JSON.stringify(data, null, 4) + '\n')

	const body = JSON.stringify(data.translations, null, 4).replace(/\n/g, '\n    ')
	const js = 'OC.L10N.register(\n    "' + APP_ID + '",\n    ' + body
		+ ',\n"' + data.pluralForm + '");\n'
	fs.writeFileSync(path.join(dir, lang + '.js'), js)

	const count = Object.keys(translations).length
	const note = fromFragments ? ` (${fromFragments} from fragments, ${replaced} replaced)` : ''
	console.log(`${lang}.json + ${lang}.js  ${count} strings${note}`)
}

process.exit(failed ? 1 : 0)
