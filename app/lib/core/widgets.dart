import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'icons.dart';
import 'theme.dart';

/// Widgets measured from the Figma file. Values here are the design, not
/// approximations — change them only alongside docs/06-DESIGN-SYSTEM.md.

/// Brand header: full-bleed, 24px bottom corners, centred 18px bold
/// title. Used by Grammar, Dictionary, Ranking and Profile.
class DanaHeader extends StatelessWidget implements PreferredSizeWidget {
  const DanaHeader({super.key, required this.title, this.trailing});

  final String title;
  final Widget? trailing;

  @override
  Size get preferredSize => const Size.fromHeight(72);

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(
        color: DanaColors.brand,
        borderRadius: BorderRadius.only(
          bottomLeft: Radius.circular(DanaRadius.banner),
          bottomRight: Radius.circular(DanaRadius.banner),
        ),
      ),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
          child: Stack(
            alignment: Alignment.center,
            children: [
              Text(
                title,
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                  letterSpacing: -0.36,
                ),
              ),
              if (trailing != null)
                Positioned(right: 0, child: trailing!),
            ],
          ),
        ),
      ),
    );
  }
}

/// White field, 1.5px border, 12 radius, 24px leading icon.
class DanaSearchBar extends StatelessWidget {
  const DanaSearchBar({
    super.key,
    required this.hint,
    required this.onChanged,
    this.controller,
  });

