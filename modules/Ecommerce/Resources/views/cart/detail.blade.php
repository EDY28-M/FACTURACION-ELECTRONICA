@extends('ecommerce::layouts.layout_ecommerce_cart.index')
@section('content')

<div class="row" id="app">
    <div class="col-lg-8">
        <div class="cart-table-container">

            <table class="table table-cart">
                <thead>
                    <tr>
                        <th class="product-col">Producto</th>
                        <th class="price-col">Precio</th>
                        <th class="qty-col">Cantidad</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in records" class="product-row">
                        <td class="product-col">
                            <figure class="product-image-container">
                                <a href="#" class="product-image">
                                    <img :src=" '/storage/uploads/items/' + row.image" alt="product">
                                </a>
                            </figure>
                            <h2 class="product-title">
                                <a href="#">@{{ row.description }}</a>
                            </h2>
                        </td>
                        <td>@{{ row.currency_type_symbol }} @{{ row.sale_unit_price }}</td>
                        <td>
                            <input class="vertical-quantity form-control input_quantity" :data-product="row.id"
                                type="text">
                        </td>
                        <td>S/ @{{ row.sub_total }}</td>
                        <td>
                            <button type="button" @click="deleteItem(row.id, index)"
                                class="btn btn-outline-danger btn-sm"><i class="icon-cancel"></i></button>
                        </td>
                    </tr>

                </tbody>

                <tfoot>
                    <tr>
                        <td colspan="4" class="clearfix">
                            <div class="float-left">
                                <a href="/ecommerce" class="btn btn-outline-secondary">Continuar Comprando</a>
                            </div><!-- End .float-left -->

                            <div class="float-right">
                                <a href="#" @click="clearShoppingCart"
                                    class="btn btn-outline-secondary btn-clear-cart">Limpiar Carrito</a>
                                <!--<a href="#" class="btn btn-outline-secondary btn-update-cart">Update Shopping Cart</a> -->
                            </div><!-- End .float-right -->
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div><!-- End .cart-table-container -->
    </div><!-- End .col-lg-8 -->

    <div class="col-lg-4">
        <div class="cart-summary">
            <h3>Resumen</h3>
            <table class="table table-totals">
                <tbody>

                    <tr v-if="summary.total_exonerated > 0">
                        <td>OP.EXONERADAS</td>
                        <td>S/ @{{ summary.total_exonerated }}</td>
                    </tr>
                    <tr v-if="summary.total_unaffected > 0">
                        <td>OP.INAFECTAS</td>
                        <td>S/ @{{ summary.total_unaffected }}</td>
                    </tr>
                    <tr v-if="summary.total_taxed > 0">
                        <td>OP.GRAVADA</td>
                        <td>S/ @{{ summary.total_taxed }}</td>
                    </tr>
                    <tr v-if="summary.total_igv > 0">
                        <td>IGV</td>
                        <td>S/ @{{ summary.total_igv }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Orden Total</td>
                        <td>S/ @{{summary.total}}</td>
                    </tr>
                </tfoot>
            </table>

            <div class="checkout-methods text-center">

                @guest
                @if(!empty($cardEnabled))
                <a href="#" class="btn btn-block btn-sm btn-primary login-link culqi" data-toggle="modal" data-target="#login_register_modal">Pagar
                    con VISA</a>
                @endif
                <a href="#" class="btn btn-block btn-sm btn-primary login-link" data-toggle="modal" data-target="#login_register_modal">Pagar
                    con EFECTIVO</a>
                @else
                @if(!empty($cardEnabled))
                <button class="btn btn-block btn-sm btn-primary culqi" onclick="execCulqi()"> Pagar con VISA </button>
                @endif

                <button @click="payment_cash.clicked = !payment_cash.clicked" class="btn btn-block btn-sm btn-primary">
                    Pagar con EFECTIVO </button>
                <div v-show="payment_cash.clicked" style="margin: 3%" class="form-group">
                    <div class="input-group mb-3">
                        <div class="input-group-prepend">
                            <span class="input-group-text">S/</span>
                        </div>
                        <input readonly placeholder="0.0" v-model="payment_cash.amount" type="text"
                            onkeypress="return isNumberKey(event)" maxlength="14" class="form-control"
                            aria-label="Amount">
                        <button @click="paymentCash" :disabled="payment_cash.processing" class="btn btn-success">OK!</button>
                    </div>
                </div>



                @endguest

            </div><!-- End .checkout-methods -->
        </div><!-- End .cart-summary -->

        <div class="cart-summary">
            <h3>Tipo de comprobante</h3>

            <div class="form-group" :class="{'text-danger': errors.identity_document_type_id}">
                <label>Comprobante:</label>
                <select v-model="formIdentity.identity_document_type_id" class="form-control" @change="optionDocument">
                    <option value="" disabled>Tipo de comprobante</option>
                    <option value="1">Boleta</option>
                    <option value="6">Factura</option>
                </select>
                <small class="form-control-feedback" v-if="errors.identity_document_type_id" v-text="errors.identity_document_type_id[0]"></small>
            </div>
            <div class="form-group" :class="{'text-danger': errors.codigo_tipo_documento_identidad}">
                <label>Tipo de documento:</label>
                <select v-model="typeDocuments" class="form-control">
                    <option value="" disabled>Tipo de documento</option>
                    <option v-for="item in typeDocumentList" :value="item.id" :label="item.name">@{{ item.name }}</option>
                </select>
                <small class="form-control-feedback" v-if="errors.codigo_tipo_documento_identidad" v-text="errors.codigo_tipo_documento_identidad[0]"></small>
            </div>
            <div class="form-group" :class="{'text-danger': errors.numero_documento}">
                <label>Número de documento:</label>
                <input v-model="numberDocument" :maxlength="maxLength" type="text" class="form-control">
                <small class="form-control-feedback" v-if="errors.numero_documento" v-text="errors.numero_documento[0]"></small>
            </div>

        </div><!-- End .col-lg-4 -->

        <div class="cart-summary">
            <h3>Datos de contacto y envío</h3>

            <form autocomplete="off" action="#">
                <div class="form-group" :class="{'text-danger': errors.telefono}">
                    <label for="contact_phone">Teléfono:</label>
                    <input id="contact_phone" v-model="form_contact.telephone" type="text" autocomplete="off" class="form-control" placeholder="Ingrese número de teléfono" name="teléfono">
                    <small class="form-control-feedback" v-if="errors.telefono" v-text="errors.telefono[0]"></small>
                </div>
                <div class="form-group" :class="{'text-danger': errors.direccion}">
                    <label for="contact_address">Dirección:</label>
                    <textarea id="contact_address" v-model="form_contact.address" class="form-control" placeholder="Ingrese dirección de envío" rows="2" cols="10"></textarea>
                    <small class="form-control-feedback" v-if="errors.direccion" v-text="errors.direccion[0]"></small>
                </div>
            </form>
        </div>
    </div><!-- End .col-lg-4 -->
</div><!-- End .row -->

<input type="hidden" id="total_amount" data-total="0.0">

@endsection

@push('scripts')
<!-- script src="https://checkout.culqi.com/js/v3"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@7.31.1/dist/sweetalert2.all.min.js"></script>
<script src="https://momentjs.com/downloads/moment.min.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script -->


<script type="text/javascript">
    var app_cart = new Vue({
        el: '#app',
        data: {
            form_contact: {
                address:   '',
                telephone:   '',
            },
            payment_cash: {
                amount: '',
                clicked: false,
                processing: false
            },
            response_search: {},
            text_search: '',
            loading_search: false,
            identity_document_types: [{
                id: '1',
                description: 'DNI'
            }, {
                id: '6',
                description: 'RUC'
            }],
            formIdentity: {
                identity_document_type_id: ''
            },
            records: [],
            records_old: [],
            order_generated: {},
            summary: {
                subtotal: '0.0',
                tax: '0.0',
                total: '0.0',
                total_taxed: '0.0',
                total_value: '0.0',
                total_exonerated: '0.0',
                total_unaffected: '0.0',
                total_igv: '0.0'
            },
            aux_totals: {},
            form_document: {},
            user: {},
            typeDocumentSelected: '',
            response_order_total:0,
            errors: {},
            exchange_rate_sale: '',
            typeDocuments: '',
            typeDocumentList: [],
            numberDocument: ''
        },
        computed: {
            maxLength: function () {
                if (this.formIdentity.identity_document_type_id === '6') {
                    return 11
                }
                if (this.formIdentity.identity_document_type_id === '1') {
                    return 8
                }
            }
        },
        async mounted() {
          let contex = this

          $(".input_quantity").change(function (e) {
            let value = parseFloat($(this).val())
            let id = $(this).data('product')
            let row = contex.records.find(x => x.id == id)

            if (!row || !(value > 0)) return

            row.cantidad = value
            row.sub_total = (parseFloat(row.sale_unit_price) * value).toFixed(2)
            contex.persistQuantity(id, value)
            contex.applyQuote()
          })

          // Reflect the saved quantities in the touchspin inputs.
          this.records.forEach(function (row) {
            $('.input_quantity[data-product="' + row.id + '"]').val(row.cantidad)
          })

          await this.applyQuote()
        },
        created() {
            let array = localStorage.getItem('products_cart');
            array = JSON.parse(array)
            if (array) {
                // Older carts saved the whole product record in the browser; keep only public fields.
                const allowed = ['id', 'internal_id', 'unit_type_id', 'description', 'name', 'image', 'image_medium', 'image_small',
                    'sale_affectation_igv_type_id', 'igv_general_rate', 'currency_type_id', 'currency_type_symbol', 'sale_unit_price']
                this.records = array.map(function (item) {
                    let obj = {}
                    allowed.forEach(function (key) { if (item[key] !== undefined) obj[key] = item[key] })
                    // The quantity chosen on this page survives navigation (saved in the cart entry).
                    const savedQty = Number(item.cantidad)
                    obj.cantidad = Number.isFinite(savedQty) && savedQty > 0 ? savedQty : 1
                    obj.sub_total = (parseFloat(item.sale_unit_price) * obj.cantidad).toFixed(2)
                    obj.exchange_rate_sale = ''
                    return obj
                })
            }
            // console.log(this.records)
            this.initForm();

        },
        methods: {
          // Prices and totals come from the server (single source of truth, includes USD conversion).
          async applyQuote() {
            if (this.records.length) {
              try {
                const response = await axios.post('{{ route("tenant_ecommerce_cart_quote") }}', {
                  items: this.records.map(x => ({ id: x.id, cantidad: x.cantidad }))
                }, this.getHeaderConfig())
                if (response.data.success) {
                  response.data.lines.forEach((line) => {
                    let row = this.records.find(x => x.id == line.id)
                    if (!row) return
                    this.$set(row, 'sale_unit_price', Number(line.unit_pen).toFixed(2))
                    this.$set(row, 'currency_type_id', 'PEN')
                    this.$set(row, 'currency_type_symbol', 'S/')
                    this.$set(row, 'exchange_rate_sale', line.exchange_rate_sale)
                    this.$set(row, 'cantidad', line.cantidad)
                    this.$set(row, 'sub_total', Number(line.sub_total).toFixed(2))
                    // Tax data comes from the server quote so the summary equals what is charged.
                    this.$set(row, 'sale_affectation_igv_type_id', String(line.sale_affectation_igv_type_id))
                    this.$set(row, 'igv_percentage', Number(line.igv_percentage))
                  })
                  this.exchange_rate_sale = parseFloat(response.data.exchange_rate_sale) || 1
                } else if (response.data && typeof response.data.message === 'string' && response.data.message) {
                  // e.g. not enough stock / product no longer available: show it as plain text.
                  swal('Revise su carrito', response.data.message, 'warning')
                }
              } catch (e) {
                // Keep the last known prices but tell the buyer they may be outdated.
                swal('No se pudo actualizar el carrito', 'No pudimos confirmar los precios actuales. Revise su conexión e intente nuevamente antes de pagar.', 'warning')
              }
            }
            this.calculateSummary()
          },
          persistQuantity(id, quantity) {
            try {
              let stored = JSON.parse(localStorage.getItem('products_cart')) || []
              stored.forEach(function (entry) { if (entry.id == id) entry.cantidad = quantity })
              localStorage.setItem('products_cart', JSON.stringify(stored))
            } catch (e) {}
          },
          async changeExchangeRate(exchange_rate_date){
            var response = await axios.get(`/exchange_rate/ecommence/${exchange_rate_date}`)
            this.exchange_rate_sale = parseFloat(response.data.sale)
          },
          optionDocument(keepSelection) {
              const previousType = this.typeDocuments
              this.typeDocumentList = []
              this.typeDocuments = ''
              let voucher = [{id: '6', name: 'RUC'}]
              let ticket = [{id: '0', name: 'DOC'},{id: '4', name: 'CE'},{id: '1', name: 'DNI'}]
              if(this.formIdentity.identity_document_type_id === '6') {
                this.typeDocumentList = voucher
              }else if (parseFloat(this.payment_cash.amount) > 700) {
                // Boletas above S/ 700.00 must identify the buyer: "DOC" (type 0) is not allowed
                this.typeDocumentList = ticket.filter(function (t) { return t.id !== '0' })
                this.typeDocuments = ''
              } else {
                this.typeDocumentList = ticket
              }
              // Recalculating the cart must not wipe the document type already chosen.
              if (keepSelection === true && this.typeDocumentList.some(function (t) { return t.id === previousType })) {
                this.typeDocuments = previousType
              }
          },
            refreshSetDatosCliente()
            {

              this.form_document.datos_del_cliente_o_receptor.direccion = this.form_contact.address
              this.form_document.datos_del_cliente_o_receptor.telefono = this.form_contact.telephone
              this.form_document.datos_del_cliente_o_receptor.codigo_tipo_documento_identidad = this.typeDocuments
              this.form_document.datos_del_cliente_o_receptor.numero_documento = this.numberDocument
              this.form_document.datos_del_cliente_o_receptor.identity_document_type_id = this.formIdentity.identity_document_type_id

            },
            async getFormPaymentCash() {
                
                this.refreshSetDatosCliente()

                let precio = Math.round(Number(this.summary.total) * 100).toFixed(2);
                let precio_culqi = Number(this.summary.total)
                return {
                    producto: 'Compras Ecommerce Facturador Pro',
                    precio: precio,
                    precio_culqi: precio_culqi,
                    customer: this.form_document.datos_del_cliente_o_receptor,
                    items: this.records.map(x => ({ id: x.id, cantidad: x.cantidad }))
                }
            },
            async paymentCash() {
              // verifica si tiene productos seleccionado
              let product = JSON.parse(localStorage.getItem('products_cart'));

              if (product.length < 1){
                swal({
                    title: "No se han encontrado productos",
                    text: "Por favor seleccione algún producto de la tienda.",
                    type: "error"
                })
                return
              }

                // Guard against double submit while the request is pending.
                if (this.payment_cash.processing) {
                    return
                }
                this.payment_cash.processing = true

                swal({
                    title: "Estamos generando el Pago.",
                    text: `Por favor no cierre esta ventana hasta que el proceso termine.`,
                    focusConfirm: false,
                    onOpen: () => {
                        Swal.showLoading()
                    }
                });

                try {
                    let url_finally = '{{ route("tenant_ecommerce_payment_cash")}}';
                    let response = await axios.post(url_finally, await this.getFormPaymentCash(), this.getHeaderConfig())
                    if (response.data && response.data.success === true) {
                        this.saveContactDataUser()
                        this.response_order_total = response.data.order.total
                        this.order_generated = response.data.order
                        const orderNumber = response.data.order.order_id ? ` Su número de pedido es ${response.data.order.order_id}.` : ''
                        // Keep the cart (and the page) until the buyer has read the confirmation.
                        const finish = () => { this.clearShoppingCart() }
                        swal({
                            title: "Gracias por su pago!",
                            text: "En breve le enviaremos un correo electronico con los detalles de su compra." + orderNumber,
                            type: "success"
                        }).then(finish, finish)
                    } else {
                        // Business rejection (HTTP 200 with success !== true): replace the loader and keep the cart.
                        // The server message is shown as plain text only (never as the HTML title).
                        let message = response.data && typeof response.data.message === 'string' ? response.data.message.trim() : ''
                        swal({
                            title: "Pago No realizado",
                            text: message || 'No se pudo registrar el pedido. Intente nuevamente.',
                            type: "error"
                        })
                    }
                } catch (error) {
                    // Transport/HTTP error: replace the loader and keep the cart so the buyer can retry.
                    let detail = 'Sucedio algo inesperado.'
                    if (error && error.response && error.response.status === 422 && error.response.data) {
                        const first = Object.values(error.response.data)[0]
                        if (Array.isArray(first) && typeof first[0] === 'string') detail = first[0]
                    }
                    swal("Pago No realizado", detail, "error");
                    if (error && error.response && error.response.status === 422) {
                        this.errors = error.response.data;
                    } else {
                        console.log(error);
                    }
                } finally {
                    this.payment_cash.processing = false
                }

            },
            redirectHome() {
                window.location = "{{ route('tenant.ecommerce.index') }}";
            },
            getHeaderConfig() {
                let headers = {
                    "Content-Type": "application/json"
                };
                if (this.user && this.user.api_token) {
                    headers.Authorization = `Bearer ${this.user.api_token}`;
                }
                return { headers: headers };
            },
            initForm() {
              this.errors = {}
                this.user = {!! json_encode(Auth::user() ? Auth::user()->only(['id', 'name', 'email', 'identity_document_type_id', 'number', 'address', 'telephone']) : null) !!}
                if(!this.user){
                    return false
                }

                this.form_document = {
                    "acciones": {
                        "enviar_email": true,
                        "formato_pdf": "a4"
                    },
                    "serie_documento": "",
                    "numero_documento": "#",
                    "fecha_de_emision": moment().format('YYYY-MM-DD'),
                    "hora_de_emision": moment().format('HH:mm:ss'),
                    "codigo_tipo_operacion": "0101",
                    "codigo_tipo_documento": "01",
                    "codigo_tipo_moneda": "PEN",
                    "fecha_de_vencimiento": moment().format('YYYY-MM-DD'),
                    "datos_del_cliente_o_receptor": {
                        "codigo_tipo_documento_identidad": "0",
                        "numero_documento": "0",
                        "apellidos_y_nombres_o_razon_social": this.user.name,
                        "codigo_pais": "PE",
                        "ubigeo": "150101",
                        "direccion": this.user.address,
                        "correo_electronico": this.user.email,
                        "telefono": this.user.telephone
                    },
                    "totales": {},
                    "items": [],
                }


                this.formIdentity = {
                    identity_document_type_id: ''
                }

                this.form_contact.address =  this.user.address
                this.form_contact.telephone =  this.user.telephone


            },
            deleteItem(id, index) {
                //remove en fronted
                this.records.splice(index, 1)
                //set remove en localstorage
                let array = localStorage.getItem('products_cart');
                array = JSON.parse(array);
                let indexFound = array.findIndex(x => x.id == id)
                // findIndex is -1 when the id is not stored: splice(-1, 1) would remove the last product.
                if (indexFound !== -1) {
                    array.splice(indexFound, 1);
                }
                localStorage.setItem('products_cart', JSON.stringify(array));

                this.calculateSummary()


            },
            clearShoppingCart() {
              this.errors = {}
                this.records_old = this.records
                this.records = []
                localStorage.setItem('products_cart', JSON.stringify([]))
                // this.calculateSummary()

                this.summary = {
                    subtotal: '0.0',
                    tax: '0.0',
                    total: '0.00',
                    total_taxed: '0.0',
                    total_value: '0.0',
                    total_exonerated: '0.0',
                    total_igv: '0.0'
                }
                this.payment_cash.amount = '0.00'
                location.reload()
            },
            calculateSummary() {

                let total_taxed = 0
                let total_value = 0
                let total_exonerated = 0
                let total_unaffected = 0
                let total_igv = 0
                let total = 0

                this.records.forEach(function (item) {

                    // sub_total is the amount the server charges for the line (IGV included when taxed).
                    let unit_price = parseFloat(item.sub_total)
                    let affectation = parseInt(item.sale_affectation_igv_type_id, 10)
                    let percentage_igv = item.igv_percentage !== undefined && !isNaN(item.igv_percentage)
                        ? item.igv_percentage
                        : (item.igv_general_rate ? 18 : {{ \App\CoreFacturalo\Helpers\Igv\IgvRate::forTenant() }})

                    if (isNaN(affectation) || (affectation >= 10 && affectation <= 17)) {
                        let unit_value = unit_price / (1 + percentage_igv / 100)
                        total_taxed += unit_value
                        total_igv += unit_price - unit_value
                        total_value += unit_value
                    } else if (affectation === 20 || affectation === 21) {
                        total_exonerated += unit_price
                        total_value += unit_price
                    } else {
                        total_unaffected += unit_price
                        total_value += unit_price
                    }

                    total += unit_price
                })

                this.summary.total_taxed = total_taxed.toFixed(2)
                this.summary.total_exonerated = total_exonerated.toFixed(2)
                this.summary.total_unaffected = total_unaffected.toFixed(2)
                this.summary.total_igv = total_igv.toFixed(2)
                this.summary.total_value = total_value.toFixed(2)
                this.summary.total = total.toFixed(2)
                this.aux_totals = this.summary
                // console.log(this.summary)


                $("#total_amount").data('total', this.summary.total);

                this.payment_cash.amount = this.summary.total;

                // Keep the buyer's comprobante choice across recalculations; only refresh what it allows.
                this.optionDocument(true)

                // let x =
                // console.log(x)

                // let subtotal = 0.00
                // this.records.forEach(function (item) {
                //     //console.log(item)
                //     subtotal += parseFloat(item.sub_total)
                // })

                // this.summary.subtotal = subtotal.toFixed(2)
                // let tax = (subtotal * 0.18)
                // this.summary.tax = tax.toFixed(2)
                // this.summary.total = (subtotal + tax).toFixed(2)
                // $("#total_amount").data('total', this.summary.total);

                // this.payment_cash.amount = this.summary.total
            },
            saveContactDataUser()
            {
                let url_finally = '{{ route("tenant_ecommerce_user_data")}}';
                axios.post(url_finally, this.form_contact, this.getHeaderConfig())
                    .then(response => {
                       console.log(response.data)
                    })
                    .catch(error => {

                    });
            }
        }
    })

</script>

<script>
    // Card payments are rendered only when both Culqi keys are configured (see $cardEnabled).
    var culqi_public_key = {!! json_encode(!empty($cardEnabled) ? optional($configuration)->token_public_culqui : null) !!};

    function showPaymentLoading() {
        swal({
            title: "Estamos hablando con su banco",
            text: `Por favor no cierre esta ventana hasta que el proceso termine.`,
            focusConfirm: false,
            allowOutsideClick: false,
            onOpen: () => {
                Swal.showLoading()
            }
        });
    }

    async function execCulqi() {

        // The widget amount is display only: the server recomputes the total from database prices.
        let precio = Math.round((Number($("#total_amount").data('total')) * 100).toFixed(2));
        if (precio > 0) {
            CulqiPay.start({
                publicKey: culqi_public_key,
                title: "Productos Ecommerce",
                amountCents: precio,
                email: {!! json_encode(optional(Auth::user())->email) !!},
                url: "{{route('tenant_ecommerce_culqui')}}",
                csrf: $('meta[name="csrf-token"]').attr('content'),
                showLoading: showPaymentLoading,
                hideLoading: function () { swal.close(); },
                buildData: async function (base) {
                    const formpayment = await app_cart.getFormPaymentCash()

                    base.producto = 'Compras Ecommerce Facturador Pro';
                    base.precio = precio;
                    base.precio_culqi = Number($("#total_amount").data('total')).toFixed(2);
                    base.customer = JSON.stringify(formpayment.customer);
                    base.items = JSON.stringify(getItems().map(function (x) { return { id: x.id, cantidad: x.cantidad }; }));

                    return base;
                },
                onSuccess: function (data) {
                    app_cart.saveContactDataUser();
                    app_cart.order_generated = data.order;
                    // Clear the cart (it reloads the page) only after the buyer has read the confirmation.
                    const finish = function () { app_cart.clearShoppingCart(); };
                    swal({
                        title: "Gracias por su pago!",
                        text: "En breve le enviaremos un correo electronico con los detalles de su compra.",
                        type: "success"
                    }).then(finish, finish)
                },
                onFailure: function (data) {
                    swal("Pago No realizado", data.message, "error");
                },
                onError: function (error_data) {
                    console.log(error_data)
                    if (error_data.status === 422) {
                        app_cart.errors = JSON.parse( error_data.responseText);
                    }
                    swal("Pago No realizado", 'Faltan completar campos', "error");
                }
            });
        }
    }

    function getCustomer() {
        let user = {!! json_encode(Auth::user() ? Auth::user()->only(['id', 'name', 'email', 'identity_document_type_id', 'number', 'address', 'telephone']) : null) !!}
        return {
            "codigo_tipo_documento_identidad": "0",
            "numero_documento": "0",
            "apellidos_y_nombres_o_razon_social": user.name,
            "codigo_pais": "PE",
            "ubigeo": "150101",
            "direccion": app_cart.user.address,
            "correo_electronico": user.email,
            "telefono": app_cart.user.telephone
        }
    }

    function getItems() {
        return app_cart.records
    }

    function isNumberKey(evt) {
        var charCode = (evt.which) ? evt.which : evt.keyCode;
        if (charCode != 46 && charCode > 31 &&
            (charCode < 48 || charCode > 57))
            return false;
        return true;
    }

</script>

@endpush
