import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'cache.dart';

/// A failure the user should see. The server sends its languages
/// already (FR-4.14), so nothing is translated on the device.
class ApiError implements Exception {
  ApiError(this.code, this.messageTk, this.messageRu, this.status,
      {this.messageEn});

  final String code;
  final String messageTk;
  final String messageRu;
  final int status;

  /// FR-13.22 made English the third interface language, but the API's
  /// error envelope predates it — `message_en` is optional and the
  /// English UI falls back to Turkmen when it is absent.
  final String? messageEn;

  String message(String lang) => switch (lang) {
        'ru' => messageRu,
        'en' => messageEn ?? messageTk,
        _ => messageTk,
      };

  static ApiError network() => ApiError(
        'network',
        'Birikme ýok. Internetiňizi barlaň.',
        'Нет соединения. Проверьте интернет.',
        0,
        messageEn: 'No connection. Check your internet.',
      );

  @override
  String toString() => 'ApiError($code)';
}

/// Talks to the Dana API.
///
/// The base URL is a build-time define, never hardcoded (NFR-4):
///
///   flutter run --dart-define=API_BASE=http://127.0.0.1:8080/api/v1
///
/// The default suits a USB-connected device with
/// `adb reverse tcp:8080 tcp:8080`, which needs no firewall change and
/// works regardless of which Wi-Fi the phone is on.
class Api {
  Api._();

  static final Api instance = Api._();

  static const baseUrl = String.fromEnvironment(
    'API_BASE',
    defaultValue: 'http://127.0.0.1:8080/api/v1',
  );

  String? _accessToken;
  String? _refreshToken;

  String? get accessToken => _accessToken;
  bool get isSignedIn => _accessToken != null;

  Future<void> restore() async {
    final prefs = await SharedPreferences.getInstance();
    _accessToken = prefs.getString('access_token');
    _refreshToken = prefs.getString('refresh_token');
  }

  Future<void> _persist() async {
    final prefs = await SharedPreferences.getInstance();
    if (_accessToken == null) {
      await prefs.remove('access_token');
      await prefs.remove('refresh_token');
    } else {
      await prefs.setString('access_token', _accessToken!);
      await prefs.setString('refresh_token', _refreshToken ?? '');
    }
  }

  Future<Map<String, dynamic>> login(String login, String password) async {
    final body = await _send(
      'POST',
      '/auth/login',
      body: {'login': login, 'password': password},
      authenticated: false,
    );

    _accessToken = body['access_token'] as String?;
    _refreshToken = body['refresh_token'] as String?;
    await _persist();

    // Clearing on logout is not enough: if the app was killed without
    // signing out, cached content from the previous student would still
    // be on disk. Phones get shared in a classroom, so clear on the way
    // in as well.
    await ContentCache.clear();

    return body['user'] as Map<String, dynamic>;
  }

  Future<void> logout() async {
    if (_refreshToken != null) {
      try {
        await _send('POST', '/auth/logout',
            body: {'refresh_token': _refreshToken}, authenticated: false);
      } on ApiError {
        // Signing out locally matters more than telling the server.
      }
    }
    _accessToken = null;
    _refreshToken = null;
    await _persist();
    // A shared phone must not leak one student's content to the next.
    await ContentCache.clear();
  }

  Future<Map<String, dynamic>> get(String path) => _send('GET', path);

  /// Resolves a media reference from a content-v2 payload against the
  /// API base. The contract serves media at `/media/{name}` under
  /// `/api/v1` (docs/06-CONTENT-V2.md §2) but the payload may carry the
  /// path with or without the `/api/v1` prefix, or a full URL — all
  /// three resolve to the same request.
  static String mediaUrl(String ref) {
    if (ref.startsWith('http://') || ref.startsWith('https://')) return ref;

    final base = Uri.parse(baseUrl);
    if (ref.startsWith('/api/')) {
      return '${base.scheme}://${base.authority}$ref';
    }
    return ref.startsWith('/') ? '$baseUrl$ref' : '$baseUrl/$ref';
  }

