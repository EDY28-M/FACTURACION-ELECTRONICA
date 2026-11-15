<template>
    <div>
        <div class="page-header pr-0">
            <h2><a href="/dashboard"><i class="fas fa-tachometer-alt"></i></a></h2>
            <ol class="breadcrumbs">
                <li class="active"><span>Compras</span></li>
            </ol>
            <div class="right-wrapper pull-right">
                <a :href="`/${resource}/create`" class="btn btn-custom btn-sm  mt-2 mr-2"><i class="fa fa-plus-circle"></i> Nuevo</a>
                <button   @click.prevent="clickImport()" type="button" class="btn btn-custom btn-sm  mt-2 mr-2" ><i class="fa fa-upload"></i> Importar</button>
                <button @click.prevent="openBulkValidation()" type="button" class="btn btn-custom btn-sm mt-2 mr-2"><i class="fa fa-check-circle"></i> Validar compras del mes</button>

            </div>
        </div>
        <div class="card mb-0">
            <div class="data-table-visible-columns">
                <el-dropdown :hide-on-click="false">
                    <el-button type="primary">
                        Mostrar/Ocultar columnas<i class="el-icon-arrow-down el-icon--right"></i>
                    </el-button>
                    <el-dropdown-menu slot="dropdown">
                        <el-dropdown-item v-for="(column, index) in columns" :key="index">
                            <el-checkbox v-model="column.visible">{{ column.title }}</el-checkbox>
                        </el-dropdown-item>
                    </el-dropdown-menu>
                </el-dropdown>
            </div>
            <div class="card-body">
                <data-table :resource="resource">
                    <tr slot="heading">
                        <th>#</th>
                        <th class="text-center">F. Emisión</th>
                        <th class="text-center" v-if="columns.date_of_due.visible" >F. Vencimiento</th>
                        <th>Proveedor</th>
                        <th>Estado</th>
                        <th>Estado de pago</th>
                        <th>SUNAT</th>
                        <th>Número</th>
                        <th>Productos</th>
                        <th>Pagos</th>
                        <!-- <th>F. Pago</th> -->
                        <!-- <th>Estado</th> -->
                        <th class="text-center">Moneda</th>
                        <!-- <th class="text-right">T.Exportación</th> -->
                        <th v-if="columns.total_free.visible"  class="text-right">T.Gratuita</th>
                        <th v-if="columns.total_unaffected.visible" class="text-right">T.Inafecta</th>
                        <th v-if="columns.total_exonerated.visible" class="text-right">T.Exonerado</th>
                        <th v-if="columns.total_taxed.visible" class="text-right">T.Gravado</th>
                        <th v-if="columns.total_igv.visible" class="text-right">T.Igv</th>
                        <th v-if="columns.total_perception.visible" >Percepcion</th>
                        <th class="text-right">Total</th>
                        <!-- <th class="text-center">Descargas</th> -->
                        <th class="text-right">Acciones</th>
                    <tr>
                    <tr slot-scope="{ index, row }">
                        <td>{{ index }}</td>
                        <td class="text-center">{{ row.date_of_issue }}</td>
                        <td v-if="columns.date_of_due.visible" class="text-center">{{ row.date_of_due }}</td>
                        <td>{{ row.supplier_name }}<br/><small v-text="row.supplier_number"></small></td>
                        <td>{{row.state_type_description}}</td>
                        <td>{{row.state_type_payment_description}}</td>
                        <td>
                            <el-tooltip v-if="row.sunat_validation_status" placement="top" effect="dark">
                                <div slot="content" style="max-width: 280px">
                                    <div v-if="row.sunat_validation_detail && row.sunat_validation_detail.estadoRucLabel">Estado RUC: {{ row.sunat_validation_detail.estadoRucLabel }}</div>
                                    <div v-if="row.sunat_validation_detail && row.sunat_validation_detail.condDomiRucLabel">Condición: {{ row.sunat_validation_detail.condDomiRucLabel }}</div>
                                    <div v-if="row.sunat_validation_detail && row.sunat_validation_detail.message">{{ row.sunat_validation_detail.message }}</div>
                                    <div v-for="(obs, i) in ((row.sunat_validation_detail && row.sunat_validation_detail.observaciones) || [])" :key="i">{{ obs }}</div>
                                    <div>Validado: {{ row.sunat_validated_at }}</div>
                                </div>
                                <span class="badge" :class="sunatBadgeClass(row.sunat_validation_status)">{{ row.sunat_validation_label }}</span>
                            </el-tooltip>
                            <span v-else class="text-muted">&mdash;</span>
                        </td>
                        <td>{{ row.number }}<br/>
                            <small v-text="row.document_type_description"></small><br/>
                        </td>
                        <td>

                            <el-popover
                                placement="right"
                                width="400"
                                trigger="click">
                                <el-table :data="row.items">
                                    <el-table-column width="80" property="key" label="#"></el-table-column>
                                    <el-table-column width="220" property="description" label="Nombre"></el-table-column>
                                    <el-table-column width="90" property="quantity" label="Cantidad"></el-table-column>
                                </el-table>
                                <el-button slot="reference"> <i class="fa fa-eye"></i></el-button>
                            </el-popover>

                        </td>
                        <!-- <td>{{ row.payment_method_type_description }}</td> -->
                        <!-- <td>
                            <template v-for="(it,ind) in row.payments">
                                {{it.payment_method_type_description}} - {{it.payment}}
                            </template>
                        </td> -->
                        <!-- <td>{{ row.state_type_description }}</td> -->
                        <td class="text-right">
                            <button
                                v-if="row.state_type_id != '11'"
                                type="button"
                                style="min-width: 41px"
                                class="btn waves-effect waves-light btn-xs btn-info m-1__2"
                                @click.prevent="clickPurchasePayment(row.id)"
                            >Pagos</button>
                        </td>

                        <td class="text-center">{{ row.currency_type_id }}</td>
                        <!-- <td class="text-right">{{ row.total_exportation }}</td> -->
                        <td v-if="columns.total_free.visible" class="text-right">{{ row.total_free }}</td>
                        <td v-if="columns.total_unaffected.visible" class="text-right">{{ row.total_unaffected }}</td>
                        <td v-if="columns.total_exonerated.visible" class="text-right">{{ row.total_exonerated }}</td>
                        <td v-if="columns.total_taxed.visible" class="text-right">{{ row.total_taxed }}</td>
                        <td v-if="columns.total_igv.visible" class="text-right">{{ row.total_igv }}</td>
                        <td v-if="columns.total_perception.visible" class="text-right">{{ row.total_perception ? row.total_perception : 0 }}</td>
                        <td class="text-right">{{ row.total   }}</td>
                        <td class="text-right">

                            <a v-if="row.state_type_id != '11'" :href="`/${resource}/edit/${row.id}`" type="button" class="btn waves-effect waves-light btn-xs btn-info">Editar</a>
                            <button v-if="row.state_type_id != '11'" type="button" class="btn waves-effect waves-light btn-xs btn-danger" @click.prevent="clickAnulate(row.id)">Anular</button>
                            <button v-if="row.state_type_id == '11'" type="button" class="btn waves-effect waves-light btn-xs btn-danger" @click.prevent="clickDelete(row.id)">Eliminar</button>
                            <button v-if="row.state_type_id != '11'" type="button" class="btn waves-effect waves-light btn-xs btn-secondary" :disabled="validatingId === row.id" @click.prevent="clickValidateSunat(row.id)"><i class="fa fa-spinner fa-spin" v-if="validatingId === row.id"></i> Validar en SUNAT</button>
                            <button  type="button" class="btn waves-effect waves-light btn-xs btn-primary" @click.prevent="clickOptions(row.id)">Opciones</button>



                        </td>

                        <!-- <td class="text-right">
                            <button type="button" class="btn waves-effect waves-light btn-xs btn-danger"
                                    @click.prevent="clickVoided(row.id)"
                                    v-if="row.btn_voided">Anular</button>
                            <a :href="`/${resource}/note/${row.id}`" class="btn waves-effect waves-light btn-xs btn-warning"
                               v-if="row.btn_note">Nota</a>
                            <button type="button" class="btn waves-effect waves-light btn-xs btn-info"
                                    @click.prevent="clickResend(row.id)"
                                    v-if="row.btn_resend">Reenviar</button>
                            <button type="button" class="btn waves-effect waves-light btn-xs btn-info"
                                    @click.prevent="clickConsultCdr(row.id)"
                                    v-if="row.btn_consult_cdr">Consultar CDR</button>
                            <button type="button" class="btn waves-effect waves-light btn-xs btn-info"
                                    @click.prevent="clickOptions(row.id)">Opciones</button>
                        </td> -->
                    </tr>
                </data-table>
            </div>

            <!-- <documents-voided :showDialog.sync="showDialogVoided"
                            :recordId="recordId"></documents-voided>

            <document-options :showDialog.sync="showDialogOptions"
                              :recordId="recordId"
                              :showClose="true"></document-options> -->

            <purchase-import :showDialog.sync="showImportDialog"></purchase-import>
        </div>

        
        <purchase-payments
            :showDialog.sync="showDialogPurchasePayments"
            :purchaseId="recordId"
            :external="true"
            ></purchase-payments>
            
        <purchase-options :showDialog.sync="showDialogOptions"
                          :recordId="recordId"
                          :showClose="true"></purchase-options>

        <el-dialog title="Validar compras del mes en SUNAT" :visible.sync="showBulkDialog" width="460px"
                   :close-on-click-modal="false" :before-close="closeBulkDialog" append-to-body>
            <div class="form-group">
                <label class="control-label">Periodo</label><br/>
                <el-date-picker v-model="bulk.period" type="month" value-format="yyyyMM" format="MM/yyyy"
                                placeholder="Seleccione el mes" :clearable="false" :disabled="bulk.running"></el-date-picker>
            </div>
            <el-checkbox v-model="bulk.onlyPending" :disabled="bulk.running">Solo compras pendientes de validar</el-checkbox>
            <div class="mt-3" v-if="bulk.started">
                <el-progress :percentage="bulkPercentage" :status="bulk.error ? 'exception' : (bulk.running ? null : 'success')"></el-progress>
                <div class="mt-2 text-muted"><small>Validadas: {{ bulk.processed }}<span v-if="bulk.total > 0"> de {{ bulk.total }}</span></small></div>
                <div class="mt-1" v-if="bulkSummary"><small>{{ bulkSummary }}</small></div>
                <div class="mt-2 text-danger" v-if="bulk.error"><small>{{ bulk.error }}</small></div>
            </div>
            <span slot="footer" class="dialog-footer">
                <el-button @click="closeBulkDialog" :disabled="bulk.running">Cerrar</el-button>
                <el-button type="primary" :loading="bulk.running" :disabled="!bulk.period" @click="runBulkValidation">Validar</el-button>
            </span>
        </el-dialog>
    </div>
