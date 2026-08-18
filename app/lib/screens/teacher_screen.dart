import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/icons.dart';
import '../core/l10n.dart';
import '../core/theme.dart';
import '../core/widgets.dart';
import '../main.dart';
import 'home_screen.dart' show sectionTitle;
import 'shell.dart';

/// Teacher side — READ-ONLY since the redesign (FR-13.10): classes,
/// students, results. No unlocking (FR-13.3 removed gating), no student
/// creation (FR-1.4) and no credential authority (FR-13.17).
///
/// Built 1:1 to the Figma "Teacher" section (export 2026-08-13):
/// `home-screen` (My Classes), `home-screen-1` (settings drawer),
/// `unit-vocabulary-screen-1/-2` (classroom Students / Ranking),
/// `student-detail`, `unit-vocabulary-screen-3` (Unit progress).
/// The stale `student-detail-more` frame (password box, XP, locks)
/// predates the role rework and stays unbuilt — same rule as the
/// removed add-student frame (FR-13.23).
class TeacherClassroomsScreen extends StatelessWidget {
  const TeacherClassroomsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final user = AppState.instance.user ?? {};
    final name = user['full_name'] as String? ?? '';

    return Scaffold(
      backgroundColor: DanaColors.surface,
      // Figma `home-screen-1`: settings slide in as a LEFT drawer over
      // the classes list — there is no bottom nav anywhere.
      drawer: const TeacherDrawer(),
      body: Builder(
        builder: (context) => Column(
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
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Hamburger tile, then the wordmark beside it.
                      Row(
                        children: [
                          GestureDetector(
                            onTap: () => Scaffold.of(context).openDrawer(),
                            child: Container(
                              width: 40,
                              height: 40,
                              alignment: Alignment.center,
                              decoration: BoxDecoration(
                                color: Colors.white.withValues(alpha: 0.1),
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: const Icon(
                                Icons.menu,
                                size: 22,
                                color: Colors.white,
                              ),
                            ),
                          ),
                          const SizedBox(width: 14),
                          const Image(
                            image: AssetImage('assets/brand/logo.png'),
                            width: 96,
                          ),
                        ],
                      ),
                      const SizedBox(height: 20),
                      // Greeting row: avatar, "Hello Teacher!", the name.
                      Row(
                        children: [
                          Container(
                            width: 56,
                            height: 56,
                            alignment: Alignment.center,
                            decoration: BoxDecoration(
                              color: Colors.white.withValues(alpha: 0.1),
                              shape: BoxShape.circle,
                            ),
                            child: Text(
                              danaInitials(name),
                              style: const TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.w600,
                                color: Colors.white,
                                letterSpacing: -0.36,
                              ),
                            ),
                          ),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  l.t('hello_teacher'),
                                  style: TextStyle(
                                    fontSize: 13,
                                    color: Colors.white.withValues(alpha: 0.7),
                                    letterSpacing: -0.26,
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  name,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    fontSize: 20,
                                    fontWeight: FontWeight.w700,
                                    color: Colors.white,
                                    letterSpacing: -0.4,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
            Expanded(
              child: AsyncView(
                load: () => Api.instance.get('/teacher/classrooms'),
                builder: (context, data, reload) {
                  final classrooms = ((data['classrooms'] as List?) ?? [])
                      .cast<Map<String, dynamic>>();

                  if (classrooms.isEmpty) {
                    return Center(child: Text(l.t('no_content')));
                  }

                  return RefreshIndicator(
                    onRefresh: () async => reload(),
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
                      children: [
                        // "MY CLASSES" left, "4 CLASSES" right.
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                l.t('teacher_classrooms').toUpperCase(),
                                style: const TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.w600,
                                  color: DanaColors.textMuted,
                                  letterSpacing: -0.22,
                                ),
                              ),
                            ),
                            Text(
                              '${classrooms.length} '
                                      '${l.plural('classes_count', classrooms.length)}'
                                  .toUpperCase(),
                              style: const TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w600,
                                color: DanaColors.textMuted,
                                letterSpacing: -0.22,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        for (var i = 0; i < classrooms.length; i++)
                          _ClassCard(
                            classroom: classrooms[i],
                            index: i,
                            onTap: () async {
                              await Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (_) => ClassroomScreen(
                                    id: classrooms[i]['id'] as int,
                                    name:
                                        classrooms[i]['name'] as String? ?? '',
                                    studentCount: classrooms[i]['student_count']
                                            as int? ??
                                        0,
                                  ),
                                ),
                              );
                              reload();
                            },
                          ),
                      ],
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Figma `home-screen` class card: a pastel rounded square with the
/// two-people glyph (the tint cycles blue → amber → green down the
/// list), the class name over "N students", chevron on the right.
class _ClassCard extends StatelessWidget {
  const _ClassCard({
    required this.classroom,
    required this.index,
    required this.onTap,
  });

  final Map<String, dynamic> classroom;
  final int index;
  final VoidCallback onTap;

  // Sampled from the frame: blue / amber / green icon tiles.
  static const _tints = [
    Color(0xFF0B93F5),
    DanaColors.accent,
    DanaColors.ok,
  ];

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final count = classroom['student_count'] as int? ?? 0;
    final tint = _tints[index % _tints.length];

    return GestureDetector(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: DanaColors.card,
          borderRadius: BorderRadius.circular(DanaRadius.card),
        ),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: tint.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(12),
              ),
              child: DanaIcon(DanaIcons.users, size: 24, color: tint),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    classroom['name'] as String? ?? '',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 17,
                      fontWeight: FontWeight.w700,
                      color: DanaColors.ink,
                      letterSpacing: -0.34,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    '$count ${l.t('students').toLowerCase()}',
                    style: const TextStyle(
                      fontSize: 13,
                      color: DanaColors.textMuted,
                      letterSpacing: -0.26,
                    ),
                  ),
                ],
              ),
            ),
            const DanaIcon(DanaIcons.chevronRight),
          ],
        ),
      ),
    );
  }
}

