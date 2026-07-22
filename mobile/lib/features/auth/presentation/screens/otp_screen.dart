import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/theme/app_spacing.dart';
import '../providers/otp_view_model.dart';

/// OTP verification screen: 6-digit code, countdown + resend, verify. No
/// real API — `OtpViewModel` calls the mocked repository, which always
/// succeeds once 6 digits are submitted.
///
/// [redirectTo] is the original protected route (soft-auth
/// return-to-intent) — on success, navigates there instead of always
/// landing on Home.
class OtpScreen extends ConsumerStatefulWidget {
  const OtpScreen({required this.phoneNumber, this.redirectTo, super.key});

  final String phoneNumber;
  final String? redirectTo;

  @override
  ConsumerState<OtpScreen> createState() => _OtpScreenState();
}

class _OtpScreenState extends ConsumerState<OtpScreen> {
  final _otpController = TextEditingController();

  @override
  void dispose() {
    _otpController.dispose();
    super.dispose();
  }

  Future<void> _verify() async {
    final String otp = _otpController.text.trim();
    if (otp.length != 6) {
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Enter the 6-digit code.')));
      return;
    }
    final session = await ref
        .read(otpViewModelProvider.notifier)
        .verifyOtp(phoneNumber: widget.phoneNumber, otp: otp);
    if (!mounted) {
      return;
    }
    if (session != null) {
      context.go(widget.redirectTo ?? '/home');
    } else {
      final Object? error = ref.read(otpViewModelProvider).error;
      final String message = error is Failure ? error.message : 'Verification failed. Please try again.';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    }
  }

  Future<void> _resend() async {
    await ref.read(otpViewModelProvider.notifier).resendOtp(widget.phoneNumber);
  }

  @override
  Widget build(BuildContext context) {
    final AsyncValue<void> state = ref.watch(otpViewModelProvider);
    final bool isLoading = state.isLoading;
    final int secondsRemaining = ref.watch(otpResendCountdownProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Verify Phone')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.space4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              Text('Enter verification code', style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: AppSpacing.space2),
              Text(
                'A 6-digit code was sent to ${widget.phoneNumber}.',
                style: Theme.of(context).textTheme.bodyLarge,
              ),
              const SizedBox(height: AppSpacing.space5),
              TextField(
                controller: _otpController,
                keyboardType: TextInputType.number,
                maxLength: 6,
                enabled: !isLoading,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineSmall,
                decoration: const InputDecoration(counterText: '', labelText: 'OTP Code'),
              ),
              const SizedBox(height: AppSpacing.space3),
              Center(
                child: secondsRemaining > 0
                    ? Text('Resend code in ${secondsRemaining}s')
                    : TextButton(onPressed: isLoading ? null : _resend, child: const Text('Resend Code')),
              ),
              const SizedBox(height: AppSpacing.space5),
              ElevatedButton(
                onPressed: isLoading ? null : _verify,
                child: isLoading
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Verify'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
