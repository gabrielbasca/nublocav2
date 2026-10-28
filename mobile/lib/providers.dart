import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:nubloca/app.dart';
import 'package:nubloca/core/auth/user.dart';
import 'package:nubloca/core/providers.dart';
import 'package:nubloca/features/auth/data/auth_repository.dart';
import 'package:nubloca/features/auth/presentation/auth_screen.dart';
import 'package:nubloca/features/auth/presentation/home_screen.dart';
import 'package:nubloca/features/vehicles/presentation/vehicle_screens.dart';

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => ApiAuthRepository(ref.watch(apiClientProvider)),
);

final authProvider = AsyncNotifierProvider<AuthController, User?>(
  AuthController.new,
);

class AuthController extends AsyncNotifier<User?> {
  @override
  Future<User?> build() async {
    ref.read(apiClientProvider).onUnauthorized = _handleUnauthorized;
    final user = await ref.read(authRepositoryProvider).restoreSession();
    if (user != null) {
      await _registerDeviceForPush();
    }
    return user;
  }

  Future<void> login({required String email, required String password}) async {
    final session = await ref
        .read(authRepositoryProvider)
        .login(email: email, password: password);
    state = AsyncData(session.user);
    await _registerDeviceForPush();
  }

  Future<void> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    final session = await ref
        .read(authRepositoryProvider)
        .register(
          name: name,
          email: email,
          password: password,
          passwordConfirmation: passwordConfirmation,
        );
    state = AsyncData(session.user);
    await _registerDeviceForPush();
  }

  Future<void> logout() async {
    try {
      await ref.read(pushNotificationServiceProvider).unregisterCurrentDevice();
      await ref.read(authRepositoryProvider).logout();
    } finally {
      state = const AsyncData(null);
    }
  }

  /// Registers this device's FCM token with the backend so the user can
  /// receive push notifications. Best-effort: failures never block auth.
  Future<void> _registerDeviceForPush() async {
    try {
      final service = ref.read(pushNotificationServiceProvider);
      await service.initialize();
      await service.registerCurrentDevice();
    } on Exception {
      // Push registration must never break sign-in.
    }
  }

  Future<void> retryRestore() async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(
      () => ref.read(authRepositoryProvider).restoreSession(),
    );
  }

  void _handleUnauthorized() {
    state = const AsyncData(null);
  }
}

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = _AuthRouterRefresh();
  ref.listen(authProvider, (_, _) => refresh.refresh());
  ref.onDispose(refresh.dispose);

  final router = GoRouter(
    initialLocation: '/',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authProvider);
      final location = state.matchedLocation;

      if (auth.isLoading) {
        return location == '/' ? null : '/';
      }

      if (auth.hasError) {
        return location == '/session-error' ? null : '/session-error';
      }

      final isAuthenticated = auth.asData?.value != null;
      final isAuthRoute = location == '/login' || location == '/register';

      if (isAuthenticated && (location == '/' || isAuthRoute)) {
        return '/home';
      }

      if (!isAuthenticated && !isAuthRoute) {
        return '/login';
      }

      return null;
    },
    routes: [
      GoRoute(
        path: '/',
        builder: (context, state) => const SessionLoadingScreen(),
      ),
      GoRoute(
        path: '/session-error',
        builder: (context, state) => const SessionErrorScreen(),
      ),
      GoRoute(
        path: '/login',
        builder: (context, state) => const AuthScreen(mode: AuthMode.login),
      ),
      GoRoute(
        path: '/register',
        builder: (context, state) => const AuthScreen(mode: AuthMode.register),
      ),
      GoRoute(path: '/home', builder: (context, state) => const HomeScreen()),
      GoRoute(
        path: '/vehicles',
        builder: (context, state) => const VehicleListScreen(),
      ),
      GoRoute(
        path: '/vehicles/new',
        builder: (context, state) => const VehicleFormScreen(),
      ),
      GoRoute(
        path: '/vehicles/:vehicleId',
        builder: (context, state) => VehicleDetailsScreen(
          vehicleId: int.parse(state.pathParameters['vehicleId']!),
        ),
      ),
      GoRoute(
        path: '/vehicles/:vehicleId/edit',
        builder: (context, state) => VehicleFormScreen(
          vehicleId: int.parse(state.pathParameters['vehicleId']!),
        ),
      ),
    ],
  );

  ref.onDispose(router.dispose);
  return router;
});

class _AuthRouterRefresh extends ChangeNotifier {
  void refresh() => notifyListeners();
}
