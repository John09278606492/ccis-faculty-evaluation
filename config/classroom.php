<?php

return [
    // Empty host uses the page hostname so other LAN devices never connect to their own localhost.
    'websocket_host' => env('CLASSROOM_WEBSOCKET_HOST'),
    'websocket_port' => (int) env('CLASSROOM_WEBSOCKET_PORT', env('REVERB_PORT', 8085)),
    'websocket_scheme' => env('CLASSROOM_WEBSOCKET_SCHEME'),
];
