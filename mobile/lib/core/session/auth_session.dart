import 'package:flutter/foundation.dart';

/// A logged-in session. `null` elsewhere in the app means "not
/// authenticated". Lives in `core/session/` rather than
/// `features/auth/domain/` because both `core/router/` (redirect guard)
/// and `features/auth/` need it, and `core` must never import from
/// `features/` (docs/09_Flutter_Architecture.md §3.1 dependency rule) —
/// features depending on core is the allowed direction.
@immutable
class AuthSession {
  const AuthSession({required this.phoneNumber, required this.accessToken});

  final String phoneNumber;
  final String accessToken;
}
