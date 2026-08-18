import 'package:flutter/material.dart';

/// Design tokens — exact values from the Figma variables (MCP
/// get_variable_defs, 2026-08-14). See docs/design/redesign-2026-08/
/// FIGMA-TOKENS.md; keep the two in step.
class DanaColors {
  static const brand = Color(0xFF7C0C3E); // brand/primary
  static const surface = Color(0xFFF5F2F3); // background/primary (was FAF8F9)
  static const card = Colors.white; // surface/default
  static const border = Color(0xFFF5F2F3); // border/default (was F0E9EC)
  static const textMuted = Color(0xFF7D7477); // text/secondary
  static const textDark = Color(0xFF2B1B22);
  /// List and body text in the Figma screens.
  static const ink = Color(0xFF1F1A1C);
  /// Tab bar hairline — a cooler grey than the pink-tinted card border.
  static const navBorder = Color(0xFFE5E7EB);
  static const onBrandMuted = Color(0xCCFFFFFF);
  static const success = Color(0xFF1E7F4F);
  static const error = Color(0xFFC0392B);
  /// Exercise progress fill and the vocabulary tile on Home.
  static const accent = Color(0xFFF59E0B);
  /// A word chip already placed into a gap — still visible, not gone.
  static const chipUsed = Color(0xFFB1B1B1);

  /// Leaderboard podium. Gold is [accent]; these are the other two.
  static const silver = Color(0xFFBDBDBD);
  static const bronze = Color(0xFFD97706);

  /// The Log Out row and the Wrong verdict use a lighter red than
  /// [error], which is reserved for validation and failure states.
  static const danger = Color(0xFFEF4444);

  /// The Correct verdict and the completed-exercise tick. Brighter than
  /// [success], which is the darker green used for body text.
  static const ok = Color(0xFF10B981);

  /// Exercise progress-bar fill (redesign 2026-08). Replaces [accent]
  /// there only — the vocabulary tile and podium gold keep [accent].
  static const progressAmber = Color(0xFFFFC301);

  /// The Listening module glyph — Figma `status/info` (was a sampled
  /// #3B82F6; the exact token is #0B94F5).
  static const listeningBlue = Color(0xFF0B94F5);

  /// Figma `status/info-bg` — the Listening tile's pale fill.
  static const listeningTint = Color(0xFFE8F4FC);
}

class DanaRadius {
  static const field = 12.0;
  static const card = 16.0;
  static const banner = 24.0;
  static const screen = 40.0;
}

/// Figma tracking is consistently about -2% of the font size.
double _tracking(double size) => size * -0.02;

ThemeData buildDanaTheme() {
  const family = 'Inter';

  TextStyle t(double size, FontWeight weight, {Color? color, double? height}) => TextStyle(
        fontSize: size,
        fontWeight: weight,
        letterSpacing: _tracking(size),
        color: color ?? DanaColors.textDark,
        height: height,
      );

  return ThemeData(
    useMaterial3: true,
    fontFamily: family,
    scaffoldBackgroundColor: DanaColors.surface,
    colorScheme: ColorScheme.fromSeed(
      seedColor: DanaColors.brand,
      primary: DanaColors.brand,
      surface: DanaColors.surface,
    ),
    textTheme: TextTheme(
      headlineLarge: t(28, FontWeight.w700, height: 34 / 28),
      titleLarge: t(20, FontWeight.w600),
      titleMedium: t(17, FontWeight.w600, height: 22 / 17),
      bodyLarge: t(15, FontWeight.w400),
      bodyMedium: t(14, FontWeight.w400),
      labelLarge: t(16, FontWeight.w600),
      labelSmall: t(11, FontWeight.w600, color: DanaColors.textMuted),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: DanaColors.card,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(DanaRadius.field),
        borderSide: const BorderSide(color: DanaColors.border, width: 1.5),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(DanaRadius.field),
        borderSide: const BorderSide(color: DanaColors.border, width: 1.5),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(DanaRadius.field),
        borderSide: const BorderSide(color: DanaColors.brand, width: 1.5),
      ),
      hintStyle: t(15, FontWeight.w400, color: DanaColors.textMuted),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: DanaColors.brand,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(52),
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(DanaRadius.field),
        ),
        textStyle: t(16, FontWeight.w600, color: Colors.white),
      ),
    ),
  );
}

/// Uppercase field label, as used above every input in the design.
class FieldLabel extends StatelessWidget {
  const FieldLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(
          text.toUpperCase(),
          style: Theme.of(context).textTheme.labelSmall,
        ),
      );
}
