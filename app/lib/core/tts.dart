import 'package:flutter_tts/flutter_tts.dart';

/// Pronunciation through the device's own text-to-speech service
/// (FR-13.24). No recordings ship with the app and no network is
/// involved: whatever engine, voice and speech rate the user configured
/// in Android's system settings is what speaks.
///
/// The content language is English, so an English voice is requested
/// once up front; if the system engine has none installed the engine's
/// default voice reads the word instead — imperfect, but never silent.
class Speech {
  Speech._();

  static final Speech instance = Speech._();

  final FlutterTts _tts = FlutterTts();
  bool _configured = false;

  Future<void> _configure() async {
    if (_configured) return;
    _configured = true;

    try {
      if (await _tts.isLanguageAvailable('en-US') == true) {
        await _tts.setLanguage('en-US');
      }
    } catch (_) {
      // Engine not ready or missing — speak() will still try, and the
      // button stays harmless on devices with no TTS at all.
    }
  }

  /// Reads [text] aloud, cutting off anything still being spoken so
  /// rapid taps re-start the word rather than queueing it.
  Future<void> speak(String text) async {
    if (text.trim().isEmpty) return;

    await _configure();
    try {
      await _tts.stop();
      await _tts.speak(text);
    } catch (_) {
      // A missing engine is the user's device configuration, not an
      // app failure worth an error dialog on a dictionary card.
    }
  }
}
