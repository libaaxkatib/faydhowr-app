import 'dart:async';

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../../core/error/result.dart';
import '../../../../core/session/auth_session.dart';
import '../../../../core/session/session_provider.dart';
import 'auth_providers.dart';

part 'otp_view_model.g.dart';

/// OTP resend cooldown — matches the already-approved business rule
/// (docs/02_SRS.md FR-002B: 60-second resend cooldown), applied here even
/// though verification is mocked, so the UI behaves like the real flow
/// will once a backend exists.
const int otpResendCooldownSeconds = 60;

/// Resend countdown, ticking down from [otpResendCooldownSeconds] to 0.
@riverpod
class OtpResendCountdown extends _$OtpResendCountdown {
  Timer? _timer;

  @override
  int build() {
    ref.onDispose(() => _timer?.cancel());
    // Only ever RETURN the initial state here — never assign `state =`
    // during `build()` itself (Riverpod does not support mutating state
    // before `build()` has returned). The timer's callback runs later,
    // asynchronously, which is safe.
    _scheduleTicker();
    return otpResendCooldownSeconds;
  }

  void _scheduleTicker() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (Timer timer) {
      if (state <= 1) {
        timer.cancel();
        state = 0;
      } else {
        state = state - 1;
      }
    });
  }

  /// Called from a user action (Resend), well outside `build()` — safe to
  /// assign `state` directly here.
  void restart() {
    state = otpResendCooldownSeconds;
    _scheduleTicker();
  }
}

/// OTP screen ViewModel — verify and resend actions.
@riverpod
class OtpViewModel extends _$OtpViewModel {
  @override
  FutureOr<void> build() {}

  Future<AuthSession?> verifyOtp({required String phoneNumber, required String otp}) async {
    state = const AsyncLoading<void>();
    final Result<AuthSession> result = await ref
        .read(authenticationRepositoryProvider)
        .verifyOtp(phoneNumber: phoneNumber, otp: otp);
    switch (result) {
      case Ok<AuthSession>(:final value):
        ref.read(sessionProvider.notifier).update(value);
        state = const AsyncData<void>(null);
        return value;
      case Err<AuthSession>(:final failure):
        state = AsyncError<void>(failure, StackTrace.current);
        return null;
    }
  }

  Future<bool> resendOtp(String phoneNumber) async {
    state = const AsyncLoading<void>();
    final Result<void> result = await ref.read(authenticationRepositoryProvider).requestOtp(phoneNumber);
    switch (result) {
      case Ok<void>():
        state = const AsyncData<void>(null);
        ref.read(otpResendCountdownProvider.notifier).restart();
        return true;
      case Err<void>(:final failure):
        state = AsyncError<void>(failure, StackTrace.current);
        return false;
    }
  }
}
