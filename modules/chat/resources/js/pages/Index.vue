<script setup lang="ts">
import {
    Conversation,
    ConversationContent,
    ConversationEmptyState,
    ConversationScrollButton,
} from '@/components/ai-elements/conversation';
import {
    Message,
    MessageContent,
    MessageResponse,
} from '@/components/ai-elements/message';
import {
    PromptInput,
    PromptInputBody,
    PromptInputFooter,
    PromptInputSpeechButton,
    PromptInputSubmit,
    PromptInputTextarea,
    PromptInputTools,
    type PromptInputMessage,
} from '@/components/ai-elements/prompt-input';
import {
    Plan,
    PlanContent,
    PlanDescription,
    PlanFooter,
    PlanHeader,
    PlanTitle,
} from '@/components/ai-elements/plan';
import {
    ChainOfThought,
    ChainOfThoughtContent,
    ChainOfThoughtHeader,
    ChainOfThoughtImage,
    ChainOfThoughtSearchResult,
    ChainOfThoughtSearchResults,
    ChainOfThoughtStep,
} from '@/components/ai-elements/chain-of-thought';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    ResizableHandle,
    ResizablePanel,
    ResizablePanelGroup,
} from '@/components/ui/resizable';
import { Button } from '@/components/ui/button';
import AppHeader from '@/components/AppHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { csrfToken } from '@/lib/utils';
import { useWebMcpTools } from '@/webmcp';
import ContextMap from '@modules/chat/resources/js/components/ContextMap.vue';
import PlanSummary from '@modules/chat/resources/js/components/PlanSummary.vue';
import ThinkingIndicator from '@modules/chat/resources/js/components/ThinkingIndicator.vue';
import {
    mergeViews,
    toMapView,
    viewKey,
    MAP_TOOLS,
    type MapMarker,
    type MapView,
    type MapViewport,
} from '@modules/chat/resources/js/map';
import { thoughtsFor } from '@modules/chat/resources/js/thoughts';
import {
    chatTools,
    type TripPhase,
} from '@modules/chat/resources/js/webmcp/chatTools';
import { Chat } from '@ai-sdk/vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    CheckIcon,
    CircleAlertIcon,
    ClipboardListIcon,
    LoaderCircleIcon,
} from '@lucide/vue';
import { DefaultChatTransport, type UIMessage } from 'ai';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

type Onboarding = {
    phase: string;
    question_count: number;
    answers?: Array<{ question: string; answer: string }>;
    current_question: {
        question: string;
        options: string[];
        multiple: boolean;
        count?: number;
    } | null;
    plan: {
        goal: string;
        location: string;
        details: Record<string, string>;
    } | null;
};

const props = defineProps<{
    conversationId: string | null;
    initialMessages: UIMessage[];
    initialMapView: MapView | null;
    onboarding: Onboarding | null;
}>();

const examplePrompts = [
    {
        emoji: '☕',
        text: 'Find quiet coffee shops in Shibuya for a morning of work.',
    },
    {
        emoji: '🍽️',
        text: 'Plan a relaxed afternoon in Lisbon with good food and a museum.',
    },
    {
        emoji: '♿',
        text: 'Show me accessible things to do near Edinburgh Castle.',
    },
    {
        emoji: '👨‍👩‍👧',
        text: 'Find a family-friendly day out in Melbourne on a low budget.',
    },
    { emoji: '✨', text: 'Help me plan a date night in Mexico City.' },
    { emoji: '🥾', text: 'Find scenic walks and viewpoints around Cape Town.' },
]
    .sort(() => Math.random() - 0.5)
    .slice(0, 3);

// Tracked separately from the prop: a brand new chat learns its id from the
// first stream response, without an Inertia round trip.
const conversationId = ref(props.conversationId);
const onboarding = ref<Onboarding | null>(props.onboarding);
const selectedAnswers = ref<string[]>([]);
const otherAnswer = ref('');

const title = 'Chat';

const sessionExpired = ref(false);

/**
 * fetch follows redirects transparently, so an expired session arrives here as
 * a 200 containing the login page rather than an error -- the SDK would parse
 * it as an empty stream and show nothing. A real stream is never redirected,
 * so that flag distinguishes the two. Covers both expiry routes: the auth
 * redirect to login, and a 419 CSRF failure, which the exception handler turns
 * into a redirect back to this page.
 */
async function guardedFetch(
    input: RequestInfo | URL,
    init?: RequestInit,
): Promise<Response> {
    const response = await fetch(input, init);

    if (
        response.redirected ||
        response.status === 401 ||
        response.status === 419
    ) {
        sessionExpired.value = true;

        throw new Error('Session expired.');
    }

    // A new chat is created server-side on its first message. Adopt the id and
    // move onto its own URL so a reload lands back on this conversation.
    const id = response.headers.get('X-Conversation-Id');

    if (id && id !== conversationId.value) {
        conversationId.value = id;
        onboarding.value ??= {
            phase: 'interviewing',
            question_count: 0,
            current_question: null,
            plan: null,
        };

        // One partial visit moves the URL and refreshes the shared sidebar
        // props together, keeping Inertia's page.url honest. `only` keeps
        // initialMessages out of the response, which is what stops the reset
        // watcher wiping the chat that is mid-stream.
        router.visit(route('chat.show', id), {
            replace: true,
            preserveState: true,
            preserveScroll: true,
            only: ['chat'],
        });
    }

    return response;
}