/// Figma `home-screen-1`: the teacher's settings drawer. Burgundy block
/// with the close tile, logo and identity; white body with SETTINGS
/// rows and the red Logout row. No Notifications and no course card —
/// both are student-only.
class TeacherDrawer extends StatelessWidget {
  const TeacherDrawer({super.key});

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final user = AppState.instance.user ?? {};
    final name = user['full_name'] as String? ?? '';
    final lang = AppState.instance.language ?? 'tk';

    return Drawer(
      width: MediaQuery.of(context).size.width * 0.86,
      backgroundColor: DanaColors.card,
      shape: const RoundedRectangleBorder(),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: double.infinity,
            color: DanaColors.brand,
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        GestureDetector(
                          onTap: () => Navigator.of(context).pop(),
                          child: Container(
                            width: 40,
                            height: 40,
                            alignment: Alignment.center,
                            decoration: BoxDecoration(
                              color: Colors.white.withValues(alpha: 0.1),
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: const DanaIcon(
                              DanaIcons.times,
                              size: 20,
                              color: Colors.white,
                            ),
                          ),
                        ),
                        const SizedBox(width: 14),
                        const Image(
                          image: AssetImage('assets/brand/logo.png'),
                          width: 96,
                        ),
                      ],
                    ),
                    const SizedBox(height: 24),
                    Row(
                      children: [
                        Container(
                          width: 56,
                          height: 56,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.1),
                            shape: BoxShape.circle,
                          ),
                          child: Text(
                            danaInitials(name),
                            style: const TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w600,
                              color: Colors.white,
                              letterSpacing: -0.36,
                            ),
                          ),
                        ),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                name,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  fontSize: 18,
                                  fontWeight: FontWeight.w700,
                                  color: Colors.white,
                                  letterSpacing: -0.36,
                                ),
                              ),
                              const SizedBox(height: 3),
                              Text(
                                user['login'] as String? ?? '',
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
                  ],
                ),
              ),
            ),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
              children: [
                Text(
                  l.t('settings').toUpperCase(),
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: DanaColors.textMuted,
                    letterSpacing: -0.22,
                  ),
                ),
                const SizedBox(height: 8),
                DanaSettingsRow(
                  icon: DanaIcons.globe,
                  label: l.t('interface_language'),
                  value: l.t('language_$lang'),
                  onTap: () => danaPickLanguage(context),
                ),
                DanaSettingsRow(
                  icon: DanaIcons.messageText,
                  label: l.t('feedback'),
                  onTap: () => danaInfoSheet(
                      context, l.t('feedback'), l.t('ask_your_teacher')),
                ),
                DanaSettingsRow(
                  icon: DanaIcons.infoCircle,
                  label: l.t('about_app'),
                  last: true,
                  onTap: () => danaInfoSheet(
                      context, l.t('about_app'), 'mydana · ${l.t('version')} 0.1.0'),
                ),
                const SizedBox(height: 24),
                // The frame's red Logout row, separated from the list.
                GestureDetector(
                  onTap: () => danaConfirmLogout(context),
                  behavior: HitTestBehavior.opaque,
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Row(
                      children: [
                        const DanaIcon(DanaIcons.logOut,
                            color: DanaColors.danger),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            l.t('log_out'),
                            style: const TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.w600,
                              color: DanaColors.danger,
                              letterSpacing: -0.28,
                            ),
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
        ],
      ),
    );
  }
}

