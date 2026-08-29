import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api.dart';
import '../core/audio.dart';
import '../core/icons.dart';
import '../core/l10n.dart';
import '../core/study_tracker.dart';
import '../core/theme.dart';
import '../main.dart';
import 'exercise_end_screen.dart';

/// Plays every question of one section. Built 1:1 to the redesign frames
/// (Test*, Match Type*, Fill in the blanks*, Fill letter space*,
/// reorder-words-exercise*).
///
/// Flow per the 2026-08 contract:
///   - GET /sections/{id} delivers all questions up front (a quiz section
///     carries every quiz-eligible question of its sibling sections,
///     FR-13.4);
///   - each answer goes to POST /sections/{id}/check, which grades it and
///     stores NOTHING — it only feeds the verdict sheet (FR-13.5);
///   - answers are buffered client-side and submitted in one
///     POST /sections/{id}/attempts after the last question, the only
///     write. The server re-grades everything; the per-question verdicts
///     here are advisory and this screen never computes a score (NFR-5).
///
/// A wrong answer is recorded as wrong and the set moves on — the FR-12.7
/// re-queue is gone with the point system (FR-13.7).
class ExerciseScreen extends StatefulWidget {
  const ExerciseScreen({super.key, required this.sectionId, required this.title});

  final int sectionId;
  final String title;

  @override
  State<ExerciseScreen> createState() => _ExerciseScreenState();
}

/// Resolves a UI string through core l10n, falling back to the design
/// file's English copy while the key — or the whole 'en' language,
/// FR-13.22 — has not landed in core/l10n.dart yet. `L.t` echoes the key
/// back when it has nothing, which is the miss signal.
// Every key below is defined trilingually in core/l10n.dart, so the
// fallback literals are dead in practice — they only surface if a key is
// ever removed from the table.
String _s(L l, String key, String fallback) {
  final resolved = l.t(key);
  return resolved == key ? fallback : resolved;
}

/// [_s] plus `{name}` placeholder filling, mirroring `L.f`.
String _sf(L l, String key, String fallback, Map<String, Object> values) {
  var out = _s(l, key, fallback);
  values.forEach((name, value) => out = out.replaceAll('{$name}', '$value'));
  return out;
}

// Measured from the state frames (Test-* / Match Type-* / Fill * /
// reorder-*): the pale fills behind selected / correct / wrong content.
// SPEC: no core token names exist for these tints; swap in DanaColors
// names if agent B adds them.
const _brandTint = Color(0xFFF9ECEF);
const _okTint = Color(0xFFE8FCF2);
const _dangerTint = Color(0xFFFCE8E8);

class _ExerciseScreenState extends State<ExerciseScreen> with StudyTimeAware {
  bool _loading = true;
  String? _error;

  String _sectionType = '';
  List<Map<String, dynamic>> _questions = [];
  int _index = 0;

  /// Buffered `{question_id, answer}` pairs, POSTed once after the last
  /// question (FR-13.5: nothing is saved mid-section).
  final List<Map<String, dynamic>> _answers = [];

  /// Question ids already buffered into [_answers]. A wrong practice
  /// answer re-queues its question (FR-15.13), so the same id can be
  /// checked several times — but only the FIRST answer is what the
  /// attempt submits and scores ("first try counts", client 2026-08-27).
  final Set<int> _answeredIds = {};

  dynamic _answer;
  bool? _verdict;

  /// The `answer_description_en` from the last /check (FR-14, §6): the
  /// verdict sheet's explanation line ("You heard seven."). Served
  /// verbatim in English in every UI language; null when the question
  /// carries none.
  String? _answerDescription;

  bool _checking = false;
  bool _finishing = false;
  String? _finishError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    // Stop any clip still sounding so audio never bleeds into the next
    // screen. super.dispose() chains to StudyTimeAware.leave().
    AudioBus.instance.stop();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final data = await Api.instance.get('/sections/${widget.sectionId}');
      final section = (data['section'] as Map?)?.cast<String, dynamic>() ?? {};
      final questions =
          ((data['questions'] as List?) ?? []).cast<Map<String, dynamic>>();

