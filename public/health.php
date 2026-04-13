<?php

// Minimal healthcheck — no Laravel, no DB, no session
// Used by Railway to verify container is running
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';
