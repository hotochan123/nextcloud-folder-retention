// node --test src/  – dieselben Fälle wie tests/Unit/Service/RuleResolverTest.php
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { resolve } from './resolver.js'

const CHAINS = {
	1: [1],
	10: [10, 1], 11: [11, 10, 1], 12: [12, 11, 10, 1],
	13: [13, 10, 1], 14: [14, 13, 10, 1], 15: [15, 13, 10, 1], 16: [16, 15, 13, 10, 1],
	20: [20, 1], 21: [21, 20, 1], 22: [22, 21, 20, 1],
	30: [30, 1], 31: [31, 30, 1], 32: [32, 31, 30, 1], 33: [33, 32, 31, 30, 1], 34: [34, 33, 32, 31, 30, 1],
	40: [40, 1],
}
const def = { id: 1, folderId: null, periodUnit: 'month', periodValue: 1, scope: null }
const rules = new Map([
	[10, { id: 100, folderId: 10, scope: 'inherit' }],
	[13, { id: 101, folderId: 13, scope: 'inherit' }],
	[15, { id: 102, folderId: 15, scope: 'inherit' }],
	[20, { id: 103, folderId: 20, scope: 'here' }],
	[30, { id: 104, folderId: 30, scope: 'inherit' }],
	[31, { id: 105, folderId: 31, scope: 'here' }],
	[33, { id: 106, folderId: 33, scope: 'here' }],
])

const fileCases = [
	['Datei direkt in Academy', 10, 100, 0],
	['Academy/Projekte', 11, 100, 1],
	['Academy/Projekte/2026', 12, 100, 2],
	['Academy/Archiv', 13, 101, 0],
	['Archiv/Alt erbt Ausnahme', 14, 101, 1],
	['Ausnahme in der Ausnahme', 15, 102, 0],
	['unter Ausnahme in der Ausnahme', 16, 102, 1],
	['QM-IT direkt (here gilt)', 20, 103, 0],
	['QM-IT/Entwürfe → Standard', 21, null, null],
	['QM-IT/Entwürfe/Tief → Standard', 22, null, null],
	['Presse direkt (here)', 31, 105, 0],
	['Presse/Fotos → Kommunikation', 32, 104, 2],
	['Roh direkt (here)', 33, 106, 0],
	['Roh/Neu überspringt zwei here', 34, 104, 4],
	['Sonstiges → Standard', 40, null, null],
	['Wurzel → Standard', 1, null, null],
]

for (const [name, parent, ruleId, depth] of fileCases) {
	test(`resolve: ${name}`, () => {
		const res = resolve(CHAINS[parent], rules, def)
		assert.equal(res.rule.id, ruleId ?? def.id)
		assert.equal(res.isDefault, ruleId === null)
		assert.equal(res.depth, depth)
		assert.equal(res.isOwn, depth === 0)
	})
}

const childCases = [
	['Academy vererbt sich selbst', 10, 100],
	['QM-IT (here) → Standard', 20, null],
	['Presse (here) → Kommunikation', 31, 104],
	['Roh (here) → Kommunikation', 33, 104],
	['ohne Regel → wie Datei darin', 11, 100],
	['Sonstiges → Standard', 40, null],
]

for (const [name, folder, ruleId] of childCases) {
	test(`resolve forChildren: ${name}`, () => {
		assert.equal(resolve(CHAINS[folder], rules, def, true).rule.id, ruleId ?? def.id)
	})
}

test('forChildren stimmt mit echtem Kind ohne Regel überein', () => {
	for (const [folder, child] of [[20, 21], [31, 32], [33, 34], [10, 11], [13, 14]]) {
		assert.equal(resolve(CHAINS[folder], rules, def, true).rule, resolve(CHAINS[child], rules, def).rule)
	}
})

test('leere Kette → Standard', () => {
	assert.equal(resolve([], rules, def).isDefault, true)
})
