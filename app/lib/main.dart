import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'core/api.dart';
import 'core/l10n.dart';
import 'core/theme.dart';
import 'screens/auth_screens.dart';
import 'screens/shell.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // Portrait only. Every frame in the design is a portrait phone, and the
  // exercise screens (letter boxes, word banks, the keyboard) are laid out
  // for that width — landscape would reflow them into something nobody
  // designed. Locked here as well as in the Android manifest so the
  // orientation cannot change even mid-session.
  SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  runApp(const DanaApp());
}

/// App-wide state. Deliberately a single ChangeNotifier rather than a
/// state-management package — the app has one user, one language and one
/// session, and adding a framework for that would be ceremony.
class AppState extends ChangeNotifier {
  static final AppState instance = AppState();

  static const _supported = {'tk', 'ru', 'en'};

  String? language;
  Map<String, dynamic>? user;
  bool ready = false;

  L get l => L(language ?? 'tk');
  bool get isTeacher => user?['role'] == 'teacher';

  Future<void> boot() async {
    // The server can end a session under the app's feet — the account
    // disabled, or FR-12.2 signing this student out because they logged
    // in on another phone. Show the login screen when that happens
    // instead of a shell whose every tap fails (FR-15.15).
    Api.instance.onSessionEnded = () {
      if (user == null) return;
      user = null;
      SharedPreferences.getInstance().then((prefs) => _cacheUser(prefs, null));
      notifyListeners();
    };

    final prefs = await SharedPreferences.getInstance();
    language = prefs.getString('language');
    // FR-13.25: a system-language change made while the app was closed
    // is applied on this launch, same rule as the live path.
    await syncSystemLocale(
        WidgetsBinding.instance.platformDispatcher.locale.languageCode);
    await Api.instance.restore();

    if (Api.instance.isSignedIn) {
      try {
        final body = await Api.instance.get('/auth/me');
        user = body['user'] as Map<String, dynamic>?;
        await _cacheUser(prefs, user);
      } on ApiError catch (e) {
        if (e.status == 401 || e.status == 403) {
          // The session was REJECTED — the account is gone, disabled, or
          // signed in elsewhere. The login screen is right.
          user = null;
          await _cacheUser(prefs, null);
        } else {
          // The server was merely unreachable: no signal in the corridor,
          // or a redeploy in progress. The session is untouched, so open
          // on the last known profile instead of demanding the password
          // again (FR-15.15). Grammar and vocabulary are already cached
          // for exactly this case (FR-12.9); anything that needs the
          // network will say so where it is used.
          user = _cachedUser(prefs);
        }
      }
    }

    ready = true;
    notifyListeners();
  }

  static Map<String, dynamic>? _cachedUser(SharedPreferences prefs) {
    final raw = prefs.getString('cached_user');
    if (raw == null || raw.isEmpty) return null;

    try {
      return jsonDecode(raw) as Map<String, dynamic>;
    } catch (_) {
      return null;
    }
  }

  static Future<void> _cacheUser(
      SharedPreferences prefs, Map<String, dynamic>? value) async {
    if (value == null) {
      await prefs.remove('cached_user');
    } else {
      await prefs.setString('cached_user', jsonEncode(value));
    }
  }

  Future<void> setLanguage(String value) async {
    language = value;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('language', value);
    notifyListeners();
  }

  /// FR-13.25: the interface follows the phone's system language.
  ///
  /// "Changed" is judged against the last system locale this app saw —
  /// not against the current in-app choice — so picking Russian in-app
  /// on an English phone sticks, but actually switching the phone's
  /// language re-applies here, live or on next launch. Unsupported
  /// system languages change nothing, and until the first-run picker
  /// has been answered we still ask, never guess (FR-12.13).
  Future<void> syncSystemLocale(String sys) async {
    final prefs = await SharedPreferences.getInstance();
    final seen = prefs.getString('system_locale_seen');
    await prefs.setString('system_locale_seen', sys);

    // No baseline yet (first launch on this build) only RECORDS the
    // system locale — adopting here would flip a manual in-app choice
    // on update, with no actual system change behind it.
    if (seen != null &&
        seen != sys &&
        language != null &&
        language != sys &&
        _supported.contains(sys)) {
      await setLanguage(sys);
    }
  }

  void setUser(Map<String, dynamic>? value) {
    user = value;
    // Kept for the offline launch in [boot]. Not awaited: signing in must
    // not wait on a disk write.
    SharedPreferences.getInstance().then((prefs) => _cacheUser(prefs, value));
    notifyListeners();
  }

  Future<void> signOut() async {
    await Api.instance.logout();
    user = null;
    // A shared classroom phone must not open on the last student's name.
    await _cacheUser(await SharedPreferences.getInstance(), null);
    notifyListeners();
  }
}

class DanaApp extends StatefulWidget {
  const DanaApp({super.key});

  @override
  State<DanaApp> createState() => _DanaAppState();
}

class _DanaAppState extends State<DanaApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    AppState.instance.boot();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// FR-13.25: changing the phone's system language re-applies to the
  /// app immediately — no restart needed.
  @override
  void didChangeLocales(List<Locale>? locales) {
    if (locales != null && locales.isNotEmpty) {
      AppState.instance.syncSystemLocale(locales.first.languageCode);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: AppState.instance,
      builder: (context, _) {
        final state = AppState.instance;

        Widget home;
        if (!state.ready) {
          home = const SplashScreen();
        } else if (state.language == null) {
          // FR-12.13: ask, never guess.
          home = const LanguagePickerScreen();
        } else if (state.user == null) {
          home = const LoginScreen();
        } else {
          home = const AppShell();
        }

        return MaterialApp(
          title: 'Dana',
          debugShowCheckedModeBanner: false,
          theme: buildDanaTheme(),
          home: home,
        );
      },
    );
  }
}

/// Figma `splash-screen` (redesign 2026-08): the mydana* wordmark alone
/// on brand — cream letters, amber asterisk. The PNG blends into the
/// brand screen behind it.
class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: DanaColors.brand,
      body: Center(
        child: Image(
          image: AssetImage('assets/brand/logo.png'),
          width: 240,
        ),
      ),
    );
  }
}
