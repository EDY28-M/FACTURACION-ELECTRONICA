// Single source of truth for POS document-type availability and API error formatting
// across SUNAT environments:
//   '01' = Demo (Factura, Boleta, Nota de Venta)
//   '02' = Producción (Factura, Boleta, Nota de Venta; warns if SUNAT config incomplete)
//   '03' = Interno (solo Nota de Venta '80')
//
// Keep in sync with app/CoreFacturalo/Helpers/Sunat/SoapEnvironmentGuard.php

const ALL_POS_DOCUMENT_TYPES = ['01', '03', '80'];
const INTERNAL_POS_DOCUMENT_TYPES = ['80'];

export function isInternalSoapMode(soapCompany) {
    return String(soapCompany || '') === '03';
}

export function allowedPosDocumentTypes(soapCompany) {
    return isInternalSoapMode(soapCompany)
        ? INTERNAL_POS_DOCUMENT_TYPES.slice()
        : ALL_POS_DOCUMENT_TYPES.slice();
}

export function normalizePosDocumentType(documentTypeId, soapCompany) {
    if (isInternalSoapMode(soapCompany)) {
        return '80';
    }

    const code = String(documentTypeId || '');
    return ALL_POS_DOCUMENT_TYPES.indexOf(code) !== -1 ? code : '03';
}

export function defaultPosDocumentType(soapCompany, customer, defaultDocumentType03) {
    if (isInternalSoapMode(soapCompany)) {
        return '80';
    }

    if (defaultDocumentType03) {
        return '03';
    }

    if (customer && String(customer.identity_document_type_id) === '6') {
        return '01';
    }

    return '03';
}

export function nextPosDocumentType(currentTypeId, soapCompany) {
    const allowed = allowedPosDocumentTypes(soapCompany);
    if (allowed.length <= 1) {
        return allowed[0];
    }

    const current = String(currentTypeId || '');
    const index = allowed.indexOf(current);
    if (index === -1) {
        return allowed[0];
    }

    return allowed[(index + 1) % allowed.length];
}

export function productionMissingMessage(soapTypeId, missingFields) {
    if (String(soapTypeId || '') !== '02') {
        return null;
    }

    if (!Array.isArray(missingFields) || missingFields.length === 0) {
        return null;
    }

    return `Producción incompleta: falta configurar ${missingFields.join(', ')}. Complételo en Configuración → Empresa → SUNAT y certificado, o emita una Nota de Venta.`;
}

function firstValidationMessage(errorsObj) {
    if (!errorsObj || typeof errorsObj !== 'object') {
        return null;
    }

    const keys = Object.keys(errorsObj);
    for (let i = 0; i < keys.length; i++) {
        const key = keys[i];
        if (key === 'message' || key === 'success') {
            continue;
        }
        const value = errorsObj[key];
        if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'string' && value[0].trim() !== '') {
            return value[0].trim();
        }
        if (typeof value === 'string' && value.trim() !== '') {
            return value.trim();
        }
    }

    return null;
}

export function extractApiErrorMessage(error, fallback = 'No se pudo procesar la operación. Intente nuevamente.') {
    if (!error || !error.response) {
        return 'No se pudo conectar con el servidor. Verifique su conexión e intente nuevamente.';
    }

    const status = error.response.status;
    const data = error.response.data;

    if (data && typeof data === 'object') {
        if (data.errors && typeof data.errors === 'object') {
            const nested = firstValidationMessage(data.errors);
            if (nested) {
                return nested;
            }
        }

        if (status === 422) {
            const flat = firstValidationMessage(data);
            if (flat) {
                return flat;
            }
        }

        if (typeof data.message === 'string' && data.message.trim() !== '') {
            return data.message.trim();
        }
    }

    if (status === 403) {
        return 'No tiene permisos o el entorno actual no permite esta operación.';
    }

    if (status === 419) {
        return 'La sesión expiró. Recargue la página e intente nuevamente.';
    }

    return fallback;
}
