<?php

return [
    'trusted_proxies' => array_values(array_filter(explode(',', env('TRUSTED_PROXIES', '')))),
];
