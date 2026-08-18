import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/icons.dart';
import '../core/theme.dart';
import '../core/widgets.dart';
import '../main.dart';
import 'home_screen.dart';
import 'library_screens.dart';
import 'notifications_screen.dart';
import 'teacher_screen.dart';

/// Bottom-nav shell. Students and teachers share the app (FR-2.1) but
/// see different tabs.
class AppShell extends StatefulWidget {
  const AppShell({super.key});

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    // Rebuild the shell — and the mounted tab screens with it — whenever
    // the interface language changes (FR-13.25). A live system-language
    // switch fires AppState.notifyListeners(); without subscribing here,
    // only freshly-pushed routes pick up the new language while the tabs
    // already on screen stay in the old one — their const children are
    // identical across rebuilds, so Flutter skips them. Listening to
    // AppState and handing the IndexedStack fresh (non-const) children
    // forces each tab to re-run build(); the tab index and every screen's
    // own state are preserved, only the text re-resolves.
    return AnimatedBuilder(
      animation: AppState.instance,
      builder: (context, _) {
        final l = AppState.instance.l;

        // The teacher app has NO bottom nav (Figma `home-screen` /
        // `Teacher` section): it is just the classes screen, and
        // Profile/Settings is a left drawer opened from the hamburger —
        // not a tab. Non-const for the same language-rebuild reason.
        if (AppState.instance.isTeacher) {
          // ignore: prefer_const_constructors
          return TeacherClassroomsScreen();
        }

        // FOUR student destinations — Main, Vocabulary, Ranking, Profile.
        // The exported grammar-guide frame still shows five; the SPEC
        // calls that nav stale and the guide is reached from Profile.
        //
        // Deliberately NOT const: a const list hands the IndexedStack the
        // same child instances every rebuild, and a language change would
        // never reach the mounted tabs (see the note above).
        final pages = <Widget>[
          // ignore: prefer_const_constructors
          HomeScreen(),
          // ignore: prefer_const_constructors
          VocabularyScreen(),
          // ignore: prefer_const_constructors
          LeaderboardScreen(),
          // ignore: prefer_const_constructors
          ProfileScreen(),
        ];

        // Glyphs exported from Figma, outline then filled. The design
        // draws the active destination as a solid glyph, not just a
        // recoloured outline, so each tab carries both.
        final items = [
          (DanaIcons.home, DanaIcons.homeActive, l.t('main')),
          (DanaIcons.dictionary, DanaIcons.dictionaryActive, l.t('vocabulary')),
          (DanaIcons.ranking, DanaIcons.rankingActive, l.t('ranking')),
          (DanaIcons.profile, DanaIcons.profileActive, l.t('profile')),
        ];

        return Scaffold(
          body: IndexedStack(index: _index, children: pages),
          bottomNavigationBar: DanaTabBar(
            index: _index,
            onChanged: (i) => setState(() => _index = i),
            items: items,
          ),
        );
      },
    );
  }
}

/// The Figma TabBar: white, hairline top border, 24px icons over 10px
/// labels, brand for the active item and muted for the rest.
class DanaTabBar extends StatelessWidget {
  const DanaTabBar({
    super.key,
    required this.index,
    required this.onChanged,
    required this.items,
  });

  final int index;
  final ValueChanged<int> onChanged;