/**
 * Which canned reply to ask for, when the server is in test mode.
 *
 * `?scenario=places` on the page pins what comes back, so a state can be
 * returned to while it is being worked on rather than refreshed towards. It
 * does nothing at all unless the server is in test mode, which production
 * refuses outright.
 */
const scenario = new URLSearchParams(window.location.search).get('scenario');

const chat = new Chat({
    messages: props.initialMessages,
    transport: new DefaultChatTransport({
        api:
            route('chat.stream') +
            (scenario ? `?scenario=${encodeURIComponent(scenario)}` : ''),
        fetch: guardedFetch,
        // The id is echoed back, but the server never trusts it: it verifies the
        // conversation belongs to the authenticated user before continuing it.
        prepareSendMessagesRequest: ({ messages }) => ({
            body: {
                message: messages
                    .at(-1)
                    ?.parts.filter((part) => part.type === 'text')
                    .map((part) => part.text)
                    .join('\n'),
                conversation_id: conversationId.value,
                map: viewport.value,
            },
            headers: { 'X-XSRF-TOKEN': csrfToken() },
        }),
    }),
});

const messages = computed(() => chat.messages);
const status = computed(() => chat.status);

type Question = NonNullable<Onboarding['current_question']>;
type MapPlan = NonNullable<Onboarding['plan']>;

function toolOutput<T>(part: {
    type: string;
    state?: string;
    output?: unknown;
}): T | null {
    if (part.state !== 'output-available') {
        return null;
    }

    if (typeof part.output === 'object' && part.output !== null) {
        return part.output as T;
    }

    if (typeof part.output === 'string') {
        try {
            return JSON.parse(part.output) as T;
        } catch {
            return null;
        }
    }

    return null;
}

/**
 * The onboarding state as the transcript tells it, in order.
 *
 * One walk rather than one lookup per tool: a question is only open until the
 * visitor answers it or a plan is saved, which a "latest question anywhere"
 * search cannot tell. When the transcript carries no tool output at all (a
 * reopened conversation is plain text) the server's row stands in.
 */
const transcriptState = computed(() => {
    let question: Question | null = null;
    let plan: MapPlan | null = null;
    let sawTools = false;

    for (const message of messages.value) {
        if (message.role === 'user') {
            question = null;
            continue;
        }

        for (const part of message.parts) {
            if (part.type === 'tool-interview_visitor') {
                sawTools = true;
                question = toolOutput<Question>(part) ?? question;
            } else if (part.type === 'tool-save_map_ready_plan') {
                sawTools = true;
                const saved = toolOutput<MapPlan>(part);

                if (saved) {
                    plan = saved;
                    question = null;
                }
            }
        }
    }

    return { question, plan, sawTools };
});

const activeQuestion = computed(
    () =>
        transcriptState.value.question ??
        (transcriptState.value.sawTools
            ? null
            : (onboarding.value?.current_question ?? null)),
);

const activePlan = computed(
    () => transcriptState.value.plan ?? onboarding.value?.plan ?? null,
);

const onboardingPhase = computed(() => {
    if (onboarding.value?.phase === 'mapping') {
        return 'mapping';
    }

    return activePlan.value && !activeQuestion.value
        ? 'reviewing'
        : 'interviewing';
});

const isMapStaging = computed(
    () => onboarding.value !== null && onboardingPhase.value !== 'mapping',
);

const isPreparingOnboarding = computed(
    () => isMapStaging.value && !activeQuestion.value && !activePlan.value,
);

watch(
    () => props.onboarding,
    (value) => {
        onboarding.value = value;
    },
);

watch(activeQuestion, () => {
    selectedAnswers.value = [];
    otherAnswer.value = '';
});

/** The chat pane's floor, and the width it opens at. */
const CHAT_MIN_SIZE = 25;

/**
 * Where the map sits until a conversation gives it somewhere better.
 *
 * The whole world, because that is the whole subject: opening on one country
 * or city would imply a scope the assistant no longer has.
 */
const defaultView: MapView = {
    label: 'World',
    bbox: ['-180', '-85', '180', '85'],
};

/**
 * The newest successful call to a map tool wins, so the map holds its last
 * known place while the visitor asks follow-ups that are not about anywhere.
 */
const conversationView = computed<MapView>(() => {
    for (const message of [...messages.value].reverse()) {
        const views = (
            message.parts as Array<{
                type: string;
                state?: string;
                output?: unknown;
            }>
        )
            .filter(
                (part) =>
                    MAP_TOOLS.some((tool) => part.type === `tool-${tool}`) &&
                    part.state === 'output-available',
            )
            .map((part) => toMapView(part.output))
            .filter((view): view is MapView => view !== null);

        const merged = mergeViews(views);

        if (merged) {
            return merged;
        }
    }

    // Nothing streamed this visit, so fall back to where the transcript left
    // the map -- which is what reopening a saved conversation hits.
    return props.initialMapView ?? defaultView;
});

/**
 * A place set straight from a WebMCP tool, bypassing the assistant.
 *
 * It outranks the conversation until the assistant moves the map itself, at
 * which point the newer instruction wins and this is dropped.
 */
const overrideView = ref<MapView | null>(null);

// Keyed, not by reference: the view is rebuilt from the transcript on every
// token, so watching the object would clear the override immediately.
watch(
    () => viewKey(conversationView.value),
    () => {
        overrideView.value = null;
    },
);

const mapView = computed<MapView>(
    () => overrideView.value ?? conversationView.value,
);

/**
 * Where the map is pointing, sent with every message.
 *
 * Null only until the map has settled once. It is sent even when nothing in the
 * conversation put it there: the visitor can see the map, so "where am I?" on a
 * fresh chat is a question the assistant should be able to answer.
 */
