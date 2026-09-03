# Task: Assess conversation persistence seams

Status: Closed
Labels: wayfinder:task
Assignee: Codex
Parent: ../PRD.md
Blocked by: 03-grilling-map-ready-plan-shape-and-review.md

## Question

Which existing conversation, message, and tool extension points can safely
store the onboarding phase, interview answers, and map-ready plan?

## Comments

- Resolution: Add a module-owned one-to-one onboarding record keyed by the SDK
  conversation id. It stores the onboarding phase, current question, answers,
  question count, and map-ready plan as structured data. Do not alter the
  vendor-managed `agent_conversations` or message metadata: those tables only
  hold SDK ownership, title, and transcript data.

  The chat controller already owns every conversation before it streams. It
  should load this record there, pass its plan and phase to `ChatAgent` as
  hidden instructions, and return it as Inertia page data. Interactive choices,
  typed "Other" answers, and direct plan edits can reuse the existing stream
  route as user messages; the controller records them against the current
  question before the agent decides what comes next. Persisting the current
  question is necessary because reopened conversations currently flatten tool
  results to text and cannot recreate an interactive question from transcript
  alone.
