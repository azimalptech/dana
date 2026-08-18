import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/icons.dart';
import '../core/l10n.dart';
import '../core/theme.dart';
import '../core/widgets.dart';
import '../main.dart';
import 'exercise_screen.dart';
import 'library_screens.dart';
import 'shell.dart';

/// Home — Figma `home-screen-completed` and its state variants.
///
/// One child unit is featured at a time: its vocabulary row and its
/// Practice Modules (the sections that actually exist — FR-13.2, no
/// placeholders). Everything is unlocked (FR-13.3), so the hamburger
/// sheet lets the student jump to any unit, and "Next Lesson" is a
/// convenience, not a gate.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  /// The child unit the student jumped to via the lessons sheet or the
  /// Next Lesson button. Null means "let the outline decide".
  int? _selectedChildId;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final fullName = AppState.instance.user?['full_name'] as String? ?? '';
    final first = fullName.split(' ').first;

    return AsyncView(
      load: () => Api.instance.get('/me/outline'),
      builder: (context, data, reload) {
        final level = data['level'] as Map<String, dynamic>?;
        final units = _flatten(data);
        final featured = _featured(units);

        final completed =
            units.where((u) => u.child['state'] == 'completed').length;

        return RefreshIndicator(
          onRefresh: () async => reload(),
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              _Header(
                initials: danaInitials(fullName),
                greeting: '${l.t('hello')}, $first! 👋',
                subtitle: l.t('keep_learning'),
                level: level?['name'] as String? ?? '',
                done: completed,
                total: units.length,
                percent: data['level_progress'] as int? ?? 0,
              ),
              if (featured == null)
                Padding(
                  padding: const EdgeInsets.all(40),
                  child: Text(l.t('no_content'), textAlign: TextAlign.center),
                )
              else
                Padding(
                  padding: const EdgeInsets.all(20),
                  child: _FeaturedUnit(
                    unit: featured,
                    units: units,
                    levelName: level?['name'] as String? ?? '',
                    onSelect: (id) => setState(() => _selectedChildId = id),
                    onChanged: reload,
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  /// Parent units exist as containers only (FR-13.1) — the screen works
  /// on the flat child-unit list and keeps each child's parent beside it
  /// for the Next Unit label.
  static List<OutlineUnit> _flatten(Map<String, dynamic> outline) {
    final out = <OutlineUnit>[];

    for (final parent in ((outline['parent_units'] as List?) ?? [])
        .cast<Map<String, dynamic>>()) {
      for (final child in ((parent['child_units'] as List?) ?? [])
          .cast<Map<String, dynamic>>()) {
        out.add(OutlineUnit(parent: parent, child: child));
      }
    }

    return out;
  }

  /// The unit the card leads with: an explicit pick if the student made
  /// one, else the first unit still in progress, else the first not yet
  /// started, else the last one with content.
  OutlineUnit? _featured(List<OutlineUnit> units) {
    final withContent =
        units.where((u) => u.child['state'] != 'empty').toList();
    if (withContent.isEmpty) return null;

    if (_selectedChildId != null) {
      for (final unit in withContent) {
        if (unit.child['id'] == _selectedChildId) return unit;
      }
    }

    for (final state in ['in_progress', 'not_started']) {
      for (final unit in withContent) {
        if (unit.child['state'] == state) return unit;
      }
    }

    return withContent.last;
  }
}

/// One child unit with the parent it belongs to.
class OutlineUnit {
  const OutlineUnit({required this.parent, required this.child});

  final Map<String, dynamic> parent;
  final Map<String, dynamic> child;
}

/* ------------------------------------------------------------- header */

class _Header extends StatelessWidget {
  const _Header({
    required this.initials,
    required this.greeting,
    required this.subtitle,
    required this.level,
    required this.done,
    required this.total,
    required this.percent,
  });

  final String initials;
  final String greeting;
  final String subtitle;
  final String level;

  /// Child units completed at least once / all child units — the
  /// design's "13/36 Units completed".
  final int done;
  final int total;

  /// FR-13.9 level completion progress: coverage, not correctness.
  final int percent;

  @override
  Widget build(BuildContext context) {
    return Container(
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
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    width: 64,
                    height: 64,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(100),
                    ),
                    child: Text(
                      initials,
                      style: const TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.w600,
                        color: Colors.white,
                        letterSpacing: -0.4,
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          greeting,
                          style: const TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.w700,
                            color: Colors.white,
                            letterSpacing: -0.36,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          subtitle,
                          style: TextStyle(
                            fontSize: 13,
                            color: Colors.white.withValues(alpha: 0.7),
                            letterSpacing: -0.26,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 24),
              CourseOverviewCard(
                level: level,
                done: done,
                total: total,
                percent: percent,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/* ------------------------------------------------------ featured unit */

class _FeaturedUnit extends StatelessWidget {
  const _FeaturedUnit({
    required this.unit,
    required this.units,
    required this.levelName,
    required this.onSelect,
    required this.onChanged,
  });

  final OutlineUnit unit;
  final List<OutlineUnit> units;
  final String levelName;
  final ValueChanged<int> onSelect;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final child = unit.child;
    final sections =
        ((child['sections'] as List?) ?? []).cast<Map<String, dynamic>>();
    final modules = sections.where((s) => s['type'] != 'quiz').toList();
    final quiz = sections.where((s) => s['type'] == 'quiz').toList();
    final rate = (child['success_rate'] as num?)?.round();
    final next = _next();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // --- unit card: header row + vocabulary row -------------------
        Container(
          decoration: BoxDecoration(
            color: DanaColors.card,
            borderRadius: BorderRadius.circular(DanaRadius.card),
          ),
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              InkWell(
                // The whole header row opens the level's unit list
                // (`home-screen-lessons`), same as the ≡ glyph — the
                // design has no other destination here.
                onTap: () => _openLessonsSheet(context),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '${l.t('unit')} ${child['label']}',
                              style: const TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.w700,
                                color: DanaColors.ink,
                                letterSpacing: -0.36,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              child['title'] as String? ?? '',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontSize: 13,
                                color: DanaColors.textMuted,
                                letterSpacing: -0.26,
                              ),
                            ),
                          ],
                        ),
                      ),
                      // FR-13.8: the unit's success rate averages its
                      // section averages; null until something is tried.
                      if (rate != null) ...[
                        DanaPercentChip(percent: rate),
                        const SizedBox(width: 10),
                      ],
                      GestureDetector(
                        onTap: () => _openLessonsSheet(context),
                        behavior: HitTestBehavior.opaque,
                        child: const Padding(
                          padding: EdgeInsets.all(4),
                          child: _MenuGlyph(),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const Divider(height: 1, thickness: 1, color: DanaColors.border),
              InkWell(
                onTap: () => _openUnitVocabulary(context),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    children: [
                      // Solid amber with the white glyph, unlike the
                      // tinted module tiles — that is how the frame
                      // singles the vocabulary row out.
                      Container(
                        width: 40,
                        height: 40,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: DanaColors.accent,
                          borderRadius: BorderRadius.circular(10),
                        ),
                        // The designer's bookmark-book, exported in white
                        // for this filled tile — so it is not tinted.
                        child: const DanaIcon.original(
                            DanaIcons.bookBookmarkWhite, size: 22),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              l.t('vocabulary'),
                              style: const TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w600,
                                color: DanaColors.ink,
                                letterSpacing: -0.3,
                              ),
                            ),
                            const SizedBox(height: 2),
                            _WordCountCaption(
                                unitId: child['id'] as int? ?? 0),
                          ],
                        ),
                      ),
                      const DanaIcon(DanaIcons.chevronRight),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),

        // --- practice modules ----------------------------------------
        if (modules.isNotEmpty) ...[
          Padding(
            padding: const EdgeInsets.only(left: 4, bottom: 10),
            child: Text(
              l.t('practice_modules').toUpperCase(),
              style: const TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w600,
                color: DanaColors.textMuted,
                letterSpacing: -0.22,
              ),
            ),
          ),
          Container(
            decoration: BoxDecoration(
              color: DanaColors.card,
              borderRadius: BorderRadius.circular(DanaRadius.card),
            ),
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                for (var i = 0; i < modules.length; i++) ...[
                  if (i > 0)
                    const Divider(
                        height: 1, thickness: 1, color: DanaColors.border),
                  _ModuleRow(
                    section: modules[i],
                    onOpen: () => _openSection(context, modules[i]),
                  ),
                ],
              ],
            ),
          ),
        ],

        // --- exam quiz (FR-13.4) -------------------------------------
        for (final section in quiz) ...[
          const SizedBox(height: 16),
          Container(
            decoration: BoxDecoration(
              color: DanaColors.card,
              borderRadius: BorderRadius.circular(DanaRadius.card),
            ),
            clipBehavior: Clip.antiAlias,
            child: _ModuleRow(
              section: section,
              onOpen: () => _openSection(context, section),
            ),
          ),
        ],

        // --- next lesson / next unit ---------------------------------
        if (child['state'] == 'completed' && next != null) ...[
          const SizedBox(height: 24),
          ElevatedButton(
            onPressed: () => onSelect(next.child['id'] as int),
            style: ElevatedButton.styleFrom(
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(DanaRadius.card),
              ),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  next.parent['id'] == unit.parent['id']
                      ? l.t('next_lesson')
                      : l.t('next_unit'),
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w600,
                    letterSpacing: -0.32,
                  ),
                ),
                const SizedBox(width: 6),
                const Icon(Icons.chevron_right, size: 20),
              ],
            ),
          ),
        ],
      ],
    );
  }

  /// The next child unit with content after the featured one.
  OutlineUnit? _next() {
    final index = units.indexWhere((u) => u.child['id'] == unit.child['id']);
    if (index == -1) return null;

    for (var i = index + 1; i < units.length; i++) {
      if (units[i].child['state'] != 'empty') return units[i];
    }

    return null;
  }

  Future<void> _openUnitVocabulary(BuildContext context) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => UnitVocabularyScreen(
          unitId: unit.child['id'] as int,
          unitNumber: unit.parent['number'] as int? ?? 0,
          unitLabel: unit.child['label'] as String?,
        ),
      ),
    );

    onChanged();
  }

  /// FR-13.5/13.6: opening a section starts a fresh run; if it has been
  /// completed before, the history modal (average + dated attempts)
  /// comes first and its Start button launches the run.
  Future<void> _openSection(
      BuildContext context, Map<String, dynamic> section) async {
    final attempts = section['attempts'] as int? ?? 0;

    if (attempts > 0) {
      final start = await showSectionHistory(context, section);
      if (start != true || !context.mounted) return;
    }

    await openSectionExercise(context, section);
    onChanged();
  }

  Future<void> _openLessonsSheet(BuildContext context) async {
    final chosen = await showLessonsSheet(
      context,
      levelName: levelName,
      units: units,
      selectedChildId: unit.child['id'] as int?,
    );

    if (chosen != null) onSelect(chosen);
  }
}

