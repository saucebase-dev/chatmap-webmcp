<?php

namespace Modules\Chat\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Tools\Request as ToolRequest;
use Modules\Chat\Ai\ChatAgent;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Modules\Chat\Jobs\GenerateConversationTitle;
use Modules\Chat\Models\OnboardingState;
use Modules\Chat\Testing\CannedReplies;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController
{
    /**
     * The tools whose results move the map.
     *
     * Kept here rather than checked one name at a time: a second map-moving
     * tool that is not listed reopens a conversation on the wrong place, and
     * the failure is silent.
     */
    public const array MAP_TOOLS = [
        ShowOnMap::NAME,
        FindPlaces::NAME,
    ];

    /**
     * Show a blank chat. No row exists until the first message is sent.
     */
    public function index(): Response
    {
        return Inertia::render('Chat::Index', [
            'conversationId' => null,
            'initialMessages' => [],
            'initialMapView' => null,
            'onboarding' => null,
        ]);
    }

    /**
     * Show an existing conversation belonging to the authenticated user.
     */
    public function show(Request $request, string $conversation): Response
    {
        $owned = $this->ownedConversation($request, $conversation);

        $messages = (new ChatAgent)
            ->continue($owned->id, $request->user())
            ->messages();

        $onboarding = OnboardingState::find($owned->id);

        return Inertia::render('Chat::Index', [
            'conversationId' => $owned->id,
            'initialMessages' => collect($messages)
                // Tool rows carry no prose, and an assistant turn that only
                // asked a question would otherwise reopen as an empty bubble.
                ->reject(fn (Message $message): bool => $message instanceof ToolResultMessage || trim((string) $message->content) === '')
                ->values()
                ->map(fn (Message $message, int $index): array => [
                    'id' => 'history-'.$index,
                    'role' => $message->role->value,
                    'parts' => [
                        ['type' => 'text', 'text' => $message->content ?? ''],
                    ],
                ])
                ->all(),
            'initialMapView' => $this->lastMapView($messages),
            'onboarding' => $onboarding?->only(['phase', 'question_count', 'current_question', 'answers', 'plan']),
        ]);
    }

    /**
     * Move the onboarding phase forward without going through the model.
     *
     * "Skip questions", "Show my map" and "Back to planning" must be certain,
     * so they are recorded here rather than sent as chat messages the
     * assistant may misread. A skip before any plan exists saves the opening
     * message as a minimal plan.
     */
    public function onboarding(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->ownedConversation($request, $conversation);

        $phase = $request->validate(['phase' => ['required', 'in:mapping,interviewing']])['phase'];

        $state = OnboardingState::firstOrCreate(['conversation_id' => $owned->id]);

        // Going back to the interview keeps the plan and the answers: the
        // visitor wants more questions, not a fresh start.
        $state->update([
            'phase' => $phase,
            'current_question' => null,
            'plan' => $phase === 'mapping'
                ? ($state->plan ?? ['goal' => (string) $owned->getAttribute('title'), 'location' => '', 'details' => []])
                : $state->plan,
        ]);

        return response()->json($state->only(['phase', 'question_count', 'current_question', 'answers', 'plan']));
    }

    /**
     * Find what the map was last showing in this conversation.
     *
     * The transcript is rebuilt as plain text, so the tool calls that moved the
     * map are dropped on the way to the browser. Without this, reopening a
     * conversation snaps the map back to its default while the messages beside
     * it still discuss somewhere else.
     *
     * Views are grouped per reply, and searches in the last reply are pooled
     * the same way the browser pools them live, so a refresh shows the same
     * pins the visitor was just looking at.
     *
     * @param  iterable<Message>  $messages
     * @return array<string, mixed>|null
     */
    protected function lastMapView(iterable $messages): ?array
    {
        $replies = [];
        $current = [];

        foreach ($messages as $message) {
            // Matched on the role: the store rehydrates history as generic
            // messages, so an instanceof check on UserMessage never fires.
            if ($message->role === MessageRole::User) {
                if ($current !== []) {
                    $replies[] = $current;
                }
                $current = [];

                continue;
            }

            if (! $message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->toolResults->all() as $result) {
                if (! in_array($result->name, self::MAP_TOOLS, true)) {
                    continue;
                }

                $view = json_decode((string) $result->result, true);

                if (is_array($view) && isset($view['bbox'])) {
                    $current[] = $view;
                }
            }
        }

        if ($current !== []) {
            $replies[] = $current;
        }

        return $replies === [] ? null : $this->mergeViews(end($replies));
    }

    /**
     * Pool the searches of one reply into a single view. Mirrors `mergeViews()`
     * in `map.ts`: searches win over a bare placement, and their pins are combined.
     *
     * @param  list<array<string, mixed>>  $views
     * @return array<string, mixed>
     */
    protected function mergeViews(array $views): array
    {
        $searches = array_values(array_filter($views, fn (array $view): bool => ! empty($view['markers'])));

        if (count($searches) <= 1) {
            return $searches[0] ?? end($views);
        }

        $markers = [];
        $lons = [];
        $lats = [];

        foreach ($searches as $view) {
            foreach ($view['markers'] as $marker) {
                $markers[] = $marker + ['categoryKey' => $view['categoryKey'] ?? null];
            }

            array_push($lons, (float) $view['bbox'][0], (float) $view['bbox'][2]);
            array_push($lats, (float) $view['bbox'][1], (float) $view['bbox'][3]);
        }

        $area = Str::after($searches[0]['label'], ' in ');
        $categories = implode(' and ', array_map(fn (array $view): string => $view['category'] ?? $view['label'], $searches));

        return [
            'label' => $area === $searches[0]['label'] ? $categories : "{$categories} in {$area}",
            'category' => $categories,
            'bbox' => [(string) min($lons), (string) min($lats), (string) max($lons), (string) max($lats)],
            'markers' => $markers,
        ];
    }

    /**
     * Resolve a place name to a map view.
     *
     * Lets a visitor's own agent move the map without going through the
     * assistant, reusing the same geocoder and cache the ShowOnMap tool uses so
     * there is one place where a place name becomes coordinates.
     */
    public function place(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'place' => ['required', 'string', 'max:200'],
        ]);

        $result = (string) (new ShowOnMap)->handle(
            new ToolRequest(['place' => $validated['place']])
        );

        $view = json_decode($result, true);

        // The tool answers in prose when it cannot place somewhere, which is
        // the same signal the assistant gets.
        return is_array($view)
            ? response()->json($view)
            : response()->json(['message' => $result], 404);
    }

    /**
     * Return one conversation's transcript as JSON.
     *
     * Lets an agent read a saved conversation without navigating the visitor
     * away from the one they are looking at.
     */
    public function messages(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->ownedConversation($request, $conversation);

        $messages = (new ChatAgent)
            ->continue($owned->id, $request->user())
            ->messages();

        return response()->json([
            'id' => $owned->id,
            'title' => $owned->getAttribute('title'),
            'messages' => collect($messages)
                ->values()
                ->map(fn (Message $message): array => [
                    'role' => $message->role->value,
                    'text' => $message->content ?? '',
                ])
                ->all(),
        ]);
    }

    /**
     * Stream an assistant reply, creating the conversation on first use.
     */
    public function stream(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            // Where the visitor's map is pointing. It reaches the model, so it
            // is bounded here rather than trusted as the browser sent it.
            'map' => ['nullable', 'array'],
            'map.label' => ['required_with:map', 'string', 'max:200'],
            'map.center' => ['required_with:map', 'array', 'size:2'],
            'map.center.0' => ['required_with:map', 'numeric', 'between:-90,90'],
            'map.center.1' => ['required_with:map', 'numeric', 'between:-180,180'],
            'map.zoom' => ['required_with:map', 'numeric', 'between:0,24'],
            'map.moved' => ['required_with:map', 'boolean'],
        ]);

        $conversation = isset($validated['conversation_id'])
            ? $this->ownedConversation($request, $validated['conversation_id'])
            : $this->startConversation($request, $validated['message']);

        $onboarding = OnboardingState::firstOrCreate(['conversation_id' => $conversation->id]);

        // Whatever is typed while a question is open answers that question, so
        // the row stops advertising it and the model can see it was answered.
        if ($onboarding->current_question !== null) {
            $onboarding->update([
                'answers' => [...($onboarding->answers ?? []), ['question' => $onboarding->current_question['question'], 'answer' => $validated['message']]],
                'current_question' => null,
            ]);
        }

        if ($this->inTestMode()) {
            return $this->cannedStream($request, $conversation->id);
        }

        $stream = (new ChatAgent($validated['map'] ?? null, $onboarding))
            ->continue($conversation->id, $request->user())
            ->stream($validated['message'])
            ->then(function () use ($conversation): void {
                $userMessageCount = $conversation->messages()
                    ->where('role', 'user')
                    ->count();

                if (in_array($userMessageCount, GenerateConversationTitle::RETITLE_AT, true)) {
                    GenerateConversationTitle::dispatch(
                        $conversation->id,
                        $userMessageCount,
                    );
                }
            });

        $response = $stream
            ->usingVercelDataProtocol()
            ->toResponse($request);

        // A brand new chat has to move onto its own URL, so the browser needs
        // the id. It cannot ride in the stream body: the id must be known
        // before the first byte, and the protocol encoder exposes no hook for
        // extra frames.
        $response->headers->set('X-Conversation-Id', $conversation->id);

        return $this->keepFailuresInTheStream($response);
    }

    /**
     * Should replies be invented rather than generated?
     *
     * Two conditions, not one. The flag is what a developer turns on, and the
     * environment check is what stops it ever mattering if that flag reaches
     * production in a `.env` -- serving made-up answers to real visitors would
     * be a worse failure than the outage it looks like.
     */
    protected function inTestMode(): bool
    {
        return config('chat.test_mode') === true && ! app()->isProduction();
    }

    /**
     * Stream a canned reply, picked at random unless one was named.
     *
     * `?scenario=` is honoured so a particular state can be returned to while
     * it is being worked on, rather than refreshing until it comes up. Nothing
     * is persisted: the conversation row exists, but reopening it shows an
     * empty transcript, because none of this came from the model and none of
     * it belongs in the history the model later reads back.
     */
    protected function cannedStream(Request $request, string $conversationId): SymfonyResponse
    {
        $scenario = CannedReplies::pick($request->query('scenario'));
        $replies = new CannedReplies($conversationId);

        $response = new StreamedResponse(function () use ($replies, $scenario): void {
            foreach ($replies->frames($scenario) as $frame) {
                $this->writeFrame($frame);

                // Slow enough to watch the reply build, which is the point of
                // looking at it at all.
                usleep(40_000);
            }

            $this->writeFrame('[DONE]');
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'x-vercel-ai-ui-message-stream' => 'v1',
            'X-Conversation-Id' => $conversationId,
            // So it is obvious in the network tab that none of this is real.
            'X-Chat-Test-Scenario' => $scenario,
        ]);

        return $this->keepFailuresInTheStream($response);
    }

    /**
     * Report a mid-stream failure as a protocol frame rather than an exception.
     *
     * The Vercel encoder iterates the provider with no try/catch, so anything
     * the provider raises -- an expired key, a rate limit -- escapes after the
     * response headers have gone out. Laravel then renders an entire HTML error
     * page and sends *its* headers on top of the ones already written, which is
     * what produced nginx's "upstream sent duplicate header line: Date" warning,
     * a merged Cache-Control, and a 37KB error document delivered as
     * text/event-stream.
     *
     * Caught here it stays one well-formed stream: an error part the browser can
     * show, then the terminator the protocol requires. The status is already 200
     * by this point -- headers are sent before the callback runs -- which is why
     * the protocol carries errors in band rather than in the status line.
     */
    protected function keepFailuresInTheStream(SymfonyResponse $response): SymfonyResponse
    {
        if (! $response instanceof StreamedResponse || ($stream = $response->getCallback()) === null) {
            return $response;
        }

        return $response->setCallback(function () use ($stream): void {
            $stopHiding = $this->hideProviderErrors();

            try {
                $stream();
            } catch (Throwable $e) {
                // Still an error worth paging over; it just must not escape.
                report($e);

                $this->writeFrame(['type' => 'error', 'errorText' => $this->failureMessage()]);
                $this->writeFrame('[DONE]');
            } finally {
                $stopHiding();
            }
        });
    }

    /**
     * Replace the text of any error frame on its way to the browser.
     *
     * A provider that fails mid-stream reports it *as an event* before the
     * exception is raised, and the encoder writes that event's message out
     * verbatim -- which for OpenAI means the organisation id, the account's
     * limits and how long until they reset. Catching the exception above is
     * therefore too late: the raw text has already gone out ahead of it.
     *
     * The frame itself is kept rather than dropped, because the browser needs
     * it to know the reply failed at all; only the wording is ours. Errors
     * still reach the log intact through `report()`.
     *
     * @return Closure(): void Stops the rewriting and releases anything held back.
     */
    protected function hideProviderErrors(): Closure
    {
        $partial = '';

        // Frames are written one at a time and separated by a blank line, so a
        // chunk size of 1 hands this whole frames. It carries the remainder
        // anyway: a frame split down the middle would otherwise slip through
        // unread, which is the one case that must not leak.
        ob_start(function (string $chunk) use (&$partial): string {
            $partial .= $chunk;
            $complete = '';

            while (($end = strpos($partial, "\n\n")) !== false) {
                $complete .= $this->withoutProviderDetail(substr($partial, 0, $end + 2));
                $partial = substr($partial, $end + 2);
            }

            return $complete;
        }, 1);

        return function () use (&$partial): void {
            ob_end_flush();

            // Anything still held back was never a whole frame. It goes out as
            // it is, now that nothing is buffering, rather than vanishing.
            if ($partial !== '') {
                echo $partial;
                flush();
            }
        };
    }

    /**
     * Swap the provider's wording out of one server-sent event.
     */
    protected function withoutProviderDetail(string $frame): string
    {
        if (! str_starts_with($frame, 'data: ')) {
            return $frame;
        }

        $payload = json_decode(substr($frame, 6, -2), true);

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'error') {
            return $frame;
        }

        $payload['errorText'] = $this->failureMessage();

        return 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
    }

    /**
     * What the visitor is told when a reply does not arrive.
     */
    protected function failureMessage(): string
    {
        return __('The assistant could not be reached. Please try again.');
    }

    /**
     * Write one server-sent event, flushing as the streamed response does.
     *
     * @param  array<string, mixed>|string  $frame
     */
    protected function writeFrame(array|string $frame): void
    {
        echo 'data: '.(is_string($frame) ? $frame : json_encode($frame, JSON_THROW_ON_ERROR))."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * Create the conversation up front so its id is known before streaming.
     *
     * Laravel\Ai would otherwise create it mid-stream, which is too late to
     * report back to the browser. Creating it here also means the package skips
     * its own title generation, so the title is the opening message.
     */
    protected function startConversation(Request $request, string $message): Conversation
    {
        return Conversation::create([
            'id' => (string) Str::uuid(),
            'participant_type' => Conversation::participantType($request->user()),
            'participant_id' => Conversation::participantKey($request->user()),
            'title' => Str::limit(trim($message), 50, preserveWords: true) ?: __('New chat'),
        ]);
    }

    /**
     * Resolve a conversation the authenticated user owns.
     *
     * Laravel\Ai's continue() performs no ownership check of its own, so every
     * path that accepts an id from the client must come through here first.
     */
    protected function ownedConversation(Request $request, string $id): Conversation
    {
        return Conversation::query()
            ->where('id', $id)
            ->where('participant_type', Conversation::participantType($request->user()))
            ->where('participant_id', Conversation::participantKey($request->user()))
            ->firstOrFail();
    }
}