</template>

<script>

    // import DocumentsVoided from './partials/voided.vue'
    // import DocumentOptions from './partials/options.vue'
    import DataTable from '../../../components/DataTable.vue'
    import {deletable} from '../../../mixins/deletable'
    import PurchaseImport from './import.vue'
    import PurchasePayments from '@viewsModulePurchase/purchase_payments/payments.vue'
    import PurchaseOptions from './partials/options.vue'


    export default {
        mixins: [deletable],
        // components: {DocumentsVoided, DocumentOptions, DataTable},
        components: {DataTable, PurchaseImport, PurchasePayments, PurchaseOptions},
        data() {
            return {
                showDialogOptions: false,
                showDialogVoided: false,
                resource: 'purchases',
                recordId: null,
                showDialogOptions: false,
                showDialogPurchasePayments: false,
                showImportDialog: false,
                validatingId: null,
                showBulkDialog: false,
                bulk: {
                    period: null,
                    onlyPending: true,
                    running: false,
                    started: false,
                    processed: 0,
                    total: 0,
                    byStatus: {},
                    error: null,
                },
                columns: {
                    date_of_due: {
                        title: 'F. Vencimiento',
                        visible: false
                    },
                    total_free: {
                        title: 'T.Gratuita',
                        visible: false
                    },
                    total_unaffected: {
                        title: 'T.Inafecta',
                        visible: false
                    },
                    total_exonerated: {
                        title: 'T.Exonerado',
                        visible: false
                    },
                    total_taxed: {
                        title: 'T.Gravado',
                        visible: false
                    },
                    total_igv: {
                        title: 'T.Igv',
                        visible: false
                    },
                    total_perception:{
                        title: 'Percepcion',
                        visible: false
                    }

                }
            }
        },
        created() {
        },
        computed: {
            bulkPercentage() {
                if (!this.bulk.total) {
                    return this.bulk.running ? 0 : 100
                }
                return Math.min(100, Math.round(this.bulk.processed * 100 / this.bulk.total))
            },
            bulkSummary() {
                const labels = {
                    aceptado: 'Aceptado', anulado: 'Anulado', no_existe: 'No existe', autorizado: 'Autorizado',
                    no_autorizado: 'No autorizado', no_aplica: 'No aplica', error: 'Error'
                }
                return Object.keys(this.bulk.byStatus)
                    .map(key => `${labels[key] || key}: ${this.bulk.byStatus[key]}`)
                    .join(' | ')
            },
        },
        methods: {
            sunatBadgeClass(status) {
                return {
                    aceptado: 'badge-success',
                    anulado: 'badge-danger',
                    no_existe: 'badge-danger',
                    no_autorizado: 'badge-danger',
                    autorizado: 'badge-info',
                }[status] || 'badge-secondary'
            },
            errorMessage(error) {
                return (error.response && error.response.data && error.response.data.message)
                    || 'No se pudo completar la operación. Intente nuevamente.'
            },
            clickValidateSunat(id) {
                this.validatingId = id
                this.$http.post(`/${this.resource}/${id}/validate-sunat`)
                    .then(response => {
                        this.$message[response.data.validation && response.data.validation.status === 'no_aplica' ? 'warning' : 'success'](response.data.message)
                        this.$eventHub.$emit('reloadData')
                    })
                    .catch(error => this.$message.error(this.errorMessage(error)))
                    .then(() => { this.validatingId = null })
            },
            openBulkValidation() {
                if (!this.bulk.period) {
                    const now = new Date()
                    this.bulk.period = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}`
                }
                this.showBulkDialog = true
            },
            closeBulkDialog() {
                if (this.bulk.running) {
                    return
                }
                this.showBulkDialog = false
            },
            async runBulkValidation() {
                this.bulk.running = true
                this.bulk.started = true
                this.bulk.processed = 0
                this.bulk.total = 0
                this.bulk.byStatus = {}
                this.bulk.error = null

                let afterId = null
                try {
                    while (true) {
                        const response = await this.$http.post(`/${this.resource}/validate-sunat-period`, {
                            period: this.bulk.period,
                            only_pending: this.bulk.onlyPending,
                            after_id: afterId,
                        })
                        const data = response.data
                        this.bulk.processed += data.processed
                        this.bulk.total = this.bulk.processed + data.remaining
                        Object.keys(data.by_status).forEach(key => {
                            this.$set(this.bulk.byStatus, key, (this.bulk.byStatus[key] || 0) + data.by_status[key])
                        })
                        afterId = data.next_after_id

                        if (data.stopped) {
                            this.bulk.error = data.message
                            break
                        }
                        if (!data.remaining || !data.processed) {
                            break
                        }
                    }
                } catch (error) {
                    // The server reports the progress stored before the failure: keep those counters.
                    const data = (error.response && error.response.data) || {}
                    if (data.processed) {
                        this.bulk.processed += data.processed
                        Object.keys(data.by_status || {}).forEach(key => {
                            this.$set(this.bulk.byStatus, key, (this.bulk.byStatus[key] || 0) + data.by_status[key])
                        })
                    }
                    this.bulk.error = this.errorMessage(error)
                    this.$message.error(this.bulk.error)
                }
                this.bulk.running = false
                this.$eventHub.$emit('reloadData')
            },
            clickPurchasePayment(recordId) {
                this.recordId = recordId;
                this.showDialogPurchasePayments = true
            },
            clickVoided(recordId = null) {
                this.recordId = recordId
                this.showDialogVoided = true
            },
            clickDownload(download) {
                window.open(download, '_blank');
            },
            clickOptions(recordId = null) {
                this.recordId = recordId
                this.showDialogOptions = true
            },
            clickAnulate(id)
            {
                this.anular(`/${this.resource}/anular/${id}`).then(() =>
                    this.$eventHub.$emit('reloadData')
                )
            },
            clickDelete(id)
            {
                this.delete(`/${this.resource}/delete/${id}`).then(() =>
                    this.$eventHub.$emit('reloadData')
                )
            },
             clickImport() {
                this.showImportDialog = true
            },
        }
    }
</script>
