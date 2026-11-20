<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Ai\AiProviders;
use App\Support\Assistant\AssistantAgent;
use App\Support\Assistant\AssistantAllowance;
use App\Support\Audit\NotAudited;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantController extends Controller
{
    public const LOCK_SECONDS = 1200;

    #[NotAudited('A message to Ask AI; the tools it runs are audited themselves')]
    public function __invoke(Request $request, Workspace $workspace, AssistantAgent $agent): StreamedResponse|JsonResponse
    {
        $data = $request->validate([
            'chat' => ['nullable', 'string', 'uuid'],
            'message' => ['required_without:images', 'nullable', 'string', 'max:6000'],
            'images' => ['nullable', 'array', 'max:'.AssistantAgent::IMAGES_PER_MESSAGE],
            'images.*' => ['string', 'max:'.(int) ceil(AssistantAgent::IMAGE_BYTES * 4 / 3) + 40, 'regex:/^data:image\/(png|jpeg|webp|gif);base64,[A-Za-z0-9+\/=]+$/'],
            'path' => ['nullable', 'string', 'max:300'],
            'model' => ['nullable', 'string', 'max:100', 'regex:/^(anthropic|openai|custom):[A-Za-z0-9._:\/\[\]-]{1,80}$/'],
        ]);

        $user = $request->user();
        $chat = AssistantAgent::load($data['chat'] ?? null, $workspace, $user);
        $lock = Cache::lock("assistant:chat:{$chat['id']}:busy", self::LOCK_SECONDS);
        if (! $lock->get()) {
            return response()->json(['error' => 'Ask AI is still working on your last message.'], 409);
        }
        if (($why = AssistantAllowance::take($workspace)) !== null) {
            $lock->release();

            return response()->json(['error' => $why, 'allowance' => AssistantAllowance::for($workspace)], 429);
        }
        $chat = AssistantAgent::reload($chat);
        $images = [];
        foreach ($data['images'] ?? [] as $url) {
            [$head, $body] = explode(',', $url, 2);
            $binary = base64_decode($body, true);
            if ($binary === false || strlen($binary) > AssistantAgent::IMAGE_BYTES || @getimagesizefromstring($binary) === false) {
                $lock->release();

                return response()->json(['error' => 'One of those images couldn’t be read. Try a PNG, JPEG or WebP under 4 MB.'], 422);
            }
            $images[] = ['type' => substr($head, 5, -7), 'data' => $body];
        }
        if (count($chat['images'] ?? []) + count($images) > AssistantAgent::IMAGES_PER_CHAT) {
            $lock->release();

            return response()->json(['error' => 'This chat has all the images it can take. Start a new chat to send more.'], 422);
        }

        return $this->stream($lock, $workspace, fn (callable $emit) => $agent->ask($chat, $workspace, $user, trim((string) ($data['message'] ?? '')), $data['path'] ?? null, $emit, $data['model'] ?? null, $images));
    }

    public function models(Workspace $workspace, AiProviders $providers): JsonResponse
    {
        return response()->json([
            'options' => $providers->modelOptions($workspace),
            'settingsUrl' => '/settings/mcp',
        ]);
    }

    #[NotAudited('Approving a change in Ask AI; the tool it runs is audited itself')]
    public function decide(Request $request, Workspace $workspace, AssistantAgent $agent): StreamedResponse|JsonResponse
    {
        $data = $request->validate([
            'chat' => ['required', 'string', 'uuid'],
            'call' => ['required', 'string', 'max:128'],
            'approve' => ['required', 'boolean'],
            'always' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $chat = AssistantAgent::load($data['chat'], $workspace, $user);
        if ($chat['id'] !== $data['chat']) {
            return response()->json(['error' => 'That chat has expired. Start a new one and ask again.'], 410);
        }
        $lock = Cache::lock("assistant:chat:{$chat['id']}:busy", self::LOCK_SECONDS);
        if (! $lock->get()) {
            return response()->json(['error' => 'Ask AI is still working on your last message.'], 409);
        }
        $chat = AssistantAgent::reload($chat);

        return $this->stream($lock, $workspace, fn (callable $emit) => $agent->decide($chat, $workspace, $user, $data['call'], (bool) $data['approve'], (bool) ($data['always'] ?? false), $emit));
    }

    /** @param  callable(callable(array<string, mixed>): void): void  $work */
    private function stream(Lock $lock, Workspace $workspace, callable $work): StreamedResponse
    {
        return response()->stream(function () use ($work, $lock, $workspace) {
            set_time_limit(AssistantAgent::BUDGET_SECONDS + 300);
            ignore_user_abort(true);
            $emit = function (array $event): void {
                echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $work($emit);
                $emit(['type' => 'done', 'allowance' => AssistantAllowance::for($workspace)]);
            } finally {
                $lock->release();
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
