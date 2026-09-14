// Shrinks a Lottie JSON without changing a pixel of it (FR-15.23).
//
//   node tool/optimize_lottie.mjs <in.json> <out.json>
//
// Used on the three result-screen animations in assets/illustrations/.
// Run it on any replacement the client picks, so a swapped tier does not
// quietly ship an unoptimised file.
//
// Two passes, both chosen by measurement rather than taste:
//
//  - Authoring metadata (`nm`, `mn`, `cl`, `ln`) and property indices
//    (`ix`) are dropped. `ix` is only ever read to resolve an expression,
//    and none of these files contains one — CHECK THIS before trusting
//    the pass on a new file; the script refuses if it finds an
//    expression.
//  - Numbers are rounded to 3dp, 4dp below 10 (easing handles, opacity,
//    scale fractions). 2dp is tempting and wrong: pixel-diffed against
//    the original it moved up to 0.28% of pixels on shape edges, while
//    3dp diffs to exactly zero.
//
// Typical saving is 10-20% raw. The number that matters is the gzipped
// size, since assets ship inside the compressed APK.

import fs from 'fs';
import zlib from 'zlib';

const DROP = new Set(['nm', 'mn', 'cl', 'ln', 'ix']);

const round = (v) => {
  if (!Number.isFinite(v)) return v;
  const r = +v.toFixed(Math.abs(v) < 10 ? 4 : 3);
  return Object.is(r, -0) ? 0 : r;
};

/// Expressions are the one thing that makes `ix` load-bearing.
const hasExpression = (o) => {
  if (Array.isArray(o)) return o.some(hasExpression);
  if (!o || typeof o !== 'object') return false;
  if (typeof o.x === 'string' && o.x.length) return true;
  return Object.values(o).some(hasExpression);
};

/// Layer effects are the one thing that makes `nm` load-bearing.
///
/// lottie's Dart renderer resolves a drop shadow's five parameters by
/// matching each inner effect's `nm` against the literal strings
/// 'Shadow Color', 'Opacity', 'Direction', 'Distance' and 'Softness'
/// (drop_shadow_effect_parser.dart). Strip `nm` and every field stays
/// null, the parser returns null, and the shadow disappears — with no
/// error, on a file that passed every other check. None of the three
/// compositions shipped today contains an `ef` at all, so this is a
/// guard for the next one the client picks, not a live bug.
const hasEffects = (o) => {
  if (Array.isArray(o)) return o.some(hasEffects);
  if (!o || typeof o !== 'object') return false;
  if (Array.isArray(o.ef) && o.ef.length) return true;
  return Object.values(o).some(hasEffects);
};

const strip = (o) => {
  if (typeof o === 'number') return round(o);
  if (Array.isArray(o)) return o.map(strip);
  if (o && typeof o === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(o)) {
      if (DROP.has(k)) continue;
      out[k] = strip(v);
    }
    return out;
  }
  return o;
};

const [input, output] = process.argv.slice(2);
if (!input || !output) {
  console.error('usage: node tool/optimize_lottie.mjs <in.json> <out.json>');
  process.exit(2);
}

const before = fs.readFileSync(input, 'utf8');
const parsed = JSON.parse(before);

if (hasExpression(parsed)) {
  console.error(`${input} contains an expression — dropping \`ix\` would break it. Refusing.`);
  process.exit(1);
}

if (hasEffects(parsed)) {
  console.error(`${input} carries layer effects — dropping \`nm\` would silently delete them. Refusing.`);
  process.exit(1);
}

const after = JSON.stringify(strip(parsed));
fs.writeFileSync(output, after);

const kb = (n) => (n / 1024).toFixed(1) + ' KB';
const gz = (s) => zlib.gzipSync(s, { level: 9 }).length;
console.log(
  `${input} -> ${output}\n` +
  `  raw ${kb(before.length)} -> ${kb(after.length)}  ` +
  `(${Math.round((1 - after.length / before.length) * 100)}% smaller)\n` +
  `  gz  ${kb(gz(before))} -> ${kb(gz(after))}`,
);
