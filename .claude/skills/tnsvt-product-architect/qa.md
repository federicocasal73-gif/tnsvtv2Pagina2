# TNSVT QA Protocol

## Smoke test

After meaningful changes:
- application boots
- homepage loads
- authentication routes load
- authenticated shell loads
- primary navigation routes load
- no fatal PHP/Twig errors
- no obvious JS console failures

## Module test

For each module:
- authorized user
- unauthorized user
- valid data
- empty data
- invalid data
- failure response
- mobile layout

## Visual test

Compare implementation against:
- Stitch screens
- TNSVT tokens
- component rules
- spacing
- typography
- responsive breakpoints

Visual similarity is not enough; verify actual interactions.

## Regression

After shared component changes, test every known consumer.

## Definition of done

A ticket is not done when the code exists.
It is done when behavior, integration, UI and QA agree.
