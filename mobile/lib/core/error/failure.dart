/// Typed failure hierarchy — docs/09_Flutter_Architecture.md §11.
///
/// `TimeoutFailure` refines the frozen architecture's `NetworkFailure`
/// ("Offline, timeout, DNS") into its own type so callers can distinguish a
/// slow/unreachable server from a genuinely offline device. This is an
/// additive, non-conflicting refinement, not a change to the frozen
/// taxonomy's intent — flagged here for traceability.
sealed class Failure {
  const Failure(this.message, {this.code});

  final String message;
  final String? code;
}

final class NetworkFailure extends Failure {
  const NetworkFailure({String message = 'No network connection.', String? code})
      : super(message, code: code);
}

final class TimeoutFailure extends Failure {
  const TimeoutFailure({String message = 'The request timed out.', String? code})
      : super(message, code: code);
}

final class ValidationFailure extends Failure {
  const ValidationFailure({
    String message = 'Validation failed.',
    String? code = 'VALIDATION_ERROR',
    this.errors,
  }) : super(message, code: code);

  final Map<String, List<String>>? errors;
}

final class AuthFailure extends Failure {
  const AuthFailure({String message = 'Authentication required.', String? code = 'UNAUTHENTICATED'})
      : super(message, code: code);
}

final class ForbiddenFailure extends Failure {
  const ForbiddenFailure({String message = 'You do not have permission to do this.', String? code})
      : super(message, code: code);
}

final class NotFoundFailure extends Failure {
  const NotFoundFailure({String message = 'The requested resource was not found.', String? code})
      : super(message, code: code);
}

final class ConflictFailure extends Failure {
  const ConflictFailure({String message = 'This action conflicts with the current state.', String? code})
      : super(message, code: code);
}

final class RateLimitFailure extends Failure {
  const RateLimitFailure({
    String message = 'Too many requests. Please try again shortly.',
    String? code = 'RATE_LIMITED',
    this.retryAfter,
  }) : super(message, code: code);

  final Duration? retryAfter;
}

final class ServerFailure extends Failure {
  const ServerFailure({String message = 'Something went wrong on our end.', String? code})
      : super(message, code: code);
}

final class UnknownFailure extends Failure {
  const UnknownFailure({String message = 'An unexpected error occurred.', String? code})
      : super(message, code: code);
}
