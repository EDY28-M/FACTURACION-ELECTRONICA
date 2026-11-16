<template>
    <div class="system-dashboard">
        <header class="system-page-heading">
            <div>
                <h1>Configuración</h1>
                <p>Parámetros globales del sistema</p>
            </div>
            <p class="system-progress" role="status">
                <span class="system-progress-bars" aria-hidden="true"><i v-for="n in 3" :key="n" :class="{'is-on': n <= readyCount}"></i></span>
                {{ readyCount }} de 3 integraciones listas
            </p>
        </header>

        <div class="system-config-layout">
        <div class="system-tile-row" role="tablist" aria-label="Secciones de configuración">
            <button v-for="section in sections" :key="section.key" type="button" role="tab" class="system-tile"
                    :id="'config-tab-' + section.key" :aria-selected="String(current === section.key)" :aria-controls="'config-panel-' + section.key" :tabindex="current === section.key ? 0 : -1"
                    :class="{'is-selected': current === section.key}" @click="current = section.key" @keydown.right.prevent="move(1)" @keydown.left.prevent="move(-1)" @keydown.home.prevent="moveTo(0)" @keydown.end.prevent="moveTo(sections.length - 1)">
                <span class="system-tile-icon"><svg class="sys-icon" aria-hidden="true"><use :href="$icon(section.icon)" /></svg></span>
                <span class="system-tile-copy"><strong>{{ section.title }}</strong><small>{{ section.subtitle }}</small></span>
                <span class="system-status" :class="'is-' + state(section.key).tone"><i class="system-dot"></i>{{ state(section.key).label }}</span>
            </button>
        </div>

        <section class="system-panel system-config-panel">
            <header class="system-config-head">
                <span class="system-tile-icon"><svg class="sys-icon" aria-hidden="true"><use :href="$icon(active.icon)" /></svg></span>
                <div>
                    <h2>{{ active.title }}</h2>
                    <p>{{ active.description }}</p>
                </div>
                <span class="system-status" :class="'is-' + state(current).tone"><i class="system-dot"></i>{{ state(current).label }}</span>
            </header>
            <div class="system-config-main">
            <div class="system-config-body">
                <div v-show="current === 'certificate'" id="config-panel-certificate" role="tabpanel" aria-labelledby="config-tab-certificate">
                    <system-certificate-index @status="merge"></system-certificate-index>
                </div>
                <div v-show="current === 'culqi'" id="config-panel-culqi" role="tabpanel" aria-labelledby="config-tab-culqi">
                    <system-configuration-culqi @status="merge"></system-configuration-culqi>
                </div>
                <div v-show="current === 'ruc'" id="config-panel-ruc" role="tabpanel" aria-labelledby="config-tab-ruc">
                    <system-configuration-token @status="merge"></system-configuration-token>
                </div>
                <div v-show="current === 'login'" id="config-panel-login" role="tabpanel" aria-labelledby="config-tab-login">
                    <system-login-settings :configuration="configuration" @status="merge"></system-login-settings>
                </div>
            </div>
            <aside class="system-config-aside" aria-label="Estado de la integración">
                <h3 class="system-aside-title">Estado</h3>
                <ul class="system-check-list">
                    <li v-for="item in checklist" :key="item.label" :class="{'is-done': item.done}">
                        <svg class="sys-icon" aria-hidden="true"><use :href="$icon(item.done ? 'circle-check' : 'circle-dashed')" /></svg>
                        <span>{{ item.label }}</span>
                        <b v-if="item.value" class="system-mono" :title="item.value">{{ item.value }}</b>
                        <em v-else>{{ item.done ? 'Listo' : 'Pendiente' }}</em>
                    </li>
                </ul>
                <h3 class="system-aside-title">Para qué sirve</h3>
                <p class="system-aside-text">{{ active.help }}</p>
            </aside>
            </div>
        </section>
        </div>
    </div>
</template>