const viewport = ref<MapViewport | null>(null);

type ContextMapHandle = {
    focusMarker: (marker: MapMarker) => void;
};

const contextMap = ref<ContextMapHandle | null>(null);

function focusMapMarker(marker: MapMarker): void {
    contextMap.value?.focusMarker(marker);
}

const lastMessageId = computed(() => messages.value.at(-1)?.id);

/**
 * The status the composer should show, which is not always the chat's.
 *
 * A failed reply leaves the chat in 'error' until the next send, and the
 * submit button renders that as a cross -- so the composer sits there looking
 * broken while the visitor is perfectly able to ask something else. The
 * failure is already reported on the message it belongs to, with the retry
 * beside it, so the composer goes back to accepting the next question.
 */
const composerStatus = computed(() =>
    status.value === 'error' ? 'ready' : status.value,
);

/**
 * The newest question, which is not always the newest message.
 *
 * The stream announces the reply with a `start` part before the model has
 * produced anything, so a failure leaves an empty assistant message sitting
 * after the question. Anchoring "not delivered" to the last message would then
 * look for it on that stub and never find it.
 */
const lastUserMessageId = computed(
    () => messages.value.findLast((message) => message.role === 'user')?.id,
);

/**
 * In flight: the message left the browser but nothing has come back yet. The
 * status flips to 'streaming' as soon as the first token lands, so this only
 * covers the wait before any reply exists.
 */
function isPending(message: UIMessage): boolean {
    return (
        status.value === 'submitted' &&
        message.role === 'user' &&
        message.id === lastMessageId.value
    );
}

/**
 * The caret and the per-character reveal belong only on the reply being written
 * right now. Every other message is settled text and renders in one go.
 */
function isWriting(message: UIMessage): boolean {
    return (
        status.value === 'streaming' &&
        message.role === 'assistant' &&
        message.id === lastMessageId.value
    );
}

/**
 * The send failed outright. Session expiry raises its own dialog, so it is
 * excluded here rather than reported in two places at once.
 */
function isUndelivered(message: UIMessage): boolean {
    return (
        status.value === 'error' &&
        !sessionExpired.value &&
        message.role === 'user' &&
        message.id === lastUserMessageId.value
    );
}

/**
 * An assistant message the model never wrote anything into.
 *
 * The `start` part creates it before the first token, so a reply that fails
 * outright leaves this stub behind. Rendered, it is an empty bubble sitting
 * where the answer should be, which reads as the thoughts having vanished.
 */
function isEmptyReply(message: UIMessage): boolean {
    return message.role === 'assistant' && message.parts.length === 0;
}

/**
 * How many times one message may be resent by hand.
 *
 * A send that has failed three times is failing for a reason retrying will not
 * fix, and an offer that never stops being offered reads as a broken button.
 */
const RETRY_LIMIT = 3;

/** Attempts so far, per message id. */
const retries = ref<Record<string, number>>({});

function retriesLeft(message: UIMessage): number {
    return RETRY_LIMIT - (retries.value[message.id] ?? 0);
}

/**
 * Send a failed message again.
 *
 * regenerate() keeps a *user* message and re-requests from it, so the bubble
 * stays put rather than being appended a second time. The failure surfaces the
 * same way it did the first time -- through the error status -- so there is
 * nothing to catch here that is not already shown.
 */
function retry(message: UIMessage) {
    retries.value[message.id] = (retries.value[message.id] ?? 0) + 1;

    chat.clearError();
    chat.regenerate({ messageId: message.id });
}

/**
 * Scrolling is driven by sending, not by receiving. On send the new message is
 * pulled up to the top of the pane and the reply is left to grow underneath it;
 * the reply itself is never chased.
 *
 * The target is out of reach at first -- nothing sits below the new message yet
 * -- so it clamps to the true bottom and creeps up as tokens arrive, settling
 * the moment the message reaches the top. Any manual scroll releases the
 * anchor, and nothing re-arms it until the next send.
 */
const ANCHOR_OFFSET = 16;

const pane = ref<{ $el?: HTMLElement } | HTMLElement | null>(null);
const conversation = ref<{
    pinToEnd: () => void;
    releasePin: () => void;
} | null>(null);
let scroller: HTMLElement | null = null;
let anchorId: string | null = null;

function findScroller(): HTMLElement | null {
    const value = pane.value;
    const root = value instanceof HTMLElement ? value : (value?.$el ?? null);

    if (!(root instanceof HTMLElement)) {
        return null;
    }

    return (
        [...root.querySelectorAll<HTMLElement>('*')].find((element) =>
            /(auto|scroll)/.test(getComputedStyle(element).overflowY),
        ) ?? null
    );
}

function releaseAnchor() {
    anchorId = null;
}

let followFrame: number | null = null;

/**
 * Coalesced to one run per frame. Tokens arrive far faster than frames are
 * painted, and every run reads layout, so following on each token forced a
 * layout per token for nothing the visitor could see.
 */
function followAnchor() {
    if (anchorId === null || followFrame !== null) {
        return;
    }

    followFrame = requestAnimationFrame(() => {
        followFrame = null;
        scroller ??= findScroller();

        const message = scroller?.querySelector<HTMLElement>(
            `[data-testid="message-${anchorId}"]`,
        );

        if (!scroller || !message) {
            return;
        }

        // Measured from rects rather than offsetTop, which is relative to
        // whichever ancestor happens to be positioned.
        const top =
            scroller.scrollTop +
            message.getBoundingClientRect().top -
            scroller.getBoundingClientRect().top;

        scroller.scrollTop = Math.min(
            top - ANCHOR_OFFSET,
            scroller.scrollHeight - scroller.clientHeight,
        );
    });
}

