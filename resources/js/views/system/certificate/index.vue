<template>
    <div>
        <div
            class="system-dropzone"
            :class="{'is-loaded': record, 'is-dragover': isDragover}"
            @dragover.prevent="onDragOver"
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDropFile"
        >
            <svg class="sys-icon sys-icon-md" aria-hidden="true"><use :href="$icon('file-up')" /></svg>
            <div class="system-dropzone-copy">
                <template v-if="record">
                    <strong class="system-mono">{{ record }}</strong>
                    <small>Certificado cargado. Puedes arrastrar o seleccionar otro .pfx para reemplazarlo.</small>
                </template>
                <template v-else>
                    <strong>Sube el certificado .pfx</strong>
                    <small>Arrastra aquí tu archivo .pfx o selecciónalo desde tu equipo</small>
                </template>
            </div>
            <div class="system-dropzone-actions">
                <button type="button" class="system-button" @click="clickCreate">
                    <svg class="sys-icon" aria-hidden="true"><use :href="$icon('upload')" /></svg>Seleccionar .pfx
                </button>
                <button v-if="record" type="button" class="system-button system-button-danger" @click.prevent="clickDelete">
                    Eliminar
                </button>
            </div>
        </div>

        <h3 class="system-subtitle">Usuario secundario SUNAT</h3>
        <form class="system-form-grid" autocomplete="off" @submit.prevent="clickSaveSoapUser">
            <div class="system-field">
                <label for="soap-user">Usuario</label>
                <el-input id="soap-user" class="system-mono-input" v-model="soap_username" placeholder="01234567890ELUSUARIO"></el-input>
                <small class="system-field-hint">RUC + usuario</small>
            </div>
            <div class="system-field">
                <label for="soap-pass">Contraseña</label>
                <el-input id="soap-pass" type="password" v-model="soap_password" autocomplete="new-password"></el-input>
            </div>
            <div class="system-form-actions">
                <button type="submit" class="system-button system-button-primary" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
            </div>
        </form>

        <certificates-form
            :showDialog.sync="showDialog"
            :recordId="recordId"
            :initial-file="droppedFile"
            @clear-initial-file="droppedFile = null"
        ></certificates-form>
    </div>
</template>

<script>

    import CertificatesForm from './form.vue'
    import {deletable} from '../../../mixins/deletable'

    export default {
        mixins: [deletable],
        components: {CertificatesForm},
        data() {
            return {
                showDialog: false,
                resource: 'certificates',
                recordId: null,
                record: null,
                soap_username: null,
                soap_password: null,
                saving: false,
                isDragover: false,
                droppedFile: null
            }
        },
        created() {
            this.$eventHub.$on('reloadData', () => {
                this.getData()
            })
            this.getData()
        },
        methods: {
            onDragOver() {
                this.isDragover = true
            },
            onDragLeave() {
                this.isDragover = false
            },
            onDropFile(event) {
                this.isDragover = false
                const files = event && event.dataTransfer && event.dataTransfer.files
                    ? event.dataTransfer.files
                    : []
                if (!files.length) return
                const file = files[0]
                if (!/\.(pfx|p12)$/i.test(file.name || '')) {
                    this.$message.error('Selecciona un archivo .pfx o .p12 válido.')
                    return
                }
                this.droppedFile = file
                this.showDialog = true
            },
            clickSaveSoapUser()
            {
                 this.saving = true
                 let soap_username = this.soap_username
                 let soap_password = this.soap_password

                  this.$http.post(`${this.resource}/saveSoapUser`, { soap_username, soap_password})
                    .then(response => {
                        if (response.data.success) {

                             this.$message.success(response.data.message)
                             this.$emit('status', {soap_user: !!this.soap_username})

                        } else {

                            this.$message.error(response.data.message)
                        }
                    })
                    .catch(error => {

                        this.$message.error("Sucedio un error.");

                    })
                    .then(() => {
                        this.saving = false
                    })

            },
            getData() {
                this.$http.get(`/${this.resource}/record`)
                    .then(response => {
                        this.record = response.data.certificate
                        this.soap_username = response.data.soap_username
                        this.soap_password = response.data.soap_password
                        this.$emit('status', {certificate: !!this.record, soap_user: !!this.soap_username, certificate_file: this.record || ''})
                    })
            },
            clickCreate() {
                this.droppedFile = null
                this.showDialog = true
            },
            clickDelete() {
                this.destroy(`/${this.resource}`).then(() =>
                    this.$eventHub.$emit('reloadData')
                )
            }
        }
    }
</script>
