import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:nubloca/core/network/api_client.dart';
import 'package:nubloca/core/storage/token_storage.dart';

final tokenStorageProvider = Provider<TokenStorage>(
  (ref) => SecureTokenStorage(const FlutterSecureStorage()),
);

final apiClientProvider = Provider<ApiClient>((ref) {
  final client = ApiClient(tokenStorage: ref.watch(tokenStorageProvider));
  ref.onDispose(() => client.dio.close(force: true));
  return client;
});