onMounted(() => {
    scroller = findScroller();
    // Touching the scroll yourself ends the follow. Listening for the gesture
    // rather than for scroll events is what distinguishes a reader's scroll
    // from our own, which fires the same event.
    scroller?.addEventListener('wheel', releaseAnchor, { passive: true });
    scroller?.addEventListener('touchstart', releaseAnchor, { passive: true });
});

onBeforeUnmount(() => {
    scroller?.removeEventListener('wheel', releaseAnchor);
    scroller?.removeEventListener('touchstart', releaseAnchor);
});

// Fires on every streamed delta, not just on new messages.
watch(
    () =>
        messages.value
            .map((message) =>
                message.parts
                    .map((part) => ('text' in part ? part.text : ''))
                    .join(''),
            )
            .join(''),
    followAnchor,
);

/**
 * Inertia reuses this component when moving between sessions, so the Chat
 * instance has to be reset by hand. initialMessages is a fresh array on every
 * visit, which makes it the reliable signal -- conversationId is not, because
 * starting a new chat goes /chat -> /chat with the prop null both times.
 */
watch(
    () => props.initialMessages,
    (initial) => {
        conversationId.value = props.conversationId;
        chat.messages = initial;
        retries.value = {};
        releaseAnchor();
        nextTick(() => conversation.value?.pinToEnd());
    },
);

const page = usePage();

const sessions = computed(() => page.props.chat?.sessions ?? []);

const currentTitle = computed(
    () =>
        sessions.value.find((session) => session.id === conversationId.value)
            ?.title ?? null,
);

let retitleTimer: ReturnType<typeof setTimeout> | undefined;

function refreshSessions() {
    // The onboarding row is what the assistant just wrote to; picking it up
    // here is what keeps a later reload landing on the same screen.
    router.reload({ only: ['chat', 'onboarding'] });
}

/**
 * The title is rewritten by a queued job, so the browser is never told. Rather
 * than poll, refresh once a reply lands and again a few seconds after a
 * milestone, which is the only moment the title can have changed.
 */
function scheduleSessionRefresh() {
    refreshSessions();

    const userMessages = messages.value.filter(
        (message) => message.role === 'user',
    ).length;

    if (page.props.chat?.retitle_at?.includes(userMessages)) {
        clearTimeout(retitleTimer);
        retitleTimer = setTimeout(refreshSessions, 5000);
    }
}

watch(status, (next, previous) => {
    if (previous === 'streaming' && next === 'ready') {
        scheduleSessionRefresh();
    }
});

onBeforeUnmount(() => clearTimeout(retitleTimer));

/**
 * The phase an agent should see the page in. Old conversations predate the
 * onboarding row and open straight onto the map.
 */
const tripPhase = computed<TripPhase>(() => {
    if (!conversationId.value && !messages.value.length) {
        return 'landing';
    }

    return onboarding.value === null
        ? 'mapping'
        : (onboardingPhase.value as TripPhase);
});

// Every execute reads live state when called, so the only thing that rebuilds
// this list, and re-registers with the browser, is a change of phase.
useWebMcpTools(
    computed(() =>
        chatTools({
            chat,
            sessions: () => sessions.value,
            currentConversationId: () => conversationId.value,
            mapLocation: () => viewport.value,
            showOnMap: (view) => {
                overrideView.value = view;
            },
            trip: () => ({
                phase: tripPhase.value,
                question_count:
                    activeQuestion.value?.count ??
                    onboarding.value?.question_count ??
                    0,
                question: activeQuestion.value,
                answers: onboarding.value?.answers ?? [],
                plan: activePlan.value,
            }),
            send,
            openMap: showMap,
            startTrip,
            showPlan,
        }).filter(
            (tool) => !tool.phases || tool.phases.includes(tripPhase.value),
        ),
    ),
);

/**
 * The steps to show beside a reply.
 *
 * Which parts become steps, and how each one reads, lives in the thought
 * registry -- adding a kind is an entry there, not a branch in this template.
 */
function thoughts(message: UIMessage) {
    return thoughtsFor(message.parts);
}

function goToLogin() {
    window.location.href = route('login');
}

function handleSubmit(message: PromptInputMessage) {
    void send(message.text);
}

/**
 * Send as the visitor. Resolves once the assistant's reply has finished
 * streaming, which is what lets an agent chain answers through WebMCP.
 */
async function send(text: string): Promise<void> {
    if (!text.trim()) {
        return;
    }

    // The first stream cannot provide an interview question until the model has
    // started responding. Switch the map into its staged state before sending,
    // so the blank world map never flashes between the landing and the plan.
    if (!conversationId.value && onboarding.value === null) {
        onboarding.value = {
            phase: 'interviewing',
            question_count: 0,
            current_question: null,
            plan: null,
        };
    }

    const finished = chat.sendMessage({ text });

    // The conversation holds itself at the end until now; from here the anchor
    // owns the viewport, so the two must not both be driving it.
    conversation.value?.releasePin();

    // Anchor on the message just appended, once it has actually rendered.
    nextTick(() => {
        anchorId = messages.value.at(-1)?.id ?? null;
        followAnchor();
    });

    await finished;

    // The server recorded the answer and whatever the tools wrote before the
    // reply ended. Wait for that row so a caller reads the state, not a guess.
    await new Promise<void>((resolve) =>
        router.reload({ only: ['onboarding'], onFinish: () => resolve() }),
    );
}

