import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/theme/app_spacing.dart';
import '../../domain/validators/phone_validator.dart';
import '../providers/login_view_model.dart';

/// Login screen: phone number entry only. Somalia E.164 validation
/// (docs/02_SRS.md FR-002A); no backend call beyond the mocked OTP
/// request.
///
/// [redirectTo] is the protected route the guest was trying to reach
/// (soft-auth return-to-intent) — threaded through to OTP so a successful
/// verification resumes it instead of always landing on Home.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({this.redirectTo, super.key});

  final String? redirectTo;

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneController = TextEditingController(text: PhoneValidator.defaultCountryCode);

  @override
  void dispose() {
    _phoneController.dispose();
    super.dispose();
  }

  Future<void> _continue() async {
    if (!(_formKey.currentState?.validate() ?? false)) {
      return;
    }
    final String phoneNumber = _phoneController.text.trim();
    final bool success = await ref.read(loginViewModelProvider.notifier).requestOtp(phoneNumber);
    if (!mounted) {
      return;
    }
    if (success) {
      final Map<String, String> query = <String, String>{
        'phone': phoneNumber,
        if (widget.redirectTo != null) 'redirect': widget.redirectTo!,
      };
      context.go(Uri(path: '/otp', queryParameters: query).toString());
    } else {
      final Object? error = ref.read(loginViewModelProvider).error;
      final String message = error is Failure ? error.message : 'Something went wrong. Please try again.';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final AsyncValue<void> state = ref.watch(loginViewModelProvider);
    final bool isLoading = state.isLoading;

    return Scaffold(
      appBar: AppBar(title: const Text('Login')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.space4),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                Text('Enter your phone number', style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: AppSpacing.space2),
                Text(
                  'We will send you a verification code.',
                  style: Theme.of(context).textTheme.bodyLarge,
                ),
                const SizedBox(height: AppSpacing.space5),
                TextFormField(
                  controller: _phoneController,
                  keyboardType: TextInputType.phone,
                  enabled: !isLoading,
                  decoration: const InputDecoration(
                    labelText: 'Phone Number',
                    hintText: '+252 61 234 5678',
                  ),
                  validator: PhoneValidator.validate,
                ),
                const SizedBox(height: AppSpacing.space5),
                ElevatedButton(
                  onPressed: isLoading ? null : _continue,
                  child: isLoading
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Continue'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
