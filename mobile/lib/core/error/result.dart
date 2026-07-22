import 'failure.dart';

/// Minimal result wrapper pairing with [Failure] — docs/09_Flutter_Architecture.md
/// §11. Deliberately deferred until the first repository needed it
/// (Milestone F2's own scope notes); Authentication is that first
/// repository, so this now lives in `core/error/` as reusable
/// infrastructure rather than a one-off type inside `features/auth/`.
sealed class Result<T> {
  const Result();
}

final class Ok<T> extends Result<T> {
  const Ok(this.value);

  final T value;
}

final class Err<T> extends Result<T> {
  const Err(this.failure);

  final Failure failure;
}
