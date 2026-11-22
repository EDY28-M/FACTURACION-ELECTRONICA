<template>
    <div>
        <div class="page-header pr-0">
            <h2><a href="#"><i class="fas fa-cogs"></i></a></h2>
            <ol class="breadcrumbs">
                <li class="active"><span>Configuración</span> </li>
                <li><span class="text-muted">Avanzado</span></li>
            </ol>
        </div>
        <div class="card cfg-card">
            <div class="card-header bg-info">
                <h3 class="my-0">Configuraciones</h3>
            </div>
            <div class="card-body">
                <el-tabs v-model="activeTab" class="cfg-tabs">
                    <el-tab-pane label="Servicios" name="services">
                        <div class="cfg-row" :class="{'has-danger': errors.send_auto}">
                            <div class="cfg-text">
                                <span class="cfg-label">Envío de comprobantes automático</span>
                                <small class="cfg-help">Envía a SUNAT cada comprobante al emitirlo.</small>
                                <small class="form-control-feedback" v-if="errors.send_auto" v-text="errors.send_auto[0]"></small>
                            </div>
                            <el-switch v-model="form.send_auto" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" v-if="typeUser != 'integrator'" :class="{'has-danger': errors.cron}">
                            <div class="cfg-text">
                                <span class="cfg-label">Crontab</span>
                                <small class="cfg-help">Ejecuta las tareas programadas del sistema.</small>
                                <small class="form-control-feedback" v-if="errors.cron" v-text="errors.cron[0]"></small>
                            </div>
                            <el-switch v-model="form.cron" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" v-if="typeUser != 'integrator'" :class="{'has-danger': errors.sunat_alternate_server}">
                            <div class="cfg-text">
                                <span class="cfg-label">Envío de comprobantes a servidor alterno de SUNAT</span>
                                <small class="form-control-feedback" v-if="errors.sunat_alternate_server" v-text="errors.sunat_alternate_server[0]"></small>
                            </div>
                            <el-switch v-model="form.sunat_alternate_server" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                    </el-tab-pane>

                    <el-tab-pane label="Contable" name="accounting">
                        <div class="cfg-row" v-if="typeUser != 'integrator'" :class="{'has-danger': errors.decimal_quantity}">
                            <div class="cfg-text">
                                <span class="cfg-label">Cantidad decimales POS</span>
                                <small class="form-control-feedback" v-if="errors.decimal_quantity" v-text="errors.decimal_quantity[0]"></small>
                            </div>
                            <el-input-number v-model="form.decimal_quantity" size="small" @change="submit" :min="2" :max="10"></el-input-number>
                        </div>
                        <div class="cfg-row" v-if="typeUser != 'integrator'" :class="{'has-danger': errors.amount_plastic_bag_taxes}">
                            <div class="cfg-text">
                                <span class="cfg-label">Impuesto bolsa plástica</span>
                                <small class="form-control-feedback" v-if="errors.amount_plastic_bag_taxes" v-text="errors.amount_plastic_bag_taxes[0]"></small>
                            </div>
                            <el-input-number v-model="form.amount_plastic_bag_taxes" size="small" @change="changeAmountPlasticBagTaxes" :precision="2" :step="0.1" :max="0.5" :min="0.1"></el-input-number>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.affectation_igv_type_id}">
                            <div class="cfg-text">
                                <span class="cfg-label">Tipo de afectación</span>
                                <small class="cfg-help">Predeterminada al registrar un nuevo producto.</small>
                                <small class="form-control-feedback" v-if="errors.affectation_igv_type_id" v-text="errors.affectation_igv_type_id[0]"></small>
                            </div>
                            <el-select v-model="form.affectation_igv_type_id" size="small" class="cfg-select" @change="submit" filterable>
                                <el-option v-for="option in affectation_igv_types" :key="option.id" :value="option.id" :label="option.description"></el-option>
                            </el-select>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.include_igv}">
                            <div class="cfg-text">
                                <span class="cfg-label">Impuesto incluido en registro de productos</span>
                                <small class="form-control-feedback" v-if="errors.include_igv" v-text="errors.include_igv[0]"></small>
                            </div>
                            <el-switch v-model="form.include_igv" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.igv_reduced_regime}">
                            <div class="cfg-text">
                                <span class="cfg-label">Régimen IGV reducido (restaurantes y hoteles - Ley 31556)</span>
                                <small class="cfg-help">Aplica 10.5% (8% IGV + 2.5% IPM) en 2026 y 15% en 2027 a los productos gravados, salvo los marcados como 'Tasa general'. Solo para MYPE con actividad principal de restaurante u hospedaje.</small>
                                <small class="form-control-feedback" v-if="errors.igv_reduced_regime" v-text="errors.igv_reduced_regime[0]"></small>
                            </div>
                            <el-switch v-model="form.igv_reduced_regime" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.destination_sale}">
                            <div class="cfg-text">
                                <span class="cfg-label">Caja General seleccionada por defecto</span>
                                <small class="form-control-feedback" v-if="errors.destination_sale" v-text="errors.destination_sale[0]"></small>
                            </div>
                            <el-switch v-model="form.destination_sale" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                    </el-tab-pane>

                    <el-tab-pane label="Comprobantes y POS" name="documents">
                        <div class="cfg-row" :class="{'has-danger': errors.options_pos}">
                            <div class="cfg-text">
                                <span class="cfg-label">Opciones avanzadas en POS</span>
                                <small class="form-control-feedback" v-if="errors.options_pos" v-text="errors.options_pos[0]"></small>
                            </div>
                            <el-switch v-model="form.options_pos" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.default_document_type_03}">
                            <div class="cfg-text">
                                <span class="cfg-label">Seleccionar boleta por defecto</span>
                                <small class="cfg-help">Disponible en POS.</small>
                                <small class="form-control-feedback" v-if="errors.default_document_type_03" v-text="errors.default_document_type_03[0]"></small>
                            </div>
                            <el-switch v-model="form.default_document_type_03" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.edit_name_product}">
                            <div class="cfg-text">
                                <span class="cfg-label">Editar nombre de productos</span>
                                <small class="form-control-feedback" v-if="errors.edit_name_product" v-text="errors.edit_name_product[0]"></small>
                            </div>
                            <el-switch v-model="form.edit_name_product" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.restrict_receipt_date}">
                            <div class="cfg-text">
                                <span class="cfg-label">Restringir fecha de comprobante</span>
                                <small class="form-control-feedback" v-if="errors.restrict_receipt_date" v-text="errors.restrict_receipt_date[0]"></small>
                            </div>
                            <el-switch v-model="form.restrict_receipt_date" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.product_only_location}">
                            <div class="cfg-text">
                                <span class="cfg-label">Productos de una ubicación</span>
                                <small class="form-control-feedback" v-if="errors.product_only_location" v-text="errors.product_only_location[0]"></small>
                            </div>
                            <el-switch v-model="form.product_only_location" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.cotizaction_finance}">
                            <div class="cfg-text">
                                <span class="cfg-label">Mostrar cotización en finanzas</span>
                                <small class="form-control-feedback" v-if="errors.cotizaction_finance" v-text="errors.cotizaction_finance[0]"></small>
                            </div>
                            <el-switch v-model="form.cotizaction_finance" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.quotation_allow_seller_generate_sale}">
                            <div class="cfg-text">
                                <span class="cfg-label">Permitir generar comprobante de pago desde cotización a vendedores</span>
                                <small class="form-control-feedback" v-if="errors.quotation_allow_seller_generate_sale" v-text="errors.quotation_allow_seller_generate_sale[0]"></small>
                            </div>
                            <el-switch v-model="form.quotation_allow_seller_generate_sale" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row" :class="{'has-danger': errors.legend_footer}">
                            <div class="cfg-text">
                                <span class="cfg-label">Mostrar leyenda en footer - PDF</span>
                                <small class="cfg-help">"Bienes transferidos y/o servicios prestados en la Amazonía para ser consumidos en la misma". Facturas, boletas, notas y cotizaciones.</small>
                                <small class="form-control-feedback" v-if="errors.legend_footer" v-text="errors.legend_footer[0]"></small>
                            </div>
                            <el-switch v-model="form.legend_footer" active-text="Si" inactive-text="No" @change="submit"></el-switch>
                        </div>
                        <div class="cfg-row">
                            <div class="cfg-text">
                                <span class="cfg-label">Imagen para encabezado - PDF</span>
                                <small class="cfg-help">Facturas y boletas A4 con la plantilla header_image_full_width.</small>
                            </div>
                            <el-input v-model="form.header_image" size="small" class="cfg-select" :readonly="true" placeholder="Sin imagen">
                                <el-upload slot="append"
                                           :headers="headers"
                                           action="/configurations/uploads"
                                           :show-file-list="false"
                                           :on-success="successUpload">
                                    <el-button type="primary" icon="el-icon-upload"></el-button>
                                </el-upload>
                            </el-input>
                        </div>
                        <div class="cfg-row">
                            <div class="cfg-text">
                                <span class="cfg-label">Términos y condiciones</span>
                                <small class="cfg-help">Texto que se imprime en cotizaciones y ventas.</small>
                            </div>
                            <div class="cfg-actions">
                                <el-button size="small" @click="showDialogTermsCondition = true">Cotización</el-button>
                                <el-button size="small" @click="showDialogTermsConditionSales = true">Ventas</el-button>
                            </div>
                        </div>
                    </el-tab-pane>

                    <el-tab-pane label="Finanzas" name="finances">
                        <div class="cfg-row">
                            <div class="cfg-text">
                                <span class="cfg-label">Aplicar penalidad a los pagos vencidos</span>
                            </div>
                            <el-switch v-model="form.finances.apply_arrears" @change="submit" active-text="Si" inactive-text="No"></el-switch>
                        </div>
                        <div class="cfg-row" v-if="form.finances.apply_arrears">
                            <div class="cfg-text">
                                <span class="cfg-label">Cantidad a aplicar por día</span>
                            </div>
                            <el-input placeholder="Monto por día" v-model="form.finances.arrears_amount" size="small" class="cfg-select">
                                <el-button slot="append" @click="submit">
                                    <i class="fa fa-save"></i>
                                </el-button>
                            </el-input>
                        </div>
                    </el-tab-pane>

                    <el-tab-pane label="Mantenimiento" name="maintenance">
                        <tenant-options-form embedded></tenant-options-form>
                    </el-tab-pane>
                </el-tabs>
            </div>

            <terms-condition :showDialog.sync="showDialogTermsCondition"
                            :form="form"
                            :showClose="false"></terms-condition>
            <terms-condition-sale :showDialog.sync="showDialogTermsConditionSales"
                            :form="form"
                            :showClose="false"></terms-condition-sale>
        </div>
    </div>
