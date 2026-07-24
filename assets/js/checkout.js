window.addEventListener("fluent_cart_load_payments_uddoktapay", function (e) {
    const submitButton = window.fluentcart_checkout_vars?.submit_button;
    const gatewayContainer = document.querySelector(
        '.fluent-cart-checkout_embed_payment_container_uddoktapay'
    );

    if (gatewayContainer) {
        gatewayContainer.innerHTML =
            '<p>' +
            "You'll be redirected to UddoktaPay to complete your payment via bKash, Nagad, Rocket or Upay." +
            '</p>';
    }

    // Hosted redirect gateway — no SDK to load, just enable checkout.
    e.detail.paymentLoader.enableCheckoutButton(submitButton?.text);
});