/// Classroom detail — brand header with the students count on the right,
/// then the Students / Ranking switch. The old Lessons tab rode on the
/// unlock system; both left with the redesign.
class ClassroomScreen extends StatefulWidget {
  const ClassroomScreen({
    super.key,
    required this.id,
    required this.name,
    required this.studentCount,
  });

  final int id;
  final String name;
  final int studentCount;

  @override
  State<ClassroomScreen> createState() => _ClassroomScreenState();
}

class _ClassroomScreenState extends State<ClassroomScreen> {
  int _tab = 0;

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
                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
                child: Row(
                  children: [
                    const DanaBackButton(),
                    Expanded(
                      child: Text(
                        widget.name,
                        textAlign: TextAlign.center,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                          letterSpacing: -0.36,
                        ),
                      ),
                    ),
                    Text(
                      '${widget.studentCount} ${l.t('students').toLowerCase()}',
                      style: TextStyle(
                        fontSize: 13,
                        color: Colors.white.withValues(alpha: 0.7),
                        letterSpacing: -0.26,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 24, 20, 0),
            child: DanaSegmented(
              labels: [l.t('students'), l.t('ranking')],
              index: _tab,
              onChanged: (i) => setState(() => _tab = i),
            ),
          ),
          Expanded(
            child: _tab == 0
                ? _StudentsTab(classroomId: widget.id)
                : _RankingTab(classroomId: widget.id),
          ),
        ],
      ),
    );
  }
}

/* ------------------------------------------------------- students tab */

class _StudentsTab extends StatelessWidget {
  const _StudentsTab({required this.classroomId});

  final int classroomId;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return AsyncView(
      load: () => Api.instance.get('/teacher/classrooms/$classroomId/students'),
      builder: (context, data, reload) {
        final students =
            ((data['students'] as List?) ?? []).cast<Map<String, dynamic>>();

        // The old frame pinned "Add New Student" here. Enrolment moved
        // to the centre admin (FR-1.4, FR-13.23), so the button is gone
        // and an empty class explains who to ask.
        if (students.isEmpty) {
          return Padding(
            padding: const EdgeInsets.all(24),
            child: Center(
              child: Text(
                l.t('students_added_by_admin'),
                textAlign: TextAlign.center,
                style: const TextStyle(color: DanaColors.textMuted),
              ),
            ),
          );
        }

        return ListView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
          children: [
            DanaListCard(
              children: [
                for (final student in students)
                  InkWell(
                    onTap: () async {
                      await Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => StudentDetailScreen(
                            studentId: student['id'] as int,
                          ),
                        ),
                      );
                      reload();
                    },
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Row(
                        children: [
                          _Avatar(name: student['full_name'] as String? ?? ''),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  student['full_name'] as String? ?? '',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.w600,
                                    color: DanaColors.ink,
                                    letterSpacing: -0.3,
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  student['login'] as String? ?? '',
                                  style: const TextStyle(
                                    fontSize: 13,
                                    color: DanaColors.textMuted,
                                    letterSpacing: -0.26,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 8),
                          if (student['average'] != null) ...[
                            DanaPercentChip(
                              percent:
                                  (student['average'] as num).round(),
                            ),
                            const SizedBox(width: 8),
                          ],
                          const DanaIcon(DanaIcons.chevronRight),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          ],
        );
      },
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.name, this.size = 40});

  final String name;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: const BoxDecoration(
        color: DanaColors.surface,
        shape: BoxShape.circle,
      ),
      child: Text(
        danaInitials(name),
        style: const TextStyle(
          fontSize: 14,
          fontWeight: FontWeight.w600,
          color: DanaColors.ink,
          letterSpacing: -0.28,
        ),
      ),
    );
  }
}

