import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'core/config/theme_config.dart';
import 'core/constants/app_constants.dart';
import 'core/network/dio_client.dart';
import 'data/services/product_service.dart';
import 'data/services/order_service.dart';
import 'presentation/providers/auth_provider.dart';
import 'presentation/providers/product_provider.dart';
import 'presentation/providers/cart_provider.dart';
import 'presentation/providers/order_provider.dart';
import 'presentation/routes/app_router.dart';

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    // DioClient 초기화
    final dioClient = DioClient();

    return MultiProvider(
      providers: [
        // Auth Provider
        ChangeNotifierProvider(
          create: (_) => AuthProvider(dioClient)..initialize(),
        ),

        // Product Provider
        ChangeNotifierProvider(
          create: (_) => ProductProvider(ProductService(dioClient)),
        ),

        // Cart Provider
        ChangeNotifierProvider(
          create: (_) => CartProvider(dioClient),
        ),

        // Order Provider
        ChangeNotifierProvider(
          create: (_) => OrderProvider(OrderService(dioClient)),
        ),
      ],
      child: Consumer<AuthProvider>(
        builder: (context, authProvider, child) {
          return MaterialApp.router(
            title: AppConstants.appName,
            debugShowCheckedModeBanner: false,
            theme: ThemeConfig.lightTheme,
            routerConfig: AppRouter.router(
              isLoggedIn: authProvider.isLoggedIn,
            ),
          );
        },
      ),
    );
  }
}
