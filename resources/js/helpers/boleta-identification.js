/**
 * Single source of truth (JS side) for the SUNAT rule on buyer identification in boletas.
 * Keep in sync with app/CoreFacturalo/Helpers/Sunat/BoletaIdentification.php
 *
 * Anexo II RS 114-2019/SUNAT: identification is required when the boleta total
 * is strictly greater than S/ 700.00 (SUNAT error 2396).
 */
export const BOLETA_IDENTIFICATION_THRESHOLD = 700

export const BOLETA_IDENTIFICATION_MESSAGE =
    'Las boletas por más de S/ 700.00 deben identificar al cliente con su tipo y número de documento (Reglamento de Comprobantes de Pago, art. 8).'

export function boletaRequiresIdentification(total, currencyTypeId, exchangeRate) {
    let amount = parseFloat(total) || 0
    if (currencyTypeId && String(currencyTypeId).toUpperCase() !== 'PEN') {
        const rate = parseFloat(exchangeRate)
        amount *= rate > 0 ? rate : 1
    }
    return Math.round(amount * 100) / 100 > BOLETA_IDENTIFICATION_THRESHOLD
}

export function isCustomerIdentified(identityDocumentTypeId, number) {
    const type = String(identityDocumentTypeId === null || identityDocumentTypeId === undefined ? '' : identityDocumentTypeId).trim()
    const num = String(number === null || number === undefined ? '' : number).trim()
    if (type === '' || type === '0' || type === '-' || num === '') return false
    if (/^(0+|9+)$/.test(num)) return false
    return true
}

// true when a boleta must be blocked: above the threshold and the customer is not identified
export function boletaNeedsIdentification(total, currencyTypeId, exchangeRate, identityDocumentTypeId, number) {
    return boletaRequiresIdentification(total, currencyTypeId, exchangeRate) &&
        !isCustomerIdentified(identityDocumentTypeId, number)
}
