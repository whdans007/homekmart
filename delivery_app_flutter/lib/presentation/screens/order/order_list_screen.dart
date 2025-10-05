import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:go_router/go_router.dart';
import '../../../core/config/theme_config.dart';
import '../../providers/order_provider.dart';
import '../../widgets/loading_overlay.dart';
import '../../widgets/error_widget.dart';
import '../../widgets/empty_state.dart';
import '../../routes/app_router.dart';

/// 주문 목록 화면
class OrderListScreen extends StatefulWidget {
  const OrderListScreen({super.key});

  @override
  State<OrderListScreen> createState() => _OrderListScreenState();
}

class _OrderListScreenState extends State<OrderListScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  final List<String> _tabs = [
    'All',
    'Pending',
    'Confirmed',
    'Preparing',
    'Out for Delivery',
    'Delivered',
    'Cancelled',
  ];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: _tabs.length, vsync: this);
    _loadOrders();

    _tabController.addListener(() {
      if (_tabController.indexIsChanging) {
        _loadOrders();
      }
    });
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadOrders() async {
    final orderProvider = context.read<OrderProvider>();
    final status = _tabController.index == 0
        ? null
        : _tabs[_tabController.index].toLowerCase().replaceAll(' ', '_');
    await orderProvider.loadOrders(status: status);
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

  String _getStatusLabel(String status) {
    switch (status) {
      case 'pending':
        return 'Pending';
      case 'confirmed':
        return 'Confirmed';
      case 'preparing':
        return 'Preparing';
      case 'out_for_delivery':
        return 'Out for Delivery';
      case 'delivered':
        return 'Delivered';
      case 'cancelled':
        return 'Cancelled';
      default:
        return status;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('My Orders'),
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          tabs: _tabs.map((tab) => Tab(text: tab)).toList(),
        ),
      ),
      body: Consumer<OrderProvider>(
        builder: (context, orderProvider, child) {
          if (orderProvider.isLoading) {
            return const LoadingIndicator(message: 'Loading orders...');
          }

          if (orderProvider.error != null) {
            return AppErrorWidget(
              message: orderProvider.error!,
              onRetry: _loadOrders,
            );
          }

          if (orderProvider.orders.isEmpty) {
            return EmptyState(
              icon: Icons.receipt_long_outlined,
              title: 'No Orders Yet',
              message: _tabController.index == 0
                  ? 'Start shopping to see your orders here'
                  : 'No orders with this status',
            );
          }

          return RefreshIndicator(
            onRefresh: _loadOrders,
            child: ListView.separated(
              padding: const EdgeInsets.all(ThemeConfig.spacingMd),
              itemCount: orderProvider.orders.length,
              separatorBuilder: (context, index) =>
                  const SizedBox(height: ThemeConfig.spacingMd),
              itemBuilder: (context, index) {
                final order = orderProvider.orders[index];
                return Card(
                  clipBehavior: Clip.antiAlias,
                  child: InkWell(
                    onTap: () => context.push(
                      AppRouter.orderDetail.replaceAll(':id', '${order.orderId}'),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // 주문 번호 및 상태
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                order.orderNumber,
                                style: Theme.of(context)
                                    .textTheme
                                    .titleMedium
                                    ?.copyWith(
                                      fontWeight: FontWeight.bold,
                                    ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: ThemeConfig.spacingSm,
                                  vertical: ThemeConfig.spacingXs,
                                ),
                                decoration: BoxDecoration(
                                  color: _getStatusColor(order.orderStatus)
                                      .withOpacity(0.1),
                                  borderRadius: BorderRadius.circular(
                                    ThemeConfig.radiusSm,
                                  ),
                                  border: Border.all(
                                    color: _getStatusColor(order.orderStatus),
                                  ),
                                ),
                                child: Text(
                                  _getStatusLabel(order.orderStatus),
                                  style: TextStyle(
                                    color: _getStatusColor(order.orderStatus),
                                    fontWeight: FontWeight.w600,
                                    fontSize: 12,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: ThemeConfig.spacingSm),

                          // 주문 날짜
                          Row(
                            children: [
                              const Icon(
                                Icons.calendar_today,
                                size: 16,
                                color: ThemeConfig.textSecondary,
                              ),
                              const SizedBox(width: ThemeConfig.spacingXs),
                              Text(
                                order.formattedOrderDate,
                                style: Theme.of(context)
                                    .textTheme
                                    .bodySmall
                                    ?.copyWith(
                                      color: ThemeConfig.textSecondary,
                                    ),
                              ),
                            ],
                          ),
                          const SizedBox(height: ThemeConfig.spacingXs),

                          // 결제 방법
                          Row(
                            children: [
                              Icon(
                                order.paymentMethod == 'cod'
                                    ? Icons.money
                                    : Icons.payment,
                                size: 16,
                                color: ThemeConfig.textSecondary,
                              ),
                              const SizedBox(width: ThemeConfig.spacingXs),
                              Text(
                                order.paymentMethodLabel,
                                style: Theme.of(context)
                                    .textTheme
                                    .bodySmall
                                    ?.copyWith(
                                      color: ThemeConfig.textSecondary,
                                    ),
                              ),
                            ],
                          ),
                          const Divider(height: ThemeConfig.spacingMd),

                          // 합계 금액
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                'Total Amount',
                                style: Theme.of(context).textTheme.bodyMedium,
                              ),
                              Text(
                                order.formattedTotalAmount,
                                style: Theme.of(context)
                                    .textTheme
                                    .titleMedium
                                    ?.copyWith(
                                      color: ThemeConfig.primaryColor,
                                      fontWeight: FontWeight.bold,
                                    ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}