</template>

<script>

    import TermsCondition from '@views/quotations/partials/terms_condition.vue'
    import TermsConditionSale from '@views/documents/partials/terms_condition.vue'

    export default {
        props:['typeUser'],
        components: {TermsCondition, TermsConditionSale},

        data() {
            return {
                headers: headers_token,
                showDialogTermsCondition: false,
                showDialogTermsConditionSales: false,
                activeTab: 'services',
                loading_submit: false,
                resource: 'configurations',
                errors: {},
                form: {
                    finances: {}
                },
                affectation_igv_types: [],
                placeholder:'',

            }
        },
        async created() {
            await this.loadTables()
            await this.initForm();

            await this.$http.get(`/${this.resource}/record`) .then(response => {
                if (response.data !== ''){
                this.form = response.data.data;
                }
                // console.log(this.placeholder)
            });

            await this.events()
        },
        methods: {
            events(){

                this.$eventHub.$on('submitFormConfigurations', (form) => {
                    this.form = form
                    this.submit()
                })

            },
            successUpload(response, file, fileList) {
                if (response.success) {
                    this.$message.success(response.message)
                    this.getRecord()
                    this.form[response.type] = response.name
                } else {
                    this.$message({message:'Error al subir el archivo', type: 'error'})
                }
            },
            async getRecord(){

                await this.$http.get(`/${this.resource}/record`) .then(response => {
                    if (response.data !== ''){
                    this.form = response.data.data;
                    }
                    // console.log(this.placeholder)
                });
            },
            async loadTables(){

                await this.$http.get(`/${this.resource}/tables`) .then(response => {
                        this.affectation_igv_types = response.data.affectation_igv_types
                    })

            },
            initForm() {
                this.errors = {};
                this.form = {
                    send_auto: true,
                    stock: true,
                    cron: true,
                    id: null,
                    sunat_alternate_server: false,
                    subtotal_account:null,
                    decimal_quantity: null,
                    amount_plastic_bag_taxes: 0.1,
                    colums_grid_item: 4,
                    affectation_igv_type_id:'10',
                    igv_reduced_regime: false,
                    terms_condition:null,
                    header_image: null,
                    legend_footer: false,
                    default_document_type_03: false,
                    destination_sale: false,
                    quotation_allow_seller_generate_sale: false,
                    finances: {}
                };
            },
            submit() {
                this.loading_submit = true;

                this.$http.post(`/${this.resource}`, this.form).then(response => {
                    if (response.data.success) {
                        this.$message.success(response.data.message);
                        window.TENANT_IGV = {reduced_regime: !!this.form.igv_reduced_regime};
                    }
                    else {
                        this.$message.error(response.data.message);
                    }
                }).catch(error => {
                    if (error.response.status === 422) {
                        this.errors = error.response.data.errors;
                    }
                    else {
                        console.log(error);
                    }
                }).then(() => {
                    this.loading_submit = false;
                });
            },
            changeAmountPlasticBagTaxes() {
                this.loading_submit = true;

                this.$http.post(`/${this.resource}/icbper`, this.form).then(response => {
                    if (response.data.success) {
                        this.$message.success(response.data.message);
                    }
                    else {
                        this.$message.error(response.data.message);
                    }
                }).catch(error => {
                    if (error.response.status === 422) {
                        this.errors = error.response.data.errors;
                    }
                    else {
                        console.log(error);
                    }
                }).then(() => {
                    this.loading_submit = false;
                });
            }
        }
    }
</script>
<style>
.cfg-card .card-body { padding-top: 4px; }
.cfg-tabs .el-tabs__header { margin-bottom: 4px; }
.cfg-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 14px 2px;
    border-bottom: 1px solid var(--tenant-hairline, #e6e9f0);
}
.cfg-row:last-child { border-bottom: 0; }
.cfg-label {
    display: block;
    font-weight: 500;
    color: var(--tenant-ink, #1b2333);
}
.cfg-help {
    display: block;
    margin-top: 2px;
    line-height: 1.45;
    color: var(--tenant-muted, #6b7489);
}
.cfg-text { flex: 1 1 0; min-width: 220px; }
.cfg-row > .el-switch,
.cfg-row > .el-button,
.cfg-actions { flex: 0 0 auto; }
.cfg-row > .el-input-number { flex: 0 0 160px; width: 160px !important; }
.cfg-select { flex: 0 0 300px; max-width: 300px; }
.cfg-actions { display: flex; gap: 8px; }
.cfg-actions .el-button + .el-button { margin-left: 0; }
@media (max-width: 767px) {
    .cfg-row { flex-wrap: wrap; gap: 10px; }
    .cfg-select { flex-basis: 100%; max-width: 100%; }
}
</style>
