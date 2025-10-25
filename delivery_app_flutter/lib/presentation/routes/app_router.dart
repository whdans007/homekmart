import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../screens/auth/splash_screen.dart';
import '../screens/auth/login_screen.dart';
import '../screens/auth/register_screen.dart';
import '../screens/home/home_screen.dart';
import '../screens/product/product_detail_screen.dart';
import '../screens/cart/cart_screen.dart';
import '../screens/checkout/checkout_screen.dart';
import '../screens/order/order_list_screen.dart';
import '../screens/order/order_detail_screen.dart';
import '../screens/profile/profile_screen.dart';

/// 앱 라우터 설정
class AppRouter {
  // 라우트 이름 상수
  static const String splash = '/';
  static const String login = '/login';
  static const String register = '/register';
  static const String home = '/home';
  static const String productDetail = '/product/:id';
  static const String cart = '/cart';
  static const String checkout = '/checkout';
  static const String orders = '/orders';
  static const String orderDetail = '/orders/:id';
  static const String profile = '/profile';

  /// GoRouter 인스턴스
  static GoRouter router() {
    return GoRouter(
      initialLocation: splash,
      redirect: (context, state) {
        // Provider에서 직접 로그인 상태 읽기 (listen: false로 무한 루프 방지)
        final authProvider = context.read<AuthProvider>();
        final isLoggedIn = authProvider.isLoggedIn;

        final isOnSplash = state.matchedLocation == splash;
        final isOnAuth = state.matchedLocation == login ||
                        state.matchedLocation == register;
        final isOnCheckout = state.matchedLocation == checkout;
        final isOnProfile = state.matchedLocation == profile;
        final isOnOrders = state.matchedLocation.startsWith('/orders');

        // 스플래시 화면은 항상 접근 가능
        if (isOnSplash) {
          return null;
        }

        // 배달앱: 상품 보기, 장바구니는 로그인 불필요
        // 주문/결제/프로필만 로그인 필요
        if (!isLoggedIn && (isOnCheckout || isOnProfile || isOnOrders)) {
          return login;
        }

        // 이미 로그인된 상태에서 인증 화면 접근 시 홈으로
        if (isLoggedIn && isOnAuth) {
          return home;
        }

        return null;
      },
      routes: [
        // 스플래시
        GoRoute(
          path: splash,
          builder: (context, state) => const SplashScreen(),
        ),

        // 로그인
        GoRoute(
          path: login,
          builder: (context, state) => const LoginScreen(),
        ),

        // 회원가입
        GoRoute(
          path: register,
          builder: (context, state) => const RegisterScreen(),
        ),

        // 홈
        GoRoute(
          path: home,
          builder: (context, state) => const HomeScreen(),
        ),

        // 상품 상세
        GoRoute(
          path: productDetail,
          builder: (context, state) {
            final id = state.pathParameters['id']!;
            return ProductDetailScreen(productId: id);
          },
        ),

        // 장바구니
        GoRoute(
          path: cart,
          builder: (context, state) => const CartScreen(),
        ),

        // 주문 확인
        GoRoute(
          path: checkout,
          builder: (context, state) => const CheckoutScreen(),
        ),

        // 주문 내역
        GoRoute(
          path: orders,
          builder: (context, state) => const OrderListScreen(),
        ),

        // 주문 상세
        GoRoute(
          path: orderDetail,
          builder: (context, state) {
            final id = state.pathParameters['id']!;
            return OrderDetailScreen(orderId: id);
          },
        ),

        // 프로필
        GoRoute(
          path: profile,
          builder: (context, state) => const ProfileScreen(),
        ),
      ],
      errorBuilder: (context, state) => Scaffold(
        body: Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.error_outline, size: 64, color: Colors.red),
              const SizedBox(height: 16),
              Text(
                '페이지를 찾을 수 없습니다',
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 8),
              Text(
                state.error?.toString() ?? '',
                style: Theme.of(context).textTheme.bodyMedium,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: () => context.go(home),
                child: const Text('홈으로 돌아가기'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
