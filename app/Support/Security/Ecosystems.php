<?php

namespace App\Support\Security;

use App\Models\Machine;

/**
 * How a machine's OS and packages are named in OSV: the ecosystem for its
 * release (Ubuntu:24.04:LTS, Debian:12, AlmaLinux:9, Rocky Linux:9) and,
 * for Debian and Ubuntu, the source package an installed one is built
 * from, since their trackers list source packages (libssl3t64 is
 * openssl's). The daemon reports binary names, so this knows the common
 * ones and otherwise tries the name as it is.
 */
final class Ecosystems
{
    /** Installed package => the source package it's built from. */
    private const array SOURCES = [
        'libc6' => 'glibc', 'libc-bin' => 'glibc', 'libc-dev-bin' => 'glibc', 'libc6-dev' => 'glibc', 'libc-l10n' => 'glibc', 'locales' => 'glibc', 'libc6-i386' => 'glibc',
        'libssl3' => 'openssl', 'libssl3t64' => 'openssl', 'libssl-dev' => 'openssl', 'libssl1.1' => 'openssl', 'openssl-provider-legacy' => 'openssl',
        'openssh-client' => 'openssh', 'openssh-server' => 'openssh', 'openssh-sftp-server' => 'openssh', 'ssh' => 'openssh',
        'libsystemd0' => 'systemd', 'libsystemd-shared' => 'systemd', 'libudev1' => 'systemd', 'systemd-sysv' => 'systemd', 'systemd-timesyncd' => 'systemd',
        'systemd-resolved' => 'systemd', 'systemd-dev' => 'systemd', 'udev' => 'systemd', 'libnss-systemd' => 'systemd', 'libpam-systemd' => 'systemd',
        'libcurl4' => 'curl', 'libcurl4t64' => 'curl', 'libcurl3-gnutls' => 'curl', 'libcurl3t64-gnutls' => 'curl', 'libcurl4-openssl-dev' => 'curl',
        'libexpat1' => 'expat', 'libgnutls30' => 'gnutls28', 'libgnutls30t64' => 'gnutls28', 'libsqlite3-0' => 'sqlite3', 'zlib1g' => 'zlib', 'zlib1g-dev' => 'zlib',
        'libpam0g' => 'pam', 'libpam-modules' => 'pam', 'libpam-modules-bin' => 'pam', 'libpam-runtime' => 'pam',
        'libnss3' => 'nss', 'libkrb5-3' => 'krb5', 'libgssapi-krb5-2' => 'krb5', 'libk5crypto3' => 'krb5', 'libkrb5support0' => 'krb5',
        'libldap-2.5-0' => 'openldap', 'libldap2' => 'openldap', 'libldap-common' => 'openldap',
        'libglib2.0-0' => 'glib2.0', 'libglib2.0-0t64' => 'glib2.0', 'libglib2.0-data' => 'glib2.0', 'libglib2.0-bin' => 'glib2.0',
        'libsasl2-2' => 'cyrus-sasl2', 'libsasl2-modules' => 'cyrus-sasl2', 'libsasl2-modules-db' => 'cyrus-sasl2',
        'libssh-4' => 'libssh', 'libssh2-1' => 'libssh2', 'libssh2-1t64' => 'libssh2', 'libnghttp2-14' => 'nghttp2', 'libbrotli1' => 'brotli',
        'libzstd1' => 'libzstd', 'liblzma5' => 'xz-utils', 'libbz2-1.0' => 'bzip2', 'libpcre2-8-0' => 'pcre2', 'libelf1' => 'elfutils', 'libelf1t64' => 'elfutils',
        'libxml2' => 'libxml2', 'libxslt1.1' => 'libxslt', 'libyaml-0-2' => 'libyaml', 'libjson-c5' => 'json-c', 'libarchive13' => 'libarchive', 'libarchive13t64' => 'libarchive',
        'libpng16-16' => 'libpng1.6', 'libpng16-16t64' => 'libpng1.6', 'libtiff6' => 'tiff', 'libjpeg-turbo8' => 'libjpeg-turbo', 'libjpeg62-turbo' => 'libjpeg-turbo',
        'libwebp7' => 'libwebp', 'libgd3' => 'libgd2', 'libcups2' => 'cups', 'libcups2t64' => 'cups', 'libuv1' => 'libuv1', 'libuv1t64' => 'libuv1',
        'gpg' => 'gnupg2', 'gpgv' => 'gnupg2', 'gnupg' => 'gnupg2', 'dirmngr' => 'gnupg2', 'gpg-agent' => 'gnupg2', 'gpgsm' => 'gnupg2', 'gnupg-utils' => 'gnupg2', 'gpgconf' => 'gnupg2',
        'login' => 'shadow', 'passwd' => 'shadow', 'xxd' => 'vim', 'vim-common' => 'vim', 'vim-runtime' => 'vim', 'vim-tiny' => 'vim',
        'libblkid1' => 'util-linux', 'libmount1' => 'util-linux', 'libuuid1' => 'util-linux', 'libsmartcols1' => 'util-linux', 'libfdisk1' => 'util-linux',
        'mount' => 'util-linux', 'fdisk' => 'util-linux', 'bsdutils' => 'util-linux', 'bsdextrautils' => 'util-linux', 'uuid-runtime' => 'util-linux',
        'libext2fs2' => 'e2fsprogs', 'libext2fs2t64' => 'e2fsprogs', 'libcom-err2' => 'e2fsprogs', 'libss2' => 'e2fsprogs', 'logsave' => 'e2fsprogs',
        'libproc2-0' => 'procps', 'libnftables1' => 'nftables', 'libip4tc2' => 'iptables', 'libip6tc2' => 'iptables', 'libxtables12' => 'iptables',
        'libncursesw6' => 'ncurses', 'libtinfo6' => 'ncurses', 'ncurses-base' => 'ncurses', 'ncurses-bin' => 'ncurses', 'libreadline8' => 'readline', 'libreadline8t64' => 'readline',
        'libgmp10' => 'gmp', 'libffi8' => 'libffi', 'libseccomp2' => 'libseccomp', 'libselinux1' => 'libselinux', 'libtirpc3' => 'libtirpc', 'libtirpc3t64' => 'libtirpc',
        'libidn2-0' => 'libidn2', 'libpsl5' => 'libpsl', 'libpsl5t64' => 'libpsl', 'libsodium23' => 'libsodium', 'libgcrypt20' => 'libgcrypt20', 'libtasn1-6' => 'libtasn1-6',
        'libapt-pkg6.0' => 'apt', 'libapt-pkg6.0t64' => 'apt', 'apt-utils' => 'apt', 'libdpkg-perl' => 'dpkg', 'dpkg-dev' => 'dpkg',
        'perl-base' => 'perl', 'perl-modules-5.36' => 'perl', 'perl-modules-5.38' => 'perl', 'perl-modules-5.40' => 'perl',
        'libperl5.36' => 'perl', 'libperl5.38' => 'perl', 'libperl5.38t64' => 'perl', 'libperl5.40' => 'perl',
        'nginx-common' => 'nginx', 'nginx-core' => 'nginx', 'nginx-full' => 'nginx', 'nginx-light' => 'nginx',
        'apache2-bin' => 'apache2', 'apache2-data' => 'apache2', 'apache2-utils' => 'apache2',
        'bind9-host' => 'bind9', 'bind9-dnsutils' => 'bind9', 'bind9-libs' => 'bind9', 'dnsutils' => 'bind9',
        'git-man' => 'git', 'redis-server' => 'redis', 'redis-tools' => 'redis', 'libnode109' => 'nodejs', 'libnode115' => 'nodejs',
        'python3-urllib3' => 'python-urllib3', 'python3-requests' => 'requests', 'python3-jinja2' => 'jinja2', 'python3-cryptography' => 'python-cryptography',
        'python3-setuptools' => 'setuptools', 'python3-pip' => 'python-pip', 'python3-idna' => 'python-idna', 'python3-certifi' => 'python-certifi',
        'python3-yaml' => 'pyyaml', 'python3-twisted' => 'twisted', 'python3-tornado' => 'python-tornado', 'python3-pil' => 'pillow',
        'libmariadb3' => 'mariadb', 'mariadb-client' => 'mariadb', 'mariadb-server' => 'mariadb', 'mariadb-common' => 'mariadb',
        'libsmbclient' => 'samba', 'libwbclient0' => 'samba', 'samba-libs' => 'samba', 'samba-common' => 'samba',
        'libgnutls-dane0' => 'gnutls28', 'libunbound8' => 'unbound', 'libevent-2.1-7' => 'libevent', 'libevent-2.1-7t64' => 'libevent',
        'libicu72' => 'icu', 'libicu74' => 'icu', 'libicu76' => 'icu', 'libnetplan1' => 'netplan.io', 'netplan-generator' => 'netplan.io',
        'libpolkit-gobject-1-0' => 'policykit-1', 'libpolkit-agent-1-0' => 'policykit-1', 'polkitd' => 'policykit-1', 'pkexec' => 'policykit-1',
        'libdbus-1-3' => 'dbus', 'dbus-bin' => 'dbus', 'dbus-daemon' => 'dbus', 'dbus-system-bus-common' => 'dbus', 'dbus-session-bus-common' => 'dbus',
        'libgstreamer1.0-0' => 'gstreamer1.0', 'libmagickcore-6.q16-6' => 'imagemagick', 'libmagickwand-6.q16-6' => 'imagemagick',
        'libsnmp40' => 'net-snmp', 'libsnmp-base' => 'net-snmp', 'libxmlsec1' => 'xmlsec1', 'libxmlsec1t64' => 'xmlsec1',
        'cloud-init-base' => 'cloud-init', 'libnss-myhostname' => 'systemd', 'libmagic1' => 'file', 'libmagic1t64' => 'file', 'libmagic-mgc' => 'file',
        'sudo-ldap' => 'sudo', 'libgnutls28-dev' => 'gnutls28', 'libkmod2' => 'kmod', 'libcap2' => 'libcap2', 'libcap2-bin' => 'libcap2',
    ];