  /// Raw authenticated GET — media streaming (audio, images). The media
  /// route requires the Bearer header like every other route; an expired
  /// access token is refreshed once and the request replayed, exactly as
  /// [_send] does for JSON.
  Future<List<int>> getBytes(String url, {bool allowRetry = true}) async {
    final uri = Uri.parse(mediaUrl(url));

    http.Response response;
    try {
      response = await http.get(uri, headers: {
        if (_accessToken != null) 'Authorization': 'Bearer $_accessToken',
      }).timeout(const Duration(seconds: 30));
    } catch (_) {
      throw ApiError.network();
    }

    if (response.statusCode == 401 && allowRetry && _refreshToken != null) {
      if (await _refresh()) return getBytes(url, allowRetry: false);
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return response.bodyBytes;
    }

    throw ApiError(
      'media',
      'Faýl ýüklenmedi.',
      'Не удалось загрузить файл.',
      response.statusCode,
      messageEn: 'Could not load the file.',
    );
  }

  /// FR-12.9: grammar and vocabulary stay readable without a connection.
  ///
  /// Network first — a teacher may have corrected the content — then the
  /// cached copy if the request fails for connectivity reasons. A 403 or
  /// 404 is NOT served from cache: if the server has revoked access, a
  /// stale local copy must not paper over it.
  Future<Map<String, dynamic>> getCached(String path) async {
    try {
      final body = await _send('GET', path);
      await ContentCache.save(path, body);
      return body;
    } on ApiError catch (e) {
      if (e.status != 0) rethrow;

      final cached = await ContentCache.read(path);
      if (cached != null) return cached;

      rethrow;
    }
  }

  Future<Map<String, dynamic>> post(String path, {Map<String, dynamic>? body}) =>
      _send('POST', path, body: body);

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    bool authenticated = true,
    bool allowRetry = true,
  }) async {
    final uri = Uri.parse('$baseUrl$path');
    final headers = <String, String>{
      'Content-Type': 'application/json; charset=utf-8',
      if (authenticated && _accessToken != null) 'Authorization': 'Bearer $_accessToken',
    };

    http.Response response;
    try {
      response = method == 'GET'
          ? await http.get(uri, headers: headers).timeout(const Duration(seconds: 20))
          : await http
              .post(uri, headers: headers, body: jsonEncode(body ?? {}))
              .timeout(const Duration(seconds: 20));
    } catch (_) {
      throw ApiError.network();
    }

    // A healthy API always answers JSON, but a crashed PHP process or a
    // proxy error page answers HTML. Decoding that outside a guard threw
    // a FormatException/TypeError straight past every `on ApiError`
    // handler — sticking spinners and losing the buffered attempt. Treat
    // a non-JSON body as a server error the same handlers can catch.
    Map<String, dynamic> decoded;
    try {
      decoded = response.body.isEmpty
          ? <String, dynamic>{}
          : jsonDecode(utf8.decode(response.bodyBytes)) as Map<String, dynamic>;
    } catch (_) {
      throw ApiError(
        'bad_response',
        'Serwer garaşylmadyk jogap berdi.',
        'Сервер вернул неожиданный ответ.',
        response.statusCode,
        messageEn: 'The server returned an unexpected response.',
      );
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return decoded;
    }

    // An expired access token is normal every 15 minutes. Refresh once
    // and replay, so the user never sees it.
    if (response.statusCode == 401 && authenticated && allowRetry && _refreshToken != null) {
      if (await _refresh()) {
        return _send(method, path, body: body, authenticated: true, allowRetry: false);
      }
    }

    final error = decoded['error'] as Map<String, dynamic>?;
    throw ApiError(
      error?['code'] as String? ?? 'error',
      error?['message_tk'] as String? ?? 'Ýalňyşlyk ýüze çykdy.',
      error?['message_ru'] as String? ?? 'Произошла ошибка.',
      response.statusCode,
      messageEn: error?['message_en'] as String?,
    );
  }

  /// In flight while a refresh is running, so concurrent 401s (a UI call
  /// racing the 30s heartbeat) share ONE rotation instead of each
  /// spending the single-use token. Without this the loser would present
  /// an already-rotated token — which the server now treats as theft and
  /// revokes the whole session for — logging the student out mid-exercise.
  Future<bool>? _refreshing;

  Future<bool> _refresh() {
    return _refreshing ??= _doRefresh().whenComplete(() => _refreshing = null);
  }

  Future<bool> _doRefresh() async {
    try {
      final body = await _send(
        'POST',
        '/auth/refresh',
        body: {'refresh_token': _refreshToken},
        authenticated: false,
      );
      _accessToken = body['access_token'] as String?;
      _refreshToken = body['refresh_token'] as String?;
      await _persist();
      return true;
    } on ApiError {
      _accessToken = null;
      _refreshToken = null;
      await _persist();
      return false;
    }
  }
}
