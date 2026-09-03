# Wayfinder

> An agent-native trip planner where people, AI agents, and a live map stay in sync.

Wayfinder turns a rough idea such as “a rainy Sunday in Porto with kids” into a
map-ready plan. Its own assistant asks only the useful follow-up questions,
builds a structured plan, and places relevant results on an interactive map.

What makes Wayfinder different is that the experience is also available to the
visitor's browser agent through
[WebMCP](https://github.com/webmachinelearning/webmcp). The agent can start or
resume a trip, answer the guided interview with the visitor, update the plan,
talk to Wayfinder's place specialist, and read or move the live map without
scraping the interface.

Built for [The WebMCP Challenge](https://webmcp.devpost.com/).

<div align="center">

[![WebMCP](https://img.shields.io/badge/WebMCP-enabled-7C3AED)](https://github.com/webmachinelearning/webmcp)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Vue](https://img.shields.io/badge/Vue-3.5-4FC08D?logo=vue.js&logoColor=white)](https://vuejs.org)
[![Inertia.js](https://img.shields.io/badge/Inertia.js-3.x-9553E9)](https://inertiajs.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## The experience

1. Describe the outing or trip you want in one sentence.
2. Wayfinder runs a short discovery interview about the details that matter,
   such as timing, companions, interests, budget, or accessibility.
3. Review the structured plan and open the map.
4. Wayfinder finds relevant places, shows up to 40 useful results together,
   and keeps the conversation and map synchronized.
5. Refine the plan in either interface. A person can click, type, and drag the
   map while their browser agent uses the same live state through WebMCP.

The assistant never invents coordinates. Named places are geocoded with
[Nominatim](https://nominatim.openstreetmap.org/); nearby place searches use
[Overpass](https://overpass-api.de/); and the vector map is served by
[OpenFreeMap](https://openfreemap.org/) from OpenStreetMap data.

## Why WebMCP

A trip-planning page is stateful: the current question, saved preferences,
conversation history, selected trip, and map viewport all matter. A general
browser agent could try to infer that state from buttons and rendered text, but
that is slow and brittle. Wayfinder instead exposes explicit, typed tools for
the actions and context that are safe at each point in the journey.

This creates a useful agent-to-agent handoff. The visitor's agent understands
the visitor and can orchestrate the task; Wayfinder's assistant understands the
trip, place data, and map. WebMCP lets them collaborate while the person stays
in control and sees every result appear in the normal interface.

### Browser tools

Wayfinder declares 15 imperative WebMCP tools: two global authentication entry
points and 13 authenticated trip tools. Signed-out pages expose only login and,
when registration is enabled, signup. After authentication those guest tools
are withdrawn and Wayfinder registers only the tools that fit the current trip
phase. Read-only tools are annotated with `readOnlyHint`.

| Tool                 | Available   | What it lets the visitor's agent do                           |
| -------------------- | ----------- | ------------------------------------------------------------- |
| `open_login`         | Signed out  | Open the sign-in form without handling credentials            |
| `open_signup`        | Signed out  | Open registration when new accounts are enabled               |
| `read_trip_plan`     | Always      | Read the phase, open question, answers, and saved plan        |
| `start_trip`         | Always      | Start a fresh trip from a natural-language goal               |
| `answer_question`    | Discovery   | Answer the current interview question                         |
| `skip_interview`     | Discovery   | End discovery early and open the map                          |
| `open_map`           | Review      | Accept the plan and begin map exploration                     |
| `update_trip_plan`   | Review, map | Change the complete plan in plain language                    |
| `ask_this_assistant` | Always      | Delegate a place question to Wayfinder's specialist assistant |
| `read_current_chat`  | Always      | Read the visible conversation transcript                      |
| `list_chat_sessions` | Always      | List the visitor's saved conversations                        |
| `open_chat_session`  | Always      | Switch to one of those conversations                          |
| `show_trip_plan`     | Map         | Bring the saved plan back over the map                        |
| `read_map_location`  | Map         | Read the current place, center, zoom, and moved state         |
| `show_place_on_map`  | Map         | Move the map to a named place without adding a chat message   |

All tools run in the page. The guest tools only navigate to Wayfinder's forms;
they never accept credentials. Authenticated tools reuse the visitor's existing
session, with no separate API token, CORS surface, or second account for the
browser agent.

## Try the WebMCP flow

Use ChatGPT's in-app browser, or Chrome 149+ with
`chrome://flags/#enable-webmcp-testing` enabled and the browser relaunched.
Chrome 151+ is recommended.

1. Open `/chat`. If signed out, the browser agent can call `open_login` or
   `open_signup`; the visitor completes the form privately.
2. Open the **WebMCP tools** item in Wayfinder's sidebar. A green indicator
   means the browser API is connected; the panel also shows which tools are
   active in the current phase.
3. Give the browser agent this prompt:

    > Open this Wayfinder page. Discover and use its WebMCP tools to plan a
    > rainy Sunday in Porto for two adults and two children, with a low budget,
    > museums, and cafés. Use the details I supplied when answering the guided
    > questions, then open the map when the plan is ready.

4. Continue in either direction: answer a question or drag the map yourself,
   then ask the browser agent to pick up from the page's current state.

For a timed demo script, paste-ready submission copy, and the final compliance
checklist, see [docs/HACKATHON_SUBMISSION.md](docs/HACKATHON_SUBMISSION.md).

## Architecture

```text
Visitor <-> browser agent
                 |
                 | document.modelContext tools
                 v
        Vue page + live map state
                 |
                 | authenticated same-origin requests
                 v
       Laravel + Laravel AI assistant
          |          |          |
       OpenAI    Nominatim   Overpass / OSM
```

The WebMCP implementation is intentionally small and inspectable:

- [`resources/js/webmcp/index.ts`](resources/js/webmcp/index.ts) owns tool
  registration, authentication gating, result envelopes, and reactive
  re-registration with `AbortController`.
- [`modules/auth/resources/js/webmcp/authTools.ts`](modules/auth/resources/js/webmcp/authTools.ts)
  defines the global, guest-only login and signup entry points.
- [`modules/chat/resources/js/webmcp/chatTools.ts`](modules/chat/resources/js/webmcp/chatTools.ts)
  defines the 13 authenticated trip, conversation, and map tools.
- [`modules/chat/resources/js/pages/Index.vue`](modules/chat/resources/js/pages/Index.vue)
  connects those tools to the current conversation, onboarding phase, plan,
  and map viewport.
- [`modules/chat/src/Ai/ChatAgent.php`](modules/chat/src/Ai/ChatAgent.php) is the
  site specialist. Its server-side tools interview the visitor, save a
  map-ready plan, geocode named places, and search OpenStreetMap data.

## Run locally

### Requirements

- Docker with Docker Compose
- An OpenAI API key
- For WebMCP testing: ChatGPT's in-app browser, or Chrome 149+ with the
  experimental WebMCP flag enabled

### Install

```bash
cp .env.example .env
# Add OPENAI_API_KEY to .env

docker compose up -d
docker compose exec app composer setup
```

Wayfinder is served at `https://localhost`. Create an account, sign in, and
open `https://localhost/chat`. The local certificate may require one browser
confirmation the first time.

For frontend hot reload, run this from the repository root:

```bash
npm run dev
```

### Data and storage

The development stack uses PostgreSQL 18 with PostGIS 3.6, Redis, and a database
queue worker. PostGIS is available for future geometry work; the current map
flow uses portable columns and external OpenStreetMap services. Conversations,
messages, interview answers, map-ready plans, and session ownership are stored
by Laravel.

## Verification

```bash
php artisan test --compact
npm run lint
npm run format:check
```

The PHP suite uses in-memory SQLite so it cannot touch the development database.
End-to-end tests are available through `npm run test:e2e`.

## Project structure

Wayfinder is a Vue-only modular Laravel application built on
[Saucebase](https://saucebase-dev.github.io/docs/). Modules are copy-and-own
Composer packages under `modules/`; installing one makes it active.

| Module     | Responsibility                                                        |
| ---------- | --------------------------------------------------------------------- |
| `chat`     | Guided trip planning, streaming assistant, live map, and WebMCP tools |
| `auth`     | Registration, sign-in, magic links, email verification, and OAuth     |
| `settings` | Profile, avatar, password, and connected-account management           |

The project and its WebMCP implementation were created during the challenge
submission period. The repository history begins on September 1, 2026.

## License

Wayfinder is open source under the [MIT License](LICENSE). Third-party notices
are listed in [Composer licenses](THIRD_PARTY_LICENSES.md) and
[npm licenses](THIRD_PARTY_PACKAGE_LICENSES.md). Code originating in Saucebase
retains its original copyright notice alongside the Wayfinder attribution.
