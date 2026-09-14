import 'dart:async';

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
///
/// **Built for latency, because that is what the client heard.** The
/// first version called `play(AssetSource(...))` on one shared player,
/// which on every single tap did a stop round-trip to the platform, then
/// resolved the asset out of the bundle, then prepared a decoder, and
/// only then made a sound — audibly behind the verdict sheet that had
/// already slid up. So instead:
///
///  - one player per clip, never re-pointed at a different file;
///  - each one's source is set ONCE during [warmUp], so the copy out of
///    the bundle and the decoder setup are paid for at launch;
///  - `lowLatency` mode, which the package documents as "ideal for short
///    audio files" — it gives up duration, position and completion
///    events, none of which this uses;
///  - firing is a bare `resume()` with nothing awaited before it.
///
/// Every entry point is fire-and-forget: a clip that fails to load is
/// silence, never an error on top of an exercise, and nothing here is
/// ever awaited by the UI.
class Sfx {
  Sfx._();

  static final Sfx instance = Sfx._();

  static const _correct = 'sounds/correct.mp3';
  static const _incorrect = 'sounds/incorrect.mp3';
  static const _completeGood = 'sounds/complete_good.mp3';
  static const _completePass = 'sounds/complete_pass.mp3';
  static const _completeLow = 'sounds/complete_low.mp3';

  static const _all = [
    _correct,
    _incorrect,
    _completeGood,
    _completePass,
    _completeLow,
  ];

  final Map<String, AudioPlayer> _players = {};

  /// Bumped on every clip. A screen keeps the handle it was given and
  /// hands it back to [stopOwn], so it can only ever silence its OWN
  /// sound — see that method for why this is not optional.
  int _epoch = 0;

  /// The clip currently sounding, so the next one can stop just that.
  String? _sounding;

  /// Loads and prepares all five clips. Called once at launch; safe to
  /// call again, and safe to never call — a clip that finds no prepared
  /// player falls back to loading itself, just more slowly.
  Future<void> warmUp() async {
    for (final asset in _all) {
      if (_players.containsKey(asset)) continue;

      try {
        final player = AudioPlayer();
        await player.setPlayerMode(PlayerMode.lowLatency);
        await player.setReleaseMode(ReleaseMode.stop);
        await player.setSource(AssetSource(asset));
        _players[asset] = player;
      } catch (e) {
        assert(() {
          debugPrint('Sfx: could not prepare assets/$asset — $e');
          return true;
        }());
      }
    }
  }

  /// Answered correctly.
  int correct() => _play(_correct);

  /// Answered wrongly. Also fires on a re-queued retry (FR-15.13) —
  /// the sound is feedback on the answer just given, not on the score.
  int incorrect() => _play(_incorrect);

  /// The results screen, chosen by the SAME thresholds that screen uses
  /// for its artwork and copy (good ≥ 80, passed ≥ 50, below that a
  /// fail — see ExerciseEndScreen). A student who scores 30 hears the
  /// "not this time" clip, not the fanfare: the screen already tells
  /// them they did not pass, and a celebration over it would read as
  /// mockery.
  int completed(int percent) => _play(switch (percent) {
        >= 80 => _completeGood,
        >= 50 => _completePass,
        _ => _completeLow,
      });

  /// Cuts whatever is sounding (FR-15.20, client 2026-09-14: «sound
  /// should stop immediately after closing that page or moving to
  /// next page»). A three-second chime outliving the screen that caused
  /// it is the complaint.
  ///
  /// Unconditional: for the app going to the background, where nothing
  /// should be sounding at all. A SCREEN should use [stopOwn].
  Future<void> stop() async {
    _epoch++;
    await _silence();
  }

  /// Stops only if [handle] is still the clip playing.
  ///
  /// This exists because of one specific ordering. `pushReplacement`
  /// from the exercise screen to the result screen builds the new route
  /// and runs its `initState` — starting the result clip — and disposes
  /// the OLD route only after the transition finishes, a few hundred
  /// milliseconds later. Without this check, that dispose would reach
  /// out and cut off a sound belonging to the screen that replaced it, a
  /// third of a second in. Holding a handle makes "stop my sound" mean
  /// exactly that, whatever order the framework tears things down in.
  Future<void> stopOwn(int handle) async {
    if (handle == _epoch) await stop();
  }

  /// Fires [asset] and silences whatever was sounding.
  ///
  /// Nothing is awaited before the sound starts: the whole point is that
  /// it lands with the verdict sheet, not after a round trip.
  int _play(String asset) {
    final handle = ++_epoch;
    final previous = _sounding;
    _sounding = asset;

    // Stop the OTHER clip, not this one — stopping the player we are
    // about to start would put the round trip back in front of the
    // sound. Replaying the same clip restarts it by itself.
    if (previous != null && previous != asset) {
      unawaited(_stopPlayer(previous));
    }

    final player = _players[asset];

    if (player != null) {
      unawaited(_resume(player, asset));
    } else {
      // Not warmed up (warmUp still running, or it failed for this one):
      // the slow path still makes the sound.
      unawaited(_playCold(asset, handle));
    }

    return handle;
  }

  Future<void> _resume(AudioPlayer player, String asset) async {
    try {
      await player.resume();
    } catch (e) {
      assert(() {
        debugPrint('Sfx: could not play assets/$asset — $e');
        return true;
      }());
    }
  }

  Future<void> _playCold(String asset, int handle) async {
    try {
      final player = AudioPlayer();
      await player.setPlayerMode(PlayerMode.lowLatency);
      await player.setReleaseMode(ReleaseMode.stop);
      await player.setSource(AssetSource(asset));
      _players[asset] = player;

      // Superseded while that was loading — a newer clip, or a stop.
      if (handle != _epoch) return;

      await player.resume();
    } catch (e) {
      assert(() {
        debugPrint('Sfx: could not play assets/$asset — $e');
        return true;
      }());
    }
  }

  Future<void> _silence() async {
    final asset = _sounding;
    _sounding = null;

    if (asset != null) await _stopPlayer(asset);
  }

  Future<void> _stopPlayer(String asset) async {
    try {
      await _players[asset]?.stop();
    } catch (_) {}
  }
}