      setState(() {
        _sectionType = section['type'] as String? ?? '';
        _questions = questions;
        _loading = false;
      });
    } on ApiError catch (e) {
      setState(() {
        _error = e.message(AppState.instance.language ?? 'tk');
        _loading = false;
      });
    }
  }

  Map<String, dynamic> get _question => _questions[_index];

  /// Per-question exercise type. A quiz section mixes questions of many
  /// exercises (FR-13.4), so the type must ride on the question itself.
  // SPEC: the contract writes `questions:[ payloadForStudent ]` without
  // naming the field — agent A's payload carries `type` per question.
  String get _type => _question['type'] as String? ?? '';

  /// The student payload — either nested under `payload` or the question
  /// map itself, depending on how flat agent A serialises it.
  Map<String, dynamic> get _payload =>
      (_question['payload'] as Map?)?.cast<String, dynamic>() ?? _question;

  /// §6: `rule_en` is the instruction line above the question, served
  /// verbatim in English in every UI language. It may ride on the payload
  /// or the question map; a blank one reads as absent.
  String? _ruleOf(Map<String, dynamic> question) {
    final raw = (_payload['rule_en'] ?? question['rule_en']) as String?;
    final rule = raw?.trim();
    return (rule == null || rule.isEmpty) ? null : rule;
  }

  /// The answer as the API expects it, or null while incomplete. Shapes
  /// mirror the server grader: the chosen option's ORIGINAL index for
  /// v2 multiple choice (`i`), falling back to option TEXT for legacy
  /// payloads; word list for the two fill types, token list for reorder,
  /// left→right map for match.
  dynamic _serializedAnswer() {
    switch (_type) {
      case 'multiple_choice':
        // `_answer` holds the DISPLAY position of the chosen row; the
        // wire value is that option's original index (v2) or its text
        // (legacy) — never the display position, which the server-side
        // shuffle would read as a different option.
        final display = _answer as int?;
        if (display == null) return null;
        final options = _mcOptions(_payload);
        if (display < 0 || display >= options.length) return null;
        final chosen = options[display];
        return chosen.originalIndex ?? chosen.text;

      case 'fill_blank':
        final slots = _answer as List<String?>?;
        final blanks = _blankCount(_payload);
        if (slots == null || slots.length != blanks) return null;
        final words = slots.whereType<String>().toList();
        return words.length == blanks ? words : null;

      case 'fill_letter_space':
        final slots = _answer as List<String?>?;
        final total = _letterSlotCount(_payload);
        if (total == 0 || slots == null || slots.length != total) return null;
        if (slots.any((s) => s == null)) return null;
        // v2 mask ("S<e>ve<n>"): the flat list of the letters typed into
        // the hidden boxes, in reading order — the grader compares them
        // to the hidden letters case-insensitively (§3).
        if (_payload['mask'] is String) return slots.cast<String>();
        // Legacy {text, reveal}: one joined string per contiguous blank.
        final blanks = _letterBlanks(_payload);
        final parts = <String>[];
        var offset = 0;
        for (final blank in blanks) {
          parts.add(slots.sublist(offset, offset + blank.count).join());
          offset += blank.count;
        }
        return parts;

      case 'reorder':
        final placed = _answer as List<String>?;
        // The frame shows Submit and Check as soon as a word is placed —
        // a partial order is submittable and simply grades wrong.
        return (placed == null || placed.isEmpty) ? null : placed;

      case 'match_pairs':
        final matched = _answer as Map<String, String>?;
        final total = ((_payload['left'] as List?) ?? []).length;
        if (matched == null || total == 0 || matched.length != total) return null;
        return matched;
    }
    return null;
  }

  Future<void> _check() async {
    if (_checking || _verdict != null) return;
    final answer = _serializedAnswer();
    if (answer == null) return;

    final questionId = _question['id'];
    setState(() => _checking = true);

    try {
      final result = await Api.instance.post(
        '/sections/${widget.sectionId}/check',
        body: {'question_id': questionId, 'answer': answer},
      );
      if (!mounted) return;

      setState(() {
        _checking = false;
        _verdict = result['correct'] == true;
        // §6: the verdict sheet's explanation line, when the question
        // carries one. Trimmed so an empty string reads as absent.
        final description = (result['answer_description_en'] as String?)?.trim();
        _answerDescription =
            (description == null || description.isEmpty) ? null : description;
        // Buffered exactly as graded, so the final attempt submits what
        // the student saw the verdict for — but only the FIRST answer
        // per question: a re-queued retry (FR-15.13) is practice, not a
        // second chance at the score.
        final id = (questionId as num).toInt();
        if (_answeredIds.add(id)) {
          _answers.add({'question_id': questionId, 'answer': answer});
        }
      });
    } on ApiError catch (e) {
      if (!mounted) return;
      setState(() => _checking = false);
      // Not fatal: the answer is still on screen, submit can be retried.
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.message(AppState.instance.language ?? 'tk'))),
      );
    }
  }

  /// Leaves the verdict sheet.
  ///
  /// FR-15.13 (client, 2026-08-27): in the PRACTICE modules a wrongly
  /// answered question re-queues at the end of the run and keeps coming
  /// back until answered correctly — the run only finishes when every
  /// question has been solved. The score is untouched by retries: only
  /// the first answer was buffered (see _check), so the submitted
  /// attempt grades the first try. The quiz stays a one-pass exam.
  void _advance() {
    final requeue = _verdict == false && _sectionType != 'quiz';
    final atLast = _index + 1 >= _questions.length;

    // A clip from this question must not keep sounding over the next.
    AudioBus.instance.stop();

    setState(() {
      if (requeue) _questions.add(_reshuffledCopy(_question));

      _verdict = null;
      _answer = null;
      _answerDescription = null;

      // The re-queue guarantees a next question even from the last slot.
      if (!atLast || requeue) _index++;
    });

    if (atLast && !requeue) _finish();
  }

  /// A display-fresh copy of a question for its re-queue: the option
  /// list / word bank are locally reshuffled so the retry cannot be
  /// pattern-matched by position. Safe by construction — answers travel
  /// as the option's ORIGINAL index `i` (or text), never the display
  /// position, so reordering the display cannot change which option is
  /// correct: "am" stays the answer wherever it lands.
  Map<String, dynamic> _reshuffledCopy(Map<String, dynamic> question) {
    final copy = Map<String, dynamic>.from(question);
    final payload = (question['payload'] as Map?)?.cast<String, dynamic>();
    if (payload == null) return copy;

    final newPayload = Map<String, dynamic>.from(payload);

    if (newPayload['options'] is List) {
      newPayload['options'] = List.of(newPayload['options'] as List)..shuffle();
    }
    if (newPayload['word_bank'] is List) {
      newPayload['word_bank'] = List.of(newPayload['word_bank'] as List)
        ..shuffle();
    }

    copy['payload'] = newPayload;
    return copy;
  }

  /// FR-4.20: an exercise type this build cannot render is reported and
  /// stepped over, never fabricated. The answer is buffered as null so
  /// the attempt still carries one entry per question.
  void _skipUnsupported() {
    final id = (_question['id'] as num).toInt();
    if (_answeredIds.add(id)) {
      _answers.add({'question_id': _question['id'], 'answer': null});
    }
    _advance();
  }

  Future<void> _finish() async {
    setState(() {
      _finishing = true;
      _finishError = null;
    });

    try {
      final result = await Api.instance.post(
        '/sections/${widget.sectionId}/attempts',
        body: {'answers': _answers},
      );
      if (!mounted) return;

      final percent = (result['percent'] as num?)?.round() ?? 0;
      await Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => ExerciseEndScreen(
            percent: percent,
            isQuiz: _sectionType == 'quiz',
          ),
        ),
      );
    } on ApiError catch (e) {
      if (!mounted) return;
      setState(() {
        _finishing = false;
        _finishError = e.message(AppState.instance.language ?? 'tk');
      });
    }
  }

  /// FR-13.5: quitting mid-way discards the attempt — nothing was written
  /// server-side (check is stateless), so leaving is just a pop. The
  /// dialog makes sure the student knows that is what leaving means.
  Future<void> _confirmEndSession() async {
    final l = AppState.instance.l;

    final leave = await showDialog<bool>(
      context: context,
      barrierColor: const Color(0x4D000000),
      builder: (context) => Dialog(
        backgroundColor: DanaColors.card,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(DanaRadius.card),
        ),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                _s(l, 'end_session_title', 'End session'),
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w700,
                  color: DanaColors.ink,
                  letterSpacing: -0.36,
                ),
              ),
              const SizedBox(height: 12),
              Text(
                _s(
                  l,
                  'end_session_body',
                  "Do you really want to end this session. "
                      "Your progress won't be saved.",
                ),
                style: const TextStyle(
                  fontSize: 15,
                  height: 1.45,
                  color: DanaColors.textMuted,
                  letterSpacing: -0.3,
                ),
              ),
              const SizedBox(height: 20),
              Row(
                children: [
                  Expanded(
                    child: SizedBox(
                      height: 48,
                      child: TextButton(
                        style: TextButton.styleFrom(
                          backgroundColor: DanaColors.surface,
                          foregroundColor: DanaColors.ink,
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(DanaRadius.field),
                          ),
                          textStyle: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w600,
                            letterSpacing: -0.3,
                          ),
                        ),
                        onPressed: () => Navigator.of(context).pop(false),
                        child: Text(l.t('cancel')),
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: SizedBox(
                      height: 48,
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: DanaColors.danger,
                          minimumSize: Size.zero,
                        ),
                        onPressed: () => Navigator.of(context).pop(true),
                        child: Text(_s(l, 'end_session_confirm', 'End session')),
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

    if (leave == true && mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    if (_loading) {
      return const Scaffold(
        body: Center(child: CircularProgressIndicator(color: DanaColors.brand)),
      );
    }

    if (_error != null || _questions.isEmpty) {
      return Scaffold(
        body: Column(
          children: [
            _Header(title: widget.title, current: 0, total: 0, onBack: () => Navigator.of(context).maybePop()),
            Expanded(
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(_error ?? l.t('no_content'), textAlign: TextAlign.center),
                ),
              ),
            ),
          ],
        ),
      );
    }

    final submittable = _serializedAnswer() != null;
    // Match Pairs has no button — the last pair triggers the check.
    final showButton = _verdict == null &&
        !_finishing &&
        _finishError == null &&
        _type != 'match_pairs' &&
        submittable;

    return PopScope(
      // Every pop while playing goes through the End-session dialog —
      // leaving mid-section discards the whole attempt (FR-13.5).
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _confirmEndSession();
      },
      child: Scaffold(
        body: Stack(
          children: [
            Column(
              children: [
                _Header(
                  title: _titleFor(_type, l),
                  current: _index + 1,
                  total: _questions.length,
                  onBack: _confirmEndSession,
                ),
                Expanded(
                  child: _finishing
                      ? const Center(
                          child: CircularProgressIndicator(color: DanaColors.brand),
                        )
                      : _finishError != null
                          ? _buildFinishError(l)
                          : SingleChildScrollView(
                              padding: const EdgeInsets.all(20),
                              child: _buildQuestion(l),
                            ),
                ),
                if (showButton)
                  SafeArea(
                    top: false,
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
                      child: SizedBox(
                        height: 51,
                        child: ElevatedButton(
                          onPressed: _checking ? null : _check,
                          child: Text(
                            _type == 'multiple_choice'
                                ? _s(l, 'continue_', 'Continue')
                                : _s(l, 'submit_and_check', 'Submit and Check'),
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
            // Sits over the whole screen, including the action button —
            // the verdict is the only thing to interact with until it is
            // dismissed.
            if (_verdict != null) _buildVerdict(l),
          ],
        ),
      ),
    );
  }

  /// Header titles are the exercise-type names from the frames. The type
  /// varies per question inside a quiz, so this follows the question.
  String _titleFor(String type, L l) => switch (type) {
        'multiple_choice' => _s(l, 'type_multiple_choice', 'Multiple Choice'),
        'match_pairs' => _s(l, 'type_match_pairs', 'Match Pairs'),
        'fill_blank' => _s(l, 'type_fill_blank', 'Fill the Blanks'),
        'fill_letter_space' => _s(l, 'type_fill_letter_space', 'Fill letter spaces'),
        'reorder' => _s(l, 'reorder_words', 'Reorder Words'),
        _ => widget.title,
      };

  Widget _buildQuestion(L l) {
    // Keyed by question id so per-question widget state (active match
    // tile, letter focus) never leaks into the next question.
    final key = ValueKey(_question['id']);

    switch (_type) {
      case 'multiple_choice':
        return _MultipleChoice(
          key: key,
          payload: _payload,
          rule: _ruleOf(_question),
          selected: _answer as int?,
          verdict: _verdict,
          onChanged: (v) => setState(() => _answer = v),
        );
      case 'fill_blank':
        return _FillBlanks(
          key: key,
          payload: _payload,
          slots: (_answer as List<String?>?) ??
              List<String?>.filled(_blankCount(_payload), null),
          verdict: _verdict,
          onChanged: (v) => setState(() => _answer = v),
        );
      case 'fill_letter_space':
        return _FillLetterSpace(
          key: key,
          payload: _payload,
          slots: (_answer as List<String?>?) ??
              List<String?>.filled(_letterSlotCount(_payload), null),
          verdict: _verdict,
          onChanged: (v) => setState(() => _answer = v),
        );
      case 'reorder':
        return _Reorder(
          key: key,
          payload: _payload,
          question: _question,
          rule: _ruleOf(_question),
          selected: (_answer as List<String>?) ?? const [],
          verdict: _verdict,
          onChanged: (v) => setState(() => _answer = v.isEmpty ? null : v),
        );
      case 'match_pairs':
        return _MatchPairs(
          key: key,
          payload: _payload,
          question: _question,
          matched: (_answer as Map<String, String>?) ?? const {},
          verdict: _verdict,
          onChanged: (v) => setState(() => _answer = v.isEmpty ? null : v),
          onCompleted: _check,
        );
    }

    // FR-4.20: report, never fabricate.
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _QuestionCard(
          label: _s(l, 'unsupported_type', 'Unsupported exercise'),
          child: Text(
            _sf(l, 'unsupported_type_body',
                'This exercise type ({t}) is not supported by this version of the app.',
                {'t': _type}),
            style: const TextStyle(fontSize: 15, color: DanaColors.textMuted),
          ),
        ),
        const SizedBox(height: 20),
        SizedBox(
          height: 51,
          child: ElevatedButton(
            onPressed: _skipUnsupported,
            child: Text(_s(l, 'continue_', 'Continue')),
          ),
        ),
      ],
    );
  }

  Widget _buildFinishError(L l) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_finishError!, textAlign: TextAlign.center),
            const SizedBox(height: 16),
            // The buffered answers are still in memory — retry re-submits
            // them rather than losing the whole attempt to a bad moment
            // of connectivity.
            SizedBox(
              height: 48,
              child: ElevatedButton(
                onPressed: _finish,
                child: Text(_s(l, 'retry', 'Try again')),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// The verdict bottom sheet from the frames: scrim, white top-rounded
  /// panel, badge + coloured title, one-line body, Continue.
  Widget _buildVerdict(L l) {
    final correct = _verdict == true;

    return Positioned.fill(
      child: Container(
        color: const Color(0x4D000000),
        alignment: Alignment.bottomCenter,
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.all(24),
          decoration: const BoxDecoration(
            color: DanaColors.card,
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
            boxShadow: [
              BoxShadow(color: Color(0x26000000), blurRadius: 20, offset: Offset(0, 16)),
            ],
          ),
          child: SafeArea(
            top: false,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    correct
                        ? const DanaIcon.original(DanaIcons.checkCircle, size: 28)
                        : const DanaIcon.original(DanaIcons.timesCircle,
                            size: 28, frame: 36),
                    const SizedBox(width: 12),
                    Text(
                      correct ? _s(l, 'correct', 'Correct') : _s(l, 'wrong', 'Wrong'),
                      style: TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -0.48,
                        color: correct ? DanaColors.ok : DanaColors.danger,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                Text(
                  // §6: when the question carries an Answer Description it
                  // is the line under the title ("You heard seven."),
                  // shown verbatim in English. Otherwise the generic
                  // encouragement — the old 'wrong_body' is gone with the
                  // re-queue it promised (FR-13.7).
                  _answerDescription ??
                      (correct
                          ? _s(l, 'correct_body', 'You did it! Keep going!')
                          : _s(l, 'made_mistakes', 'You made some mistakes.')),
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w600,
                    height: 1.4,
                    color: DanaColors.ink,
                    letterSpacing: -0.32,
                  ),
                ),
                const SizedBox(height: 20),
                SizedBox(
                  height: 51,
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: _advance,
                    child: Text(_s(l, 'continue_', 'Continue')),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/* ------------------------------------------------------- payload parsing */

/// A media reference from a content-v2 payload, or null when the part is
/// unused. The import format writes `"0"` for an unused triple member
/// (§1.1); a for-student payload should send null, but `"0"` and empty
/// strings are treated as absent here so both shapes render the same.
String? _mediaRef(dynamic value) {
  if (value is! String) return null;
  final ref = value.trim();
  return (ref.isEmpty || ref == '0') ? null : ref;
}

/// The media URL of a stem/option map for the given [kind] ('audio' or
/// 'image'), tolerant of both shapes the server may send:
///   • `{"kind": "audio", "url": "/api/v1/media/…"}` (the current
///     serializer), and
///   • `{"audio": "/api/v1/media/…"}` (flat kind→url).
/// Returns null when this map carries no media of that kind.
String? _mediaOf(Map<String, dynamic> map, String kind) {
  if (map['kind'] == kind) return _mediaRef(map['url']);
  return _mediaRef(map[kind]);
}

/// One multiple-choice option of a v2 payload. Options arrive shuffled,
/// each carrying its ORIGINAL index `i` and exactly one of text / audio /
/// image (§3). A legacy option is a bare string with no index — its text
/// is submitted instead of an index.
class _McOption {
  const _McOption({this.originalIndex, this.text, this.audio, this.image});

  final int? originalIndex;
  final String? text;
  final String? audio;
  final String? image;

  bool get isImage => image != null;
  bool get isAudio => audio != null;
}

List<_McOption> _mcOptions(Map<String, dynamic> payload) {
  final raw = (payload['options'] as List?) ?? const [];

  return raw.map<_McOption>((option) {
    if (option is Map) {
      final map = option.cast<String, dynamic>();
      return _McOption(
        originalIndex: (map['i'] as num?)?.toInt(),
        text: map['text'] as String?,
        audio: _mediaOf(map, 'audio'),
        image: _mediaOf(map, 'image'),
      );
    }
    // Legacy: a bare string option, submitted by text.
    return _McOption(text: option?.toString());
  }).toList();
}

/// The multiple-choice stem: at most one of text / audio / image (§3),
/// plus the `rule_en` instruction. A legacy stem is a bare string, read
/// as its text.
class _McStem {
  const _McStem({this.text, this.audio, this.image});

  final String? text;
  final String? audio;
  final String? image;

  static _McStem of(Map<String, dynamic> payload) {
    final raw = payload['stem'];
    if (raw is Map) {
      final map = raw.cast<String, dynamic>();
      return _McStem(
        text: (map['text'] as String?)?.trim(),
        audio: _mediaOf(map, 'audio'),
        image: _mediaOf(map, 'image'),
      );
    }
    return _McStem(text: (raw as String?)?.trim());
  }
}

/// One cell of a v2 fill_letter_space mask: a fixed visible letter or a
/// hidden box the student fills. See [_maskWords].
class _MaskCell {
  const _MaskCell.fixed(this.char) : hidden = false;
  const _MaskCell.blank()
      : char = null,
        hidden = true;

  final String? char;
  final bool hidden;
}

/// Parses a v2 letter-space mask (`S<e>ve<n>`) into words of cells.
/// Everything inside `<>` is HIDDEN — one blank box per character — and
/// everything else is a visible fixed letter shown in place. Whitespace
/// splits words so a word's boxes never wrap apart. The hidden characters
/// are only counted, never shown, so a for-student mask that keeps the
/// answer letters and one that replaces them with placeholders both
/// render identically.
List<List<_MaskCell>> _maskWords(String mask) {
  final words = <List<_MaskCell>>[];
  var current = <_MaskCell>[];
  var hidden = false;
  var hiddenChars = 0; // chars seen since the opening '<'

  for (final ch in mask.split('')) {
    if (ch == '<') {
      hidden = true;
      hiddenChars = 0;
      continue;
    }
    if (ch == '>') {
      // An empty <> still marks one hidden slot.
      if (hidden && hiddenChars == 0) current.add(const _MaskCell.blank());
      hidden = false;
      continue;
    }
    if (ch == ' ' || ch == '\t' || ch == '\n') {
      if (current.isNotEmpty) {
        words.add(current);
        current = <_MaskCell>[];
      }
      continue;
    }
    if (hidden) {
      hiddenChars++;
      current.add(const _MaskCell.blank());
    } else {
      current.add(_MaskCell.fixed(ch));
    }
  }

  if (current.isNotEmpty) words.add(current);
  return words;
}

/// One `{blank}` of a fill_letter_space payload (FR-13.21): the revealed
/// leading letters and how many the student must type.
class _LetterBlank {
  const _LetterBlank(this.given, this.count);

  final String given;
  final int count;
}

/// The `parts` array of the two fill types: `{"t": "..."}` runs of text
/// and `{"blank": {...}}` gaps, per the contract. A legacy fill_blank
/// payload (`before`/`after`/`word_bank`) is normalised into the same
/// shape so both render identically.
List<Map<String, dynamic>> _partsOf(Map<String, dynamic> payload) {
  final raw = payload['parts'];
  if (raw is List) {
    return raw.whereType<Map>().map((p) => p.cast<String, dynamic>()).toList();
  }

  return [
    if ((payload['before'] as String? ?? '').trim().isNotEmpty)
      {'t': payload['before'] as String},
    {'blank': <String, dynamic>{}},
    if ((payload['after'] as String? ?? '').trim().isNotEmpty)
      {'t': payload['after'] as String},
  ];
}

int _blankCount(Map<String, dynamic> payload) =>
    _partsOf(payload).where((p) => p.containsKey('blank')).length;

/// One slot per hidden letter across the whole sentence — from the v2
/// mask's hidden cells, or the legacy blanks' letter counts.
int _letterSlotCount(Map<String, dynamic> payload) {
  final mask = payload['mask'];
  if (mask is String) {
    return _maskWords(mask)
        .expand((word) => word)
        .where((cell) => cell.hidden)
        .length;
  }
  return _letterBlanks(payload).fold<int>(0, (sum, b) => sum + b.count);
}

List<_LetterBlank> _letterBlanks(Map<String, dynamic> payload) {
  final blanks = <_LetterBlank>[];

  for (final part in _partsOf(payload)) {
    final blank = part['blank'];
    if (blank is Map) {
      blanks.add(_LetterBlank(
        blank['given'] as String? ?? '',
        (blank['count'] as num?)?.toInt() ?? 0,
      ));
    }
  }

  return blanks;
}

/* --------------------------------------------------------------- header */

/// Brand block: 36px tinted back tile, centred type-name title, "N of M"
/// counter, then the amber progress bar. The bar fills by question
/// position — "2 of 5" paints 40% — matching the frames.
class _Header extends StatelessWidget {
  const _Header({
    required this.title,
    required this.current,
    required this.total,
    required this.onBack,
  });

  final String title;
  final int current;
  final int total;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

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
          padding: const EdgeInsets.all(20),
          child: Column(
            children: [
              Row(
                children: [
                  GestureDetector(
                    onTap: onBack,
                    child: Container(
                      width: 36,
                      height: 36,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: const DanaIcon(
                        DanaIcons.arrowLeft,
                        size: 20,
                        color: Colors.white,
                      ),
                    ),
                  ),
                  Expanded(
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
                  SizedBox(
                    // Wide enough for "12 of 12" (FR-2.9) without pushing
                    // the title off centre against the 36px back tile.
                    width: 52,
                    child: Text(
                      total == 0
                          ? ''
                          : _sf(l, 'progress_of', '{n} of {m}',
                              {'n': current, 'm': total}),
                      textAlign: TextAlign.right,
                      style: TextStyle(
                        fontSize: 13,
                        color: Colors.white.withValues(alpha: 0.7),
                        letterSpacing: -0.26,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              ClipRRect(
                borderRadius: BorderRadius.circular(100),
                child: LinearProgressIndicator(
                  value: total == 0 ? 0 : (current / total).clamp(0.0, 1.0),
                  minHeight: 10,
                  // Measured #892451 on brand = white at 10%.
                  backgroundColor: Colors.white.withValues(alpha: 0.1),
                  valueColor:
                      const AlwaysStoppedAnimation(DanaColors.progressAmber),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/* ------------------------------------------------------- shared pieces */

/// The frames' question card: white, 16 radius, soft shadow, no border —
/// the redesign dropped the old 1px outline.
class _QuestionCard extends StatelessWidget {
  const _QuestionCard({required this.label, required this.child, this.gap = 12});

  final String label;
  final Widget child;
  final double gap;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: DanaColors.card,
        borderRadius: BorderRadius.circular(DanaRadius.card),
        boxShadow: const [
          BoxShadow(color: Color(0x0A000000), blurRadius: 12, offset: Offset(0, 4)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(label),
          SizedBox(height: gap),
          child,
        ],
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Text(
        text.toUpperCase(),
        style: const TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: DanaColors.textMuted,
          letterSpacing: -0.24,
        ),
      );
}

/// The question stem / instruction line: 20px semibold ink.
class _Stem extends StatelessWidget {
  const _Stem(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Text(
        text,
        style: const TextStyle(
          fontSize: 20,
          fontWeight: FontWeight.w600,
          color: DanaColors.ink,
          letterSpacing: -0.4,
          height: 1.35,
        ),
      );
}

/// A word in the pool below the card: flat white chip, no border. A used
/// word stays in place, greyed — removing it would reflow the row under
/// the student's finger mid-tap.
class _PoolChip extends StatelessWidget {
  const _PoolChip({required this.text, required this.onTap, this.used = false});

  final String text;
  final VoidCallback? onTap;
  final bool used;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: used ? null : onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          color: DanaColors.card,
          borderRadius: BorderRadius.circular(10),
          boxShadow: const [
            BoxShadow(color: Color(0x08000000), blurRadius: 4, offset: Offset(0, 2)),
          ],
        ),
        child: Text(
          text,
          style: TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w600,
            letterSpacing: -0.32,
            color: used ? DanaColors.chipUsed : DanaColors.ink,
          ),
        ),
      ),
    );
  }
}

/// Colour role of a placed answer: brand while answering, then the
/// verdict's green or red.
enum _Tone { brand, ok, danger }

extension on _Tone {
  Color get fill => switch (this) {
        _Tone.brand => _brandTint,
        _Tone.ok => _okTint,
        _Tone.danger => _dangerTint,
      };

  Color get stroke => switch (this) {
        _Tone.brand => DanaColors.brand,
        _Tone.ok => DanaColors.ok,
        _Tone.danger => DanaColors.danger,
      };
}

_Tone _toneFor(bool? verdict) => switch (verdict) {
      null => _Tone.brand,
      true => _Tone.ok,
      false => _Tone.danger,
    };

/// A word placed into the answer (reorder card, filled blank): tinted
/// fill, 1.5px coloured border.
class _PlacedChip extends StatelessWidget {
  const _PlacedChip({required this.text, required this.tone, this.onTap});

  final String text;
  final _Tone tone;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: tone.fill,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: tone.stroke, width: 1.5),
        ),
        child: Text(
          text,
          style: TextStyle(
            fontSize: 17,
            fontWeight: FontWeight.w600,
            letterSpacing: -0.34,
            color: tone.stroke,
          ),
        ),
      ),
    );
  }
}

/* --------------------------------------------------------------- types */

/// Test frames: the stem card (text / audio tile / image), then the
/// option list. Options arrive shuffled with their original index; the
/// chosen row is reported by DISPLAY position and the screen maps it back
/// to the original index (or text) for the wire. Text, image and audio
/// options each render to their own frame (Test-*, Match Type-image /
/// -audio) while sharing one selection + verdict model.
class _MultipleChoice extends StatelessWidget {
  const _MultipleChoice({
    super.key,
    required this.payload,
    required this.rule,
    required this.selected,
    required this.verdict,
    required this.onChanged,
  });

  final Map<String, dynamic> payload;

  /// The `rule_en` instruction, shown as the prompt when the stem is a
  /// media stem that carries no text of its own (§6).
  final String? rule;

  /// The DISPLAY position of the chosen row, not the option's index.
  final int? selected;
  final bool? verdict;
  final ValueChanged<int> onChanged;

  _OptionState _stateFor(int display) {
    if (selected != display) return _OptionState.idle;
    return switch (verdict) {
      null => _OptionState.selected,
      true => _OptionState.correct,
      false => _OptionState.wrong,
    };
  }

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final stem = _McStem.of(payload);
    final options = _mcOptions(payload);

    // A media stem carries no text of its own, so the rule becomes the
    // visible prompt; a text stem shows its own text.
    final prompt = (stem.text != null && stem.text!.isNotEmpty)
        ? stem.text!
        : (rule ?? '');

    final cardChildren = <Widget>[
      if (prompt.isNotEmpty) _Stem(prompt),
      if (stem.audio != null) ...[
        if (prompt.isNotEmpty) const SizedBox(height: 20),
        Center(child: _AudioStemTile(url: stem.audio!)),
      ],
      if (stem.image != null) ...[
        if (prompt.isNotEmpty) const SizedBox(height: 16),
        ClipRRect(
          borderRadius: BorderRadius.circular(8),
          child: _AuthImage(url: stem.image!, height: 160),
        ),
      ],
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _QuestionCard(
          label: _s(l, 'choose_right_answer', 'Choose right answer'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: cardChildren,
          ),
        ),
        const SizedBox(height: 28),
        for (var i = 0; i < options.length; i++)
          Padding(
            padding: EdgeInsets.only(bottom: i == options.length - 1 ? 0 : 12),
            // The chosen row alone carries the verdict colour; the rest
            // stay idle (the right answer is never revealed, NFR-5).
            child: _buildOption(options[i], i),
          ),
      ],
    );
  }

  Widget _buildOption(_McOption option, int display) {
    final letter = String.fromCharCode(65 + display);
    final state = _stateFor(display);
    final onTap = verdict == null ? () => onChanged(display) : null;

    if (option.isImage) {
      return _ImageOptionRow(
          letter: letter, url: option.image!, state: state, onTap: onTap);
    }
    if (option.isAudio) {
      return _AudioOptionRow(
          letter: letter, url: option.audio!, state: state, onTap: onTap);
    }
    return _OptionRow(
        letter: letter, text: option.text ?? '', state: state, onTap: onTap);
  }
}

enum _OptionState { idle, selected, correct, wrong }

/// The accent a chosen option carries: brand while answering, then the
/// verdict's green or red. Idle options have none.
Color? _optionAccent(_OptionState state) => switch (state) {
      _OptionState.idle => null,
      _OptionState.selected => DanaColors.brand,
      _OptionState.correct => DanaColors.ok,
      _OptionState.wrong => DanaColors.danger,
    };

/// The circular A–D badge shared by every option kind. Idle: muted glyph
/// on the surface; chosen: white glyph on the accent.
class _LetterBadge extends StatelessWidget {
  const _LetterBadge({required this.letter, required this.accent});

  final String letter;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 28,
      height: 28,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: accent ?? DanaColors.surface,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Text(
        letter,
        style: TextStyle(
          fontSize: 14,
          fontWeight: FontWeight.w700,
          letterSpacing: -0.28,
          color: accent == null ? DanaColors.textMuted : Colors.white,
        ),
      ),
    );
  }
}

/// The verdict tick that trails a graded option, or nothing while idle.
Widget _optionVerdictMark(_OptionState state) => switch (state) {
      _OptionState.correct =>
        const DanaIcon.original(DanaIcons.checkCircle, size: 24),
      _OptionState.wrong =>
        const DanaIcon.original(DanaIcons.timesCircle, size: 24, frame: 36),
      _ => const SizedBox.shrink(),
    };

class _OptionRow extends StatelessWidget {
  const _OptionRow({
    required this.letter,
    required this.text,
    required this.state,
    required this.onTap,
  });

  final String letter;
  final String text;
  final _OptionState state;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final accent = _optionAccent(state);
    final graded = state == _OptionState.correct || state == _OptionState.wrong;

    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          // Rows stay white in every state — only border, badge and text
          // take the colour (Test-selected/-wrong/-correct frames).
          color: DanaColors.card,
          borderRadius: BorderRadius.circular(DanaRadius.field),
          border: Border.all(
            color: accent ?? DanaColors.border,
            width: accent == null ? 1 : 1.5,
          ),
        ),
        child: Row(
          children: [
            _LetterBadge(letter: letter, accent: accent),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                text,
                style: TextStyle(
                  fontSize: 15,
                  fontWeight: accent == null ? FontWeight.w500 : FontWeight.w600,
                  letterSpacing: -0.3,
                  color: accent ?? DanaColors.ink,
                ),
              ),
            ),
            if (graded) ...[
              const SizedBox(width: 12),
              _optionVerdictMark(state),
            ],
          ],
        ),
      ),
    );
  }
}

/// An image option (Match Type-image card style): the image fills the
/// card, with the letter badge kept in the corner and the verdict tick
/// beside it. Selecting is tapping anywhere on the card.
class _ImageOptionRow extends StatelessWidget {
  const _ImageOptionRow({
    required this.letter,
    required this.url,
    required this.state,
    required this.onTap,
  });

  final String letter;
  final String url;
  final _OptionState state;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final accent = _optionAccent(state);
    final graded = state == _OptionState.correct || state == _OptionState.wrong;

    return GestureDetector(
      onTap: onTap,
      child: Container(
        height: 96,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: DanaColors.card,
          borderRadius: BorderRadius.circular(DanaRadius.field),
          border: Border.all(
            color: accent ?? DanaColors.border,
            width: accent == null ? 1 : 1.5,
          ),
        ),
        child: Stack(
          fit: StackFit.expand,
          children: [
            _AuthImage(url: url),
            Positioned(
              top: 8,
              left: 8,
              child: _LetterBadge(letter: letter, accent: accent),
            ),
            if (graded)
              Positioned(
                top: 8,
                right: 8,
                child: Container(
                  decoration: const BoxDecoration(
                    color: DanaColors.card,
                    shape: BoxShape.circle,
                  ),
                  child: _optionVerdictMark(state),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// An audio option (Match Type-audio row style): a leading brand speaker
/// glyph that plays the clip on tap WITHOUT selecting, the letter badge as
/// its label, and the whole row body selecting on tap.
class _AudioOptionRow extends StatelessWidget {
  const _AudioOptionRow({
    required this.letter,
    required this.url,
    required this.state,
    required this.onTap,
  });

  final String letter;
  final String url;
  final _OptionState state;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final accent = _optionAccent(state);
    final graded = state == _OptionState.correct || state == _OptionState.wrong;

    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: DanaColors.card,
          borderRadius: BorderRadius.circular(DanaRadius.field),
          border: Border.all(
            color: accent ?? DanaColors.border,
            width: accent == null ? 1 : 1.5,
          ),
        ),
        child: Row(
          children: [
            // The glyph plays on tap and swallows it, so listening never
            // counts as choosing.
            GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: () => AudioBus.instance.play(url),
              child: _AudioIndicator(url: url, size: 24, color: DanaColors.brand),
            ),
            const SizedBox(width: 14),
            _LetterBadge(letter: letter, accent: accent),
            const Expanded(child: SizedBox()),
            if (graded) _optionVerdictMark(state),
          ],
        ),
      ),
    );
  }
}

/// The brand audio glyph driven by [AudioBus]: the speaker when idle, a
/// spinner while its bytes download, the waveform while it plays. Every
/// tile that references the same url mirrors the same state.
class _AudioIndicator extends StatelessWidget {
  const _AudioIndicator({
    required this.url,
    required this.size,
    required this.color,
  });

  final String url;
  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<String?>(
      valueListenable: AudioBus.instance.loading,
      builder: (context, loading, _) {
        if (loading == url) {
          return SizedBox(
            width: size,
            height: size,
            child: Padding(
              padding: EdgeInsets.all(size * 0.15),
              child: CircularProgressIndicator(strokeWidth: 2, color: color),
            ),
          );
        }
        return ValueListenableBuilder<String?>(
          valueListenable: AudioBus.instance.playing,
          builder: (context, playing, _) {
            if (playing == url) return _Waveform(color: color, size: size);
            return DanaIcon(
              DanaIcons.volume2,
              size: size,
              frame: 20,
              color: color,
            );
          },
        );
      },
    );
  }
}

/// The static waveform shown while audio plays (Test-audio-playing): a row
/// of rounded vertical bars in the brand colour.
class _Waveform extends StatelessWidget {
  const _Waveform({required this.color, required this.size});

  final Color color;
  final double size;

  static const _ratios = [0.4, 0.7, 1.0, 0.55, 0.85, 0.35];

  @override
  Widget build(BuildContext context) {
    final bar = size * 0.1;

    return SizedBox(
      width: size,
      height: size,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          for (var i = 0; i < _ratios.length; i++) ...[
            if (i > 0) SizedBox(width: bar * 0.6),
            Container(
              width: bar,
              height: size * _ratios[i],
              decoration: BoxDecoration(
                color: color,
                borderRadius: BorderRadius.circular(bar),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// The audio-stem tile (Test-audio / Test-audio-playing): a brand-tinted
/// square with the audio glyph, "TAP TO LISTEN" beneath. Tapping plays and
/// swaps to the waveform until the clip ends; tapping again replays.
class _AudioStemTile extends StatelessWidget {
  const _AudioStemTile({required this.url});

  final String url;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: () => AudioBus.instance.play(url),
          child: Container(
            width: 96,
            height: 96,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: _brandTint,
              borderRadius: BorderRadius.circular(DanaRadius.card),
            ),
            child: _AudioIndicator(url: url, size: 34, color: DanaColors.brand),
          ),
        ),
        const SizedBox(height: 10),
        Text(
          _s(l, 'tap_to_listen', 'Tap to listen').toUpperCase(),
          style: const TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: DanaColors.textMuted,
            letterSpacing: 0.4,
          ),
        ),
      ],
    );
  }
}

/// An authenticated media image (Test-image, image options). The media
/// route needs the Bearer header, so the bytes come through [Api.getBytes]
/// (auth + token refresh) and render from memory, with graceful loading
/// and error boxes.
class _AuthImage extends StatefulWidget {
  const _AuthImage({required this.url, this.height});

  final String url;

  /// A fixed height for the stem image; null lets a parent (an option
  /// card's Positioned.fill) constrain it instead.
  final double? height;

  @override
  State<_AuthImage> createState() => _AuthImageState();
}

class _AuthImageState extends State<_AuthImage> {
  Uint8List? _bytes;
  bool _loading = true;
  bool _error = false;

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  @override
  void didUpdateWidget(_AuthImage old) {
    super.didUpdateWidget(old);
    if (old.url != widget.url) {
      _bytes = null;
      _loading = true;
      _error = false;
      _fetch();
    }
  }

  Future<void> _fetch() async {
    try {
      final bytes = await Api.instance.getBytes(widget.url);
      if (!mounted) return;
      setState(() {
        _bytes = Uint8List.fromList(bytes);
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = true;
        _loading = false;
      });
    }
  }

  Widget _box(Widget child) => Container(
        width: double.infinity,
        height: widget.height,
        color: DanaColors.surface,
        alignment: Alignment.center,
        child: child,
      );

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return _box(const SizedBox(
        width: 22,
        height: 22,
        child: CircularProgressIndicator(strokeWidth: 2, color: DanaColors.brand),
      ));
    }
    if (_error || _bytes == null) {
      return _box(const Icon(Icons.broken_image_outlined,
          color: DanaColors.textMuted, size: 28));
    }
    return Image.memory(
      _bytes!,
      height: widget.height,
      width: double.infinity,
      fit: BoxFit.cover,
      gaplessPlayback: true,
      errorBuilder: (_, _, _) => _box(const Icon(
          Icons.broken_image_outlined,
          color: DanaColors.textMuted,
          size: 28)),
    );
  }
}

