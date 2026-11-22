@extends('tenant.layouts.app')

@section('content')
    <tenant-account-payment-index></tenant-account-payment-index>
@endsection


@push('scripts')
<script src="https://js.culqi.com/checkout-js"></script>
<script src="https://3ds.culqi.com" defer></script>
<script src="{{ asset('payments/culqi-checkout.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@7.31.1/dist/sweetalert2.all.min.js"></script>

<script>
    var culqi_public_key = {!! json_encode($token_public_culqui) !!};
    var culqi_client_email = {!! json_encode(optional(auth()->user())->email) !!};

    var price_culqi_payment_account = 0;
    var price_payment_account = 0;

    var id_payment_account = null;

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

    function execCulqi(id, payment) {

        id_payment_account  = id
        price_culqi_payment_account =  Math.round( Number(payment).toFixed(2))

        price_payment_account = Math.round((Number(payment).toFixed(2)) * 100)

        CulqiPay.start({
            publicKey: culqi_public_key,
            title: "Pago de Cuenta Facturador",
            amountCents: price_payment_account,
            email: culqi_client_email,
            url: "{{route('tenant.account.payment_culqui')}}",
            csrf: $('meta[name="csrf-token"]').attr('content'),
            showLoading: showPaymentLoading,
            hideLoading: function () { swal.close(); },
            buildData: function (base) {
                base.producto = 'Pago Progamado Cuenta Facturador Pro';
                base.precio = price_payment_account;
                base.precio_culqi = price_culqi_payment_account;
                base.id_payment_account = id_payment_account;
                base.items = [{ description: 'Pago programado facturador', cantidad: '1', unit_type_id: 'NIU' }];
                return base;
            },
            onSuccess: function () {
                swal({
                    title: "Gracias por su pago!",
                    text: "En breve le enviaremos un correo electronico con los detalles de su compra.",
                    type: "success"
                }).then((x) => {
                    location.reload();
                })
            },
            onFailure: function (data) {
                swal({
                    title: "Pago No realizado!",
                    text: data.message,
                    type: "error"
                }).then((x) => {
                    location.reload();
                })
            },
            onError: function () {
                swal({
                    title: "Pago No realizado!",
                    text: "Tuvimos un problema al procesar el pago.",
                    type: "error"
                }).then((x) => {
                    location.reload();
                })
            }
        });

    }

</script>
@endpush