    /** [os-release id => OSV's name], for the RPM distros (same naming for every release). */
    private const array RPM = ['almalinux' => 'AlmaLinux', 'rocky' => 'Rocky Linux'];

    /** The OSV ecosystem for the machine's OS release, if OSV covers it. */
    public static function for(Machine $machine): ?string
    {
        $os = is_array($machine->os_release) ? $machine->os_release : [];

        $ecosystem = self::forRelease((string) ($os['id'] ?? ''), (string) ($os['version_id'] ?? ''));

        // Machines' updates come from apt and dnf; Alpine is for images.
        return $ecosystem !== null && str_starts_with($ecosystem, 'Alpine:') ? null : $ecosystem;
    }

    /**
     * The OSV ecosystem for an OS release by its os-release ID and
     * VERSION_ID, if OSV covers it: a machine's, or an image's (Alpine
     * too, which images use and machines rarely do).
     */
    public static function forRelease(string $id, string $version): ?string
    {
        $id = strtolower($id);

        if ($id === 'ubuntu' && preg_match('/^(\d{2})\.(\d{2})$/', $version, $m)) {
            // LTS is every other April: 20.04, 22.04, 24.04, 26.04.
            return 'Ubuntu:'.$version.($m[2] === '04' && (int) $m[1] % 2 === 0 ? ':LTS' : '');
        }
        if ($id === 'debian' && preg_match('/^(\d+)/', $version, $m)) {
            return 'Debian:'.$m[1];
        }
        if ($id === 'alpine' && preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
            return 'Alpine:v'.$m[1].'.'.$m[2];
        }
        if (isset(self::RPM[$id]) && preg_match('/^(\d+)/', $version, $m)) {
            return self::RPM[$id].':'.$m[1];
        }

        return null;
    }

