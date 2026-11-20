<?php

namespace App\Support\Workflows;

use App\Models\DaemonJob;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Screenshots an agent took of what it changed, sent back in its log. Kept
 * on the public disk under names nobody can guess, so they show in the pull
 * request on GitHub and on the agent's page.
 */
final class AgentShots
{
    public const MAX_BYTES = 400_000;

    /**
     * The screenshots in the job's log that came back whole and are images.
     *
     * @return list<array{name: string, bytes: string, ext: string}>
     */
    public static function read(DaemonJob $job): array
    {
        $shots = [];
        $current = null;
        foreach (array_filter($job->log ?? [], 'is_string') as $line) {
            $line = rtrim($line);
            if (str_starts_with($line, AgentSpec::SHOT)) {
                if ($current !== null) {
                    $shots[] = $current;
                }
                $head = preg_split('/\s+/', trim(substr($line, strlen(AgentSpec::SHOT)))) ?: [];
                $current = count($head) === 3 ? ['name' => $head[0], 'sha' => $head[1], 'count' => (int) $head[2], 'parts' => []] : null;
            } elseif ($current !== null && str_starts_with($line, AgentSpec::SHOT_PART)) {
                $current['parts'][] = trim(substr($line, strlen(AgentSpec::SHOT_PART)));
            }
        }
        if ($current !== null) {
            $shots[] = $current;
        }

        $read = [];
        foreach (array_slice($shots, 0, AgentSpec::MAX_SHOTS) as $shot) {
            $payload = implode('', $shot['parts']);
            if (count($shot['parts']) !== $shot['count'] || ! hash_equals($shot['sha'], hash('sha256', $payload))) {
                continue;
            }
            $bytes = base64_decode($payload, true);
            if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
                continue;
            }
            $info = @getimagesizefromstring($bytes);
            $ext = match ($info[2] ?? null) {
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG => 'png',
                default => null,
            };
            if ($ext === null || $info[0] < 1 || $info[1] < 1 || $info[0] > 4000 || $info[1] > 16000) {
                continue;
            }
            $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', pathinfo($shot['name'], PATHINFO_FILENAME))) ?: 'Screenshot';
            $read[] = ['name' => mb_substr($name, 0, 60), 'bytes' => $bytes, 'ext' => $ext];
        }

        return $read;
    }

    /**
     * Keeps the job's screenshots for the run, and says where they are.
     *
     * @return list<array{name: string, url: string}>
     */
    public static function keep(WorkflowRun $run, DaemonJob $job): array
    {
        $kept = [];
        foreach (self::read($job) as $shot) {
            $path = self::folder($run).'/'.Str::random(40).'.'.$shot['ext'];
            Storage::disk('public')->put($path, $shot['bytes']);
            $kept[] = ['name' => $shot['name'], 'url' => Storage::disk('public')->url($path)];
        }

        return $kept;
    }

    public static function forget(WorkflowRun $run): void
    {
        Storage::disk('public')->deleteDirectory(self::folder($run));
    }

    private static function folder(WorkflowRun $run): string
    {
        return "agent-shots/{$run->id}";
    }
}
