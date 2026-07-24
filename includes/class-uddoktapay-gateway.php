<?php

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Helpers\Helper;

// NOTE: verify these three against the working namespaces already used
// in your bkash-nagad-gateway plugin — FluentCart's model/enum paths
// aren't fully documented publicly, so these are best-effort based on
// FluentCart's own dev docs examples.
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Order;
use FluentCart\App\Helpers\Status;

class UddoktaPay_FC_Gateway extends AbstractPaymentGateway
{
    public array $supportedFeatures = ['payment', 'webhook'];

    /** @var UddoktaPay_FC_API */
    private $api;

    public function __construct()
    {
        parent::__construct(new UddoktaPay_FC_Settings());
        $this->api = new UddoktaPay_FC_API($this->settings);
    }

    public function boot()
    {
        // Confirm-URL ajax handler is registered independently in the
        // main plugin file (on plugins_loaded) so it doesn't depend
        // on FluentCart definitely calling boot() on this instance.
    }

    public function meta(): array
    {
        return [
            'title'              => __('UddoktaPay (bKash/Nagad/Rocket/Upay)', 'uddoktapay-for-fluentcart'),
            'route'              => UDDOKTAPAY_FC_SLUG,
            'slug'               => UDDOKTAPAY_FC_SLUG,
            'label'              => 'UddoktaPay',
            'admin_title'        => 'UddoktaPay',
            'description'        => __('Accept bKash, Nagad, Rocket and Upay payments via UddoktaPay.', 'uddoktapay-for-fluentcart'),
            'logo'               => UDDOKTAPAY_FC_URL . 'assets/images/logo.svg',
            'icon'               => UDDOKTAPAY_FC_URL . 'assets/images/icon.svg',
            'brand_color'        => '#7C3AED',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
    }

    public function fields(): array
    {
        return [
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'uddoktapay-for-fluentcart'),
                        'value'  => 'live',
                        'schema' => [
                            'live_api_key' => [
                                'value'       => '',
                                'label'       => __('Live API Key', 'uddoktapay-for-fluentcart'),
                                'type'        => 'password',
                                'placeholder' => __('Enter your live RT-UDDOKTAPAY-API-KEY', 'uddoktapay-for-fluentcart'),
                            ],
                            'live_api_url' => [
                                'value'       => '',
                                'label'       => __('Live API Base URL', 'uddoktapay-for-fluentcart'),
                                'type'        => 'text',
                                'placeholder' => __('e.g. https://pay.yourdomain.com/api/checkout-v2', 'uddoktapay-for-fluentcart'),
                            ],
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'uddoktapay-for-fluentcart'),
                        'value'  => 'test',
                        'schema' => [
                            'test_api_key' => [
                                'value'       => '',
                                'label'       => __('Sandbox API Key', 'uddoktapay-for-fluentcart'),
                                'type'        => 'password',
                                'placeholder' => __('Enter your sandbox RT-UDDOKTAPAY-API-KEY', 'uddoktapay-for-fluentcart'),
                            ],
                            'test_api_url' => [
                                'value'       => 'https://sandbox.uddoktapay.com/api/checkout-v2',
                                'label'       => __('Sandbox API Base URL', 'uddoktapay-for-fluentcart'),
                                'type'        => 'text',
                                'placeholder' => 'https://sandbox.uddoktapay.com/api/checkout-v2',
                            ],
                        ],
                    ],
                ],
            ],
            'webhook_info' => [
                'value' => '<code>' . esc_url(home_url('/?fluent-cart=fct_payment_listener_ipn&method=' . UDDOKTAPAY_FC_SLUG)) . '</code>'
                    . '<p class="description">' . esc_html__('UddoktaPay is redirect-verified by default; this webhook URL is only needed as a backup confirmation channel if you want to set it in your UddoktaPay instance settings.', 'uddoktapay-for-fluentcart') . '</p>',
                'label' => __('Webhook URL', 'uddoktapay-for-fluentcart'),
                'type'  => 'html_attr',
            ],
        ];
    }

    /**
     * Called by FluentCart before persisting posted settings - lets us
     * encrypt the API key the same way FluentCart's own gateways
     * encrypt secret keys, matching the pattern in the official
     * Paystack reference implementation.
     *
     * Only encrypts when the submitted value actually differs from
     * what's already stored - otherwise resubmitting the form (which
     * redisplays the already-encrypted ciphertext in the field) would
     * re-encrypt an already-encrypted value on every save, corrupting
     * it a bit further each time.
     */
    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        foreach (['test_api_key', 'live_api_key'] as $field) {
            if (!isset($data[$field])) {
                continue;
            }

            $old = $oldSettings[$field] ?? '';

            if ($data[$field] === $old || $data[$field] === '') {
                // Unchanged (still holds the old ciphertext) or cleared -
                // leave as-is, don't re-encrypt.
                continue;
            }

            $data[$field] = Helper::encryptKey($data[$field]);
        }

        return $data;
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    /**
     * Required: enqueue the small checkout script that renders the
     * "you'll be redirected" notice and enables the checkout button.
     */
    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle'  => 'uddoktapay-fc-checkout',
                'src'     => UDDOKTAPAY_FC_URL . 'assets/js/checkout.js',
                'version' => UDDOKTAPAY_FC_VERSION,
            ],
        ];
    }

    /**
     * Required: process the payment. UddoktaPay is a hosted-redirect
     * gateway, so we create the payment then hand FluentCart a
     * redirect_to URL.
     */
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $order       = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $customer    = $paymentInstance->order->customer;

        $fullName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        if ($fullName === '') {
            $fullName = __('Customer', 'uddoktapay-for-fluentcart');
        }

        $payload = [
            'full_name'    => $fullName,
            'email'        => $customer->email ?? '',
            'amount'       => (string) number_format($transaction->total / 100, 2, '.', ''),
            'metadata'     => [
                'order_id'       => $order->id,
                'order_uuid'     => $order->uuid,
                'transaction_id' => $transaction->uuid,
            ],
            'redirect_url' => add_query_arg([
                'action'         => 'uddoktapay_fc_confirm',
                'transaction_id' => $transaction->uuid,
            ], admin_url('admin-ajax.php')),
            'return_type'  => 'GET',
            'cancel_url'   => $this->getCancelUrl(),
            'webhook_url'  => home_url('/?fluent-cart=fct_payment_listener_ipn&method=' . UDDOKTAPAY_FC_SLUG),
        ];

        $result = $this->api->createPayment($payload);

        if (empty($result['success'])) {
            return [
                'status'  => 'failed',
                'message' => $result['message'] ?? __('Could not initiate UddoktaPay payment.', 'uddoktapay-for-fluentcart'),
            ];
        }

        return [
            'redirect_to' => $result['payment_url'],
            'status'      => 'success',
            'message'     => __('Redirecting to UddoktaPay...', 'uddoktapay-for-fluentcart'),
        ];
    }

    /**
     * Required (frontend data for the checkout container).
     */
    public function getOrderInfo(array $data)
    {
        wp_send_json([
            'status'       => 'success',
            'payment_args' => [],
            'message'      => __('Order info retrieved', 'uddoktapay-for-fluentcart'),
        ], 200);
    }

    /**
     * Return-URL confirmation: customer lands back here after paying
     * (or cancelling) on UddoktaPay's hosted page.
     */
    public function confirmPayment()
    {
        $transactionId = sanitize_text_field($_REQUEST['transaction_id'] ?? '');
        $invoiceId     = sanitize_text_field($_REQUEST['invoice_id'] ?? '');

        $transaction = OrderTransaction::query()->where('uuid', $transactionId)->first();

        if (!$transaction) {
            wp_die(esc_html__('Invalid or expired transaction.', 'uddoktapay-for-fluentcart'));
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            wp_redirect($this->getSuccessUrl($transaction));
            exit;
        }

        if (!$invoiceId) {
            // True cancellation - customer backed out before paying.
            wp_redirect($this->getCancelUrl());
            exit;
        }

        // We have an invoice_id, meaning a payment attempt happened.
        // Verify/update status, but land on the receipt page either
        // way (completed or still pending) - the receipt page itself
        // reflects the current status. Only a missing invoice_id
        // means true cancellation.
        $this->verifyAndComplete($invoiceId, $transaction);
        $transaction->refresh();

        wp_redirect($this->getSuccessUrl($transaction));
        exit;
    }

    /**
     * Required: handle the UddoktaPay webhook (fired by FluentCart's
     * fct_payment_listener_ipn router for this gateway's slug).
     */
    public function handleIPN()
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);

        $invoiceId = $data['invoice_id'] ?? null;
        if (!$invoiceId) {
            wp_send_json(['status' => 'ignored'], 200);
            return;
        }

        $verified = $this->api->verifyPayment($invoiceId);
        if (empty($verified['success'])) {
            wp_send_json(['status' => 'verification_failed'], 200);
            return;
        }

        $meta          = $verified['data']['metadata'] ?? [];
        $transactionId = $meta['transaction_id'] ?? null;

        if (!$transactionId) {
            wp_send_json(['status' => 'no_transaction_ref'], 200);
            return;
        }

        $transaction = OrderTransaction::query()->where('uuid', $transactionId)->first();
        if ($transaction) {
            $this->completeTransaction($transaction, $verified['data'], $invoiceId);
        }

        wp_send_json(['status' => 'processed'], 200);
    }

    private function verifyAndComplete($invoiceId, $transaction)
    {
        $verified = $this->api->verifyPayment($invoiceId);
        if (empty($verified['success'])) {
            return;
        }
        $this->completeTransaction($transaction, $verified['data'], $invoiceId);
    }

    private function completeTransaction($transaction, array $vendorData, $invoiceId)
    {
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return; // already processed, avoid double-crediting
        }

        $paymentStatus = strtolower($vendorData['status'] ?? '');
        if (!in_array($paymentStatus, ['completed', 'paid'], true)) {
            return;
        }

        $transaction->fill([
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id' => $invoiceId,
        ]);
        $transaction->save();

        $order = Order::query()->find($transaction->order_id);
        if ($order) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }
    }
}
