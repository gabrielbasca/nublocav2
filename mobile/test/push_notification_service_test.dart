import 'package:flutter_test/flutter_test.dart';
import 'package:nubloca/core/notifications/push_notification_service.dart';

void main() {
  group('PushNotificationService.extractNavigationData', () {
    test('decodes the backend navigation_data JSON into a string map', () {
      final data = <String, dynamic>{
        'type': 'account_event',
        'route': 'vehicle_alerts',
        'navigation_data':
            '{"route":"vehicle_alerts","vehicle_id":"3","alert_id":"7"}',
      };

      final result = PushNotificationService.extractNavigationData(data);

      expect(result, isNotNull);
      expect(result!['route'], 'vehicle_alerts');
      expect(result['vehicle_id'], '3');
      expect(result['alert_id'], '7');
    });

    test('decodes conversation navigation data', () {
      final data = <String, dynamic>{
        'type': 'contact_request',
        'conversation_id': '12',
        'navigation_data': '{"conversation_id":"12"}',
      };

      final result = PushNotificationService.extractNavigationData(data);

      expect(result, isNotNull);
      expect(result!['conversation_id'], '12');
    });

    test('returns null when navigation_data is missing', () {
      expect(PushNotificationService.extractNavigationData(const {}), isNull);
    });

    test('returns null when navigation_data is not a string', () {
      expect(
        PushNotificationService.extractNavigationData(
          const {'navigation_data': 123},
        ),
        isNull,
      );
    });

    test('returns null for malformed JSON', () {
      expect(
        PushNotificationService.extractNavigationData(
          const {'navigation_data': '{not valid json'},
        ),
        isNull,
      );
    });

    test('returns null for a non-object JSON payload', () {
      expect(
        PushNotificationService.extractNavigationData(
          const {'navigation_data': '[1,2,3]'},
        ),
        isNull,
      );
    });
  });
}
