// FEC-002: POS draft kept in localStorage, scoped to tenant (host) + user.
// localStorage belongs to the browser origin, not to the authenticated session, so
// every key is namespaced and the legacy generic keys are discarded, never adopted.

const PREFIX = 'pos_draft'
const LEGACY_KEYS = ['form_pos', 'customer', 'amount', 'enter_amount', 'difference']

let scope = null

function storage() {
    try {
        return window.localStorage
    } catch (e) {
        return null
    }
}

function namespaced(key) {
    return scope ? `${scope}:${key}` : null
}

// Must be called once the authenticated user is known, before any read/write.
export function setPosDraftContext(userId) {
    const s = storage()
    scope = (userId === null || userId === undefined || userId === '')
        ? null
        : `${PREFIX}:${window.location.host}:${userId}`

    // Legacy generic keys have no verifiable owner: drop them.
    if (s) {
        LEGACY_KEYS.forEach(key => {
            try { s.removeItem(key) } catch (e) { /* ignore */ }
        })
    }
}

export function readPosDraft(key, defaultValue = null) {
    const s = storage()
    const k = namespaced(key)
    if (!s || !k) return defaultValue
    try {
        const value = JSON.parse(s.getItem(k))
        return value ? value : defaultValue
    } catch (e) {
        return defaultValue
    }
}

export function writePosDraft(key, value) {
    const s = storage()
    const k = namespaced(key)
    if (!s || !k) return
    try {
        s.setItem(k, JSON.stringify(value))
    } catch (e) { /* quota or blocked storage: draft is best-effort */ }
}

export function removePosDraft(key) {
    const s = storage()
    const k = namespaced(key)
    if (!s || !k) return
    try { s.removeItem(k) } catch (e) { /* ignore */ }
}
