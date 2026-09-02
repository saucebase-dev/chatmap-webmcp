# Map: Map-ready onboarding

Status: Closed
Labels: wayfinder:map

## Destination

A signed-in visitor can start a map-free chat, describe their goal, complete or
skip a short discovery interview, review a saved map-ready plan, and continue
to the map.

## Notes

- This map covers landing and discovery only; plan-driven map exploration is a
  later effort.
- The landing page shows three shuffled example prompts from a starting set of
  six. The set can grow later.
- The interview asks no more than ten questions. Each question uses options,
  with the recommended option first.
- A visible skip action opens the map without an interview.
- The plan is shown for review before the map and can be updated by later chat.
- Follow `CONTEXT.md`, `.ai/rules/`, and the root hot-reload workflow.

## Decisions so far

- [Discovery interview rules](issues/02-grilling-discovery-interview-rules.md):
  adaptive, optional interview with a ten-question maximum, recommended-first
  options, typed answers, and a typed location requirement.
- [Map-ready plan shape and review](issues/03-grilling-map-ready-plan-shape-and-review.md):
  an adaptive plan in an editable review card that stays current after the map
  opens.
- [Conversation persistence seams](issues/04-task-assess-conversation-persistence-seams.md):
  module-owned onboarding state, keyed by the SDK conversation, preserves the
  phase, active question, answers, and plan without modifying vendor tables.
- [Landing page and sample prompts](issues/01-prototype-landing-page-and-sample-prompts.md):
  a map-free, centred entry screen with three shuffled, one-click global
  examples, followed by the staged split layout.

## Not yet specified

<!-- All in-scope decisions are settled. -->

## Out of scope

- Plan-driven map search, result presentation, and recommendation behavior
  after the visitor reaches the map.
