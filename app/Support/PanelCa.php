<?php

namespace App\Support;

/**
 * The CA behind the panel's own HTTPS certificate, when it isn't a public
 * one (DAEMON_PANEL_CA): a self-hosted panel on a home network, with
 * Caddy's local_certs. It's served at /panel-ca.crt, and the install
 * command checks it against its SHA-256 before anything trusts it.
 */
final class PanelCa
{
    public static function configured(): bool
    {
        return filled(config('daemon.panel_ca'));
    }

    /** The CA, or null when there isn't one (or it isn't written yet). */
    public static function pem(): ?string
    {
        $path = config('daemon.panel_ca');
        $pem = is_string($path) && is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return is_string($pem) && str_contains($pem, '-----BEGIN CERTIFICATE-----') ? $pem : null;
    }
}