/// Match Type frames: two columns, tap left (pink tint) then right; the
/// pair just made shows green, earlier pairs fade to muted; the final
/// pair fires the check automatically — there is no submit button.
///
// SPEC: the Match Type-wrong frame paints a mispaired attempt red before
// bouncing it back. The student payload deliberately excludes the pair
// key (NFR-5) and /check grades whole answers only, so per-pair
// verification is not possible client-side; a wrong mapping surfaces via
// the verdict sheet instead.
class _MatchPairs extends StatefulWidget {
  const _MatchPairs({
    super.key,
    required this.payload,
    required this.question,
    required this.matched,
    required this.verdict,
    required this.onChanged,
    required this.onCompleted,
  });

  final Map<String, dynamic> payload;
  final Map<String, dynamic> question;
  final Map<String, String> matched;
  final bool? verdict;
  final ValueChanged<Map<String, String>> onChanged;
  final VoidCallback onCompleted;

  @override
  State<_MatchPairs> createState() => _MatchPairsState();
}

class _MatchPairsState extends State<_MatchPairs> {
  String? _activeLeft;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final left = ((widget.payload['left'] as List?) ?? []).cast<String>();
    final right = ((widget.payload['right'] as List?) ?? []).cast<String>();
    final matched = widget.matched;

    // Content stem (e.g. "Match words with its synonyms") comes with the
    // question; the instruction prompt is its bilingual fallback.
    final stem = widget.payload['stem'] as String? ??
        l.pick(widget.question['prompt_tk'] as String?,
            widget.question['prompt_ru'] as String?);

