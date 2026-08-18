import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// FR-12.9: exercises need a connection, but grammar and vocabulary for
/// unlocked sections stay readable offline.
///
/// Only content the student is already entitled to is cached. Nothing
/// here bypasses an access check — the server decided what to send, and
/// this only remembers the answer.
class ContentCache {
  ContentCache._();

  static const _prefix = 'cache:';

  /// Cached content older than this is still served offline, but the app
  /// prefers the network. Content changes rarely, so there is no reason
  /// to expire it aggressively and leave a student with a blank screen.
  static const maxAge = Duration(days: 30);

  static Future<void> save(String key, Map<String, dynamic> value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      '$_prefix$key',
      jsonEncode({
        'saved_at': DateTime.now().toIso8601String(),
        'data': value,
      }),
    );
  }

  static Future<Map<String, dynamic>?> read(String key) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString('$_prefix$key');

    if (raw == null) return null;

    try {
      final decoded = jsonDecode(raw) as Map<String, dynamic>;
      final savedAt = DateTime.tryParse(decoded['saved_at'] as String? ?? '');

      if (savedAt != null && DateTime.now().difference(savedAt) > maxAge) {
        await prefs.remove('$_prefix$key');
        return null;
      }

      return (decoded['data'] as Map).cast<String, dynamic>();
    } catch (_) {
      // A corrupt entry is not worth crashing over — drop it and refetch.
      await prefs.remove('$_prefix$key');
      return null;
    }
  }

  /// Called on sign-out: one student's cached content must not be
  /// readable by whoever logs in next on a shared phone.
  static Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    final keys = prefs.getKeys().where((k) => k.startsWith(_prefix)).toList();

    for (final key in keys) {
      await prefs.remove(key);
    }
  }
}
