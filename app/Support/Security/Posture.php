<?php

namespace App\Support\Security;

use App\Models\Machine;
use App\Models\MachinePolicy;
use App\Support\Firewall;
use App\Support\Machines\MachinePolicies;

/**
 * @phpstan-type Check array{key: string, severity: string, status: string, params: array<string, string|int>}
 * @phpstan-type Report array{state: string, score: int|null, grade: string|null, seenAt: string|null, checks: list<Check>}
 */
class Posture
{
    public const WEIGHTS = ['critical' => 10, 'high' => 5, 'medium' => 2, 'low' => 1];

    public const CLEARTEXT_PORTS = [21 => 'FTP', 23 => 'Telnet', 512 => 'rexec', 513 => 'rlogin', 514 => 'rsh'];

    public const FAILED_SIGN_INS = 20;

    public const STALE_AFTER_HOURS = 24;

    /** @return Report */
    public static function report(Machine $machine, ?MachinePolicy $policy = null): array
    {
        $watching = ($policy ?? MachinePolicies::for($machine))->security_watch;
        $facts = $watching ? SecurityFacts::of($machine) : new SecurityFacts;
        $view = Firewall::view($machine);

        $checks = [
            self::sshPassword($facts),
            self::sshRoot($facts),
            self::signIns($facts),
            self::exposed($machine, $view),
            self::cleartext($view),
            self::firewall($machine),
            self::securityUpdates($machine),
            self::autoUpdates($facts),
            self::reboot($machine),
        ];
        usort($checks, fn (array $a, array $b) => [self::rank($a['status']), -self::WEIGHTS[$a['severity']]]
            <=> [self::rank($b['status']), -self::WEIGHTS[$b['severity']]]);

        $state = self::state($machine, $checks, $watching);
        $score = $state === 'scored' ? self::score($checks) : null;

        return [
            'state' => $state,
            'score' => $score,
            'grade' => $score === null ? null : self::grade($score),
            'seenAt' => $machine->last_seen_at?->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /** @param list<Check> $checks */
    public static function score(array $checks): int
    {
        $possible = $lost = 0;
        foreach ($checks as $check) {
            if ($check['status'] === 'unknown') {
                continue;
            }
            $possible += self::WEIGHTS[$check['severity']];
            $lost += $check['status'] === 'fail' ? self::WEIGHTS[$check['severity']] : 0;
        }

        return $possible === 0 ? 0 : (int) round(100 * ($possible - $lost) / $possible);
    }

    public static function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }

    /** @param Check $check */
    public static function serious(array $check): bool
    {
        return $check['status'] === 'fail' && self::WEIGHTS[$check['severity']] >= self::WEIGHTS['high'];
    }

    /** @param list<Check> $checks */
    private static function state(Machine $machine, array $checks, bool $watching): string
    {
        $known = array_filter($checks, fn (array $c) => $c['status'] !== 'unknown');

        return match (true) {
            $known === [] => 'waiting',
            $machine->last_seen_at === null || $machine->last_seen_at->lt(now()->subHours(self::STALE_AFTER_HOURS)) => 'stale',
            ! $watching => 'watch_off',
            collect($checks)->contains(fn (array $c) => $c['status'] === 'unknown' && self::WEIGHTS[$c['severity']] >= self::WEIGHTS['high']) => 'incomplete',
            default => 'scored',
        };
    }

    /** @return Check */
    private static function sshPassword(SecurityFacts $facts): array
    {
        $password = $facts->passwordSignIn();

        return self::check('ssh_password', 'medium', $password === null ? null : ! $password);
    }

    /** @return Check */
    private static function sshRoot(SecurityFacts $facts): array
    {
        if ($facts->permitRootLogin === null) {
            return self::check('ssh_root', 'high', null);
        }

        return self::check('ssh_root', 'high', $facts->permitRootLogin !== 'yes' || $facts->passwordSignIn() === false);
    }

    /** @return Check */
    private static function signIns(SecurityFacts $facts): array
    {
        if ($facts->failedSignIns === null) {
            return self::check('sign_ins', 'medium', null);
        }

        return self::check('sign_ins', 'medium', $facts->failedSignIns < self::FAILED_SIGN_INS || $facts->passwordSignIn() === false, [
            'failed' => $facts->failedSignIns,
            'sources' => $facts->failedSources,
            'root' => $facts->failedAsRoot,
            'minutes' => $facts->windowMinutes,
        ]);
    }

    /**
     * @param  array{ports: list<array{port: int, proto: string, process: string|null, exposure: string|null, because: string|null, what: string|null, ssh: bool}>}|null  $view
     * @return Check
     */
    private static function exposed(Machine $machine, ?array $view): array
    {
        if ($view === null) {
            return self::check('exposed', 'high', null);
        }
        $exposed = array_map(fn (array $p) => "{$p['what']} ({$p['port']}/{$p['proto']})", Firewall::exposedDatabases($machine, $view));

        return self::check('exposed', 'high', $exposed === [], ['services' => implode(', ', $exposed)]);
    }

    /**
     * @param  array{ports: list<array{port: int, proto: string, process: string|null, exposure: string|null, because: string|null, what: string|null, ssh: bool}>}|null  $view
     * @return Check
     */
    private static function cleartext(?array $view): array
    {
        if ($view === null) {
            return self::check('cleartext', 'high', null);
        }

        $found = [];
        foreach ($view['ports'] as $port) {
            if ($port['proto'] === 'tcp' && isset(self::CLEARTEXT_PORTS[$port['port']]) && in_array($port['exposure'], Firewall::REACHABLE, true)) {
                $found[] = self::CLEARTEXT_PORTS[$port['port']]." ({$port['port']}/tcp)";
            }
        }

        return self::check('cleartext', 'high', $found === [], ['services' => implode(', ', $found)]);
    }

    /** @return Check */
    private static function firewall(Machine $machine): array
    {
        $active = Firewall::report($machine)['active'] ?? null;

        return self::check('firewall', 'low', is_bool($active) ? $active : null);
    }

    /** @return Check */
    private static function securityUpdates(Machine $machine): array
    {
        if ($machine->updates_checked_at === null) {
            return self::check('security_updates', 'medium', null);
        }
        $count = (int) $machine->security_updates_count;

        return self::check('security_updates', 'medium', $count === 0, ['count' => $count]);
    }

    /** @return Check */
    private static function autoUpdates(SecurityFacts $facts): array
    {
        return self::check('auto_updates', 'low', $facts->autoUpdates);
    }

    /** @return Check */
    private static function reboot(Machine $machine): array
    {
        if ($machine->updates_checked_at === null) {
            return self::check('reboot', 'low', null);
        }

        return self::check('reboot', 'low', ! $machine->reboot_required);
    }

    /**
     * @param  array<string, string|int>  $params
     * @return Check
     */
    private static function check(string $key, string $severity, ?bool $passed, array $params = []): array
    {
        return [
            'key' => $key,
            'severity' => $severity,
            'status' => match ($passed) {
                true => 'pass',
                false => 'fail',
                null => 'unknown',
            },
            'params' => $params,
        ];
    }

    private static function rank(string $status): int
    {
        return ['fail' => 0, 'unknown' => 1, 'pass' => 2][$status];
    }
}
