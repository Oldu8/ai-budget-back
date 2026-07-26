<?php

declare(strict_types=1);

// Where each currency's rate comes from. EUR is the pivot (implicit 1.0) and is
// not fetched. The full supported set is EUR + ecb + nbu (24 currencies).
// BGN was dropped: Bulgaria joined the euro on 2026-01-01, so ECB no longer
// publishes the lev — Bulgarian amounts are now EUR.
$ecb = [
    'USD', 'PLN', 'GBP', 'CAD', 'CHF', 'TRY', 'CZK', 'HUF', 'RON',
    'SEK', 'NOK', 'DKK', 'JPY', 'CNY', 'AUD', 'BRL', 'ILS', 'MXN', 'IDR', 'THB',
];
$nbu = ['UAH', 'MDL', 'VND'];

return [
    // Pivot currency used only while building the rate matrix at sync time
    // (sources quote against EUR or, for NBU, against UAH). Stored rates are
    // direct cross pairs, so conversion never pivots through EUR at read time.
    'base' => 'EUR',

    // Earliest date we hold rate history for. Transactions dated before this are
    // rejected (see backend validation). Keep in sync with the backfill range.
    'earliest_date' => '2026-01-01',

    'sources' => [
        // ECB reference rates via Frankfurter — all returned in one call.
        'ecb' => $ecb,
        // National Bank of Ukraine — currencies ECB does not publish.
        'nbu' => $nbu,
    ],

    // Everything we track and validate against (EUR pivot + both sources).
    'supported' => array_merge(['EUR'], $ecb, $nbu),
];
