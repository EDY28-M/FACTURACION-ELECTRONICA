<template>
    <div class="system-dashboard">
        <header class="system-page-heading">
            <div>
                <h1>Backup</h1>
                <p>Copia de base de datos y archivos de facturación</p>
            </div>
        </header>

        <div class="system-split system-split-3">
            <div class="system-stack">
                <section class="system-panel system-panel-pad">
                    <h2 class="system-panel-title"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('database-backup')" /></svg><span>Generar backup</span></h2>
                    <form @submit.prevent="start()">
                        <div class="system-option-grid" role="radiogroup" aria-label="Tipo de backup">
                            <label class="system-option" :class="{'is-selected': formBackups.type === 'todos'}">
                                <input type="radio" value="todos" v-model="formBackups.type" />
                                <svg class="sys-icon sys-icon-md" aria-hidden="true"><use :href="$icon('database')" /></svg><span><strong>Todos</strong><small>Base de datos y archivos</small></span>
                            </label>
                            <label class="system-option" :class="{'is-selected': formBackups.type === 'individual'}">
                                <input type="radio" value="individual" v-model="formBackups.type" />
                                <svg class="sys-icon sys-icon-md" aria-hidden="true"><use :href="$icon('building-2')" /></svg><span><strong>Individual</strong><small>Un cliente específico</small></span>
                            </label>
                        </div>

                        <div v-if="formBackups.type === 'individual'" class="system-field" :class="{'has-danger': errors.hostname_id || clientError}">
                            <label>Cliente</label>
                            <el-select v-model="formBackups.hostname_id" clearable filterable placeholder="Selecciona un cliente" aria-label="Cliente">
                                <el-option v-for="cl in clients" :key="cl.hostname_id" :label="cl.name" :value="cl.hostname_id"></el-option>
                            </el-select>
                            <small class="form-control-feedback" v-if="clientError">Selecciona el cliente que quieres respaldar.</small>
                            <small class="form-control-feedback" v-else-if="errors.hostname_id" v-text="errors.hostname_id[0]"></small>
                        </div>

                        <p v-if="db.error || files.error" class="system-inline-alert" role="alert">
                            {{ db.error || files.error }}
                        </p>
                        <p v-else-if="files.status === 'success'" class="system-inline-ok" role="status">
                            Backup generado correctamente.
                        </p>

                        <button type="submit" class="system-button system-button-primary" :disabled="loading_submit">
                            <svg class="sys-icon" aria-hidden="true"><use :href="$icon('play')" /></svg>{{ loading_submit ? 'Generando…' : 'Iniciar proceso' }}
                        </button>
                    </form>

                    <div class="system-stat-row">
                        <div class="system-stat"><span>Espacio disponible en disco</span><strong>{{ discLabel }}</strong></div>
                        <div class="system-stat"><span>Archivos de facturación</span><strong>{{ storageLabel }}</strong></div>
                    </div>

                    <div class="system-last-backup">
                        <p v-if="newLastZip !== '' && newLastZip">Último backup: <strong>{{ newLastZip.date || 'sin fecha' }}</strong> · <span class="system-mono">{{ newLastZip.name }}</span></p>
                        <p v-else>Todavía no se generó ningún backup.</p>
                        <button type="button" class="system-button" :disabled="!newLastZip" @click.prevent="clickDownload()">
                            <svg class="sys-icon" aria-hidden="true"><use :href="$icon('download')" /></svg>Descargar
                        </button>
                    </div>
                </section>

                <section class="system-panel system-panel-pad">
                    <h2 class="system-panel-title"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('history')" /></svg><span>Restaurar</span></h2>
                    <h3 class="system-subtitle">Base de datos</h3>
                    <p class="system-hint">Para restaurar una base de datos ejecuta el siguiente comando.</p>
                    <div class="system-code">
                        <code>mysql -u [user] -p [database_name] &lt; [filename].sql</code>
                        <button type="button" class="system-code-copy" aria-label="Copiar comando" @click="copy('mysql -u [user] -p [database_name] < [filename].sql')"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('copy')" /></svg></button>
                    </div>
                    <h3 class="system-subtitle">Archivos</h3>
                    <p class="system-hint">Para restaurar los archivos descargados copia las carpetas dentro de la carpeta del cliente.</p>
                    <div class="system-code">
                        <pre><code v-for="folder in folders" :key="folder">cp [path_del_zip]/{{ folder }} storage/app/tenancy/tenants/tenancy_[subdominio del cliente]
