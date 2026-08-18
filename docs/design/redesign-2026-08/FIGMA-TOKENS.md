# Figma design tokens (exact) — via MCP get_variable_defs, 2026-08-14

Ground truth for the 1:1 audit. Reconcile `app/lib/core/theme.dart` DanaColors
and text styles against these.

## Colors
| Figma variable | Hex | Maps to (app) |
|---|---|---|
| brand/primary | `#7C0C3E` | DanaColors.brand ✓ |
| brand/brand-bg | `#F9ECEF` | brand tint (_brandTint) ✓ |
| background/primary | `#F5F2F3` | DanaColors.surface (app bg) |
| surface/default | `#FFFFFF` | DanaColors.card |
| surface/alpha | `#FFFFFF1A` (white 10%) | header tile bg |
| border/default | `#F5F2F3` | DanaColors.border |
| text/primary | `#1F1A1C` | DanaColors.ink |
| text/secondary | `#7D7477` | DanaColors.textMuted |
| text/on-brand-primary | `#FFFFFF` | on-brand text |
| text/on-brand-secondary | `#FFFFFFCC` (white 80%) | on-brand subtitle |
| status/success | `#10B981` | ok ✓ |
| status/success-bg | `#E8FCF2` | ok tint ✓ |
| status/warning | `#F59E0B` | amber % pill text — **check vs current** |
| status/warning-bg | `#FCF5E8` | amber pill bg |
| status/info | `#0B94F5` | listening blue — **CURRENT #3B82F6 IS WRONG, fix to #0B94F5** |
| status/info-bg | `#E8F4FC` | listening tint |

Note: progress-amber `#FFC301` (sampled) is a distinct gold used for the logo /
rank-1 / progress bar; `status/warning #F59E0B` is the amber % pill. Keep both.

## Typography — Inter, letterSpacing −2% (≈ −0.02em)
| Style | Size / weight |
|---|---|
| Heading/H1 | 24 / 700 |
| Heading/H3 | 20 / 600 |
| Heading/H4 | 18 / 600 |
| Body/Large | 16 / 500 · Large Semibold 16 / 600 |
| Body/Default | 14 / 500 · Default Semibold 14 / 600 |
| Body/Small | 12 / 400 |
| Label/Small | 12 / 500 |
| Label/Caption | 11 / 600 |

lineHeight is 100 (i.e. 1.0 / tight) on headings & labels; letterSpacing −2%
(at 14px ≈ −0.28, at 24px ≈ −0.48 — matches the hardcoded values in the app).

## First real 1:1 gaps found (to fix in the audit pass)
1. **Listening blue**: app uses `#3B82F6`, Figma token is `#0B94F5`.
2. **Amber pill** vs progress-amber: confirm the % pill uses `#F59E0B` (warning) not the gold `#FFC301`.
Both are token-level — one-line theme fixes, applied after the content-v2 workflows land (they may touch theme.dart).
