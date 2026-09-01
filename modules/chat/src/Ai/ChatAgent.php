<?php

namespace Modules\Chat\Ai;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Modules\Chat\Ai\Tools\FindPlaces;
use Modules\Chat\Ai\Tools\ShowOnMap;
use Stringable;

/**
 * The model is pinned so provider SDK updates cannot silently change the
 * quality, latency, or cost of the application's central experience.
 */
#[Model(self::MODEL)]
#[MaxSteps(8)]
class ChatAgent implements Agent, HasProviderOptions, HasTools, RemembersConversationsContract
{
    use Promptable, RemembersConversations;

    /**
     * The model this assistant runs on.
     *
     * A constant rather than a literal in the attribute so there is one place
     * to change it, and so the admin pricing form can offer it as the rate
     * that actually matters.
     */
    public const string MODEL = 'gpt-5.4-mini';

    /**
     * @param  array{label: string, center: array{float, float}, zoom: float, moved: bool}|null  $mapViewport
     *                                                                                                         Where the visitor's map is pointing as this message is sent.
     */
    public function __construct(protected ?array $mapViewport = null) {}

    /**
     * Get the instructions that the agent should follow.
     *
     * The map position rides here rather than on the user's message, so the
     * transcript the visitor reads back stays exactly what they typed.
     */
    public function instructions(): Stringable|string
    {
        $instructions = <<<'INSTRUCTIONS'
        You are a helpful assistant who answers questions about places anywhere
        in the world. Focus on towns, streets, addresses, landmarks,
        neighbourhoods, and what is in or near them. If a request is not about a
        place, explain that you specialize in location-based questions and offer
        to help the visitor explore somewhere.

        A map sits beside the conversation. Whenever your answer is about a place
        the visitor could look at, call show_on_map so the map follows along, then
        answer normally. Do not mention the map or the tool in your reply, and do
        not read coordinates out loud: the visitor can already see it.

        When they ask what is in or around somewhere rather than where one place
        is, use the find_places tool so the map shows up to ten results at once.
        Treat them as a selection, not a complete inventory. The map already
        shows every returned pin, so summarize the selection and mention only
        the places worth singling out.
        INSTRUCTIONS;

        $viewport = $this->viewportContext();

        return $viewport === '' ? $instructions : $instructions."\n\n".$viewport;
    }

    /**
     * Describe where the map is pointing, if the browser told us.
     */
    protected function viewportContext(): string
    {
        if ($this->mapViewport === null) {
            return '';
        }

        [$latitude, $longitude] = $this->mapViewport['center'];
        $label = $this->placeLabel($latitude, $longitude);
        $point = round($latitude, 5).', '.round($longitude, 5);

        return "The map beside the conversation is showing {$label}, centred on {$point}. When the visitor says \"here\", \"there\" or \"this area\" without naming a place, they mean {$label}.";
    }

    /**
     * Name what the map is centred on.
     *
     * The browser's label is whatever the conversation last put on the map, so
     * once the visitor drags the camera elsewhere it describes the wrong place.
     * The centre is then named afresh, because coordinates on their own tell
     * the model nothing it can answer with.
     */
    protected function placeLabel(float $latitude, float $longitude): string
    {
        if (! $this->mapViewport['moved']) {
            return $this->mapViewport['label'];
        }

        return (new ShowOnMap)->placeAt($latitude, $longitude)
            ?? $this->mapViewport['label'];
    }

    /**
     * Get the tools available to the agent.
     *
     * Both map tools are local so their calls and results are visible in the
     * streamed route of thought. Web search remains provider-hosted.
     *
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        return [
            new ShowOnMap,
            new FindPlaces,
            new WebSearch,
        ];
    }

    /**
     * Get provider-specific generation options.
     *
     * OpenAI streams no reasoning summaries unless they are asked for, so
     * without this the route of thought beside the reply has nothing to show
     * but the tool calls.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            // Both halves are load-bearing. Without `summary` OpenAI reasons
            // silently and streams nothing to summarise; without `effort` the
            // model does not reason at all, so `summary` has nothing to say.
            // Keep the visible route useful without letting it compete with the reply.
            Lab::OpenAI => ['reasoning' => ['effort' => 'low', 'summary' => 'concise']],
            default => [],
        };
    }
}
