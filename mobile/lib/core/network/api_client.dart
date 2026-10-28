import 'package:dio/dio.dart';
import 'package:nubloca/core/storage/token_storage.dart';

const apiBaseUrl = 'https://nubloca.clarfiscal.ro/api/';

class ApiClient {
  ApiClient({required this.tokenStorage, Dio? dio})
    : dio =
          dio ??
          Dio(
            BaseOptions(
              baseUrl: apiBaseUrl,
              connectTimeout: const Duration(seconds: 15),
              receiveTimeout: const Duration(seconds: 20),
              sendTimeout: const Duration(seconds: 20),
              headers: const {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
              },
            ),
          ) {
    this.dio.interceptors.add(_SanctumInterceptor(this));
  }

  final Dio dio;
  final TokenStorage tokenStorage;

  void Function()? onUnauthorized;
}

class _SanctumInterceptor extends Interceptor {
  _SanctumInterceptor(this._client);

  final ApiClient _client;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    if (options.extra['publicAuthRequest'] != true) {
      final token = await _client.tokenStorage.readToken();
      if (token != null && token.isNotEmpty) {
        options.headers['Authorization'] = 'Bearer $token';
      }
    }

    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    if (err.response?.statusCode == 401 &&
        err.requestOptions.extra['publicAuthRequest'] != true) {
      await _client.tokenStorage.deleteToken();
      _client.onUnauthorized?.call();
    }

    handler.next(err);
  }
}

String apiErrorMessage(Object error) {
  if (error is DioException) {
    final data = error.response?.data;
    if (data is Map<String, dynamic>) {
      final errors = data['errors'];
      if (errors is Map<String, dynamic>) {
        for (final fieldErrors in errors.values) {
          if (fieldErrors is List && fieldErrors.isNotEmpty) {
            return fieldErrors.first.toString();
          }
        }
      }

      final message = data['message'];
      if (message is String && message.isNotEmpty) {
        return message;
      }
    }

    if (error.response?.statusCode == 401) {
      return 'The email or password is incorrect.';
    }

    if (error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.receiveTimeout ||
        error.type == DioExceptionType.sendTimeout ||
        error.type == DioExceptionType.connectionError) {
      return 'Could not connect. Check your internet connection and try again.';
    }
  }

  if (error is FormatException) {
    return 'The server returned an unexpected response. Please try again later.';
  }

  return 'Something went wrong. Please try again.';
}
