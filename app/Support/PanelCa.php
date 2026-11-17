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

    /** The CA's certificates, never anything else in the file, or null when there isn't one (or it isn't written yet). */
    public static function pem(): ?string
    {
        $path = config('daemon.panel_ca');
        $file = is_string($path) && is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if (! is_string($file) || preg_match_all('/-----BEGIN CERTIFICATE-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----/', $file, $blocks) === 0) {
            return null;
        }

        return implode("\n", $blocks[0])."\n";
    }
}
