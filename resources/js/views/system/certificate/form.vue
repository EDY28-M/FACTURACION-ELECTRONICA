<template>
    <el-dialog :title="titleDialog" :visible="showDialog" @close="close" @open="create" class="certificate-form">
        <div class="form-body">
            <div class="row">
                <div class="col-md-7">
                    <div class="form-group" :class="{'has-danger': errors.password}">
                        <label class="control-label">Contraseña</label>
                        <el-input type="password" v-model="form.password" autocomplete="new-password"></el-input>
                        <small class="form-control-feedback" v-if="errors.password" v-text="errors.password[0]"></small>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="form-group" :class="{'has-danger': errors.certificate}">
                        <label class="control-label">Certificado pfx</label>
                        <el-upload
                            ref="upload"
                            :headers="headers"
                            :data="{'password': form.password}"
                            action="/certificates/uploads"
                            accept=".pfx,.p12"
                            :show-file-list="false"
                            :auto-upload="false"
                            :multiple="false"
                            :on-change="handleFileChange"
                            :on-error="errorUpload"
                            :on-success="successUpload"
                        >
                            <el-button slot="trigger">Seleccionar archivo</el-button>
                        </el-upload>
                        <small class="system-field-hint system-mono" v-if="selectedFile">{{ selectedFile.name }}</small>
                        <small class="form-control-feedback" v-if="errors.certificate" v-text="errors.certificate[0]"></small>
                    </div>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-md-12 text-right">
                    <el-button @click.prevent="close()">Cancelar</el-button>
                    <el-button type="primary" @click.prevent="clickUpload" :loading="loading_submit">Subir certificado</el-button>
                </div>
            </div>
        </div>
    </el-dialog>
</template>

<script>

    import {EventBus} from '../../../helpers/bus'

    export default {
        props: ['showDialog', 'recordId', 'initialFile'],
        data() {
            return {
                loading_submit: false,
                headers: headers_token,
                titleDialog: null,
                resource: 'items',
                errors: {},
                form: {},
                selectedFile: null
            }
        },
        created() {
            this.initForm()
        },
        methods: {
            initForm() {
                this.errors = {}
                this.form = {
                    id: null,
                    certificate: null,
                    password: null,
                }
                this.selectedFile = null
            },
            create() {
                this.titleDialog = 'Subir certificado .pfx'
                this.selectedFile = this.initialFile || null
            },
            handleFileChange(file) {
                if (!file) return
                const raw = file.raw || file
                if (!/\.(pfx|p12)$/i.test(raw.name || file.name || '')) {
                    this.selectedFile = null
                    if (this.$refs.upload && typeof this.$refs.upload.clearFiles === 'function') {
                        this.$refs.upload.clearFiles()
                    }
                    this.$message.error('Solo se permiten archivos .pfx o .p12')
                    return
                }
                this.selectedFile = raw
            },
            clickUpload() {
                if (!this.selectedFile) {
                    this.$message.error('Seleccione un archivo .pfx o .p12')
                    return
                }
                this.loading_submit = true
                const formData = new FormData()
                formData.append('file', this.selectedFile)
                formData.append('password', this.form.password || '')

                this.$http.post('/certificates/uploads', formData)
                    .then(response => {
                        this.successUpload(response.data)
                    })
                    .catch(error => {
                        if (error.response && error.response.status === 422) {
                            this.errors = (error.response && error.response.data && (error.response.data.errors || error.response.data)) || {}
                        } else {
                            this.errorUpload(error)
                        }
                    })
                    .then(() => {
                        this.loading_submit = false
                    })
            },
            close() {
                if (this.$refs.upload && typeof this.$refs.upload.clearFiles === 'function') {
                    this.$refs.upload.clearFiles()
                }
                this.$emit('clear-initial-file')
                this.$emit('update:showDialog', false)
                this.initForm()
            },
            successUpload(response, file, fileList) {
                if (response && response.success) {
                    this.$message.success(response.message)
                    this.$eventHub.$emit('reloadData')
                    this.close()
                } else {
                    this.$message({message: (response && response.message) || 'Error al subir el certificado', type: 'error'})
                }
            },
            errorUpload(error) {
                if (error && error.response && error.response.status === 422 && error.response.data) {
                    this.errors = error.response.data.errors || error.response.data || {}
                    return
                }
                this.$message.error((error && error.response && error.response.data && error.response.data.message) || 'Error al subir el certificado')
            }
        }
    }
</script>
