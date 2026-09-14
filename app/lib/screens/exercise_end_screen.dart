import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:lottie/lottie.dart';

import '../core/icons.dart';
import '../core/l10n.dart';
import '../core/sfx.dart';
import '../core/theme.dart';
import '../main.dart';

/// The result screen after a section attempt is saved, built 1:1 to the
/// exercise-end-good/normal/bad frames; a quiz section (FR-13.4) shows
/// the exam-end-* copy on the same layout.
///
/// Tiers per the spec: good ≥ 80, normal ≥ 50, bad below. The percent is
/// the server's — this screen displays what POST /sections/{id}/attempts
/// returned and computes nothing (NFR-5). By the time it appears the
/// attempt is already written, so both exits just pop; nothing here can
/// be discarded any more (unlike mid-exercise, FR-13.5).
class ExerciseEndScreen extends StatefulWidget {
  const ExerciseEndScreen({super.key, required this.percent, this.isQuiz = false});

  final int percent;
  final bool isQuiz;

  @override
  State<ExerciseEndScreen> createState() => _ExerciseEndScreenState();
}

/// Stateful only to own the result sound (FR-15.20). The clip starts
/// when this screen appears and stops when it goes — so leaving it,
/// by either exit, cuts the sound instead of letting three or four
/// seconds of it follow the student onto the next screen.
///
/// initState, not build: build runs again on every rebuild and would
/// restart the clip each time.
class _ExerciseEndScreenState extends State<ExerciseEndScreen> {
  /// The clip this screen started, so leaving cuts its own sound and
  /// not one a later screen owns (Sfx.stopOwn).
  int _sfx = 0;

  @override
  void initState() {
    super.initState();
    _sfx = Sfx.instance.completed(widget.percent);
  }

