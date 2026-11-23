// Shared behaviour of every settings group on the store configuration screen:
// one form per group, one save action, server-side (422) errors next to their field,
// dirty tracking and a loading state while saving.

export function normalizeErrors(data) {
    if (!data || typeof data !== 'object') return {}
    if (data.errors && typeof data.errors === 'object') return data.errors
    if (data.message && typeof data.message === 'object') return data.message
    const clean = {}
    Object.keys(data).forEach(key => {
        if (Array.isArray(data[key])) clean[key] = data[key]
    })
    return clean
}

export function requestErrorMessage(error, fallback) {
    const response = error && error.response
    if (!response) return 'No hay conexión con el servidor. Revise su internet e intente nuevamente.'
    if (response.status === 419) return 'Su sesión expiró. Recargue la página e intente nuevamente.'
    if (response.status === 429) return 'Demasiados intentos. Espere un momento e intente nuevamente.'
    if (response.status === 403) return 'No tiene permiso para realizar esta acción.'
    const data = response.data
    if (data && typeof data.message === 'string' && data.message && response.status < 500) return data.message
    return fallback || 'No se pudo completar la acción. Intente nuevamente.'
}

export default {
    props: {
        record: { type: Object, default: null }
    },
    data() {
        return {
            errors: {},
            saving: false,
            snapshot: null,
            form: {}
        }
    },
    computed: {
        dirty() {
            return this.snapshot !== null && JSON.stringify(this.payload()) !== this.snapshot
        }
    },
    watch: {
        record: {
            immediate: true,
            handler(record) {
                if (record) {
                    this.fill(record)
                    this.markClean()
                }
            }
        },
        dirty(value) {
            this.$emit('dirty', value)
        }
    },
    methods: {
        payload() {
            return Object.assign({}, this.form)
        },
        markClean() {
            this.snapshot = JSON.stringify(this.payload())
        },
        clearError(field) {
            if (this.errors[field]) {
                this.errors = Object.assign({}, this.errors, { [field]: undefined })
            }
        },
        afterSave() {},
        async submit() {
            if (this.saving || !this.dirty) return
            this.saving = true
            this.errors = {}
            try {
                const { data } = await this.$http.post(this.endpoint, this.payload())
                if (data.success) {
                    this.$message.success(data.message)
                    this.afterSave(data)
                    this.$emit('saved', data.record)
                    this.markClean()
                } else {
                    this.$message.error(data.message || 'No se pudo guardar.')
                }
            } catch (error) {
                this.handleError(error)
            } finally {
                this.saving = false
            }
        },
        handleError(error) {
            const response = error && error.response
            if (response && response.status === 422) {
                this.errors = normalizeErrors(response.data)
                this.$message.error('Revise los campos marcados.')
                this.$nextTick(() => {
                    const field = this.$el && this.$el.querySelector('.has-danger input, .has-danger textarea')
                    if (field) field.focus()
                })
                return
            }
            this.$message.error(requestErrorMessage(error, 'No se pudo guardar. Intente nuevamente.'))
        }
    }
}
