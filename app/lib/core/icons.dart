import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

import 'theme.dart';

/// Icons exported from the Figma file, so the glyphs are the designer's
/// own rather than Material approximations.
///
/// The exported SVGs carry a hardcoded fill (the muted grey they were
/// drawn in), so every use tints them — otherwise an active tab would
/// stay grey.
class DanaIcons {
  // Tab bar. Each destination has two drawings in Figma: an outline for
  // the resting state and a filled one for the active tab — they are
  // different geometry, not the same shape recoloured.
  static const home = 'assets/icons/nav_home.svg';
  static const grammar = 'assets/icons/nav_grammar.svg';
  static const ranking = 'assets/icons/nav_ranking.svg';
  static const dictionary = 'assets/icons/nav_dictionary.svg';
  static const profile = 'assets/icons/nav_profile.svg';

  static const homeActive = 'assets/icons/nav_home_active.svg';
  static const grammarActive = 'assets/icons/nav_grammar_active.svg';
  static const rankingActive = 'assets/icons/nav_ranking_active.svg';
  static const dictionaryActive = 'assets/icons/nav_dictionary_active.svg';
  static const profileActive = 'assets/icons/nav_profile_active.svg';

  static const bell = 'assets/icons/bell.svg';
  static const search = 'assets/icons/search.svg';
  static const chevronRight = 'assets/icons/chevron_right.svg';
  static const arrowLeft = 'assets/icons/arrow_left.svg';

  /// The filled bookmark is drawn in amber and is meant to keep that
  /// colour — it is the "saved" state, not a tinted outline.
  static const bookmarkFilled = 'assets/icons/bookmark_filled.svg';
  static const bookmarkOutline = 'assets/icons/bookmark_outline.svg';

  static const phone = 'assets/icons/phone.svg';
  static const lock = 'assets/icons/lock.svg';
  static const eye = 'assets/icons/eye.svg';

  static const globe = 'assets/icons/globe.svg';
  static const envelope = 'assets/icons/envelope.svg';
  static const messageText = 'assets/icons/message_text.svg';

  /// The four Practice Module glyphs, exported from Figma at their final
  /// colours (2026-08-14): green book, blue speaker, amber bookmark-book,
  /// brand question mark. Each is drawn in the same hue its tile is
  /// tinted with, so all four render through [DanaIcon.original] — tinting
  /// them would only repaint them the colour they already are.
  static const moduleGrammar = 'assets/icons/book_open_text.svg';
  static const moduleListening = 'assets/icons/volume_high.svg';
  static const moduleVocabulary = 'assets/icons/book_bookmark.svg';
  static const moduleQuiz = 'assets/icons/question_circle.svg';

  /// The same bookmark-book drawn in white, for the unit card's solid
  /// amber vocabulary row (the tile is filled, so the glyph reverses).
  static const bookBookmarkWhite = 'assets/icons/book_bookmark_white.svg';

  /// Student Detail's two stat tiles (design `student-detail`): a blue
  /// trophy for the ranking, an amber clock for time spent.
  static const trophyStar = 'assets/icons/trophy_star.svg';
  static const clockAmber = 'assets/icons/clock_amber.svg';
  // The hand-drawn checklist that stood in for the Unit Quiz glyph was
  // replaced on 2026-08-14 by the designer's own question mark
  // ([moduleQuiz]); nav_grammar/clock/fire likewise lost their last
  // callers when the module tiles took the exported set.

  static const infoCircle = 'assets/icons/info_circle.svg';
  static const logOut = 'assets/icons/log_out.svg';

  /// The Delete Account row's bin. Rendered in the danger colour like the
  /// logout glyph beside it (Figma `profile-language`).
  static const trash = 'assets/icons/trash.svg';

  /// Drawn green and amber respectively — the colour is the point, so
  /// these two are rendered with [DanaIcon.original].
  static const clock = 'assets/icons/clock.svg';
  static const fire = 'assets/icons/fire.svg';

  static const book = 'assets/icons/book.svg';
  static const bookOpen = 'assets/icons/book_open.svg';

  /// Exercise-row state glyphs. The padlock here is a different drawing
  /// from the login field's [lock] — Figma uses two, and they are not a
  /// uniform scale of each other.
  static const checkCircle = 'assets/icons/check_circle.svg';
  static const playCircle = 'assets/icons/play_circle.svg';
  static const lockRow = 'assets/icons/lock_row.svg';

  static const times = 'assets/icons/times.svg';

  /// The Wrong sheet's badge. Exported from a 36px frame it fills to
  /// 30×30, so it needs `frame: 36`.
  static const timesCircle = 'assets/icons/times_circle.svg';

  /// Exported from a 20px frame that it fills edge to edge — pass
  /// `frame: 20` so it is not shrunk by the usual 24px assumption.
  static const volume2 = 'assets/icons/volume_2.svg';

  // Teacher home (154:298).
  static const gear = 'assets/icons/gear.svg';

  /// The course card's students count. Drawn in a 16px frame.
  static const users = 'assets/icons/users.svg';
}

/// Draws a Figma icon at a given box size and colour.
///
/// The exported vectors are the icon's *inner* shape, and Figma nests
/// each one — at its own natural size — inside a 24px frame. The padding
/// around it is therefore part of the design and differs per glyph: the
/// chevron is 5.5×9.5 in that frame while the home roof is 19.5×19.5.
///
/// So the SVG is drawn at its intrinsic size and scaled only by how far
/// [size] departs from Figma's 24px frame. Sizing it to the box instead
/// (BoxFit.contain) stretches narrow glyphs to the full height — which
/// turned the chevron into a caret more than twice its intended width.
class DanaIcon extends StatelessWidget {
  const DanaIcon(
    this.asset, {
    super.key,
    this.size = 24,
    this.color = DanaColors.textMuted,
    this.frame = 24,
  });

  /// Renders the icon in the colours it was drawn in. Use for glyphs
  /// whose fill carries meaning, like the amber saved-bookmark.
  const DanaIcon.original(this.asset, {super.key, this.size = 24, this.frame = 24})
      : color = null;

  final String asset;
  final double size;
  final Color? color;

  /// Side of the Figma frame this glyph was exported from. Nearly every
  /// icon comes out of a 24px frame; a few — like the modal's speaker —
  /// were drawn in a 20px one and would render undersized at the default.
  final double frame;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: Center(
        child: Transform.scale(
          scale: size / frame,
          child: SvgPicture.asset(
            asset,
            colorFilter:
                color == null ? null : ColorFilter.mode(color!, BlendMode.srcIn),
          ),
        ),
      ),
    );
  }
}
