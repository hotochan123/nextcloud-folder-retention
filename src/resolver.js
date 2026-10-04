/**
 * JS-Port von lib/Service/RuleResolver.php – muss sich identisch verhalten
 * (siehe resolver.test.js, gleiche Fälle wie RuleResolverTest.php).
 *
 * @param {number[]} chain Ordner-IDs vom direkten Elternordner (bzw. dem Ordner selbst) aufwärts
 * @param {Map<number, object>} byFolder Ordnerregeln, Schlüssel = folderId
 * @param {object} defaultRule Standardregel
 * @param {boolean} forChildren true = was ein Unterordner ohne eigene Regel erben würde
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
		// scope = here an einem Vorfahren: überspringen
	}
	return { rule: defaultRule, depth: null, sourceId: null, isOwn: false, isDefault: true }
}
