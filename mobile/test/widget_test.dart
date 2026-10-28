import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nubloca/app.dart';
import 'package:nubloca/core/auth/auth_session.dart';
import 'package:nubloca/core/auth/user.dart';
import 'package:nubloca/features/auth/data/auth_repository.dart';
import 'package:nubloca/providers.dart';

class _FakeAuthRepository implements AuthRepository {
  User? user;
  Object? loginError;
  int loginCalls = 0;
  int logoutCalls = 0;

  @override
  Future<AuthSession> login({
    required String email,
    required String password,
  }) async {
    loginCalls++;
    if (loginError != null) {
      throw loginError!;
    }
    final signedInUser = const User(
      id: 1,
      name: 'Test Driver',
      email: 'driver@example.com',
    );
    user = signedInUser;
    return AuthSession(token: 'mock-token', user: signedInUser);
  }

  @override
  Future<AuthSession> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    final registeredUser = User(id: 2, name: name, email: email);
    user = registeredUser;
    return AuthSession(token: 'mock-token', user: registeredUser);
  }

  @override
  Future<User?> restoreSession() async => user;

  @override
  Future<void> logout() async {
    logoutCalls++;
    user = null;
  }
}

void main() {
  testWidgets('login navigates to the authenticated home screen', (
    tester,
  ) async {
    final repository = _FakeAuthRepository();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [authRepositoryProvider.overrideWithValue(repository)],
        child: const NuBlocaApp(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Welcome back'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('emailField')),
      'driver@example.com',
    );
    await tester.enterText(
      find.byKey(const Key('passwordField')),
      'correct-horse-battery',
    );
    await tester.tap(find.byKey(const Key('submitButton')));
    await tester.pumpAndSettle();

    expect(repository.loginCalls, 1);
    expect(find.text('Hello, Test Driver'), findsOneWidget);
  });

  testWidgets('registration validates matching passwords and creates account', (
    tester,
  ) async {
    final repository = _FakeAuthRepository();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [authRepositoryProvider.overrideWithValue(repository)],
        child: const NuBlocaApp(),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('New to NuBloca? Create an account'));
    await tester.pumpAndSettle();

    expect(find.text('Join NuBloca'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('nameField')), 'Taylor Driver');
    await tester.enterText(
      find.byKey(const Key('emailField')),
      'taylor@example.com',
    );
    await tester.enterText(
      find.byKey(const Key('passwordField')),
      'correct-horse-battery',
    );
    await tester.enterText(
      find.byKey(const Key('passwordConfirmationField')),
      'different-password',
    );
    final submitButton = find.byKey(const Key('submitButton'));
    await tester.ensureVisible(submitButton);
    await tester.tap(submitButton);
    await tester.pump();

    expect(find.text('Passwords do not match.'), findsOneWidget);
    expect(find.text('Hello, Taylor Driver'), findsNothing);

    await tester.enterText(
      find.byKey(const Key('passwordConfirmationField')),
      'correct-horse-battery',
    );
    await tester.ensureVisible(submitButton);
    await tester.tap(submitButton);
    await tester.pumpAndSettle();

    expect(find.text('Hello, Taylor Driver'), findsOneWidget);
  });

  testWidgets('login displays a safe error message from the repository', (
    tester,
  ) async {
    final repository = _FakeAuthRepository()
      ..loginError = StateError('The email or password is incorrect.');
    await tester.pumpWidget(
      ProviderScope(
        overrides: [authRepositoryProvider.overrideWithValue(repository)],
        child: const NuBlocaApp(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('emailField')),
      'driver@example.com',
    );
    await tester.enterText(
      find.byKey(const Key('passwordField')),
      'incorrect-password',
    );
    await tester.tap(find.byKey(const Key('submitButton')));
    await tester.pumpAndSettle();

    expect(
      find.text('Something went wrong. Please try again.'),
      findsOneWidget,
    );
  });

  testWidgets('logout returns the user to login', (tester) async {
    final repository = _FakeAuthRepository()
      ..user = const User(
        id: 1,
        name: 'Test Driver',
        email: 'driver@example.com',
      );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [authRepositoryProvider.overrideWithValue(repository)],
        child: const NuBlocaApp(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Hello, Test Driver'), findsOneWidget);
    await tester.tap(find.byKey(const Key('logoutButton')));
    await tester.pumpAndSettle();

    expect(repository.logoutCalls, 1);
    expect(find.text('Welcome back'), findsOneWidget);
  });
}
