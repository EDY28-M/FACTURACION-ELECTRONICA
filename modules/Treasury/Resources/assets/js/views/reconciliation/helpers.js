// Shared helpers of the bank reconciliation screens.

export function currencySymbol(currencyTypeId) {
    return currencyTypeId === 'USD' ? 'US$' : 'S/'
}

export function money(raw, currencyTypeId) {
    const value = parseFloat(raw || 0)
    return `${currencySymbol(currencyTypeId)} ${value.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

// Signed amount with an explicit "+" / "-" so the direction never depends on color alone.
export function signedMoney(raw, currencyTypeId) {
    const value = parseFloat(raw || 0)
    const sign = value > 0 ? '+' : (value < 0 ? '-' : '')
    return `${sign}${money(Math.abs(value), currencyTypeId)}`
}

export function amountClass(raw) {
    const value = parseFloat(raw || 0)
    return value < 0 ? 'text-danger' : (value > 0 ? 'text-success' : '')
}

export function cents(raw) {
    return Math.round(parseFloat(raw || 0) * 100)
}

// Normalizes an axios error: field errors (422) plus a general message.
export function parseError(error) {
    const result = { fields: {}, general: null }
    const response = error && error.response
    if (response && response.status === 422) {
        result.fields = (response.data && response.data.errors) || {}
        const keys = Object.keys(result.fields)
        result.general = keys.length ? result.fields[keys[0]][0] : ((response.data && response.data.message) || null)
    } else if (response && response.status === 403) {
        result.general = (response.data && response.data.message) || 'No tiene permiso para realizar esta operación.'
    } else if (response && response.data && response.data.message) {
        result.general = response.data.message
    } else {
        result.general = 'No se pudo completar la operación. Intente nuevamente.'
    }
    return result
}

export function today() {
    const d = new Date()
    const pad = n => (n < 10 ? '0' + n : '' + n)
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}