</code></pre>
                    </div>
                    <p class="system-hint">Repetir para todas las carpetas que están dentro del .zip.</p>
                </section>
            </div>

            <section class="system-panel system-panel-pad system-ftp">
                <h2 class="system-panel-title"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('server')" /></svg><span>Enviar por FTP</span></h2>
                <p class="system-hint">Envía el último backup generado. Por seguridad, sus datos FTP no son guardados.</p>
                <form autocomplete="off" @submit.prevent="uploadFtp()">
                    <div class="system-field" :class="{'has-danger': errors.host}">
                        <label for="ftp-host">Servidor</label>
                        <el-input id="ftp-host" v-model="form.host" placeholder="ftp.dominio.com"></el-input>
                        <small class="form-control-feedback" v-if="errors.host" v-text="errors.host[0]"></small>
                    </div>
                    <div class="system-field" :class="{'has-danger': errors.port}">
                        <label for="ftp-port">Puerto</label>
                        <el-input id="ftp-port" class="system-narrow" v-model="form.port" placeholder="21"></el-input>
                        <small class="form-control-feedback" v-if="errors.port" v-text="errors.port[0]"></small>
                    </div>
                    <div class="system-field" :class="{'has-danger': errors.username}">
                        <label for="ftp-user">Usuario</label>
                        <el-input id="ftp-user" v-model="form.username"></el-input>
                        <small class="form-control-feedback" v-if="errors.username" v-text="errors.username[0]"></small>
                    </div>
                    <div class="system-field" :class="{'has-danger': errors.password}">
                        <label for="ftp-pass">Contraseña</label>
                        <el-input id="ftp-pass" type="password" v-model="form.password" autocomplete="new-password"></el-input>
                        <small class="form-control-feedback" v-if="errors.password" v-text="errors.password[0]"></small>
                    </div>
                    <button type="submit" class="system-button system-button-primary" :disabled="loading_upload || !newLastZip">
                        <svg class="sys-icon" aria-hidden="true"><use :href="$icon('send')" /></svg>{{ loading_upload ? 'Enviando…' : 'Enviar último backup' }}
                    </button>
                    <p v-if="!newLastZip" class="system-hint">Genera un backup para poder enviarlo.</p>
                </form>
            </section>
        </div>
    </div>
</template>
<script>
import $ from 'jquery'

