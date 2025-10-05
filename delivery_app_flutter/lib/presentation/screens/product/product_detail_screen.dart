import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:go_router/go_router.dart';
import '../../../core/config/theme_config.dart';
import '../../../core/network/dio_client.dart';
import '../../../data/models/product_model.dart';
import '../../../data/services/product_service.dart';
import '../../providers/cart_provider.dart';
import '../../providers/product_provider.dart';
import '../../widgets/loading_overlay.dart';
import '../../widgets/error_widget.dart';
import '../../widgets/app_button.dart';

/// 상품 상세 화면
class ProductDetailScreen extends StatefulWidget {
  final String productId;

  const ProductDetailScreen({
    super.key,
    required this.productId,
  });

  @override
  State<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends State<ProductDetailScreen> {
  late final ProductService _productService;
  ProductModel? _product;
  bool _isLoading = true;
  String? _error;
  int _quantity = 1;

  @override
  void initState() {
    super.initState();
    _productService = ProductService(DioClient());
    _loadProductDetail();
  }

  Future<void> _loadProductDetail() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      final product = await _productService.getProductDetail(
        productId: int.parse(widget.productId),
      );
      setState(() {
        _product = product;
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _error = e.toString();
        _isLoading = false;
      });
    }
  }

  Future<void> _handleAddToCart() async {
    if (_product == null) return;

    final cartProvider = context.read<CartProvider>();
    final success = await cartProvider.addToCart(
      productId: _product!.productId,
      storeId: 1, // TODO: Get from store selection
      quantity: _quantity,
    );

    if (!mounted) return;

    if (success) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Added $_quantity item(s) to cart'),
          backgroundColor: Colors.green,
          duration: const Duration(seconds: 2),
        ),
      );
      context.pop();
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(cartProvider.error ?? 'Failed to add to cart'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  void _increaseQuantity() {
    if (_product != null && _quantity < _product!.stockQuantity) {
      setState(() => _quantity++);
    }
  }

  void _decreaseQuantity() {
    if (_quantity > 1) {
      setState(() => _quantity--);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Product Details'),
      ),
      body: _isLoading
          ? const LoadingIndicator(message: 'Loading product...')
          : _error != null
              ? AppErrorWidget(
                  message: _error!,
                  onRetry: _loadProductDetail,
                )
              : _product == null
                  ? const AppErrorWidget(message: 'Product not found')
                  : SingleChildScrollView(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          // 상품 이미지
                          AspectRatio(
                            aspectRatio: 1,
                            child: _product!.imageUrl != null
                                ? CachedNetworkImage(
                                    imageUrl: _product!.imageUrl!,
                                    fit: BoxFit.cover,
                                    placeholder: (context, url) => Container(
                                      color: Colors.grey.shade200,
                                      child: const Center(
                                        child: CircularProgressIndicator(),
                                      ),
                                    ),
                                    errorWidget: (context, url, error) =>
                                        Container(
                                      color: Colors.grey.shade200,
                                      child: const Icon(
                                        Icons.image_not_supported,
                                        size: 80,
                                        color: Colors.grey,
                                      ),
                                    ),
                                  )
                                : Container(
                                    color: Colors.grey.shade200,
                                    child: const Icon(
                                      Icons.shopping_bag,
                                      size: 80,
                                      color: Colors.grey,
                                    ),
                                  ),
                          ),

                          Padding(
                            padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                // 상품명
                                Text(
                                  _product!.name,
                                  style: Theme.of(context)
                                      .textTheme
                                      .headlineSmall
                                      ?.copyWith(
                                        fontWeight: FontWeight.bold,
                                      ),
                                ),
                                const SizedBox(height: ThemeConfig.spacingSm),

                                // 가격
                                Text(
                                  _product!.formattedPrice,
                                  style: Theme.of(context)
                                      .textTheme
                                      .headlineMedium
                                      ?.copyWith(
                                        color: ThemeConfig.primaryColor,
                                        fontWeight: FontWeight.bold,
                                      ),
                                ),
                                const SizedBox(height: ThemeConfig.spacingMd),

                                // 재고 상태
                                Container(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: ThemeConfig.spacingMd,
                                    vertical: ThemeConfig.spacingSm,
                                  ),
                                  decoration: BoxDecoration(
                                    color: _product!.stockQuantity > 0
                                        ? Colors.green.shade50
                                        : Colors.red.shade50,
                                    borderRadius: BorderRadius.circular(
                                      ThemeConfig.radiusSm,
                                    ),
                                    border: Border.all(
                                      color: _product!.stockQuantity > 0
                                          ? Colors.green
                                          : ThemeConfig.errorColor,
                                    ),
                                  ),
                                  child: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Icon(
                                        _product!.stockQuantity > 0
                                            ? Icons.check_circle
                                            : Icons.cancel,
                                        size: 16,
                                        color: _product!.stockQuantity > 0
                                            ? Colors.green
                                            : ThemeConfig.errorColor,
                                      ),
                                      const SizedBox(width: ThemeConfig.spacingXs),
                                      Text(
                                        _product!.stockQuantity > 0
                                            ? 'In Stock (${_product!.stockQuantity} available)'
                                            : 'Out of Stock',
                                        style: TextStyle(
                                          color: _product!.stockQuantity > 0
                                              ? Colors.green.shade900
                                              : ThemeConfig.errorColor,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                const SizedBox(height: ThemeConfig.spacingXl),

                                // 설명
                                if (_product!.description != null) ...[
                                  Text(
                                    'Description',
                                    style: Theme.of(context)
                                        .textTheme
                                        .titleMedium
                                        ?.copyWith(
                                          fontWeight: FontWeight.bold,
                                        ),
                                  ),
                                  const SizedBox(height: ThemeConfig.spacingSm),
                                  Text(
                                    _product!.description!,
                                    style: Theme.of(context)
                                        .textTheme
                                        .bodyMedium
                                        ?.copyWith(
                                          color: ThemeConfig.textSecondary,
                                        ),
                                  ),
                                  const SizedBox(height: ThemeConfig.spacingXl),
                                ],

                                // 수량 선택 (재고가 있을 때만)
                                if (_product!.stockQuantity > 0) ...[
                                  Text(
                                    'Quantity',
                                    style: Theme.of(context)
                                        .textTheme
                                        .titleMedium
                                        ?.copyWith(
                                          fontWeight: FontWeight.bold,
                                        ),
                                  ),
                                  const SizedBox(height: ThemeConfig.spacingSm),
                                  Row(
                                    children: [
                                      IconButton(
                                        onPressed: _decreaseQuantity,
                                        icon: const Icon(Icons.remove_circle_outline),
                                        iconSize: 32,
                                        color: ThemeConfig.primaryColor,
                                      ),
                                      Container(
                                        width: 80,
                                        alignment: Alignment.center,
                                        child: Text(
                                          _quantity.toString(),
                                          style: Theme.of(context)
                                              .textTheme
                                              .headlineSmall
                                              ?.copyWith(
                                                fontWeight: FontWeight.bold,
                                              ),
                                        ),
                                      ),
                                      IconButton(
                                        onPressed: _increaseQuantity,
                                        icon: const Icon(Icons.add_circle_outline),
                                        iconSize: 32,
                                        color: ThemeConfig.primaryColor,
                                      ),
                                    ],
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
      bottomNavigationBar: _product != null && _product!.stockQuantity > 0
          ? SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(ThemeConfig.spacingMd),
                child: AppButton(
                  text: 'Add to Cart',
                  icon: Icons.add_shopping_cart,
                  onPressed: _handleAddToCart,
                ),
              ),
            )
          : null,
    );
  }
}
