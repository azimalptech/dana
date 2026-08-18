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
      } on ApiError {
        // Token no longer valid — fall back to the login screen rather
        // than showing a broken shell.
        user = null;
      }
    }

    ready = true;
    notifyListeners();
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
    notifyListeners();
  }

  Future<void> signOut() async {
    await Api.instance.logout();
    user = null;
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
