<template>
    <div>
        <div class="page-header pr-0">
            <h2><a href="#"><i class="fas fa-cogs"></i></a></h2>
            <ol class="breadcrumbs">
                <li class="active"><span>Configuración</span> </li>
                <li><span class="text-muted">Empresa</span></li>
            </ol>
        </div>
        <div class="card co-card">
            <div class="card-header bg-info">
                <h3 class="my-0">Datos de la Empresa</h3>
            </div>
            <form autocomplete="off" @submit.prevent="submit">
                <div class="card-body">
                    <el-tabs v-model="activeTab">
                        <el-tab-pane label="Datos generales" name="general">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group" :class="{'has-danger': errors.number}">
                                        <label class="control-label">RUC</label>
                                        <el-input v-model="form.number" :maxlength="11" :disabled="true"></el-input>
                                        <small class="form-control-feedback" v-if="errors.number" v-text="errors.number[0]"></small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group" :class="{'has-danger': errors.name}">
                                        <label class="control-label">Razón social <span class="text-danger">*</span></label>
                                        <el-input v-model="form.name"></el-input>
                                        <small class="form-control-feedback" v-if="errors.name" v-text="errors.name[0]"></small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group" :class="{'has-danger': errors.trade_name}">
                                        <label class="control-label">Nombre comercial <span class="text-danger">*</span></label>
                                        <el-input v-model="form.trade_name"></el-input>
                                        <small class="form-control-feedback" v-if="errors.trade_name" v-text="errors.trade_name[0]"></small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group" :class="{'has-danger': errors.detraction_account}">
                                        <label class="control-label">N° Cuenta de detracción</label>
                                        <el-input v-model="form.detraction_account"></el-input>
                                        <small class="form-control-feedback" v-if="errors.detraction_account" v-text="errors.detraction_account[0]"></small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group" :class="{'has-danger': errors.mtc_registration_number}">
                                        <label class="control-label">N° de registro MTC</label>
                                        <el-input v-model="form.mtc_registration_number" :maxlength="20"></el-input>
                                        <small class="co-help">Obligatorio para emitir guías de remisión transportista.</small>
                                        <small class="form-control-feedback" v-if="errors.mtc_registration_number" v-text="errors.mtc_registration_number[0]"></small>
                                    </div>
                                </div>
                                <div class="col-md-6" v-show="false">
                                    <el-checkbox v-model="form.operation_amazonia">¿Emite en la Amazonía?</el-checkbox>
                                </div>
                            </div>
                        </el-tab-pane>

                        <el-tab-pane label="Imágenes" name="images">
                            <div class="co-images">
                                <div class="co-image" v-for="img in images" :key="img.type">
                                    <div class="co-image-preview" :class="{'is-square': img.square}">
                                        <img v-if="imageUrl(img.type)" :src="imageUrl(img.type)" :alt="img.label" @error="brokenImages = Object.assign({}, brokenImages, {[img.type]: true})">
                                        <span v-else>Sin imagen</span>
                                    </div>
                                    <div class="co-image-body">
                                        <span class="co-image-title">{{ img.label }}</span>
                                        <small class="co-help">{{ img.help }}</small>
                                        <el-upload :headers="headers"
                                                   :data="{'type': img.type}"
                                                   action="/companies/uploads"
                                                   :show-file-list="false"
                                                   :on-success="successUpload">
                                            <el-button size="small" icon="el-icon-upload">{{ form[img.type] ? 'Cambiar' : 'Subir' }}</el-button>
                                        </el-upload>
                                    </div>
                                </div>
                            </div>
                        </el-tab-pane>

                        <el-tab-pane label="SUNAT y certificado" name="sunat">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group" :class="{'has-danger': errors.soap_type_id}">
                                        <label class="control-label">SOAP Tipo</label>
                                        <el-select :disabled="!form.config_system_env" v-model="form.soap_type_id">
                                            <el-option v-for="option in soap_types" :key="option.id" :value="option.id" :label="option.description"></el-option>
                                        </el-select>
                                        <small class="form-control-feedback" v-if="errors.soap_type_id" v-text="errors.soap_type_id[0]"></small>
                                    </div>
                                </div>
                                <div class="col-md-6" v-if="form.soap_type_id != '03'">
                                    <div class="form-group" :class="{'has-danger': errors.soap_send_id}">
                                        <label class="control-label">SOAP Envío</label>
                                        <el-select :disabled="!form.config_system_env" v-model="form.soap_send_id">
                                            <el-option v-for="(option, index) in soap_sends" :key="index" :value="index" :label="option"></el-option>
                                        </el-select>
                                        <small class="form-control-feedback" v-if="errors.soap_send_id" v-text="errors.soap_send_id[0]"></small>
                                    </div>
                                </div>
                                <template v-if="form.soap_type_id == '02' || form.soap_send_id == '02'">
                                    <div class="col-md-6">
                                        <div class="form-group" :class="{'has-danger': errors.soap_username}">
                                            <label class="control-label">Usuario secundario SOL/OSE <span class="text-danger">*</span></label>
                                            <el-input :disabled="!form.config_system_env" v-model="form.soap_username"></el-input>
                                            <small class="co-help">RUC + usuario. Ejemplo: 01234567890ELUSUARIO</small>
                                            <small class="form-control-feedback" v-if="errors.soap_username" v-text="errors.soap_username[0]"></small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group" :class="{'has-danger': errors.soap_password}">
                                            <label class="control-label">Clave <span class="text-danger">*</span></label>
                                            <el-input :disabled="!form.config_system_env" v-model="form.soap_password"></el-input>
                                            <small class="form-control-feedback" v-if="errors.soap_password" v-text="errors.soap_password[0]"></small>
                                        </div>
                                    </div>
                                </template>
                                <div class="col-md-12" v-if="form.soap_send_id == '02'">
                                    <div class="form-group" :class="{'has-danger': errors.soap_url}">
                                        <label class="control-label">SOAP Url</label>
                                        <el-input v-model="form.soap_url"></el-input>
                                        <small class="form-control-feedback" v-if="errors.soap_url" v-text="errors.soap_url[0]"></small>
                                    </div>
                                </div>
                                <div class="col-md-6" v-if="form.soap_type_id == '02'">
                                    <div class="form-group" :class="{'has-danger': errors.certificate_due}">
                                        <label class="control-label">Vencimiento del certificado</label>
                                        <el-date-picker v-model="form.certificate_due" type="date" value-format="yyyy-MM-dd" :clearable="true"></el-date-picker>
                                        <small class="form-control-feedback" v-if="errors.certificate_due" v-text="errors.certificate_due[0]"></small>
                                    </div>
                                </div>
                            </div>
                            <tenant-certificates-index embedded></tenant-certificates-index>
                        </el-tab-pane>

                        <el-tab-pane label="Credenciales API" name="api">
                            <p v-if="form.soap_type_id != '02'" class="co-empty">Las credenciales API de SUNAT se configuran cuando el SOAP Tipo es Producción.</p>
                            <template v-else>
                                <div class="co-group" v-for="group in apiGroups" :key="group.prefix">
                                    <span class="co-group-title">{{ group.title }}</span>
                                    <small class="co-help mb-2">{{ group.help }}</small>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group" :class="{'has-danger': errors[group.prefix + '_client_id']}">
                                                <label class="control-label">Client ID</label>
                                                <el-input v-model="form[group.prefix + '_client_id']" :maxlength="100"></el-input>
                                                <small class="form-control-feedback" v-if="errors[group.prefix + '_client_id']" v-text="errors[group.prefix + '_client_id'][0]"></small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group" :class="{'has-danger': errors[group.prefix + '_client_secret']}">
                                                <label class="control-label">Client Secret</label>
                                                <el-input v-model="form[group.prefix + '_client_secret']" type="password" :maxlength="255" autocomplete="new-password"
                                                          :placeholder="form['has_' + group.prefix + '_client_secret'] ? 'Configurado (dejar vacío para mantener)' : ''"></el-input>
                                                <small class="form-control-feedback" v-if="errors[group.prefix + '_client_secret']" v-text="errors[group.prefix + '_client_secret'][0]"></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </el-tab-pane>
                    </el-tabs>
                </div>
                <div class="co-footer">
                    <el-button type="primary" native-type="submit" :loading="loading_submit">Guardar cambios</el-button>
                </div>
            </form>
        </div>
    </div>
