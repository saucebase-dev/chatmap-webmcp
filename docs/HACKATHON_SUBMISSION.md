# Wayfinder — WebMCP Challenge submission pack

This is a working sheet for the Devpost submission. Replace every `TODO` before
copying the text into Devpost.

## Deadline and official links

- Submission deadline: **September 3, 2026 at 1:00 PM Pacific Time**
  (**9:00 PM in Dublin**).
- [Challenge overview and requirements](https://webmcp.devpost.com/)
- [Challenge resources and FAQ](https://webmcp.devpost.com/resources)
- [Official rules](https://webmcp.devpost.com/rules)
- Judging ends September 21, 2026 at 5:00 PM Pacific Time. Keep the submitted
  repository, live app, and test account unchanged and available until then.

## Submission links and access

| Item                  | Value                                                                        | Status                                    |
| --------------------- | ---------------------------------------------------------------------------- | ----------------------------------------- |
| Project name          | Wayfinder                                                                    | Ready                                     |
| Public repository     | https://github.com/saucebase-dev/chatmap-webmcp                              | Ready; verified public September 3, 2026  |
| Open-source license   | MIT (`LICENSE`)                                                              | Ready; GitHub detects it as MIT           |
| Live app              | TODO: add production URL                                                     | Required                                  |
| Public YouTube demo   | TODO: add video URL                                                          | Required; must be under 3:00              |
| Team / representative | TODO: add names and representative                                           | Required if entering as a team            |
| Test account          | TODO: add email and password in Devpost's private Testing Instructions field | Required if judges cannot register freely |

Do not put test credentials in this file, the README, the video, or any public
repository content.

## Paste-ready project copy

### One-line tagline

An agent-native trip planner where people, AI agents, and a live map stay in sync.

### Short description

Wayfinder turns a rough trip idea into a structured, map-ready plan through a
short guided conversation. Its WebMCP tools let the visitor's browser agent
start and resume trips, answer questions with the visitor, update plans, consult
Wayfinder's place specialist, and read or move the live map through the user's
existing session.

### Full challenge description

Wayfinder is a conversational place explorer and trip planner for people who
know the experience they want but not yet which places fit it. A visitor starts
with one sentence, such as “a rainy Sunday in Porto with kids.” Wayfinder asks a
few focused questions about the missing details, saves the result as a
structured map-ready plan, and opens an interactive map with relevant places.
The visitor can keep refining the plan, ask location questions, or drag the map
and ask what is nearby.

This use case is a strong fit for WebMCP because the page has important live
state that is difficult for a general agent to infer reliably from rendered
controls: the current interview question, prior answers, saved plan,
conversation, selected session, and map viewport. Wayfinder publishes that
state and its safe actions as 15 typed browser tools. Signed-out pages expose
only `open_login` and, when registration is enabled, `open_signup`; the visitor
still enters credentials privately. After authentication, the active set
changes with the workflow, so an agent sees interview tools during discovery,
an `open_map` action during review, and map tools only after map exploration
begins.

The result is a better experience than either a standalone chatbot or UI
automation. The visitor's agent can carry their intent into the task and
orchestrate it, while Wayfinder's specialist assistant handles place reasoning,
geocoding, and OpenStreetMap search. The person remains in the normal visual
interface and can take over at any time. They can answer one question by hand,
let their agent answer the next, drag the map themselves, and then ask the agent
to continue from that exact location.

Before WebMCP, making those two agents and the person collaborate would require
a separate public API and token, fragile DOM automation, or repeated
copy-and-paste of the plan and map context. Here, tools execute inside the page
through the visitor's existing authenticated session, with no second auth
surface and no duplicated state.

Wayfinder implements the imperative WebMCP API with
`document.modelContext.registerTool(...)`. A Vue composable collects tool
providers, filters guest and authenticated tools, annotates read-only
operations, wraps results in WebMCP content envelopes, and uses
`AbortController` to withdraw and re-register tools when authentication or the
trip phase changes. The chat page binds the tool implementations directly to
its current reactive state and same-origin Laravel routes. Laravel AI powers
the site's streaming specialist assistant, while Nominatim geocodes names,
Overpass finds nearby OpenStreetMap features, and MapLibre renders OpenFreeMap
vector tiles.

### Inspiration

Travel planning rarely begins with a precise search query. People start with
constraints, companions, moods, and half-formed ideas, then jump between a
chatbot, search results, and a map. We wanted the conversation and map to behave
as one workspace—and to let a personal agent join that workspace without
pretending to be a person clicking buttons.

### What it does

- Turns a natural-language goal into a short, adaptive discovery interview.
- Saves the answers as a structured map-ready plan for review.
- Finds and displays relevant places on an interactive map.
- Keeps follow-up chat grounded in the map's current viewport.
- Stores conversations so visitors or their agents can resume a trip.
- Exposes two guest authentication tools and 13 phase-aware tools for planning,
  chat, sessions, and map control.

### How we built it

The frontend uses Vue 3, Inertia 3, TypeScript, MapLibre GL, and the Vercel AI
SDK. The Laravel 13 backend uses Laravel AI with OpenAI's `gpt-5.4-mini` for the
streaming place assistant. Nominatim provides geocoding, Overpass provides
nearby OpenStreetMap features, and OpenFreeMap provides vector tiles. Data is
stored in PostgreSQL; the local Docker stack also includes Redis and a queue
worker.

WebMCP is implemented directly against the browser API rather than through a UI
automation layer. Each tool has a model-facing description, JSON input schema,
read-only annotation where applicable, authentication requirement, and an
executor connected to the live page or a same-origin endpoint.

### Challenges

The hard part was not registering a tool; it was keeping the tool surface
truthful as a stateful workflow changed. Chrome ignores duplicate registrations
and does not expose a simple unregister call, so Wayfinder aborts the previous
registrations and declares the valid set again whenever the trip phase changes.
We also had to make async tool calls wait for both the streamed assistant reply
and the refreshed server state so a browser agent never reads a stale question
or plan.

Map context created another trust boundary. Coordinates and viewport details
coming from the browser are validated and bounded before reaching the model,
and the model cannot write arbitrary Overpass queries. It chooses from an
allow-list of supported place categories instead.

### Accomplishments

- A non-trivial WebMCP surface with 15 tools from authentication through an
  end-to-end trip workflow.
- Reactive tool availability that matches discovery, review, and mapping.
- A genuine two-agent design: the visitor's agent orchestrates while
  Wayfinder's specialist reasons about places and operates the map.
- Bidirectional human/agent handoff through shared conversation and map state.
- Geocoding and multi-place map results without invented coordinates.
- Authenticated, same-origin execution with ownership checks on conversations.

### What we learned

Agent-friendly tools need to expose workflow state, not just endpoints. Clear
descriptions, narrow schemas, read-only hints, and phase-specific availability
made the browser agent's choices more reliable. We also learned that the visual
UI remains essential: WebMCP is most compelling when an agent's action appears
in the same workspace where the person can inspect, adjust, or continue it.

### What's next

- Route building and travel-time comparisons between selected places.
- Saved collections and shareable plans.
- Geometry-aware searches using PostGIS instead of rectangular bounding boxes.
- Accessibility, opening-hours, and budget filters with clearer provenance.
- WebMCP evaluation cases for tool selection, state transitions, and recovery.

## WebMCP tool inventory

| Tool                 | Phase        | Read-only | Purpose                                         |
| -------------------- | ------------ | --------- | ----------------------------------------------- |
| `open_login`         | Signed out   | No        | Open login; the visitor enters credentials      |
| `open_signup`        | Signed out   | No        | Open registration when new accounts are enabled |
| `read_trip_plan`     | Any          | Yes       | Read phase, question, answers, and plan         |
| `start_trip`         | Any          | No        | Start a new trip from one sentence              |
| `answer_question`    | Discovery    | No        | Answer the open interview question              |
| `skip_interview`     | Discovery    | No        | Move directly to map exploration                |
| `open_map`           | Review       | No        | Accept the reviewed plan and open the map       |
| `update_trip_plan`   | Review / map | No        | Revise the plan in plain language               |
| `ask_this_assistant` | Any          | No        | Ask Wayfinder's specialist and await its reply  |
| `read_current_chat`  | Any          | Yes       | Read the current transcript                     |
| `list_chat_sessions` | Any          | Yes       | List saved conversations                        |
| `open_chat_session`  | Any          | No        | Open a saved conversation                       |
| `show_trip_plan`     | Map          | No        | Restore the plan overlay                        |
| `read_map_location`  | Map          | Yes       | Read place, center, zoom, and moved state       |
| `show_place_on_map`  | Map          | No        | Move the map without adding a chat turn         |

## Testing instructions for judges

Paste this into Devpost's Testing Instructions field, replacing the TODOs:

> Open **TODO: LIVE URL** in ChatGPT's in-app browser. Alternatively, use
> Chrome 149+ with `chrome://flags/#enable-webmcp-testing` enabled, then
> relaunch Chrome. While signed out, the page exposes `open_login` and
> `open_signup`; ask the browser agent to open the appropriate form, then enter
> **TODO: TEST EMAIL** and **TODO: TEST PASSWORD** yourself. Open `/chat`, then
> open “WebMCP tools” in the sidebar; the status indicator should be green. Ask
> the browser agent: “Discover and use this page's WebMCP tools. Plan a rainy
> Sunday in Porto for two adults and two children, with a low budget, museums,
> and cafés. Use the details I supplied to answer the guided questions, then
> open the map.” The agent should call `start_trip`, use `answer_question` while
> Wayfinder interviews it, and call `open_map` after the plan appears. On the
> map, ask it to “Update the plan to vegetarian food and step-free places,” then
> “Call `read_map_location` and ask Wayfinder what is interesting near the
> current map center.”

Before submitting those instructions, test them from a signed-out, clean
browser profile against the production URL.

## Video plan — target length 2:45

The three examples form one continuous story. Record response waits separately
and trim dead time; do not speed up the visible tool calls so far that judges
cannot read their names.

### Before recording

- Use the deployed app and a clean demo account with no private information.
- Start signed out so the agent can call `open_login`, then cut while you enter
  credentials and resume after sign-in. Never show credentials.
- Use ChatGPT's in-app browser, or a Chrome build with WebMCP enabled and an
  agent connected.
- Start on a fresh `/chat` page and confirm the WebMCP indicator is green.
- Set browser zoom so the chat, map, agent, and tool-call names remain legible.
- Close notifications and unrelated tabs. Use voice only or music you are
  authorized to use.
- Record at 1080p and leave a small timing margin below the three-minute limit.

### 0:00–0:20 — introduction

**Screen:** Wayfinder's fresh chat screen, then briefly show the empty world map.

**Say:**

> This is Wayfinder, an agent-native trip planner. You describe the experience
> you want, Wayfinder asks a few useful questions, and turns the result into a
> live map. The same workflow is available to your own browser agent through
> WebMCP, so it can collaborate with the site instead of scraping or clicking
> through the UI.

### 0:20–0:35 — prove WebMCP is connected

**Screen:** Ask the browser agent to sign in and show its `open_login` call.
Cut while entering credentials, then open Wayfinder's **WebMCP tools** panel and
show the green connection indicator with several active trip tools.

**Say:**

> Signed out, Wayfinder exposes only safe entry points and never handles my
> credentials. Once I sign in, those tools are replaced by typed trip tools that
> reuse my session and change with the trip's current phase.

### 0:35–1:20 — example 1: create a trip through tools

**Prompt to browser agent:**

> Discover and use this page's WebMCP tools. Plan a rainy Sunday in Porto for
> two adults and two children, with a low budget, museums, and cafés. Use those
> details to answer the guided questions. For anything else, choose Wayfinder's
> recommended option for this demo, then open the map when the plan is ready.

**Expected visible calls:** `start_trip`, two or more `answer_question` calls,
then `open_map`. Trim model waiting time, but show the interview question and
plan card briefly before the map opens.

**Say:**

> From one instruction, my agent starts a real Wayfinder conversation. The
> site's specialist controls the interview, while my agent supplies only the
> preferences I gave it. Once the plan is complete, the available tool set
> changes and the agent opens the map. Wayfinder then finds places and renders
> them in the normal interface.

### 1:20–1:55 — example 2: refine the live plan

**Prompt to browser agent:**

> Update this trip so all food suggestions are vegetarian and every place
> should be step-free where possible. Refresh the map for the new plan.

**Expected visible call:** `update_trip_plan`. In Wayfinder's streamed route,
briefly show its internal plan-save and place-search steps, followed by the
updated pins.

**Say:**

> This is not a detached answer. The WebMCP call delegates to Wayfinder's own
> assistant, updates the saved structured plan, and refreshes the map. The
> person can inspect the result and keep editing it from either side.

### 1:55–2:30 — example 3: hand control between human and agent

**Screen:** Drag the map manually to a nearby area before sending the prompt.

**Prompt to browser agent:**

> Call `read_map_location`, then ask Wayfinder's assistant what is interesting
> near the current map center.

**Expected visible calls:** `read_map_location`, then `ask_this_assistant`.
Wayfinder receives the moved viewport, names it, searches if appropriate, and
updates the chat or map.

**Say:**

> I moved the map myself. My agent reads that live viewport through WebMCP and
> delegates the place question to Wayfinder. This human-to-agent-to-specialist
> handoff would otherwise require copied coordinates, a second API, or brittle
> UI automation.

### 2:30–2:45 — close

**Screen:** Hold on the final conversation and populated map, then show the
repository's WebMCP implementation files.

**Say:**

> Wayfinder exposes 15 tools in total: two safe authentication entry points and
> 13 phase-aware tools across planning, conversations, and map control. WebMCP
> turns the website into a shared workspace for a person, their agent, and a
> domain-specific assistant—all while keeping the visual app in control.

## The three demo examples at a glance

### 1. Plan from intent

- Prompt: the rainy Porto family-day prompt above.
- Demonstrates: tool discovery, `start_trip`, guided `answer_question` calls,
  dynamic phase changes, and `open_map`.
- Judge takeaway: the agent completes a coherent product workflow rather than
  triggering a single novelty action.

### 2. Revise the result

- Prompt: add vegetarian and step-free constraints.
- Demonstrates: `update_trip_plan`, delegation to the site's specialist, saved
  state, and visible map refresh.
- Judge takeaway: WebMCP improves an ongoing task and keeps the UI inspectable.

### 3. Continue from human map movement

- Action: manually drag the map, then ask the agent to read it and consult
  Wayfinder.
- Demonstrates: `read_map_location`, `ask_this_assistant`, and shared live state.
- Judge takeaway: people and agents can hand control back and forth without
  copying context.

## Final submission checklist

### Required project assets

- [ ] Join the hackathon and complete every required Devpost field.
- [ ] Deploy the exact submitted commit and add the working live URL.
- [ ] Confirm the live app works in ChatGPT's in-app browser or WebMCP-enabled
      Chrome.
- [x] Confirm the repository is public and includes all source, assets, and
      setup instructions.
- [x] Confirm GitHub detects the MIT license and displays it in the repository
      **About** area.
- [ ] Add private test credentials and concise steps to Devpost if sign-up is
      not the intended judging path.
- [ ] Upload a public YouTube video with clear audio and a duration below 3:00.
- [ ] Add the YouTube URL and live URL to both Devpost and any desired README
      links.

### Content quality

- [ ] The text explicitly explains why WebMCP fits the use case.
- [ ] The text explains the user-experience improvement.
- [ ] The text shows what a person and agents can do together that was
      previously difficult.
- [ ] The implementation summary names the imperative browser API and points to
      the relevant source files.
- [ ] Claims in the description and video match the deployed build.
- [ ] Add a clear project thumbnail and two or three screenshots if the Devpost
      form offers gallery media.

### Last production pass

- [ ] Confirm the deployed app and page title say **Wayfinder**.
- [ ] Test registration or the judge account from a signed-out browser.
- [ ] Confirm the WebMCP indicator is green after sign-in.
- [ ] Run all three demo prompts against production and save a backup recording.
- [ ] Check mobile/desktop layout, light/dark theme, map tiles, geocoding, place
      search, streaming, and queued conversation titles.
- [ ] Remove private data, notifications, API keys, and credentials from the
      video and screenshots.

### Eligibility and freeze

- [x] Repository history begins September 1, 2026, inside the August 25 to
      September 3 submission period.
- [x] An MIT license file and third-party license notices are present.
- [ ] Submit before September 3, 2026 at 1:00 PM Pacific Time.
- [ ] After the deadline, do not edit the submitted Devpost entry, repository,
      or live site until winners are announced. If development must continue, fork
      the repository and work on the fork.
