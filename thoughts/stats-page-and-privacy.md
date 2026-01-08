# Stats Page & Privacy Rethink

## Problem with the landing page table

Showing all shortened links on the homepage was a mistake. Anyone who visits
the root URL can see every URL ever shortened — original destinations, click
counts, timestamps. That's a privacy issue and it makes the tool feel like a
shared clipboard rather than a personal utility.

## Decision: /{code}+ stats page

Adopted the `+` suffix convention (same as bit.ly). Appending `+` to any short
link shows a stats page for that specific code without redirecting.

- `/{code}`  → 301 redirect to original URL
- `/{code}+` → stats page: original URL, click count, creation date

The stats URL is surfaced in the success flash after creating a link, and
included in API responses as a `stats` field alongside `short`.

## Homepage is now just the form

No table, no stats bar, no list of links. Just the input and a one-line hint
about the `+` convention. Clean.

## DELETE endpoint removed

Removed `DELETE /api/links/{code}`. Reasons:
- No auth layer — anyone with the code could delete links.
- The use case doesn't justify the surface area right now.
- Can be added back behind an API key when that's implemented.

## GET /api/links removed

Same reasoning as the homepage table — exposes all links to anyone who knows
the endpoint exists. Removed. The only list-style access is through the
database directly (admin concern, not a public API concern).

## Router fix

`patternToRegex()` was passing literal characters outside `{param}` blocks
directly into the regex without escaping. The `+` in `/{code}+` would have
been interpreted as a regex quantifier. Fixed by splitting on `{param}` tokens,
escaping everything else with `preg_quote`, then reassembling.
