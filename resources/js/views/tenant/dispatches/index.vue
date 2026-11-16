<template>
    <div>
        <div class="page-header pr-0">
            <h2><a href="/dashboard"><i class="fas fa-tachometer-alt"></i></a></h2>
            <ol class="breadcrumbs">
                <li class="active"><span>Guias de remisión</span></li>
            </ol>
            <div class="right-wrapper pull-right">
                <a :href="`/${resource}/create`" class="btn btn-custom btn-sm  mt-2 mr-2"><i class="fa fa-plus-circle"></i> Nuevo</a>
                <a href="#" @click.prevent="showModalGenerateCPE = true" class="btn btn-custom btn-sm  mt-2 mr-2">Generar comprobante desde múltiples guías</a>
            </div>
        </div>
        <div class="card mb-0">
            <div class="card-body">
                <data-table :resource="resource">
                    <tr slot="heading">
                        <th>#</th>
                        <th class="text-center">Fecha Emisión</th>
                        <th>Cliente</th>
                        <th>Número</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th class="text-center">Fecha Envío</th>
                        <th class="text-center">Descargas</th>
                        <th class="text-center">Acciones</th>
                    <tr>
                    <tr slot-scope="{ index, row }" :class="{'text-danger': (row.state_type_id === '11')}">
                        <td>{{ index }}</td>
                        <td class="text-center">{{ row.date_of_issue }}</td>
                        <td>{{ row.customer_name }} <br /> <small>{{ row.customer_number }}</small></td>
                        <td>{{ row.number }}</td>
                        <td><small>{{ row.document_type_description }}</small></td>
                        <td>
                            <span class="badge bg-secondary text-white" :class="{'bg-secondary': (row.state_type_id === '01'), 'bg-info': (row.state_type_id === '03'), 'bg-success': (row.state_type_id === '05'), 'bg-secondary': (row.state_type_id === '07'), 'bg-dark': (row.state_type_id === '09')}">{{row.state_type_description}}</span>
                            <br v-if="row.sunat_message && ['01', '09'].includes(row.state_type_id)">
                            <small class="text-muted" v-if="row.sunat_message && ['01', '09'].includes(row.state_type_id)">{{ row.sunat_message }}</small>
                        </td>
                        <td class="text-center">{{ row.date_of_shipping }}</td>
                        <td class="text-center">
                            <button type="button" class="btn waves-effect waves-light btn-xs btn-info" @click.prevent="clickDownload(row.download_external_xml)">XML</button>
                            <button type="button" class="btn waves-effect waves-light btn-xs btn-info" @click.prevent="clickDownload(row.download_external_pdf)">PDF</button>
                            <button type="button" class="btn waves-effect waves-light btn-xs btn-info" @click.prevent="clickDownload(row.download_external_cdr)" v-if="row.has_cdr">CDR</button>
                        </td>
                        <td class="text-center">
                            <button v-if="!row.reference_document_id" type="button" class="btn waves-effect waves-light btn-xs btn-info" @click.prevent="onGenerateDocument(row.id)">Generar comprobante</button>
                            <button v-if="row.state_type_id === '01' && row.series && ((row.document_type_id === '31' && row.series[0] === 'V') || (row.document_type_id !== '31' && row.series[0] === 'T'))" type="button" class="btn waves-effect waves-light btn-xs btn-primary" :disabled="loadingId === row.id" @click.prevent="sendToSunat(row.id)">
                                <i class="fa fa-spinner fa-spin" v-if="loadingId === row.id"></i> Enviar a SUNAT
                            </button>
                            <button v-if="row.state_type_id === '03' && row.ticket" type="button" class="btn waves-effect waves-light btn-xs btn-warning" :disabled="loadingId === row.id" @click.prevent="checkStatus(row.id)">
                                <i class="fa fa-spinner fa-spin" v-if="loadingId === row.id"></i> Consultar estado
                            </button>
                            <button type="button" class="btn waves-effect waves-light btn-xs btn-info" @click.prevent="clickOptions(row.id)">Opciones</button>
                        </td>
                    </tr>
                </data-table>
            </div>
        </div>
            <dispatch-options :showDialog.sync="showDialogOptions"
                              :recordId="recordId"
                              :showClose="true"></dispatch-options>

        <FormGenerateDocument
            :showDialog.sync="showDialogGenerateDocument"
            :recordId="recordId"
            :showClose="true"
            :showGenerate="true"
        ></FormGenerateDocument>
        <ModalGenerateCPE :show.sync="showModalGenerateCPE"></ModalGenerateCPE>
    </div>
</template>

<script>
    import DataTable from '../../../components/DataTable.vue'
    import DispatchOptions from './partials/options.vue'
    import FormGenerateDocument from "./generate-document";
    import ModalGenerateCPE from './ModalGenerateCPE';

    export default {
        components: {DataTable, DispatchOptions, FormGenerateDocument, ModalGenerateCPE},
        data() {
            return {
                resource: 'dispatches',
                showDialogOptions: false,
                recordId: null,
                showDialogGenerateDocument: false,
                showModalGenerateCPE: false,
                loadingId: null,
            }
        },
        methods: {
            sendToSunat(id) {
                this.callSunat(`/${this.resource}/send/${id}`, id)
            },
            checkStatus(id) {
                this.callSunat(`/${this.resource}/status/${id}`, id)
            },
            callSunat(url, id) {
                this.loadingId = id
                this.$http.post(url)
                    .then(response => {
                        const data = response.data
                        this.$message({
                            message: data.message,
                            type: data.success ? 'success' : 'warning',
                            duration: 10000,
                            showClose: true
                        })
                        this.$eventHub.$emit('reloadData')
                    })
                    .catch(error => {
                        const message = (error.response && error.response.data && error.response.data.message) || 'No se pudo completar la operación.'
                        this.$message.error(message)
                    })
                    .then(() => {
                        this.loadingId = null
                    })
            },
            onGenerateDocument(dispatchId) {
                this.recordId = dispatchId;
                this.showDialogGenerateDocument = true;
            },
            clickOptions(recordId = null) {
                this.recordId = recordId
                this.showDialogOptions = true
            },
            clickDownload(download) {
                window.open(download, '_blank');
            },
            clickPrint(external_id){
                window.open(`/print/dispatch/${external_id}/a4`, '_blank');
            },
        }
    }
</script>
