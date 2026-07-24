<?php

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;

class UddoktaPay_FC_Settings extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_uddoktapay';

    /**
     * Mirrors FluentCart's own PaystackSettingsBase pattern exactly:
     * load real persisted settings via the inherited getCachedSettings(),
     * merge over defaults, and cache in $this->settings. This is what
     * actually connects to FluentCart's storage - reinventing our own
     * get_option() based storage (previous version of this file) was
     * the root cause of settings never saving/loading.
     */
    public function __construct()
    {
        parent::__construct();

        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        $this->settings = $settings;
    }

    public static function getDefaults()
    {
        return [
            'is_active'    => 'no',
            'payment_mode' => 'test', // test|live
            'test_api_key' => '',
            'test_api_url' => 'https://sandbox.uddoktapay.com/api/checkout-v2',
            'live_api_key' => '',
            'live_api_url' => '',
        ];
    }

    public function isActive(): bool
    {
        return isset($this->settings['is_active']) && $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $this->settings;
    }

    public function getMode()
    {
        return $this->get('payment_mode');
    }

    public function isTestMode()
    {
        return $this->getMode() === 'test';
    }

    public function getApiKey($mode = 'current')
    {
        if ($mode === 'current' || !$mode) {
            $mode = $this->getMode();
        }
        $key = $this->get($mode . '_api_key');
        return $key ? Helper::decryptKey($key) : '';
    }

    public function getApiUrl($mode = 'current')
    {
        if ($mode === 'current' || !$mode) {
            $mode = $this->getMode();
        }
        return rtrim($this->get($mode . '_api_url'), '/');
    }
}