/// Three grey bars — the sheet trigger on the unit card. No SVG was
/// exported for it, so it is drawn rather than approximated with a
/// Material glyph of different proportions.
class _MenuGlyph extends StatelessWidget {
  const _MenuGlyph();

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 0; i < 3; i++)
          Container(
            width: 18,
            height: 2,
            margin: EdgeInsets.only(top: i == 0 ? 0 : 4),
            decoration: BoxDecoration(
              color: DanaColors.textMuted,
              borderRadius: BorderRadius.circular(1),
            ),
          ),
      ],
    );
  }
}

/// "12 words" under the vocabulary row. The outline does not carry the
/// count, so it is read (and cached) from the unit's vocabulary list.
class _WordCountCaption extends StatelessWidget {
  const _WordCountCaption({required this.unitId});

  final int unitId;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return FutureBuilder<Map<String, dynamic>>(
      future: Api.instance.getCached('/units/$unitId/vocabulary'),
      builder: (context, snapshot) {
        final count = ((snapshot.data?['items'] as List?) ?? []).length;

        return Text(
          snapshot.connectionState == ConnectionState.done
              ? '$count ${l.plural('words', count)}'
              : '…',
          style: const TextStyle(
            fontSize: 13,
            color: DanaColors.textMuted,
            letterSpacing: -0.26,
          ),
        );
      },
    );
  }
}