  /// (outline asset, filled asset, label) per destination.
  final List<(String, String, String)> items;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: DanaColors.navBorder)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              for (var i = 0; i < items.length; i++)
                GestureDetector(
                  onTap: () => onChanged(i),
                  behavior: HitTestBehavior.opaque,
                  child: SizedBox(
                    width: 64,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        DanaIcon(
                          i == index ? items[i].$2 : items[i].$1,
                          size: 24,
                          color: i == index ? DanaColors.brand : DanaColors.textMuted,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          items[i].$3,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            fontSize: 10,
                            fontWeight: FontWeight.w500,
                            letterSpacing: -0.2,
                            color: i == index ? DanaColors.brand : DanaColors.textMuted,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Small helper that loads once and renders loading / error / content.
class AsyncView extends StatefulWidget {
  const AsyncView({super.key, required this.load, required this.builder});

  final Future<Map<String, dynamic>> Function() load;
  final Widget Function(BuildContext, Map<String, dynamic>, VoidCallback reload) builder;

  @override
  State<AsyncView> createState() => _AsyncViewState();
}

class _AsyncViewState extends State<AsyncView> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.load();
  }

  // A block body, not an arrow: `() => _future = widget.load()` evaluates
  // to the assigned Future, and setState rejects a callback that returns
  // one — every pull-to-refresh threw instead of reloading.
  void _reload() {
    setState(() {
      _future = widget.load();
    });
  }

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return FutureBuilder<Map<String, dynamic>>(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator(color: DanaColors.brand));
        }

        if (snapshot.hasError) {
          final error = snapshot.error;
          final message = error is ApiError
              ? error.message(AppState.instance.language ?? 'tk')
              : l.t('connection_error');

          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.cloud_off, size: 40, color: DanaColors.textMuted),
                  const SizedBox(height: 12),
                  Text(message, textAlign: TextAlign.center),
                  const SizedBox(height: 16),
                  OutlinedButton(onPressed: _reload, child: Text(l.t('continue_'))),
                ],
              ),
            ),
          );
        }

        return widget.builder(context, snapshot.data ?? {}, _reload);
      },
    );
  }
}

/// Initials for an avatar circle. One letter for a single-word name, the
/// first and last otherwise.
String danaInitials(String name) {
  final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();

  if (parts.isEmpty) return '?';
  if (parts.length == 1) return parts.first.characters.first.toUpperCase();

  return (parts.first.characters.first + parts.last.characters.first).toUpperCase();
}

/// The brand banner every top-level screen sits under: filled, 24px
/// bottom corners, one centred title.
class DanaBrandHeader extends StatelessWidget {
  const DanaBrandHeader({super.key, required this.title});

  final String title;

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
          child: Text(
            title,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.w700,
              color: Colors.white,
              letterSpacing: -0.36,
            ),
          ),
        ),
      ),
    );
  }
}

/// The white Course Overview card that sits on the brand banner of Home
/// and Profile: level name, "13/36 Units completed", green ring.
class CourseOverviewCard extends StatelessWidget {
  const CourseOverviewCard({
    super.key,
    required this.level,
    required this.done,
    required this.total,
    required this.percent,
  });

  final String level;
  final int done;
  final int total;

  /// FR-13.9 level completion progress — coverage, drawn in green.
  final int percent;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: DanaColors.card,
        borderRadius: BorderRadius.circular(DanaRadius.card),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  l.t('course_overview').toUpperCase(),
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: DanaColors.textMuted,
                    letterSpacing: -0.22,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  level,
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
                    'n': done,
                    'm': total,
                    'w': l.plural('units_count', total),
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
          DanaProgressRing(percent: percent),
        ],
      ),
    );
  }
}