/**
 * A new trip from wherever the page is.
 *
 * Reset in place rather than visiting /chat: an Inertia visit remounts this
 * component, and the WebMCP tool mid-call would keep sending through the old
 * instance into the conversation it was meant to leave. The first reply's
 * conversation id then moves the URL, as it does for a typed first message.
 */
async function startTrip(goal: string): Promise<void> {
    conversationId.value = null;
    onboarding.value = null;
    chat.messages = [];
    retries.value = {};
    overrideView.value = null;
    releaseAnchor();
    await nextTick();

    await send(goal);
}

function startExample(text: string): void {
    handleSubmit({ text, files: [] });
}

function toggleAnswer(answer: string): void {
    if (!activeQuestion.value?.multiple) {
        selectedAnswers.value = [answer];
        return;
    }

    selectedAnswers.value = selectedAnswers.value.includes(answer)
        ? selectedAnswers.value.filter((value) => value !== answer)
        : [...selectedAnswers.value, answer];
}

function submitAnswer(): void {
    const answer = [...selectedAnswers.value, otherAnswer.value.trim()]
        .filter(Boolean)
        .join(', ');

    if (!answer) {
        return;
    }

    handleSubmit({ text: answer, files: [] });
}

/**
 * Open the map for good.
 *
 * Recorded on the server rather than asked of the assistant: a skip that
 * depends on the model choosing to save a plan is a skip that sometimes does
 * not happen. Both buttons land here.
 */
/**
 * Record a phase change on the server and adopt the row it returns.
 *
 * Only the two visitor-driven moves go through here: opening the map and
 * returning to the interview. The rest of the phase changes are made by the
 * assistant's tools.
 */
async function setPhase(phase: 'mapping' | 'interviewing'): Promise<void> {
    if (!conversationId.value) {
        return;
    }

    onboarding.value = {
        ...(onboarding.value ?? {
            question_count: 0,
            current_question: null,
            plan: null,
        }),
        phase,
    };

    const response = await guardedFetch(
        route('chat.onboarding', conversationId.value),
        {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ phase }),
        },
    );

    if (response.ok) {
        onboarding.value = await response.json();
    }
}

async function showMap(): Promise<void> {
    if (!conversationId.value) {
        return;
    }

    await setPhase('mapping');

    // A map that opens on the blank world reads as a dead button. Fly to the
    // plan's location at once, then let the assistant fill it from the plan.
    const location = onboarding.value?.plan?.location;

    if (location) {
        void locate(location);
    }

    if (status.value === 'ready') {
        chat.sendMessage({
            text: location
                ? `Show me places for my plan in ${location}.`
                : 'Show me places for my plan.',
        });
    }
}

/** Move the map to a named place through the assistant's own geocoder. */
async function locate(place: string): Promise<void> {
    const response = await fetch(route('chat.place'), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ place }),
    });

    if (response.ok) {
        overrideView.value = (await response.json()) as MapView;
    }
}

const skipInterview = showMap;

/**
 * Reopen the interview from the map. The plan and answers stay; the assistant
 * is told the visitor wants more questions, and its tools force one.
 */
async function backToPlanning(): Promise<void> {
    if (!conversationId.value || status.value !== 'ready') {
        return;
    }

    planOpen.value = false;
    await setPhase('interviewing');
    await send('I want to refine my plan. Ask me a few more questions.');
}

/** The plan card can be brought back over the open map. */
const planOpen = ref(false);

function showPlan(): void {
    planOpen.value = true;
}

// A phase change means a new card, so a stale "open" must not carry over.
watch(tripPhase, () => {
    planOpen.value = false;
});
</script>

