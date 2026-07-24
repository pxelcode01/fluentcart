<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around the UddoktaPay REST API.
 *
 * Endpoints (relative to the configured base URL, which already
 * includes /api/checkout-v2 per UddoktaPay's own convention):
 *   POST {base}                -> create a payment, returns payment_url
 *   POST {base-root}/verify-payment -> verify by invoice_id
 *
 * Auth header: RT-UDDOKTAPAY-API-KEY
 */
class UddoktaPay_FC_API
{
    /** @var UddoktaPay_FC_Settings */
    private $settings;

    public function __construct(UddoktaPay_FC_Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * The API root is the checkout URL with the trailing
     * /checkout-v2 segment stripped off, e.g.
     * https://sandbox.uddoktapay.com/api
     */
    private function apiRoot()
    {
        $url = $this->settings->getApiUrl();
        $pos = strpos($url, '/api');
        if ($pos !== false) {
            return substr($url, 0, $pos + 4);
        }
        return $url;
    }

    private function request($url, $body)
    {
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'RT-UDDOKTAPAY-API-KEY' => $this->settings->getApiKey(),
                'Accept'                => 'application/json',
                'Content-Type'          => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400 || empty($data)) {
            return [
                'success' => false,
                'message' => $data['message'] ?? __('UddoktaPay API request failed.', 'uddoktapay-for-fluentcart'),
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
        ];
    }

    /**
     * Create a payment (hosted checkout). Returns the payment_url
     * the customer should be redirected to, or a WP_Error-shaped
     * array on failure.
     */
    public function createPayment(array $payload)
    {
        $result = $this->request($this->settings->getApiUrl(), $payload);

        if (!$result['success']) {
            return $result;
        }

        if (empty($result['data']['payment_url'])) {
            return [
                'success' => false,
                'message' => $result['data']['message'] ?? __('No payment URL returned by UddoktaPay.', 'uddoktapay-for-fluentcart'),
            ];
        }

        return [
            'success'      => true,
            'payment_url'  => $result['data']['payment_url'],
        ];
    }

    /**
     * Verify a payment by invoice_id (from redirect return or webhook).
     */
    public function verifyPayment($invoiceId)
    {
        $url = $this->apiRoot() . '/verify-payment';
        return $this->request($url, ['invoice_id' => $invoiceId]);
    }
}