/* -------------------------------------------------------- ranking tab */

/// Figma `unit-vocabulary-screen-2`: the classroom ranking with the
/// gold/silver/bronze podium over the numbered list — the exact same
/// components as the students' own leaderboard, no "YOU" pill.
///
/// Ordered exactly as the students' leaderboard (FR-13.19): level
/// correctness × 100, derived from the same `average` the register
/// shows, so the two tabs can never disagree.
class _RankingTab extends StatelessWidget {
  const _RankingTab({required this.classroomId});

  final int classroomId;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return AsyncView(
      load: () => Api.instance.get('/teacher/classrooms/$classroomId/students'),
      builder: (context, data, reload) {
        final students =
            ((data['students'] as List?) ?? []).cast<Map<String, dynamic>>();

        if (students.isEmpty) {
          return Center(child: Text(l.t('no_ranking_yet')));
        }

        final ranked = List.of(students)
          ..sort((a, b) => ((b['average'] as num?) ?? -1)
              .compareTo((a['average'] as num?) ?? -1));

        // The podium components speak the leaderboard entry shape; the
        // frame shows first names under the avatars.
        final entries = [
          for (var i = 0; i < ranked.length; i++)
            {
              'rank': i + 1,
              'display_name': (ranked[i]['full_name'] as String? ?? '')
                  .trim()
                  .split(RegExp(r'\s+'))
                  .first,
              'score':
                  (((ranked[i]['average'] as num?) ?? 0) * 100).round(),
              'is_me': false,
            },
        ];

        final podium = entries.take(3).toList();
        final rest = entries.skip(3).toList();

        return RefreshIndicator(
          onRefresh: () async => reload(),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 28, 20, 24),
            children: [
              DanaPodium(entries: podium),
              if (rest.isNotEmpty) ...[
                const SizedBox(height: 28),
                DanaRankingList(entries: rest),
              ],
            ],
          ),
        );
      },
    );
  }
}

/* ----------------------------------------------------- student detail */

/// Figma `student-detail`: the student card, their Course Overview with
/// the green ring, the ranking / active-time tiles, then per-unit
/// averages. Every number is server-derived (FR-13.8/13.9).
class StudentDetailScreen extends StatelessWidget {
  const StudentDetailScreen({super.key, required this.studentId});

