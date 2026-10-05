<?php

namespace SumUpWhmcs;

/**
 * Builds the HTML returned to the WHMCS invoice page.
 */
class Renderer
{
    private static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private static function js($value)
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A plain link to the invoice, for pages other than the invoice itself
     * (for example the order confirmation page).
     */
    public static function invoiceButton($url, $label)
    {
        return '<a href="' . self::e($url) . '" class="btn btn-primary">' . self::e($label) . '</a>';
    }

    public static function hostedButton($hostedUrl, $label)
    {
        return '<a href="' . self::e($hostedUrl) . '" class="btn btn-primary sumup-pay-button">' . self::e($label) . '</a>';
    }

    /**
     * Embeds the SumUp Payment Widget for a checkout.
     *
     * @param array $o checkout_id, return_url, amount, currency, locale, email
     */
    public static function widget(array $o)
    {
        $containerId = 'sumup-card-' . preg_replace('/[^A-Za-z0-9]/', '', $o['checkout_id']);

        $config = [
            'id' => $containerId,
            'checkoutId' => $o['checkout_id'],
            'amount' => number_format((float) $o['amount'], 2, '.', ''),
            'currency' => $o['currency'],
        ];
        if (!empty($o['locale'])) {
            $config['locale'] = $o['locale'];
        }
        if (!empty($o['email'])) {
            $config['email'] = $o['email'];
        }

        return '<div class="sumup-payment" style="max-width:480px;margin:0 auto;text-align:left">'
            . '<div id="' . self::e($containerId) . '"></div>'
            . '</div>'
            . '<script src="' . self::e(Module::WIDGET_SDK_URL) . '"></script>'
            . '<script>(function () {'
            . 'var config = ' . self::js($config) . ';'
            . 'var returnUrl = ' . self::js($o['return_url']) . ';'
            . 'var done = false;'
            . 'config.onResponse = function (type) {'
            // The final status is always confirmed server-side by the callback.
            . 'if (!done && (type === "success" || type === "fail" || type === "error")) {'
            . 'done = true; setTimeout(function () { window.location.href = returnUrl; }, type === "success" ? 0 : 2500);'
            . '}'
            . '};'
            . 'if (window.SumUpCard) { window.SumUpCard.mount(config); }'
            . 'else { document.getElementById(config.id).innerHTML = "Unable to load the SumUp payment form. Please refresh the page."; }'
            . '})();</script>';
    }

    public static function error($message)
    {
        return '<div class="alert alert-danger">' . self::e($message) . '</div>';
    }
}
