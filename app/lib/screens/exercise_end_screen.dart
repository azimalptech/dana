import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/icons.dart';
import '../core/l10n.dart';
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
class ExerciseEndScreen extends StatelessWidget {
  const ExerciseEndScreen({super.key, required this.percent, this.isQuiz = false});

  final int percent;
  final bool isQuiz;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
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
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 24),
                child: Column(
                  children: [
                    const SizedBox(height: 16),
                    // SPEC: agent B crops these from the end-screen frames
                    // into app/assets/illustrations/ and registers them in
                    // pubspec. The builder keeps the screen alive until
                    // the crop lands.
                    Image.asset(
                      tier.asset,
                      height: 240,
                      errorBuilder: (_, _, _) => const SizedBox(height: 240),
                    ),
                    const SizedBox(height: 36),
                    Text(
                      _s(l, tier.titleKey(isQuiz), tier.titleFallback(isQuiz)),
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
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: Text(
                        _s(l, tier.bodyKey(isQuiz), tier.bodyFallback(isQuiz)),
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
                    SizedBox(
                      width: 92,
                      height: 92,
                      child: CustomPaint(
                        painter: _RingPainter(
                          fraction: (percent / 100).clamp(0.0, 1.0),
                          color: tier.color,
                          track: tier.tint,
                        ),
                        child: Center(
                          child: Text(
                            '$percent%',
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
                    const SizedBox(height: 16),
                    Text(
                      _s(l, 'success_rate', 'Success rate').toUpperCase(),
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        // Measured off the frame — lighter than textMuted.
                        color: Color(0xFFB6B1B3),
                        letterSpacing: -0.22,
                      ),
                    ),
                    const SizedBox(height: 24),
                  ],
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

  String get asset => switch (this) {
        _Tier.good => 'assets/illustrations/end-good.png',
        _Tier.normal => 'assets/illustrations/end-normal.png',
        _Tier.bad => 'assets/illustrations/end-bad.png',
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
