<?php

/**
 * Host guard of the Docker stack, prepended to every PHP-FPM request of the
 * app container (auto_prepend_file in host-guard.ini).
 *
 * Invoice Ninja builds links from the request's Host header, and Caddy's
 * ":80" site answers any host: a password reset requested with a forged
 * Host mailed a valid reset link to that host. Only the hosts of APP_URL and
 * NINJA_PORTAL_URL, the ones in NINJA_EXTRA_HOSTS (comma separated) and
 * loopback names are accepted; other hosts get HTTP 400. Requests without a
 * Host header (the container's own healthcheck) pass.
 */

(static function (): void {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return;
    }
    $host = strtolower((string) preg_replace('/:\d+$/', '', $host));

    $allowed = ['localhost', '127.0.0.1'];
    foreach (['APP_URL', 'NINJA_PORTAL_URL'] as $name) {
        $allowed[] = strtolower((string) parse_url((string) getenv($name), PHP_URL_HOST));
    }
    foreach (explode(',', (string) getenv('NINJA_EXTRA_HOSTS')) as $extra) {
        $allowed[] = strtolower(trim($extra));
    }

    if (!in_array($host, array_filter($allowed), true)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo "Bad Request\n";
        exit;
    }
})();