  final int studentId;

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
                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
                child: Row(
                  children: [
                    const DanaBackButton(),
                    Expanded(
                      child: Text(
                        l.t('student_detail'),
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                          letterSpacing: -0.36,
                        ),
                      ),
                    ),
                    const SizedBox(width: 36),
                  ],
                ),
              ),
            ),
          ),
          Expanded(
            child: AsyncView(
              load: () =>
                  Api.instance.get('/teacher/students/$studentId/overview'),
              builder: (context, data, reload) {
                final student =
                    (data['student'] as Map?)?.cast<String, dynamic>() ?? {};
                final name = student['full_name'] as String? ?? '';
                final units = ((data['units'] as List?) ?? [])
                    .cast<Map<String, dynamic>>()
                    .where((u) => u['state'] != 'empty')
                    .toList();
                final hours =
                    ((data['study_seconds'] as num?) ?? 0).toDouble() / 3600;

                return RefreshIndicator(
                  onRefresh: () async => reload(),
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
                    children: [
                      Container(
                        decoration: BoxDecoration(
                          color: DanaColors.card,
                          borderRadius: BorderRadius.circular(DanaRadius.card),
                        ),
                        child: Column(
                          children: [
                            Padding(
                              padding: const EdgeInsets.all(16),
                              child: Row(
                                children: [
                                  _Avatar(name: name, size: 48),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          name,
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                          style: const TextStyle(
                                            fontSize: 16,
                                            fontWeight: FontWeight.w700,
                                            color: DanaColors.ink,
                                            letterSpacing: -0.32,
                                          ),
                                        ),
                                        const SizedBox(height: 2),
                                        Text(
                                          student['login'] as String? ?? '',
                                          style: const TextStyle(
                                            fontSize: 13,
                                            color: DanaColors.textMuted,
                                            letterSpacing: -0.26,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            const Divider(
                                height: 1,
                                thickness: 1,
                                color: DanaColors.border),
                            Padding(
                              padding: const EdgeInsets.all(16),
                              child: Row(
                                children: [
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          l
                                              .t('overall_progress')
                                              .toUpperCase(),
                                          style: const TextStyle(
                                            fontSize: 11,
                                            fontWeight: FontWeight.w600,
                                            color: DanaColors.textMuted,
                                            letterSpacing: -0.22,
                                          ),
                                        ),
                                        const SizedBox(height: 8),
                                        Text(
                                          data['level'] as String? ?? '',
                                          style: const TextStyle(
                                            fontSize: 22,
                                            fontWeight: FontWeight.w700,
                                            color: DanaColors.brand,
                                            letterSpacing: -0.44,
                                          ),
                                        ),
                                        const SizedBox(height: 8),
                                        Text(
                                          l.f('units_completed_line', {
                                            'n': data['units_completed'] ?? 0,
                                            'm': data['units_total'] ?? 0,
                                            'w': l.plural('units_count',
                                                data['units_total'] as int? ?? 0),
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
                                  const SizedBox(width: 12),
                                  DanaProgressRing(
                                    percent: data['percent'] as int? ?? 0,
                                  ),
                                ],
                              ),
                            ),
                            const Divider(
                                height: 1,
                                thickness: 1,
                                color: DanaColors.border),
                            IntrinsicHeight(
                              child: Row(
                                children: [
                                  Expanded(
                                    child: _StatTile(
                                      // Design `student-detail`: a blue
                                      // trophy here, not the nav podium.
                                      icon: DanaIcons.trophyStar,
                                      label: l.t('ranking'),
                                      value: data['rank'] == null
                                          ? '—'
                                          : '#${data['rank']}',
                                    ),
                                  ),
                                  const VerticalDivider(
                                      width: 1,
                                      thickness: 1,
                                      color: DanaColors.border),
                                  Expanded(
                                    child: _StatTile(
                                      icon: DanaIcons.clockAmber,
                                      label: l.t('total_active'),
                                      value:
                                          '${_hours(l, hours)} ${l.t('hours_short')}',
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 20),
                      Padding(
                        padding: const EdgeInsets.only(left: 4, bottom: 10),
                        child: Text(
                          l.t('unit_progress').toUpperCase(),
                          style: const TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w600,
                            color: DanaColors.textMuted,
                            letterSpacing: -0.22,
                          ),
                        ),
                      ),
                      DanaListCard(
                        children: [
                          for (var i = 0; i < units.length; i++)
                            _UnitProgressRow(
                              index: i,
                              unit: units[i],
                              // Figma: a unit row opens the Unit progress
                              // screen (`unit-vocabulary-screen-3`).
                              onTap: () => Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (_) => TeacherUnitProgressScreen(
                                    studentId: studentId,
                                    name: name,
                                    childUnitId: units[i]['id'] as int,
                                    label: units[i]['label'] as String? ?? '',
                                  ),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  /// "56,2" — the design writes the decimal comma; English keeps the dot.
  static String _hours(L l, double hours) {
    final text = hours.toStringAsFixed(1);
    return AppState.instance.language == 'en' ? text : text.replaceAll('.', ',');
  }
}

class _StatTile extends StatelessWidget {
  const _StatTile({
    required this.icon,
    required this.label,
    required this.value,
  });

  /// Drawn in its own colours — these two glyphs carry their meaning in
  /// the fill (blue trophy, amber clock), so the tile does not tint them.
  final String icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Row(
        children: [
          // The glyph sits bare on the card (design `student-detail`): the
          // clock's amber disc is part of the drawing, so a tinted circle
          // behind it read as two circles stacked.
          DanaIcon.original(icon, size: 26),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label.toUpperCase(),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.w600,
                    color: DanaColors.textMuted,
                    letterSpacing: -0.2,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  value,
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: DanaColors.ink,
                    letterSpacing: -0.3,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _UnitProgressRow extends StatelessWidget {
  const _UnitProgressRow({
    required this.index,
    required this.unit,
    required this.onTap,
  });

  final int index;
  final Map<String, dynamic> unit;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    // The design paints the unit currently being worked on brand.
    final active = unit['state'] == 'in_progress';
    final average = (unit['average'] as num?)?.round();

    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Container(
              width: 40,
              height: 40,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: active ? DanaColors.brand : DanaColors.surface,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                '${index + 1}'.padLeft(2, '0'),
                style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w600,
                  letterSpacing: -0.3,
                  color: active ? Colors.white : DanaColors.ink,
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${l.t('unit')} ${unit['label']}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      letterSpacing: -0.3,
                      color: active ? DanaColors.brand : DanaColors.ink,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    unit['title'] as String? ?? '',
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
            if (average != null) ...[
              DanaPercentChip(percent: average),
              const SizedBox(width: 8),
            ],
            const DanaIcon(DanaIcons.chevronRight),
          ],
        ),
      ),
    );
  }
}

/* ----------------------------------------------------- unit progress */

/// Figma `unit-vocabulary-screen-3`: one student's results in one child
/// unit — UNIT OVERALL, the practice modules with their try counts and
/// averages, and the Unit Quiz on its own card. Read-only; tapping a
/// row opens the attempt history (FR-12.10) for the unit.
class TeacherUnitProgressScreen extends StatelessWidget {
  const TeacherUnitProgressScreen({
    super.key,
    required this.studentId,
    required this.name,
    required this.childUnitId,
    required this.label,
  });

  final int studentId;
  final String name;
  final int childUnitId;
  final String label;

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
                        l.t('unit_progress'),
                        textAlign: TextAlign.center,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                          letterSpacing: -0.36,
                        ),
                      ),
                    ),
                    // Balances the back tile so the title stays centred.
                    const SizedBox(width: 36),
                  ],
                ),
              ),
            ),
          ),
          Expanded(
            child: AsyncView(
              load: () => Api.instance
                  .get('/teacher/students/$studentId/units/$childUnitId/progress'),
              builder: (context, data, reload) {
                final unit =
                    (data['unit'] as Map?)?.cast<String, dynamic>() ?? {};
                final modules = ((data['modules'] as List?) ?? [])
                    .cast<Map<String, dynamic>>();
                final quiz =
                    (data['quiz'] as Map?)?.cast<String, dynamic>();

                void openAttempts() => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => StudentAttemptsScreen(
                          studentId: studentId,
                          name: name,
                          filterLabel: label,
                        ),
                      ),
                    );

                return RefreshIndicator(
                  onRefresh: () async => reload(),
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
                    children: [
                      _caption(l.t('unit_overall')),
                      const SizedBox(height: 10),
                      Container(
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: DanaColors.card,
                          borderRadius:
                              BorderRadius.circular(DanaRadius.card),
                        ),
                        child: Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    '${l.t('unit')} ${unit['label'] ?? label}',
                                    style: const TextStyle(
                                      fontSize: 16,
                                      fontWeight: FontWeight.w700,
                                      color: DanaColors.ink,
                                      letterSpacing: -0.32,
                                    ),
                                  ),
                                  if ((unit['title'] as String?)
                                          ?.isNotEmpty ==
                                      true) ...[
                                    const SizedBox(height: 3),
                                    Text(
                                      unit['title'] as String,
                                      style: const TextStyle(
                                        fontSize: 13,
                                        color: DanaColors.textMuted,
                                        letterSpacing: -0.26,
                                      ),
                                    ),
                                  ],
                                ],
                              ),
                            ),
                            _chip(unit['average'] as num?),
                          ],
                        ),
                      ),
                      const SizedBox(height: 24),
                      _caption(l.t('practice_modules')),
                      const SizedBox(height: 10),
                      if (modules.isNotEmpty)
                        DanaListCard(
                          children: [
                            for (final module in modules)
                              _moduleRow(
                                l,
                                type: module['type'] as String? ?? '',
                                title: sectionTitle(l, module),
                                tries: module['tries'] as int? ?? 0,
                                average: module['average'] as num?,
                                onTap: openAttempts,
                              ),
                          ],
                        )
                      else
                        Container(
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(
                            color: DanaColors.card,
                            borderRadius:
                                BorderRadius.circular(DanaRadius.card),
                          ),
                          child: Text(
                            l.t('no_content'),
                            style: const TextStyle(
                              fontSize: 13,
                              color: DanaColors.textMuted,
                            ),
                          ),
                        ),
                      // The Exam Quiz on its own card, as framed.
                      if (quiz != null) ...[
                        const SizedBox(height: 16),
                        DanaListCard(
                          children: [
                            _moduleRow(
                              l,
                              type: 'quiz',
                              title: l.t('unit_quiz'),
                              tries: quiz['tries'] as int? ?? 0,
                              average: quiz['average'] as num?,
                              onTap: openAttempts,
                            ),
                          ],
                        ),
                      ],
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _caption(String text) => Text(
        text.toUpperCase(),
        style: const TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          color: DanaColors.textMuted,
          letterSpacing: -0.22,
        ),
      );

  /// Green/amber/red pill for an average, the grey N/A pill otherwise.
  Widget _chip(num? average) {
    if (average != null) {
      return DanaPercentChip(percent: average.round());
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: DanaColors.surface,
        borderRadius: BorderRadius.circular(100),
      ),
      child: const Text(
        'N/A',
        style: TextStyle(
          fontSize: 13,
          fontWeight: FontWeight.w700,
          color: DanaColors.textMuted,
          letterSpacing: -0.26,
        ),
      ),
    );
  }

  Widget _moduleRow(
    L l, {
    required String type,
    required String title,
    required int tries,
    required num? average,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
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
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      color: DanaColors.ink,
                      letterSpacing: -0.3,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    '$tries ${l.plural('tries', tries)}',
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
            _chip(average),
          ],
        ),
      ),
    );
  }
}

/* --------------------------------------------------- attempt history */

/// FR-12.10: the individual attempts with their answers, so a teacher
/// can see what to re-teach rather than only that a score was low.
class StudentAttemptsScreen extends StatelessWidget {
  const StudentAttemptsScreen({
    super.key,
    required this.studentId,
    required this.name,
    this.filterLabel,
  });

  final int studentId;
  final String name;

  /// When set, only attempts of this child unit ("1-A") are listed —
  /// the Unit Progress row the teacher came from.
  final String? filterLabel;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return Scaffold(
      backgroundColor: DanaColors.surface,
      appBar: AppBar(
        title: Text(
          filterLabel == null ? name : '$name · ${l.t('unit')} $filterLabel',
        ),
        backgroundColor: DanaColors.brand,
        foregroundColor: Colors.white,
      ),
      body: AsyncView(
        load: () => Api.instance.get('/teacher/students/$studentId/attempts'),
        builder: (context, data, reload) {
          var attempts = ((data['attempts'] as List?) ?? [])
              .cast<Map<String, dynamic>>();

          if (filterLabel != null) {
            attempts =
                attempts.where((a) => a['label'] == filterLabel).toList();
          }

          if (attempts.isEmpty) {
            return Center(child: Text(l.t('no_content')));
          }

          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              for (final attempt in attempts) _AttemptCard(attempt: attempt),
            ],
          );
        },
      ),
    );
  }
}

