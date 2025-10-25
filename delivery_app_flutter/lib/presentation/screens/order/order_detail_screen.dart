import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../../../core/config/theme_config.dart';
import '../../providers/order_provider.dart';
import '../../widgets/loading_overlay.dart';
import '../../widgets/error_widget.dart' as custom_error;
import '../../widgets/app_button.dart';

/// 주문 상세 화면
class OrderDetailScreen extends StatefulWidget {
  final String orderId;

  const OrderDetailScreen({
    super.key,
    required this.orderId,
  });

  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  @override
  void initState() {
    super.initState();
    _loadOrderDetail();
  }

  Future<void> _loadOrderDetail() async {
    final orderProvider = context.read<OrderProvider>();
    await orderProvider.loadOrderDetail(int.parse(widget.orderId));
    await orderProvider.loadOrderTracking(int.parse(widget.orderId));
  }

  Future<void> _handleCancelOrder() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Cancel Order'),
        content: const Text('Are you sure you want to cancel this order?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('No'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Yes, Cancel'),
          ),
        ],
      ),
    );

    if (confirmed == true && mounted) {
      final orderProvider = context.read<OrderProvider>();
      final success = await orderProvider.cancelOrder(int.parse(widget.orderId));

      if (!mounted) return;

      if (success) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Order cancelled successfully'),
            backgroundColor: Colors.green,
          ),
        );
        _loadOrderDetail();
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(orderProvider.error ?? 'Failed to cancel order'),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'pending':
        return Colors.orange;
      case 'confirmed':
        return Colors.blue;
      case 'preparing':
        return Colors.purple;
      case 'out_for_delivery':
        return Colors.teal;
      case 'delivered':
        return Colors.green;
      case 'cancelled':
        return ThemeConfig.errorColor;
      default:
        return Colors.grey;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Order Details'),
      ),
      body: Consumer<OrderProvider>(
        builder: (context, orderProvider, child) {
          if (orderProvider.isLoading) {
            return const LoadingIndicator(message: 'Loading order details...');
          }

          if (orderProvider.error != null) {
            return custom_error.AppErrorWidget(
              message: orderProvider.error!,
              onRetry: _loadOrderDetail,
            );
          }

          final order = orderProvider.currentOrderDetail;
          if (order == null) {
            return const custom_error.AppErrorWidget(message: 'Order not found');
          }

          return SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // 주문 정보
                Container(
                  color: ThemeConfig.surfaceColor,
                  padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Order ${order.orderNumber}',
                        style:
                            Theme.of(context).textTheme.titleLarge?.copyWith(
                                  fontWeight: FontWeight.bold,
                                ),
                      ),
                      const SizedBox(height: ThemeConfig.spacingSm),
                      Text(
                        order.formattedOrderDate,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: ThemeConfig.textSecondary,
                            ),
                      ),
                    ],
                  ),
                ),

                // 주문 추적
                if (orderProvider.tracking.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Order Tracking',
                          style:
                              Theme.of(context).textTheme.titleMedium?.copyWith(
                                    fontWeight: FontWeight.bold,
                                  ),
                        ),
                        const SizedBox(height: ThemeConfig.spacingMd),
                        ...orderProvider.tracking.map((track) {
                          return Padding(
                            padding: const EdgeInsets.only(
                              bottom: ThemeConfig.spacingMd,
                            ),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Container(
                                  width: 12,
                                  height: 12,
                                  margin: const EdgeInsets.only(top: 4),
                                  decoration: BoxDecoration(
                                    color: _getStatusColor(track.status),
                                    shape: BoxShape.circle,
                                  ),
                                ),
                                const SizedBox(width: ThemeConfig.spacingSm),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        track.statusLabel,
                                        style: Theme.of(context)
                                            .textTheme
                                            .bodyMedium
                                            ?.copyWith(
                                              fontWeight: FontWeight.w600,
                                            ),
                                      ),
                                      Text(
                                        track.formattedTimestamp,
                                        style: Theme.of(context)
                                            .textTheme
                                            .bodySmall
                                            ?.copyWith(
                                              color: ThemeConfig.textSecondary,
                                            ),
                                      ),
                                      if (track.notes != null)
                                        Text(
                                          track.notes!,
                                          style: Theme.of(context)
                                              .textTheme
                                              .bodySmall,
                                        ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                          );
                        }).toList(),
                      ],
                    ),
                  ),

                const Divider(),

                // 배송 주소
                Padding(
                  padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Delivery Address',
                        style:
                            Theme.of(context).textTheme.titleMedium?.copyWith(
                                  fontWeight: FontWeight.bold,
                                ),
                      ),
                      const SizedBox(height: ThemeConfig.spacingSm),
                      Text(
                        order.deliveryAddress.fullAddress,
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                    ],
                  ),
                ),

                const Divider(),

                // 결제 정보
                Padding(
                  padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Payment Method',
                        style:
                            Theme.of(context).textTheme.titleMedium?.copyWith(
                                  fontWeight: FontWeight.bold,
                                ),
                      ),
                      const SizedBox(height: ThemeConfig.spacingSm),
                      Text(
                        order.paymentMethodLabel,
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                    ],
                  ),
                ),

                const Divider(),

                // 주문 아이템
                Padding(
                  padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Order Items',
                        style:
                            Theme.of(context).textTheme.titleMedium?.copyWith(
                                  fontWeight: FontWeight.bold,
                                ),
                      ),
                      const SizedBox(height: ThemeConfig.spacingMd),
                      ...order.items.map((item) {
                        return Padding(
                          padding: const EdgeInsets.only(
                            bottom: ThemeConfig.spacingMd,
                          ),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              ClipRRect(
                                borderRadius: BorderRadius.circular(
                                  ThemeConfig.radiusSm,
                                ),
                                child: item.imageUrl != null
                                    ? CachedNetworkImage(
                                        imageUrl: item.imageUrl!,
                                        width: 60,
                                        height: 60,
                                        fit: BoxFit.cover,
                                      )
                                    : Container(
                                        width: 60,
                                        height: 60,
                                        color: Colors.grey.shade200,
                                        child: const Icon(Icons.shopping_bag),
                                      ),
                              ),
                              const SizedBox(width: ThemeConfig.spacingSm),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      item.productName,
                                      style: Theme.of(context)
                                          .textTheme
                                          .bodyMedium
                                          ?.copyWith(
                                            fontWeight: FontWeight.w600,
                                          ),
                                    ),
                                    Text(
                                      '${item.formattedPrice} x ${item.quantity}',
                                      style:
                                          Theme.of(context).textTheme.bodySmall,
                                    ),
                                  ],
                                ),
                              ),
                              Text(
                                item.formattedSubtotal,
                                style: Theme.of(context)
                                    .textTheme
                                    .bodyMedium
                                    ?.copyWith(
                                      fontWeight: FontWeight.bold,
                                    ),
                              ),
                            ],
                          ),
                        );
                      }).toList(),
                    ],
                  ),
                ),

                const Divider(),

                // 주문 요약
                Padding(
                  padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                  child: Column(
                    children: [
                      _buildSummaryRow('Subtotal', order.formattedSubtotal),
                      const SizedBox(height: ThemeConfig.spacingSm),
                      _buildSummaryRow(
                        'Delivery Fee',
                        order.formattedDeliveryFee,
                      ),
                      const Divider(height: ThemeConfig.spacingMd),
                      _buildSummaryRow(
                        'Total',
                        order.formattedTotalAmount,
                        isTotal: true,
                      ),
                    ],
                  ),
                ),

                // 취소 버튼
                if (order.canCancel)
                  Padding(
                    padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                    child: AppButton(
                      text: 'Cancel Order',
                      type: AppButtonType.outlined,
                      onPressed: _handleCancelOrder,
                      width: double.infinity,
                    ),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _buildSummaryRow(String label, String value, {bool isTotal = false}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                fontWeight: isTotal ? FontWeight.bold : FontWeight.normal,
              ),
        ),
        Text(
          value,
          style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                fontWeight: isTotal ? FontWeight.bold : FontWeight.w600,
                color: isTotal ? ThemeConfig.primaryColor : null,
              ),
        ),
      ],
    );
  }
}
