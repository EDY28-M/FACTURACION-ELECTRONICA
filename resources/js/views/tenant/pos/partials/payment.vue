<template >
    <div class="row col-lg-12 m-0 p-0" v-loading="loading_submit">
      <Keypress key-event="keyup" :key-code="113" @success="handleFn113" />

        <div class="col-lg-4 col-md-6 bg-white m-0 p-0 tn-pos-receipt" style="height: calc(100vh - 110px)">
            <div class="h-75 bg-light" style="overflow-y: auto">

                <div class="row pl-3 py-2 border-bottom m-0 p-0 bg-white">
                    <div class="col-12 px-0 py-3">
                        <h4 class="font-weight-semibold m-0">{{customer.description}}</h4>
                    </div>
                </div>

                 <template v-for="(item,index) in form.items">
                    <div class="row py-1 border-bottom m-0 p-0" :key="index">
                        <div class="col-2 p-r-0 m-l-2">
                            <h4 class="font-weight-semibold m-0 text-center">{{item.quantity}}</h4>

                        </div>
                        <div class="col-6 px-0">
                            <h4 class="font-weight-semibold m-0 text-center m-b-0">{{item.item.description}}</h4>
                            <!-- <p class="m-b-0">Descripción del producto</p> -->
                            <!-- <p class="text-muted m-b-0"><small>Descuento 2%</small></p> -->
                        </div>
                        <div class="col-4 p-l-0">
                            <!-- <p class="font-weight-semibold m-b-0">{{currencyTypeActive.symbol}} 240.00</p> -->
                            <h4 class="font-weight-semibold m-0 text-center">{{currencyTypeActive.symbol}} {{ toAmount(item.total) }}</h4>
                        </div>
                    </div>
                </template>


            </div>
            <div class="h-25 bg-info tn-pos-receipt-foot" style="overflow-y: auto">
                <template v-if="form.total_plastic_bag_taxes > 0">
                    <div class="row m-0 p-0 bg-white h-17 d-flex align-items-center">
                        <div class="col-sm-6 py-1">
                            <p class="font-weight-semibold mb-0">SUBTOTAL</p>
                        </div>
                        <div class="col-sm-6 py-1 text-right">
                            <p class="font-weight-semibold mb-0">{{currencyTypeActive.symbol}} {{ toAmount(form.total_taxed) }}</p>
                        </div>
                    </div>
                    <div class="row m-0 p-0 bg-white h-17 d-flex align-items-center">
                        <div class="col-sm-6 py-1">
                            <p class="font-weight-semibold mb-0">IGV</p>
                        </div>
                        <div class="col-sm-6 py-1 text-right">
                            <p class="font-weight-semibold mb-0">{{currencyTypeActive.symbol}} {{ toAmount(form.total_igv) }}</p>
                        </div>
                    </div>
                    <div class="row m-0 p-0 bg-white h-17 d-flex align-items-center">
                        <div class="col-sm-6 py-1">
                            <p class="font-weight-semibold mb-0">ICBPER</p>
                        </div>
                        <div class="col-sm-6 py-1 text-right">
                            <p class="font-weight-semibold mb-0">{{currencyTypeActive.symbol}} {{ toAmount(form.total_plastic_bag_taxes) }}</p>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="row m-0 p-0 bg-white h-25 d-flex align-items-center">
                        <div class="col-sm-6 py-1">
                            <p class="font-weight-semibold mb-0">SUBTOTAL</p>
                        </div>
                        <div class="col-sm-6 py-1 text-right">
                            <p class="font-weight-semibold mb-0">{{currencyTypeActive.symbol}} {{ toAmount(form.total_taxed) }}</p>
                        </div>
                    </div>
                    <div class="row m-0 p-0 bg-white h-25 d-flex align-items-center">
                        <div class="col-sm-6 py-1">
                            <p class="font-weight-semibold mb-0">IGV</p>
                        </div>
                        <div class="col-sm-6 py-1 text-right">
                            <p class="font-weight-semibold mb-0">{{currencyTypeActive.symbol}} {{ toAmount(form.total_igv) }}</p>
                        </div>
                    </div>
                </template>

                <!-- <div class="row m-0 p-0 bg-white">
                    <div class="col-sm-6 py-1">
                        <p class="font-weight-semibold mb-0">DESCUENTO</p>
                    </div>
                    <div class="col-sm-6 py-1 text-right">
                        <p class="font-weight-semibold mb-0">{{currencyTypeActive.symbol}} 4.00</p>
                    </div>
                </div> -->
                <div class="row m-0 p-0 h-50 d-flex align-items-center tn-pos-receipt-total">
                    <div class="col-sm-6 py-2">
                        <p class="font-weight-semibold mb-0 text-white">TOTAL</p>
                    </div>
                    <div class="col-sm-6 py-2 text-right">
                        <p class="font-weight-semibold mb-0 text-white">{{currencyTypeActive.symbol}} {{ toAmount(form.total) }}</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8 col-md-6 pp-panel">
            <div class="pp-head">
                <el-radio-group v-model="form.document_type_id" size="small" @change="filterSeries">
                    <el-radio-button label="01">FACTURA</el-radio-button>
                    <el-radio-button label="03">BOLETA</el-radio-button>
                    <el-radio-button label="80">N. VENTA</el-radio-button>
                </el-radio-group>
                <el-select v-model="form.series_id" size="small" class="pp-series">
                    <el-option v-for="option in series" :key="option.id" :label="option.number" :value="option.id"></el-option>
                </el-select>
                <button type="button" class="pp-back" @click="back"><i class="fas fa-angle-left"></i> Regresar</button>
            </div>

            <div class="pp-body">
                <section class="pp-card pp-charge">
                    <div class="pp-charge-total">
                        <span class="pp-label">Monto a cobrar</span>
                        <strong class="pp-amount">{{currencyTypeActive.symbol}} {{ toAmount(form.total) }}</strong>
                    </div>
                    <div class="pp-charge-tender">
                        <label class="pp-label">Ingrese monto</label>
                        <el-input v-model="enter_amount" @keyup.enter.native="keyupEnterAmount()" @input="enterAmount()" ref="enter_amount">
                            <template slot="prepend">{{currencyTypeActive.symbol}}</template>
                        </el-input>
                        <div class="pp-quick" v-if="form_payment.payment_method_type_id=='01'">
                            <button type="button" v-for="cash in [10, 20, 50, 100]" :key="cash" @click="setAmountCash(cash)">{{currencyTypeActive.symbol}}{{cash}}</button>
                        </div>
                    </div>
                    <div class="pp-charge-change" :class="{'is-short': difference < 0}">
                        <span class="pp-label" v-text="(difference < 0) ? 'Faltante' : 'Vuelto'"></span>
                        <strong>{{currencyTypeActive.symbol}} {{ toAmount(difference) }}</strong>
                    </div>
                </section>

                <section class="pp-card pp-payments">
                    <div class="pp-card-head">
                        <span class="pp-title">Pagos agregados</span>
                        <button type="button" class="pp-link" @click="clickAddPayment()"><i class="fas fa-plus"></i> Agregar</button>
                    </div>
                    <ul class="pp-pay-list">
                        <li v-for="(pay, index) in form.payments" :key="index">
                            <span class="pp-pay-index">{{index + 1}}</span>
                            <span class="pp-pay-method">{{getDescriptionPaymentMethodType(pay.payment_method_type_id)}}</span>
                            <strong>{{currencyTypeActive.symbol}} {{pay.payment}}</strong>
                        </li>
                        <li v-if="!form.payments || !form.payments.length" class="pp-pay-empty">Sin pagos agregados</li>
                    </ul>
                </section>

                <div class="pp-grid">
                    <section class="pp-card">
                        <div class="pp-card-head">
                            <el-switch @change="changeEnabledDiscount" v-model="enabled_discount" active-text="Aplicar descuento"></el-switch>
                        </div>
                        <label class="pp-label">Monto descuento</label>
                        <el-input v-model="discount_amount" @input="inputDiscountAmount()" :disabled="!enabled_discount">
                            <template slot="prepend">{{currencyTypeActive.symbol}}</template>
                        </el-input>
                    </section>
                    <section class="pp-card">
                        <label class="pp-label">Datos de referencia</label>
                        <el-input type="textarea" :rows="3" resize="none" v-model="form.reference_data"></el-input>
                    </section>
                    <section class="pp-card" v-if="businessTurns.active">
                        <label class="pp-label">N° Placa</label>
                        <el-input type="textarea" :rows="2" resize="none" v-model="form.plate_number"></el-input>
                    </section>
                </div>
            </div>

            <div class="pp-actions">
                <button type="button" class="pp-cancel" @click="clickCancel">Cancelar</button>
                <button type="button" class="pp-pay" @click="clickPayment" :disabled="button_payment">
                    Pagar <span>{{currencyTypeActive.symbol}} {{ toAmount(form.total) }}</span>
                </button>
            </div>
        </div>
        <options-form
            :showDialog.sync="showDialogOptions"
            :recordId="documentNewId"
            :statusDocument="statusDocument"
            :resource="resource_options"
            ></options-form>

        <multiple-payment-form
            :showDialog.sync="showDialogMultiplePayment"
            :payments="payments"
            :total="form.total"
            @add="addRow"
            ></multiple-payment-form>

        <!-- <sale-notes-options :showDialog.sync="showDialogSaleNote"
                          :recordId="saleNotesNewId"
                          :showClose="true"></sale-notes-options>  -->

        <card-brands-form   :showDialog.sync="showDialogNewCardBrand"
                            :external="true"
                            :recordId="null"></card-brands-form>
    </div>
