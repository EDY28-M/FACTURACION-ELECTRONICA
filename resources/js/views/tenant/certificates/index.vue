<template>
    <div v-if="embedded" class="co-cert">
        <div class="co-cert-text">
            <span class="co-group-title">Certificado digital</span>
            <small class="co-help" v-if="record">{{ record }}</small>
            <small class="co-help" v-else-if="config_system_env">Sube tu certificado (.pfx o .p12) para firmar tus comprobantes.</small>
            <small class="co-help" v-else>Lo habilita el administrador del sistema.</small>
        </div>
        <el-button v-if="record" :disabled="!config_system_env" type="danger" size="small" @click="clickDelete">Eliminar</el-button>
        <el-button v-else :disabled="!config_system_env" type="primary" size="small" icon="el-icon-plus" @click="clickCreate">Subir certificado</el-button>
        <certificates-form :showDialog.sync="showDialog"
                           :recordId="recordId"></certificates-form>
    </div>
    <div v-else class="card">
        <div class="card-header bg-info">
            <h3 class="my-0">Certificado</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive" v-if="record">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Archivo</th>
                        <th class="text-right">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td>{{ record }}</td>
                        <td class="text-right">
                            <button type="button" class="btn waves-effect waves-light btn-xs btn-danger"
                                    :disabled="!config_system_env"
                                    @click.prevent="clickDelete">Eliminar</button>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div class="row" v-else>
                <div class="col-md-12 text-center">
                    <el-button  :disabled="!config_system_env" type="primary" icon="el-icon-plus" @click="clickCreate">Subir</el-button>
                </div>
            </div>
        </div>
        <certificates-form :showDialog.sync="showDialog"
                           :recordId="recordId"></certificates-form>
    </div>
</template>

<script>

    import CertificatesForm from './form.vue'
    import {deletable} from '../../../mixins/deletable'

    export default {
        mixins: [deletable],
        components: {CertificatesForm},
        props: {
            embedded: { type: Boolean, default: false },
        },
        data() {
            return {
                showDialog: false,
                resource: 'certificates',
                recordId: null,
                record: {},
                config_system_env: false
            }
        },
        created() {
            this.$eventHub.$on('reloadData', () => {
                this.getData()
            })
            this.getData()
        },
        methods: {
            getData() {
                this.$http.get(`/${this.resource}/record`)
                    .then(response => {
                        this.record = response.data.certificate
                        this.config_system_env = response.data.config_system_env
                    })
            },
            clickCreate() {
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

<style>
.co-cert {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 16px;
    margin-top: 4px;
    border: 1px solid var(--tenant-hairline, #e6e9f0);
    border-radius: 12px;
}
.co-cert-text { min-width: 0; }
.co-cert-text .co-help { display: block; margin-top: 2px; color: var(--tenant-muted, #6b7489); word-break: break-all; }
</style>