class _AttemptCard extends StatelessWidget {
  const _AttemptCard({required this.attempt});

  final Map<String, dynamic> attempt;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final title = l.pick(
      attempt['title_tk'] as String?,
      attempt['title_ru'] as String?,
    );
    final answers =
        ((attempt['answers'] as List?) ?? []).cast<Map<String, dynamic>>();
    final wrong = answers.where((a) => a['correct'] != true).toList();

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: DanaColors.card,
        borderRadius: BorderRadius.circular(DanaRadius.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${l.t('unit')} ${attempt['label']}'
                      '${title.isEmpty ? '' : ' · $title'}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: DanaColors.ink,
                        letterSpacing: -0.28,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      l.f('answers_correct_line', {
                        'n': attempt['correct'] ?? 0,
                        'm': attempt['total'] ?? 0,
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
              DanaPercentChip(
                percent: ((attempt['percent'] as num?) ?? 0).round(),
              ),
            ],
          ),
          // Only the misses are spelled out — that is what the teacher
          // opened this for; a perfect attempt needs no list.
          for (final answer in wrong) ...[
            const SizedBox(height: 10),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: DanaColors.danger.withValues(alpha: 0.06),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    answer['question'] as String? ?? '',
                    style: const TextStyle(
                      fontSize: 13,
                      color: DanaColors.ink,
                      letterSpacing: -0.26,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '✗ ${_given(answer['given'])}',
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: DanaColors.danger,
                      letterSpacing: -0.26,
                    ),
                  ),
                  Text(
                    '→ ${answer['answer']}',
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: DanaColors.ok,
                      letterSpacing: -0.26,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// The student's submitted answer, whatever shape the type stores.
  static String _given(dynamic given) {
    if (given == null) return '—';
    if (given is List) return given.map((e) => '$e').join(' ');
    if (given is Map) {
      return given.entries.map((e) => '${e.key} = ${e.value}').join(', ');
    }
    return '$given';
  }
}