  @override
  void dispose() {
    Sfx.instance.stopOwn(_sfx);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final percent = widget.percent;
    final isQuiz = widget.isQuiz;
    final l = AppState.instance.l;
    final still = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
    final tier = percent >= 80
        ? _Tier.good
        : percent >= 50
            ? _Tier.normal
            : _Tier.bad;

    return Scaffold(
      // The frames sit on plain white, not the usual surface grey.
      backgroundColor: DanaColors.card,
      body: SafeArea(
        child: Column(
          children: [
            Align(
              alignment: Alignment.centerLeft,
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: GestureDetector(
                  onTap: () => Navigator.of(context).pop(),
                  child: Container(
                    width: 44,
                    height: 44,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: DanaColors.surface,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: const DanaIcon(DanaIcons.times, size: 18),
                  ),
                ),
              ),
            ),
            Expanded(
              // Outside the scroll view on purpose: the illustration is
              // sized from the room this screen actually has, and inside
              // a scroll view the available height is unbounded.
              child: LayoutBuilder(
                builder: (context, box) => SingleChildScrollView(
                  child: Column(
                    children: [
                      const SizedBox(height: 16),
                      _Illustration(tier: tier, room: box.maxHeight, still: still),
                      const SizedBox(height: 36),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 24),
                        child: Column(
                          children: [
                            Text(
                              _s(l, tier.titleKey(isQuiz),
                                  tier.titleFallback(isQuiz)),
                              textAlign: TextAlign.center,
                              style: const TextStyle(
                                fontSize: 24,
                                fontWeight: FontWeight.w700,
                                color: DanaColors.brand,
                                letterSpacing: -0.48,
                              ),
                            ),
                            const SizedBox(height: 10),
                            Padding(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 16),
                              child: Text(
                                _s(l, tier.bodyKey(isQuiz),
                                    tier.bodyFallback(isQuiz)),
                                textAlign: TextAlign.center,
                                style: const TextStyle(
                                  fontSize: 16,
                                  height: 1.45,
                                  color: DanaColors.textMuted,
                                  letterSpacing: -0.32,
                                ),
                              ),
                            ),
                            const SizedBox(height: 36),
                            // FR-15.22: the ring sweeps up to the score
                            // and the number climbs with it, so the
                            // student watches the result arrive instead
                            // of finding it already sitting there.
                            //
                            // ONE tween drives both, so the arc and the
                            // digits can never disagree, and it ends on
                            // exactly the server's number — this screen
                            // computes no score of its own (NFR-5). The
                            // COLOUR does not animate: the tier is fixed
                            // by the final percent, so a 92% ring is
                            // green from the first frame rather than
                            // travelling red -> amber -> green and
                            // implying a verdict that was never in doubt.
                            TweenAnimationBuilder<double>(
                              tween: Tween(begin: 0, end: percent.toDouble()),
                              // Longer for a higher score: a full ring
                              // that takes the same time as a quarter one
                              // has to race, and the climb is the reward.
                              duration: still
                                  ? Duration.zero
                                  : Duration(milliseconds: 450 + percent * 6),
                              curve: Curves.easeOutCubic,
                              builder: (context, shown, _) => SizedBox(
                                width: 92,
                                height: 92,
                                child: CustomPaint(
                                  painter: _RingPainter(
                                    fraction: (shown / 100).clamp(0.0, 1.0),
                                    color: tier.color,
                                    track: tier.tint,
                                  ),
                                  child: Center(
                                    child: Text(
                                      '${shown.round()}%',
                                      style: TextStyle(
                                        fontSize: 21,
                                        fontWeight: FontWeight.w700,
                                        letterSpacing: -0.42,
                                        color: tier.color,
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                            ),
                            const SizedBox(height: 16),
                            Text(
                              _s(l, 'success_rate', 'Success rate')
                                  .toUpperCase(),
                              style: const TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w600,
                                // Measured off the frame — lighter than
                                // textMuted.
                                color: Color(0xFFB6B1B3),
                                letterSpacing: -0.22,
                              ),
                            ),
                            const SizedBox(height: 24),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
              child: SizedBox(
                height: 51,
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () => Navigator.of(context).pop(),
                  child: Text(_s(l, 'back_to_home', 'Back to Home')),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The tier's artwork (FR-15.23), sized to the room this screen has.
///
/// Two shapes, one rule. The trophy and the woman at her laptop are
/// square once their empty margins are discounted (ink aspect 1.06 and
/// 1.07), so they are sized off the available HEIGHT — a tall phone gets
/// a bigger picture, a short one still fits — bounded so that even the
/// smallest phone shows more artwork than the still it replaced.
///
/// The 80+ rocket is the exception: a landscape flying pose, ink aspect
/// 1.86, with the jetpack flame at one end and an outstretched fist at
/// the other. There is no square crop of it that does not amputate both,
/// so it runs the full WIDTH of the screen instead, escaping the 24pt
/// gutter the text keeps. Wider than the still it replaces, and
/// necessarily shorter.
///
/// The floor below therefore does NOT apply to the rocket, and cannot:
/// it is bound by the screen, not by the room. Its area equals the
/// 249x240 still at a screen width of 347dp, so on anything narrower —
/// a 320dp hdpi phone, the bottom of this market — it is wider than the
/// still but smaller in area. The alternative is scaling past the screen
/// edge and clipping, which costs the flame and the fist, the two things
/// that make the pose read. Left deliberately: a wide picture on a
/// narrow phone is short, and no arrangement of this artwork fixes that.
///
/// In both cases what is fitted is the [_Tier.ink] rectangle rather than
/// the composition's canvas, and the rest is scaled off the edge and
/// clipped. That is the difference between artwork that fills the slot
/// and artwork floating in the middle of its own empty margin.
class _Illustration extends StatelessWidget {
  const _Illustration({
    required this.tier,
    required this.room,
    required this.still,
  });

  final _Tier tier;

  /// Height available to the whole scrolling block.
  final double room;

  final bool still;

  @override
  Widget build(BuildContext context) {
    final canvas = tier.canvas;
    final ink = tier.ink;
    final inkSize = Size(ink.width * canvas.width, ink.height * canvas.height);
    final wide = inkSize.aspectRatio > 1.3;

    // A fraction of the room rather than "the room minus everything
    // below": the copy under this is translated, and Turkmen and Russian
    // wrap to more lines than the English such a number would have been
    // measured against. The scroll view absorbs whatever it gets wrong.
    //
    // The floor is 270, not the 240 the still was drawn at, and the
    // difference is the point of it. A still fills its box; an animation
    // fills 96% of one side and ~91% of the other, so a 240 box would
    // put 230x217 of artwork on screen against the still's 249x240 —
    // SMALLER than what it replaced, which is the one outcome the client
    // ruled out. 270 puts 259x245 on screen, and the brief was «make
    // bigger than current PNG». The ceiling is 320 so a tall phone does
    // not turn the artwork into a poster.
    final side = (room * 0.48).clamp(270.0, 320.0);
    final boxWidth = wide ? MediaQuery.sizeOf(context).width : side;
    final boxHeight = wide ? boxWidth / inkSize.aspectRatio : side;

    // Fit the INK to the box. The 4% held back is margin against a frame
    // the measurement sampled past — a stray confetti flake reaching
    // further than any frame that was rasterised would be clipped, and
    // the cost of the insurance is invisible.
    final scale = 0.96 *
        math.min(boxWidth / inkSize.width, boxHeight / inkSize.height);

    // Move the ink's centre onto the box's centre. OverflowBox centres
    // the canvas, so this is the distance between the two centres.
    final shift = Offset(
      (canvas.width / 2 - (ink.left * canvas.width + inkSize.width / 2)) *
          scale,
      (canvas.height / 2 - (ink.top * canvas.height + inkSize.height / 2)) *
          scale,
    );

    return SizedBox(
      width: double.infinity,
      height: boxHeight,
      child: Center(
        child: SizedBox(
          width: boxWidth,
          height: boxHeight,
          child: ClipRect(
            child: OverflowBox(
              maxWidth: double.infinity,
              maxHeight: double.infinity,
              child: Transform.translate(
                offset: shift,
                child: SizedBox(
                  width: canvas.width * scale,
                  height: canvas.height * scale,
                  child: Lottie.asset(
                    tier.animation,
                    fit: BoxFit.fill,
                    // MediaQuery.disableAnimations: the artwork still
                    // shows, it just holds its first frame — the same
                    // bargain as the score ring and the six glyphs of
                    // FR-15.21.
                    animate: !still,
                    // Not the package's default of `true` for every
                    // tier — see [_Tier.loops]. The trophy is a build-in
                    // and looping it snaps the figure back down the
                    // podium every six seconds.
                    repeat: tier.loops,
                    // Parse the composition on a background isolate.
                    // The package defaults this to false, which runs
                    // LottieCompositionParser synchronously on the UI
                    // isolate — and the first build of this widget
                    // happens DURING the pushReplacement transition,
                    // alongside the result clip starting and the
                    // FR-15.22 ring tween running. Tokenising 248 KB of
                    // JSON there is exactly the stutter this screen can
                    // least afford, and it would land on the cheapest
                    // phones hardest. Costs a frame or two before the
                    // artwork appears; the screen is not blank behind
                    // it, the copy and the ring are already there.
                    backgroundLoading: true,
                    // A composition that fails to parse falls back to
                    // the still this screen shipped with, which is
                    // strictly better than the empty box the old
                    // builder left. It undoes [shift] first, because a
                    // still has none of the empty margin the crop above
                    // exists to remove.
                    errorBuilder: (_, _, _) => Transform.translate(
                      offset: -shift,
                      child: Center(
                        child: SizedBox(
                          width: boxWidth,
                          height: boxHeight,
                          child: Image.asset(tier.asset, fit: BoxFit.contain),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Same fallback bridge as exercise_screen.dart: use the l10n key when it
/// exists, the design file's English copy until then. Every key below is
/// defined trilingually in core/l10n.dart, so the fallbacks are dead in
/// practice.
String _s(L l, String key, String fallback) {
  final resolved = l.t(key);
  return resolved == key ? fallback : resolved;
}

enum _Tier { good, normal, bad }

extension on _Tier {
  Color get color => switch (this) {
        _Tier.good => DanaColors.ok,
        _Tier.normal => DanaColors.accent,
        _Tier.bad => DanaColors.danger,
      };

  /// Ring track: the tier colour at ~10% over white, sampled from the
  /// frames. SPEC: no core token names exist for these tints.
  Color get tint => switch (this) {
        _Tier.good => const Color(0xFFE8FCF2),
        _Tier.normal => const Color(0xFFFCF5E8),
        _Tier.bad => const Color(0xFFFCE8E8),
      };

  /// The still this tier shipped with, now only a fallback for an
  /// animation that fails to parse.
  String get asset => switch (this) {
        _Tier.good => 'assets/illustrations/end-good.png',
        _Tier.normal => 'assets/illustrations/end-normal.png',
        _Tier.bad => 'assets/illustrations/end-bad.png',
      };

  /// FR-15.23. Client-chosen, Lottie Simple License (commercial use
  /// granted, attribution encouraged but not required). Each was run
  /// through a metadata strip and a 3dp precision pass — verified
  /// pixel-identical to the originals across 40 sampled frames — which
  /// is why these are the sizes they are.
  String get animation => switch (this) {
        _Tier.good => 'assets/illustrations/end-good.json',
        _Tier.normal => 'assets/illustrations/end-normal.json',
        _Tier.bad => 'assets/illustrations/end-bad.json',
      };

  /// Whether this composition's last frame returns to its first, and so
  /// may be looped.
  ///
  /// MEASURED, not assumed: each composition's first and last frames were
  /// rasterised and diffed. The woman is a true cycle (0.00% of pixels
  /// differ). The rocket's seam is 0.68%, twenty times smaller than the
  /// difference between its first and middle frames — the speed-lines
  /// resetting, invisible in motion. The TROPHY is neither: 9.23% of
  /// pixels differ, two thirds as much as its own mid-point, because it
  /// is a build-in — the figure climbs the podium over about three
  /// seconds and then holds. Looping it teleports the figure 153 units
  /// back down the canvas and snaps three layers through 43-61 degrees,
  /// every six seconds, for as long as the screen is open. So it plays
  /// once and holds the pose it was drawn to end on.
  bool get loops => switch (this) {
        _Tier.good => true,
        _Tier.normal => false,
        _Tier.bad => true,
      };

  /// The composition's own canvas, in its own units.
  Size get canvas => switch (this) {
        _Tier.good => const Size(1010, 550),
        _Tier.normal => const Size(1200, 1200),
        _Tier.bad => const Size(512, 512),
      };

  /// The part of that canvas which actually contains ink, as a fraction
  /// of it.
  ///
  /// Lottie compositions are routinely authored with generous empty
  /// margins, and these three are no exception — the trophy spends 43%
  /// of its canvas on nothing, the woman 25%, the rocket 15%. Fitting
  /// the whole canvas into the slot would spend that emptiness on
  /// screen, which is most of why the stills looked bigger than the
  /// animations replacing them.
  ///
  /// MEASURED, not eyeballed: each composition was rasterised at 45
  /// points across its timeline and scanned for non-white pixels, and
  /// the union taken. `getBBox` was tried first and is wrong here — on
  /// the trophy, which is built from precomps, it returns the clip
  /// rectangle at every frame and reports a shape the artwork does not
  /// have.
  Rect get ink => switch (this) {
        _Tier.good => const Rect.fromLTRB(0.0356, 0.0436, 0.9653, 0.9618),
        _Tier.normal => const Rect.fromLTRB(0.1142, 0.1258, 0.8925, 0.8592),
        _Tier.bad => const Rect.fromLTRB(0.0039, 0.0820, 0.9004, 0.9160),
      };

  String titleKey(bool quiz) => switch (this) {
        _Tier.good => quiz ? 'exam_good_title' : 'end_good_title',
        _Tier.normal => quiz ? 'exam_normal_title' : 'end_normal_title',
        _Tier.bad => quiz ? 'exam_bad_title' : 'end_bad_title',
      };

  String titleFallback(bool quiz) => switch (this) {
        _Tier.good => quiz ? 'Excellent Result!' : 'Great Job!',
        _Tier.normal => quiz ? 'Good Result!' : 'Good Work!',
        _Tier.bad => quiz ? 'Keep Trying!' : 'Keep Practicing!',
      };

  String bodyKey(bool quiz) => switch (this) {
        _Tier.good => quiz ? 'exam_good_body' : 'end_good_body',
        _Tier.normal => quiz ? 'exam_normal_body' : 'end_normal_body',
        _Tier.bad => quiz ? 'exam_bad_body' : 'end_bad_body',
      };

  String bodyFallback(bool quiz) => switch (this) {
        _Tier.good => quiz
            ? 'Congratulations! You achieved an excellent score on the exam.'
            : 'Excellent work! You completed the exercise with a great result.',
        _Tier.normal => quiz
            ? "Well done! You passed, but there's still room to improve."
            : 'Nice effort! Keep practicing to improve your result.',
        _Tier.bad => quiz
            ? "Don't worry! Review your lessons and try the exam again."
            : "Don't give up! Review the lesson and try the exercise again.",
      };
}

/// The success-rate ring: a full tinted track with the percentage swept
/// on top from twelve o'clock, rounded caps, as drawn in the frames.
class _RingPainter extends CustomPainter {
  const _RingPainter({
    required this.fraction,
    required this.color,
    required this.track,
  });

  final double fraction;
  final Color color;
  final Color track;

  @override
  void paint(Canvas canvas, Size size) {
    const stroke = 10.0;
    final rect = Offset.zero & size;
    final inner = rect.deflate(stroke / 2);

    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round;

    canvas.drawArc(inner, 0, math.pi * 2, false, paint..color = track);

    if (fraction > 0) {
      canvas.drawArc(
        inner,
        -math.pi / 2,
        math.pi * 2 * fraction,
        false,
        paint..color = color,
      );
    }
  }

  @override
  bool shouldRepaint(_RingPainter old) =>
      old.fraction != fraction || old.color != color || old.track != track;
}