</template>

<script>

    export default {
        data() {
            return {
                loading_submit: false,
                headers: headers_token,
                resource: 'companies',
                errors: {},
                form: {},
                soap_sends: [],
                soap_types: [],
                toggle: false, //Creando el objeto a retornar con v-model
                activeTab: 'general',
                uploadStamp: Date.now(),
                brokenImages: {},
                images: [
                    {type: 'logo', label: 'Logo', help: 'Aparece en comprobantes y en el sistema. Recomendado 700x300 px.'},
                    {type: 'img_firm', label: 'Rúbrica (firma digital)', help: 'Se imprime en los comprobantes. Recomendado 700x300 px.'},
                    {type: 'favicon', label: 'Favicon', help: 'Ícono de la pestaña del navegador. PNG cuadrado con fondo transparente.', square: true},
                ],
                apiGroups: [
                    {prefix: 'gre', title: 'Guías de remisión (GRE)', help: 'Generadas en SUNAT Operaciones en Línea (Menú SOL).'},
                    {prefix: 'sire', title: 'SIRE', help: 'Opcional. Si se dejan vacías se usan las de GRE. Menú SOL > Empresas > Credenciales de API SUNAT, marcando "MIGE RCE y RVIE - SIRE".'},
                    {prefix: 'cpe', title: 'Consulta de validez de comprobantes', help: 'Necesarias para validar compras. Menú SOL > Empresas > Comprobantes de pago > Consulta de Validez de Comprobantes de Pago > Credenciales de API SUNAT.'},
                ],
            }
        },
        async created() {
            await this.initForm()
            await this.$http.get(`/${this.resource}/tables`)
                .then(response => {
                    this.soap_sends = response.data.soap_sends
                    this.soap_types = response.data.soap_types
                })
            await this.$http.get(`/${this.resource}/record`)
                .then(response => {
                    if (response.data !== '') {
                        this.form = response.data.data
                        this.$set(this.form, 'gre_client_secret', null)
                        this.$set(this.form, 'sire_client_secret', null)
                        this.$set(this.form, 'cpe_client_secret', null)
                    }
                })
        },
        methods: {
            initForm() {
                this.errors = {}
                this.form = {
                    id: null,
                    identity_document_type_id: '06000006',
                    number: null,
                    name: null,
                    trade_name: null,
                    soap_send_id: '01',
                    soap_type_id: '01',
                    soap_username: null,
                    soap_password: null,
                    soap_url: null,
                    certificate: null,
                    certificate_due: null,
                    logo: null,
                    logo_store: null,
                    detraction_account: null,
                    operation_amazonia: false,
                    toggle: false,
                    config_system_env: false,
                    img_firm: null,
                    gre_client_id: null,
                    mtc_registration_number: null,
                    gre_client_secret: null,
                    has_gre_client_secret: false,
                    sire_client_id: null,
                    sire_client_secret: null,
                    has_sire_client_secret: false,
                    cpe_client_id: null,
                    cpe_client_secret: null,
                    has_cpe_client_secret: false,

                }
            },
            submit() {
                this.loading_submit = true
                this.$http.post(`/${this.resource}`, this.form)
                    .then(response => {
                        if (response.data.success) {
                            this.$message.success(response.data.message)
                            if (this.form.gre_client_secret) {
                                this.form.has_gre_client_secret = true
                                this.form.gre_client_secret = null
                            }
                            if (this.form.sire_client_secret) {
                                this.form.has_sire_client_secret = true
                                this.form.sire_client_secret = null
                            }
                            if (this.form.cpe_client_secret) {
                                this.form.has_cpe_client_secret = true
                                this.form.cpe_client_secret = null
                            }
                        } else {
                            this.$message.error(response.data.message)
                        }
                    })
                    .catch(error => {
                        if (error.response.status === 422) {
                            this.errors = error.response.data
                            this.showTabWithError()
                        } else {
                            console.log(error)
                        }
                    })
                    .then(() => {
                        this.loading_submit = false
                    })
            },
            imageUrl(type) {
                const name = this.form[type]
                if (!name || this.brokenImages[type]) return null
                const dirs = {logo: 'storage/uploads/logos/', img_firm: 'storage/uploads/firms/', favicon: ''}
                return '/' + dirs[type] + name + '?v=' + this.uploadStamp
            },
            showTabWithError() {
                const tabs = {
                    general: ['number', 'name', 'trade_name', 'detraction_account', 'mtc_registration_number'],
                    sunat: ['soap_type_id', 'soap_send_id', 'soap_username', 'soap_password', 'soap_url', 'certificate_due'],
                    api: ['gre_client_id', 'gre_client_secret', 'sire_client_id', 'sire_client_secret', 'cpe_client_id', 'cpe_client_secret'],
                }
                const field = Object.keys(this.errors || {})[0]
                const tab = Object.keys(tabs).find(name => tabs[name].includes(field))
                if (tab) this.activeTab = tab
            },
            successUpload(response, file, fileList) {
                if (response.success) {
                    this.$message.success(response.message)
                    this.form[response.type] = response.name
                    this.brokenImages = Object.assign({}, this.brokenImages, {[response.type]: false})
                    this.uploadStamp = Date.now()
                    // Show the new favicon in the browser tab right away, without a reload.
                    if (response.type === 'favicon' && typeof window.setTenantFavicon === 'function') {
                        window.setTenantFavicon('/' + response.name)
                    }
                } else {
                    this.$message({message:'Error al subir el archivo', type: 'error'})
                }
            },
        }
    }
