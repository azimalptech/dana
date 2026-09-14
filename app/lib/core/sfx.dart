import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/foundation.dart';

/// The interface's own sounds: right, wrong, and the result screen
/// (FR-15.20).
///
/// Deliberately NOT [AudioBus]. That bus owns one player for question
/// media and stops whatever is sounding on every new clip, so sharing it
/// would mean a "correct" chime cutting off a listening exercise the
/// student is still hearing — and would drive the `TAP TO LISTEN` tile
/// into the wrong state, because the tile mirrors that bus's notifiers.
/// These are a separate player with no notifiers at all.
///
/// Every entry point is fire-and-forget: an effect that fails to load is
/// silence, never an error on top of an exercise. Nothing here is ever
/// awaited by the UI, so a slow decode cannot delay the verdict sheet.
class Sfx {
  Sfx._();

  static final Sfx instance = Sfx._();

  final AudioPlayer _player = AudioPlayer();

  /// Answered correctly.
  void correct() => _play('sounds/correct.mp3');

  /// Answered wrongly. Also fires on a re-queued retry (FR-15.13) —
  /// the sound is feedback on the answer just given, not on the score.
  void incorrect() => _play('sounds/incorrect.mp3');

  /// The results screen, chosen by the SAME thresholds that screen uses
  /// for its artwork and copy (good ≥ 80, passed ≥ 50, below that a
  /// fail — see ExerciseEndScreen). A student who scores 30 hears the
  /// "not this time" clip, not the fanfare: the screen already tells
  /// them they did not pass, and a celebration over it would read as
  /// mockery.
  void completed(int percent) => _play(switch (percent) {
        >= 80 => 'sounds/complete_good.mp3',
        >= 50 => 'sounds/complete_pass.mp3',
        _ => 'sounds/complete_low.mp3',
      });

  /// Starting a clip stops the previous one. The "correct" clip runs
  /// about three seconds and a student can tap Continue well before it
  /// ends, so without this the next verdict would sound over the top of
  /// the last one.
  Future<void> _play(String asset) async {
    try {
      await _player.stop();
      await _player.play(AssetSource(asset));
    } catch (e) {
      // Silence is an acceptable outcome for a sound effect in a
      // student's hands — but NOT while building the thing. Swallowing
      // everything would make a mistyped asset path indistinguishable
      // from a working app on a muted phone, and the mistake would ship.
      assert(() {
        debugPrint('Sfx: could not play assets/$asset — $e');
        return true;
      }());
    }
  }
}
