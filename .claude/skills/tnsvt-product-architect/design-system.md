# TNSVT Design System Rules

## Visual direction

Use the Stitch project and existing TNSVT design documentation as references.

Core character:
- premium
- dark
- disciplined
- intelligent
- technical
- restrained
- cinematic

Avoid:
- excessive neon
- excessive glass
- random gradients
- inconsistent corner radii
- oversized decorative elements
- dashboard clutter

## Semantic palette

Void: #050308
Surface: #161121
Gold: #D4AF37
Gold Bright: #F2CA50
Violet: #8A3CFF
Primary Text: #E9DEF6
Muted Text: #D0C5AF

Semantic:
- positive = green
- danger = red
- warning = amber
- information = violet/blue

Do not hardcode colors repeatedly. Prefer tokens.

## Typography

Preserve the existing TNSVT/Stitch typography direction unless repository evidence requires otherwise.

Hierarchy:
- page title
- section title
- card title
- body
- metadata

Typography must establish hierarchy before effects.

## Layout

Use a consistent content container.
Use predictable spacing.
Align cards and controls to a grid.
Do not solve layout problems with arbitrary margins.

## Components

Every repeated visual pattern should have one canonical implementation.

Prefer:
- semantic HTML
- accessible controls
- keyboard support
- visible focus
- consistent hover/active/disabled states
