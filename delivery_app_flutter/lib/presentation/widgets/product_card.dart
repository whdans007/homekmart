import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../../core/config/theme_config.dart';
import '../../data/models/product_model.dart';

/// 상품 카드 위젯
class ProductCard extends StatelessWidget {
  final ProductModel product;
  final VoidCallback onTap;
  final VoidCallback? onAddToCart;

  const ProductCard({
    super.key,
    required this.product,
    required this.onTap,
    this.onAddToCart,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // 상품 이미지
            AspectRatio(
              aspectRatio: 1,
              child: product.imageUrl != null
                  ? CachedNetworkImage(
                      imageUrl: product.imageUrl!,
                      fit: BoxFit.cover,
                      placeholder: (context, url) => Container(
                        color: Colors.grey.shade200,
                        child: const Center(
                          child: CircularProgressIndicator(),
                        ),
                      ),
                      errorWidget: (context, url, error) => Container(
                        color: Colors.grey.shade200,
                        child: const Icon(
                          Icons.image_not_supported,
                          size: 48,
                          color: Colors.grey,
                        ),
                      ),
                    )
                  : Container(
                      color: Colors.grey.shade200,
                      child: const Icon(
                        Icons.shopping_bag,
                        size: 48,
                        color: Colors.grey,
                      ),
                    ),
            ),

            // 상품 정보
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(ThemeConfig.spacingSm),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // 상품명
                    Text(
                      product.name,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: ThemeConfig.spacingXs),

                    // 가격
                    Text(
                      product.formattedPrice,
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            color: ThemeConfig.primaryColor,
                            fontWeight: FontWeight.bold,
                          ),
                    ),

                    const Spacer(),

                    // 재고 및 장바구니 버튼
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        // 재고 상태
                        if (product.stockQuantity <= 0)
                          Text(
                            'Out of Stock',
                            style:
                                Theme.of(context).textTheme.bodySmall?.copyWith(
                                      color: ThemeConfig.errorColor,
                                      fontWeight: FontWeight.w600,
                                    ),
                          )
                        else if (product.stockQuantity < 10)
                          Text(
                            'Only ${product.stockQuantity} left',
                            style:
                                Theme.of(context).textTheme.bodySmall?.copyWith(
                                      color: Colors.orange,
                                    ),
                          )
                        else
                          const SizedBox.shrink(),

                        // 장바구니 버튼
                        if (onAddToCart != null && product.stockQuantity > 0)
                          InkWell(
                            onTap: onAddToCart,
                            child: Container(
                              padding: const EdgeInsets.all(ThemeConfig.spacingXs),
                              decoration: BoxDecoration(
                                color: ThemeConfig.primaryColor,
                                borderRadius: BorderRadius.circular(
                                  ThemeConfig.radiusSm,
                                ),
                              ),
                              child: const Icon(
                                Icons.add_shopping_cart,
                                size: 20,
                                color: Colors.white,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
