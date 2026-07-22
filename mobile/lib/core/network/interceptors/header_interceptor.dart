import 'package:dio/dio.dart';

/// Sets baseline headers on every request — docs/09_Flutter_Architecture.md
/// §6.3, interceptor stack position 1.
class HeaderInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers.putIfAbsent('Accept', () => 'application/json');
    options.headers.putIfAbsent('Accept-Language', () => 'en');
    handler.next(options);
  }
}
