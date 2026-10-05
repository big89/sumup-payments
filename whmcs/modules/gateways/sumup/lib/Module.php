<?php

namespace SumUpWhmcs;

/**
 * WHMCS glue shared by the gateway module and its callback file.
 */
class Module
{
    const NAME = 'sumup';
    const VERSION = '1.0.0';
    const WIDGET_SDK_URL = 'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js';

    public static function client(array $params)
    {
        $apiKey = isset($params['apiKey']) ? $params['apiKey'] : '';

        // Can be overridden in configuration.php, for example to route requests
        // through a proxy or a mock server.
        $baseUrl = defined('SUMUP_WHMCS_API_BASE_URL') ? SUMUP_WHMCS_API_BASE_URL : SumUpClient::BASE_URL;

        return new SumUpClient($apiKey, null, function ($action, $request, $response, $parsed) use ($apiKey) {
            if (function_exists('logModuleCall')) {
                logModuleCall(Module::NAME, $action, $request, $response, $parsed, [$apiKey]);
            }
        }, $baseUrl);
    }

    public static function service(array $params)
    {
        return new PaymentService(
            self::client($params),
            new CapsuleCheckoutStore(),
            isset($params['merchantCode']) ? $params['merchantCode'] : ''
        );
    }

    public static function mode(array $params)
    {
        return (isset($params['checkoutMode']) && stripos($params['checkoutMode'], 'hosted') === 0)
            ? PaymentService::MODE_HOSTED
            : PaymentService::MODE_WIDGET;
    }

    public static function callbackUrl($systemUrl, array $query = [])
    {
        $url = rtrim($systemUrl, '/') . '/modules/gateways/callback/' . self::NAME . '.php';

        return $query ? PaymentService::appendQuery($url, $query) : $url;
    }

    public static function invoiceUrl($systemUrl, $invoiceId, array $query = [])
    {
        return PaymentService::appendQuery(rtrim($systemUrl, '/') . '/viewinvoice.php', ['id' => (int) $invoiceId] + $query);
    }
}
