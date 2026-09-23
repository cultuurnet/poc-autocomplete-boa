We need to change the "hasOvernight" field, to express 3 states: with overnight stay, without overnight
stay, and unknown (never sent).  (true/false/null).

This change request is the result of
[overnight brainstorm](https://confluence.publiq.be/spaces/UPW/pages/228527225/21+9+2026+-+overnight) where option 1 was picked:
*keep the current boolean format, but make sending no value meaningfully different from
sending a value.*

### Behaviour

- Omitting `hasOvernight` keeps the field absent -> "unknown". (not shown in json)
- Setting `hasOvernight` to `true` or `false` stores that value.
- Once a value has been set, a follow-up PUT/PATCH can no longer reset it to `null`:  
  it can only be flipped between `true` and `false`.
- "hasOvernight": false` will now appear in JSON projections where it
  previously did not.

## Todo 1:   entry-api

Accept and persist an explicit `false`, and make "set" irreversible.

- Accept `hasOvernight: false` on create and on PUT/PATCH and persist it.
- Reject an attempt to set `hasOvernight` back to `null` once a value has been set
  (only `true` <-> `false` remains possible).
- Project `false` instead of omitting the field.
- Update the JSON schema / API documentation

## Todo 2:   SAPI3

Support all three states in search.

- Index `false` as a real value instead of treating it as absent.
- Allow filtering on the 2 states: `true`, `false`, once the filter hasOvernight is selected, we DO NOT show any. "unknowns/null", regardless if we search on true or false.
- Update the search API documentation.

## Todo 3:   replay (limited)

We have to do a replay for the old data, luckely we only have to do this for the type "kampjes"

## Todo 4:   reindex

Reindex after the replay so search reflects the new projections.
