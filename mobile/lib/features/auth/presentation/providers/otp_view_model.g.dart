// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'otp_view_model.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning
/// Resend countdown, ticking down from [otpResendCooldownSeconds] to 0.

@ProviderFor(OtpResendCountdown)
final otpResendCountdownProvider = OtpResendCountdownProvider._();

/// Resend countdown, ticking down from [otpResendCooldownSeconds] to 0.
final class OtpResendCountdownProvider
    extends $NotifierProvider<OtpResendCountdown, int> {
  /// Resend countdown, ticking down from [otpResendCooldownSeconds] to 0.
  OtpResendCountdownProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'otpResendCountdownProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$otpResendCountdownHash();

  @$internal
  @override
  OtpResendCountdown create() => OtpResendCountdown();

  /// {@macro riverpod.override_with_value}
  Override overrideWithValue(int value) {
    return $ProviderOverride(
      origin: this,
      providerOverride: $SyncValueProvider<int>(value),
    );
  }
}

String _$otpResendCountdownHash() =>
    r'98dd6b4f277dd56ca6f6f91a03c8c1ca7f294e67';

/// Resend countdown, ticking down from [otpResendCooldownSeconds] to 0.

abstract class _$OtpResendCountdown extends $Notifier<int> {
  int build();
  @$mustCallSuper
  @override
  WhenComplete runBuild() {
    final ref = this.ref as $Ref<int, int>;
    final element =
        ref.element
            as $ClassProviderElement<
              AnyNotifier<int, int>,
              int,
              Object?,
              Object?
            >;
    return element.handleCreate(ref, build);
  }
}

/// OTP screen ViewModel — verify and resend actions.

@ProviderFor(OtpViewModel)
final otpViewModelProvider = OtpViewModelProvider._();

/// OTP screen ViewModel — verify and resend actions.
final class OtpViewModelProvider
    extends $AsyncNotifierProvider<OtpViewModel, void> {
  /// OTP screen ViewModel — verify and resend actions.
  OtpViewModelProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'otpViewModelProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$otpViewModelHash();

  @$internal
  @override
  OtpViewModel create() => OtpViewModel();
}

String _$otpViewModelHash() => r'ae6aebe94d4f7a94b36b3c07298da3a792777844';

/// OTP screen ViewModel — verify and resend actions.

abstract class _$OtpViewModel extends $AsyncNotifier<void> {
  FutureOr<void> build();
  @$mustCallSuper
  @override
  WhenComplete runBuild() {
    final ref = this.ref as $Ref<AsyncValue<void>, void>;
    final element =
        ref.element
            as $ClassProviderElement<
              AnyNotifier<AsyncValue<void>, void>,
              AsyncValue<void>,
              Object?,
              Object?
            >;
    return element.handleCreate(ref, build);
  }
}