    public static function isDeb(string $ecosystem): bool
    {
        return str_starts_with($ecosystem, 'Ubuntu:') || str_starts_with($ecosystem, 'Debian:');
    }

    /**
     * The package name to look up in OSV, or null for one to leave out:
     * kernels (thousands of CVEs, judged by the advisories on running
     * kernels instead) and anything not named like a package.
     */
    public static function source(string $ecosystem, string $package): ?string
    {
        $package = strtolower(explode(':', $package)[0]);

        if (! preg_match('/^[a-z0-9][a-z0-9+._-]*$/', $package)
            || preg_match('/^(linux|kernel)(-|$)/', $package)) {
            return null;
        }
        if (! self::isDeb($ecosystem)) {
            // dnf lists name.arch.
            return preg_replace('/\.(x86_64|aarch64|noarch|i686)$/', '', $package);
        }

        return self::SOURCES[$package] ?? match (true) {
            (bool) preg_match('/^(?:lib)?python(3\.\d+)(?:-.*)?$/', $package, $m) => 'python'.$m[1],
            (bool) preg_match('/^(?:libapache2-mod-)?php(\d\.\d)(?:-.*)?$/', $package, $m) => 'php'.$m[1],
            (bool) preg_match('/^postgresql-(?:client-|plpython3-|contrib-)?(\d+)$/', $package, $m) => 'postgresql-'.$m[1],
            (bool) preg_match('/^openjdk-(\d+)-/', $package, $m) => 'openjdk-'.$m[1],
            (bool) preg_match('/^(?:libmysqlclient\d+|mysql-(?:server|client)(?:-core)?-(8\.\d))$/', $package, $m) => 'mysql-'.($m[1] ?? '8.0'),
            str_starts_with($package, 'libnginx-mod-') => 'nginx',
            default => $package,
        };
    }
}