    // Only the most recent pair shows green — older ones read as done
    // (Match Type-correct frame). Once the verdict sheet is up they all
    // read as done (Match Type-full frame).
    final latestLeft = matched.isEmpty || widget.verdict != null ? null : matched.keys.last;

    _MatchState stateForLeft(String item) {
      if (item == latestLeft) return _MatchState.justMatched;
      if (matched.containsKey(item)) return _MatchState.done;
      if (_activeLeft == item) return _MatchState.selected;
      return _MatchState.idle;
    }

    _MatchState stateForRight(String item) {
      if (latestLeft != null && matched[latestLeft] == item) {
        return _MatchState.justMatched;
      }
      if (matched.containsValue(item)) return _MatchState.done;
      return _MatchState.idle;
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _QuestionCard(
          label: _s(l, 'connect_pairs', 'Connect the pairs'),
          child: _Stem(stem.isEmpty ? _s(l, 'match_pairs_hint', 'Match the pairs') : stem),
        ),
        const SizedBox(height: 24),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                children: [
                  for (final item in left)
                    _PairTile(
                      text: item,
                      state: stateForLeft(item),
                      onTap: matched.containsKey(item) || widget.verdict != null
                          ? null
                          : () => setState(
                              () => _activeLeft = _activeLeft == item ? null : item),
                    ),
                ],
              ),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                children: [
                  for (final item in right)
                    _PairTile(
                      text: item,
                      state: stateForRight(item),
                      onTap: () {
                        final active = _activeLeft;
                        if (active == null ||
                            matched.containsValue(item) ||
                            widget.verdict != null) {
                          return;
                        }
                        final next = Map<String, String>.of(matched)..[active] = item;
                        setState(() => _activeLeft = null);
                        widget.onChanged(next);
                        if (next.length == left.length) widget.onCompleted();
                      },
                    ),
                ],
              ),
            ),
          ],
        ),
      ],
    );
  }
}