</template>
<style>
.c-width{
    width: 80px!important;
    padding: 0!important;
    margin-right: 0!important;
}

.pp-panel {
    --pp-surface: var(--tenant-surface, #fff);
    --pp-line: var(--tenant-hairline, #e6e9f0);
    --pp-ink: var(--tenant-ink, #1b2333);
    --pp-muted: var(--tenant-muted, #6b7489);
    --pp-blue: var(--tenant-blue, #3b5bdb);
    --pp-soft: var(--tenant-surface-subtle, #f4f6fb);
    display: flex;
    flex-direction: column;
    height: calc(100vh - var(--tenant-header-h, 72px) - 92px);
    min-height: 460px;
    padding: 0 0 0 24px !important;
    color: var(--pp-ink);
}
.pp-head, .pp-body, .pp-actions {
    width: 100%;
    max-width: 780px;
    margin: 0 auto;
}
.pp-head {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 14px;
}
.pp-series { width: 96px; }
.pp-back {
    margin-left: auto;
    border: 1px solid var(--pp-line);
    background: var(--pp-surface);
    color: var(--pp-ink);
    border-radius: 10px;
    padding: 6px 14px;
    font-weight: 500;
}
.pp-back:hover { border-color: var(--pp-blue); color: var(--pp-blue); }
.pp-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding-bottom: 12px;
}
.pp-card {
    background: var(--pp-surface);
    border: 1px solid var(--pp-line);
    border-radius: 14px;
    padding: 16px 18px;
}
.pp-label {
    display: block;
    margin-bottom: 6px;
    font-size: 12.5px;
    font-weight: 500;
    color: var(--pp-muted);
}
.pp-title { font-weight: 600; font-size: 15px; }
.pp-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.pp-charge {
    display: grid;
    grid-template-columns: 1.1fr 1.3fr 0.9fr;
    gap: 20px;
    align-items: start;
}
.pp-amount {
    display: block;
    font-size: 34px;
    font-weight: 600;
    line-height: 1.1;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
}
.pp-charge-change strong {
    display: block;
    font-size: 22px;
    font-weight: 600;
    color: #1f9d55;
    font-variant-numeric: tabular-nums;
}
.pp-charge-change.is-short strong { color: #d64545; }
.pp-quick {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
    margin-top: 8px;
}
.pp-quick button {
    border: 1px solid var(--pp-line);
    background: var(--pp-soft);
    color: var(--pp-ink);
    border-radius: 8px;
    padding: 5px 0;
    font-size: 13px;
    font-weight: 500;
}
.pp-quick button:hover { border-color: var(--pp-blue); color: var(--pp-blue); }
.pp-link {
    border: 0;
    background: none;
    color: var(--pp-blue);
    font-weight: 600;
    padding: 2px 4px;
}
.pp-pay-list { list-style: none; margin: 0; padding: 0; }
.pp-pay-list li {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 0;
    border-top: 1px solid var(--pp-line);
}
.pp-pay-list li:first-child { border-top: 0; }
.pp-pay-index {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--pp-soft);
    color: var(--pp-muted);
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.pp-pay-method { flex: 1; }
.pp-pay-empty { color: var(--pp-muted); font-size: 13px; }
.pp-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}
.pp-actions {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 12px;
    padding: 12px 0 4px;
    border-top: 1px solid var(--pp-line);
}
.pp-actions button {
    border-radius: 12px;
    padding: 13px 16px;
    font-size: 15px;
    font-weight: 600;
}
.pp-cancel {
    border: 1px solid var(--pp-line);
    background: var(--pp-surface);
    color: #d64545;
}
.pp-cancel:hover { border-color: #d64545; }
.pp-pay {
    border: 0;
    background: var(--pp-blue);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}
.pp-pay span { font-variant-numeric: tabular-nums; opacity: .9; }
.pp-pay:hover:not(:disabled) { filter: brightness(1.08); }
.pp-pay:disabled { opacity: .55; cursor: not-allowed; }
@media (max-width: 1199px) {
    .pp-charge { grid-template-columns: 1fr 1fr; }
    .pp-charge-total { grid-column: 1 / -1; }
}
@media (max-width: 991px) {
    .pp-panel { height: auto; padding: 16px 0 0 !important; }
    .pp-grid { grid-template-columns: 1fr; }
    .pp-actions { position: sticky; bottom: 0; background: var(--tenant-ground, #f4f6fb); }
}
</style>

<script>
    import Keypress from 'vue-keypress'
    import {splitGross} from '../../../../helpers/igv'
    import {boletaNeedsIdentification, BOLETA_IDENTIFICATION_MESSAGE} from '../../../../helpers/boleta-identification'

    import CardBrandsForm from '../../card_brands/form.vue'
    import SaleNotesOptions from '../../sale_notes/partials/options.vue'
    import OptionsForm from './options.vue'
    import MultiplePaymentForm from './multiple_payment.vue'
    import {readPosDraft, writePosDraft, removePosDraft} from '../../../../helpers/pos_draft'

    export default {
        components: {OptionsForm, CardBrandsForm, SaleNotesOptions, MultiplePaymentForm, Keypress},

        props:['form','customer', 'currencyTypeActive', 'exchangeRateSale', 'is_payment', 'soapCompany', 'businessTurns'],
        data() {
            return {
                enabled_discount: false,
                discount_amount:0,
                loading_submit: false,
                showDialogOptions:false,
                showDialogMultiplePayment:false,
                showDialogSaleNote:false,
                showDialogNewCardBrand:false,
                documentNewId:null,
                saleNotesNewId:null,
                resource_options:null,
                has_card: false,
                resource: 'pos',
                resource_documents: 'documents',
                resource_payments: 'document_payments',
                amount: 0,
                enter_amount: 0,
                difference: 0,
                button_payment: false,
                input_item: '',
                form_payment:{},
                series:[],
                all_series:[],
                cards_brand:[],
                cancel:false,
                form_cash_document:{},
                statusDocument:{},
                payment_method_types:[],
                payments:[],
                locked_submit: false
            }
        },
        async created() {

            await this.initLStoPayment()
            await this.getTables()
            this.initFormPayment()
            this.inputAmount()
            this.form.payments = []
            this.$eventHub.$on('reloadDataCardBrands', (card_brand_id) => {
                this.reloadDataCardBrands(card_brand_id)
            })

            this.$eventHub.$on('localSPayments', (payments) => {
                this.payments = payments

            })

            await this.setInitialAmount()

            await this.getFormPosLocalStorage()
            // console.log(this.form.payments, this.payments)
        },
        mounted(){
            // console.log(this.currencyTypeActive)
        },
        methods: {
            // Display only: amounts may arrive as 153.9; show 153.90.
            toAmount(value) {
                const amount = Number(value);
                return Number.isFinite(amount) ? amount.toFixed(2) : value;
            },
            handleFn113(){
                const code = this.form.document_type_id
                if(code == '01')
                {
                    this.form.document_type_id = '03'
                }
                else if(code == '03')
                {
                    this.form.document_type_id = '80'
                }
                else if(code == '80')
                {
                    this.form.document_type_id = '01'
                }

                this.filterSeries()
            },
            keyupEnterAmount(){

                if(this.button_payment){
                    return this.$message.warning("El monto a pagar es menor al total")
                }

                if(this.locked_submit) return;

                this.clickPayment()

            },
            async setInitialAmount(){
                this.enter_amount = this.form.total
                // this.form.payments = this.payments
                // this.$eventHub.$emit('eventSetFormPosLocalStorage', this.form)
                await this.$refs.enter_amount.$el.getElementsByTagName('input')[0].focus()
                await this.$refs.enter_amount.$el.getElementsByTagName('input')[0].select()
                // console.log(this.$refs.enter_amount.$el.getElementsByTagName('input')[0])
            },
            changeEnabledDiscount(){

                if(!this.enabled_discount){

                    this.discount_amount = 0
                    this.deleteDiscountGlobal()
                    this.reCalculateTotal()

                }

            },
            inputDiscountAmount(){

                if(this.enabled_discount){

                    if(this.discount_amount && !isNaN(this.discount_amount) && parseFloat(this.discount_amount) > 0){

                        if(this.discount_amount >= this.form.total)
                            return this.$message.error("El monto de descuento debe ser menor al total de venta")

                        this.reCalculateTotal()

                    }else{

                        // this.discount_amount = 0
                        this.deleteDiscountGlobal()
                        this.reCalculateTotal()

                    }

                    // console.log(this.discount_amount)
                }
            },
            discountGlobal(){

                let global_discount = parseFloat(this.discount_amount)

                let base = parseFloat(this.form.total)
                let amount = parseFloat(global_discount)
                let factor = _.round(amount/base, 4)

                let discount = _.find(this.form.discounts,{'discount_type_id':'03'})

                if(global_discount>0 && !discount){

                    this.form.total_discount =  _.round(amount,2)

                    this.form.total =  _.round(this.form.total - amount, 2)

                    const split = splitGross(this.form.items, this.form.total, this.form.date_of_issue)
                    this.form.total_value =  _.round(split.value, 2)
                    this.form.total_taxed =  this.form.total_value

                    this.form.total_igv =  _.round(split.igv, 2)
                    this.form.total_taxes =  this.form.total_igv

                    this.form.discounts.push({
                            discount_type_id: '03',
                            description: 'Descuentos globales que no afectan la base imponible del IGV/IVAP',
                            factor: factor,
                            amount: amount,
                            base: base
                        })

                }else{

                    let index = this.form.discounts.indexOf(discount);

                    if(index > -1){

                        this.form.total_discount =  _.round(amount,2)

                        this.form.total =  _.round(this.form.total - amount, 2)

                        const split = splitGross(this.form.items, this.form.total, this.form.date_of_issue)
                        this.form.total_value =  _.round(split.value, 2)
                        this.form.total_taxed =  this.form.total_value

                        this.form.total_igv =  _.round(split.igv, 2)
                        this.form.total_taxes =  this.form.total_igv

                        this.form.discounts[index].base = base
                        this.form.discounts[index].amount = amount
                        this.form.discounts[index].factor = factor

                    }

                }

                this.difference = this.enter_amount - this.form.total
                // console.log(this.form.discounts)
            },
            reCalculateTotal() {

                let total_discount = 0
                let total_charge = 0
                let total_exportation = 0
                let total_taxed = 0
                let total_exonerated = 0
                let total_unaffected = 0
                let total_free = 0
                let total_igv = 0
                let total_value = 0
                let total = 0
                let total_plastic_bag_taxes = 0

                this.form.items.forEach((row) => {
                    total_discount += parseFloat(row.total_discount)
                    total_charge += parseFloat(row.total_charge)

                    if (row.affectation_igv_type_id === '10') {
                        total_taxed += parseFloat(row.total_value)
                    }
                    if (row.affectation_igv_type_id === '20') {
                        total_exonerated += parseFloat(row.total_value)
                    }
                    if (row.affectation_igv_type_id === '30') {
                        total_unaffected += parseFloat(row.total_value)
                    }
                    if (row.affectation_igv_type_id === '40') {
                        total_exportation += parseFloat(row.total_value)
                    }
                    if (['10', '20', '30', '40'].indexOf(row.affectation_igv_type_id) < 0) {
                        total_free += parseFloat(row.total_value)
                    }
                    if (['10', '20', '30', '40'].indexOf(row.affectation_igv_type_id) > -1) {
                        total_igv += parseFloat(row.total_igv)
                        total += parseFloat(row.total)
                    }
                    total_value += parseFloat(row.total_value)
                    total_plastic_bag_taxes += parseFloat(row.total_plastic_bag_taxes)
                });

                this.form.total_exportation = _.round(total_exportation, 2)
                this.form.total_taxed = _.round(total_taxed, 2)
                this.form.total_exonerated = _.round(total_exonerated, 2)
                this.form.total_unaffected = _.round(total_unaffected, 2)
                this.form.total_free = _.round(total_free, 2)
                this.form.total_igv = _.round(total_igv, 2)
                this.form.total_value = _.round(total_value, 2)
                this.form.total_taxes = _.round(total_igv, 2)
                this.form.total_plastic_bag_taxes = _.round(total_plastic_bag_taxes, 2)
                // this.form.total = _.round(total, 2)
                this.form.total = _.round(total + this.form.total_plastic_bag_taxes, 2)

                this.discountGlobal()


            },
            deleteDiscountGlobal(){

                let discount = _.find(this.form.discounts, {'discount_type_id':'03'})
                let index = this.form.discounts.indexOf(discount)

                if (index > -1) {
                    this.form.discounts.splice(index, 1)
                    this.form.total_discount = 0
                }

            },
            back()
            {
                this.$emit('update:is_payment', false)
            },
            async initLStoPayment(){

                this.amount = await this.getLocalStoragePayment('amount', 0)
                this.enter_amount = await this.getLocalStoragePayment('enter_amount', 0)
                this.difference = await this.getLocalStoragePayment('difference', 0)
            },
            getFormPosLocalStorage(){

                let form_pos = readPosDraft('form_pos');
                if (form_pos) {
                    this.form.payments = form_pos.payments
                }

            },
            clickAddPayment(){
                this.showDialogMultiplePayment = true
            },
            reloadDataCardBrands(card_brand_id) {
                this.$http.get(`/${this.resource}/table/card_brands`).then((response) => {
                    this.cards_brand = response.data
                    this.form_payment.card_brand_id = card_brand_id
                    this.changePaymentMethodType()
                })
            },
            getDescriptionPaymentMethodType(id){
                let payment_method_type = _.find(this.payment_method_types,{'id':id})
                return (payment_method_type) ? payment_method_type.description:''

            },
            changePaymentMethodType(){
                let payment_method_type = _.find(this.payment_method_types,{'id':this.form_payment.payment_method_type_id})
                this.has_card = payment_method_type.has_card
                this.form_payment.card_brand_id = (payment_method_type.has_card) ? this.form_payment.card_brand_id:null
            },
            addRow(payments) {

                this.form.payments = payments
                let acum_payment = 0

                this.form.payments.forEach((item)=>{
                    acum_payment += parseFloat(item.payment)
                })

               // this.amount = acum_payment
                this.setAmount(acum_payment)

                // console.log(this.form.payments)
            },
            setAmount(amount){
                // this.amount = parseFloat(this.amount) + parseFloat(amount)
                this.amount =  parseFloat(amount) //+ parseFloat(amount)
                this.enter_amount =  parseFloat(amount) //+ parseFloat(amount)
                this.inputAmount()
            },
            setAmountCash(amount)
            {
                let row = _.last(this.payments, { 'payment_method_type_id' : '01' })
                row.payment = parseFloat(row.payment) + parseFloat(amount)
                // console.log(row.payment)

                this.form.payments = this.payments
                let acum_payment = 0

                this.form.payments.forEach((item)=>{
                    acum_payment += parseFloat(item.payment)
                })

                this.setAmount(acum_payment)

            },
            async enterAmount(){

                let r_item = await _.last(this.payments, { 'payment_method_type_id' : '01' })
                r_item.payment = await parseFloat(this.enter_amount)
                // console.log(r_item.payment)

                let ind = this.form.payments.length - 1
                this.form.payments[ind].payment = parseFloat(this.enter_amount)
                // this.setAmount(item.payment)

                let acum_payment = 0

                await this.form.payments.forEach((item)=>{
                    acum_payment += parseFloat(item.payment)
                })
                // console.log(this.form.payments)

                // this.amount = item.payment
                this.amount = acum_payment
                // this.amount = this.enter_amount
                // console.log(this.amount)
                this.difference = this.amount - this.form.total

                if(isNaN(this.difference)) {
                    this.button_payment = true
                    this.difference = "-"
                }else if(this.difference >=0){
                    this.button_payment = false
                    this.difference = this.amount - this.form.total
                }else{
                    this.button_payment = true
                }
                this.difference = _.round(this.difference,2)

                this.$eventHub.$emit('eventSetFormPosLocalStorage', this.form)

                await this.lStoPayment()

            },
            getLocalStoragePayment(key, re_default = null){

                return readPosDraft(key, re_default)
            },
            setLocalStoragePayment(key, obj){
                if (obj === null || obj === undefined) {
                    removePosDraft(key)
                    return
                }
                writePosDraft(key, obj)
            },
            inputAmount(){

                this.difference = this.amount - this.form.total

                if(isNaN(this.difference)) {
                    this.button_payment = true
                    this.difference = "-"
                }else if(this.difference >=0){
                    this.button_payment = false
                    this.difference = this.amount - this.form.total
                }else{
                    this.button_payment = true
                }
                this.difference = _.round(this.difference,2)
                // this.form_payment.payment = this.amount

                this.$eventHub.$emit('eventSetFormPosLocalStorage', this.form)
                this.lStoPayment()

            },
            lStoPayment(){

                this.setLocalStoragePayment('enter_amount', this.enter_amount)
                this.setLocalStoragePayment('amount', this.amount)
                // console.log(this.amount)
                this.setLocalStoragePayment('difference', this.difference)

            },
            initFormPayment() {

                this.difference = -this.form.total
                this.form_payment = {
                    id: null,
                    date_of_payment: moment().format('YYYY-MM-DD'),
                    payment_method_type_id: '01',
                    reference: null,
                    card_brand_id:null,
                    document_id:null,
                    sale_note_id:null,
                    payment: this.form.total,
                }

                this.form_cash_document = {
                    document_id:null,
                    sale_note_id:null
                }

            },

            filterSeries() {
                this.form.series_id = null
                this.series = _.filter(this.all_series, {'document_type_id': this.form.document_type_id });
                this.form.series_id = (this.series.length > 0)?this.series[0].id:null

                if(!this.form.series_id)
                {
                   return this.$message.warning('El establecimiento no tiene series disponibles para el comprobante');
                }
            },
            async clickCancel(){

                this.loading_submit = true
                await this.sleep(800);
                this.loading_submit = false
                this.cleanLocalStoragePayment()
                this.$eventHub.$emit('cancelSale')

            },
            cleanLocalStoragePayment(){

                this.setLocalStoragePayment('amount', null)
                this.setLocalStoragePayment('enter_amount', null)
                this.setLocalStoragePayment('difference', null)
            },
            sleep(ms) {
                return new Promise(resolve => setTimeout(resolve, ms));
            },
            async asignPlateNumberToItems() {
                if(this.form.plate_number) {

                    await this.form.items.forEach(item => {

                        let at = _.find(item.attributes, {'attribute_type_id': '5010'})

                        if(!at){
                            item.attributes.push({
                                attribute_type_id: '7000',
                                description: "Gastos Art. 37 Renta:  Número de Placa",
                                value: this.form.plate_number,
                                start_date: null,
                                end_date: null,
                                duration: null,
                            })
                        }
                    });
                }
            },
            async clickPayment(){
                // SUNAT: boletas above S/ 700.00 must identify the customer; keep the cart untouched
                if (this.form.document_type_id === '03' && boletaNeedsIdentification(
                    this.form.total,
                    this.form.currency_type_id,
                    this.form.exchange_rate_sale,
                    this.customer ? this.customer.identity_document_type_id : null,
                    this.customer ? this.customer.number : null
                )) {
                    return this.$message.error(BOLETA_IDENTIFICATION_MESSAGE);
                }

                // SUNAT: a factura always requires a customer identified with RUC
                if (this.form.document_type_id === '01' && (!this.customer || String(this.customer.identity_document_type_id) !== '6')) {
                    return this.$message.error('La factura requiere un cliente con RUC. Use boleta o nota de venta para ventas sin identificar.');
                }

                // if(this.has_card && !this.form_payment.card_brand_id) return this.$message.error('Seleccione una tarjeta');

                if(!moment(moment().format("YYYY-MM-DD")).isSame(this.form.date_of_issue)){
                   return this.$message.error('La fecha de emisión no coincide con la del día actual');
                }

                if(!this.form.series_id)
                {
                   return this.$message.warning('El establecimiento no tiene series disponibles para el comprobante');
                }

                if (this.form.document_type_id === "80") {
                    this.form.prefix = "NV";
                    this.form.paid = 1;
                    this.resource_documents = "sale-notes";
                    this.resource_payments = "sale_note_payments";
                    this.resource_options = this.resource_documents;
                } else {
                    this.form.prefix = null;
                    this.resource_documents = "documents";
                    this.resource_payments = "document_payments";
                    this.resource_options = this.resource_documents;
                    await this.asignPlateNumberToItems()
                }

                this.loading_submit = true
                this.locked_submit = true
                
                await this.$http.post(`/${this.resource_documents}`, this.form).then(response => {
                    if (response.data.success) {

                        if (this.form.document_type_id === "80") {

                            // this.form_payment.sale_note_id = response.data.data.id;
                            this.form_cash_document.sale_note_id = response.data.data.id;

                        } else {

                            // this.form_payment.document_id = response.data.data.id;
                            this.form_cash_document.document_id = response.data.data.id;
                            this.statusDocument = response.data.data.response

                        }

                        this.documentNewId = response.data.data.id;
                        this.showDialogOptions = true;

                        // this.savePaymentMethod();
                        this.saveCashDocument();

                        // this.initFormPayment() ;
                        this.cleanLocalStoragePayment()
                        this.$eventHub.$emit('saleSuccess');
                    }
                    else {
                        this.$message.error(response.data.message);
                    }
                }).catch(error => {
                    if (error.response.status === 422) {
                        this.errors = error.response.data;
                    }
                    else {
                        this.$message.error(error.response.data.message);
                    }
                }).then(() => {
                    this.loading_submit = false;
                    this.locked_submit = false
                });
            },
            saveCashDocument(){
                this.$http.post(`/cash/cash_document`, this.form_cash_document)
                    .then(response => {
                        if (response.data.success) {
                            // console.log(response)
                        } else {
                            this.$message.error(response.data.message);
                        }
                    })
                    .catch(error => {
                        console.log(error);
                    })
            },
            savePaymentMethod(){
                this.$http.post(`/${this.resource_payments}`, this.form_payment)
                    .then(response => {
                        if (response.data.success) {
                            // console.log(response)
                        } else {
                            this.$message.error(response.data.message);
                        }
                    })
                    .catch(error => {
                        if (error.response.status === 422) {
                            this.records[index].errors = error.response.data;
                        } else {
                            console.log(error);
                        }
                    })
            },
            getTables(){
                this.$http.get(`/${this.resource}/payment_tables`)
                    .then(response => {
                        this.all_series = response.data.series
                        this.payment_method_types = response.data.payment_method_types
                        this.cards_brand = response.data.cards_brand
                        this.filterSeries()
                    })

            },
        }
    }
</script>
