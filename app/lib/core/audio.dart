import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/foundation.dart';

import 'api.dart';

/// Plays content-v2 question audio.
///
/// The media route needs the Bearer header like every other route
/// (docs/06-CONTENT-V2.md §2), and audioplayers' `UrlSource` cannot carry
/// one — so the bytes are fetched through [Api.getBytes] (which adds the
/// header and refreshes an expired token) and handed to the player from
/// memory via `BytesSource`. No temp file, no path_provider.
///
/// One player, one clip at a time: starting a new clip stops the previous
/// one, so tapping option B's speaker cuts off option A. [playing] carries
/// the url currently sounding and [loading] the url whose bytes are still
/// downloading, so every tile mirrors the state through a
/// [ValueListenableBuilder] without owning a player of its own.
class AudioBus {
  AudioBus._() {
    // The clip finished on its own — drop back to idle so the waveform
    // tile reverts to "TAP TO LISTEN".
    _player.onPlayerComplete.listen((_) {
      if (playing.value != null) playing.value = null;
    });
  }

  static final AudioBus instance = AudioBus._();

  final AudioPlayer _player = AudioPlayer();

  /// The url currently playing, or null when silent.
  final ValueNotifier<String?> playing = ValueNotifier<String?>(null);

  /// The url whose bytes are downloading right now (distinct from
  /// [playing] so a tile can show a spinner before the sound starts).
  final ValueNotifier<String?> loading = ValueNotifier<String?>(null);

  /// Bumped on every new request so a slow download that lands after a
  /// newer tap is discarded instead of playing over it.
  int _epoch = 0;

  /// Plays [url] from the start, stopping whatever was sounding — so a tap
  /// while it already plays replays it, and a tap on another clip switches.
  Future<void> play(String url) async {
    final epoch = ++_epoch;
    try {
      await _player.stop();
    } catch (_) {}
    playing.value = null;
    loading.value = url;

    List<int> bytes;
    try {
      bytes = await Api.instance.getBytes(url);
    } catch (_) {
      // A clip that will not load is silent, never a crash or an error
      // dialog on top of the exercise.
      if (epoch == _epoch) loading.value = null;
      return;
    }

    if (epoch != _epoch) return; // superseded by a newer tap
    loading.value = null;

    try {
      await _player.play(BytesSource(Uint8List.fromList(bytes)));
      if (epoch == _epoch) playing.value = url;
    } catch (_) {
      if (epoch == _epoch) playing.value = null;
    }
  }

  Future<void> stop() async {
    _epoch++;
    loading.value = null;
    playing.value = null;
    try {
      await _player.stop();
    } catch (_) {}
  }
}
