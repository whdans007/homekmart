import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:go_router/go_router.dart';
import '../../../core/config/theme_config.dart';
import '../../../core/constants/app_constants.dart';
import '../../providers/auth_provider.dart';
import '../../routes/app_router.dart';

/// 스플래시 화면
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _initialize();
  }

  Future<void> _initialize() async {
    try {
      // 최소 1초 대기 (로고 표시)
      await Future.delayed(const Duration(seconds: 1));

      if (!mounted) return;

      // 배달앱은 로그인 없이 바로 메인 화면으로 이동
      // 백그라운드에서 인증 상태만 확인
      final authProvider = context.read<AuthProvider>();
      authProvider.initialize(); // await 없이 비동기로 실행

      // 바로 홈 화면으로 이동
      context.go(AppRouter.home);
    } catch (e) {
      // 에러 발생 시에도 홈 화면으로 이동
      print('Splash initialization error: $e');
      if (mounted) {
        context.go(AppRouter.home);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ThemeConfig.primaryColor,
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // 로고 아이콘
            Container(
              width: 120,
              height: 120,
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(ThemeConfig.radiusXl),
              ),
              child: const Icon(
                Icons.shopping_bag,
                size: 64,
                color: ThemeConfig.primaryColor,
              ),
            ),
            const SizedBox(height: ThemeConfig.spacingXl),

            // 앱 이름
            Text(
              AppConstants.appName,
              style: Theme.of(context).textTheme.displaySmall?.copyWith(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                  ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: ThemeConfig.spacingSm),

            // 버전
            Text(
              'Version ${AppConstants.appVersion}',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: Colors.white.withOpacity(0.8),
                  ),
            ),
            const SizedBox(height: ThemeConfig.spacingXl),

            // 로딩 인디케이터
            const CircularProgressIndicator(
              valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
            ),
          ],
        ),
      ),
    );
  }
}
