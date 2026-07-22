import 'package:dio/dio.dart';

import '../../logging/logging_service.dart';

/// Error logging — docs/09_Flutter_Architecture.md §6.3 position 4.
///
/// Does not convert or swallow the exception: typed [Failure] mapping is a
/// separate concern owned by `FailureMapper`, applied by the caller (future
/// repositories), not inside Dio's pipeline. Delegates to the shared
/// [LoggingService] (Milestone F3).
class ErrorInterceptor extends Interceptor {
  const ErrorInterceptor(this._logging);

  final LoggingService _logging;

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    _logging.error(
      '${err.requestOptions.method} ${err.requestOptions.uri} status=${err.response?.statusCode}',
      error: err.type.name,
      tag: 'dio',
    );
    handler.next(err);
  }
}