<script>
    export default {
        props: ['configuration'],
        data() {
            const config = (this.configuration && this.configuration.status) || {}
            return {
                current: 'certificate',
                status: {
                    certificate: !!config.certificate,
                    soap_user: !!config.soap_user,
                    culqi_public: !!config.culqi_public,
                    culqi_private: !!config.culqi_private,
                    ruc: !!config.ruc,
                    ruc_partial: !!config.ruc_partial,
                    login: !!(this.configuration && this.configuration.use_login_global),
                    certificate_file: '',
                    ruc_internal: false
                },
                sections: [
                    {key: 'certificate', icon: 'file-key-2', help: 'El certificado .pfx firma digitalmente cada comprobante. El usuario secundario SUNAT se usa para autenticar el envío de esos comprobantes a SUNAT.', title: 'Certificado PSE y SUNAT', subtitle: 'Firma y envío a SUNAT', description: 'Certificado digital y usuario secundario que se usan para firmar y enviar comprobantes.'},
                    {key: 'culqi', icon: 'credit-card', help: 'Culqi es la pasarela de pagos. Aquí se guardan las llaves de tu cuenta; la llave privada es secreta y no debe compartirse.', title: 'Culqi', subtitle: 'Pasarela de pagos', description: 'Credenciales de la pasarela de pagos Culqi.'},
                    {key: 'ruc', icon: 'search-check', help: 'Al registrar clientes, el sistema consulta el RUC o DNI para completar sus datos. Puedes usar la consulta interna o un servicio externo con URL y token.', title: 'Consulta RUC/DNI', subtitle: 'Validación de documentos', description: 'Servicio que valida RUC y DNI al registrar clientes.'},
                    {key: 'login', icon: 'shield-check', help: 'Si usas la configuración global, todos los clientes ven la misma apariencia en su pantalla de acceso: fondo, logo y redes sociales.', title: 'Acceso de clientes', subtitle: 'Login de clientes', description: 'Configuración global para el login de los clientes.'}
                ]
            }
        },
        computed: {
            active() {
                return this.sections.find(section => section.key === this.current)
            },
            checklist() {
                const s = this.status
                if (this.current === 'certificate') return [
                    {label: 'Certificado .pfx', done: s.certificate, value: s.certificate_file || ''},
                    {label: 'Usuario secundario SUNAT', done: s.soap_user}
                ]
                if (this.current === 'culqi') return [
                    {label: 'Token público', done: s.culqi_public},
                    {label: 'Token privado', done: s.culqi_private}
                ]
                if (this.current === 'ruc') return [
                    {label: s.ruc_internal ? 'Consulta interna' : 'Servicio externo (URL y token)', done: s.ruc}
                ]
                return [{label: 'Login global de clientes', done: s.login}]
            },
            readyCount() {
                return ['certificate', 'culqi', 'ruc'].filter(key => this.state(key).tone === 'ok').length
            }
        },
        methods: {
            merge(patch) {
                this.status = Object.assign({}, this.status, patch)
            },
            moveTo(index) {
                this.current = this.sections[index].key
                this.$nextTick(() => document.getElementById('config-tab-' + this.current).focus())
            },
            move(step) {
                const index = this.sections.findIndex(section => section.key === this.current)
                this.current = this.sections[(index + step + this.sections.length) % this.sections.length].key
                this.$nextTick(() => document.getElementById('config-tab-' + this.current).focus())
            },
            state(key) {
                const s = this.status
                if (key === 'certificate') {
                    if (s.certificate && s.soap_user) return {tone: 'ok', label: 'Configurado'}
                    return s.certificate || s.soap_user ? {tone: 'warn', label: 'Incompleto'} : {tone: 'off', label: 'Sin configurar'}
                }
                if (key === 'culqi') {
                    if (s.culqi_public && s.culqi_private) return {tone: 'ok', label: 'Configurado'}
                    return s.culqi_public || s.culqi_private ? {tone: 'warn', label: 'Incompleto'} : {tone: 'off', label: 'Sin configurar'}
                }
                if (key === 'ruc') {
                    if (s.ruc) return {tone: 'ok', label: 'Configurado'}
                    return s.ruc_partial ? {tone: 'warn', label: 'Incompleto'} : {tone: 'off', label: 'Pendiente'}
                }
                return s.login ? {tone: 'ok', label: 'Activado'} : {tone: 'off', label: 'Desactivado'}
            }
        }
    }
</script>