enum _MatchState { idle, selected, justMatched, done }

class _PairTile extends StatelessWidget {
  const _PairTile({required this.text, required this.state, required this.onTap});

  final String text;
  final _MatchState state;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final (bg, border, fg) = switch (state) {
      _MatchState.idle => (DanaColors.card, null, DanaColors.ink),
      _MatchState.selected => (_brandTint, DanaColors.brand, DanaColors.brand),
      _MatchState.justMatched => (_okTint, DanaColors.ok, DanaColors.ok),
      _MatchState.done => (DanaColors.card, null, DanaColors.chipUsed),
    };

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: GestureDetector(
        onTap: onTap,
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
          decoration: BoxDecoration(
            color: bg,
            borderRadius: BorderRadius.circular(DanaRadius.field),
            border: border == null ? null : Border.all(color: border, width: 1.5),
            boxShadow: state == _MatchState.idle || state == _MatchState.done
                ? const [
                    BoxShadow(
                        color: Color(0x08000000), blurRadius: 4, offset: Offset(0, 2)),
                  ]
                : null,
          ),
          child: Text(
            text,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w600,
              color: fg,
              letterSpacing: -0.3,
            ),
          ),
        ),
      ),
    );
  }
}

/// Fill in the blanks frames: the sentence flows as a paragraph with
/// inline gaps; tapping a pool word fills the first empty gap, tapping a
/// filled gap returns its word.
///
// SPEC: the -wrong frame colours each blank by its own correctness, but
// /check returns a single bool for the whole answer — so on a wrong
// verdict every filled blank reads red. Needs per-blank results in the
// check response to match the frame exactly.
class _FillBlanks extends StatelessWidget {
  const _FillBlanks({
    super.key,
    required this.payload,
    required this.slots,
    required this.verdict,
    required this.onChanged,
  });

