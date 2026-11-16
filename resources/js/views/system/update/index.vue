<template>
    <div class="system-dashboard">
        <header class="system-page-heading">
            <div>
                <h1>Actualizaciones</h1>
                <p>Versión instalada e historial de cambios</p>
            </div>
        </header>

        <div class="system-split system-split-even">
            <section class="system-version-card" aria-label="Versión instalada">
                <p class="system-version-label"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('git-branch')" /></svg>Versión instalada</p>
                <strong class="system-version-value" :class="{'is-empty': !versionLabel}">{{ versionLabel || 'Sin información' }}</strong>
                <div class="system-version-tiles">
                    <div><span>Última comprobación</span><strong>{{ checkedAt || 'Sin datos' }}</strong></div>
                    <div><span>Estado</span><strong>{{ statusLabel }}</strong></div>
                </div>
                <div class="system-version-actions">
                    <button type="button" class="system-button system-button-light" :disabled="checking || loading_submit" @click="check()">
                        <svg class="sys-icon" aria-hidden="true"><use :href="$icon('refresh-cw')" /></svg>{{ checking ? 'Comprobando…' : 'Comprobar' }}
                    </button>
                    <button type="button" class="system-button system-button-primary" :disabled="loading_submit" @click.prevent="confirmStart()">
                        <svg class="sys-icon" aria-hidden="true"><use :href="$icon('download')" /></svg>{{ loading_submit ? 'Actualizando…' : 'Iniciar actualización' }}
                    </button>
                </div>
                <p v-if="message" class="system-version-message" role="status">{{ message }}</p>
            </section>

            <section class="system-panel system-panel-pad">
                <h2 class="system-panel-title"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('tag')" /></svg><span>Historial de cambios</span></h2>
                <div v-if="changelog" class="system-changelog" v-html="changelog"></div>
                <div v-else class="system-empty-state">
                    <h2>Sin historial disponible</h2>
                    <p>Aparecerá después de comprobar actualizaciones.</p>
                </div>
            </section>
        </div>

        <section v-if="content.status == true && content.step == 'updating'" id="response-content" class="system-panel system-panel-pad system-update-log" aria-live="polite">
            <h2 class="system-panel-title"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('refresh-cw')" /></svg><span>Progreso de la actualización</span></h2>

            <h3>Obteniendo rama del repositorio</h3>
            <el-progress :percentage="branch.percent"></el-progress>

            <div v-if="branch.status == 'success'">
                <p>Rama actual: <strong class="system-mono">{{ branch.name }}</strong></p>
                <h3>Descargando actualización</h3>
                <pre class="system-code-block">{{ pull.content }}</pre>
            </div>
            <p class="system-inline-alert" v-if="branch.error || pull.error">{{ branch.error || pull.error }}</p>

            <div v-if="pull.status == 'success'">
                <div v-if="pull.updated == false">
                    <template v-if="composer.install.status == 'success'">
                        <h3>Actualizando dependencias</h3>
                        <pre class="system-code-block">{{ composer.install.content }}</pre>
                    </template>
                    <template v-if="artisan.migrate.status == 'success'">
                        <h3>Corriendo migraciones en administrador</h3>
                        <pre class="system-code-block">{{ artisan.migrate.content }}</pre>
                    </template>
                    <template v-if="artisan.tenancy_migrate.status == 'success'">
                        <h3>Corriendo migraciones en cliente</h3>
                        <pre class="system-code-block">{{ artisan.tenancy_migrate.content }}</pre>
                    </template>
                    <template v-if="artisan.clear.status == 'success'">
                        <h3>Eliminando caché</h3>
                        <pre class="system-code-block">{{ artisan.clear.content }}</pre>
                    </template>
                    <p class="system-inline-alert" v-if="composer.install.error || artisan.migrate.error || artisan.tenancy_migrate.error || artisan.clear.error">{{ composer.install.error || artisan.migrate.error || artisan.tenancy_migrate.error || artisan.clear.error }}</p>
                </div>
                <p v-else class="system-inline-ok">El sistema está actualizado.</p>
            </div>
        </section>
    </div>