</script>

<style>
.co-card .card-body { padding-bottom: 8px; }
.co-help { display: block; margin-top: 4px; line-height: 1.45; color: var(--tenant-muted, #6b7489); }
.co-footer {
    display: flex;
    justify-content: flex-end;
    padding: 14px 24px;
    border-top: 1px solid var(--tenant-hairline, #e6e9f0);
}
.co-images { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; padding-top: 4px; }
.co-image {
    border: 1px solid var(--tenant-hairline, #e6e9f0);
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.co-image-preview {
    height: 140px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--tenant-surface-subtle, #f4f6fb);
    color: var(--tenant-muted, #6b7489);
    font-size: 13px;
}
.co-image-preview img { max-width: 85%; max-height: 110px; object-fit: contain; }
.co-image-preview.is-square img { max-width: 64px; max-height: 64px; }
.co-image-body { padding: 12px 14px 14px; display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
.co-image-title { font-weight: 600; color: var(--tenant-ink, #1b2333); }
.co-image-body .co-help { margin: 0 0 4px; }
.co-group { padding: 4px 0 8px; border-bottom: 1px solid var(--tenant-hairline, #e6e9f0); margin-bottom: 12px; }
.co-group:last-child { border-bottom: 0; margin-bottom: 0; }
.co-group-title { display: block; font-weight: 600; color: var(--tenant-ink, #1b2333); }
.co-empty { color: var(--tenant-muted, #6b7489); margin: 8px 0; }
</style>
