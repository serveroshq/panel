export const DOMAIN_PATTERN =
    /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;

/** A domain as someone might paste it, without scheme, path or port. */
export function bareDomain(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/^[a-z]+:\/\//, '')
        .replace(/[/?#].*$/, '')
        .replace(/:\d+$/, '');
}

export type Proxy = 'caddy' | 'nginx' | 'apache' | 'traefik';

export const PROXY_NAMES: Record<Proxy, string> = {
    caddy: 'Caddy',
    nginx: 'nginx',
    apache: 'Apache',
    traefik: 'Traefik',
};

export function isProxy(value: string | null | undefined): value is Proxy {
    return !!value && value in PROXY_NAMES;
}

/** Where an app can be reached: a free sslip.io name, its own domain, or only on the machine. */
export type AddressMode = 'free' | 'domain' | 'none';
