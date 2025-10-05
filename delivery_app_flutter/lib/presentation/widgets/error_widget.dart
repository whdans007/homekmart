import 'package:flutter/material.dart';
import '../../core/config/theme_config.dart';

/// 에러 표시 위젯
class AppErrorWidget extends StatelessWidget {
  final String message;
  final VoidCallback? onRetry;
  final IconData icon;

  const AppErrorWidget({
    super.key,
    required this.message,
    this.onRetry,
    this.icon = Icons.error_outline,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(ThemeConfig.spacingXl),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              icon,
              size: 64,
              color: ThemeConfig.errorColor,
            ),
            const SizedBox(height: ThemeConfig.spacingMd),
            Text(
              message,
              style: Theme.of(context).textTheme.bodyLarge,
              textAlign: TextAlign.center,
            ),
            if (onRetry != null) ...[
              const SizedBox(height: ThemeConfig.spacingXl),
              ElevatedButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh),
                label: const Text('Retry'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// 인라인 에러 메시지
class ErrorMessage extends StatelessWidget {
  final String message;

  const ErrorMessage({
    super.key,
    required this.message,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(ThemeConfig.spacingMd),
      decoration: BoxDecoration(
        color: ThemeConfig.errorColor.withOpacity(0.1),
        borderRadius: BorderRadius.circular(ThemeConfig.radiusMd),
        border: Border.all(
          color: ThemeConfig.errorColor.withOpacity(0.3),
        ),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.error_outline,
            color: ThemeConfig.errorColor,
          ),
          const SizedBox(width: ThemeConfig.spacingSm),
          Expanded(
            child: Text(
              message,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: ThemeConfig.errorColor,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}