  final String hint;
  final ValueChanged<String> onChanged;
  final TextEditingController? controller;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: DanaColors.card,
        borderRadius: BorderRadius.circular(DanaRadius.field),
        border: Border.all(color: DanaColors.border, width: 1.5),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 2),
      child: Row(
        children: [
          const DanaIcon(DanaIcons.search),
          const SizedBox(width: 10),
          Expanded(
            child: TextField(
              controller: controller,
              onChanged: onChanged,
              style: const TextStyle(fontSize: 15, letterSpacing: -0.3),
              decoration: InputDecoration(
                hintText: hint,
                hintStyle: const TextStyle(
                  fontSize: 15,
                  letterSpacing: -0.3,
                  color: DanaColors.textMuted,
                ),
                border: InputBorder.none,
                enabledBorder: InputBorder.none,
                focusedBorder: InputBorder.none,
                filled: false,
                isDense: true,
                contentPadding: EdgeInsets.symmetric(vertical: 12),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Bordered container whose children are separated by hairlines, with
/// the last divider omitted — the Figma "topics-list" / "word-list".
class DanaListCard extends StatelessWidget {
  const DanaListCard({super.key, required this.children, this.radius = DanaRadius.field});

  final List<Widget> children;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final rows = <Widget>[];

    for (var i = 0; i < children.length; i++) {
      rows.add(children[i]);
      if (i != children.length - 1) {
        rows.add(const Divider(height: 1, thickness: 1, color: DanaColors.border));
      }
    }

    return Container(
      decoration: BoxDecoration(
        color: DanaColors.card,
        borderRadius: BorderRadius.circular(radius),
        border: Border.all(color: DanaColors.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(children: rows),
    );
  }
}

/// Two-up pill switch on a muted track — Figma "All Words / Bookmarked".
class DanaSegmented extends StatelessWidget {
  const DanaSegmented({
    super.key,
    required this.labels,
    required this.index,
    required this.onChanged,
  });

  final List<String> labels;
  final int index;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: DanaColors.border,
        borderRadius: BorderRadius.circular(DanaRadius.field),
      ),
      child: Row(
        children: [
          for (var i = 0; i < labels.length; i++)
            Expanded(
              child: GestureDetector(
                onTap: () => onChanged(i),
                behavior: HitTestBehavior.opaque,
                child: Container(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  decoration: BoxDecoration(
                    color: i == index ? DanaColors.card : Colors.transparent,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  alignment: Alignment.center,
                  child: Text(
                    labels[i],
                    style: TextStyle(
                      fontSize: 14,
                      letterSpacing: -0.28,
                      fontWeight: i == index ? FontWeight.w600 : FontWeight.w400,
                      color: i == index ? DanaColors.brand : DanaColors.textMuted,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// A row inside DanaListCard: title over a muted caption, optional
/// trailing widget. 16px padding all round.
class DanaListRow extends StatelessWidget {
  const DanaListRow({
    super.key,
    required this.title,
    this.caption,
    this.trailing,
    this.onTap,
    this.titleSize = 14,
  });

  final String title;
  final String? caption;
  final Widget? trailing;
  final VoidCallback? onTap;
  final double titleSize;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: TextStyle(
                      fontSize: titleSize,
                      fontWeight: FontWeight.w600,
                      color: DanaColors.ink,
                      letterSpacing: titleSize * -0.02,
                    ),
                  ),
                  if (caption != null) ...[
                    const SizedBox(height: 4),
                    Text(
                      caption!,
                      style: TextStyle(
                        fontSize: titleSize == 14 ? 11 : 12,
                        color: DanaColors.textMuted,
                        letterSpacing: -0.22,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            ?trailing,
          ],
        ),
      ),
    );
  }
}

/// A percentage chip: solid text on a 10% tint of the same colour
/// (SPEC tokens). Thresholds are the design's: green ≥ 80, amber ≥ 50,
/// red below — the same bands the end screens use for their verdicts.
class DanaPercentChip extends StatelessWidget {
  const DanaPercentChip({super.key, required this.percent, this.fontSize = 13});

  final int percent;
  final double fontSize;

  static Color colorFor(int percent) => percent >= 80
      ? DanaColors.ok
      : percent >= 50
          ? DanaColors.accent
          : DanaColors.danger;

  @override
  Widget build(BuildContext context) {
    final color = colorFor(percent);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(100),
      ),
      child: Text(
        '$percent%',
        style: TextStyle(
          fontSize: fontSize,
          fontWeight: FontWeight.w700,
          color: color,
          letterSpacing: fontSize * -0.02,
        ),
      ),
    );
  }
}

/// The light square ✕ used by every sheet and modal in the frames.
class DanaCloseButton extends StatelessWidget {
  const DanaCloseButton({super.key, this.onTap});

  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap ?? () => Navigator.of(context).maybePop(),
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(
          color: const Color(0xFFF7F7F7),
          borderRadius: BorderRadius.circular(8),
        ),
        child: const DanaIcon(DanaIcons.times, size: 20, color: DanaColors.ink),
      ),
    );
  }
}

/// The 36px translucent back tile that sits on brand headers.
class DanaBackButton extends StatelessWidget {
  const DanaBackButton({super.key});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => Navigator.of(context).maybePop(),
      behavior: HitTestBehavior.opaque,
      child: Container(
        width: 36,
        height: 36,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.1),
          borderRadius: BorderRadius.circular(8),
        ),
        child: const DanaIcon(DanaIcons.arrowLeft, size: 20, color: Colors.white),
      ),
    );
  }
}

/// The ⓘ card above the podium and inside the section-history modal.
class DanaInfoCard extends StatelessWidget {
  const DanaInfoCard({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: DanaColors.card,
        borderRadius: BorderRadius.circular(DanaRadius.field),
        border: Border.all(color: DanaColors.border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const DanaIcon(DanaIcons.infoCircle, size: 16, color: DanaColors.ink),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text,
              style: const TextStyle(
                fontSize: 12,
                color: DanaColors.ink,
                height: 1.4,
                letterSpacing: -0.24,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Rounded square tile holding a tinted section-type glyph — the module
/// rows on Home, the history modal and the Unit Vocabulary rows.
class DanaModuleTile extends StatelessWidget {
  const DanaModuleTile({super.key, required this.type, this.size = 40});

  /// A section type: grammar / vocabulary / listening / quiz.
  final String type;
  final double size;

  @override
  Widget build(BuildContext context) {
    // The designer's own glyphs (2026-08-14), each already drawn in the
    // hue its tile is tinted with — so they render untinted and the tuple's
    // colour is used only for the 10% background.
    final (icon, color) = switch (type) {
      'grammar' => (DanaIcons.moduleGrammar, DanaColors.ok),
      'listening' => (DanaIcons.moduleListening, DanaColors.listeningBlue),
      'vocabulary' => (DanaIcons.moduleVocabulary, DanaColors.accent),
      _ => (DanaIcons.moduleQuiz, DanaColors.brand), // unit quiz
    };

    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(10),
      ),
      child: DanaIcon.original(icon, size: size * 0.6),
    );
  }
}

/// 64px ring with the percentage centred — the Course Overview card on
/// Home and Profile. The redesign draws it in [DanaColors.ok] green
/// (level progress is a coverage number, not a grade), so green is the
/// default and callers can override.
class DanaProgressRing extends StatelessWidget {
  const DanaProgressRing({
    super.key,
    required this.percent,
    this.color = DanaColors.ok,
  });

  final int percent;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 64,
      height: 64,
      child: CustomPaint(
        painter: _RingPainter(percent / 100, color),
        child: Center(
          child: Text(
            '$percent%',
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: color,
              letterSpacing: -0.28,
            ),
          ),
        ),
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  const _RingPainter(this.progress, this.color);

  final double progress;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    const stroke = 5.0;
    final rect = Offset(stroke / 2, stroke / 2) &
        Size(size.width - stroke, size.height - stroke);

    final track = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..color = DanaColors.border;

    final fill = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round
      ..color = color;

    canvas.drawArc(rect, 0, math.pi * 2, false, track);
    canvas.drawArc(
        rect, -math.pi / 2, math.pi * 2 * progress.clamp(0, 1), false, fill);
  }

  @override
  bool shouldRepaint(_RingPainter old) =>
      old.progress != progress || old.color != color;
}

/// "12560" → "12,560" — the leaderboard's big scores (FR-13.9).
String danaFormatScore(int score) {
  final digits = score.abs().toString();
  final out = StringBuffer();

  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) out.write(',');
    out.write(digits[i]);
  }

  return '${score < 0 ? '-' : ''}$out';
}

/// Empty / error state that matches the muted list styling.
class DanaEmpty extends StatelessWidget {
  const DanaEmpty({super.key, required this.icon, required this.message});

  final IconData icon;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 40, color: DanaColors.textMuted),
            const SizedBox(height: 12),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: DanaColors.textMuted),
            ),
          ],
        ),
      ),
    );
  }
}