  final Map<String, dynamic> payload;
  final List<String?> slots;
  final bool? verdict;
  final ValueChanged<List<String?>> onChanged;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final parts = _partsOf(payload);
    final bank = ((payload['word_bank'] as List?) ?? []).cast<String>();

    const sentence = TextStyle(
      fontSize: 19,
      fontWeight: FontWeight.w600,
      color: DanaColors.ink,
      letterSpacing: -0.38,
      height: 1.4,
    );

    // Count how many of each word are placed, so a bank holding the same
    // word twice greys out one chip at a time.
    final placedCount = <String, int>{};
    for (final word in slots.whereType<String>()) {
      placedCount[word] = (placedCount[word] ?? 0) + 1;
    }
    final seen = <String, int>{};

    final pieces = <Widget>[];
    var blankIndex = 0;

    for (final part in parts) {
      final text = part['t'];
      if (text is String) {
        // Word by word, so the paragraph wraps naturally around the gaps.
        for (final word in text.split(RegExp(r'\s+'))) {
          if (word.isNotEmpty) pieces.add(Text(word, style: sentence));
        }
        continue;
      }
      if (!part.containsKey('blank')) continue;

      final index = blankIndex++;
      final word = index < slots.length ? slots[index] : null;

      if (word == null) {
        pieces.add(Container(
          width: 56,
          height: 38,
          decoration: BoxDecoration(
            color: DanaColors.card,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: DanaColors.border, width: 1.5),
          ),
        ));
      } else {
        pieces.add(_PlacedChip(
          text: word,
          tone: _toneFor(verdict),
          onTap: verdict != null
              ? null
              : () => onChanged(List.of(slots)..[index] = null),
        ));
      }
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _QuestionCard(
          label: _s(l, 'fill_in_gaps', 'Fill in the gaps'),
          gap: 16,
          child: Wrap(
            spacing: 7,
            runSpacing: 12,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: pieces,
          ),
        ),
        const SizedBox(height: 20),
        Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final word in bank)
              Builder(builder: (context) {
                final index = (seen[word] = (seen[word] ?? 0) + 1) - 1;
                final used = index < (placedCount[word] ?? 0);

                return _PoolChip(
                  text: word,
                  used: used,
                  onTap: verdict != null
                      ? null
                      : () {
                          final firstEmpty = slots.indexOf(null);
                          if (firstEmpty == -1) return;
                          onChanged(List.of(slots)..[firstEmpty] = word);
                        },
                );
              }),
          ],
        ),
      ],
    );
  }
}