/// A section row: tinted type tile, type name, fixed caption, then the
/// average chip once tried or the play glyph before the first attempt.
class _ModuleRow extends StatelessWidget {
  const _ModuleRow({required this.section, required this.onOpen});

  final Map<String, dynamic> section;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final type = section['type'] as String? ?? '';
    final attempts = section['attempts'] as int? ?? 0;
    final average = (section['average'] as num?)?.round();

    return InkWell(
      onTap: onOpen,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            DanaModuleTile(type: type),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    sectionTitle(l, section),
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      color: DanaColors.ink,
                      letterSpacing: -0.3,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    l.t(switch (type) {
                      'grammar' => 'grammar_module_caption',
                      'listening' => 'listening_module_caption',
                      'vocabulary' => 'vocabulary_module_caption',
                      _ => 'quiz_module_caption',
                    }),
                    style: const TextStyle(
                      fontSize: 12,
                      color: DanaColors.textMuted,
                      letterSpacing: -0.24,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            if (attempts > 0 && average != null)
              DanaPercentChip(percent: average)
            else
              const DanaIcon(DanaIcons.playCircle, color: DanaColors.brand),
          ],
        ),
      ),
    );
  }
}

/// A section's display name: its optional own title when the superadmin
/// set one, the type name otherwise — the frames label rows "Grammar",
/// "Listening", "Vocabulary" and "Unit Quiz".
String sectionTitle(L l, Map<String, dynamic> section) {
  final own = l.pick(
    section['title_tk'] as String?,
    section['title_ru'] as String?,
    section['title_en'] as String?,
  );
  if (own.isNotEmpty) return own;

  return switch (section['type'] as String? ?? '') {
    'grammar' => l.t('grammar'),
    'vocabulary' => l.t('vocabulary'),
    'listening' => l.t('listening'),
    _ => l.t('unit_quiz'),
  };
}