/// Ranking — Figma `leaderboard-screen`.
///
/// Averaged big scores (FR-13.9: level correctness % × 100), the top
/// three on the podium, everyone else in the list. The server pins the
/// student's own row separately, so a student outside the returned
/// window still sees where they stand.
class LeaderboardScreen extends StatelessWidget {
  const LeaderboardScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return Scaffold(
      backgroundColor: DanaColors.surface,
      body: Column(
        children: [
          DanaBrandHeader(title: l.t('ranking')),
          Expanded(
            child: AsyncView(
              load: () => Api.instance.get('/me/leaderboard'),
              builder: (context, data, reload) {
                final entries =
                    ((data['entries'] as List?) ?? []).cast<Map<String, dynamic>>();
                final me = data['me'] as Map<String, dynamic>?;

                final podium = entries.take(3).toList();
                final rest = entries.skip(3).toList();

                // Below the window the server returns: keep the student
                // visible by appending their pinned row.
                if (me != null &&
                    me['rank'] != null &&
                    !entries.any((e) => e['is_me'] == true)) {
                  rest.add(me);
                }

                return RefreshIndicator(
                  onRefresh: () async => reload(),
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
                    children: [
                      DanaInfoCard(text: l.t('leaderboard_hint')),
                      if (entries.isEmpty) ...[
                        const SizedBox(height: 40),
                        Text(l.t('no_ranking_yet'), textAlign: TextAlign.center),
                      ] else ...[
                        const SizedBox(height: 28),
                        DanaPodium(entries: podium),
                        const SizedBox(height: 28),
                        if (rest.isNotEmpty) DanaRankingList(entries: rest),
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
}

/// Second, first, third — in that order, aligned along their baselines so
/// the winner's larger avatar rises above the other two. Shared by the
/// student leaderboard and the teacher classroom Ranking tab, so both
/// podiums are the exact same component.
class DanaPodium extends StatelessWidget {
  const DanaPodium({super.key, required this.entries});

  final List<Map<String, dynamic>> entries;

  @override
  Widget build(BuildContext context) {
    Map<String, dynamic>? at(int i) => i < entries.length ? entries[i] : null;

    final second = at(1);
    final first = at(0);
    final third = at(2);

    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        if (second != null)
          _PodiumColumn(entry: second, place: 2, color: DanaColors.silver),
        if (second != null && first != null) const SizedBox(width: 16),
        if (first != null)
          _PodiumColumn(entry: first, place: 1, color: DanaColors.accent),
        if (third != null) ...[
          const SizedBox(width: 16),
          _PodiumColumn(entry: third, place: 3, color: DanaColors.bronze),
        ],
      ],
    );
  }
}

class _PodiumColumn extends StatelessWidget {
  const _PodiumColumn({
    required this.entry,
    required this.place,
    required this.color,
  });

  final Map<String, dynamic> entry;
  final int place;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final winner = place == 1;
    final avatar = winner ? 86.0 : 64.0;
    final name = entry['display_name'] as String? ?? '';

    return SizedBox(
      width: winner ? 110 : 100,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            width: avatar,
            height: avatar,
            child: Stack(
              clipBehavior: Clip.none,
              alignment: Alignment.center,
              children: [
                Container(
                  width: avatar,
                  height: avatar,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: DanaColors.card,
                    shape: BoxShape.circle,
                    border: Border.all(color: color, width: 2),
                  ),
                  child: Text(
                    danaInitials(name),
                    style: TextStyle(
                      fontSize: winner ? 24 : 20,
                      fontWeight: FontWeight.w600,
                      color: color,
                      letterSpacing: winner ? -0.48 : -0.4,
                    ),
                  ),
                ),
                // Straddles the avatar's bottom edge, as in the design.
                Positioned(
                  bottom: -9,
                  child: Container(
                    width: 24,
                    height: 18,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: color,
                      borderRadius: BorderRadius.circular(100),
                    ),
                    child: Text(
                      '$place',
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                        color: Colors.white,
                        letterSpacing: -0.22,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          SizedBox(height: winner ? 14 : 12),
          Text(
            name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: winner ? 14 : 13,
              fontWeight: FontWeight.w600,
              color: DanaColors.ink,
              letterSpacing: winner ? -0.28 : -0.26,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            danaFormatScore(entry['score'] as int? ?? 0),
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: winner ? 13 : 12,
              fontWeight: FontWeight.w600,
              // The frame greys the runner-up's score; only gold and
              // bronze reuse their ring colour.
              color: place == 2 ? DanaColors.textMuted : color,
              letterSpacing: winner ? -0.26 : -0.24,
            ),
          ),
        ],
      ),
    );
  }
}

class DanaRankingList extends StatelessWidget {
  const DanaRankingList({super.key, required this.entries});

  final List<Map<String, dynamic>> entries;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(DanaRadius.card),
        color: DanaColors.card,
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          for (var i = 0; i < entries.length; i++)
            Builder(builder: (context) {
              final entry = entries[i];
              final isMe = entry['is_me'] == true;
              final name = entry['display_name'] as String? ?? '';

              return Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 16),
                decoration: BoxDecoration(
                  color: DanaColors.card,
                  border: i == entries.length - 1
                      ? null
                      : const Border(bottom: BorderSide(color: DanaColors.border)),
                ),
                child: Row(
                  children: [
                    SizedBox(
                      width: 16,
                      child: Text(
                        '${entry['rank']}',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: DanaColors.textMuted,
                          letterSpacing: -0.26,
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Container(
                      width: 36,
                      height: 36,
                      alignment: Alignment.center,
                      decoration: const BoxDecoration(
                        color: DanaColors.surface,
                        shape: BoxShape.circle,
                      ),
                      child: Text(
                        danaInitials(name),
                        style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: DanaColors.textMuted,
                          letterSpacing: -0.26,
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Row(
                        children: [
                          Flexible(
                            child: Text(
                              name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontSize: 14,
                                fontWeight: FontWeight.w600,
                                color: DanaColors.ink,
                                letterSpacing: -0.28,
                              ),
                            ),
                          ),
                          // The frame's brand YOU pill after the name.
                          if (isMe) ...[
                            const SizedBox(width: 8),
                            Container(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(
                                color: DanaColors.brand,
                                borderRadius: BorderRadius.circular(100),
                              ),
                              child: Text(
                                l.t('you').toUpperCase(),
                                style: const TextStyle(
                                  fontSize: 10,
                                  fontWeight: FontWeight.w700,
                                  color: Colors.white,
                                  letterSpacing: 0.2,
                                ),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      danaFormatScore(entry['score'] as int? ?? 0),
                      style: const TextStyle(
                        fontSize: 14,
                        color: DanaColors.textMuted,
                        letterSpacing: -0.28,
                      ),
                    ),
                  ],
                ),
              );
            }),
        ],
      ),
    );
  }
}

/// Profile — Figma `profile-settings`.
///
/// The brand banner carries the identity block and the course card; the
/// settings list and the Logout card sit on the surface below. Teachers
/// get the same chrome without the course card, since `/me/outline` is a
/// student endpoint.
class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final user = AppState.instance.user ?? {};
    final isTeacher = AppState.instance.isTeacher;

    // The whole page scrolls, banner included.
    //
    // The scroll view sits *inside* the safe area rather than under it,
    // so the banner cannot ride up over the clock and battery on the way
    // out. The scaffold behind it is brand-coloured, which is what shows
    // in that strip — the same colour the banner starts in, so the top of
    // the screen looks unbroken.
    return Scaffold(
      backgroundColor: DanaColors.brand,
      body: SafeArea(
        bottom: false,
        child: ColoredBox(
          color: DanaColors.surface,
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              Container(
                decoration: const BoxDecoration(
                  color: DanaColors.brand,
                  borderRadius: BorderRadius.only(
                    bottomLeft: Radius.circular(DanaRadius.banner),
                    bottomRight: Radius.circular(DanaRadius.banner),
                  ),
                ),
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _identity(user),
                    if (!isTeacher) ...[
                      const SizedBox(height: 24),
                      const _ProfileCourseCard(),
                    ],
                  ],
                ),
              ),
              const Padding(
                padding: EdgeInsets.fromLTRB(20, 24, 20, 24),
                child: _SettingsSection(),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _identity(Map<String, dynamic> user) {
    final name = user['full_name'] as String? ?? '';

    return Row(
      children: [
        Container(
          width: 64,
          height: 64,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.1),
            shape: BoxShape.circle,
          ),
          child: Text(
            danaInitials(name),
            style: const TextStyle(
              fontSize: 20,
              fontWeight: FontWeight.w600,
              color: Colors.white,
              letterSpacing: -0.4,
            ),
          ),
        ),
        const SizedBox(width: 16),
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
              const SizedBox(height: 4),
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
    );
  }
}

/// The same Course Overview card Home shows, fed from the same outline.
class _ProfileCourseCard extends StatelessWidget {
  const _ProfileCourseCard();

  @override
  Widget build(BuildContext context) {
    return AsyncView(
      load: () => Api.instance.get('/me/outline'),
      builder: (context, data, reload) {
        var done = 0;
        var total = 0;

        for (final parent in ((data['parent_units'] as List?) ?? [])
            .cast<Map<String, dynamic>>()) {
          for (final child in ((parent['child_units'] as List?) ?? [])
              .cast<Map<String, dynamic>>()) {
            total++;
            if (child['state'] == 'completed') done++;
          }
        }

        return CourseOverviewCard(
          level: (data['level'] as Map<String, dynamic>?)?['name'] as String? ??
              '',
          done: done,
          total: total,
          percent: data['level_progress'] as int? ?? 0,
        );
      },
    );
  }
}

class _SettingsSection extends StatelessWidget {
  const _SettingsSection();

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final lang = AppState.instance.language ?? 'tk';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
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
        const SizedBox(height: 12),
        Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(DanaRadius.card),
            color: DanaColors.card,
          ),
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              DanaSettingsRow(
                icon: DanaIcons.globe,
                label: l.t('interface_language'),
                value: l.t('language_$lang'),
                onTap: () => danaPickLanguage(context),
              ),
              DanaSettingsRow(
                icon: DanaIcons.bell,
                label: l.t('notifications'),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const NotificationsScreen()),
                ),
              ),
              // FR pending (Q-55): there is no feedback channel in the
              // data model yet, so both rows point at the teacher rather
              // than inventing an address.
              DanaSettingsRow(
                icon: DanaIcons.messageText,
                label: l.t('feedback'),
                onTap: () =>
                    danaInfoSheet(context, l.t('feedback'), l.t('ask_your_teacher')),
              ),
              DanaSettingsRow(
                icon: DanaIcons.infoCircle,
                label: l.t('about_app'),
                last: true,
                onTap: () => danaInfoSheet(
                    context, l.t('about_app'), 'mydana · ${l.t('version')} 0.1.0'),
              ),
            ],
          ),
        ),
        const SizedBox(height: 24),
        // The frame's separate Logout card, confirmed by the dialog in
        // `profile-language-1` before anything is signed out.
        GestureDetector(
          onTap: () => danaConfirmLogout(context),
          behavior: HitTestBehavior.opaque,
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: DanaColors.card,
              borderRadius: BorderRadius.circular(DanaRadius.card),
            ),
            child: Row(
              children: [
                const DanaIcon(DanaIcons.logOut, color: DanaColors.danger),
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
        const SizedBox(height: 12),
        // Delete Account — Figma `profile-language`. The client's decision
        // (2026-08-14) is that this must NOT delete anything: purging a
        // student is the centre admin's action on course closure (FR-1.14).
        // Here the row only confirms and signs the student out.
        GestureDetector(
          onTap: () => danaConfirmDeleteAccount(context),
          behavior: HitTestBehavior.opaque,
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: DanaColors.card,
              borderRadius: BorderRadius.circular(DanaRadius.card),
            ),
            child: Row(
              children: [
                const DanaIcon(DanaIcons.trash, color: DanaColors.danger),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    l.t('delete_account'),
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
    );
  }

}

/// Figma `profile-language`: English / Turkmen / Russian with a check
/// on the active one (FR-13.22). Shared by the student Profile and the
/// teacher settings drawer.
Future<void> danaPickLanguage(BuildContext context) async {
  final l = AppState.instance.l;

    final chosen = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: DanaColors.card,
      barrierColor: const Color(0x4D000000),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      builder: (context) => SafeArea(
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
                      l.t('language'),
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
              const SizedBox(height: 8),
              for (final option in const ['en', 'tk', 'ru'])
                Container(
                  decoration: BoxDecoration(
                    border: option == 'ru'
                        ? null
                        : const Border(
                            bottom: BorderSide(color: DanaColors.border)),
                  ),
                  child: GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    onTap: () => Navigator.of(context).pop(option),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 16),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              l.t('language_$option'),
                              style: const TextStyle(
                                fontSize: 15,
                                color: DanaColors.ink,
                                letterSpacing: -0.3,
                              ),
                            ),
                          ),
                          if (AppState.instance.language == option)
                            const Icon(Icons.check,
                                size: 20, color: DanaColors.ink),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );

    if (chosen != null) AppState.instance.setLanguage(chosen);
  }

/// Figma `profile-language-1`: the centred Logout confirm dialog.
Future<void> danaConfirmLogout(BuildContext context) async {
  final l = AppState.instance.l;

    final confirmed = await showDialog<bool>(
      context: context,
      barrierColor: const Color(0x4D000000),
      builder: (context) => Dialog(
        backgroundColor: DanaColors.card,
        elevation: 0,
        insetPadding: const EdgeInsets.symmetric(horizontal: 32),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(28),
        ),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                l.t('log_out'),
                style: const TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w700,
                  color: DanaColors.brand,
                  letterSpacing: -0.44,
                ),
              ),
              const SizedBox(height: 12),
              Text(
                l.t('logout_body'),
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 14,
                  height: 1.45,
                  color: DanaColors.ink,
                  letterSpacing: -0.28,
                ),
              ),
              const SizedBox(height: 20),
              Row(
                children: [
                  Expanded(
                    child: GestureDetector(
                      onTap: () => Navigator.of(context).pop(false),
                      child: Container(
                        height: 44,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: const Color(0xFFF7F7F7),
                          borderRadius: BorderRadius.circular(DanaRadius.field),
                        ),
                        child: Text(
                          l.t('cancel'),
                          style: const TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.w600,
                            color: DanaColors.ink,
                            letterSpacing: -0.28,
                          ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: GestureDetector(
                      onTap: () => Navigator.of(context).pop(true),
                      child: Container(
                        height: 44,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: DanaColors.danger,
                          borderRadius: BorderRadius.circular(DanaRadius.field),
                        ),
                        child: Text(
                          l.t('log_out'),
                          style: const TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.w600,
                            color: Colors.white,
                            letterSpacing: -0.28,
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );

    if (confirmed == true) await AppState.instance.signOut();
  }

/// Figma `profile-language`: the Delete Account confirm dialog. By the
/// client's decision (2026-08-14) confirming does NOT delete the account
/// — a student is only ever purged by the centre admin on course closure
/// (FR-1.14). Confirming simply signs the student out, exactly like the
/// Logout dialog above; only the copy and the red button label differ.
Future<void> danaConfirmDeleteAccount(BuildContext context) async {
  final l = AppState.instance.l;

    final confirmed = await showDialog<bool>(
      context: context,
      barrierColor: const Color(0x4D000000),
      builder: (context) => Dialog(
        backgroundColor: DanaColors.card,
        elevation: 0,
        insetPadding: const EdgeInsets.symmetric(horizontal: 32),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(28),
        ),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                l.t('delete_account'),
                style: const TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w700,
                  color: DanaColors.brand,
                  letterSpacing: -0.44,
                ),
              ),
              const SizedBox(height: 12),
              Text(
                l.t('delete_account_body'),
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 14,
                  height: 1.45,
                  color: DanaColors.ink,
                  letterSpacing: -0.28,
                ),
              ),
              const SizedBox(height: 20),
              Row(
                children: [
                  Expanded(
                    child: GestureDetector(
                      onTap: () => Navigator.of(context).pop(false),
                      child: Container(
                        height: 44,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: const Color(0xFFF7F7F7),
                          borderRadius: BorderRadius.circular(DanaRadius.field),
                        ),
                        child: Text(
                          l.t('cancel'),
                          style: const TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.w600,
                            color: DanaColors.ink,
                            letterSpacing: -0.28,
                          ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: GestureDetector(
                      onTap: () => Navigator.of(context).pop(true),
                      child: Container(
                        height: 44,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: DanaColors.danger,
                          borderRadius: BorderRadius.circular(DanaRadius.field),
                        ),
                        child: Text(
                          l.t('delete'),
                          style: const TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.w600,
                            color: Colors.white,
                            letterSpacing: -0.28,
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );

    if (confirmed == true) await AppState.instance.signOut();
  }

Future<void> danaInfoSheet(BuildContext context, String title, String body) {
  final l = AppState.instance.l;

    return showModalBottomSheet<void>(
      context: context,
      backgroundColor: DanaColors.card,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: const TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.w700,
                  color: DanaColors.ink,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                body,
                style: const TextStyle(
                  fontSize: 14,
                  height: 1.4,
                  color: DanaColors.textMuted,
                ),
              ),
              const SizedBox(height: 20),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton(
                  onPressed: () => Navigator.of(context).pop(),
                  child: Text(l.t('close')),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

class DanaSettingsRow extends StatelessWidget {
  const DanaSettingsRow({
    super.key,
    required this.icon,
    required this.label,
    this.value,
    this.onTap,
    this.last = false,
  });

  final String icon;
  final String label;
  final String? value;
  final VoidCallback? onTap;
  final bool last;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: DanaColors.card,
          border: last
              ? null
              : const Border(bottom: BorderSide(color: DanaColors.border)),
        ),
        child: Row(
          children: [
            DanaIcon(icon, color: DanaColors.ink),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: DanaColors.ink,
                  letterSpacing: -0.28,
                ),
              ),
            ),
            if (value != null) ...[
              Text(
                value!,
                style: const TextStyle(
                  fontSize: 13,
                  color: DanaColors.textMuted,
                  letterSpacing: -0.26,
                ),
              ),
              const SizedBox(width: 4),
            ],
            const DanaIcon(DanaIcons.chevronRight),
          ],
        ),
      ),
    );
  }
}
