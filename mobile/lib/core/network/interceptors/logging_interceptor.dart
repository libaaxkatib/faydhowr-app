import 'package:dio/dio.dart';

import '../../logging/logging_service.dart';

/// Request/response logging — docs/09_Flutter_Architecture.md §6.3 position
/// 5. Delegates to the shared [LoggingService] (Milestone F3) rather than
/// printing directly, so all app logging goes through one reusable path.
class LoggingInterceptor extends Interceptor {
  const LoggingInterceptor(this._logging);

  final LoggingService _logging;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    _logging.debug('→ ${options.method} ${options.uri}', tag: 'dio');
    handler.next(options);
  }

  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    _logging.debug(
      '← ${response.statusCode} ${response.requestOptions.method} ${response.requestOptions.uri}',
      tag: 'dio',
    );
    handler.next(response);
  }
}
