/**
 * Culqi Checkout Custom + Culqi 3DS flow shared by the platform payment screen and the storefront cart.
 *
 * Requires (loaded by the page): https://js.culqi.com/checkout-js, https://3ds.culqi.com, jQuery, SweetAlert2.
 *
 * Usage: CulqiPay.start({
 *   publicKey, title, amountCents, email (optional), url, csrf,
 *   buildData(base) -> object|Promise<object>   // merges the page's own fields into the request
 *   onSuccess(data), onFailure(data), onError(xhr),
 *   showLoading(), hideLoading()
 * });
 *
 * The amount here is only used to display the widget and to challenge the card; the server always
 * recomputes the amount it charges.
 */
(function (window, $) {
    'use strict';

    var checkout = null;   // current CulqiCheckout instance
    var session = null;    // in-flight payment: { tokenId, deviceId, opts, awaiting3ds, sent3ds }
    var listening = false;

    function notify(title, text) {
        var s = window.Swal || window.swal;
        if (s) {
            s(title, text, 'error');
        }
    }

    function release() {
        session = null;
    }

    function listen() {
        if (listening) {
            return;
        }
        listening = true;

        window.addEventListener('message', function (e) {
            if (!session || !session.awaiting3ds) {
                return;
            }
            if (e.origin !== window.location.origin) {
                return;
            }
            var data = e.data;
            if (!data || typeof data !== 'object') {
                return;
            }

            if (data.error) {
                var opts = session.opts;
                release();
                if (window.Culqi3DS) { window.Culqi3DS.reset(); }
                opts.hideLoading();
                notify('Pago No realizado', 'No se pudo completar la autenticación con su banco. Intente nuevamente.');
                return;
            }

            if (data.parameters3DS && typeof data.parameters3DS === 'object') {
                session.awaiting3ds = false;
                session.sent3ds = true;
                submit(data.parameters3DS);
            }
        });
    }

    function submit(auth3ds) {
        var current = session;
        if (!current) {
            return;
        }
        var opts = current.opts;
        opts.showLoading();

        var base = {
            token: current.tokenId,
            email: current.email,
            installments: current.installments,
            device_finger_print_id: current.deviceId
        };
        if (auth3ds) {
            base.authentication_3DS = auth3ds;
        }

        Promise.resolve(opts.buildData(base)).then(function (data) {
            $.ajax({
                url: opts.url,
                method: 'post',
                headers: { 'X-CSRF-TOKEN': opts.csrf },
                data: data,
                dataType: 'JSON'
            }).done(function (response) {
                if (session !== current) {
                    return;
                }

                if (response && response.needs_3ds && !current.sent3ds) {
                    // The bank wants a challenge: authenticate the same token, then submit it again.
                    current.awaiting3ds = true;
                    opts.hideLoading();
                    try {
                        window.Culqi3DS.settings = {
                            charge: { totalAmount: opts.amountCents, returnUrl: window.location.href, currency: 'PEN' },
                            card: { email: current.email }
                        };
                        window.Culqi3DS.initAuthentication(current.tokenId);
                    } catch (err) {
                        release();
                        notify('Pago No realizado', 'No se pudo iniciar la autenticación con su banco.');
                    }
                    return;
                }

                release();
                if (response && response.needs_3ds) {
                    opts.hideLoading();
                    notify('Pago No realizado', 'Su banco no pudo autenticar el pago. Utilice otro medio de pago.');
                } else if (response && response.success === true) {
                    opts.onSuccess(response);
                } else {
                    opts.onFailure(response || {});
                }
            }).fail(function (xhr) {
                if (session === current) {
                    release();
                }
                opts.onError(xhr);
            });
        }).catch(function (err) {
            // The page could not build the request (e.g. incomplete form): nothing was sent, allow a new attempt.
            if (window.console) { console.log(err); }
            if (session === current) {
                release();
            }
            opts.hideLoading();
            notify('Pago No realizado', 'Complete los datos requeridos e intente nuevamente.');
        });
    }

    function onToken() {
        var opts = checkout && checkout.__opts;
        if (!opts) {
            return;
        }

        if (!checkout.token) {
            var err = checkout.error;
            if (window.console) { console.log(err); }
            notify('Pago No realizado', (err && err.user_message) || 'No se pudo procesar la tarjeta.');
            return;
        }

        if (session) {
            return; // a payment is already in flight: never submit a second token
        }

        var token = checkout.token;
        var metadata = token.metadata || {};
        session = {
            tokenId: token.id,
            email: token.email || opts.email || '',
            installments: metadata.installments || null,
            deviceId: checkout.__deviceId || null,
            opts: opts,
            awaiting3ds: false,
            sent3ds: false
        };
        checkout.close();
        submit(null);
    }

    window.CulqiPay = {
        start: function (opts) {
            if (session) {
                return; // payment in progress
            }
            if (typeof window.CulqiCheckout === 'undefined' || typeof window.Culqi3DS === 'undefined') {
                notify('Pago No disponible', 'No se pudo cargar la pasarela de pago. Recargue la página e intente nuevamente.');
                return;
            }
            if (!opts.publicKey || !(opts.amountCents > 0)) {
                return;
            }

            listen();

            var Culqi3DS = window.Culqi3DS;
            Culqi3DS.reset();
            Culqi3DS.publicKey = opts.publicKey;
            Culqi3DS.settings = {
                charge: { totalAmount: opts.amountCents, returnUrl: window.location.href, currency: 'PEN' },
                card: { email: opts.email || '' }
            };
            Culqi3DS.options = {
                showModal: true,
                showLoading: true,
                showIcon: true,
                closeModalAction: function () {
                    // The customer closed the 3DS window: stop and allow a new attempt.
                    if (session && session.awaiting3ds) {
                        var o = session.opts;
                        release();
                        o.hideLoading();
                        notify('Pago No realizado', 'La autenticación con su banco fue cancelada.');
                    }
                }
            };

            Promise.resolve(Culqi3DS.generateDevice()).catch(function () { return null; }).then(function (deviceId) {
                if (checkout) {
                    try { checkout.close(); } catch (e) { /* already closed */ }
                }

                var config = {
                    settings: { title: opts.title, currency: 'PEN', amount: opts.amountCents },
                    options: {
                        lang: 'auto',
                        installments: true,
                        modal: true,
                        paymentMethods: {
                            tarjeta: true, yape: false, billetera: false, bancaMovil: false, agente: false, cuotealo: false
                        },
                        paymentMethodsSort: ['tarjeta']
                    }
                };
                if (opts.email) {
                    config.client = { email: opts.email };
                }

                checkout = new window.CulqiCheckout(opts.publicKey, config);
                checkout.__opts = opts;
                checkout.__deviceId = typeof deviceId === 'string' ? deviceId.slice(0, 100) : null;
                checkout.culqi = onToken;
                checkout.open();
            });
        }
    };
})(window, window.jQuery);
