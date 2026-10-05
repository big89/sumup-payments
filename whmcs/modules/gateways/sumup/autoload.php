<?php

foreach (['Http', 'Money', 'SumUpClient', 'CheckoutStore', 'PaymentService', 'Module', 'Renderer'] as $sumupWhmcsFile) {
    require_once __DIR__ . '/lib/' . $sumupWhmcsFile . '.php';
}
unset($sumupWhmcsFile);
