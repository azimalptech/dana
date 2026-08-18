import 'dart:async';

import 'package:flutter/widgets.dart';

import 'api.dart';

/// FR-8.11: study time is accumulated **active foreground seconds** on
/// exercise, grammar and vocabulary screens.
///
/// Counting wall-clock time between screen open and close would credit a
/// student who opened grammar and left the phone on the table overnight.
/// So the tracker:
///   - only runs while a study screen is on top,
///   - stops the moment the app is backgrounded,
///   - reports in small increments rather than one total at the end, so
///     a force-quit loses seconds rather than the whole session.
///
/// The server clamps each report and the daily total anyway — this is a
/// convenience, not a source of truth.
class StudyTracker with WidgetsBindingObserver {
  StudyTracker._() {
    WidgetsBinding.instance.addObserver(this);
  }

  static final StudyTracker instance = StudyTracker._();

  static const _interval = Duration(seconds: 30);

  int _activeScreens = 0;
  Timer? _timer;
  DateTime? _since;

  /// Call when a study screen appears.
  void enter() {
    _activeScreens++;
    _resume();
  }

  /// Call when a study screen is dismissed.
  void leave() {
    _activeScreens = (_activeScreens - 1).clamp(0, 1 << 30);

    if (_activeScreens == 0) {
      _flush();
      _pause();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      if (_activeScreens > 0) _resume();
    } else {
      // Backgrounded or inactive — bank what was earned and stop.
      _flush();
      _pause();
    }
  }

  void _resume() {
    _since ??= DateTime.now();
    _timer ??= Timer.periodic(_interval, (_) => _flush());
  }

  void _pause() {
    _timer?.cancel();
    _timer = null;
    _since = null;
  }

  void _flush() {
    final since = _since;
    if (since == null) return;

    final seconds = DateTime.now().difference(since).inSeconds;
    _since = DateTime.now();

    if (seconds <= 0) return;

    // Fire and forget: a dropped heartbeat costs a few seconds of
    // credit, never a visible error.
    Api.instance.post('/me/heartbeat', body: {'seconds': seconds}).catchError(
      (_) => <String, dynamic>{},
    );
  }
}

/// Mixin for any screen whose time on display counts as studying.
mixin StudyTimeAware<T extends StatefulWidget> on State<T> {
  @override
  void initState() {
    super.initState();
    StudyTracker.instance.enter();
  }

  @override
  void dispose() {
    StudyTracker.instance.leave();
    super.dispose();
  }
}
