# Grilling: Map-ready plan shape and review

Status: Closed
Labels: wayfinder:grilling
Assignee: Codex
Parent: ../PRD.md
Blocked by: 02-grilling-discovery-interview-rules.md

## Question

What information belongs in a map-ready plan, how should a visitor review it,
and how should later chat messages revise it?

## Comments

- The plan always shows the visitor's goal and location. It adds only the
  details relevant to the request, such as timing, interests, budget,
  accessibility, or group size.
- The plan appears as an expandable review card in the conversation, with a
  "Show my map" action.
- The card starts expanded before the map opens and remains collapsed afterward.
- Later visitor messages automatically update the plan and show a short
  "Plan updated" note.
- The review card highlights sections changed by a later message.
- Visitors can edit a plan section directly from the review card.
- Editing a section opens a small text editor with a Save action.
- Skipping the interview saves a minimal plan from the first message and opens
  the map without plan review.

- Resolution: A map-ready plan always shows goal and location, plus only the
  details relevant to the request. It is reviewed in an expandable card that
  opens before the map and collapses after. Visitors can edit sections directly
  in a small text editor. Later messages update the plan automatically, show a
  short update note, and highlight changed sections. Skipping creates a minimal
  plan and opens the map immediately.
- Placement update: The discovery interview and plan review card are centred in
  the right pane over a blurred map. The map is revealed when the visitor
  chooses "Show my map".