</template>
<script>
    import $ from 'jquery'

    export default {
        data() {
            return {
                headers: null,
                resource: 'auto-update',
                errors: {},
                form: {},
                loading_submit: false,
                version: '',
                changelog: '',
                checking: false,
                checkedAt: '',
                message: '',
                content: {
                    status: false,
                },
                branch: {
                    name: '',
                    percent: 1,
                    error: '',
                    status: '',
                },
                pull: {
                    error: '',
                    status: '',
                    content: '',
                    updated: '',
                },
                artisan: {
                    error: '',
                    status: '',
                    migrate: {
                        error: '',
                        status: false,
                        content: '',
                    },
                    tenancy_migrate: {
                        error: '',
                        status: false,
                        content: '',
                    },
                    clear: {
                        error: '',
                        status: false,
                        content: '',
                    }
                },
                composer: {
                    install: {
                        error: '',
                        status: false,
                        content: '',
                    },
                    update: {
                        error: '',
                        status: false,
                        content: '',
                    },
                }
            }
        },
        computed: {
            versionLabel() {
                return typeof this.version === 'string' ? this.version.trim() : ''
            },
            statusLabel() {
                if (this.loading_submit) return 'Actualizando'
                if (this.pull.updated === true) return 'Actualizado'
                if (this.branch.error || this.pull.error) return 'Con errores'
                return this.versionLabel ? 'Instalado' : 'Sin información'
            }
        },
        created() {
            this.check()
        },
        methods: {
            check() {
                this.checking = true
                this.message = ''
                return Promise.all([this.getVersion(), this.getChangelog()]).then(() => {
                    this.checkedAt = new Intl.DateTimeFormat('es-PE', {dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Lima'}).format(new Date())
                    this.message = this.versionLabel ? '' : 'No se pudo leer la versión instalada.'
                    this.checking = false
                })
            },
            confirmStart() {
                this.$confirm('El sistema descargará e instalará la última versión disponible. Durante el proceso los clientes pueden experimentar interrupciones.', 'Iniciar actualización', {
                    confirmButtonText: 'Iniciar actualización',
                    cancelButtonText: 'Cancelar',
                    type: 'warning'
                }).then(() => this.start()).catch(() => {})
            },
            async start() {
                this.loading_submit = true
                this.initContent()
                this.content.status = true
                this.content.step = 'updating'
                await this.getBranch()
            },
            initContent() {
                this.content.status= false
                this.content.step= ''
                this.branch.name = ''
                this.branch.percent = 1
                this.branch.error = ''
                this.branch.status = false
                this.pull.error = ''
                this.pull.status = false
                this.pull.updated = false
                this.artisan.error = ''
                this.artisan.status = false
                this.artisan.content = ''
                this.artisan.migrate
                this.artisan.migrate.error = ''
                this.artisan.migrate.status = false
                this.artisan.migrate.content = ''
                this.artisan.tenancy_migrate.error = ''
                this.artisan.tenancy_migrate.status = false
                this.artisan.tenancy_migrate.content = ''
                this.artisan.clear.error = ''
                this.artisan.clear.status = false
                this.artisan.clear.content = ''
                this.composer.install.error = ''
                this.composer.install.status = false
                this.composer.install.content = ''
                this.composer.update.error = ''
                this.composer.update.status = false
                this.composer.update.content = ''
            },
            getVersion() {
                return this.$http.get(`/${this.resource}/version`)
                .then(response => {
                    if (response.data !== '') {
                        this.version = response.data
                    }
                }).catch(() => {
                    this.version = ''
                })
            },
            getChangelog() {
                return this.$http.get(`/${this.resource}/changelog`)
                .then(response => {
                    this.changelog = typeof response.data === 'string' ? response.data : ''
                }).catch(() => {
                    this.changelog = ''
                })
            },
            getBranch() {
                this.branch.percent = 40
                this.$http.get(`/${this.resource}/branch`)
                .then(response => {
                    this.branch.percent = 70
                    if (response.data !== '') {
                        this.branch.name = response.data
                        this.branch.percent = 100
                        if (response.status === 200) {
                            this.branch.status = 'success'
                        }
                        this.execPull()
                    }
                }).catch(error => {
                    if (error.response.status !== 200) {
                        this.branch.percent = 0
                        this.branch.error = error.response.data.message
                        this.branch.status = 'false'
                        this.loading_submit = false
                    } else {
                        console.log(error)
                    }
                })
            },
            execPull() {
                this.$http.get(`/${this.resource}/pull/${this.branch.name}`)
                .then(response => {
                    if (response.data !== '') {
                        this.pull.content = response.data
                        this.pull.percent = 100
                        if (response.status === 200) {
                            this.pull.status = 'success'
                        }
                        let pullContent = this.pull.content
                        if (pullContent.includes('Already up to date.') === true ) {
                            this.loading_submit = false
                            this.pull.updated = true
                        } else {
                            this.execComposer()
                        }
                    }
                }).catch(error => {
                    this.pull.percent = 0
                    this.pull.error = 'no ha podido finalizar'
                    this.pull.status = 'false'
                    this.loading_submit = false
                    console.log(error)
                })
            },
            execComposer() {
                this.$http.get(`/${this.resource}/composer/install`)
                .then(response => {

                    if (response.data !== '') {
                        this.composer.install.content = response.data
                        this.composer.install.percent = 100
                        if (response.status === 200) {
                            this.composer.install.status = 'success'
                            this.execArtisanMigrate()
                        }
                    }
                }).catch(error => {
                    if (error.response.status !== 200) {
                        this.composer.install.percent = 0
                        this.composer.install.error = error.response.data.message
                        this.composer.install.status = 'false'
                    } else {
                        console.log(error)
                    }
                    this.loading_submit = false
                })
            },
            execArtisanMigrate() {
                this.$http.get(`/${this.resource}/artisan/migrate`)
                .then(response => {
                    if (response.data !== '') {
                        this.artisan.migrate.content = response.data
                        this.artisan.migrate.percent = 100
                        if (response.status === 200) {
                            this.artisan.migrate.status = 'success'
                            this.execArtisanMigrateTenant()
                        }
                    }
                }).catch(error => {
                    this.loading_submit = false
                    if (error.response.status !== 200) {
                        this.artisan.migrate.percent = 0
                        this.artisan.migrate.error = error.response.data.message
                        this.artisan.migrate.status = 'false'
                    } else {
                        console.log(error)
                    }
                })
            },
            execArtisanMigrateTenant() {
                this.$http.get(`/${this.resource}/artisan/migrate/tenant`)
                .then(response => {
                    if (response.data !== '') {
                        this.artisan.tenancy_migrate.content = response.data
                        this.artisan.tenancy_migrate.percent = 100
                        if (response.status === 200) {
                            this.artisan.tenancy_migrate.status = 'success'
                            this.execArtisanClear()
                        }
                    }
                }).catch(error => {
                    this.loading_submit = false
                    this.artisan.tenancy_migrate.percent = 0
                    this.artisan.tenancy_migrate.error = error
                    this.artisan.tenancy_migrate.status = false
                    console.log(error)
                })
            },
            execArtisanClear() {
                this.$http.get(`/${this.resource}/artisan/clear`)
                .then(response => {
                    if (response.data !== '') {
                        this.artisan.clear.content = response.data
                        this.artisan.clear.percent = 100
                        if (response.status === 200) {
                            this.artisan.clear.status = 'success'
                            this.loading_submit = false
                            this.getVersion()
                        }
                    }
                }).catch(error => {
                    this.loading_submit = false
                    this.artisan.clear.percent = 0
                    this.artisan.clear.error = error
                    this.artisan.clear.status = false
                    console.log(error)
                })
            }
        }
    }
</script>
