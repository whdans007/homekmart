import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:go_router/go_router.dart';
import '../../../core/config/theme_config.dart';
import '../../providers/cart_provider.dart';
import '../../widgets/loading_overlay.dart';
import '../../widgets/error_widget.dart' as custom_error;
import '../../widgets/empty_state.dart';
import '../../widgets/app_button.dart';
import '../../routes/app_router.dart';

/// 장바구니 화면
class CartScreen extends StatefulWidget {
  const CartScreen({super.key});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  @override
  void initState() {
    super.initState();
    _loadCart();
  }

  Future<void> _loadCart() async {
    final cartProvider = context.read<CartProvider>();
    await cartProvider.loadCart();
  }

  Future<void> _updateQuantity(int cartId, int newQuantity) async {
    final cartProvider = context.read<CartProvider>();
    await cartProvider.updateQuantity(
      cartId: cartId,
      quantity: newQuantity,
    );
  }

  Future<void> _removeItem(int cartId) async {
    final cartProvider = context.read<CartProvider>();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Remove Item'),
        content: const Text('Are you sure you want to remove this item?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Remove'),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      await cartProvider.removeItem(cartId: cartId);
    }
  }

  Future<void> _clearCart() async {
    final cartProvider = context.read<CartProvider>();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Clear Cart'),
        content: const Text('Are you sure you want to clear all items?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Clear'),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      await cartProvider.clearCart();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Shopping Cart'),
        actions: [
          Consumer<CartProvider>(
            builder: (context, cartProvider, child) {
              if (cartProvider.items.isEmpty) return const SizedBox.shrink();
              return IconButton(
                icon: const Icon(Icons.delete_sweep),
                onPressed: _clearCart,
                tooltip: 'Clear cart',
              );
            },
          ),
        ],
      ),
      body: Consumer<CartProvider>(
        builder: (context, cartProvider, child) {
          if (cartProvider.isLoading) {
            return const LoadingIndicator(message: 'Loading cart...');
          }

          if (cartProvider.error != null) {
            return custom_error.AppErrorWidget(
              message: cartProvider.error!,
              onRetry: _loadCart,
            );
          }

          if (cartProvider.items.isEmpty) {
            return EmptyState(
              icon: Icons.shopping_cart_outlined,
              title: 'Your Cart is Empty',
              message: 'Add some products to get started',
              actionLabel: 'Start Shopping',
              onAction: () => context.pop(),
            );
          }

          return Column(
            children: [
              // 재고 부족 경고
              if (cartProvider.hasOutOfStockItems)
                Container(
                  color: Colors.orange.shade100,
                  padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                  child: Row(
                    children: [
                      Icon(
                        Icons.warning,
                        color: Colors.orange.shade900,
                      ),
                      const SizedBox(width: ThemeConfig.spacingSm),
                      Expanded(
                        child: Text(
                          'Some items are out of stock or have insufficient quantity',
                          style: TextStyle(
                            color: Colors.orange.shade900,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),

              // 장바구니 아이템 목록
              Expanded(
                child: RefreshIndicator(
                  onRefresh: _loadCart,
                  child: ListView.separated(
                    padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                    itemCount: cartProvider.items.length,
                    separatorBuilder: (context, index) =>
                        const SizedBox(height: ThemeConfig.spacingMd),
                    itemBuilder: (context, index) {
                      final item = cartProvider.items[index];
                      final canOrder = item.canOrder;

                      return Card(
                        child: Padding(
                          padding: const EdgeInsets.all(ThemeConfig.spacingSm),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              // 상품 이미지
                              ClipRRect(
                                borderRadius: BorderRadius.circular(
                                  ThemeConfig.radiusSm,
                                ),
                                child: item.imageUrl != null
                                    ? CachedNetworkImage(
                                        imageUrl: item.imageUrl!,
                                        width: 80,
                                        height: 80,
                                        fit: BoxFit.cover,
                                      )
                                    : Container(
                                        width: 80,
                                        height: 80,
                                        color: Colors.grey.shade200,
                                        child: const Icon(Icons.shopping_bag),
                                      ),
                              ),
                              const SizedBox(width: ThemeConfig.spacingSm),

                              // 상품 정보
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      item.productName,
                                      style: Theme.of(context)
                                          .textTheme
                                          .bodyLarge
                                          ?.copyWith(
                                            fontWeight: FontWeight.w600,
                                          ),
                                      maxLines: 2,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                    const SizedBox(height: ThemeConfig.spacingXs),
                                    Text(
                                      item.formattedPrice,
                                      style: Theme.of(context)
                                          .textTheme
                                          .titleMedium
                                          ?.copyWith(
                                            color: ThemeConfig.primaryColor,
                                            fontWeight: FontWeight.bold,
                                          ),
                                    ),
                                    const SizedBox(height: ThemeConfig.spacingXs),

                                    // 재고 상태
                                    if (!canOrder)
                                      Text(
                                        'Out of stock',
                                        style: Theme.of(context)
                                            .textTheme
                                            .bodySmall
                                            ?.copyWith(
                                              color: ThemeConfig.errorColor,
                                              fontWeight: FontWeight.w600,
                                            ),
                                      ),

                                    const SizedBox(height: ThemeConfig.spacingSm),

                                    // 수량 조절
                                    Row(
                                      children: [
                                        IconButton(
                                          onPressed: () {
                                            if (item.quantity > 1) {
                                              _updateQuantity(
                                                item.cartId,
                                                item.quantity - 1,
                                              );
                                            }
                                          },
                                          icon: const Icon(
                                            Icons.remove_circle_outline,
                                          ),
                                          iconSize: 24,
                                        ),
                                        Container(
                                          width: 40,
                                          alignment: Alignment.center,
                                          child: Text(
                                            item.quantity.toString(),
                                            style: Theme.of(context)
                                                .textTheme
                                                .titleMedium
                                                ?.copyWith(
                                                  fontWeight: FontWeight.bold,
                                                ),
                                          ),
                                        ),
                                        IconButton(
                                          onPressed: () {
                                            if (item.quantity <
                                                item.stockQuantity) {
                                              _updateQuantity(
                                                item.cartId,
                                                item.quantity + 1,
                                              );
                                            }
                                          },
                                          icon: const Icon(
                                            Icons.add_circle_outline,
                                          ),
                                          iconSize: 24,
                                        ),
                                        const Spacer(),
                                        IconButton(
                                          onPressed: () =>
                                              _removeItem(item.cartId),
                                          icon: const Icon(Icons.delete_outline),
                                          color: ThemeConfig.errorColor,
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ),

              // 주문 요약 및 체크아웃 버튼
              Container(
                padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                decoration: BoxDecoration(
                  color: Colors.white,
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withOpacity(0.05),
                      blurRadius: 10,
                      offset: const Offset(0, -5),
                    ),
                  ],
                ),
                child: SafeArea(
                  child: Column(
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            'Subtotal',
                            style: Theme.of(context).textTheme.bodyLarge,
                          ),
                          Text(
                            cartProvider.summary.formattedSubtotal,
                            style:
                                Theme.of(context).textTheme.bodyLarge?.copyWith(
                                      fontWeight: FontWeight.w600,
                                    ),
                          ),
                        ],
                      ),
                      const SizedBox(height: ThemeConfig.spacingSm),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            'Total',
                            style:
                                Theme.of(context).textTheme.titleLarge?.copyWith(
                                      fontWeight: FontWeight.bold,
                                    ),
                          ),
                          Text(
                            cartProvider.summary.formattedTotal,
                            style:
                                Theme.of(context).textTheme.titleLarge?.copyWith(
                                      color: ThemeConfig.primaryColor,
                                      fontWeight: FontWeight.bold,
                                    ),
                          ),
                        ],
                      ),
                      const SizedBox(height: ThemeConfig.spacingMd),
                      AppButton(
                        text: 'Proceed to Checkout',
                        onPressed: cartProvider.hasOutOfStockItems
                            ? null
                            : () => context.push(AppRouter.checkout),
                        width: double.infinity,
                      ),
                    ],
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
