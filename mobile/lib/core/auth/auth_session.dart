import 'package:nubloca/core/auth/user.dart';

class AuthSession {
  const AuthSession({required this.token, required this.user});

  final String token;
  final User user;

  factory AuthSession.fromJson(Map<String, dynamic> json) {
    final token = json['token'];
    final user = json['user'];

    if (token is! String || token.isEmpty || user is! Map<String, dynamic>) {
      throw const FormatException(
        'The API returned an invalid sign-in response.',
      );
    }

    return AuthSession(token: token, user: User.fromJson(user));
  }
}