export default {
    props: ['storageSize','discUsed', 'lastZip', 'clients'],
    computed: {
        discLabel() {
            return this.cleanSize(this.discUsed)
        },
        storageLabel() {
            return this.cleanSize(this.storageSize)
        }
    },
    data() {
        return {
            newLastZipDate: '',
            headers: null,
            resource: 'backup',
            errors: {},
            form: {},
            loading_submit: false,
            loading_upload: false,
            db: {
                error: '',
                content: '',
                status: '',
            },
            files: {
                error: '',
                content: '',
                status: '',
            },
            newLastZip: '',
            clientError: false,
            folders: ['signed', 'unsigned', 'cdr', 'pdf'],
            formBackups: {
                type: 'todos',
            },
        }
    },
    created() {
        this.initForm();
    },
    methods: {
        onGenerateNnameLastFile() {
            if (this.newLastZip) {
                if (this.newLastZip.date) {
                    this.newLastZipDate = `creado el ${this.newLastZip.date}`;
                }
            }
        },
        clickDownload() {
            window.open(`/${this.resource}/download/${this.newLastZip.name}`, '_blank');
        },
        initForm(){
            this.form = {
                host: null,
                port: null,
                username: null,
                password: null,
            }
            this.newLastZip = this.lastZip;
            this.onGenerateNnameLastFile();
        },
        cleanSize(value) {
            const text = String(value || '').split('\n').map(line => line.trim()).filter(line => line && !/^avail/i.test(line)).join(' ')
            return text || 'Sin datos'
        },
        copy(text) {
            const done = () => this.$message.success('Comando copiado')
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, () => this.$message.warning('Copia el comando manualmente'))
                return
            }
            const area = document.createElement('textarea')
            area.value = text
            area.setAttribute('readonly', '')
            area.style.position = 'fixed'
            area.style.opacity = '0'
            document.body.appendChild(area)
            area.select()
            let copied = false
            try { copied = document.execCommand('copy') } catch (e) { copied = false }
            document.body.removeChild(area)
            copied ? done() : this.$message.warning('Copia el comando manualmente')
        },
        async start() {
            this.clientError = false
            if (this.formBackups.type === 'individual' && !this.formBackups.hostname_id) {
                this.clientError = true
                return
            }
            this.initContent()
            this.loading_submit = true
            this.backupDb()
        },
        initContent() {
            this.db.error = ''
            this.db.content = ''
            this.db.status = false
            this.files.error = ''
            this.files.content = ''
            this.files.status = false
        },
        backupDb() {
            this.$http.post(`/${this.resource}/db`, this.formBackups)
            .then(response => {
                if (response.data !== '') {
                    this.db.content = response.data
                    this.errors = {};
                    if (response.status === 200) {
                        this.db.status = 'success'
                    }
                    this.backupFiles()
                }
            }).catch(error => {
                const status = error.response.status;
                if (status === 422) {
                    this.errors = error.response.data;
                } else if (status !== 200) {
                    this.db.error = error.response.data.message || 'No se pudo generar el backup.'
                    this.db.status = 'false'
                }
                this.loading_submit = false
            });
        },
        backupFiles() {
            this.$http.post(`/${this.resource}/files`, this.formBackups)
                .then(response => {
                    if (response.data !== '') {
                        this.files.content = response.data
                        this.errors = {};
                        if (response.status === 200) {
                            this.files.status = 'success'
                            this.mostRecent()
                        }
                        this.loading_submit = false
                    }
                }).catch(error => {
                    const status = error.response.status;
                    if (status === 422) {
                        this.errors = error.response.data;
                    } else if (status !== 200) {
                        this.db.error = error.response.data.message || 'No se pudieron respaldar los archivos.'
                        this.db.status = 'false'
                    }
                    this.loading_submit = false
                })

        },
        mostRecent(){
            this.$http.get(`/${this.resource}/last-backup`)
                .then(response => {
                    if (response.data !== '') {
                        this.newLastZip = response.data
                        this.loading_submit = false
                        this.onGenerateNnameLastFile();
                    }
                }).catch(error => {
                    if (error.response.status !== 200) {
                        this.files.error = error.response.data.message
                    } else {
                        console.log(error)
                    }
                })
        },
        uploadFtp() {
            this.loading_upload = true
            this.sendFtp()
        },
        sendFtp() {
            this.$http.post(`${this.resource}/upload`, this.form)
                .then(response => {
                    if (response.data.success) {
                        this.$message.success(response.data.message)
                        this.$eventHub.$emit('reloadData')
                        this.loading_upload = false
                        // this.close()
                        this.initForm()
                    } else {
                        this.$message.error(response.data.message)
                    }
                })
                .catch(error => {
                    if (error.response.status === 422) {
                        this.errors = error.response.data
                    }else if(error.response.status === 500){
                        this.$message.error(error.response.data.message);
                    }
                        else {
                        console.log(error.response)
                    }
                })
                .then(()=>{
                    this.loading_upload = false
                })


        }
    }
}
</script>
