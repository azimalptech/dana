import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/icons.dart';
import '../core/theme.dart';
import '../main.dart';

/// FR-12.13: a picker on first launch. No default is guessed.
class LanguagePickerScreen extends StatelessWidget {
  const LanguagePickerScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: DanaColors.brand,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Image(
                image: AssetImage('assets/brand/logo.png'),
                width: 180,
              ),
              const SizedBox(height: 48),
              // All three languages name themselves (FR-13.22) — no
              // interface language exists yet to translate into.
              const Text(
                'Dili saýlaň  ·  Выберите язык  ·  Choose your language',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 14, color: DanaColors.onBrandMuted),
              ),
              const SizedBox(height: 24),
              _LanguageButton(
                label: 'English',
                onTap: () => AppState.instance.setLanguage('en'),
              ),
              const SizedBox(height: 12),
              _LanguageButton(
                label: 'Türkmen dili',
                onTap: () => AppState.instance.setLanguage('tk'),
              ),
              const SizedBox(height: 12),
              _LanguageButton(
                label: 'Русский язык',
                onTap: () => AppState.instance.setLanguage('ru'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LanguageButton extends StatelessWidget {
  const _LanguageButton({required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(DanaRadius.field),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(DanaRadius.field),
        child: Container(
          height: 56,
          alignment: Alignment.center,
          child: Text(
            label,
            style: const TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.w600,
              color: DanaColors.brand,
            ),
          ),
        ),
      ),
    );
  }
}

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _login = TextEditingController(text: '+993');
  final _password = TextEditingController();
  bool _obscure = true;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _login.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final user = await Api.instance.login(_login.text.trim(), _password.text);
      AppState.instance.setUser(user);
    } on ApiError catch (e) {
      setState(() => _error = e.message(AppState.instance.language ?? 'tk'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return Scaffold(
      body: Column(
        children: [
          // Header banner — brand fill, 24px bottom corners (Figma 1:723).
          Container(
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
                padding: const EdgeInsets.fromLTRB(24, 8, 24, 36),
                child: Column(
                  children: [
                    // The wordmark blends into the banner — same brand
                    // fill baked into the PNG as behind it.
                    const Image(
                      image: AssetImage('assets/brand/logo.png'),
                      width: 150,
                    ),
                    const SizedBox(height: 12),
                    Text(
                      l.t('welcome_back'),
                      style: const TextStyle(
                        fontSize: 28,
                        fontWeight: FontWeight.w700,
                        color: Colors.white,
                        height: 34 / 28,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      l.t('login_subtitle'),
                      style: const TextStyle(fontSize: 14, color: DanaColors.onBrandMuted),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  FieldLabel(l.t('phone_number')),
                  TextField(
                    controller: _login,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      // Figma sizes the field icons at 24px with 16px of
                      // lead-in; Flutter's default prefix padding is
                      // tighter, so it is set explicitly.
                      prefixIcon: Padding(
                        padding: EdgeInsets.fromLTRB(16, 12, 10, 12),
                        child: DanaIcon(DanaIcons.phone),
                      ),
                      prefixIconConstraints: BoxConstraints(minWidth: 0, minHeight: 0),
                      hintText: '+993 65 002233',
                    ),
                  ),
                  const SizedBox(height: 20),
                  FieldLabel(l.t('password')),
                  TextField(
                    controller: _password,
                    obscureText: _obscure,
                    onSubmitted: (_) => _submit(),
                    decoration: InputDecoration(
                      prefixIcon: const Padding(
                        padding: EdgeInsets.fromLTRB(16, 12, 10, 12),
                        child: DanaIcon(DanaIcons.lock),
                      ),
                      prefixIconConstraints:
                          const BoxConstraints(minWidth: 0, minHeight: 0),
                      suffixIcon: GestureDetector(
                        onTap: () => setState(() => _obscure = !_obscure),
                        behavior: HitTestBehavior.opaque,
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(10, 12, 16, 12),
                          child: DanaIcon(
                            DanaIcons.eye,
                            size: 18,
                            color: _obscure ? DanaColors.textMuted : DanaColors.brand,
                          ),
                        ),
                      ),
                      suffixIconConstraints:
                          const BoxConstraints(minWidth: 0, minHeight: 0),
                    ),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 16),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: DanaColors.error.withValues(alpha: 0.08),
                        borderRadius: BorderRadius.circular(DanaRadius.field),
                      ),
                      child: Text(
                        _error!,
                        style: const TextStyle(color: DanaColors.error, fontSize: 14),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
          // Pinned to the bottom, as in the frame — not after the fields.
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 20),
              child: SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: _busy ? null : _submit,
                  child: _busy
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            color: Colors.white,
                            strokeWidth: 2,
                          ),
                        )
                      : Text(l.t('log_in')),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
