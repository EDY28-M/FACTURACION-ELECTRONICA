<template>
    <div v-if="embedded" class="cfg-row">
        <div class="cfg-text">
            <span class="cfg-label">Eliminar documentos de prueba</span>
            <small class="cfg-help">Borra los comprobantes emitidos en modo demo. Esta acción no se puede deshacer.</small>
        </div>
        <el-button type="danger" size="small" :loading="loading_submit" @click="deleteDocuments">Eliminar documentos de prueba</el-button>
    </div>
    <div v-else class="card">
        <div class="card-header bg-info">
            <h3 class="my-0">Otras Operaciones</h3>
        </div>
        <div class="card-body">
            <el-button type="primary" :loading="loading_submit" @click="deleteDocuments">Eliminar documentos de prueba</el-button>
        </div>
    </div>
</template>

<script>

    export default {
        props: {
            embedded: { type: Boolean, default: false },
        },
        data() {
            return {
                loading_submit: false,
                resource: 'options',
                errors: {},
                form: {},
                loading_submit_voided: false
            }
        },
        methods: {
            initForm() {
                this.errors = {}
                this.form = {}
            },
            async deleteDocuments() {
                try {
                    await this.$confirm('Se eliminarán los documentos de prueba. Esta acción no se puede deshacer.', 'Eliminar documentos de prueba', {
                        confirmButtonText: 'Eliminar',
                        cancelButtonText: 'Cancelar',
                        type: 'warning',
                    })
                } catch (e) {
                    return
                }
                this.loading_submit = true
                this.$http.post(`/${this.resource}/delete_documents`)
                    .then(response => {
                        if (response.data.success) {
                            this.$message.success(response.data.message)
                        } else {
                            this.$message.error(response.data.message)
                        }
                    })
                    .catch(error => {
                        if (error.response.status === 422) {
                            this.errors = error.response.data.errors
                        } else {
                            console.log(error)
                        }
                    })
                    .then(() => {
                        this.loading_submit = false
                    })
            },
            consultVoided()
            {
                this.loading_submit_voided = true
                this.$http.get(`/voided/status_masive`)
                    .then(response => {
                        if (response.data.success) {
                            this.$message.success(response.data.message)
                        } else {
                            this.$message.error('Sucedio un error')
                        }
                    })
                    .catch(error => {
                        if (error.response.status === 422) {
                            this.errors = error.response.data.errors
                        } else {
                            console.log(error)
                        }
                    })
                    .then(() => {
                        this.loading_submit_voided = false
                    })
            }
        }
    }
</script>
