# Grilling: Discovery interview rules

Status: Closed
Labels: wayfinder:grilling
Assignee: Codex
Parent: ../PRD.md
Blocked by: None

## Question

What question-selection and completion rules let the assistant collect useful
place-discovery context in at most ten optional, recommended-first questions?

## Comments

- The interview always starts after the first message unless the visitor chooses
  to skip it.
- The assistant chooses each question from the original message and previous
  answers, rather than following a fixed list.
- Every question includes an "Other" option for a typed answer.
- The interview ends as soon as the assistant has enough detail; ten questions
  is a hard maximum, not a target.
- The interface shows progress as the current question out of a maximum of ten.
- The first option carries a visible "Recommended" label.
- A "Skip questions" action remains available throughout the interview.
- Visitors cannot edit earlier answers during the interview; they revise details
  during plan review instead.
- Questions may allow multiple selections when that captures the visitor's
  preference better than one choice.
- A question offers at most five options, in addition to "Other".
- Typed "Other" answers appear in the conversation as normal visitor messages.
- A normal visitor message sent while a question is open is treated as that
  question's answer.
- The assistant prioritizes relevant missing context about location, purpose,
  timing, companions, interests, and constraints such as budget or
  accessibility.
- A location is required before the interview can finish.
- The first version does not request device location; visitors name a place.

- Resolution: The discovery interview always starts after the first message
  unless skipped. It is adaptive, asks at most ten questions, and ends early
  once it has enough relevant detail. Questions have at most five options plus
  "Other", show a labelled recommended first option, support multiple answers
  when needed, and accept normal typed messages as answers. Progress and skip
  remain visible. Location is required to finish the interview, while device
  location is not requested. Earlier answers are revised in plan review.
