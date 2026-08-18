// Dumps every interface string as JSON, for the translation review sheet.
//
// Reads through L's own public API (definedKeys + t()) rather than parsing
// the Dart source, so the values are exactly what the app renders —
// including the pipe-separated plural forms and the {placeholder} tokens.
//
//   dart run tool/dump_strings.dart > strings.json
//
// The reverse trip (edited sheet -> l10n.dart) is tool/apply_strings.dart.
import 'dart:convert';

import 'package:dana_app/core/l10n.dart';

void main() {
  final out = <Map<String, dynamic>>[];

  final keys = L.definedKeys.toList()..sort();

  for (final key in keys) {
    final row = <String, dynamic>{
      'key': key,
      'plural': L.isPlural(key),
      'missing': L.missingLanguages(key),
    };

    for (final lang in L.supported) {
      row[lang] = L(lang).t(key);
    }

    out.add(row);
  }

  print(const JsonEncoder.withIndent('  ').convert(out));
}