/// Fill letter space frames (FR-13.21): the sentence carries the revealed
/// leading letters as grey boxes and one white box per missing letter.
/// A single hidden text field owns the input, so the system keyboard
/// types straight into the boxes and focus advances box by box.
///
// SPEC: as with fill_blank, the -4 frame mixes green and red letters but
// /check returns one bool — a wrong verdict paints every typed box red.
class _FillLetterSpace extends StatefulWidget {
  const _FillLetterSpace({
    super.key,
    required this.payload,
    required this.slots,
    required this.verdict,
    required this.onChanged,
  });

  final Map<String, dynamic> payload;

  /// One entry per missing letter, in sentence order; null = empty box.
  final List<String?> slots;
  final bool? verdict;
  final ValueChanged<List<String?>> onChanged;

  @override
  State<_FillLetterSpace> createState() => _FillLetterSpaceState();
}

/// Slot-cursor editing: every empty box is its own tap target. Tapping a
/// box selects it (and re-opens the keyboard when it was dismissed); a
/// keystroke writes into the selected box and the cursor moves on to the
/// next empty one; backspace clears backwards — like a PIN field, not a
/// text line.
///
/// The keyboard still drives everything through one invisible TextField.
/// Its text is pinned to a zero-width sentinel so both directions are
/// observable: typed characters land AFTER the sentinel, and a backspace
/// deletes the sentinel itself (an empty field would swallow backspace
/// without any callback at all).
class _FillLetterSpaceState extends State<_FillLetterSpace> {
  static const _sentinel = '\u200B';

  final _field = TextEditingController();
  final _focus = FocusNode();

  /// The selected slot — where the next keystroke lands.
  late int _cursor;

