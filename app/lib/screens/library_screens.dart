import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/icons.dart';
import '../core/study_tracker.dart';
import '../core/theme.dart';
import '../core/tts.dart';
import '../core/widgets.dart';
import '../main.dart';

/// Vocabulary and the Grammar guide.
///
/// Both are cumulative reference views: every published word and topic
/// of the level, whatever unit the student is on — everything is
/// unlocked (FR-13.3), and the repository layer already keeps drafts
/// out, so nothing here filters for access.

/* ---------------------------------------------------- unit vocabulary */

/// Figma `unit-vocabulary-screen`: one unit's words under a brand
/// header — back tile, centred "Vocabulary", the unit label on the
/// right. Opened from Home's vocabulary row and the unit detail.
class UnitVocabularyScreen extends StatefulWidget {
  const UnitVocabularyScreen({
    super.key,
    required this.unitId,
    required this.unitNumber,
    this.unitLabel,
  });

  final int unitId;
  final int unitNumber;

  /// The child-unit label ("1-A"). The teacher screens predate labels
  /// and pass only the number, so the header falls back to it.
  final String? unitLabel;

  @override
  State<UnitVocabularyScreen> createState() => _UnitVocabularyScreenState();
}

class _UnitVocabularyScreenState extends State<UnitVocabularyScreen>
    with StudyTimeAware {
  Future<Map<String, dynamic>>? _future;

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _load() {
    // Teachers read through their own scoped route (FR-13.10).
    final base = AppState.instance.isTeacher
        ? '/teacher/units/${widget.unitId}/vocabulary'
        : '/units/${widget.unitId}/vocabulary';

    _future = Api.instance.getCached(base);
  }

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return Scaffold(
      backgroundColor: DanaColors.surface,
      body: Column(
        children: [
          Container(
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
                padding:
                    const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
                child: Row(
                  children: [
                    const DanaBackButton(),
                    Expanded(
                      child: Text(
                        l.t('vocabulary'),
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                          letterSpacing: -0.36,
                        ),
                      ),
                    ),
                    SizedBox(
                      width: 36,
                      child: Text(
                        '${l.t('unit')} '
                        '${widget.unitLabel ?? widget.unitNumber}',
                        textAlign: TextAlign.right,
                        maxLines: 1,
                        overflow: TextOverflow.visible,
                        softWrap: false,
                        style: TextStyle(
                          fontSize: 13,
                          color: Colors.white.withValues(alpha: 0.7),
                          letterSpacing: -0.26,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Expanded(
            child: FutureBuilder<Map<String, dynamic>>(
              future: _future,
              builder: (context, snapshot) {
                final items = ((snapshot.data?['items'] as List?) ?? [])
                    .cast<Map<String, dynamic>>();

                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(
                    child: CircularProgressIndicator(color: DanaColors.brand),
                  );
                }

                if (items.isEmpty) {
                  return DanaEmpty(
                    icon: Icons.menu_book_outlined,
                    message: l.t('no_content'),
                  );
                }

                return ListView(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
                  children: [
                    DanaListCard(
                      radius: DanaRadius.card,
                      children: [
                        for (final item in items) WordRow(item: item),
                      ],
                    ),
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

/* ----------------------------------------------------- vocabulary tab */

/// The Vocabulary tab — Figma `vocabulary-screen`: cumulative word list
/// with the All Words / Bookmarked switch and search.
class VocabularyScreen extends StatefulWidget {
  const VocabularyScreen({super.key});

  @override
  State<VocabularyScreen> createState() => _VocabularyScreenState();
}

class _VocabularyScreenState extends State<VocabularyScreen>
    with StudyTimeAware {
  String _query = '';
  int _tab = 0;

  // The list is held in state rather than rebuilt from a FutureBuilder:
  // a bookmark toggle mutates one row and must not blank the whole screen
  // to a spinner. The spinner only shows on the very first load, while
  // there is nothing to keep on screen.
  bool _loading = true;
  List<Map<String, dynamic>> _items = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final params = <String>[
      if (_query.isNotEmpty) 'q=$_query',
      if (_tab == 1) 'bookmarked=1',
    ];
    final path =
        '/me/dictionary${params.isEmpty ? '' : '?${params.join('&')}'}';

    setState(() => _loading = true);
    try {
      final data = await Api.instance.getCached(path);
      if (!mounted) return;
      setState(() {
        _items = ((data['items'] as List?) ?? []).cast<Map<String, dynamic>>();
        _loading = false;
      });
    } on ApiError {
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return Scaffold(
      body: Column(
        children: [
          DanaHeader(title: l.t('vocabulary')),
          Expanded(
            child: ListView(
              padding:
                  const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
              children: [
                DanaSegmented(
                  labels: [l.t('all_words'), l.t('bookmarked')],
                  index: _tab,
                  onChanged: (i) {
                    setState(() => _tab = i);
                    _load();
                  },
                ),
                const SizedBox(height: 16),
                DanaSearchBar(
                  hint: l.t('search_words'),
                  onChanged: (value) {
                    _query = value.trim();
                    _load();
                  },
                ),
                const SizedBox(height: 20),
                if (_loading && _items.isEmpty)
                  const Center(
                      child:
                          CircularProgressIndicator(color: DanaColors.brand))
                else if (_items.isEmpty)
                  DanaEmpty(
                    icon: _tab == 1
                        ? Icons.bookmark_border
                        : Icons.menu_book_outlined,
                    message: _tab == 1
                        ? l.t('no_bookmarks')
                        : (_query.isEmpty
                            ? l.t('no_content')
                            : l.t('nothing_found')),
                  )
                else
                  DanaListCard(
                    radius: DanaRadius.card,
                    children: [
                      for (final item in _items)
                        WordRow(
                          item: item,
                          // On the Bookmarked tab an un-saved word leaves
                          // the list at once — removed in place, so the
                          // screen never reloads or blinks.
                          onToggled: (nowBookmarked) {
                            if (_tab == 1 && !nowBookmarked) {
                              setState(() => _items.remove(item));
                            }
                          },
                        ),
                    ],
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// One word row: term over its translation, bookmark on the right. The
/// filled state is tinted brand — the redesign dropped the amber fill.
///
/// The bookmark toggles optimistically: the glyph flips under the finger
/// and a mini confirmation flashes, so the row never triggers a reload
/// (the old callback rebuilt the whole list, which read as a blink).
/// [onToggled] lets a parent that filters by bookmark drop the row when it
/// is un-saved, without reloading.
class WordRow extends StatefulWidget {
  const WordRow({super.key, required this.item, this.onToggled});

  final Map<String, dynamic> item;
  final ValueChanged<bool>? onToggled;

  @override
  State<WordRow> createState() => _WordRowState();
}

class _WordRowState extends State<WordRow> {
  late bool _bookmarked = widget.item['bookmarked'] == true;
  bool _busy = false;

  Future<void> _toggle() async {
    if (_busy) return;
    final next = !_bookmarked;

    // Optimistic: flip the glyph and keep the shared map in sync so a
    // parent list agrees without waiting on the round trip.
    setState(() {
      _bookmarked = next;
      _busy = true;
    });
    widget.item['bookmarked'] = next;
    _flash(next);

    try {
      await Api.instance.post('/vocabulary/${widget.item['id']}/bookmark');
      widget.onToggled?.call(next);
    } on ApiError catch (e) {
      // Revert on failure and let the toast carry the error instead.
      widget.item['bookmarked'] = !next;
      if (mounted) {
        setState(() => _bookmarked = !next);
        _snack(e.message(AppState.instance.language ?? 'tk'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _flash(bool nowBookmarked) {
    final l = AppState.instance.l;
    _snack(l.t(nowBookmarked ? 'bookmark_added' : 'bookmark_removed'));
  }

  /// A small floating confirmation — replaces any in-flight one so rapid
  /// taps do not stack a queue of banners.
  void _snack(String text) {
    ScaffoldMessenger.of(context)
      ..removeCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(
            text,
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              letterSpacing: -0.28,
              color: Colors.white,
            ),
          ),
          backgroundColor: DanaColors.ink,
          behavior: SnackBarBehavior.floating,
          duration: const Duration(milliseconds: 1400),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(DanaRadius.field),
          ),
          margin: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        ),
      );
  }

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return DanaListRow(
      titleSize: 15,
      title: widget.item['term_en'] as String? ?? '',
      caption: l.pick(
        widget.item['translation_tk'] as String?,
        widget.item['translation_ru'] as String?,
      ),
      trailing: AppState.instance.isTeacher
          ? null
          : GestureDetector(
              onTap: _toggle,
              behavior: HitTestBehavior.opaque,
              child: Padding(
                padding: const EdgeInsets.only(left: 12),
                child: _bookmarked
                    ? const DanaIcon(DanaIcons.bookmarkFilled,
                        color: DanaColors.brand)
                    : const DanaIcon(DanaIcons.bookmarkOutline),
              ),
            ),
      onTap: () => showWordCard(context, widget.item),
    );
  }
}

/* --------------------------------------------------------- word modal */

/// Figma `vocabulary-screen-modal`: a bottom sheet with the term, its
/// translation, the Category/Type chips, pronunciation and the
/// source-unit chip.
///
/// The design also carries a MEANING block. Wordlist v2 has no
/// definition column (FR-15.6), so it stays hidden rather than being
/// filled from another field; the code below still renders it if an
/// `example_en` ever arrives.
Future<void> showWordCard(BuildContext context, Map<String, dynamic> item) {
  final l = AppState.instance.l;
  final ipa = item['ipa'] as String?;
  final meaning = item['example_en'] as String?;
  // Wordlist v2's Category ("Numbers 0-10", "Core Phrases") and Type
  // (word/phrase) — the design's two chips under the translation. Each
  // is dropped when its column was empty rather than filled with a
  // placeholder (FR-15.6).
  final category = (item['category'] as String?)?.trim();
  final wordType = (item['word_type'] as String?)?.trim().toLowerCase();

  final chips = <(String, Color)>[
    if (category != null && category.isNotEmpty)
      (category, DanaColors.listeningBlue),
    if (wordType == 'word' || wordType == 'phrase')
      (l.t('word_type_$wordType'), DanaColors.accent),
  ];
  final unitLabel = item['unit_label'] as String? ?? item['label'] as String?;
  final unitTitle = item['unit_title'] as String?;
  // The chip's "01" tile is the parent-unit number; when the payload
  // only carries the child label ("1-A"), its leading digits are it.
  final unitNumber = item['unit_number'] as int? ??
      int.tryParse((unitLabel ?? '').split('-').first);

  return showModalBottomSheet<void>(
    context: context,
    backgroundColor: DanaColors.card,
    barrierColor: const Color(0x4D000000),
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
    ),
    builder: (context) => SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    item['term_en'] as String? ?? '',
                    style: const TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.w700,
                      color: DanaColors.brand,
                      letterSpacing: -0.56,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                const DanaCloseButton(),
              ],
            ),
            const SizedBox(height: 16),
            // Translation, with the tap-to-hear button riding inline when
            // the word carries no written pronunciation (IPA). That keeps
            // the sheet compact — no empty PRONUNCIATION band below, the
            // button just sits beside the translation (FR-13.24 / FR-15.4).
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Expanded(
                  child: Text(
                    l.pick(
                      item['translation_tk'] as String?,
                      item['translation_ru'] as String?,
                    ),
                    style: const TextStyle(
                      fontSize: 15,
                      height: 1.4,
                      color: DanaColors.ink,
                      letterSpacing: -0.3,
                    ),
                  ),
                ),
                if (ipa == null || ipa.isEmpty) ...[
                  const SizedBox(width: 12),
                  _SpeakButton(text: item['term_en'] as String? ?? ''),
                ],
              ],
            ),
            // Wordlist v2's Category and Type, as the design's two chips
            // (`vocabulary-screen-modal`): the category in blue, the
            // word/phrase type in amber. Either is hidden when the row
            // carried no value — nothing is invented (FR-15.6).
            if (chips.isNotEmpty) ...[
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final (label, color) in chips)
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: color.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        label.toUpperCase(),
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: color,
                          letterSpacing: 0.2,
                        ),
                      ),
                    ),
                ],
              ),
            ],
            // The full PRONUNCIATION section (label + IPA + button) shows
            // only when there is written IPA to head. Wordlist v2 carries
            // none, so for those words nothing is rendered here — the
            // button already sits beside the translation above and no
            // empty band is left behind (FR-15.4).
            if (ipa != null && ipa.isNotEmpty) ...[
              const SizedBox(height: 20),
              _ModalLabel(l.t('pronunciation')),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      ipa,
                      style: const TextStyle(
                        fontSize: 15,
                        color: DanaColors.ink,
                        letterSpacing: -0.3,
                      ),
                    ),
                  ),
                  _SpeakButton(text: item['term_en'] as String? ?? ''),
                ],
              ),
            ],
            // The design's MEANING block. The API carries an English
            // example sentence, not a dictionary definition, so that is
            // what fills it — under the design's label.
            if (meaning != null && meaning.isNotEmpty) ...[
              const SizedBox(height: 20),
              _ModalLabel(l.t('meaning')),
              const SizedBox(height: 8),
              Text(
                meaning,
                style: const TextStyle(
                  fontSize: 15,
                  height: 1.45,
                  color: DanaColors.ink,
                  letterSpacing: -0.3,
                ),
              ),
            ],
            if (unitLabel != null && unitLabel.isNotEmpty) ...[
              const SizedBox(height: 20),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: DanaColors.surface,
                  borderRadius: BorderRadius.circular(DanaRadius.field),
                ),
                child: Row(
                  children: [
                    // Units can carry a free-form name now (FR-15.7), so a
                    // label like "Foods" has no leading digits to show in
                    // the number tile — it is dropped rather than padded
                    // into a meaningless "00".
                    if (unitNumber != null) ...[
                      Container(
                        width: 40,
                        height: 40,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: DanaColors.card,
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          '$unitNumber'.padLeft(2, '0'),
                          style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w600,
                            color: DanaColors.ink,
                            letterSpacing: -0.3,
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                    ],
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${l.t('unit')} $unitLabel',
                            style: const TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.w600,
                              color: DanaColors.ink,
                              letterSpacing: -0.28,
                            ),
                          ),
                          if (unitTitle != null && unitTitle.isNotEmpty) ...[
                            const SizedBox(height: 2),
                            Text(
                              unitTitle,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontSize: 12,
                                color: DanaColors.textMuted,
                                letterSpacing: -0.24,
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    ),
  );
}

/// The design's brand circle beside the IPA. Tapping it reads the word
/// through the device's system text-to-speech voice (FR-13.24).
class _SpeakButton extends StatelessWidget {
  const _SpeakButton({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => Speech.instance.speak(text),
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.all(10),
        decoration: const BoxDecoration(
          color: DanaColors.brand,
          shape: BoxShape.circle,
        ),
        child: const DanaIcon(
          DanaIcons.volume2,
          size: 20,
          frame: 20,
          color: Colors.white,
        ),
      ),
    );
  }
}

class _ModalLabel extends StatelessWidget {
  const _ModalLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Text(
        text.toUpperCase(),
        style: const TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          color: DanaColors.textMuted,
          letterSpacing: -0.22,
        ),
      );
}

// The grammar guide (index + topic screens) lived here until 2026-08-13.
// The client removed the guide from the product entirely (FR-13.26):
// grammar exists only as a practice-module section. The explanation data
// stays in the database, unread.