<template>
    <AppLayout
        :title="currentTitle ?? $t(title)"
        :breadcrumbs="[{ title: currentTitle ?? $t('New chat') }]"
        :header="false"
    >
        <!-- Full viewport height: the header now sits inside the left column
             rather than above both, so nothing is stacked on top of this. -->
        <div class="flex h-svh flex-col" data-testid="chat-page">
            <main
                v-if="!conversationId && !messages.length"
                class="mx-auto flex w-full max-w-2xl flex-1 flex-col items-center justify-center px-6"
                data-testid="chat-landing"
            >
                <div class="w-full space-y-6">
                    <h1
                        class="text-center text-3xl font-semibold tracking-tight sm:text-4xl"
                    >
                        {{ $t('Where would you like to explore?') }}
                    </h1>
                    <PromptInput
                        @submit="handleSubmit"
                        data-testid="landing-form"
                    >
                        <PromptInputBody>
                            <PromptInputTextarea
                                :placeholder="
                                    $t('Tell me what you want to explore…')
                                "
                                rows="1"
                                class="min-h-0"
                                data-testid="landing-input"
                            />
                        </PromptInputBody>
                        <PromptInputFooter align="inline-end">
                            <PromptInputTools>
                                <PromptInputSpeechButton
                                    :aria-label="$t('Dictate a message')"
                                    data-testid="landing-mic"
                                />
                                <PromptInputSubmit
                                    :status="composerStatus"
                                    data-testid="landing-submit"
                                />
                            </PromptInputTools>
                        </PromptInputFooter>
                    </PromptInput>
                    <div class="space-y-1.5" data-testid="landing-examples">
                        <Button
                            v-for="example in examplePrompts"
                            :key="example.text"
                            variant="ghost"
                            class="text-muted-foreground hover:bg-muted hover:text-foreground h-auto w-full justify-start gap-3 px-3 py-2.5 text-left text-sm whitespace-normal"
                            @click="startExample(example.text)"
                        >
                            <span class="text-base" aria-hidden="true">
                                {{ example.emoji }}
                            </span>
                            {{ $t(example.text) }}
                        </Button>
                    </div>
                </div>
            </main>

            <ResizablePanelGroup
                v-else
                direction="horizontal"
                auto-save-id="chat-split"
            >
                <!-- Opens at its minimum so the map gets the room by default;
                     the divider is there for anyone who wants more text. -->
                <ResizablePanel
                    :default-size="CHAT_MIN_SIZE"
                    :min-size="CHAT_MIN_SIZE"
                    ref="pane"
                    class="flex flex-col"
                    data-testid="chat-pane"
                >
                    <AppHeader
                        :title="currentTitle ?? $t(title)"
                        :breadcrumbs="[
                            { title: currentTitle ?? $t('New chat') },
                        ]"
                    />

                    <Conversation ref="conversation">
                        <ConversationContent data-testid="chat-messages">
                            <ConversationEmptyState
                                v-if="!messages.length"
                                :title="$t('Ask me anything')"
                                :description="
                                    $t('Your conversation is saved as you go.')
                                "
                                data-testid="chat-empty"
                            />

                            <Message
                                v-for="message in messages"
                                v-show="!isEmptyReply(message)"
                                :key="message.id"
                                :from="message.role"
                                :class="
                                    message.role === 'user'
                                        ? 'flex-col items-end'
                                        : undefined
                                "
                                :data-testid="`message-${message.id}`"
                            >
                                <MessageContent
                                    :class="
                                        isPending(message) &&
                                        'animate-pulse opacity-60'
                                    "
                                    :data-pending="
                                        isPending(message) || undefined
                                    "
                                >
                                    <!-- The whole process in one collapsible,
                                         reasoning and tool calls interleaved in
                                         the order they streamed.

                                         Labelled "Route of thought": the
                                         components keep the upstream ai-elements
                                         names so they still diff against the
                                         registry, only the visible string is
                                         ours. -->
                                    <ChainOfThought
                                        v-if="thoughts(message).length"
                                        :default-open="isWriting(message)"
                                        :data-testid="`thoughts-${message.id}`"
                                    >
                                        <ChainOfThoughtHeader
                                            v-if="isWriting(message)"
                                            hide-label
                                        >
                                            <template #icon>
                                                <ThinkingIndicator />
                                            </template>
                                        </ChainOfThoughtHeader>
                                        <ChainOfThoughtHeader v-else>
                                            {{ $t('Route of thought') }}
                                        </ChainOfThoughtHeader>

                                        <ChainOfThoughtContent>
                                            <ChainOfThoughtStep
                                                v-for="thought in thoughts(
                                                    message,
                                                )"
                                                :key="thought.id"
                                                :label="
                                                    $t(
                                                        thought.label,
                                                        thought.params,
                                                    )
                                                "
                                                :description="
                                                    thought.description
                                                "
                                                :status="thought.status"
                                                :data-testid="`thought-${message.id}-${thought.id}`"
                                            >
                                                <template #icon>
                                                    <component
                                                        :is="thought.icon"
                                                        class="size-4"
                                                    />
                                                </template>

                                                <!-- One branch per ThoughtBody
                                                     variant in the registry. -->
                                                <MessageResponse
                                                    v-if="
                                                        thought.body?.kind ===
                                                        'markdown'
                                                    "
                                                    class="text-muted-foreground! text-xs leading-relaxed"
                                                    :content="thought.body.text"
                                                    mode="static"
                                                />
                                                <!-- The vendored component is
                                                     a single non-wrapping row,
                                                     so a search of any size
                                                     runs off the edge. Set
                                                     here rather than upstream
                                                     so it still diffs against
                                                     the registry. -->
                                                <ChainOfThoughtSearchResults
                                                    v-else-if="
                                                        thought.body?.kind ===
                                                        'results'
                                                    "
                                                    class="flex-wrap gap-y-1.5"
                                                >
                                                    <ChainOfThoughtSearchResult
                                                        v-for="item in thought
                                                            .body.items"
                                                        :key="`${item.marker.lat},${item.marker.lon}`"
                                                        as="button"
                                                        type="button"
                                                        class="focus-visible:ring-ring cursor-pointer transition-transform hover:-translate-y-0.5 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                                        :aria-label="
                                                            $t(
                                                                'Show :place on map',
                                                                {
                                                                    place: item.label,
                                                                },
                                                            )
                                                        "
                                                        @click="
                                                            focusMapMarker(
                                                                item.marker,
                                                            )
                                                        "
                                                    >
                                                        {{ item.label }}
                                                    </ChainOfThoughtSearchResult>
                                                </ChainOfThoughtSearchResults>
                                                <ChainOfThoughtImage
                                                    v-else-if="
                                                        thought.body?.kind ===
                                                        'image'
                                                    "
                                                    :caption="
                                                        thought.body.caption
                                                    "
                                                >
                                                    <img
                                                        :src="thought.body.src"
                                                        alt=""
                                                    />
                                                </ChainOfThoughtImage>
                                            </ChainOfThoughtStep>
                                        </ChainOfThoughtContent>
                                    </ChainOfThought>

                                    <template
                                        v-for="(part, index) in message.parts"
                                        :key="index"
                                    >
                                        <!-- The library's word animation is off
                                             on purpose. It wraps every word in a
                                             Vue TransitionGroup, and on each
                                             streamed token Vue measures every
                                             word and reads its computed style, so
                                             the cost grows with the reply: a
                                             long answer froze the page for ten
                                             seconds at a time and the animation
                                             never showed. Tokens arriving is the
                                             typewriter; the caret marks it. -->
                                        <MessageResponse
                                            v-if="part.type === 'text'"
                                            :content="part.text"
                                            :mode="
                                                isWriting(message)
                                                    ? 'streaming'
                                                    : 'static'
                                            "
                                            :enable-animate="false"
                                            caret="block"
                                        />
                                    </template>
                                </MessageContent>

                                <div
                                    v-if="isUndelivered(message)"
                                    class="mt-1 flex flex-col items-end gap-0.5"
                                    :data-testid="`undelivered-${message.id}`"
                                >
                                    <p
                                        class="text-destructive flex items-center gap-1 text-xs"
                                    >
                                        <CircleAlertIcon
                                            class="size-3.5 shrink-0"
                                        />
                                        {{ $t('Not delivered') }}
                                    </p>

                                    <!-- Withdrawn once the attempts are spent,
                                         rather than left there doing nothing. -->
                                    <Button
                                        v-if="retriesLeft(message) > 0"
                                        variant="link"
                                        size="sm"
                                        class="text-muted-foreground h-auto p-0 text-xs"
                                        :data-testid="`retry-${message.id}`"
                                        @click="retry(message)"
                                    >
                                        {{ $t('Try again') }}
                                    </Button>
                                </div>
                            </Message>

                            <!-- Sent, nothing back yet: no assistant message
                                 exists to hang a chain of thought on. -->
                            <ThinkingIndicator v-if="status === 'submitted'" />
                        </ConversationContent>

                        <ConversationScrollButton :status="status" />
                    </Conversation>

                    <div v-if="activeQuestion && isMapStaging" class="p-4">
                        <div
                            role="group"
                            :aria-label="activeQuestion.question"
                            class="bg-card text-card-foreground max-h-[min(62svh,42rem)] space-y-3 overflow-y-auto rounded-xl border p-4 shadow-sm"
                            data-testid="onboarding-question"
                        >
                            <h2 class="text-base font-semibold">
                                {{ activeQuestion.question }}
                            </h2>
                            <p
                                v-if="activeQuestion.count"
                                class="text-muted-foreground text-xs"
                            >
                                {{
                                    $t('Question :count of up to 10', {
                                        count: String(activeQuestion.count),
                                    })
                                }}
                            </p>
                            <button
                                v-for="(
                                    option, index
                                ) in activeQuestion.options"
                                :key="option"
                                type="button"
                                :aria-pressed="selectedAnswers.includes(option)"
                                class="border-input hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring flex w-full items-start gap-3 rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                :class="
                                    selectedAnswers.includes(option) &&
                                    'border-primary bg-primary/5'
                                "
                                @click="toggleAnswer(option)"
                            >
                                <span
                                    class="border-muted-foreground mt-0.5 grid size-5 shrink-0 place-items-center border"
                                    :class="[
                                        activeQuestion.multiple
                                            ? 'rounded-sm'
                                            : 'rounded-full',
                                        selectedAnswers.includes(option) &&
                                            'border-primary bg-primary text-primary-foreground',
                                    ]"
                                    aria-hidden="true"
                                >
                                    <CheckIcon
                                        v-if="selectedAnswers.includes(option)"
                                        class="size-3"
                                    />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium">{{
                                        option
                                    }}</span>
                                    <span
                                        v-if="index === 0"
                                        class="text-muted-foreground ml-2 text-xs"
                                    >
                                        {{ $t('Recommended') }}
                                    </span>
                                </span>
                            </button>
                            <input
                                v-model="otherAnswer"
                                type="text"
                                class="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                :placeholder="
                                    $t('Other — tell us in your own words')
                                "
                                data-testid="onboarding-other"
                                @keydown.enter.prevent="submitAnswer"
                            />
                            <div
                                class="flex items-center justify-between gap-3 pt-1"
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="skipInterview"
                                >
                                    {{ $t('Skip for now') }}
                                </Button>
                                <Button
                                    :disabled="
                                        selectedAnswers.length === 0 &&
                                        !otherAnswer.trim()
                                    "
                                    @click="submitAnswer"
                                    data-testid="submit-onboarding-answer"
                                >
                                    {{
                                        activeQuestion.multiple
                                            ? $t('Continue')
                                            : $t('Submit answer')
                                    }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div v-else class="p-4">
                        <PromptInput
                            data-testid="chat-form"
                            @submit="handleSubmit"
                        >
                            <PromptInputBody>
                                <PromptInputTextarea
                                    :placeholder="$t('Send a message...')"
                                    rows="1"
                                    class="min-h-0"
                                    data-testid="chat-input"
                                />
                            </PromptInputBody>
                            <PromptInputFooter align="inline-end">
                                <PromptInputTools>
                                    <PromptInputSpeechButton
                                        :aria-label="$t('Dictate a message')"
                                        data-testid="chat-mic"
                                    />
                                    <PromptInputSubmit
                                        :status="composerStatus"
                                        data-testid="chat-submit"
                                    />
                                </PromptInputTools>
                            </PromptInputFooter>
                        </PromptInput>
                    </div>
                </ResizablePanel>

                <ResizableHandle with-handle />

                <ResizablePanel
                    :default-size="100 - CHAT_MIN_SIZE"
                    :min-size="20"
                    data-testid="context-pane"
                >
                    <div class="relative size-full">
                        <ContextMap
                            ref="contextMap"
                            :class="isMapStaging ? 'blur-md' : undefined"
                            :view="mapView"
                            @viewport="viewport = $event"
                        />
                        <div
                            v-if="isMapStaging && !activeQuestion"
                            class="bg-background/35 absolute inset-0 grid place-items-center p-6 backdrop-blur-xs"
                        >
                            <Plan
                                :default-open="true"
                                :is-streaming="status === 'streaming'"
                                class="w-full max-w-xl shadow-lg"
                                data-testid="onboarding-card"
                            >
                                <PlanHeader>
                                    <div class="space-y-1">
                                        <PlanTitle>
                                            {{
                                                isPreparingOnboarding
                                                    ? $t('Preparing your map')
                                                    : onboardingPhase ===
                                                        'reviewing'
                                                      ? $t('Your map plan')
                                                      : $t(
                                                            'A few quick questions',
                                                        )
                                            }}
                                        </PlanTitle>
                                        <PlanDescription>
                                            {{
                                                isPreparingOnboarding
                                                    ? $t(
                                                          'Getting your first question ready…',
                                                      )
                                                    : onboardingPhase ===
                                                        'reviewing'
                                                      ? $t(
                                                            'Review this before opening the map.',
                                                        )
                                                      : $t(
                                                            'A few details help make the map useful.',
                                                        )
                                            }}
                                        </PlanDescription>
                                    </div>
                                </PlanHeader>

                                <PlanContent>
                                    <div
                                        v-if="isPreparingOnboarding"
                                        class="text-muted-foreground flex items-center gap-3 py-3 text-sm"
                                    >
                                        <LoaderCircleIcon
                                            class="text-primary size-5 animate-spin"
                                        />
                                        {{
                                            $t(
                                                'Building a few tailored questions…',
                                            )
                                        }}
                                        <Button
                                            v-if="
                                                conversationId &&
                                                status !== 'streaming' &&
                                                status !== 'submitted'
                                            "
                                            variant="ghost"
                                            size="sm"
                                            class="ml-auto"
                                            data-testid="skip-preparing"
                                            @click="skipInterview"
                                        >
                                            {{ $t('Skip for now') }}
                                        </Button>
                                    </div>
                                    <div
                                        v-else-if="
                                            onboardingPhase === 'reviewing' &&
                                            activePlan
                                        "
                                        class="space-y-4"
                                    >
                                        <PlanSummary :plan="activePlan" />
                                    </div>
                                </PlanContent>

                                <PlanFooter
                                    v-if="onboardingPhase === 'reviewing'"
                                    class="justify-end"
                                >
                                    <Button
                                        @click="showMap"
                                        data-testid="show-map"
                                    >
                                        {{ $t('Show my map') }}
                                    </Button>
                                </PlanFooter>
                            </Plan>
                        </div>

                        <!-- Styled like ContextMap's own controls so it reads as
                             part of the map, not the chat. Pressed while the
                             card is open, like the 3D toggle. -->
                        <Button
                            v-if="activePlan && !isMapStaging"
                            variant="secondary"
                            size="sm"
                            class="absolute bottom-2.5 left-2.5 z-10 h-7.25 gap-1.5 rounded px-2 shadow-[0_0_0_2px_rgba(0,0,0,0.1)]"
                            :class="
                                planOpen
                                    ? 'bg-neutral-800 text-white hover:bg-neutral-700'
                                    : 'bg-white text-neutral-800 hover:bg-neutral-100'
                            "
                            :aria-pressed="planOpen"
                            data-testid="show-plan"
                            @click="planOpen = !planOpen"
                        >
                            <ClipboardListIcon class="size-4" />
                            {{ $t('Plan') }}
                        </Button>

                        <div
                            v-if="activePlan && !isMapStaging && planOpen"
                            class="absolute inset-0 grid place-items-center p-6"
                            @click.self="planOpen = false"
                        >
                            <Plan
                                :default-open="true"
                                class="w-full max-w-xl shadow-lg"
                                data-testid="plan-card"
                            >
                                <PlanHeader>
                                    <div class="space-y-1">
                                        <PlanTitle>
                                            {{ $t('Your map plan') }}
                                        </PlanTitle>
                                        <PlanDescription>
                                            {{
                                                $t(
                                                    'Tell the assistant what to change and the plan follows.',
                                                )
                                            }}
                                        </PlanDescription>
                                    </div>
                                </PlanHeader>
                                <PlanContent>
                                    <PlanSummary :plan="activePlan" />
                                </PlanContent>
                                <PlanFooter class="justify-between">
                                    <Button
                                        variant="outline"
                                        :disabled="status !== 'ready'"
                                        data-testid="back-to-planning"
                                        @click="backToPlanning"
                                    >
                                        {{ $t('Back to planning') }}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        data-testid="hide-plan"
                                        @click="planOpen = false"
                                    >
                                        {{ $t('Back to the map') }}
                                    </Button>
                                </PlanFooter>
                            </Plan>
                        </div>
                    </div>
                </ResizablePanel>
            </ResizablePanelGroup>
        </div>

        <AlertDialog :open="sessionExpired">
            <AlertDialogContent data-testid="session-expired-dialog">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {{ $t('Your session has expired') }}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {{
                            $t(
                                'You were signed out, so your message was not sent. Sign in again to continue the conversation.',
                            )
                        }}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogAction
                        data-testid="session-expired-login"
                        @click="goToLogin"
                    >
                        {{ $t('Go to login') }}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
