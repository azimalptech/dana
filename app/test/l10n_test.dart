import 'dart:io';

import 'package:dana_app/core/l10n.dart';
import 'package:flutter_test/flutter_test.dart';

/// Guards the translation table against the two ways it silently breaks.
///
/// A missing key does not throw — `t()` returns the key itself, so the UI
/// shows "25 words" where it meant "25 слов". That shipped once already.
/// A key present in only one language is worse: it looks correct in
/// whichever language the developer happens to test.
void main() {
  final source = Directory('lib')
      .listSync(recursive: true)
      .whereType<File>()
      .where((file) => file.path.endsWith('.dart'))
      .toList();

  /// Matches `l.t('key')`, `l.f('key'` and `l.plural('key'`.
  final usage = RegExp(r"""\bl\.(?:t|f|plural)\(\s*'([a-z0-9_]+)'""");

  /// Only `t()` — the lookup that returns the stored string verbatim.
  final plainLookup = RegExp(r"""\bl\.t\(\s*'([a-z0-9_]+)'""");

  test('every key used in the UI is defined', () {
    final missing = <String, List<String>>{};

    for (final file in source) {
      for (final match in usage.allMatches(file.readAsStringSync())) {
        final key = match.group(1)!;

        if (!L.definedKeys.contains(key)) {
          missing.putIfAbsent(key, () => []).add(file.path);
        }
      }
    }

    expect(
      missing,
      isEmpty,
      reason: 'Undefined translation keys would render as their own name:\n'
          '${missing.entries.map((e) => '  ${e.key} — used in ${e.value.join(', ')}').join('\n')}',
    );
  });

  test('every defined key has Turkmen, Russian and English', () {
    final incomplete = <String, List<String>>{};

    for (final key in L.definedKeys) {
      final missing = L.missingLanguages(key);

      if (missing.isNotEmpty) {
        incomplete[key] = missing;
      }
    }

    expect(
      incomplete,
      isEmpty,
      reason: 'Keys missing a language (FR-13.22 requires all three):\n'
          '${incomplete.entries.map((e) => '  ${e.key} — missing ${e.value.join(', ')}').join('\n')}',
    );
  });

  test('placeholder patterns are filled, not left literal', () {
    final l = L('tk');

    expect(
      l.f('summary_words', {'n': 25, 'u': 2}),
      isNot(contains('{')),
      reason: 'An unreplaced {placeholder} means the caller passed the wrong name.',
    );
    expect(l.f('summary_words', {'n': 25, 'u': 2}), contains('25'));
  });

  test('counted nouns are read through plural(), not t()', () {
    final wrong = <String, List<String>>{};

    for (final file in source) {
      for (final match in plainLookup.allMatches(file.readAsStringSync())) {
        final key = match.group(1)!;

        if (L.isPlural(key)) {
          wrong.putIfAbsent(key, () => []).add(file.path);
        }
      }
    }

    expect(
      wrong,
      isEmpty,
      reason: 'These keys store one|few|many forms; t() would print the '
          'bars on screen:\n'
          '${wrong.entries.map((e) => '  ${e.key} — used in ${e.value.join(', ')}').join('\n')}',
    );
  });

  test('plural() picks the Russian form the count requires', () {
    final ru = L('ru');

    expect(ru.plural('days', 1), 'день');
    expect(ru.plural('days', 2), 'дня');
    expect(ru.plural('days', 5), 'дней');
    // The teens are the exception the last digit alone gets wrong.
    expect(ru.plural('days', 11), 'дней');
    expect(ru.plural('days', 21), 'день');
    expect(ru.plural('days', 112), 'дней');
    expect(ru.plural('points', 0), 'баллов');

    // Follows «из», so genitive: "из 1 юнита", not "из 1 юнит".
    expect(ru.plural('units_noun', 1), 'юнита');
    expect(ru.plural('units_noun', 3), 'юнитов');
    expect(ru.plural('sections_noun', 1), 'раздела');
    expect(ru.plural('sections_noun', 5), 'разделов');

    // Turkmen does not inflect after a number.
    expect(L('tk').plural('days', 1), 'gün');
    expect(L('tk').plural('days', 5), 'gün');

    // English stores one|many (FR-13.22).
    expect(L('en').plural('days', 1), 'day');
    expect(L('en').plural('days', 2), 'days');
    expect(L('en').plural('tries', 1), 'try');
    expect(L('en').plural('tries', 3), 'tries');
  });

  test('pick() falls back rather than returning empty', () {
    final ru = L('ru');

    expect(ru.pick('türkmençe', null), 'türkmençe');
    expect(ru.pick(null, 'по-русски'), 'по-русски');
    expect(ru.pick('türkmençe', 'по-русски'), 'по-русски');
    expect(L('tk').pick('türkmençe', 'по-русски'), 'türkmençe');

    // Three-language pick (FR-13.22): English prefers the en column and
    // falls back to Turkmen before Russian.
    expect(L('en').pick('türkmençe', 'по-русски', 'english'), 'english');
    expect(L('en').pick('türkmençe', 'по-русски'), 'türkmençe');
    expect(L('en').pick(null, 'по-русски'), 'по-русски');
    expect(ru.pick('türkmençe', 'по-русски', 'english'), 'по-русски');
  });
}