  @override
  void initState() {
    super.initState();
    _cursor = _firstEmpty(widget.slots);
    _resetField();
    // The focus ring must follow the keyboard coming and going, not just
    // keystrokes.
    _focus.addListener(_onFocusChanged);
    // Summon the keyboard with the question — the boxes are the visible
    // cursor, there is nothing else to tap first.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _summonKeyboard();
    });
  }

  @override
  void dispose() {
    _focus.removeListener(_onFocusChanged);
    _field.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _onFocusChanged() {
    if (mounted) setState(() {});
  }

  /// requestFocus() alone is not enough: once the node holds focus, a
  /// second call is a no-op, so a keyboard the system dismissed (or
  /// never raised because the route was still animating) could never be
  /// brought back — taps looked dead. Asking the IME to show explicitly
  /// covers the already-focused case.
  void _summonKeyboard() {
    _resetField();
    if (_focus.hasFocus) {
      SystemChannels.textInput.invokeMethod('TextInput.show');
    } else {
      _focus.requestFocus();
    }
  }

  /// Programmatic set — does not re-enter [_onFieldChanged], which only
  /// fires for user edits.
  void _resetField() {
    _field.value = const TextEditingValue(
      text: _sentinel,
      selection: TextSelection.collapsed(offset: 1),
    );
  }

  static int _firstEmpty(List<String?> slots) {
    final i = slots.indexWhere((s) => s == null);
    return i == -1 ? 0 : i;
  }

  /// The next empty slot after [from]; when none is left, simply the
  /// next box (clamped), so continued typing overwrites in place.
  static int _nextCursor(List<String?> slots, int from) {
    for (var i = from + 1; i < slots.length; i++) {
      if (slots[i] == null) return i;
    }
    return (from + 1).clamp(0, slots.length - 1);
  }

  void _onFieldChanged(String text) {
    final slots = List<String?>.of(widget.slots);
    if (slots.isEmpty) return;
    var changed = false;

    if (text.isEmpty) {
      // The sentinel is gone — backspace. Clear the selected box, or
      // step back onto the previous one and clear that.
      if (slots[_cursor] != null) {
        slots[_cursor] = null;
        changed = true;
      } else if (_cursor > 0) {
        _cursor--;
        changed = slots[_cursor] != null;
        slots[_cursor] = null;
      }
    } else {
      // Whatever the keyboard delivered beyond the sentinel, in order —
      // one character per box. Multi-char bursts (fast typing, paste)
      // just walk the cursor forward.
      for (final ch in text.replaceAll(_sentinel, '').split('')) {
        slots[_cursor] = ch;
        changed = true;
        _cursor = _nextCursor(slots, _cursor);
      }
    }

    _resetField();
    setState(() {});
    if (changed) widget.onChanged(slots);
  }

  void _selectBox(int index) {
    setState(() => _cursor = index);
    _summonKeyboard();
  }

  /// One editable box for hidden slot [index], as its own tap target while
  /// answering. The SELECTED box carries the focus ring — filled or not,
  /// that is where the next keystroke lands.
  Widget _editableBox(int index) {
    final letter = index < widget.slots.length ? widget.slots[index] : null;

    final box = _LetterBox(
      letter: letter,
      focused:
          widget.verdict == null && index == _cursor && _focus.hasFocus,
      tone: letter != null ? _toneFor(widget.verdict) : null,
    );

    if (widget.verdict != null) return box;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: () => _selectBox(index),
      child: box,
    );
  }

  /// A word's boxes kept together on one line — they must not wrap apart.
  Widget _wordRow(List<Widget> boxes) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 0; i < boxes.length; i++) ...[
            if (i > 0) const SizedBox(width: 5),
            boxes[i],
          ],
        ],
      );

  /// v2 mask (`S<e>ve<n>`): every letter is a box — visible letters as
  /// grey given boxes in place, hidden positions as white editable boxes,
  /// filled in reading order.
  List<Widget> _v2Pieces() {
    final words = _maskWords(widget.payload['mask'] as String);
    final pieces = <Widget>[];
    var slot = 0;

    for (final word in words) {
      final boxes = <Widget>[];
      for (final cell in word) {
        if (cell.hidden) {
          boxes.add(_editableBox(slot));
          slot++;
        } else {
          boxes.add(_LetterBox.given(cell.char ?? ''));
        }
      }
      pieces.add(_wordRow(boxes));
    }
    return pieces;
  }

  /// Legacy {text, reveal}: plain words, then a blank whose revealed
  /// prefix is grey given boxes and whose missing tail is editable boxes.
  List<Widget> _legacyPieces() {
    const sentence = TextStyle(
      fontSize: 19,
      fontWeight: FontWeight.w600,
      color: DanaColors.ink,
      letterSpacing: -0.38,
      height: 1.4,
    );

    final pieces = <Widget>[];
    var letterIndex = 0;

    for (final part in _partsOf(widget.payload)) {
      final text = part['t'];
      if (text is String) {
        for (final word in text.split(RegExp(r'\s+'))) {
          if (word.isNotEmpty) pieces.add(Text(word, style: sentence));
        }
        continue;
      }

      final blank = part['blank'];
      if (blank is! Map) continue;

      final given = blank['given'] as String? ?? '';
      final count = (blank['count'] as num?)?.toInt() ?? 0;

      final boxes = <Widget>[
        for (final letter in given.split('')) _LetterBox.given(letter),
        for (var i = 0; i < count; i++) _editableBox(letterIndex + i),
      ];
      letterIndex += count;

      pieces.add(_wordRow(boxes));
    }
    return pieces;
  }

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final total = widget.slots.length;

    final pieces =
        widget.payload['mask'] is String ? _v2Pieces() : _legacyPieces();

    return GestureDetector(
      // Tapping anywhere on the exercise re-opens the keyboard. Opaque,
      // so the empty space between boxes and lines counts as "anywhere" —
      // by default only pixels a child paints would register.
      behavior: HitTestBehavior.opaque,
      onTap: widget.verdict == null ? _summonKeyboard : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _QuestionCard(
            label: _s(l, 'type_fill_letter_space', 'Fill letter spaces'),
            gap: 16,
            child: Wrap(
              spacing: 7,
              runSpacing: 12,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: pieces,
            ),
          ),
          // The invisible field the keyboard actually edits. 1x1 and
          // transparent rather than Offstage — an offstage TextField
          // cannot hold platform text-input focus.
          Opacity(
            opacity: 0,
            child: SizedBox(
              width: 1,
              height: 1,
              child: TextField(
                controller: _field,
                focusNode: _focus,
                onChanged: _onFieldChanged,
                autocorrect: false,
                enableSuggestions: false,
                // Plain text, predictions off via the flags above.
                // NOT visiblePassword: several Samsung keyboards refuse
                // to appear for it, which left this exercise dead.
                keyboardType: TextInputType.text,
                inputFormatters: [
                  FilteringTextInputFormatter.deny(RegExp(r'\s')),
                  // Sentinel + a typing burst; slot writes clamp anyway.
                  LengthLimitingTextInputFormatter(total + 8),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _LetterBox extends StatelessWidget {
  const _LetterBox({this.letter, this.focused = false, this.tone}) : _given = false;

  /// A revealed leading letter: flat grey box, not editable.
  const _LetterBox.given(String this.letter)
      : focused = false,
        tone = null,
        _given = true;

  final String? letter;
  final bool focused;
  final _Tone? tone;
  final bool _given;

  @override
  Widget build(BuildContext context) {
    final Color bg;
    Color? border;
    final Color fg;

    if (_given) {
      bg = DanaColors.surface;
      fg = DanaColors.textMuted;
    } else if (tone != null) {
      bg = tone!.fill;
      border = tone!.stroke;
      fg = tone!.stroke;
    } else {
      bg = DanaColors.card;
      border = focused ? DanaColors.brand : DanaColors.border;
      fg = DanaColors.ink;
    }

    return Container(
      width: 32,
      height: 40,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(8),
        border: border == null ? null : Border.all(color: border, width: 1.5),
      ),
      child: Text(
        letter ?? '',
        style: TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.w600,
          letterSpacing: -0.36,
          color: fg,
        ),
      ),
    );
  }
}

/// Reorder frames: the sentence is built as chips inside the instruction
/// card; the pool keeps every word visible, greying the placed ones.
class _Reorder extends StatelessWidget {
  const _Reorder({
    super.key,
    required this.payload,
    required this.question,
    required this.rule,
    required this.selected,
    required this.verdict,
    required this.onChanged,
  });

  final Map<String, dynamic> payload;
  final Map<String, dynamic> question;

  /// The `rule_en` instruction, preferred over the legacy bilingual
  /// prompt when present (§6).
  final String? rule;
  final List<String> selected;
  final bool? verdict;
  final ValueChanged<List<String>> onChanged;

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;
    final tokens = ((payload['tokens'] as List?) ?? []).cast<String>();

    // v2 content is English-only (rule_en); the bilingual prompt is the
    // legacy fallback.
    final stem = rule ??
        l.pick(question['prompt_tk'] as String?,
            question['prompt_ru'] as String?);

    final placed = <String, int>{};
    for (final word in selected) {
      placed[word] = (placed[word] ?? 0) + 1;
    }
    final seen = <String, int>{};

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _QuestionCard(
          label: _s(l, 'put_in_order', 'Re-order the words'),
          gap: 16,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _Stem(stem.isEmpty
                  ? _s(l, 'reorder_instruction',
                      'Re-order the words to make correct sentences.')
                  : stem),
              const SizedBox(height: 20),
              Wrap(
                spacing: 10,
                runSpacing: 10,
                children: [
                  for (var i = 0; i < selected.length; i++)
                    _PlacedChip(
                      text: selected[i],
                      tone: _toneFor(verdict),
                      onTap: verdict != null
                          ? null
                          : () => onChanged(List.of(selected)..removeAt(i)),
                    ),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final word in tokens)
              Builder(builder: (context) {
                final index = (seen[word] = (seen[word] ?? 0) + 1) - 1;
                final used = index < (placed[word] ?? 0);

                return _PoolChip(
                  text: word,
                  used: used,
                  onTap: verdict != null
                      ? null
                      : () => onChanged([...selected, word]),
                );
              }),
          ],
        ),
      ],
    );
  }
}
