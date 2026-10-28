import 'dart:convert';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:nubloca/core/network/api_client.dart';

/// Handles Firebase Cloud Messaging for the app: permission, device-token
/// registration with the backend, token refresh, and incoming messages.
///
/// The device token is sent only to the NuBloca API and is never logged.
class PushNotificationService {
  PushNotificationService({
    required ApiClient apiClient,
    FirebaseMessaging? messaging,
  }) : _apiClient = apiClient,
       _messaging = messaging ?? FirebaseMessaging.instance;

  final ApiClient _apiClient;
  final FirebaseMessaging _messaging;

  /// Called when a notification is tapped and the app should navigate.
  /// Receives the decoded `navigation_data` payload (e.g. route, ids).
  void Function(Map<String, String> navigationData)? onNotificationTap;

  /// Emits foreground messages so the UI can show an in-app banner.
  final Stream<RemoteMessage> foregroundMessages = FirebaseMessaging.onMessage;

  bool _initialized = false;

  /// Requests permission and wires up token + message listeners. Safe to call
  /// once after Firebase is initialized.
  Future<void> initialize() async {
    if (_initialized) {
      return;
    }
    _initialized = true;

    await _messaging.requestPermission();

    // Re-upload whenever FCM rotates the token. The backend sends via classic
    // FCM registration tokens (Messaging::withToken), so we use the token API
    // rather than the v17 FID direct-send registration.
    // ignore: deprecated_member_use
    _messaging.onTokenRefresh.listen(_uploadToken);

    // Notification taps (app in background/terminated).
    FirebaseMessaging.onMessageOpenedApp.listen(_handleOpenedMessage);
  }

  /// Gets the current FCM token and registers it with the backend for the
  /// authenticated user. No-op if there is no token or the upload fails (the
  /// user can still use the app; registration is retried on next launch).
  Future<void> registerCurrentDevice() async {
    // ignore: deprecated_member_use
    final token = await _messaging.getToken();
    if (token != null && token.isNotEmpty) {
      await _uploadToken(token);
    }
  }

  /// Removes this device's token from the backend (e.g. on logout).
  Future<void> unregisterCurrentDevice() async {
    // ignore: deprecated_member_use
    final token = await _messaging.getToken();
    if (token == null || token.isEmpty) {
      return;
    }
    try {
      await _apiClient.dio.delete<Object?>(
        'device-tokens',
        data: {'token': token},
      );
    } on Exception {
      // Best-effort: a stale token is cleaned up server-side on send failure.
    }
  }

  Future<void> _uploadToken(String token) async {
    try {
      await _apiClient.dio.post<Object?>(
        'device-tokens',
        data: {'token': token},
      );
    } on Exception {
      // Never crash the app on registration failure; retried on next launch.
    }
  }

  void _handleOpenedMessage(RemoteMessage message) {
    final navigationData = extractNavigationData(message.data);
    if (navigationData != null) {
      onNotificationTap?.call(navigationData);
    }
  }

  /// Decodes the backend's `navigation_data` JSON string into a string map.
  static Map<String, String>? extractNavigationData(Map<String, dynamic> data) {
    final raw = data['navigation_data'];
    if (raw is! String || raw.isEmpty) {
      return null;
    }
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) {
        return null;
      }
      return decoded.map((key, value) => MapEntry(key.toString(), value.toString()));
    } on FormatException {
      return null;
    }
  }
}