/// Launches the exercise player for a section (FR-13.5: a fresh run,
/// nothing saved until the whole section is submitted).
Future<void> openSectionExercise(
    BuildContext context, Map<String, dynamic> section) async {
  final l = AppState.instance.l;

  await Navigator.of(context).push(
    MaterialPageRoute(
      builder: (_) => ExerciseScreen(
        sectionId: section['id'] as int,
        title: sectionTitle(l, section),
      ),
    ),
  );
}

/* ------------------------------------------------------ lessons sheet */

/// Figma `home-screen-lessons`: every child unit of the level over a
/// scrim. Returns the tapped child-unit id.
Future<int?> showLessonsSheet(
  BuildContext context, {
  required String levelName,
  required List<OutlineUnit> units,
  required int? selectedChildId,
}) {
  final l = AppState.instance.l;

  return showModalBottomSheet<int>(
    context: context,
    backgroundColor: Colors.transparent,
    barrierColor: const Color(0x4D000000),
    isScrollControlled: true,
    builder: (context) => Container(
      constraints: BoxConstraints(
        maxHeight: MediaQuery.of(context).size.height - 64,
      ),
      decoration: const BoxDecoration(
        color: DanaColors.card,
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      padding: const EdgeInsets.all(24),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    levelName,
                    style: const TextStyle(
                      fontSize: 24,
                      fontWeight: FontWeight.w700,
                      color: DanaColors.brand,
                      letterSpacing: -0.48,
                    ),
                  ),
                ),
                const DanaCloseButton(),
              ],
            ),
            const SizedBox(height: 20),
            Flexible(
              child: ListView.builder(
                shrinkWrap: true,
                padding: EdgeInsets.zero,
                itemCount: units.length,
                itemBuilder: (context, i) {
                  final child = units[i].child;
                  final empty = child['state'] == 'empty';
                  final active = child['id'] == selectedChildId;
                  final rate = (child['success_rate'] as num?)?.round();

                  return Container(
                    padding: const EdgeInsets.symmetric(vertical: 16),
                    decoration: BoxDecoration(
                      border: i == units.length - 1
                          ? null
                          : const Border(
                              bottom: BorderSide(color: DanaColors.border)),
                    ),
                    child: GestureDetector(
                      behavior: HitTestBehavior.opaque,
                      // An empty unit has nothing to open — the caption
                      // already says its content is on the way.
                      onTap: empty
                          ? null
                          : () =>
                              Navigator.of(context).pop(child['id'] as int?),
                      child: Row(
                        children: [
                          Container(
                            width: 40,
                            height: 40,
                            alignment: Alignment.center,
                            decoration: BoxDecoration(
                              color: active
                                  ? DanaColors.brand
                                  : DanaColors.surface,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Text(
                              '${i + 1}'.padLeft(2, '0'),
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w600,
                                letterSpacing: -0.3,
                                color:
                                    active ? Colors.white : DanaColors.ink,
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  '${l.t('unit')} ${child['label']}',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.w600,
                                    letterSpacing: -0.3,
                                    color: active
                                        ? DanaColors.brand
                                        : DanaColors.ink,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  // The frames caption units without
                                  // content by authoring stage; the API
                                  // only knows "empty", so one caption
                                  // covers them all.
                                  empty
                                      ? l.t('in_progress')
                                      : child['title'] as String? ?? '',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    fontSize: 13,
                                    color: DanaColors.textMuted,
                                    letterSpacing: -0.26,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 12),
                          if (rate != null) ...[
                            DanaPercentChip(percent: rate),
                            const SizedBox(width: 8),
                          ],
                          const DanaIcon(DanaIcons.chevronRight),
                        ],
                      ),
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

/* ---------------------------------------------------- section history */

/// Figma `home-screen-section-history`: overall average, dated attempts
/// and a Start button. Resolves true when the student chose Start.
Future<bool?> showSectionHistory(
    BuildContext context, Map<String, dynamic> section) {
  final l = AppState.instance.l;
  final type = section['type'] as String? ?? '';
  final title = sectionTitle(l, section);

  return showModalBottomSheet<bool>(
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
        child: FutureBuilder<Map<String, dynamic>>(
          future: Api.instance.get('/sections/${section['id']}/attempts'),
          builder: (context, snapshot) {
            final average = (snapshot.data?['average'] as num?)?.round();
            final attempts = ((snapshot.data?['attempts'] as List?) ?? [])
                .cast<Map<String, dynamic>>();

            return Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        title,
                        style: const TextStyle(
                          fontSize: 24,
                          fontWeight: FontWeight.w700,
                          color: DanaColors.brand,
                          letterSpacing: -0.48,
                        ),
                      ),
                    ),
                    const DanaCloseButton(),
                  ],
                ),
                const SizedBox(height: 16),
                DanaInfoCard(text: l.t('average_hint')),
                if (snapshot.connectionState != ConnectionState.done)
                  const Padding(
                    padding: EdgeInsets.all(32),
                    child: Center(
                      child:
                          CircularProgressIndicator(color: DanaColors.brand),
                    ),
                  )
                else ...[
                  const SizedBox(height: 20),
                  Text(
                    l.t('overall_average').toUpperCase(),
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: DanaColors.textMuted,
                      letterSpacing: -0.22,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      DanaModuleTile(type: type),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              title,
                              style: const TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w600,
                                color: DanaColors.ink,
                                letterSpacing: -0.3,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              '${attempts.length} '
                              '${l.plural('tries', attempts.length)}',
                              style: const TextStyle(
                                fontSize: 13,
                                color: DanaColors.textMuted,
                                letterSpacing: -0.26,
                              ),
                            ),
                          ],
                        ),
                      ),
                      if (average != null) DanaPercentChip(percent: average),
                    ],
                  ),
                  const SizedBox(height: 20),
                  Text(
                    l.t('history').toUpperCase(),
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: DanaColors.textMuted,
                      letterSpacing: -0.22,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Flexible(
                    child: ListView.builder(
                      shrinkWrap: true,
                      padding: EdgeInsets.zero,
                      itemCount: attempts.length,
                      itemBuilder: (context, i) {
                        final attempt = attempts[i];
                        final percent =
                            (attempt['percent'] as num?)?.round() ?? 0;

                        return Padding(
                          padding: const EdgeInsets.symmetric(vertical: 10),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment:
                                      CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      _formatWhen(
                                          attempt['completed_at'] as String?),
                                      style: const TextStyle(
                                        fontSize: 15,
                                        fontWeight: FontWeight.w700,
                                        color: DanaColors.ink,
                                        letterSpacing: -0.3,
                                      ),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      l.f('answers_correct_line', {
                                        'n': attempt['correct'] ?? 0,
                                        'm': attempt['total'] ?? 0,
                                      }),
                                      style: const TextStyle(
                                        fontSize: 13,
                                        color: DanaColors.textMuted,
                                        letterSpacing: -0.26,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              DanaPercentChip(percent: percent),
                            ],
                          ),
                        );
                      },
                    ),
                  ),
                  const SizedBox(height: 16),
                  ElevatedButton(
                    onPressed: () => Navigator.of(context).pop(true),
                    style: ElevatedButton.styleFrom(
                      shape: RoundedRectangleBorder(
                        borderRadius:
                            BorderRadius.circular(DanaRadius.card),
                      ),
                    ),
                    child: Text(l.t('start')),
                  ),
                ],
              ],
            );
          },
        ),
      ),
    ),
  );
}

/// "Today, 14:35" or "12 Jun, 14:35", in the interface language.
String _formatWhen(String? iso) {
  final l = AppState.instance.l;
  final time = DateTime.tryParse(iso ?? '')?.toLocal();
  if (time == null) return '';

  final now = DateTime.now();
  final sameDay =
      time.year == now.year && time.month == now.month && time.day == now.day;
  final clock = '${time.hour.toString().padLeft(2, '0')}:'
      '${time.minute.toString().padLeft(2, '0')}';

  if (sameDay) return '${l.t('today')}, $clock';

  return '${time.day} ${l.list('months_short')[time.month - 1]}, $clock';
}
