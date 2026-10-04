/**
 * JS port of lib/Service/RuleResolver.php – must behave identically
 * (see resolver.test.js, same cases as RuleResolverTest.php).
 *
 * @param {number[]} chain folder IDs from the direct parent folder (or the folder itself) upwards
 * @param {Map<number, object>} byFolder folder rules, key = folderId
 * @param {object} defaultRule default rule
 * @param {boolean} forChildren true = what a subfolder without its own rule would inherit
 * @return {{rule: object, depth: number|null, sourceId: number|null, isOwn: boolean, isDefault: boolean}}
 */
export function resolve(chain, byFolder, defaultRule, forChildren = false) {
	for (let depth = 0; depth < chain.length; depth++) {
		const id = chain[depth]
		const rule = byFolder.get(id)
		if (!rule) {
			continue
		}
		if ((depth === 0 && !forChildren) || rule.scope === 'inherit') {
			return { rule, depth, sourceId: id, isOwn: depth === 0, isDefault: false }
		}
		// scope = here on an ancestor: skip
	}
	return { rule: defaultRule, depth: null, sourceId: null, isOwn: false, isDefault: true }
}
