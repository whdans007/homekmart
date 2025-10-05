import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:go_router/go_router.dart';
import '../../../core/config/theme_config.dart';
import '../../providers/cart_provider.dart';
import '../../providers/order_provider.dart';
import '../../widgets/loading_overlay.dart';
import '../../widgets/app_button.dart';
import '../../routes/app_router.dart';

/// 체크아웃 화면
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  final _formKey = GlobalKey<FormState>();
  final _notesController = TextEditingController();

  String _selectedPaymentMethod = 'cod';
  bool _isProcessing = false;

  // TODO: 주소 선택 기능 추가 필요
  final int _selectedAddressId = 1;

  @override
  void dispose() {
    _notesController.dispose();
    super.dispose();
  }

  Future<void> _handleCheckout() async {
    if (!_formKey.currentState!.validate()) {
      return;
    }

    setState(() => _isProcessing = true);

    final orderProvider = context.read<OrderProvider>();
    final success = await orderProvider.createOrder(
      addressId: _selectedAddressId,
      paymentMethod: _selectedPaymentMethod,
      notes: _notesController.text.trim().isNotEmpty
          ? _notesController.text.trim()
          : null,
    );

    if (!mounted) return;

    setState(() => _isProcessing = false);

    if (success) {
      // 장바구니 비우기
      final cartProvider = context.read<CartProvider>();
      await cartProvider.clearCart();

      if (!mounted) return;

      // 주문 완료 페이지로 이동
      context.go(AppRouter.orders);

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Order placed successfully!'),
          backgroundColor: Colors.green,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(orderProvider.error ?? 'Failed to place order'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Checkout'),
      ),
      body: LoadingOverlay(
        isLoading: _isProcessing,
        message: 'Processing order...',
        child: Consumer<CartProvider>(
          builder: (context, cartProvider, child) {
            return Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                children: [
                  // 배송 주소 섹션
                  _buildSection(
                    title: 'Delivery Address',
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              mainAxisAlignment: MainAxisAlignment.spaceBetween,
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        'Default Address',
                                        style: Theme.of(context)
                                            .textTheme
                                            .titleMedium
                                            ?.copyWith(
                                              fontWeight: FontWeight.bold,
                                            ),
                                      ),
                                      const SizedBox(height: ThemeConfig.spacingXs),
                                      Text(
                                        'TODO: Load user address from API',
                                        style: Theme.of(context)
                                            .textTheme
                                            .bodyMedium
                                            ?.copyWith(
                                              color: ThemeConfig.textSecondary,
                                            ),
                                      ),
                                    ],
                                  ),
                                ),
                                TextButton(
                                  onPressed: () {
                                    // TODO: 주소 변경 기능
                                  },
                                  child: const Text('Change'),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),

                  // 결제 방법 섹션
                  _buildSection(
                    title: 'Payment Method',
                    child: Column(
                      children: [
                        RadioListTile<String>(
                          title: const Text('Cash on Delivery (COD)'),
                          subtitle: const Text('Pay when you receive'),
                          value: 'cod',
                          groupValue: _selectedPaymentMethod,
                          onChanged: (value) {
                            setState(() => _selectedPaymentMethod = value!);
                          },
                        ),
                        RadioListTile<String>(
                          title: const Text('GCash'),
                          subtitle: const Text('Pay with GCash'),
                          value: 'gcash',
                          groupValue: _selectedPaymentMethod,
                          onChanged: (value) {
                            setState(() => _selectedPaymentMethod = value!);
                          },
                        ),
                        RadioListTile<String>(
                          title: const Text('PayMaya'),
                          subtitle: const Text('Pay with PayMaya'),
                          value: 'paymaya',
                          groupValue: _selectedPaymentMethod,
                          onChanged: (value) {
                            setState(() => _selectedPaymentMethod = value!);
                          },
                        ),
                      ],
                    ),
                  ),

                  // 주문 메모
                  _buildSection(
                    title: 'Order Notes (Optional)',
                    child: TextFormField(
                      controller: _notesController,
                      maxLines: 3,
                      decoration: const InputDecoration(
                        hintText: 'Any special instructions for delivery?',
                        border: OutlineInputBorder(),
                      ),
                    ),
                  ),

                  // 주문 요약
                  _buildSection(
                    title: 'Order Summary',
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                        child: Column(
                          children: [
                            _buildSummaryRow(
                              'Items',
                              '${cartProvider.itemCount}',
                            ),
                            const SizedBox(height: ThemeConfig.spacingSm),
                            _buildSummaryRow(
                              'Subtotal',
                              cartProvider.summary.formattedSubtotal,
                            ),
                            const SizedBox(height: ThemeConfig.spacingSm),
                            _buildSummaryRow(
                              'Delivery Fee',
                              cartProvider.summary.formattedDeliveryFee,
                            ),
                            const Divider(height: ThemeConfig.spacingMd),
                            _buildSummaryRow(
                              'Total',
                              cartProvider.summary.formattedTotal,
                              isTotal: true,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),

                  const SizedBox(height: ThemeConfig.spacingXl),

                  // 주문 버튼
                  AppButton(
                    text: 'Place Order',
                    onPressed: _handleCheckout,
                    width: double.infinity,
                    height: 56,
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _buildSection({
    required String title,
    required Widget child,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SizedBox(height: ThemeConfig.spacingMd),
        Text(
          title,
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.bold,
              ),
        ),
        const SizedBox(height: ThemeConfig.spacingSm),
        child,
      ],
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
